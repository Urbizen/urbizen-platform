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
	 * Programme le traitement d'une demande, sans jamais créer de doublon.
	 *
	 * `wp_next_scheduled()` suivi de `wp_schedule_single_event()` n'est **pas**
	 * atomique : deux processus peuvent tous deux constater l'absence de
	 * l'événement, et tous deux en créer un. La vérification et la création
	 * sont donc encadrées par le verrou de la notification.
	 *
	 * @param int         $id    Demande.
	 * @param int|null    $now   Horodatage courant.
	 * @param MailLockHandle|null $poignee Poignée, si le mutex est déjà tenu.
	 * @return bool Vrai si un événement est programmé après cet appel.
	 */
	public static function schedule_unique( int $id, ?int $now = null, ?MailLockHandle $poignee = null ): bool {
		$now = null === $now ? time() : $now;

		// Mutex déjà tenu par l'appelant : on travaille directement, sans
		// chercher à le reprendre — ce serait un interblocage.
		if ( null !== $poignee && $poignee->est_detenu() ) {
			return self::poser_evenement( $id, $now );
		}

		$resultat = MailQueue::with_lock(
			$id,
			static fn( MailLockHandle $poignee ) => self::poser_evenement( $id, $now ),
			$now
		);

		return ! empty( $resultat['ok'] ) && true === $resultat['valeur'];
	}

	/**
	 * Alias historique.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @return bool
	 */
	public static function schedule( int $id, ?int $now = null ): bool {
		return self::schedule_unique( $id, $now );
	}

	/**
	 * Pose l'événement s'il n'existe pas déjà. À n'appeler que sous verrou.
	 *
	 * @param int $id  Demande.
	 * @param int $now Horodatage courant.
	 * @return bool
	 */
	private static function poser_evenement( int $id, int $now ): bool {
		$args = array( $id );

		if ( false !== wp_next_scheduled( MailPolicy::EVENT, $args ) ) {
			return true;
		}

		if ( false === wp_schedule_single_event( $now, MailPolicy::EVENT, $args ) ) {
			return false;
		}

		// Relecture : sans événement réellement inscrit, on ne prétend pas
		// l'avoir programmé.
		return false !== wp_next_scheduled( MailPolicy::EVENT, $args );
	}

	/**
	 * Retire **tous** les événements résiduels d'une demande.
	 *
	 * Une seule suppression ne suffit pas : un doublon créé avant que la
	 * planification ne devienne atomique doit pouvoir être retiré lui aussi.
	 *
	 * @param int $id Demande.
	 * @return int Nombre d'événements retirés.
	 */
	public static function unschedule_all( int $id ): int {
		$args    = array( $id );
		$retires = 0;

		// Boucle bornée : `wp_next_scheduled()` ne rend qu'une occurrence à la
		// fois, et un compteur évite toute boucle infinie sur un cron fautif.
		for ( $i = 0; $i < 50; $i++ ) {
			$programme = wp_next_scheduled( MailPolicy::EVENT, $args );

			if ( false === $programme ) {
				break;
			}

			wp_unschedule_event( $programme, MailPolicy::EVENT, $args );
			++$retires;
		}

		return $retires;
	}

	/**
	 * Alias historique.
	 *
	 * @param int $id Demande.
	 * @return void
	 */
	public static function unschedule( int $id ): void {
		self::unschedule_all( $id );
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
	 * Toute la séquence — relecture, passage à `sending`, rendu, appel au
	 * transport, écriture du résultat — se déroule sous **un seul** verrou.
	 * Une ultime vérification d'éligibilité a lieu immédiatement avant l'appel
	 * au transport : si une mise à la Corbeille a gagné la course entre-temps,
	 * aucun courriel ne part.
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

		// Une demande supprimée laisse parfois un événement derrière elle : il
		// doit être sans effet. Aucun verrou n'est touché ici — il pourrait
		// appartenir à un autre processus.
		if ( null === get_post( $id ) ) {
			return 'post_absent';
		}

		$motif = MailPolicy::blocker( $id, $now );

		if ( null !== $motif ) {
			Logger::info( sprintf( 'notification #%d non envoyée : %s', $id, $motif ) );

			return $motif;
		}

		$resultat = MailQueue::with_lock(
			$id,
			static function ( MailLockHandle $poignee ) use ( $id, $now ) {
				return self::envoyer_sous_verrou( $id, $poignee, $now );
			},
			$now
		);

		if ( empty( $resultat['ok'] ) ) {
			return (string) $resultat['code'];
		}

		return (string) $resultat['valeur'];
	}

	/**
	 * Séquence d'envoi, exécutée en section critique.
	 *
	 * @param int    $id    Demande.
	 * @param MailLockHandle $poignee Poignée détenue.
	 * @param int    $now   Horodatage courant.
	 * @return string Code technique.
	 */
	private static function envoyer_sous_verrou( int $id, MailLockHandle $poignee, int $now ): string {
		// Relecture sous verrou : l'état a pu changer entre le contrôle
		// préalable et l'obtention du verrou.
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

		// **Ultime vérification, au plus près de l'appel.** Le rendu a pu
		// prendre du temps ; une mise à la Corbeille a pu aboutir. On relit
		// l'état réel, cache vidé, et on renonce plutôt que d'envoyer la
		// notification d'un dossier retiré.
		if ( ! $poignee->est_detenu() || ! MailQueue::owns_lock( $id, $poignee->jeton(), $now ) ) {
			return 'verrou_perdu';
		}

		self::oublier_cache( $id );

		// `closed_blocker` et non `blocker` : l'état vaut `sending`, puisque
		// c'est nous qui venons de l'écrire. Ce qu'on relit, c'est tout le
		// reste — en particulier le statut natif du contenu.
		$motif = MailPolicy::closed_blocker( $id );

		if ( null !== $motif ) {
			// L'état est passé à fermé pendant la préparation : on ne consomme
			// pas la tentative, la situation n'ayant rien d'un échec technique.
			Logger::info( sprintf( 'notification #%d abandonnée avant envoi : %s', $id, $motif ) );

			return $motif;
		}

		$resultat = self::transport()->send(
			$message['to'],
			$message['subject'],
			$message['body'],
			$message['headers']
		);

		// Le verrou a-t-il tenu pendant l'appel ? S'il a été repris, un autre
		// processus est aux commandes : on n'écrase pas son travail.
		// Le mutex a-t-il tenu ? Il est l'autorité : le bail d'option a pu
		// expirer pendant l'appel sans que cela change quoi que ce soit.
		if ( ! $poignee->est_detenu() ) {
			Logger::error( sprintf( 'notification #%d : mutex perdu pendant l’envoi', $id ) );

			return 'verrou_perdu';
		}

		// Le bail, lui, se réconcilie sous le mutex.
		MailQueue::refresh_lease( $poignee, $now );

		// Dernière garantie avant d'écrire : la demande ne doit pas s'être
		// fermée pendant l'appel. Si elle l'a fait, l'envoi a bien eu lieu — la
		// politique « au moins une fois » l'assume — mais on n'écrit pas `sent`
		// par-dessus une annulation légitime.
		$ferme = MailPolicy::closed_blocker( $id );

		if ( null !== $ferme ) {
			Logger::error( sprintf( 'notification #%d : envoi accepté mais demande fermée entre-temps (%s)', $id, $ferme ) );

			return 'ferme_pendant_envoi';
		}

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
	}

	/**
	 * Oublie le cache d'objets d'un contenu.
	 *
	 * Sans cela, une relecture de `post_status` pourrait rendre la valeur que
	 * ce processus a chargée avant que l'autre ne la change.
	 *
	 * @param int $id Demande.
	 * @return void
	 */
	private static function oublier_cache( int $id ): void {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $id );
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
				self::unschedule_all( $id );
				++$bilan['orphelins'];

				continue;
			}

			// Un envoi en cours n'est pas réconcilié : son propriétaire
			// travaille. Le verrou, et non l'état, fait foi.
			if ( MailQueue::is_locked( $id, $now ) ) {
				continue;
			}

			if ( MailPolicy::SENDING === $statut ) {
				if ( ! MailPolicy::sending_is_stale( $id, $now ) ) {
					continue;
				}

				// Envoi abandonné, et aucun verrou vivant : on le rend de
				// nouveau traitable, sous verrou, avec le même identifiant.
				$reprise = MailQueue::with_lock(
					$id,
					static function ( MailLockHandle $poignee ) use ( $id, $now ) {
						if ( MailPolicy::SENDING !== (string) get_post_meta( $id, MailPolicy::META_STATUS, true ) ) {
							return false;
						}

						$rang = (int) get_post_meta( $id, MailPolicy::META_ATTEMPTS, true );
						MailQueue::mark_failure( $id, max( 1, $rang ), 'sending_stale', $now );

						return true;
					},
					$now
				);

				if ( ! empty( $reprise['ok'] ) && true === $reprise['valeur'] ) {
					++$bilan['abandonnees'];
				}

				continue;
			}

			if ( MailPolicy::RETRY === $statut ) {
				$echeance = (string) get_post_meta( $id, MailPolicy::META_NEXT_ATTEMPT, true );

				if ( '' !== $echeance && (int) strtotime( $echeance . ' UTC' ) > $now ) {
					continue;
				}

				if ( self::schedule_unique( $id, $now ) ) {
					++$bilan['reprises'];
				}

				continue;
			}

			// `pending` sans événement programmé.
			if ( false === wp_next_scheduled( MailPolicy::EVENT, array( $id ) ) && self::schedule_unique( $id, $now ) ) {
				++$bilan['planifiees'];
			}
		}

		return $bilan;
	}
}
