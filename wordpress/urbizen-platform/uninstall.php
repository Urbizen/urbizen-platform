<?php
/**
 * Désinstallation de l'extension.
 *
 * Garde-fou volontaire : la suppression des données Urbizen (demandes, pièces
 * jointes, journaux) n'a lieu que si la constante URBIZEN_ALLOW_DATA_DELETION
 * est définie à true dans wp-config.php. Sans cette constante, une suppression
 * accidentelle de l'extension laisse toutes les données intactes.
 *
 * @package Urbizen\Platform
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'URBIZEN_ALLOW_DATA_DELETION' ) || true !== URBIZEN_ALLOW_DATA_DELETION ) {
	return;
}

/*
 * À implémenter lorsque les tables existeront :
 *   - suppression des tables wp_urbizen_* ;
 *   - suppression de l'option urbizen_platform_settings ;
 *   - suppression du répertoire de stockage privé.
 *
 * Étape 1 : l'extension ne crée ni table, ni option, ni fichier.
 * Il n'y a donc rien à nettoyer.
 */
