<?php
/**
 * Persistance de l'état des notifications.
 *
 * Toutes les écritures passent par `SubmissionRepository::persist_meta()` :
 * le retour de `update_post_meta()` ne prouve rien, seule la relecture fait
 * foi. Un `false` sur une valeur déjà en place est un succès, un `true` suivi
 * d'une relecture divergente est un échec (D-037).
 *
 * Ce composant ne rend aucun contenu, n'appelle aucun transport et ne
 * fabrique aucun lien signé. Il ne stocke ni corps, ni destinataire, ni lien,
 * ni signature, ni chemin — rien qui puisse transformer la base en copie des
 * données personnelles de la demande.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * File des notifications, portée par les métadonnées de la demande.
 */
final class MailQueue {

	/**
	 * Enregistre durablement une notification à envoyer.
	 *
	 * Appelée **pendant** la finalisation, avant que la demande ne soit
	 * considérée comme reçue : si cette écriture échoue, la finalisation
	 * échoue, et le retour arrière transactionnel de B2 reste applicable.
	 *
	 * Idempotente : une notification déjà créée n'est pas réinitialisée.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function create_pending( int $id, ?int $now = null, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$now      = null === $now ? time() : $now;
		$existant = (string) get_post_meta( $id, $slot->cle( MailPolicy::META_ID ), true );

		if ( '' === $existant ) {
			$existant = MailPolicy::new_notification_id();

			if ( ! SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_ID ), $existant ) ) {
				return false;
			}
		}

		$ecritures = array(
			$slot->cle( MailPolicy::META_STATUS )       => MailPolicy::PENDING,
			$slot->cle( MailPolicy::META_ATTEMPTS )     => 0,
			$slot->cle( MailPolicy::META_NEXT_ATTEMPT ) => gmdate( 'Y-m-d H:i:s', $now ),
			$slot->cle( MailPolicy::META_LAST_ERROR )   => '',
		);

		foreach ( $ecritures as $cle => $valeur ) {
			if ( ! SubmissionRepository::persist_meta( $id, $cle, $valeur ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * État complet d'une notification, sans aucune donnée personnelle.
	 *
	 * @param int $id Demande.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return array{status:string,notification_id:string,attempts:int,last_attempt:string,next_attempt:string,sent_at:string,last_error:string}
	 */
	public static function state( int $id, ?NotificationSlot $slot = null ): array {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		return array(
			'status'          => (string) get_post_meta( $id, $slot->cle( MailPolicy::META_STATUS ), true ),
			'notification_id' => (string) get_post_meta( $id, $slot->cle( MailPolicy::META_ID ), true ),
			'attempts'        => (int) get_post_meta( $id, $slot->cle( MailPolicy::META_ATTEMPTS ), true ),
			'last_attempt'    => (string) get_post_meta( $id, $slot->cle( MailPolicy::META_LAST_ATTEMPT ), true ),
			'next_attempt'    => (string) get_post_meta( $id, $slot->cle( MailPolicy::META_NEXT_ATTEMPT ), true ),
			'sent_at'         => (string) get_post_meta( $id, $slot->cle( MailPolicy::META_SENT_AT ), true ),
			'last_error'      => (string) get_post_meta( $id, $slot->cle( MailPolicy::META_LAST_ERROR ), true ),
		);
	}

