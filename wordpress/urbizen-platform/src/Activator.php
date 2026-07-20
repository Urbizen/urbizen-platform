<?php
/**
 * Activation de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

use Urbizen\Platform\Privacy\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Actions exécutées à l'activation.
 *
 * Étape 1 : aucune écriture en base, aucune création de table, aucune option.
 * Les tables wp_urbizen_* seront créées à l'étape « moteur de formulaires »,
 * une fois leur schéma validé. L'activation se limite donc à un contrôle de
 * compatibilité : elle est sans effet de bord et parfaitement réversible.
 */
final class Activator {

	/**
	 * Point d'entrée de l'activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$failures = Requirements::failures();

		if ( ! empty( $failures ) ) {
			deactivate_plugins( plugin_basename( URBIZEN_PLATFORM_FILE ) );

			wp_die(
				esc_html( implode( ' ', $failures ) ),
				esc_html__( 'Urbizen Platform — environnement incompatible', 'urbizen-platform' ),
				array( 'back_link' => true )
			);
		}

		// Purge RGPD quotidienne. Aucune table n'est créée : les demandes sont
		// des contenus WordPress privés, pas un schéma parallèle à maintenir.
		Retention::schedule();

		/*
		 * À implémenter aux étapes suivantes :
		 *   - création du répertoire de stockage privé hors racine web (B2) ;
		 *   - déclaration des capabilities urbizen_manage_dossiers.
		 */
	}
}
