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
		add_action( self::HOOK, array( self::class, 'run_daily' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Programme la tâche quotidienne si elle ne l'est pas déjà.
	 *
	 * Idempotente : `wp_next_scheduled()` interroge le tableau des tâches, déjà
	 * chargé en mémoire. Cent appels successifs ne créent qu'une tâche.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		Logger::info( 'rétention : tâche quotidienne programmée' );
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
	 * @return array{demandes:int,jetons:int,creneaux:int,references:int}
	 */
	public static function run_daily( ?int $now = null ): array {
		$now = null === $now ? time() : $now;

		$bilan = array(
			'demandes'   => self::purge( $now ),
			'jetons'     => AntiSpam::cleanup_expired_tokens( $now ),
			'creneaux'   => RateLimiter::cleanup_expired_slots( $now ),
			'references' => SubmissionRepository::cleanup_abandoned_references( $now ),
		);

		if ( $bilan['jetons'] || $bilan['creneaux'] || $bilan['references'] ) {
			// Des décomptes, jamais un jeton, un condensat ou une référence.
			Logger::info(
				sprintf(
					'ménage : %d jeton(s), %d créneau(x), %d réservation(s) de référence',
					$bilan['jetons'],
					$bilan['creneaux'],
					$bilan['references']
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

			// Les fichiers rattachés s'effacent ici, avant que la demande ne
			// disparaisse : après, plus rien ne permettrait de les retrouver.
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
