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
 * Gabarits Urbizen des pages internes (hors accueil).
 *
 * Ces pages réutilisent la charte, les polices et la feuille générée de
 * l'accueil, sans écrire de CSS propre. On n'y inscrit un slug que lorsque son
 * gabarit existe réellement dans le thème.
 */
/**
 * Version du lot de ressources des formulaires.
 *
 * Une seule valeur pour trois endroits : l'URL du cadre produite par cette page,
 * les feuilles et scripts que les documents DP et PC chargent, et les bancs de
 * contrat. Les faire diverger reviendrait à servir un document neuf qui appelle
 * d'anciens scripts, ou l'inverse — précisément la panne que le versionnement
 * doit fermer.
 *
 * À incrémenter à chaque changement d'un de ces fichiers. Un paramètre tiré au
 * hasard à chaque affichage invaliderait le cache en permanence : ce n'est pas
 * une invalidation, c'est une suppression.
 */
const URBIZEN_CHILD_FORMS_VERSION = '0.2.3';

const URBIZEN_CHILD_TEMPLATES_PAGES = array(
	'page-declaration-prealable',
	'page-permis-de-construire',
	'page-conception',
	'page-formulaire-declaration-prealable',
	'page-formulaire-permis-de-construire',
);

/**
 * La page affichée est-elle l'un des deux formulaires d'autorisation ?
 *
 * @return bool
 */
function urbizen_child_est_page_formulaire_autorisation() {
	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return in_array(
		get_page_template_slug( $id ),
		array( 'page-formulaire-declaration-prealable', 'page-formulaire-permis-de-construire' ),
		true
	);
}

/**
 * Origine du site, au sens exact que le navigateur donne à ce mot.
 *
 * Une origine est un triplet — schéma, hôte, **port**. Composer seulement le
 * schéma et l'hôte donne la bonne valeur tant que le port est celui par défaut,
 * et une valeur fausse dès qu'il ne l'est pas. Le pont compare cette chaîne à
 * `window.location.origin` au caractère près : un port omis fait rejeter la
 * configuration, et le bouton d'envoi ne se déverrouille jamais.
 *
 * Le défaut ne se voyait pas en production — `urbizen.fr` répond en HTTPS sur
 * 443, que le navigateur n'écrit pas. Il s'est révélé au premier essai intégré
 * local, sur un serveur écoutant sur un autre port.
 *
 * @return string Origine complète, sans barre oblique finale.
 */
function urbizen_child_origine_site() {
	$parties = wp_parse_url( home_url() );

	if ( ! is_array( $parties ) || empty( $parties['scheme'] ) || empty( $parties['host'] ) ) {
		return '';
	}

	$origine = $parties['scheme'] . '://' . $parties['host'];

	// Les ports par défaut ne figurent pas dans `location.origin` : les ajouter
	// produirait la même divergence, en sens inverse.
	$defaut = array( 'http' => 80, 'https' => 443 );
	$port   = isset( $parties['port'] ) ? (int) $parties['port'] : 0;

	if ( $port > 0 && ( $defaut[ $parties['scheme'] ] ?? 0 ) !== $port ) {
		$origine .= ':' . $port;
	}

	return $origine;
}

/**
 * Configuration de soumission du formulaire affiché.
 *
 * Le nonce est émis ici, dans la page parente, et non dans le document servi en
 * iframe : ce dernier est un fichier statique du thème, qu'aucun PHP ne rend.
 * C'est précisément pour cela que le pont `postMessage` existe.
 *
 * Rien de ce tableau n'est décidé par le navigateur. L'action et le type sont
 * dérivés du **gabarit de la page**, donc d'une valeur serveur ; l'origine
 * autorisée vient de `home_url()`, jamais d'un en-tête de requête.
 *
 * @return array<string, string>
 */
