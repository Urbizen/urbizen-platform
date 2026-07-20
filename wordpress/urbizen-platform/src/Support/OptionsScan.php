<?php
/**
 * Énumération des options techniques par préfixe.
 *
 * Les réservations — jetons, créneaux de débit, références — sont des options
 * WordPress, choisies pour l'atomicité que leur donne l'unicité de
 * `option_name`. Les nettoyer suppose de pouvoir les retrouver, ce que l'API
 * `get_option()` seule ne permet pas.
 *
 * D'où cette unique requête, volontairement isolée dans une classe minuscule :
 * elle ne lit que des **noms** d'options, jamais leur contenu, et ne concerne
 * que des préfixes internes à l'extension. Aucune table personnalisée n'est
 * créée ; `wp_options` est une table du cœur.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Recherche d'options par préfixe.
 */
final class OptionsScan {

	/**
	 * Nombre maximal de noms rapportés par appel.
	 *
	 * Un nettoyage ne doit jamais faire expirer une tâche planifiée. Le
	 * reliquat part au passage suivant.
	 */
	public const LOT = 500;

	/**
	 * Noms des options commençant par un préfixe donné.
	 *
	 * @param string $prefix Préfixe interne (ex. `urbizen_tok_`).
	 * @return array<int, string>
	 */
	public static function names( string $prefix ): array {
		// Garde-fou : cette classe ne sert qu'aux préfixes de l'extension. Elle
		// ne doit jamais pouvoir énumérer les options du site.
		if ( ! str_starts_with( $prefix, 'urbizen_' ) ) {
			return array();
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$noms = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $prefix ) . '%',
				self::LOT
			)
		);

		return is_array( $noms ) ? array_map( 'strval', $noms ) : array();
	}
}
