<?php
/**
 * Jeton anti-robot signé.
 *
 * Un formulaire public est une porte ouverte : il faut distinguer une personne
 * d'un script. Trois signaux indépendants y concourent — le nonce WordPress,
 * le pot de miel, et ce jeton. Aucun n'est suffisant seul.
 *
 * Le principe : le serveur émet un jeton au moment du rendu, le signe, et le
 * revérifie à la soumission. Il en déduit **le temps écoulé**, mesuré par le
 * serveur et non par le navigateur — un horodatage envoyé par le client se
 * falsifie en une ligne de JavaScript.
 *
 * Le jeton est de la forme `identifiant.emission.signature`. La signature
 * couvre les deux premiers champs : modifier l'heure d'émission invalide le
 * jeton.
 *
 * Rien de ce fichier n'est conservé dans une demande. Le jeton consommé n'est
 * mémorisé que sous forme de condensat non réversible, le temps de sa validité.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Security;

use Urbizen\Platform\Support\OptionsScan;

defined( 'ABSPATH' ) || exit;

/**
 * Émission et vérification des jetons anti-robot.
 */
final class AntiSpam {

	/**
	 * Délai minimal entre l'émission et la soumission, en secondes.
	 *
	 * Un humain qui remplit six étapes met bien davantage. Un script poste
	 * immédiatement.
	 */
	public const MIN_SECONDS = 3;

	/**
	 * Durée de validité d'un jeton, en secondes.
	 *
	 * Assez longue pour qu'une personne interrompue puisse revenir finir sa
	 * demande, assez courte pour qu'un stock de jetons ne se constitue pas.
	 */
	public const MAX_AGE = 86400;

	/**
	 * Préfixe des options de réservation.
	 *
	 * Une **option**, pas un transient. Un transient exprime une durée
	 * *maximale* de conservation, jamais une garantie : une purge du cache
	 * objet, un vidage LiteSpeed ou une éviction mémoire peuvent le faire
	 * disparaître avant terme — et rendre un jeton déjà consommé réutilisable.
	 *
	 * L'unicité de `option_name` en base fournit en revanche une primitive
	 * réellement atomique : `add_option()` échoue si le nom existe déjà, quel
	 * que soit le nombre de requêtes concurrentes.
	 */
	public const OPTION_PREFIX = 'urbizen_tok_';

	/**
	 * Sépare les trois champs du jeton.
	 */
	private const SEP = '.';

	/**
	 * Émet un jeton signé.
	 *
	 * @param int|null $now Horodatage d'émission (tests).
	 * @return string
	 */
	public static function issue_token( ?int $now = null ): string {
		$now     = null === $now ? time() : $now;
		$id      = bin2hex( random_bytes( 16 ) );
		$payload = $id . self::SEP . $now;

		return $payload . self::SEP . self::sign( $payload );
	}

