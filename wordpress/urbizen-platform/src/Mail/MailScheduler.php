<?php
/**
 * Planification, envoi et réconciliation des notifications.
 *
 * Trois moments, trois garanties distinctes.
 *
 * **La planification** suit une finalisation réussie : un événement unique,
 * dédupliqué, porte l'identifiant de la demande. Il ne transporte aucune donnée
 * — tout est relu depuis la base au moment du traitement.
 *
 * **L'envoi** est protégé par un verrou atomique et relit l'état sous ce
 * verrou. Deux processus concurrents ne peuvent pas appeler le transport deux
 * fois pour la même tentative.
 *
 * **La réconciliation** rattrape ce qu'aucun événement n'a traité : une panne
 * survenue entre la finalisation et la planification, une reprise dont
 * l'échéance est atteinte, un `sending` abandonné par une requête tuée.
 *
 * Sur les doublons, la position est explicite et assumée : `wp_mail()` ne
 * permet pas de garantir « exactement une fois ». Une interruption peut
 * survenir après l'appel et avant l'écriture de `sent`. La politique retenue
 * est **au moins une fois** — un doublon exceptionnel, reconnaissable à son
 * en-tête `X-Urbizen-Notification-ID`, vaut mieux qu'une notification
 * définitivement perdue.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestration des notifications administratives.
 */
final class MailScheduler {

	/**
	 * Transport employé pour l'envoi.
	 *
	 * Injectable : les bancs y placent un double, et rien n'est jamais émis.
	 *
	 * @var MailTransport|null
	 */
	private static ?MailTransport $transport = null;

