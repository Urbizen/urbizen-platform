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
 * Compatibilité 3/3 — palette de couleurs et police des titres.
 *
 * Le thème parent accroche `WebsiteBuilder::update_theme_json` au filtre
 * `wp_theme_json_data_theme` en priorité 999. Il y écrase deux choses :
 *
 * 1. `settings.color`, remplacé par une palette lue dans l'option Hostinger
 *    `hostinger_ai_colors` ;
 * 2. `styles.elements.heading.typography.fontFamily`, recalculé par
 *    `Fonts::get_main_font()`. Sous thème enfant, cette méthode ne retrouve pas
 *    les familles de polices et retombe sur `system-ui` : les titres perdent
 *    Poppins.
 *
 * Sous le thème parent, les styles globaux « utilisateur » enregistrés en base
 * reprenaient la main sur la couleur. Ces styles étant rattachés au thème
 * parent, ils ne suivent pas le thème enfant : sans ce filtre, le site repasse
 * sur la palette sombre de Hostinger, fonds noirs et textes illisibles.
 *
 * On réapplique donc les deux réglages après le parent, en priorité 1000. La
 * source de vérité reste le theme.json de l'enfant : aucune valeur n'est
 * dupliquée ici, tout est lu depuis le fichier versionné.
 *
 * @param \WP_Theme_JSON_Data $theme_json Données theme.json du thème.
 * @return \WP_Theme_JSON_Data
 */
function urbizen_child_restore_theme_json( $theme_json ) {
	static $overrides = null;

	if ( null === $overrides ) {
		$data = wp_json_file_decode(
			get_stylesheet_directory() . '/theme.json',
			array( 'associative' => true )
		);

		$palette      = $data['settings']['color']['palette'] ?? array();
		$heading_font = $data['styles']['elements']['heading']['typography']['fontFamily'] ?? '';

		$overrides = array( 'version' => 3 );

		if ( ! empty( $palette ) ) {
			$overrides['settings'] = array( 'color' => array( 'palette' => $palette ) );
		}

		if ( '' !== $heading_font ) {
			$overrides['styles'] = array(
				'elements' => array(
					'heading' => array(
						'typography' => array( 'fontFamily' => $heading_font ),
					),
				),
			);
		}
	}

	if ( count( $overrides ) < 2 || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
		return $theme_json;
	}

	return $theme_json->update_with( $overrides );
}
add_filter( 'wp_theme_json_data_theme', 'urbizen_child_restore_theme_json', 1000 );

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
 * Réglages du thème enfant.
 *
 * Les patterns du répertoire /patterns sont enregistrés automatiquement par
 * WordPress à partir de leurs en-têtes de commentaire : rien à déclarer ici.
 *
 * @return void
 */
function urbizen_child_setup() {
	load_child_theme_textdomain( 'urbizen-child', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'urbizen_child_setup' );

/**
 * Identifiant du gabarit de la page d'accueil Urbizen.
 */
const URBIZEN_CHILD_TEMPLATE_ACCUEIL = 'page-accueil-urbizen';

/**
 * La page affichée utilise-t-elle le gabarit de l'accueil Urbizen ?
 *
 * Deux gabarits rendent cette page, pour une raison tenant à la hiérarchie de
 * WordPress : pour la page d'accueil du site, `front-page` est consulté AVANT
 * le gabarit personnalisé de la page, qui n'est donc jamais atteint. Le thème
 * enfant fournit les deux fichiers, copies strictes l'une de l'autre :
 *
 *   - `templates/front-page.html`          → l'accueil du site ;
 *   - `templates/page-accueil-urbizen.html` → toute autre page qui l'assigne,
 *     dont la page brouillon de recette et les prévisualisations.
 *
 * La détection couvre les deux cas. `is_front_page()` est exactement la
 * condition d'emploi de `front-page.html` : dès lors que le fichier existe
 * dans le thème enfant, il est en tête de la hiérarchie de l'accueil.
 *
 * @return bool
 */
function urbizen_child_est_accueil_urbizen() {
	// Accueil du site : rendu par templates/front-page.html.
	if ( is_front_page() ) {
		return true;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return URBIZEN_CHILD_TEMPLATE_ACCUEIL === get_page_template_slug( $id );
}

/**
 * Charge la charte, les polices, les styles et le script de l'accueil.
 *
 * Chargement strictement conditionnel : une page qui n'utilise pas le gabarit
 * ne reçoit aucune de ces ressources. Les polices sont auto-hébergées — aucun
 * appel à fonts.googleapis.com ni à fonts.gstatic.com.
 *
 * @return void
 */
function urbizen_child_enqueue_accueil() {
	if ( ! urbizen_child_est_accueil_urbizen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$ressources = array(
		'urbizen-fonts'    => array( '/assets/css/urbizen-fonts.css', array() ),
		'urbizen-tokens'   => array( '/assets/css/urbizen-tokens.css', array( 'urbizen-fonts' ) ),
		'urbizen-homepage' => array( '/assets/css/urbizen-homepage.css', array( 'urbizen-tokens', 'urbizen-child-style' ) ),
	);

	foreach ( $ressources as $handle => $definition ) {
		list( $chemin, $deps ) = $definition;

		if ( ! file_exists( $dir . $chemin ) ) {
			continue;
		}

		wp_enqueue_style( $handle, $uri . $chemin, $deps, (string) filemtime( $dir . $chemin ) );
	}

	$script = '/assets/js/urbizen-homepage.js';

	if ( file_exists( $dir . $script ) ) {
		wp_enqueue_script( 'urbizen-homepage', $uri . $script, array(), (string) filemtime( $dir . $script ), true );
	}
}
add_action( 'wp_enqueue_scripts', 'urbizen_child_enqueue_accueil', 30 );

/**
 * Ajoute une classe au corps de page sur le gabarit de l'accueil.
 *
 * La maquette porte son quadrillage sur `<body class="u-grid-bg">`. Un gabarit
 * de bloc ne peut pas écrire cet attribut : on l'ajoute ici.
 *
 * @param array<int, string> $classes Classes existantes.
 * @return array<int, string>
 */
function urbizen_child_body_class( $classes ) {
	if ( urbizen_child_est_accueil_urbizen() ) {
		$classes[] = 'u-grid-bg';
	}

	return $classes;
}
add_filter( 'body_class', 'urbizen_child_body_class' );
