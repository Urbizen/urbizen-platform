<?php
/**
 * Conservation et suppression des demandes.
 *
 * Une demande contient des données personnelles. La garder indéfiniment n'est
 * ni nécessaire, ni licite : la conservation doit être limitée à ce que
 * justifie sa finalité.
 *
 * Règle retenue : **365 jours après le dernier contact**, une demande non
 * convertie est supprimée, elle et ce qui s'y rattache. Une demande devenue un
 * dossier client relève d'une autre politique, contractuelle et comptable :
 * elle n'est **jamais** touchée par ce mécanisme.
 *
 * La durée vit à un seul endroit, ajustable par filtre. Une durée recopiée à
 * trois endroits du code est une durée qu'on finit par ne plus respecter.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Privacy;

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Purge des demandes expirées.
 */
final class Retention {

	/**
	 * Tâche planifiée. Nom déjà déprogrammé par le Deactivator existant.
	 */
	public const HOOK = 'urbizen_purge_expired';

	/**
	 * Hook déclenché juste avant la suppression d'une demande.
	 *
	 * La PR B2 s'y branchera pour effacer les fichiers rattachés : les fichiers
	 * doivent disparaître avec la demande, pas lui survivre.
	 */
	public const BEFORE_DELETE = 'urbizen_before_submission_delete';

	/**
	 * Durée de conservation par défaut, en jours.
	 */
	public const DEFAULT_DAYS = 365;

	/**
	 * Verrou de programmation de la tâche.
	 *
	 * Nom fixe : il n'y a qu'une tâche à programmer, donc qu'un verrou.
	 */
	public const LOCK_OPTION = 'urbizen_cron_lock';

	/**
	 * Durée de vie du verrou, en secondes.
	 *
	 * Très courte : la section protégée se réduit à une lecture et une écriture.
	 * Un arrêt brutal au milieu ne doit pas empêcher la programmation pour
	 * toujours — au pire, la requête suivante reprend le verrou périmé.
	 */
	public const LOCK_TTL = 30;

	/**
	 * Nombre maximal de demandes traitées par passage.
	 *
	 * Une purge ne doit jamais faire expirer une tâche planifiée. Le reliquat
	 * est traité au passage suivant.
	 */
	private const LOT = 200;

	/**
	 * Accroche la tâche et garantit sa programmation.
	 *
	 * `ensure_scheduled()` est appelée à **chaque chargement**, pas seulement à
	 * l'activation. C'est indispensable : mettre à jour une extension déjà
	 * active remplace ses fichiers sans déclencher le hook d'activation. Sans
	 * cela, la purge n'existerait jamais sur un site passé de 0.5.0 à 0.6.0 —
	 * et il faudrait désactiver puis réactiver l'extension à la main, ce qu'on
	 * ne peut pas exiger d'une mise en production.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Zéro argument déclaré des deux côtés : `do_action()` transmet une
		// chaîne vide quand il n'a rien à passer, et un paramètre typé `?int`
		// la refuserait. Le déclarer évite ce piège classique.
		add_action( self::HOOK, array( self::class, 'run_daily' ), 10, 0 );
		add_action( 'init', array( self::class, 'ensure_scheduled' ), 10, 0 );
	}

	/**
	 * Programme la tâche quotidienne si elle ne l'est pas déjà.
	 *
	 * Sans paramètre : `do_action()` transmet une chaîne vide quand il n'a rien
	 * à passer, et un paramètre typé la refuserait.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		self::ensure_scheduled_at( time() );
	}

	/**
	 * Programme la tâche, à un instant donné.
	 *
	 * `wp_next_scheduled()` puis `wp_schedule_event()` est un « lire puis
	 * écrire » : deux requêtes arrivant ensemble juste après une mise à jour
	 * ne trouvent ni l'une ni l'autre de tâche, et en programment deux. Le
	 * premier contrôle reste, comme chemin rapide sans écriture ; la
	 * programmation elle-même se fait sous **verrou atomique**, et le contrôle
	 * est refait une fois le verrou tenu.
	 *
	 * @param int $now Horodatage courant.
	 * @return void
	 */
	public static function ensure_scheduled_at( int $now ): void {
		// Chemin rapide : dans l'immense majorité des requêtes, la tâche existe
		// déjà et rien n'est écrit.
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		if ( ! self::acquire_lock( $now ) ) {
			// Une autre requête s'en occupe. Elle aboutira ; celle-ci n'a rien
			// à faire, et surtout rien à programmer.
			return;
		}

		self::schedule_now();
		self::release_lock();
	}

	/**
	 * Programme la tâche. Suppose le verrou détenu.
	 *
	 * Le contrôle est refait ici : entre le chemin rapide et l'obtention du
	 * verrou, une autre requête a pu programmer.
	 *
	 * @return void
	 */
	public static function schedule_now(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		Logger::info( 'rétention : tâche quotidienne programmée' );
	}