	/**
	 * Accroche l'événement de traitement.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( MailPolicy::EVENT, array( self::class, 'handle_event' ), 10, 1 );
	}

	/**
	 * Désigne le transport à employer.
	 *
	 * @param MailTransport|null $transport Transport, ou `null` pour revenir au défaut.
	 * @return void
	 */
	public static function set_transport( ?MailTransport $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * Transport courant.
	 *
	 * @return MailTransport
	 */
	public static function transport(): MailTransport {
		if ( null === self::$transport ) {
			self::$transport = new WordPressMailTransport();
		}

		return self::$transport;
	}

	/**
	 * Programme le traitement d'une demande.
	 *
	 * Dédupliqué : un événement déjà programmé n'est pas doublé.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @return bool Vrai si un événement est programmé après cet appel.
	 */
	public static function schedule( int $id, ?int $now = null ): bool {
		$now  = null === $now ? time() : $now;
		$args = array( $id );

		if ( false !== wp_next_scheduled( MailPolicy::EVENT, $args ) ) {
			return true;
		}

		return false !== wp_schedule_single_event( $now, MailPolicy::EVENT, $args );
	}

	/**
	 * Retire l'événement programmé d'une demande.
	 *
	 * @param int $id Demande.
	 * @return void
	 */
	public static function unschedule( int $id ): void {
		$args      = array( $id );
		$programme = wp_next_scheduled( MailPolicy::EVENT, $args );

		if ( false !== $programme ) {
			wp_unschedule_event( $programme, MailPolicy::EVENT, $args );
		}
	}

	/**
	 * Traite l'événement d'une demande.
	 *
	 * @param mixed $id Identifiant transmis par le planificateur.
	 * @return string Code technique du résultat.
	 */
	public static function handle_event( $id = 0 ): string {
		return self::process( (int) $id );
	}

	/**
	 * Tente d'envoyer la notification d'une demande.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @return string Code technique.
	 */
	public static function process( int $id, ?int $now = null ): string {
		$now = null === $now ? time() : $now;

		if ( $id <= 0 ) {
			return 'identifiant_invalide';
		}

		// Une demande supprimée laisse parfois un événement derrière elle :
		// il doit être sans effet, et faire le ménage de son propre verrou.
		if ( null === get_post( $id ) ) {
			MailQueue::release_lock( $id );

			return 'post_absent';
		}

		$motif = MailPolicy::blocker( $id, $now );

		if ( null !== $motif ) {
			Logger::info( sprintf( 'notification #%d non envoyée : %s', $id, $motif ) );

			return $motif;
		}

		if ( ! MailQueue::acquire_lock( $id, $now ) ) {
			// Une autre requête traite cette notification en ce moment même.
			return 'verrou_occupe';
		}

		try {
			// Relecture **sous le verrou** : l'autre processus a pu aboutir
			// entre le contrôle d'éligibilité et la prise du verrou.
			$motif = MailPolicy::blocker( $id, $now );

			if ( null !== $motif ) {
				return $motif;
			}

			$rang = ( (int) get_post_meta( $id, MailPolicy::META_ATTEMPTS, true ) ) + 1;

			if ( $rang > MailPolicy::MAX_ATTEMPTS ) {
				MailQueue::mark_failure( $id, $rang, 'attempts_exhausted', $now );

				return 'tentatives_epuisees';
			}

			if ( ! MailQueue::mark_sending( $id, $rang, $now ) ) {
				return 'etat_non_ecrit';
			}

			$message = MailRenderer::render( $id, $now );

			if ( null === $message ) {
				MailQueue::mark_failure( $id, $rang, 'render_failed', $now );

				return 'rendu_impossible';
			}

			$resultat = self::transport()->send(
				$message['to'],
				$message['subject'],
				$message['body'],
				$message['headers']
			);

			if ( ! empty( $resultat['ok'] ) ) {
				MailQueue::mark_sent( $id, $now );

				Logger::info(
					sprintf(
						'notification #%d [%s] acceptée par le transport (tentative %d)',
						$id,
						MailPolicy::short_id( (string) get_post_meta( $id, MailPolicy::META_ID, true ) ),
						$rang
					)
				);

				return 'sent';
			}

			MailQueue::mark_failure( $id, $rang, (string) ( $resultat['code'] ?? 'transport_refused' ), $now );

			return 'echec';
		} finally {
			MailQueue::release_lock( $id );
		}
	}

	/**
	 * Rattrape les notifications qu'aucun événement ne traitera.
	 *
	 * Quatre cas : une notification `pending` sans événement programmé — la
	 * trace d'une panne survenue entre la finalisation et la planification ;
	 * une reprise dont l'échéance est atteinte ; un `sending` abandonné ; un
	 * événement programmé pour une demande qui n'existe plus.
	 *
	 * @param int|null $now Horodatage courant.
	 * @return array{planifiees:int,reprises:int,abandonnees:int,orphelins:int}
	 */
	public static function reconcile( ?int $now = null ): array {
		$now   = null === $now ? time() : $now;
		$bilan = array( 'planifiees' => 0, 'reprises' => 0, 'abandonnees' => 0, 'orphelins' => 0 );

		$candidats = get_posts(
			array(
				'post_type'        => SubmissionPostType::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => MailPolicy::META_STATUS,
						'value'   => array( MailPolicy::PENDING, MailPolicy::RETRY, MailPolicy::SENDING ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $candidats as $id ) {
			$id     = (int) $id;
			$statut = (string) get_post_meta( $id, MailPolicy::META_STATUS, true );

			if ( null === get_post( $id ) ) {
				self::unschedule( $id );
				MailQueue::release_lock( $id );
				++$bilan['orphelins'];

				continue;
			}

			if ( MailPolicy::SENDING === $statut ) {
				if ( ! MailPolicy::sending_is_stale( $id, $now ) ) {
					continue;
				}

				// Envoi abandonné : on le rend de nouveau traitable. Le même
				// identifiant de notification est conservé.
				$rang = (int) get_post_meta( $id, MailPolicy::META_ATTEMPTS, true );
				MailQueue::mark_failure( $id, max( 1, $rang ), 'sending_stale', $now );
				MailQueue::release_lock( $id );
				++$bilan['abandonnees'];

				continue;
			}

			if ( MailPolicy::RETRY === $statut ) {
				$echeance = (string) get_post_meta( $id, MailPolicy::META_NEXT_ATTEMPT, true );

				if ( '' !== $echeance && (int) strtotime( $echeance . ' UTC' ) > $now ) {
					continue;
				}

				if ( self::schedule( $id, $now ) ) {
					++$bilan['reprises'];
				}

				continue;
			}

			// `pending` sans événement programmé.
			if ( false === wp_next_scheduled( MailPolicy::EVENT, array( $id ) ) && self::schedule( $id, $now ) ) {
				++$bilan['planifiees'];
			}
		}

		return $bilan;
	}
}