function urbizen_child_configuration_formulaire() {
	// Une entrée par parcours raccordé. Le gabarit de la page détermine la
	// route : c'est le serveur qui décide, jamais un attribut du document servi
	// en iframe. Un nonce est lié à son action, et chaque parcours a la sienne —
	// partager une action laisserait un nonce émis pour une DP autoriser l'envoi
	// d'un permis de construire.
	$gabarits = array(
		'page-formulaire-declaration-prealable' => array(
			'action' => 'urbizen_declaration_prealable',
			'nonce'  => 'urbizen_declaration_prealable_submit',
			'type'   => 'declaration_prealable',
			'frame'  => 'dp-formulaire.html',
		),
		'page-formulaire-permis-de-construire'  => array(
			'action' => 'urbizen_permis_construire',
			'nonce'  => 'urbizen_permis_construire_submit',
			'type'   => 'permis_construire',
			'frame'  => 'pc-formulaire.html',
		),
	);

	if ( ! is_singular() ) {
		return array();
	}

	$slug = get_page_template_slug( get_queried_object_id() );

	if ( ! isset( $gabarits[ $slug ] ) ) {
		// Un gabarit absent de la table ne reçoit aucune configuration : son
		// formulaire reste inerte, plutôt que d'hériter d'une route qui n'est
		// pas la sienne.
		return array();
	}

	$route = $gabarits[ $slug ];

	// Le jeton anti-robot est émis ici pour la même raison que le nonce : il est
	// signé et horodaté côté serveur, et le document servi en iframe est un
	// fichier statique qu'aucun PHP ne rend. Sans lui, la route refuse toute
	// soumission — `invalid_antispam_token` — et aucun envoi depuis un
	// navigateur ne peut aboutir. Les bancs ne le voyaient pas : ils
	// fabriquaient le jeton eux-mêmes.
	$jeton = class_exists( '\\Urbizen\\Platform\\Security\\AntiSpam' )
		? \Urbizen\Platform\Security\AntiSpam::issue_token()
		: '';

	return array(
		'action'         => $route['action'],
		'formType'       => $route['type'],
		'nonceField'     => 'urbizen_conception_nonce',
		'nonce'          => wp_create_nonce( $route['nonce'] ),
		'tokenField'     => 'urbizen_token',
		'token'          => $jeton,
		'honeypotField'  => 'company_website',
		'submitUrl'      => admin_url( 'admin-post.php' ),
		'origin'         => urbizen_child_origine_site(),
		// **Sans version.** Le gabarit porte `?v=…` sur le cadre ; la comparaison
		// côté parent est un `indexOf`, donc un préfixe suffit. Y mettre la
		// version obligerait les deux à rester synchrones au caractère près, et
		// une divergence ferait échouer la vérification de source — le
		// formulaire deviendrait inerte pour une raison invisible.
		'frameSource'    => '/wp-content/themes/urbizen-child/assets/forms/' . $route['frame'],
		'assetsVersion'  => URBIZEN_CHILD_FORMS_VERSION,
	);
}

/**
 * La page affichée utilise-t-elle un gabarit Urbizen — accueil ou page interne ?
 *
 * Étend `urbizen_child_est_accueil_urbizen()` aux pages internes qui empruntent
 * la même charte : elles doivent recevoir les mêmes polices, tokens et feuille.
 *
 * @return bool
 */
