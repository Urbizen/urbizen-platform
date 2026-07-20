<?php
/**
 * Limitation de fréquence des demandes.
 *
 * Politique retenue : **cinq demandes réellement enregistrées** par heure et
 * par origine réseau. La nuance porte tout le mécanisme — une erreur corrigible
 * ne doit pas brûler un créneau. Une personne qui oublie une case et corrige ne
 * doit pas se retrouver bloquée pour une heure par sa propre application.
 *
 * D'où un fonctionnement en trois temps : **réserver** un créneau avant le
 * traitement, le **libérer** si le traitement échoue pour une raison corrigible,
 * le **confirmer** une fois la demande écrite.
 *
 * Les créneaux sont des **options**, pas des transients. Un transient exprime
 * une durée maximale de conservation, jamais une garantie : une purge du cache
 * objet remettrait le compteur à zéro. Surtout, l'unicité de `option_name`
 * fournit une primitive atomique — six requêtes simultanées ne peuvent pas
 * acquérir plus de cinq créneaux, puisque `add_option()` n'aboutit qu'une fois
 * par nom.
 *
 * **L'adresse IP n'est écrite nulle part** : ni en base, ni dans un journal, ni
 * dans une métadonnée. Seul un condensat HMAC nomme le compartiment. Il permet
 * de reconnaître deux requêtes de la même origine, jamais de retrouver cette
 * origine.
 *
 * **Aucun en-tête de proxy n'est cru sur parole.** `X-Forwarded-For`,
 * `X-Real-IP` et `Client-IP` sont envoyés par le client : les accepter d'office
 * offrirait un contournement trivial. La source est `REMOTE_ADDR`. Un
 * hébergement derrière un proxy de confiance peut désigner un en-tête par le
 * filtre `urbizen_trusted_proxy_header`, décision explicite et documentée.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Security;

use Urbizen\Platform\Support\OptionsScan;

defined( 'ABSPATH' ) || exit;

/**
 * Créneaux de soumission par origine réseau.
 */
final class RateLimiter {

	/**
	 * Nombre maximal de demandes enregistrées par fenêtre.
	 */
	public const DEFAULT_MAX = 5;

	/**
	 * Durée de la fenêtre, en secondes.
	 */
	public const DEFAULT_WINDOW = 3600;

	/**
	 * Préfixe des options de créneau.
	 */
	public const OPTION_PREFIX = 'urbizen_rl_';

	/**
	 * Réserve un créneau, de façon atomique.
	 *
	 * Les créneaux d'une origine portent des noms déterministes, numérotés de 0
	 * à `max - 1`. Réserver, c'est réussir un `add_option()` sur l'un d'eux :
	 * deux requêtes concurrentes visant le même numéro ne peuvent pas aboutir
	 * toutes les deux, et il n'existe jamais plus de `max` noms possibles.
	 *
	 * @param string               $bucket Compartiment (type de formulaire).
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @param int|null             $now    Horodatage courant (tests).
	 * @return string|null Identifiant du créneau, ou null si le quota est atteint.
	 */
	public static function reserve( string $bucket, array $server, ?int $now = null ): ?string {
		$now    = null === $now ? time() : $now;
		$max    = self::max();
		$window = self::window();

		if ( $max <= 0 ) {
			return self::OPTION_PREFIX . 'illimite';
		}

		$base = self::key( $bucket, $server );

		for ( $rang = 0; $rang < $max; $rang++ ) {
			$cle     = $base . '_' . $rang;
			$occupee = get_option( $cle, null );

			if ( is_array( $occupee ) ) {
				if ( isset( $occupee['expires'] ) && $now >= (int) $occupee['expires'] ) {
					// Créneau périmé : il se recycle.
					delete_option( $cle );
				} else {
					continue;
				}
			}

			if ( add_option( $cle, array( 'state' => 'reserved', 'expires' => $now + $window ), '', false ) ) {
				return $cle;
			}

			// Une autre requête vient de prendre ce numéro : on essaie le suivant.
		}

		return null;
	}

	/**
	 * Libère un créneau.
	 *
	 * Appelée quand le traitement échoue pour une raison corrigible : validation,
	 * fichiers, tarification, persistance. Le créneau redevient disponible
	 * immédiatement.
	 *
	 * @param string|null $slot Identifiant de créneau.
	 * @return void
	 */
	public static function release( ?string $slot ): void {
		if ( null === $slot || ! str_starts_with( $slot, self::OPTION_PREFIX ) ) {
			return;
		}

		delete_option( $slot );
	}

	/**
	 * Confirme un créneau : une demande a bien été enregistrée.
	 *
	 * Le créneau reste occupé jusqu'à la fin de la fenêtre.
	 *
	 * @param string|null $slot Identifiant de créneau.
	 * @param int|null    $now  Horodatage courant (tests).
	 * @return void
	 */
	public static function confirm( ?string $slot, ?int $now = null ): void {
		if ( null === $slot || ! str_starts_with( $slot, self::OPTION_PREFIX ) ) {
			return;
		}

		$now      = null === $now ? time() : $now;
		$existant = get_option( $slot, null );

		// L'échéance d'origine est préservée : confirmer ne doit pas repousser
		// la fin de la fenêtre, sinon un flux soutenu la prolongerait sans fin.
		$expire = is_array( $existant ) && isset( $existant['expires'] )
			? (int) $existant['expires']
			: $now + self::window();

		update_option( $slot, array( 'state' => 'confirmed', 'expires' => $expire ), false );
	}

	/**
	 * Nombre de créneaux occupés pour une origine.
	 *
	 * @param string               $bucket Compartiment.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @param int|null             $now    Horodatage courant (tests).
	 * @return int
	 */
	public static function used( string $bucket, array $server, ?int $now = null ): int {
		$now     = null === $now ? time() : $now;
		$base    = self::key( $bucket, $server );
		$occupes = 0;

		for ( $rang = 0; $rang < self::max(); $rang++ ) {
			$valeur = get_option( $base . '_' . $rang, null );

			if ( is_array( $valeur ) && isset( $valeur['expires'] ) && $now < (int) $valeur['expires'] ) {
				++$occupes;
			}
		}

		return $occupes;
	}

	/**
	 * Supprime les créneaux expirés.
	 *
	 * Idempotent. Aucune adresse n'y transite : les noms sont des condensats.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de créneaux supprimés.
	 */
	public static function cleanup_expired_slots( ?int $now = null ): int {
		$now        = null === $now ? time() : $now;
		$supprimes  = 0;

		foreach ( OptionsScan::names( self::OPTION_PREFIX ) as $cle ) {
			$valeur = get_option( $cle, null );

			if ( ! is_array( $valeur ) || ! isset( $valeur['expires'] ) || $now >= (int) $valeur['expires'] ) {
				delete_option( $cle );
				++$supprimes;
			}
		}

		return $supprimes;
	}

	/**
	 * Préfixe des créneaux d'une origine.
	 *
	 * @param string               $bucket Compartiment.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @return string
	 */
	public static function key( string $bucket, array $server ): string {
		$origine = self::origin( $server );

		return self::OPTION_PREFIX . substr(
			hash_hmac( 'sha256', $bucket . '|' . $origine, self::secret() ),
			0,
			32
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
	 * Nombre maximal de demandes, ajustable par filtre.
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
