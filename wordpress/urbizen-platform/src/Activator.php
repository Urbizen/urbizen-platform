<?php
/**
 * Activation de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

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

		/*
		 * À implémenter aux étapes suivantes :
		 *   - création des tables wp_urbizen_submissions / _submission_fields /
		 *     _files / _log via dbDelta() ;
		 *   - création du répertoire de stockage privé hors racine web ;
		 *   - enregistrement de la tâche planifiée de purge RGPD ;
		 *   - déclaration des capabilities urbizen_manage_dossiers.
		 */
	}
}
