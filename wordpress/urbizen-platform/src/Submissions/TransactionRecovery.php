<?php
/**
 * Récupération des transactions interrompues.
 *
 * Le point de non-retour d'une soumission n'est pas le marqueur `committed` :
 * c'est **l'attribution définitive de la référence**. Une réponse de succès ne
 * part qu'après elle. Une transaction portant `committed` mais dont la
 * référence est restée `reserved` n'a donc jamais abouti — et la conserver
 * indéfiniment maintiendrait des documents et des données personnelles sans
 * aucune finalité.
 *
 * D'où trois issues, et trois seulement :
 *
 * - **rollback** : la référence est encore `reserved`. La transaction n'a pas
 *   abouti, quoi que dise son marqueur. Tout est effacé.
 * - **normalisation** : la référence est `attributed` et tout concorde. La
 *   demande est valide ; on se borne à réparer l'état interne si l'interruption
 *   a précédé la dernière écriture.
 * - **conservation prudente** : la référence est `attributed` mais quelque
 *   chose ne concorde pas. On ne supprime rien, on ne normalise rien, on
 *   signale et on ferme l'accès aux documents.
 *
 * Le rollback est **fermé par défaut** : si un seul fichier résiste, rien
 * d'autre n'est supprimé. Un document qu'on n'a pas su effacer ne doit jamais
 * devenir orphelin, et la demande doit rester retentable.
 *
 * @package Urbizen\Platform\Submissions
 */

namespace Urbizen\Platform\Submissions;

use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Réconciliation des demandes restées en cours de traitement.
 */
final class TransactionRecovery {

	/**
	 * Nettoyage impossible : la demande est conservée et sera retentée.
	 */
	public const RECOVERY_FAILED = 'recovery_failed';

	/**
	 * État contradictoire : conservation prudente, intervention humaine.
	 */
	public const INCOHERENT = 'incoherent';

	/**
	 * Classements possibles d'une demande examinée.
	 */
	public const ROLLBACK   = 'rollback';
	public const COHERENT   = 'coherent';
	public const INCONSIST  = 'incoherent';
	public const SKIP       = 'skip';

	/**
	 * Délai au-delà duquel une transaction en plan est jugée abandonnée.
	 */
	public const TTL = 3600;

	/**
	 * États internes qu'une réconciliation doit examiner.
	 *
	 * `recovery_failed` en fait partie : un nettoyage impossible hier doit être
	 * retenté demain.
	 *
	 * @var array<int, string>
	 */
	public const EXAMINABLE = array(
		SubmissionPostType::STATUS_PROCESSING,
		self::RECOVERY_FAILED,
	);

