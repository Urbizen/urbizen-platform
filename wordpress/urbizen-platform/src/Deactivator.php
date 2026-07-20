<?php
/**
 * Désactivation de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

use Urbizen\Platform\Privacy\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Actions exécutées à la désactivation.
 *
 * Invariant : la désactivation ne détruit JAMAIS de données. Elle se contente
 * de retirer les tâches planifiées. Les demandes, pièces jointes et journaux
 * restent intacts et redeviennent disponibles à la réactivation.
 */
final class Deactivator {

	/**
	 * Point d'entrée de la désactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// La constante plutôt qu'une chaîne : renommer la tâche sans renommer
		// ici laisserait un événement orphelin tourner indéfiniment.
		$hooks = array( Retention::HOOK, 'urbizen_retry_transmission' );

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			while ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
	}
}
