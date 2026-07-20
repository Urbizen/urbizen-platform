<?php
/**
 * Banc d'essai de l'en-tête collant de la page d'accueil.
 *
 * La maquette déclare `header.site { position: sticky; top: 0 }`, mais sous
 * WordPress trois interférences l'empêchaient d'agir :
 *
 *   1. `body.hostinger-ai-builder-gutenberg { padding-top: 75px }` du thème
 *      parent — une bande vide qui laissait voir le quadrillage au-dessus ;
 *   2. `header, .wp-block-template-part { position: relative !important }`
 *      des styles globaux — le sticky était écrasé en silence ;
 *   3. l'enveloppe `<div>` du template part, qui bornait l'élément collant à
 *      la hauteur exacte de l'en-tête.
 *
 * Ce banc contrôle que les trois sont neutralisées, sans effet de bord sur le
 * reste de la page ni sur les autres pages du site.
 *
 * Hors WordPress : les fonctions utilisées sont doublées. Aucun accès réseau,
 * aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-72s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

/** Extrait le corps d'une règle, en option à l'intérieur d'une media query. */
function regle( $css, $selecteur, $media = null ) {
	$zone = $css;

	if ( null !== $media ) {
		$i = strpos( $css, $media );

		if ( false === $i ) {
			return null;
		}

		// Bloc @media : on avance jusqu'à l'accolade fermante correspondante.
		$j = strpos( $css, '{', $i );
		$p = 1;
		$k = $j + 1;

		while ( $k < strlen( $css ) && $p > 0 ) {
			$p += ( '{' === $css[ $k ] ) - ( '}' === $css[ $k ] );
			$k++;
		}

		$zone = substr( $css, $j + 1, $k - $j - 2 );
	}

	$motif = '/(?:^|\})\s*' . preg_quote( $selecteur, '/' ) . '\s*\{([^{}]*)\}/m';

	return preg_match( $motif, $zone, $m ) ? trim( $m[1] ) : null;
}

// ------------------------------------------------------------- le fichier ---
$css_path = $theme . '/assets/css/urbizen-accueil-entete.css';

check( 'La feuille urbizen-accueil-entete.css existe', is_file( $css_path ) );

if ( ! is_file( $css_path ) ) {
	echo "\nFEUILLE ABSENTE — contrôles interrompus\n";
	exit( 1 );
}

// Les contrôles portent sur les RÈGLES, jamais sur les commentaires : ceux-ci
// citent les sélecteurs du thème parent pour expliquer ce qu'on neutralise.
$css = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $css_path ) );

// ------------------------------------------------ 1. la bande de 75 px ------
$body = regle( $css, 'html body.u-grid-bg' );

check( 'Bande du thème parent neutralisée : padding-top remis à 0',
	null !== $body && preg_match( '/padding-top:\s*0\s*;/', $body ) );
check( 'La neutralisation est bornée à la page d\'accueil (classe u-grid-bg)',
	str_contains( $css, 'body.u-grid-bg' ) && ! preg_match( '/(?<![.\w-])body\s*\{/', $css ) );

// --------------------------------------------- 2. l'enveloppe du template ---
$enveloppe = regle( $css, '.urbizen-accueil .urbizen-entete' );

check( 'L\'enveloppe du template part ne borne plus l\'en-tête',
	null !== $enveloppe && preg_match( '/display:\s*contents\s*;/', $enveloppe ) );
check( 'Le pied de page n\'est pas touché : aucune règle sur .wp-block-template-part',
	! str_contains( $css, '.wp-block-template-part' ) );

// ------------------------------------------------------ 3. le sticky --------
$entete = regle( $css, '.urbizen-accueil header.site' );

check( 'En-tête en position sticky', null !== $entete && preg_match( '/position:\s*sticky/', $entete ) );
check( 'Le sticky porte !important, seul moyen de battre la règle du thème',
	null !== $entete && preg_match( '/position:\s*sticky\s*!important/', $entete ) );
check( 'top: 0 — l\'en-tête est collé au sommet pour un visiteur anonyme',
	null !== $entete && preg_match( '/(?<!-)top:\s*0\s*;/', $entete ) );

preg_match( '/z-index:\s*(\d+)/', (string) $entete, $z );
$zindex = isset( $z[1] ) ? (int) $z[1] : 0;

check( 'z-index supérieur à la pile de Leaflet (1000)', $zindex > 1000 );
check( 'z-index en !important, la règle du thème imposant 10',
	null !== $entete && preg_match( '/z-index:[^;]*!important/', $entete ) );