	/**
	 * Passe la notification en cours d'envoi.
	 *
	 * @param int $id      Demande.
	 * @param int $rang    Numéro de la tentative.
	 * @param int $now     Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function mark_sending( int $id, int $rang, int $now, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$ecritures = array(
			$slot->cle( MailPolicy::META_STATUS )       => MailPolicy::SENDING,
			$slot->cle( MailPolicy::META_ATTEMPTS )     => $rang,
			$slot->cle( MailPolicy::META_LAST_ATTEMPT ) => gmdate( 'Y-m-d H:i:s', $now ),
		);

		foreach ( $ecritures as $cle => $valeur ) {
			if ( ! SubmissionRepository::persist_meta( $id, $cle, $valeur ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Enregistre un envoi accepté par WordPress.
	 *
	 * `wp_mail()` rendant `true` signifie que WordPress a **accepté la requête
	 * d'envoi** — pas que le message est arrivé dans une boîte. L'état `sent`
	 * ne prétend rien de plus.
	 *
	 * @param int $id  Demande.
	 * @param int $now Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function mark_sent( int $id, int $now, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$ecritures = array(
			$slot->cle( MailPolicy::META_STATUS )      => MailPolicy::SENT,
			$slot->cle( MailPolicy::META_SENT_AT )     => gmdate( 'Y-m-d H:i:s', $now ),
			$slot->cle( MailPolicy::META_LAST_ERROR )  => '',
		);

		foreach ( $ecritures as $cle => $valeur ) {
			if ( ! SubmissionRepository::persist_meta( $id, $cle, $valeur ) ) {
				return false;
			}
		}

		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_NEXT_ATTEMPT ), '' );

		return true;
	}

	/**
	 * Enregistre un échec : reprise programmée, ou abandon.
	 *
	 * @param int    $id   Demande.
	 * @param int    $rang Tentative qui vient d'échouer.
	 * @param string $code Code technique, sans donnée personnelle.
	 * @param int    $now  Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return string État résultant.
	 */
	public static function mark_failure( int $id, int $rang, string $code, int $now, ?NotificationSlot $slot = null ): string {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_LAST_ERROR ), $code );

		if ( $rang >= MailPolicy::MAX_ATTEMPTS ) {
			SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_STATUS ), MailPolicy::FAILED );
			SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_NEXT_ATTEMPT ), '' );

			Logger::error(
				sprintf(
					'notification #%d [%s] : abandon après %d tentative(s) — %s',
					$id,
					MailPolicy::short_id( (string) get_post_meta( $id, $slot->cle( MailPolicy::META_ID ), true ) ),
					$rang,
					$code
				)
			);

			return MailPolicy::FAILED;
		}

		$prochain = $now + MailPolicy::delay_for( $rang + 1 );

		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_STATUS ), MailPolicy::RETRY );
		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_NEXT_ATTEMPT ), gmdate( 'Y-m-d H:i:s', $prochain ) );

		Logger::info(
			sprintf(
				'notification #%d [%s] : tentative %d en échec (%s), reprise programmée',
				$id,
				MailPolicy::short_id( (string) get_post_meta( $id, $slot->cle( MailPolicy::META_ID ), true ) ),
				$rang,
				$code
			)
		);

		return MailPolicy::RETRY;
	}

	/**
	 * Annule une notification jamais envoyée.
	 *
	 * Une notification déjà `sent` n'est jamais annulée : l'information est
	 * partie, la nier serait un mensonge.
	 *
	 * @param int    $id    Demande.
	 * @param string $motif Code technique.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool Vrai si l'annulation a eu lieu.
	 */
	public static function cancel( int $id, string $motif, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$statut = (string) get_post_meta( $id, $slot->cle( MailPolicy::META_STATUS ), true );

		if ( ! in_array( $statut, array( MailPolicy::PENDING, MailPolicy::RETRY, MailPolicy::SENDING ), true ) ) {
			return false;
		}

		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_STATUS ), MailPolicy::CANCELLED );
		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_NEXT_ATTEMPT ), '' );
		SubmissionRepository::persist_meta( $id, $slot->cle( MailPolicy::META_LAST_ERROR ), $motif );

		Logger::info( sprintf( 'notification #%d annulée : %s', $id, $motif ) );

		return true;
	}

	/**
	 * Remet une notification annulée ou abandonnée en attente.
	 *
	 * Le même identifiant de notification est **réutilisé** : un éventuel
	 * doublon reste ainsi reconnaissable côté boîte de réception.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function requeue( int $id, ?int $now = null, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$now    = null === $now ? time() : $now;
		$statut = (string) get_post_meta( $id, $slot->cle( MailPolicy::META_STATUS ), true );

		// Un envoi déjà accepté ne se rejoue jamais tout seul.
		if ( MailPolicy::SENT === $statut ) {
			return false;
		}

		$ecritures = array(
			$slot->cle( MailPolicy::META_STATUS )       => MailPolicy::PENDING,
			$slot->cle( MailPolicy::META_ATTEMPTS )     => 0,
			$slot->cle( MailPolicy::META_NEXT_ATTEMPT ) => gmdate( 'Y-m-d H:i:s', $now ),
			$slot->cle( MailPolicy::META_LAST_ERROR )   => '',
		);

		foreach ( $ecritures as $cle => $valeur ) {
			if ( ! SubmissionRepository::persist_meta( $id, $cle, $valeur ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prend le verrou d'une notification.
	 *
	 * `add_option()` n'aboutit qu'une fois pour un même nom : c'est la seule
	 * primitive réellement atomique dont on dispose sans table dédiée.
	 *
	 * Le verrou porte un **jeton propriétaire** aléatoire. Sans lui, n'importe
	 * quel processus pouvait supprimer le verrou d'un autre — et c'est
	 * exactement ce que faisait le nettoyage de fichiers. Avec lui, un ancien
	 * propriétaire dont le verrou a expiré et été repris ne peut plus ni
	 * écrire, ni libérer.
	 *
	 * L'option est en `autoload = false` et ne contient aucune donnée
	 * personnelle : un jeton technique et une échéance.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return string|null Jeton propriétaire, ou `null` si le verrou est pris.
	 */
	public static function acquire_lock( int $id, ?int $now = null, ?NotificationSlot $slot = null ): ?string {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$now      = null === $now ? time() : $now;
		$cle      = MailPolicy::LOCK_PREFIX . $slot->cle_verrou();
		$existant = get_option( $cle, null );

		if ( is_array( $existant ) ) {
			if ( $now < (int) ( $existant['expires'] ?? 0 ) ) {
				return null;
			}

			// Verrou périmé : son propriétaire ne peut plus s'exécuter, la
			// durée de vie étant supérieure au temps d'exécution maximal.
			delete_option( $cle );
		}

		$jeton = self::new_token();

		// Le nouveau propriétaire obtient un **nouveau** jeton : l'ancien, s'il
		// revenait, ne serait plus reconnu.
		if ( ! add_option( $cle, array( 'owner' => $jeton, 'expires' => $now + MailPolicy::lock_ttl() ), '', false ) ) {
			return null;
		}

		return $jeton;
	}

	/**
	 * Le jeton est-il toujours propriétaire du verrou ?
	 *
	 * @param int      $id    Demande.
	 * @param string   $jeton Jeton.
	 * @param int|null $now   Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function owns_lock( int $id, string $jeton, ?int $now = null, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$now      = null === $now ? time() : $now;
		$existant = get_option( MailPolicy::LOCK_PREFIX . $slot->cle_verrou(), null );

		if ( ! is_array( $existant ) || '' === $jeton ) {
			return false;
		}

		if ( $now >= (int) ( $existant['expires'] ?? 0 ) ) {
			return false;
		}

		return hash_equals( (string) ( $existant['owner'] ?? '' ), $jeton );
	}

	/**
	 * Rend le verrou, **si et seulement si** on en est le propriétaire.
	 *
	 * @param int    $id    Demande.
	 * @param string $jeton Jeton.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool Vrai si le verrou a bien été rendu par son propriétaire.
	 */
	public static function release_lock( int $id, string $jeton, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$existant = get_option( MailPolicy::LOCK_PREFIX . $slot->cle_verrou(), null );

		if ( ! is_array( $existant ) || '' === $jeton ) {
			return false;
		}

		if ( ! hash_equals( (string) ( $existant['owner'] ?? '' ), $jeton ) ) {
			// Le verrou appartient à quelqu'un d'autre : on n'y touche pas.
			return false;
		}

		delete_option( MailPolicy::LOCK_PREFIX . $slot->cle_verrou() );

		return true;
	}

	/**
	 * Exécute un travail sous les **deux** couches de verrouillage.
	 *
	 * Ordre d'acquisition **unique**, respecté par tous les composants :
	 *
	 * 1. le **mutex de processus** (`flock`), qui dit si une opération est
	 *    réellement en cours ;
	 * 2. le **verrou d'option**, qui porte le propriétaire logique et le bail.
	 *
	 * Un ordre unique, c'est l'absence d'interblocage. Le mutex est l'autorité :
	 * tant qu'il est détenu, aucune transition concurrente n'est permise, même
	 * si le bail d'option est dépassé. Le bail, lui, sert à l'observabilité, à
	 * la réconciliation différée et à la coordination durable.
	 *
	 * Fermé par défaut : si le mutex ne peut être ni créé ni acquis pour une
	 * raison technique, le travail n'a pas lieu.
	 *
	 * @param int      $id      Demande.
	 * @param callable $travail Rappel, recevant la poignée.
	 * @param int|null $now     Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return array{ok:bool,code:string,valeur:mixed}
	 */
	public static function with_process_lock( int $id, callable $travail, ?int $now = null, ?NotificationSlot $slot = null ) {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		$now     = null === $now ? time() : $now;
		$poignee = MailProcessLock::acquire( $id, $slot );

		if ( null === $poignee ) {
			return array( 'ok' => false, 'code' => 'mutex_indisponible', 'valeur' => null );
		}

		try {
			// Le bail d'option est **réconcilié** sous le mutex : un bail périmé
			// laissé par une tentative précédente n'appartient plus à personne,
			// puisque personne ne détenait le mutex.
			$jeton = self::acquire_lock( $id, $now, $slot );

			if ( null === $jeton ) {
				$jeton = self::reconcile_lease( $id, $now, $slot );
			}

			if ( null === $jeton ) {
				return array( 'ok' => false, 'code' => 'bail_indisponible', 'valeur' => null );
			}

			$poignee->set_jeton( $jeton );

			try {
				return array( 'ok' => true, 'code' => 'ok', 'valeur' => $travail( $poignee ) );
			} finally {
				self::release_lock( $id, $jeton, $slot );
			}
		} finally {
			MailProcessLock::release( $poignee );
		}
	}

	/**
	 * Rafraîchit le bail d'option, sous mutex détenu.
	 *
	 * Le bail a pu expirer pendant un appel au transport plus long que prévu.
	 * Le mutex prouvant que ce processus est bien le seul aux commandes, il a
	 * le droit — et le devoir — de remettre son bail à jour avant d'écrire son
	 * résultat.
	 *
	 * @param MailLockHandle        $poignee Poignée détenue.
	 * @param int                   $now     Horodatage courant.
	 * @param NotificationSlot|null $slot    Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function refresh_lease( MailLockHandle $poignee, int $now, ?NotificationSlot $slot = null ): bool {
		if ( ! $poignee->est_detenu() ) {
			return false;
		}

		$id   = $poignee->submission();
		$slot = $slot ?? NotificationSlot::admin( $id );

		if ( self::owns_lock( $id, $poignee->jeton(), $now, $slot ) ) {
			return true;
		}

		$jeton = self::reconcile_lease( $id, $now, $slot );

		if ( null === $jeton ) {
			return false;
		}

		$poignee->set_jeton( $jeton );

		return true;
	}

	/**
	 * Reprend le bail d'option, sous mutex détenu.
	 *
	 * Détenir le mutex prouve qu'aucun autre processus ne travaille : un bail
	 * qui subsiste est donc celui d'un processus disparu, et peut être repris
	 * quelle que soit son échéance.
	 *
	 * @param int                   $id   Demande.
	 * @param int                   $now  Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return string|null
	 */
	private static function reconcile_lease( int $id, int $now, ?NotificationSlot $slot = null ): ?string {
		$slot = $slot ?? NotificationSlot::admin( $id );

		delete_option( MailPolicy::LOCK_PREFIX . $slot->cle_verrou() );

		return self::acquire_lock( $id, $now, $slot );
	}

	/**
	 * Exécute un travail en section critique.
	 *
	 * Alias de `with_process_lock()` : **toutes** les transitions de
	 * notification passent par les deux couches. Aucun chemin ne se contente
	 * du bail d'option.
	 *
	 * @param int      $id      Demande.
	 * @param callable $travail Rappel, recevant la poignée.
	 * @param int|null $now     Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return array{ok:bool,code:string,valeur:mixed}
	 */
	public static function with_lock( int $id, callable $travail, ?int $now = null, ?NotificationSlot $slot = null ) {
		return self::with_process_lock( $id, $travail, $now, $slot );
	}

	/**
	 * Un envoi est-il en cours pour cette demande ?
	 *
	 * Le verrou seul fait foi : l'état `sending` peut subsister après une
	 * requête tuée, alors qu'un verrou vivant signifie qu'un processus est
	 * réellement en train de travailler.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @param NotificationSlot|null $slot Créneau visé ; à défaut, la notification interne.
	 * @return bool
	 */
	public static function is_locked( int $id, ?int $now = null, ?NotificationSlot $slot = null ): bool {
		// Sans créneau explicite, la file agit sur la notification interne :
		// c'est le comportement historique, et tous les appelants existants en
		// dépendent sans avoir à changer.
		$slot = $slot ?? NotificationSlot::admin( $id );
		// **Le mutex fait autorité.** Un bail périmé ne prouve pas que son
		// propriétaire est mort ; un mutex détenu prouve qu'il est vivant.
		if ( MailProcessLock::is_held( $id, $slot ) ) {
			return true;
		}

		$now      = null === $now ? time() : $now;
		$existant = get_option( MailPolicy::LOCK_PREFIX . $slot->cle_verrou(), null );

		return is_array( $existant ) && $now < (int) ( $existant['expires'] ?? 0 );
	}

	/**
	 * Fabrique un jeton propriétaire.
	 *
	 * @return string
	 */
	private static function new_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return wp_generate_password( 32, false, false );
		}
	}
}
