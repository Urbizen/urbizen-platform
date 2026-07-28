<?php
/**
 * Verrou d'exclusion mutuelle **durable et non ambigu**, adossé à `wp_options`.
 *
 * **Pourquoi ne pas se fier à `add_option()`.** Le cœur WordPress implémente
 * `add_option()` par un `INSERT … ON DUPLICATE KEY UPDATE option_name =
 * VALUES(option_name)`, précédé d'un contrôle d'existence NON atomique (cache
 * `alloptions`/`notoptions`). Sa valeur de retour dépend alors du nombre de
 * lignes affectées par `$wpdb->query()` : en configuration par défaut (sans
 * `MYSQLI_CLIENT_FOUND_ROWS`), un doublon renvoie 0 ligne affectée et
 * `add_option()` rend `false` ; mais si l'hôte active `CLIENT_FOUND_ROWS`, un
 * doublon renvoie 1 ligne « trouvée » et `add_option()` rend `true` — **deux
 * appelants concurrents peuvent alors croire avoir gagné**. La garantie n'est
 * donc pas contractuelle : elle est incidente et fragile. Aucune réservation de
 * sécurité ne doit en dépendre.
 *
 * **Primitive retenue.** Un `INSERT IGNORE` direct : la contrainte d'unicité de
 * `option_name` tranche, exactement **une** insertion réussit (1 ligne), les
 * autres sont ignorées (0 ligne), une erreur rend `false` (échec fermé). Le
 * contrat ne dépend ni de `ON DUPLICATE KEY UPDATE`, ni de `CLIENT_FOUND_ROWS`.
 *
 * **Isolation du cache Options.** La ligne de verrou n'est **jamais** lue via
 * `get_option()` : acquisition, lecture, libération et purge passent toutes par
 * `$wpdb`, et l'on invalide le cache d'options du nom concerné après chaque
 * écriture — un cache périmé ne peut donc jamais créer un faux gagnant ni
 * masquer un verrou réel.
 *
 * **Libération conditionnelle.** La suppression normale est conditionnée à la
 * valeur EXACTE du propriétaire (`DELETE … WHERE option_name = … AND
 * option_value = …`) : un processus ne peut **jamais** supprimer le verrou d'un
 * autre. Une purge inconditionnelle ({@see self::forget()}) reste réservée au
 * nettoyage d'un verrou périmé.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Acquisition/libération exclusive et non ambiguë d'un verrou dans `wp_options`.
 */
final class OptionMutex {

	/**
	 * Acquiert le verrou de façon **non écrasante**. La valeur transmise est
	 * stockée telle quelle (chaîne opaque : l'appelant y encode propriétaire,
	 * expiration, version).
	 *
	 * @param string $name  Nom d'option (déjà borné et sans PII par l'appelant).
	 * @param string $value Valeur exacte du propriétaire.
	 * @return bool Vrai **uniquement** si une ligne a été insérée (gagnant unique).
	 */
	public static function claim( string $name, string $value ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$resultat = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB
				$name,
				$value
			)
		);

		self::oublier_cache( $name );

		// 1 = insertion réussie (gagnant). 0 = ligne déjà présente (perdant).
		// false = erreur SQL → échec fermé. Jamais de « true » sur un doublon.
		return 1 === $resultat;
	}

	/**
	 * Lit la valeur EXACTE du verrou, **directement** en base (jamais le cache
	 * d'options). Renvoie null si absent.
	 *
	 * @param string $name Nom d'option.
	 * @return string|null
	 */
	public static function read( string $name ): ?string {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$valeur = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB
				$name
			)
		);

		return is_string( $valeur ) ? $valeur : null;
	}

	/**
	 * Libère le verrou **si et seulement si** la valeur correspond exactement à
	 * celle du propriétaire — jamais le verrou d'un autre.
	 *
	 * @param string $name  Nom d'option.
	 * @param string $value Valeur exacte du propriétaire.
	 * @return bool Vrai si une ligne a été supprimée.
	 */
	public static function release( string $name, string $value ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || '' === $value ) {
			return false;
		}

		$resultat = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB
				$name,
				$value
			)
		);

		self::oublier_cache( $name );

		return is_int( $resultat ) && $resultat >= 1;
	}

	/**
	 * Supprime le verrou **sans condition** (nettoyage d'un verrou périmé).
	 * Réservé à la purge différée, jamais au chemin de consommation.
	 *
	 * @param string $name Nom d'option.
	 * @return void
	 */
	public static function forget( string $name ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB
				$name
			)
		);

		self::oublier_cache( $name );
	}

	/**
	 * Invalide toute trace de ce nom dans le cache d'options, afin qu'aucune
	 * décision ultérieure ne repose sur une valeur périmée du cache (ni une
	 * entrée `notoptions` marquant l'option « absente »).
	 *
	 * @param string $name Nom d'option.
	 * @return void
	 */
	private static function oublier_cache( string $name ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		if ( function_exists( 'wp_cache_get' ) && function_exists( 'wp_cache_set' ) ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
				unset( $notoptions[ $name ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}
	}
}
