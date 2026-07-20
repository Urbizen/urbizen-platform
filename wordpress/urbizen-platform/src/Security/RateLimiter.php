<?php
/**
 * Limitation de fréquence des soumissions.
 *
 * Protège d'un envoi massif depuis une même origine, sans jamais conserver
 * l'adresse qui l'a émis.
 *
 * **L'adresse IP n'est écrite nulle part** : ni en base, ni dans un transient,
 * ni dans un journal, ni dans une métadonnée. Seul un condensat HMAC sert de
 * clé de compteur. Il permet de reconnaître deux requêtes venant de la même
 * origine, mais ne permet pas de retrouver cette origine — c'est exactement ce
 * qu'il faut pour compter sans ficher.
 *
 * **Aucun en-tête de proxy n'est cru sur parole.** `X-Forwarded-For`,
 * `X-Real-IP` et `Client-IP` sont envoyés par le client : les accepter
 * d'office offrirait un contournement trivial de la limite. La source par
 * défaut est `REMOTE_ADDR`, seule valeur établie par le serveur. Un
 * hébergement placé derrière un proxy de confiance peut désigner un en-tête
 * par le filtre `urbizen_trusted_proxy_header`, décision explicite et
 * documentée.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Compteur de soumissions par origine réseau.
 */
final class RateLimiter {

	/**
	 * Nombre maximal de soumissions par fenêtre.
	 */
	public const DEFAULT_MAX = 5;

	/**
	 * Durée de la fenêtre, en secondes.
	 */
	public const DEFAULT_WINDOW = 3600;

	/**
	 * Préfixe des transients de comptage.
	 */
	private const PREFIX = 'urbizen_rl_';

	/**
	 * Enregistre une tentative et dit si elle est autorisée.
	 *
	 * @param string               $bucket Compartiment (type de formulaire).
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @param int|null             $now    Horodatage courant (tests).
	 * @return bool
	 */
	public static function allow( string $bucket, array $server, ?int $now = null ): bool {
		$now    = null === $now ? time() : $now;
		$max    = self::max();
		$window = self::window();

		if ( $max <= 0 ) {
			return true;
		}

		$cle     = self::key( $bucket, $server );
		$compteur = get_transient( $cle );

		if ( ! is_array( $compteur ) || ! isset( $compteur['count'], $compteur['start'] ) ) {
			set_transient( $cle, array( 'count' => 1, 'start' => $now ), $window );
			return true;
		}

		$debut  = (int) $compteur['start'];
		$ecoule = $now - $debut;

		// Fenêtre écoulée : on repart à zéro plutôt que de prolonger.
		if ( $ecoule >= $window || $ecoule < 0 ) {
			set_transient( $cle, array( 'count' => 1, 'start' => $now ), $window );
			return true;
		}

		if ( (int) $compteur['count'] >= $max ) {
			return false;
		}

		// La durée restante est préservée : incrémenter ne doit pas repousser
		// la fin de la fenêtre, sinon un envoi soutenu la prolongerait sans fin.
		set_transient(
			$cle,
			array(
				'count' => (int) $compteur['count'] + 1,
				'start' => $debut,
			),
			max( 1, $window - $ecoule )
		);

		return true;
	}

	/**
	 * Clé de comptage d'une origine.
	 *
	 * @param string               $bucket Compartiment.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @return string
	 */
	public static function key( string $bucket, array $server ): string {
		$origine = self::origin( $server );

		return self::PREFIX . substr(
			hash_hmac( 'sha256', $bucket . '|' . $origine, self::secret() ),
			0,
			40
		);
	}

	/**
	 * Origine réseau retenue.
	 *
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @return string
	 */
	public static function origin( array $server ): string {
		$entete = (string) apply_filters( 'urbizen_trusted_proxy_header', '' );

		if ( '' !== $entete ) {
			$cle = 'HTTP_' . strtoupper( str_replace( '-', '_', $entete ) );

			if ( ! empty( $server[ $cle ] ) && is_string( $server[ $cle ] ) ) {
				// Un en-tête de chaîne peut contenir plusieurs adresses : la
				// première est celle du client d'origine.
				$premiere = trim( explode( ',', $server[ $cle ] )[0] );

				if ( '' !== $premiere ) {
					return $premiere;
				}
			}
		}

		return isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] )
			? $server['REMOTE_ADDR']
			: 'inconnue';
	}

	/**
	 * Nombre maximal de soumissions, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function max(): int {
		return (int) apply_filters( 'urbizen_rate_limit_max', self::DEFAULT_MAX );
	}

	/**
	 * Durée de la fenêtre, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function window(): int {
		return (int) apply_filters( 'urbizen_rate_limit_window', self::DEFAULT_WINDOW );
	}

	/**
	 * Secret de dérivation des clés.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return wp_salt( 'nonce' ) . '|urbizen-ratelimit';
	}
}