function urbizen_child_est_page_urbizen() {
	if ( urbizen_child_est_accueil_urbizen() ) {
		return true;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return in_array( get_page_template_slug( $id ), URBIZEN_CHILD_TEMPLATES_PAGES, true );
}

/**
 * La page affichée utilise-t-elle le gabarit commercial « Conception » ?
 *
 * Sert à ne charger la feuille de galerie et le script de protection des
 * visuels que sur cette page, et nulle part ailleurs.
 *
 * @return bool
 */
function urbizen_child_est_page_conception() {
	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return 'page-conception' === get_page_template_slug( $id );
}

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
	if ( ! urbizen_child_est_page_urbizen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$ressources = array(
		'urbizen-fonts'    => array( '/assets/css/urbizen-fonts.css', array() ),
		'urbizen-tokens'   => array( '/assets/css/urbizen-tokens.css', array( 'urbizen-fonts' ) ),
		'urbizen-homepage' => array( '/assets/css/urbizen-homepage.css', array( 'urbizen-tokens', 'urbizen-child-style' ) ),
		// Corrige les interférences WordPress sur l'en-tête. Chargée en dernier,
		// après la feuille générée depuis la maquette, qu'elle ne modifie pas.
		'urbizen-entete'   => array( '/assets/css/urbizen-accueil-entete.css', array( 'urbizen-homepage' ) ),
	);

	foreach ( $ressources as $handle => $definition ) {
		list( $chemin, $deps ) = $definition;

		if ( ! file_exists( $dir . $chemin ) ) {
			continue;
		}

		wp_enqueue_style( $handle, $uri . $chemin, $deps, (string) filemtime( $dir . $chemin ) );
	}

	// Feuille des pages internes (hero de page, tableaux, frise) — scopée
	// `.urbizen-page`, classe absente de l'accueil : aucune incidence dessus.
	if ( ! urbizen_child_est_accueil_urbizen() ) {
		$pages_css = '/assets/css/urbizen-pages.css';

		if ( file_exists( $dir . $pages_css ) ) {
			wp_enqueue_style( 'urbizen-pages', $uri . $pages_css, array( 'urbizen-homepage' ), (string) filemtime( $dir . $pages_css ) );
		}
	}

	// Page commerciale « Conception » : feuille dédiée (galerie de rendus,
	// hero illustré) et script de protection des visuels. Chargés uniquement
	// sur cette page ; scopés `.urbizen-page-conception`.
	if ( urbizen_child_est_page_conception() ) {
		$conception_css = '/assets/css/urbizen-conception.css';

		if ( file_exists( $dir . $conception_css ) ) {
			// Handle distinct du plugin : ConceptionAssets enregistre déjà
			// « urbizen-conception » (CSS du formulaire). Réutiliser ce handle
			// ferait écraser silencieusement notre feuille de page.
			wp_enqueue_style( 'urbizen-conception-page', $uri . $conception_css, array( 'urbizen-pages' ), (string) filemtime( $dir . $conception_css ) );
		}

		$conception_js = '/assets/js/urbizen-conception-gallery.js';

		if ( file_exists( $dir . $conception_js ) ) {
			wp_enqueue_script( 'urbizen-conception-gallery', $uri . $conception_js, array(), (string) filemtime( $dir . $conception_js ), true );
		}
	}

	// Formulaires DP et PC : la coque WordPress garde l'en-tête et le pied de
	// page du site ; l'iframe, de même origine, est redimensionnée à son contenu.
	if ( urbizen_child_est_page_formulaire_autorisation() ) {
		$form_page_css = '/assets/css/urbizen-form-page.css';

		if ( file_exists( $dir . $form_page_css ) ) {
			wp_enqueue_style( 'urbizen-form-page', $uri . $form_page_css, array( 'urbizen-pages' ), (string) filemtime( $dir . $form_page_css ) );
		}

		$form_page_js = '/assets/js/urbizen-form-page.js';

		if ( file_exists( $dir . $form_page_js ) ) {
			wp_enqueue_script( 'urbizen-form-page', $uri . $form_page_js, array(), (string) filemtime( $dir . $form_page_js ), true );

			// La configuration de soumission est émise **côté serveur**, sur la
			// page parente, et transmise à l'iframe par `postMessage`. Elle ne
			// passe jamais par l'URL du cadre : un nonce dans une query string
			// se retrouverait dans l'historique, les journaux d'accès et tout
			// en-tête `Referer` sortant.
			$config = urbizen_child_configuration_formulaire();

			if ( array() !== $config ) {
				wp_add_inline_script(
					'urbizen-form-page',
					'window.urbizenFormConfig = ' . wp_json_encode( $config ) . ';',
					'before'
				);
			}
		}
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
	if ( urbizen_child_est_page_urbizen() ) {
		$classes[] = 'u-grid-bg';
	}

	return $classes;
}
add_filter( 'body_class', 'urbizen_child_body_class' );
