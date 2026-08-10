<?php
/**
 * Génère un aperçu autonome de la page Tarifs.
 *
 * POURQUOI
 *
 * Le dépôt ne contient pas d'installation WordPress : `/tarifs/` n'existe donc
 * nulle part où la regarder. Or un gabarit de blocs n'est pas un fichier qu'on
 * ouvre — c'est une suite de directives qu'un moteur assemble. Sans ce
 * générateur, la seule façon de voir la page serait de la déployer, c'est-à-dire
 * exactement ce qu'on veut éviter avant validation.
 *
 * Ce script fait donc, à la main, ce que WordPress ferait : il déplie les blocs
 * `wp:html`, exécute les `wp:pattern` et `wp:template-part`, et enchaîne les
 * feuilles dans l'ordre où `urbizen_child_enqueue_accueil()` les met en file.
 * Le résultat est le DOM que le visiteur recevra — pas une approximation.
 *
 * CE QU'IL NE REPRODUIT PAS, et qu'il faut avoir en tête en lisant l'aperçu :
 *
 *   - les styles globaux du thème parent et de WordPress (`wp-block-library`),
 *     absents du dépôt ;
 *   - l'enveloppe `<div class="wp-block-template-part">` que WordPress pose
 *     autour d'un template part — elle est reproduite ici, justement parce
 *     qu'elle porte `.urbizen-entete` et son `display: contents` ;
 *   - la barre d'administration.
 *
 * USAGE
 *
 *   php tests/tarifs/apercu-tarifs.php > tests/tarifs/apercu-tarifs.html
 *
 * Le fichier produit est un artefact : il est ignoré par Git et se régénère.
 *
 * Toutes les données sont celles du thème. Aucune donnée client, aucun réseau,
 * aucune écriture hors du fichier de sortie.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

if ( ! is_dir( $theme ) ) {
	fwrite( STDERR, "Thème introuvable : $theme\n" );
	exit( 2 );
}

define( 'ABSPATH', $racine );

// ---------------------------------------------------------------------------
// Doublures WordPress — strictement celles qu'emploient le thème et ses
// patterns. Chacune rend ce que rendrait WordPress sur cette page.
// ---------------------------------------------------------------------------

/** Chemin public des ressources du thème, relatif à la racine servie. */
const APERCU_URI = '/wordpress/urbizen-child';

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function add_action() {}
function add_filter() {}
function add_theme_support() {}
function register_block_pattern() {}
function get_stylesheet_directory() { return ABSPATH . '/wordpress/urbizen-child'; }
function get_stylesheet_directory_uri() { return APERCU_URI; }
function get_template_directory() { return ABSPATH . '/wordpress/urbizen-child'; }
function get_template_directory_uri() { return APERCU_URI; }
function get_theme_file_uri( $f = '' ) { return APERCU_URI . '/' . ltrim( (string) $f, '/' ); }
function home_url( $p = '/' ) { return $p; }
function is_front_page() { return false; }
function is_singular() { return true; }
function get_queried_object_id() { return 10; }
function get_page_template_slug( $id = 0 ) { return 'page-tarifs'; }
function wp_kses_post( $t ) { return (string) $t; }

/**
 * Charge le catalogue et les constantes du thème sans exécuter le reste de
 * `functions.php` — dont l'amorçage suppose un WordPress complet.
 *
 * On extrait la fonction et la constante par lecture du source : c'est
 * volontairement conservateur. Exécuter tout le fichier obligerait à doubler
 * une trentaine d'API de plus, pour rien.
 *
 * @return void
 */
function apercu_charger_catalogue() {
	$src = (string) file_get_contents( get_stylesheet_directory() . '/functions.php' );

	if ( preg_match( '/^function urbizen_child_tarifs\(\).*?^}$/ms', $src, $m ) ) {
		eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- source du dépôt, jamais une entrée.
		return;
	}

	fwrite( STDERR, "urbizen_child_tarifs() introuvable dans functions.php\n" );
	exit( 1 );
}

