<?php
/**
 * Thème enfant Urbizen — amorçage.
 *
 * Périmètre volontairement restreint au rendu :
 *   - compatibilité avec le thème parent Hostinger AI ;
 *   - chargement de la feuille de style enfant.
 *
 * Interdits dans ce fichier : traitement de formulaire, requête SQL, appel
 * réseau, manipulation de données personnelles. Ces responsabilités relèvent
 * exclusivement de l'extension urbizen-platform.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compatibilité 1/2 — chemins PHP du thème parent.
 *
 * Le thème parent définit ses constantes avec get_stylesheet_directory(), qui
 * pointe vers le thème ENFANT dès que celui-ci est actif. Ses fichiers internes
 * (Builder, Admin, i18n WooCommerce) deviendraient alors introuvables.
 *
 * WordPress charge le functions.php de l'enfant AVANT celui du parent, et le
 * parent protège ses définitions par « if ( ! defined() ) ». On fixe donc ici
 * les valeurs correctes, pointant vers le répertoire du thème parent.
 */
if ( ! defined( 'HOSTINGER_AI_WEBSITES_THEME_PATH' ) ) {
	define( 'HOSTINGER_AI_WEBSITES_THEME_PATH', get_template_directory() );
}

if ( ! defined( 'HOSTINGER_AI_WEBSITES_ASSETS_URL' ) ) {
	define( 'HOSTINGER_AI_WEBSITES_ASSETS_URL', get_template_directory_uri() . '/assets' );
}

/**
 * Compatibilité 2/2 — URL des assets du thème parent.
 *
 * Le parent enregistre style.min.css et front-scripts.min.js avec
 * get_stylesheet_directory_uri(), ce qui produirait des 404 sous thème enfant.
 * On réécrit l'URL uniquement lorsque le fichier est absent du thème enfant et
 * présent dans le thème parent : les surcharges futures d'Urbizen restent donc
 * prioritaires, et les styles ajoutés en inline sur ces handles sont préservés.
 *
 * @param string $src URL de la ressource.
 * @return string URL corrigée si nécessaire.
 */
function urbizen_child_resolve_parent_asset( $src ) {
	if ( ! is_string( $src ) || '' === $src ) {
		return $src;
	}

	$child_uri = get_stylesheet_directory_uri();

	if ( 0 !== strpos( $src, $child_uri ) ) {
		return $src;
	}

	$relative = substr( $src, strlen( $child_uri ) );
	$path     = strtok( $relative, '?' );

	if ( '' === $path || false === $path ) {
		return $src;
	}

	// Le thème enfant fournit sa propre version : on ne touche à rien.
	if ( file_exists( get_stylesheet_directory() . $path ) ) {
		return $src;
	}

	if ( file_exists( get_template_directory() . $path ) ) {
		return get_template_directory_uri() . $relative;
	}

	return $src;
}
add_filter( 'style_loader_src', 'urbizen_child_resolve_parent_asset', 10, 1 );
add_filter( 'script_loader_src', 'urbizen_child_resolve_parent_asset', 10, 1 );

/**
 * Charge la feuille de style du thème enfant.
 *
 * Elle dépend du handle du parent afin d'être toujours chargée après lui.
 *
 * @return void
 */
function urbizen_child_enqueue_styles() {
	$style_path = get_stylesheet_directory() . '/style.css';

	if ( ! file_exists( $style_path ) ) {
		return;
	}

	wp_enqueue_style(
		'urbizen-child-style',
		get_stylesheet_uri(),
		array( 'hostinger-ai-style' ),
		(string) filemtime( $style_path )
	);
}
add_action( 'wp_enqueue_scripts', 'urbizen_child_enqueue_styles', 20 );

/**
 * Déclare le répertoire des patterns du thème enfant.
 *
 * Les patterns Urbizen (accueil, pages métier) y seront ajoutés aux étapes
 * suivantes. WordPress enregistre automatiquement le contenu de /patterns.
 *
 * @return void
 */
function urbizen_child_setup() {
	load_child_theme_textdomain( 'urbizen-child', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'urbizen_child_setup' );
