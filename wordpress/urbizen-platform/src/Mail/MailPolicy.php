<?php
/**
 * Politique de notification administrative.
 *
 * Source **serveur unique** des états, des délais de reprise, du destinataire
 * et des conditions d'éligibilité. Rien de tout cela ne doit se décider
 * ailleurs, et surtout pas à partir d'une donnée de formulaire.
 *
 * Le principe directeur : **l'échec d'un courriel n'invalide jamais une
 * demande.** Un dossier reçu reste reçu, que le transport de messagerie
 * fonctionne ou non. La notification est une conséquence de la réception, pas
 * une condition de celle-ci.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TrashGuard;

defined( 'ABSPATH' ) || exit;

/**
 * États, délais, destinataire et éligibilité.
 */
final class MailPolicy {

	// --- États de notification ---
	public const PENDING   = 'pending';
	public const SENDING   = 'sending';
	public const RETRY     = 'retry';
	public const SENT      = 'sent';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	/**
	 * État d'une demande qui n'a jamais eu de notification.
	 *
	 * Valeur héritée de B1, conservée pour ne pas réécrire l'existant.
	 */
	public const NOT_STARTED = 'not_started';

	// --- Métadonnées ---
	public const META_STATUS       = '_urbizen_mail_status';
	public const META_ID           = '_urbizen_mail_notification_id';
	public const META_ATTEMPTS     = '_urbizen_mail_attempts';
	public const META_LAST_ATTEMPT = '_urbizen_mail_last_attempt_gmt';
	public const META_NEXT_ATTEMPT = '_urbizen_mail_next_attempt_gmt';
	public const META_SENT_AT      = '_urbizen_mail_sent_at_gmt';
	public const META_LAST_ERROR   = '_urbizen_mail_last_error_code';

	/**
	 * Nombre maximal de tentatives.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Délai au-delà duquel un `sending` est jugé abandonné.
	 *
	 * Une requête PHP tuée pendant l'envoi laisse l'état `sending` derrière
	 * elle. Sans ce délai, la notification resterait bloquée pour toujours.
	 */
	public const SENDING_TTL = 900;

	/**
	 * Événement de traitement d'une notification.
	 */
	public const EVENT = 'urbizen_send_submission_mail';

	/**
	 * Préfixe des verrous de notification.
	 */
	public const LOCK_PREFIX = 'urbizen_mail_lock_';

	/**
	 * Durée de vie d'un verrou, en secondes.
	 *
	 * **Un verrou ne doit jamais expirer pendant que son propriétaire peut
	 * encore s'exécuter.** La production autorise `max_execution_time = 360` :
	 * un verrou de 300 s pouvait donc être repris alors que son propriétaire
	 * était toujours en train d'appeler le transport. 600 s laisse une marge
	 * franche au-delà du temps d'exécution maximal.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Temps d'exécution maximal constaté en production.
	 *
	 * Sert de plancher au TTL : un contrôle nommé refuse tout réglage qui
	 * repasserait sous cette valeur.
	 */
	public const MAX_EXECUTION = 360;

	/**
	 * Durée de vie effective d'un verrou.
	 *
	 * Filtrable pour les bancs, mais **jamais** en dessous du temps
	 * d'exécution maximal : un réglage fautif ne doit pas pouvoir réintroduire
	 * la reprise d'un verrou encore détenu.
	 *
	 * @return int
	 */
	public static function lock_ttl(): int {
		$filtre = (int) apply_filters( 'urbizen_mail_lock_ttl', self::LOCK_TTL );

		// Le plancher ne se lève **que** sous la constante d'essai, définie
		// hors du dépôt et jamais en production. Le mode CLI ne suffit pas :
		// les tâches planifiées s'y exécutent aussi.
		if ( defined( 'URBIZEN_TESTING' ) ) {
			return max( 1, $filtre );
		}

		return max( self::lock_floor(), $filtre );
	}

	/**
	 * Plancher de durée du bail.
	 *
	 * Un bail ne doit jamais expirer pendant que son propriétaire peut encore
	 * s'exécuter. Ce plancher reste une **précaution secondaire** : depuis
	 * D-040, l'autorité est le mutex de processus, qui ne repose sur aucune
	 * hypothèse de durée.
	 *
	 * @return int
	 */
	public static function lock_floor(): int {
		return self::MAX_EXECUTION + 1;
	}