apercu_charger_catalogue();

/**
 * Rend un fichier PHP (pattern) et retourne sa sortie.
 *
 * @param string $chemin Chemin absolu.
 * @return string
 */
function apercu_rendre_php( $chemin ) {
	ob_start();
	include $chemin;

	return (string) ob_get_clean();
}

/**
 * Déplie un gabarit de blocs en HTML.
 *
 * Trois directives seulement sont employées par les gabarits Urbizen :
 * `wp:html` (contenu brut), `wp:template-part` (enveloppé dans un `div`
 * comme le fait WordPress) et `wp:pattern` (fichier PHP exécuté).
 *
 * @param string $gabarit Contenu du fichier .html du gabarit.
 * @return string
 */
function apercu_deplier( $gabarit ) {
	global $theme;

	// Les template parts Urbizen ne contiennent qu'un appel de pattern.
	$gabarit = preg_replace_callback(
		'/<!--\s*wp:template-part\s*(\{.*?\})\s*\/-->/s',
		function ( $m ) use ( $theme ) {
			$attrs = json_decode( $m[1], true );
			$slug  = $attrs['slug'] ?? '';
			$part  = $theme . '/parts/' . $slug . '.html';

			if ( ! is_file( $part ) ) {
				return '<!-- template part absent : ' . esc_html( $slug ) . ' -->';
			}

			$classe = trim( 'wp-block-template-part ' . ( $attrs['className'] ?? '' ) );

			return '<div class="' . esc_attr( $classe ) . '">'
				. apercu_deplier( (string) file_get_contents( $part ) )
				. '</div>';
		},
		$gabarit
	);

	$gabarit = preg_replace_callback(
		'/<!--\s*wp:pattern\s*(\{.*?\})\s*\/-->/s',
		function ( $m ) use ( $theme ) {
			$attrs   = json_decode( $m[1], true );
			$slug    = (string) ( $attrs['slug'] ?? '' );
			$fichier = $theme . '/patterns/' . substr( $slug, strrpos( $slug, '/' ) + 1 ) . '.php';

			if ( ! is_file( $fichier ) ) {
				return '<!-- pattern absent : ' . esc_html( $slug ) . ' -->';
			}

			return apercu_deplier( apercu_rendre_php( $fichier ) );
		},
		$gabarit
	);

	// Les marqueurs wp:html / /wp:html ne produisent rien.
	return preg_replace( '/<!--\s*\/?wp:html\s*-->/', '', $gabarit );
}

$gabarit = $theme . '/templates/page-tarifs.html';

if ( ! is_file( $gabarit ) ) {
	fwrite( STDERR, "Gabarit introuvable : $gabarit\n" );
	exit( 2 );
}

$corps = apercu_deplier( (string) file_get_contents( $gabarit ) );

// Ordre EXACT de mise en file par urbizen_child_enqueue_accueil() : la cascade
// dépend de cet ordre, un aperçu qui l'inverserait ne prouverait rien.
$feuilles = array(
	'/assets/css/urbizen-fonts.css',
	'/assets/css/urbizen-tokens.css',
	'/style.css',
	'/assets/css/urbizen-homepage.css',
	'/assets/css/urbizen-accueil-entete.css',
	'/assets/css/urbizen-pages.css',
);

$liens = '';

foreach ( $feuilles as $feuille ) {
	if ( is_file( $theme . $feuille ) ) {
		$liens .= '<link rel="stylesheet" href="' . APERCU_URI . $feuille . '" />' . "\n";
	}
}

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Tarifs déclaration préalable et permis de construire | Urbizen</title>
<meta name="description" content="Découvrez les tarifs Urbizen pour votre déclaration préalable, permis de construire et conception de plans. Dossiers préparés à distance partout en France." />
<?php echo $liens; ?>
</head>
<body class="u-grid-bg">
<?php echo $corps; ?>
</body>
</html>