	/**
	 * Réconcilie les demandes restées en traitement.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return array{rollback:int,normalized:int,failed:int,incoherent:int}
	 */
	public static function run( ?int $now = null ): array {
		$now    = null === $now ? time() : $now;
		$bilan  = array( 'rollback' => 0, 'normalized' => 0, 'failed' => 0, 'incoherent' => 0 );
		$limite = gmdate( 'Y-m-d H:i:s', $now - self::TTL );

		$candidats = get_posts(
			array(
				'post_type'        => SubmissionPostType::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_urbizen_status',
						'value'   => self::EXAMINABLE,
						'compare' => 'IN',
					),
					array(
						'key'     => '_urbizen_created_at_gmt',
						'value'   => $limite,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		foreach ( $candidats as $id ) {
			$id        = (int) $id;
			$reference = (string) get_post_meta( $id, '_urbizen_reference', true );

			switch ( self::classify( $id, $now ) ) {
				case self::ROLLBACK:
					if ( self::rollback( $id, $reference ) ) {
						++$bilan['rollback'];
					} else {
						++$bilan['failed'];
					}
					break;

				case self::COHERENT:
					if ( self::normalize( $id ) ) {
						++$bilan['normalized'];
					}
					break;

				case self::INCONSIST:
					self::mark( $id, self::INCOHERENT, 'état contradictoire' );
					++$bilan['incoherent'];
					break;
			}
		}

		return $bilan;
	}

	/**
	 * Classe une demande.
	 *
	 * @param int $id  Demande.
	 * @param int $now Horodatage courant.
	 * @return string Un des classements de cette classe.
	 */
	public static function classify( int $id, int $now ): string {
		$post = get_post( $id );

		if ( ! $post || SubmissionPostType::POST_TYPE !== $post->post_type ) {
			return self::SKIP;
		}

		if ( ! in_array( (string) get_post_meta( $id, '_urbizen_status', true ), self::EXAMINABLE, true ) ) {
			return self::SKIP;
		}

		$creee = (string) get_post_meta( $id, '_urbizen_created_at_gmt', true );

		if ( '' === $creee || strtotime( $creee . ' UTC' ) > $now - self::TTL ) {
			return self::SKIP;
		}

		$reference = (string) get_post_meta( $id, '_urbizen_reference', true );

		if ( ! Storage::is_reference( $reference ) ) {
			return self::INCONSIST;
		}

		$reservation = get_option( SubmissionRepository::RESERVATION_PREFIX . $reference, null );

		if ( ! is_array( $reservation ) ) {
			return self::INCONSIST;
		}

		$etat      = (string) ( $reservation['state'] ?? '' );
		$rattachee = (int) ( $reservation['post'] ?? 0 );

		// Une réservation rattachée à une autre demande : on ne touche à rien.
		if ( 0 !== $rattachee && $rattachee !== $id ) {
			return self::INCONSIST;
		}

		if ( 'reserved' === $etat ) {
			// Le point décisif. Le marqueur `committed` ne suffit pas : la
			// référence n'ayant jamais été attribuée, la transaction n'a pas
			// atteint son point de non-retour, et aucune réponse de succès
			// n'a pu partir.
			return self::ROLLBACK;
		}

		if ( 'attributed' !== $etat ) {
			return self::INCONSIST;
		}

		return self::is_coherent( $id, $reference ) ? self::COHERENT : self::INCONSIST;
	}

	/**
	 * Une demande à référence attribuée est-elle cohérente ?
	 *
	 * @param int    $id        Demande.
	 * @param string $reference Référence.
	 * @return bool
	 */
	public static function is_coherent( int $id, string $reference ): bool {
		$transaction = SubmissionRepository::transaction( $id );

		if ( 'committed' !== ( $transaction['state'] ?? '' ) ) {
			return false;
		}

		if ( (string) ( $transaction['reference'] ?? '' ) !== $reference ) {
			return false;
		}

		if ( ! in_array( (string) get_post_meta( $id, '_urbizen_files_status', true ), array( 'stored', 'none' ), true ) ) {
			return false;
		}

		$presentes = array_keys( get_post_meta( $id ) );

		return array() === array_diff( SubmissionRepository::REQUIRED_META, $presentes );
	}

	/**
	 * Annule entièrement une transaction, en mode fermé par défaut.
	 *
	 * Ordre imposé : staging, fichiers, vérification, puis seulement ensuite la
	 * demande et la réservation. Si un seul nettoyage échoue, rien d'autre n'est
	 * supprimé — la demande reste en `recovery_failed` et sera retentée.
	 *
	 * @param int    $id        Demande.
	 * @param string $reference Référence.
	 * @return bool Vrai si le rollback est complet.
	 */
	public static function rollback( int $id, string $reference ): bool {
		$transaction = SubmissionRepository::transaction( $id );

		// 1 · le staging explicitement rattaché.
		if ( isset( $transaction['staging'] ) && is_string( $transaction['staging'] ) && '' !== $transaction['staging'] ) {
			Storage::discard_staging( $transaction['staging'] );
		}

		// 2 · les fichiers finaux explicitement rattachés.
		$files  = SubmissionRepository::decode_files( $id );
		$echecs = 0;

		foreach ( $files as $file ) {
			$relatif = isset( $file['relative_path'] ) ? (string) $file['relative_path'] : '';

			if ( '' === $relatif ) {
				++$echecs;
				continue;
			}

			$reel = Storage::resolve( $relatif );

			if ( null === $reel ) {
				// Déjà absent : l'opération est idempotente.
				continue;
			}

			if ( ! @unlink( $reel ) || file_exists( $reel ) ) {
				++$echecs;
			}
		}

		// 3 · vérification. Le répertoire de la référence doit disparaître.
		Storage::delete_reference_dir( $reference );

		if ( $echecs > 0 || Storage::has_reference_dir( $reference ) ) {
			self::mark( $id, self::RECOVERY_FAILED, sprintf( '%d nettoyage(s) en échec', max( 1, $echecs ) ) );

			return false;
		}

		// 4 · la demande et ses métadonnées.
		wp_delete_post( $id, true );

		// 5 · la réservation, qui n'est jamais `attributed` sur ce chemin.
		SubmissionRepository::release_reference( $reference );

		Logger::info( sprintf( 'transaction annulée : #%d (%s)', $id, $reference ) );

		return true;
	}

	/**
	 * Normalise une demande valide dont l'état interne est resté en plan.
	 *
	 * Idempotente : ne touche qu'au statut métier, et seulement s'il n'est pas
	 * déjà final. Ne crée aucune référence, n'émet aucune réponse.
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function normalize( int $id ): bool {
		if ( SubmissionPostType::STATUS_RECEIVED === (string) get_post_meta( $id, '_urbizen_status', true ) ) {
			return false;
		}

		update_post_meta( $id, '_urbizen_status', SubmissionPostType::STATUS_RECEIVED );
		Logger::info( sprintf( 'transaction normalisée : #%d', $id ) );

		return true;
	}

	/**
	 * Consigne un état technique, sans donnée personnelle.
	 *
	 * @param int    $id    Demande.
	 * @param string $etat  État interne.
	 * @param string $motif Motif technique.
	 * @return void
	 */
	private static function mark( int $id, string $etat, string $motif ): void {
		update_post_meta( $id, '_urbizen_status', $etat );
		Logger::error( sprintf( 'transaction #%d : %s — %s, conservée', $id, $etat, $motif ) );
	}
}