	/**
	 * Délais avant chaque tentative, en secondes.
	 *
	 * L'indice est le **numéro de la tentative à venir**, à partir de 1. La
	 * première part sans attendre ; les suivantes s'espacent.
	 *
	 * Filtrables — les bancs s'en servent pour ne pas attendre douze heures.
	 *
	 * @return array<int, int>
	 */
	public static function retry_delays(): array {
		$defaut = array(
			1 => 0,
			2 => 5 * MINUTE_IN_SECONDS,
			3 => 30 * MINUTE_IN_SECONDS,
			4 => 2 * HOUR_IN_SECONDS,
			5 => 12 * HOUR_IN_SECONDS,
		);

		$filtre = apply_filters( 'urbizen_mail_retry_delays', $defaut );

		if ( ! is_array( $filtre ) || array() === $filtre ) {
			return $defaut;
		}

		$propre = array();

		foreach ( $filtre as $rang => $delai ) {
			$rang = (int) $rang;

			if ( $rang >= 1 && is_numeric( $delai ) && (int) $delai >= 0 ) {
				$propre[ $rang ] = (int) $delai;
			}
		}

		return array() === $propre ? $defaut : $propre;
	}

	/**
	 * Délai avant la tentative numéro `$rang`.
	 *
	 * @param int $rang Numéro de la tentative à venir, à partir de 1.
	 * @return int Secondes.
	 */
	public static function delay_for( int $rang ): int {
		$delais = self::retry_delays();

		if ( isset( $delais[ $rang ] ) ) {
			return (int) $delais[ $rang ];
		}

		// Au-delà de la table, on reprend le dernier délai connu plutôt que de
		// repartir à zéro : une reprise immédiate en boucle serait pire.
		return (int) end( $delais );
	}

	/**
	 * Destinataire administratif.
	 *
	 * Ordre strict : constante serveur, puis filtre, puis `admin_email`. Une
	 * donnée de formulaire ne peut jamais atteindre ce chemin.
	 *
	 * @return string Adresse valide, ou chaîne vide si aucune ne l'est.
	 */
	public static function recipient(): string {
		if ( defined( 'URBIZEN_SUBMISSION_RECIPIENT' ) ) {
			$constante = (string) constant( 'URBIZEN_SUBMISSION_RECIPIENT' );

			if ( is_email( $constante ) ) {
				return $constante;
			}
		}

		$filtre = apply_filters( 'urbizen_submission_recipient', '' );

		if ( is_string( $filtre ) && is_email( $filtre ) ) {
			return $filtre;
		}

		$admin = (string) get_option( 'admin_email' );

		return is_email( $admin ) ? $admin : '';
	}

	/**
	 * Fabrique un identifiant de notification.
	 *
	 * Généré côté serveur, aléatoire, sans aucune donnée personnelle, et
	 * stable pour toute la vie de la notification.
	 *
	 * @return string 32 caractères hexadécimaux.
	 */
	public static function new_notification_id(): string {
		try {
			$graine = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			// Repli : jamais une valeur devinable à partir de la demande seule.
			$graine = wp_generate_password( 32, false, false );
		}

		return substr( hash_hmac( 'sha256', $graine, wp_salt( 'urbizen_mail' ) ), 0, 32 );
	}

	/**
	 * Version tronquée, sûre pour un journal.
	 *
	 * @param string $identifiant Identifiant complet.
	 * @return string
	 */
	public static function short_id( string $identifiant ): string {
		return substr( $identifiant, 0, 8 );
	}

	/**
	 * États depuis lesquels un envoi peut être tenté.
	 *
	 * @return array<int, string>
	 */
	public static function sendable_statuses(): array {
		return array( self::PENDING, self::RETRY );
	}

