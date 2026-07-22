<?php
/**
 * Jeton de vérification, lié à ce qu'il confirme.
 *
 * **Le jeton brut n'est jamais stocké.** Seul son condensat l'est, et ce
 * condensat couvre bien plus que le jeton :
 *
 *     HMAC( identifiant | cible | génération | jeton )
 *
 * La **cible** est l'adresse que ce jeton confirme. Sans elle, un lien émis
 * pour une adresse en attente pourrait en confirmer une autre : il suffirait de
 * demander un changement, de recevoir le lien, puis de demander un second
 * changement avant de cliquer. La **génération** s'incrémente à chaque
 * émission ; un ancien lien ne recalcule alors plus rien de valide, même si la
 * cible n'a pas bougé.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use RuntimeException;

/**
 * Émission et validation des jetons.
 */
final class JetonVerification {

	public const META_CONDENSAT = '_urbizen_verif_condensat';
	public const META_EXPIRE    = '_urbizen_verif_expire';
	public const META_CIBLE     = '_urbizen_verif_cible';
	public const META_GENERATION = '_urbizen_verif_generation';

	/**
	 * Durée de validité, en secondes.
	 */
	public const TTL = 86400;

	/**
	 * Longueur du jeton en hexadécimal.
	 */
	public const LONGUEUR = 64;

	/**
	 * Engendre un jeton brut.
	 *
	 * @return string 64 caractères hexadécimaux, soit 256 bits.
	 *
	 * @throws RuntimeException Si l'aléa cryptographique est indisponible.
	 */
	public static function engendrer(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			// Aucun repli sur `mt_rand()` : un jeton prévisible vaut moins que
			// pas de jeton du tout.
			throw new RuntimeException( 'alea_indisponible', 0, $e );
		}
	}

	/**
	 * Le jeton a-t-il la forme attendue ?
	 *
	 * Contrôle préalable, avant toute lecture : il évite d'aller interroger le
	 * stockage pour une valeur qui ne peut pas être un jeton.
	 *
	 * @param string $jeton Jeton brut.
	 * @return bool
	 */
	public static function forme_valide( string $jeton ): bool {
		return 1 === preg_match( '/^[0-9a-f]{' . self::LONGUEUR . '}$/', $jeton );
	}

	/**
	 * Condensat liant le jeton à son compte, sa cible et sa génération.
	 *
	 * @param int    $compte     Identifiant.
	 * @param string $cible      Adresse canonique confirmée par ce jeton.
	 * @param int    $generation Génération.
	 * @param string $jeton      Jeton brut.
	 * @return string
	 */
	public static function condensat( int $compte, string $cible, int $generation, string $jeton ): string {
		$charge = implode( '|', array( (string) $compte, $cible, (string) $generation, $jeton ) );

		return hash_hmac( 'sha256', $charge, self::secret() );
	}

	/**
	 * Le jeton correspond-il au condensat conservé ?
	 *
	 * Comparaison en temps constant : une comparaison ordinaire livrerait, par
	 * son temps d'exécution, la longueur du préfixe correct.
	 *
	 * @param string $attendu    Condensat conservé.
	 * @param int    $compte     Identifiant.
	 * @param string $cible      Cible conservée.
	 * @param int    $generation Génération conservée.
	 * @param string $jeton      Jeton présenté.
	 * @return bool
	 */
	public static function correspond(
		string $attendu,
		int $compte,
		string $cible,
		int $generation,
		string $jeton
	): bool {
		if ( '' === $attendu || ! self::forme_valide( $jeton ) ) {
			return false;
		}

		return hash_equals( $attendu, self::condensat( $compte, $cible, $generation, $jeton ) );
	}

	/**
	 * Secret de signature.
	 *
	 * @return string
	 */
	private static function secret(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return (string) wp_salt( 'auth' );
		}

		// Hors WordPress — bancs d'essai uniquement. Une valeur fixe y est
		// souhaitable : les condensats doivent être reproductibles d'un
		// processus à l'autre.
		if ( defined( 'URBIZEN_TEST_SECRET' ) ) {
			return (string) constant( 'URBIZEN_TEST_SECRET' );
		}

		return 'urbizen-secret-de-banc';
	}
}