// --------------------------------------------- 4. barre d'administration ----
$ab_defaut = regle( $css, 'body.admin-bar .urbizen-accueil header.site' );
$ab_782    = regle( $css, 'body.admin-bar .urbizen-accueil header.site', 'max-width: 782px' );
$ab_600    = regle( $css, 'body.admin-bar .urbizen-accueil header.site', 'max-width: 600px' );

check( 'admin-bar : décalage de 32 px au-delà de 782 px',
	null !== $ab_defaut && preg_match( '/top:\s*32px/', $ab_defaut ) );
check( 'admin-bar : décalage de 46 px sous 782 px',
	null !== $ab_782 && preg_match( '/top:\s*46px/', $ab_782 ) );
check( 'admin-bar : aucun décalage sous 601 px, la barre y défile avec la page',
	null !== $ab_600 && preg_match( '/top:\s*0/', $ab_600 ) );
check( 'Les décalages sont conditionnés à .admin-bar, jamais imposés à l\'anonyme',
	3 === substr_count( $css, 'body.admin-bar' ) );

// ------------------------------------------ 5. ancres sous l'en-tête --------
// Un en-tête collant ne décale pas la cible d'une ancre : sans scroll-margin,
// le navigateur amène le haut de la section à y = 0, donc derrière l'en-tête.
$cibles = array( '#localisation', '#methode', '#prestations', '#tarifs', '#faq' );

foreach ( $cibles as $cible ) {
	check( 'Ancre « ' . $cible .' » : scroll-margin-top défini',
		(bool) preg_match(
			'/\.urbizen-accueil\s+' . preg_quote( $cible, '/' ) . '\s*[,{]/',
			$css
		) );
}

preg_match_all( '/scroll-margin-top:\s*(\d+)px/', $css, $marges );
$valeurs = array_map( 'intval', $marges[1] );

check( 'Deux valeurs de scroll-margin-top : bureau et mobile', 2 === count( $valeurs ) );
check( 'Bureau : 80 px, soit les 71 px de l\'en-tête plus une respiration',
	in_array( 80, $valeurs, true ) );
check( 'Mobile : 72 px, soit les 63 px de l\'en-tête sous 421 px',
	in_array( 72, $valeurs, true ) );
check( 'Chaque valeur dépasse la hauteur réelle de l\'en-tête',
	80 > 71 && 72 > 63 );
// La valeur mobile doit vivre dans la media query du point de rupture de la
// maquette — celui où `.nav` passe de 70 à 62 px de haut.
$bloc_420 = null;
$i        = strpos( $css, 'max-width: 420px' );

if ( false !== $i ) {
	$j = strpos( $css, '{', $i );
	$p = 1;
	$k = $j + 1;

	while ( $k < strlen( $css ) && $p > 0 ) {
		$p += ( '{' === $css[ $k ] ) - ( '}' === $css[ $k ] );
		$k++;
	}

	$bloc_420 = substr( $css, $j + 1, $k - $j - 2 );
}

check( 'La valeur mobile est bornée au point de rupture de la maquette (420 px)',
	null !== $bloc_420 && str_contains( $bloc_420, 'scroll-margin-top: 72px' ) );
check( 'Les 5 cibles sont reprises dans la règle mobile',
	null !== $bloc_420 && 5 === substr_count( $bloc_420, '.urbizen-accueil #' ) );
check( 'Aucun JavaScript de défilement n\'est ajouté',
	! str_contains( $css, 'scroll-behavior' ) );

// ------------------------------------------------------- le gabarit ---------
$source = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front  = file_get_contents( $theme . '/templates/front-page.html' );

check( 'Le gabarit pose la classe urbizen-entete sur l\'en-tête',
	str_contains( $source, '"slug":"header-urbizen"' ) && str_contains( $source, '"className":"urbizen-entete"' ) );
check( 'Le pied de page garde son enveloppe inchangée',
	preg_match( '/<!-- wp:template-part \{"slug":"footer-urbizen","tagName":"div"\} \/-->/', $source ) );
check( 'front-page.html reste strictement identique à la source', $front === $source );

// --------------------------------- la feuille générée reste intacte ---------
// L'invariant de urbizen-homepage.css : rien d'ajouté, pas un !important.
$genere = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );
$maquette_css = file_get_contents( $racine . '/frontend/homepage/homepage.css' );

check( 'urbizen-homepage.css : toujours aucun !important ajouté',
	substr_count( $genere, '!important' ) === substr_count( $maquette_css, '!important' ) );
check( 'urbizen-homepage.css : la règle sticky de la maquette est conservée',
	preg_match( '/\.urbizen-accueil header\.site\s*\{[^}]*position:\s*sticky/', $genere ) );

