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
	 * Préfixe des transients de consommation.
	 */
	private const PREFIX = 'urbizen_tok_';

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
	 * Marque un jeton comme consommé.
	 *
	 * @param string $token Jeton.
	 * @return void
	 */
	public static function mark_used( string $token ): void {
		set_transient( self::transient_key( $token ), 1, self::MAX_AGE );
	}

	/**
	 * Un jeton a-t-il déjà servi ?
	 *
	 * @param string $token Jeton.
	 * @return bool
	 */
	public static function is_used( string $token ): bool {
		return false !== get_transient( self::transient_key( $token ) );
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
	 * Clé de transient d'un jeton.
	 *
	 * Condensat non réversible : la base ne contient jamais le jeton lui-même,
	 * seulement une empreinte qui permet de reconnaître un doublon.
	 *
	 * @param string $token Jeton.
	 * @return string
	 */
	private static function transient_key( string $token ): string {
		return self::PREFIX . substr( hash_hmac( 'sha256', $token, self::secret() ), 0, 40 );
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