	/**
	 * Vérifie un jeton.
	 *
	 * Renvoie un code d'erreur interne, jamais un message destiné au public :
	 * expliquer à un robot *pourquoi* il a été refusé, c'est l'aider.
	 *
	 * @param string   $token Jeton reçu.
	 * @param int|null $now   Horodatage courant (tests).
	 * @return array{ok:bool,code:string,id:string}
	 */
	public static function verify_token( string $token, ?int $now = null ): array {
		$now   = null === $now ? time() : $now;
		$parts = explode( self::SEP, $token );

		if ( 3 !== count( $parts ) ) {
			return self::refus( 'invalid_antispam_token' );
		}

		list( $id, $issued, $signature ) = $parts;

		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $id ) || 1 !== preg_match( '/^\d{1,12}$/', $issued ) ) {
			return self::refus( 'invalid_antispam_token' );
		}

		$attendue = self::sign( $id . self::SEP . $issued );

		// Comparaison à temps constant : une comparaison naïve laisse fuir la
		// signature attendue, octet par octet, par mesure du temps de réponse.
		if ( ! hash_equals( $attendue, $signature ) ) {
			return self::refus( 'invalid_antispam_token' );
		}

		$issued = (int) $issued;
		$age    = $now - $issued;

		// Un jeton daté du futur est une horloge faussée ou une falsification.
		if ( $age < 0 ) {
			return self::refus( 'invalid_antispam_token' );
		}

		if ( $age < self::min_seconds() ) {
			return self::refus( 'token_too_fast' );
		}

		if ( $age > self::MAX_AGE ) {
			return self::refus( 'token_expired' );
		}

		// La réservation n'a pas lieu ici : verify_token() ne décide que de la
		// validité intrinsèque. Réserver est un effet de bord, confié à
		// reserve_token() pour que l'appelant maîtrise le moment exact.
		if ( self::is_used( $token ) ) {
			return self::refus( 'duplicate_submission' );
		}

		return array(
			'ok'   => true,
			'code' => 'success',
			'id'   => $id,
		);
	}

	/**
	 * Réserve un jeton, de façon atomique.
	 *
	 * `add_option()` s'appuie sur l'unicité de `option_name` : de deux requêtes
	 * concurrentes portant le même jeton, une seule peut réussir. C'est ce qui
	 * ferme la fenêtre entre « vérifier » et « marquer », par laquelle deux
	 * soumissions simultanées passaient toutes les deux.
	 *
	 * La réservation vaut occupation : une seconde requête reçue **pendant** le
	 * traitement de la première est refusée, sans attendre que celle-ci ait fini
	 * d'écrire sa demande.
	 *
	 * @param string   $token Jeton.
	 * @param int|null $now   Horodatage courant (tests).
	 * @return bool Vrai si la réservation est acquise.
	 */
	public static function reserve_token( string $token, ?int $now = null ): bool {
		$now = null === $now ? time() : $now;
		$cle = self::option_key( $token );

		$existante = get_option( $cle, null );

		if ( is_array( $existante ) ) {
			// Une réservation périmée se recycle. Ce chemin ne concerne en
			// pratique que des jetons déjà refusés par le contrôle de date : il
			// existe pour que le nettoyage ne soit jamais indispensable.
			if ( isset( $existante['expires'] ) && $now >= (int) $existante['expires'] ) {
				delete_option( $cle );
			} else {
				return false;
			}
		}

		return (bool) add_option(
			$cle,
			array(
				'state'   => 'reserved',
				'expires' => $now + self::MAX_AGE,
			),
			'',
			false
		);
	}

	/**
	 * Confirme la consommation définitive d'un jeton.
	 *
	 * La réservation est conservée jusqu'à l'expiration du jeton : c'est elle
	 * qui interdit le rejeu.
	 *
	 * @param string   $token Jeton.
	 * @param int|null $now   Horodatage courant (tests).
	 * @return void
	 */
	public static function consume_token( string $token, ?int $now = null ): void {
		$now = null === $now ? time() : $now;

		update_option(
			self::option_key( $token ),
			array(
				'state'   => 'consumed',
				'expires' => $now + self::MAX_AGE,
			),
			false
		);
	}

	/**
	 * Libère une réservation.
	 *
	 * Appelée quand le traitement échoue pour une raison corrigible : la
	 * personne doit pouvoir rectifier son formulaire et renvoyer, sans que son
	 * jeton ait été brûlé par un refus qui n'est pas de son fait.
	 *
	 * @param string $token Jeton.
	 * @return void
	 */
	public static function release_token( string $token ): void {
		delete_option( self::option_key( $token ) );
	}

	/**
	 * Un jeton a-t-il déjà servi ou est-il en cours de traitement ?
	 *
	 * @param string   $token Jeton.
	 * @param int|null $now   Horodatage courant (tests).
	 * @return bool
	 */
	public static function is_used( string $token, ?int $now = null ): bool {
		$now       = null === $now ? time() : $now;
		$existante = get_option( self::option_key( $token ), null );

		if ( ! is_array( $existante ) ) {
			return false;
		}

		return ! isset( $existante['expires'] ) || $now < (int) $existante['expires'];
	}

	/**
	 * Supprime les réservations expirées.
	 *
	 * Idempotent : deux passages consécutifs donnent le même état. Aucun jeton
	 * ni condensat complet n'est journalisé — seul un décompte l'est.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de réservations supprimées.
	 */
	public static function cleanup_expired_tokens( ?int $now = null ): int {
		$now       = null === $now ? time() : $now;
		$supprimees = 0;

		foreach ( OptionsScan::names( self::OPTION_PREFIX ) as $cle ) {
			$valeur = get_option( $cle, null );

			if ( ! is_array( $valeur ) || ! isset( $valeur['expires'] ) ) {
				delete_option( $cle );
				++$supprimees;
				continue;
			}

			if ( $now >= (int) $valeur['expires'] ) {
				delete_option( $cle );
				++$supprimees;
			}
		}

		return $supprimees;
	}

	/**
	 * Délai minimal, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function min_seconds(): int {
		return (int) apply_filters( 'urbizen_antispam_min_seconds', self::MIN_SECONDS );
	}

	/**
	 * Signature HMAC d'une charge utile.
	 *
	 * @param string $payload Charge utile.
	 * @return string
	 */
	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	/**
	 * Secret de signature.
	 *
	 * Les sels WordPress vivent dans `wp-config.php`, hors dépôt. Aucun secret
	 * n'est donc écrit ici ni versionné.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return wp_salt( 'auth' ) . '|urbizen-antispam';
	}

	/**
	 * Nom d'option d'un jeton.
	 *
	 * Condensat non réversible : la base ne contient jamais le jeton lui-même,
	 * ni sa signature, ni son identifiant lisible — seulement une empreinte qui
	 * permet de reconnaître un doublon. Longueur totale 52 caractères, bien en
	 * deçà de la limite de `option_name`.
	 *
	 * @param string $token Jeton.
	 * @return string
	 */
	public static function option_key( string $token ): string {
		return self::OPTION_PREFIX . substr( hash_hmac( 'sha256', $token, self::secret() ), 0, 40 );
	}

	/**
	 * Fabrique un refus.
	 *
	 * @param string $code Code interne.
	 * @return array{ok:bool,code:string,id:string}
	 */
	private static function refus( string $code ): array {
		return array(
			'ok'   => false,
			'code' => $code,
			'id'   => '',
		);
	}
}