	/**
	 * Ce qui empêche d'envoyer la notification d'une demande.
	 *
	 * Fermé par défaut : toute condition non réunie renvoie un code technique,
	 * jamais une donnée personnelle. Aucun lien signé n'est fabriqué avant que
	 * cette fonction ait rendu `null`.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @return string|null Code technique, ou `null` si l'envoi est permis.
	 */
	public static function blocker( int $id, ?int $now = null ): ?string {
		$now   = null === $now ? time() : $now;
		$ferme = self::closed_blocker( $id );

		if ( null !== $ferme ) {
			return $ferme;
		}

		$mail = (string) get_post_meta( $id, self::META_STATUS, true );

		if ( in_array( $mail, self::sendable_statuses(), true ) ) {
			return null;
		}

		// Un `sending` périmé est repris : c'est la trace d'une requête tuée en
		// plein envoi. Voir la politique « au moins une fois ».
		if ( self::SENDING === $mail && self::sending_is_stale( $id, $now ) ) {
			return null;
		}

		return 'mail_status_non_envoyable';
	}

	/**
	 * Ce qui ferme une demande à toute notification, **hors** état de la
	 * notification elle-même.
	 *
	 * Employée pour l'ultime vérification avant l'appel au transport : à cet
	 * instant, l'état vaut légitimement `sending`, puisque c'est nous qui
	 * venons de l'écrire. Ce qui doit être relu, c'est tout le reste — et en
	 * particulier le statut natif, qu'une mise à la Corbeille concurrente a pu
	 * changer.
	 *
	 * @param int $id Demande.
	 * @return string|null
	 */
	public static function closed_blocker( int $id ): ?string {
		$post = get_post( $id );

		if ( ! $post || SubmissionPostType::POST_TYPE !== $post->post_type ) {
			return 'post_absent';
		}

		// Le statut natif est la première barrière : c'est lui qui conditionne
		// la remise des documents.
		if ( SubmissionPostType::POST_STATUS !== (string) $post->post_status ) {
			return 'post_status_inattendu';
		}

		$statut = (string) get_post_meta( $id, '_urbizen_status', true );

		if ( ! in_array( $statut, SubmissionPostType::downloadable_statuses(), true ) ) {
			return 'statut_metier_non_final';
		}

		// Une transition de Corbeille, même seulement préparée, interdit tout
		// envoi : l'intention de retirer le dossier prime.
		if ( array() !== TrashGuard::transition( $id ) ) {
			return 'transition_corbeille_active';
		}

		$demande = SubmissionRepository::get( $id );

		if ( null === $demande ) {
			return 'demande_illisible';
		}

		$transaction = $demande['transaction'];
		$reference   = (string) $demande['reference'];

		if ( 'committed' !== ( $transaction['state'] ?? '' ) ) {
			return 'transaction_non_validee';
		}

		if ( '' === $reference || (string) ( $transaction['reference'] ?? '' ) !== $reference ) {
			return 'reference_divergente';
		}

		if ( ! in_array( (string) $demande['files_status'], array( 'stored', 'none' ), true ) ) {
			return 'documents_non_finaux';
		}

		$reservation = get_option( SubmissionRepository::RESERVATION_PREFIX . $reference, null );

		if ( ! is_array( $reservation ) || 'attributed' !== ( $reservation['state'] ?? '' ) ) {
			return 'reference_non_attribuee';
		}

		if ( (int) ( $reservation['post'] ?? 0 ) !== $id ) {
			return 'reservation_autre_demande';
		}

		$manquantes = array_diff( SubmissionRepository::REQUIRED_META, array_keys( get_post_meta( $id ) ) );

		if ( array() !== $manquantes ) {
			return 'metadonnees_incompletes';
		}

		if ( '' === self::recipient() ) {
			return 'recipient_unavailable';
		}

		return null;
	}

	/**
	 * Un état `sending` est-il abandonné ?
	 *
	 * @param int $id  Demande.
	 * @param int $now Horodatage courant.
	 * @return bool
	 */
	public static function sending_is_stale( int $id, int $now ): bool {
		$depuis = (string) get_post_meta( $id, self::META_LAST_ATTEMPT, true );

		if ( '' === $depuis ) {
			return true;
		}

		$horo = (int) strtotime( $depuis . ' UTC' );

		return $horo <= 0 || ( $now - $horo ) >= self::SENDING_TTL;
	}
}
