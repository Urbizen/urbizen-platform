<?php
/**
 * Suppression des documents, en mode fermé par défaut.
 *
 * Un fichier qu'on ne parvient pas à effacer et dont on supprime malgré tout
 * la demande devient un **orphelin** : une donnée personnelle que plus rien ne
 * rattache à une personne, donc que plus rien ne permet d'effacer sur demande.
 * C'est exactement ce qu'une politique de conservation doit rendre impossible.
 *
 * D'où la règle : **si le nettoyage échoue, la suppression n'a pas lieu.** La
 * demande, ses métadonnées et sa référence sont conservées, un code technique
 * est consigné, et l'opération pourra être retentée.
 *
 * `before_delete_post` ne convenait pas : c'est une action, elle ne peut rien
 * empêcher. Le blocage passe par le filtre `pre_delete_post`, qui court-circuite
 * `wp_delete_post()` — et qui transmet **trois** arguments.
 *
 * Une API unique, `delete()`, sert la rétention, la suppression manuelle et le
 * hook métier. Un garde de réentrance évite les doubles nettoyages.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Effacement des documents rattachés à une demande.
 */
final class FileCleaner {

	// --- Issues d'une suppression ---
	public const SUCCESS            = 'success';
	public const ALREADY_DELETED    = 'already_deleted';
	public const PARTIAL_FAILURE    = 'partial_failure';
	public const UNSAFE_PATH        = 'unsafe_path';
	public const FILESYSTEM_FAILURE = 'filesystem_failure';

	/**
	 * Issues qui autorisent la suppression du contenu.
	 *
	 * @var array<int, string>
	 */
	public const OK = array( self::SUCCESS, self::ALREADY_DELETED );

	/**
	 * Métadonnée mémorisant le statut métier d'avant la suppression.
	 */
	public const STATUS_BACKUP = '_urbizen_status_before_delete';

	/**
	 * Demandes en cours de nettoyage, pour éviter la réentrance.
	 *
	 * @var array<int, bool>
	 */
	private static array $en_cours = array();

	/**
	 * Accroche le blocage et le hook métier.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Trois arguments déclarés : WordPress plafonne ce qu'il transmet au
		// nombre annoncé, et le troisième porte `force_delete`.
		add_filter( 'pre_delete_post', array( self::class, 'guard_delete' ), 10, 3 );
	}

	/**
	 * Empêche la suppression d'une demande dont les documents résistent.
	 *
	 * @param mixed    $court_circuit Valeur de court-circuit de WordPress.
	 * @param \WP_Post $post          Contenu visé.
	 * @param bool     $force         Suppression définitive.
	 * @return mixed `false` pour bloquer, la valeur reçue sinon.
	 */
	public static function guard_delete( $court_circuit, $post, $force ) {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || SubmissionPostType::POST_TYPE !== $post->post_type ) {
			return $court_circuit;
		}

		$id        = (int) $post->ID;
		$reference = (string) get_post_meta( $id, '_urbizen_reference', true );

		$resultat = self::delete( $id, $reference );

		if ( in_array( $resultat['code'], self::OK, true ) ) {
			return $court_circuit;
		}

		// Fermé par défaut : plutôt une demande qui subsiste qu'un document
		// devenu introuvable.
		Logger::error( sprintf( 'suppression bloquée pour #%d : %s', $id, $resultat['code'] ) );