	/**
	 * Prend le verrou de programmation.
	 *
	 * @param int $now Horodatage courant.
	 * @return bool Vrai si le verrou est acquis.
	 */
	public static function acquire_lock( int $now ): bool {
		$existant = get_option( self::LOCK_OPTION, null );

		if ( is_array( $existant ) ) {
			if ( $now < (int) ( $existant['expires'] ?? 0 ) ) {
				return false;
			}

			// Verrou périmé : une requête interrompue l'a laissé derrière elle.
			delete_option( self::LOCK_OPTION );
		}

		return (bool) add_option(
			self::LOCK_OPTION,
			array( 'expires' => $now + self::LOCK_TTL ),
			'',
			false
		);
	}

	/**
	 * Rend le verrou.
	 *
	 * En fonctionnement normal, aucun verrou ne subsiste dans `wp_options`.
	 *
	 * @return void
	 */
	public static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Alias historique, conservé pour l'activation.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		self::ensure_scheduled();
	}

	/**
	 * Passage quotidien complet : purge des demandes, puis ménage technique.
	 *
	 * Les réservations techniques — jetons consommés, créneaux de débit,
	 * références abandonnées — ne portent aucune donnée personnelle, mais
	 * s'accumuleraient dans `wp_options` si personne ne les retirait.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return array{demandes:int,jetons:int,creneaux:int,references:int,staging:int,abandons:int}
	 */
	public static function run_daily( ?int $now = null ): array {
		$now = null === $now ? time() : $now;

		// L'ordre compte. La récupération passe **avant** le ménage des
		// réservations : elle s'appuie sur la réservation « reserved » pour
		// reconnaître une transaction abandonnée, et la libère elle-même. La
		// nettoyer d'abord la priverait de son seul repère.
		$bilan = array(
			'demandes'   => self::purge( $now ),
			'abandons'   => self::recover_abandoned( $now ),
			'jetons'     => AntiSpam::cleanup_expired_tokens( $now ),
			'creneaux'   => RateLimiter::cleanup_expired_slots( $now ),
			'references' => SubmissionRepository::cleanup_abandoned_references( $now ),
			// Ne nettoie que le staging. Un document final n'est jamais
			// supprimé au motif qu'une métadonnée semble manquante.
			'staging'    => Storage::cleanup_staging( $now ),
		);

		if ( $bilan['jetons'] || $bilan['creneaux'] || $bilan['references'] || $bilan['staging'] || $bilan['abandons'] ) {
			// Des décomptes, jamais un jeton, un condensat ou une référence.
			Logger::info(
				sprintf(
					'ménage : %d jeton(s), %d créneau(x), %d réservation(s), %d staging, %d transaction(s)',
					$bilan['jetons'],
					$bilan['creneaux'],
					$bilan['references'],
					$bilan['staging'],
					$bilan['abandons']
				)
			);
		}

		return $bilan;
	}

	/**
	 * Déprogramme la tâche.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$horodatage = wp_next_scheduled( self::HOOK );

		while ( false !== $horodatage ) {
			wp_unschedule_event( $horodatage, self::HOOK );
			$horodatage = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Durée de conservation retenue, en jours.
	 *
	 * @return int
	 */
	public static function days(): int {
		$jours = (int) apply_filters( 'urbizen_retention_days', self::DEFAULT_DAYS );

		// Une durée nulle ou négative effacerait tout au premier passage.
		return max( 1, $jours );
	}

	/**
	 * États soumis à la suppression automatique.
	 *
	 * `converted` en est volontairement absent.
	 *
	 * @return array<int, string>
	 */
	public static function purgeable_statuses(): array {
		return array( SubmissionPostType::STATUS_RECEIVED, SubmissionPostType::STATUS_CLOSED );
	}

	/**
	 * Délai au-delà duquel une transaction reste en plan est jugée abandonnée.
	 */
	public const ABANDON_TTL = 3600;

	/**
	 * Récupère les transactions interrompues brutalement.
	 *
	 * Une exception se rattrape ; une coupure de processus, non. Ni `catch`,
	 * ni `finally`, ni destructeur ne s'exécutent lorsque PHP est tué, que le
	 * serveur redémarre ou que la connexion tombe pendant l'écriture. Le
	 * rattrapage ne peut donc pas vivre dans la requête : il lui faut un état
	 * **durable**, relu par une requête ultérieure.
	 *
	 * Sept conditions doivent être réunies **simultanément** pour qu'une
	 * demande soit jugée abandonnée. Toute incertitude conduit à conserver.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de transactions récupérées.
	 */
	public static function recover_abandoned( ?int $now = null ): int {
		$now    = null === $now ? time() : $now;
		$limite = gmdate( 'Y-m-d H:i:s', $now - self::ABANDON_TTL );

		$candidats = get_posts(
			array(
				'post_type'        => SubmissionPostType::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => self::LOT,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_urbizen_status',
						'value'   => SubmissionPostType::STATUS_PROCESSING,
						'compare' => '=',
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

		$recuperees = 0;

		foreach ( $candidats as $id ) {
			$id = (int) $id;

			if ( self::recover_one( $id, $now ) ) {
				++$recuperees;
			}
		}

		return $recuperees;
	}

	/**
	 * Récupère une transaction, si et seulement si toutes les conditions sont réunies.
	 *
	 * @param int $id  Demande.
	 * @param int $now Horodatage courant.
	 * @return bool
	 */
	private static function recover_one( int $id, int $now ): bool {
		$post = get_post( $id );

		// 1 · le bon type de contenu.
		if ( ! $post || SubmissionPostType::POST_TYPE !== $post->post_type ) {
			return false;
		}

		// 2 · état interne « processing ».
		if ( SubmissionPostType::STATUS_PROCESSING !== (string) get_post_meta( $id, '_urbizen_status', true ) ) {
			return false;
		}

		// 3 · ancienneté suffisante.
		$creee = (string) get_post_meta( $id, '_urbizen_created_at_gmt', true );

		if ( '' === $creee || strtotime( $creee . ' UTC' ) > $now - self::ABANDON_TTL ) {
			return false;
		}

		// 4 · aucun marqueur de validation.
		$transaction = SubmissionRepository::transaction( $id );

		if ( 'committed' === ( $transaction['state'] ?? '' ) ) {
			Logger::error( sprintf( 'transaction #%d : marquée committed mais restée processing — conservée', $id ) );

			return false;
		}

		$reference = (string) get_post_meta( $id, '_urbizen_reference', true );

		if ( ! Storage::is_reference( $reference ) ) {
			Logger::error( sprintf( 'transaction #%d : référence illisible — conservée', $id ) );

			return false;
		}

		// 5 et 6 · la réservation doit être encore « reserved » et pointer sur
		// cette demande. Une référence attribuée signale une demande valide :
		// on ne touche à rien.
		$reservation = get_option( SubmissionRepository::RESERVATION_PREFIX . $reference, null );

		if ( ! is_array( $reservation ) || 'reserved' !== ( $reservation['state'] ?? '' ) ) {
			Logger::error( sprintf( 'transaction #%d : réservation non « reserved » — conservée', $id ) );

			return false;
		}

		$rattachee = (int) ( $reservation['post'] ?? 0 );

		if ( 0 !== $rattachee && $rattachee !== $id ) {
			Logger::error( sprintf( 'transaction #%d : réservation rattachée à une autre demande — conservée', $id ) );

			return false;
		}

		// Ordre sûr : staging, puis fichiers finaux de cette référence, puis la
		// demande, puis la réservation. À aucun moment un fichier ne survit à
		// la disparition de ce qui permet de le retrouver.
		if ( isset( $transaction['staging'] ) && is_string( $transaction['staging'] ) ) {
			Storage::discard_staging( $transaction['staging'] );
		}

		Storage::delete_reference_dir( $reference );

		wp_delete_post( $id, true );
		SubmissionRepository::release_reference( $reference );

		Logger::info( sprintf( 'transaction abandonnée récupérée : #%d (%s)', $id, $reference ) );

		return true;
	}

	/**
	 * Supprime les demandes expirées.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de demandes supprimées.
	 */
	public static function purge( ?int $now = null ): int {
		$now    = null === $now ? time() : $now;
		$limite = gmdate( 'Y-m-d H:i:s', $now - ( self::days() * DAY_IN_SECONDS ) );

		$candidats = get_posts(
			array(
				'post_type'        => SubmissionPostType::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => self::LOT,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_urbizen_status',
						'value'   => self::purgeable_statuses(),
						'compare' => 'IN',
					),
					array(
						'key'     => '_urbizen_last_contact_at_gmt',
						'value'   => $limite,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$supprimees = 0;

		foreach ( $candidats as $id ) {
			$id     = (int) $id;
			$statut = (string) get_post_meta( $id, '_urbizen_status', true );

			// Seconde barrière : une requête méta mal interprétée ne doit pas
			// pouvoir emporter un dossier client.
			if ( ! in_array( $statut, self::purgeable_statuses(), true ) ) {
				continue;
			}

			$reference = (string) get_post_meta( $id, '_urbizen_reference', true );

			// Les fichiers rattachés s'effacent d'abord : après la suppression
			// de la demande, plus rien ne permettrait de les retrouver.
			$nettoyage = FileCleaner::delete( $id, $reference );

			if ( ! in_array( $nettoyage['code'], FileCleaner::OK, true ) ) {
				// Fermé par défaut : la demande est conservée et sera retentée
				// au passage suivant. Un document qu'on n'a pas su effacer ne
				// doit jamais devenir orphelin.
				update_post_meta( $id, '_urbizen_files_status', 'delete_failed' );
				Logger::error( sprintf( 'rétention : suppression différée pour #%d (%s)', $id, $nettoyage['code'] ) );

				continue;
			}

			// Le hook reste offert aux tiers, la demande existant encore.
			do_action( self::BEFORE_DELETE, $id, $reference );

			wp_delete_post( $id, true );
			++$supprimees;
		}

		if ( $supprimees > 0 ) {
			Logger::info( sprintf( 'rétention : %d demande(s) supprimée(s) après %d jours', $supprimees, self::days() ) );
		}

		return $supprimees;
	}
}