// ------------------------------------ rien d'autre n'est touché -------------
foreach ( array(
	'.hero', '.wrap', 'main', 'section', 'footer', '.foot',
	'[data-urbizen-cadastre]', '.leaflet', 'nav a', '.btn',
) as $interdit ) {
	check( 'Aucune règle sur « ' . $interdit . ' »', ! str_contains( $css, $interdit . ' {' ) );
}

check( 'Aucune propriété de contenu (couleur, police, taille)',
	! preg_match( '/(?:^|[;{\s])(color|font-family|font-size|content)\s*:/m', $css ) );
check( 'Aucune largeur ni débordement imposés',
	! preg_match( '/(?:^|[;{\s])(width|overflow|transform)\s*:/m', $css ) );

// -------------------------------------- le burger reste intact --------------
$pattern = file_get_contents( $theme . '/patterns/header-accueil.php' );
$js      = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );

check( 'Le bouton burger est inchangé dans le pattern', str_contains( $pattern, 'burger' ) );
check( 'Le script du burger est inchangé',
	str_contains( $js, 'burger' ) && str_contains( $js, 'aria-expanded' ) );
check( 'Aucune règle CSS ne touche au burger', ! str_contains( $css, 'burger' ) );

// ------------------------------------ chargement conditionnel ---------------
define( 'ABSPATH', __DIR__ );

$GLOBALS['urbizen_theme_dir'] = $theme;
$GLOBALS['urbizen_front']     = false;
$GLOBALS['urbizen_singular']  = false;
$GLOBALS['urbizen_slug']      = '';
$GLOBALS['urbizen_styles']    = array();
$GLOBALS['urbizen_deps']      = array();
$GLOBALS['urbizen_scripts']   = array();
$GLOBALS['urbizen_filtres']   = array();

function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['urbizen_filtres'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['urbizen_filtres'][ $h ][] = $cb; }
function get_template_directory() { return $GLOBALS['urbizen_theme_dir'] . '/../hostinger-ai-theme'; }
function get_template_directory_uri() { return 'https://exemple.test/wp-content/themes/hostinger-ai-theme'; }
function get_stylesheet_directory() { return $GLOBALS['urbizen_theme_dir']; }
function get_stylesheet_directory_uri() { return 'https://exemple.test/wp-content/themes/urbizen-child'; }
function get_stylesheet_uri() { return get_stylesheet_directory_uri() . '/style.css'; }
function load_child_theme_textdomain( $d, $p ) { return true; }
function wp_json_file_decode( $f, $a = array() ) { return json_decode( file_get_contents( $f ), true ); }
function is_front_page() { return $GLOBALS['urbizen_front']; }
function is_singular( $t = '' ) { return $GLOBALS['urbizen_singular']; }
function get_queried_object_id() { return $GLOBALS['urbizen_singular'] ? 4 : 0; }
function get_page_template_slug( $id = 0 ) { return $GLOBALS['urbizen_slug']; }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['urbizen_styles'][] = $handle;
	$GLOBALS['urbizen_deps'][ $handle ] = $deps;
}
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
	$GLOBALS['urbizen_scripts'][] = $handle;
}

require_once $theme . '/functions.php';

function contexte( $front, $singular, $slug ) {
	$GLOBALS['urbizen_front']    = $front;
	$GLOBALS['urbizen_singular'] = $singular;
	$GLOBALS['urbizen_slug']     = $slug;
	$GLOBALS['urbizen_styles']   = array();
	$GLOBALS['urbizen_deps']     = array();

	urbizen_child_enqueue_accueil();

	return $GLOBALS['urbizen_styles'];
}

$sur_accueil = contexte( true, true, 'no-title' );

check( 'Accueil du site : la feuille de l\'en-tête est chargée',
	in_array( 'urbizen-entete', $sur_accueil, true ) );
check( 'Elle est chargée après la feuille générée depuis la maquette',
	array( 'urbizen-homepage' ) === ( $GLOBALS['urbizen_deps']['urbizen-entete'] ?? array() ) );
check( 'Elle arrive en dernier dans la file',
	'urbizen-entete' === end( $sur_accueil ) );
check( 'Les 3 feuilles précédentes sont toujours chargées',
	array() === array_diff( array( 'urbizen-fonts', 'urbizen-tokens', 'urbizen-homepage' ), $sur_accueil ) );

check( 'Gabarit personnalisé (brouillon, prévisualisation) : chargée aussi',
	in_array( 'urbizen-entete', contexte( false, true, 'page-accueil-urbizen' ), true ) );
check( 'Autre page : la feuille de l\'en-tête n\'est PAS chargée',
	! in_array( 'urbizen-entete', contexte( false, true, '' ), true ) );
check( 'Archive : la feuille de l\'en-tête n\'est PAS chargée',
	! in_array( 'urbizen-entete', contexte( false, false, '' ), true ) );

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