		return false;
	}

	/**
	 * Efface les documents d'une demande.
	 *
	 * Idempotente et réentrante : un second appel renvoie `already_deleted`
	 * sans rien casser.
	 *
	 * @param int    $submission Identifiant de la demande.
	 * @param string $reference  Référence.
	 * @return array{code:string,deleted:int,failed:int}
	 */
	public static function delete( int $submission, string $reference ): array {
		if ( $submission <= 0 ) {
			return self::issue( self::UNSAFE_PATH );
		}

		// Réentrance : la rétention et le filtre de suppression peuvent se
		// succéder sur la même demande.
		if ( isset( self::$en_cours[ $submission ] ) ) {
			return self::issue( self::ALREADY_DELETED );
		}

		$files = SubmissionRepository::decode_files( $submission );

		if ( array() === $files ) {
			return self::issue( self::ALREADY_DELETED );
		}

		if ( ! Storage::is_reference( $reference ) ) {
			return self::issue( self::UNSAFE_PATH );
		}

		if ( null === Storage::root() ) {
			return self::issue( self::FILESYSTEM_FAILURE );
		}

		self::$en_cours[ $submission ] = true;

		// Verrou d'accès posé **avant** le premier unlink. À partir de cet
		// instant, aucun lien signé ne fonctionne plus — y compris pour les
		// fichiers qui n'ont pas encore été touchés. Un téléchargement en
		// cours de suppression servirait un document à moitié effacé, ou un
		// document dont la personne vient de demander l'effacement.
		// Le statut métier d'origine est mémorisé à part, et **une seule fois** :
		// une seconde tentative après un échec ne doit pas prendre
		// `delete_failed` pour l'état à restaurer.
		$memoire = (string) get_post_meta( $submission, self::STATUS_BACKUP, true );

		if ( '' === $memoire ) {
			$courant = (string) get_post_meta( $submission, '_urbizen_status', true );
			$memoire = in_array( $courant, SubmissionPostType::downloadable_statuses(), true )
				? $courant
				: SubmissionPostType::STATUS_RECEIVED;

			update_post_meta( $submission, self::STATUS_BACKUP, $memoire );
		}

		update_post_meta( $submission, '_urbizen_status', SubmissionPostType::STATUS_DELETING );

		$effaces = 0;
		$echecs  = 0;
		$code    = self::SUCCESS;

		foreach ( $files as $file ) {
			$relatif = isset( $file['relative_path'] ) ? (string) $file['relative_path'] : '';

			if ( '' === $relatif ) {
				++$echecs;
				$code = self::UNSAFE_PATH;
				continue;
			}

			$reel = Storage::resolve( $relatif );

			if ( null === $reel ) {
				// Le fichier n'est plus là — soit déjà effacé, soit hors de la
				// racine. On ne peut pas distinguer sans risque : on considère
				// l'entrée traitée, mais on ne l'oublie pas.
				++$effaces;
				continue;
			}

			if ( ! @unlink( $reel ) || file_exists( $reel ) ) {
				++$echecs;
				$code = self::FILESYSTEM_FAILURE;
			} else {
				++$effaces;
			}
		}

		unset( self::$en_cours[ $submission ] );

		if ( $echecs > 0 ) {
			// Les métadonnées sont **conservées** : elles sont la seule chose
			// qui permettra de retrouver les fichiers restants et de retenter.
			// L'état reste bloquant : les fichiers encore présents ne doivent
			// pas redevenir téléchargeables.
			update_post_meta( $submission, '_urbizen_status', 'delete_failed' );
			update_post_meta( $submission, '_urbizen_files_status', 'delete_failed' );

			return array(
				'code'    => self::PARTIAL_FAILURE === $code || $effaces > 0 ? self::PARTIAL_FAILURE : $code,
				'deleted' => $effaces,
				'failed'  => $echecs,
			);
		}

		Storage::delete_files( $reference, array() );

		update_post_meta( $submission, '_urbizen_files', (string) wp_json_encode( array() ) );
		update_post_meta( $submission, '_urbizen_files_count', 0 );
		update_post_meta( $submission, '_urbizen_files_total_size', 0 );
		update_post_meta( $submission, '_urbizen_files_status', 'deleted' );

		// Nettoyage complet : le statut métier retrouve sa valeur d'origine, ce
		// qui laisse WordPress poursuivre la suppression du contenu.
		update_post_meta( $submission, '_urbizen_status', $memoire );
		delete_post_meta( $submission, self::STATUS_BACKUP );

		Logger::info( sprintf( 'demande %s : %d document(s) effacé(s)', $reference, $effaces ) );

		return array(
			'code'    => self::SUCCESS,
			'deleted' => $effaces,
			'failed'  => 0,
		);
	}

	/**
	 * Fabrique une issue sans effacement.
	 *
	 * @param string $code Code interne.
	 * @return array{code:string,deleted:int,failed:int}
	 */
	private static function issue( string $code ): array {
		return array(
			'code'    => $code,
			'deleted' => 0,
			'failed'  => 0,
		);
	}

	/**
	 * Oublie les gardes de réentrance.
	 *
	 * Réservé aux bancs d'essai.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$en_cours = array();
	}
}
