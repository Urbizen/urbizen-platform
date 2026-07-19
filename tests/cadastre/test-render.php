<?php
/**
 * Banc d'essai hors WordPress : vérifie que bloc et shortcode produisent
 * exactement le même rendu, que les attributs hostiles sont échappés et que
 * les ressources sont enfilées à la demande. Aucun accès réseau, aucune base.
 */

define( 'ABSPATH', __DIR__ );
define( 'URBIZEN_PLATFORM_URL', 'https://exemple.test/wp-content/plugins/urbizen-platform/' );
define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );

// La version est lue dans le fichier principal du plugin : le banc detecte
// ainsi toute desynchronisation entre l'en-tete, la constante et block.json.
preg_match( "/URBIZEN_PLATFORM_VERSION\s*=\s*'([^']+)'/", file_get_contents( URBIZEN_PLATFORM_DIR . 'urbizen-platform.php' ), $m );
define( 'URBIZEN_PLATFORM_VERSION', $m[1] ?? '0.0.0' );
preg_match( "/^ \* Version:\s*(.+)$/m", file_get_contents( URBIZEN_PLATFORM_DIR . 'urbizen-platform.php' ), $mh );
define( 'URBIZEN_PLUGIN_HEADER_VERSION', trim( $mh[1] ?? '' ) );


$GLOBALS['enqueued']   = array();
$GLOBALS['registered'] = array();
$GLOBALS['blocks']     = array();
$GLOBALS['shortcodes'] = array();

function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $c; }
function add_shortcode( $t, $c ) { $GLOBALS['shortcodes'][ $t ] = $c; }
function register_block_type( $n, $args = array() ) {
	// Reproduit le comportement de WordPress : un chemin de repertoire est lu
	// depuis block.json, dont les metadonnees sont fusionnees avec $args.
	if ( is_string( $n ) && is_dir( $n ) ) {
		$meta = json_decode( (string) file_get_contents( $n . '/block.json' ), true );
		$args = array_merge( $meta, $args );
		$n    = $meta['name'];
	}
	$GLOBALS['blocks'][ $n ] = $args;
}
function wp_register_style( $h, $s = '', $d = array(), $v = '' ) {
	$GLOBALS['registered'][] = "style:$h => $s";
	$GLOBALS['styles'][ $h ] = array( 'src' => $s, 'deps' => $d, 'ver' => $v );
}
function wp_register_script( $h, $s = '', $d = array(), $v = '', $f = false ) {
	$GLOBALS['registered'][]         = "script:$h => $s";
	$GLOBALS['scripts'][ $h ]        = array( 'src' => $s, 'deps' => $d, 'ver' => $v );
}
function wp_set_script_translations( $h, $d = '' ) { $GLOBALS['translations'][] = $h; }
function wp_enqueue_style( $h ) { $GLOBALS['enqueued'][] = "style:$h"; }
function wp_enqueue_script( $h ) { $GLOBALS['enqueued'][] = "script:$h"; }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $s ) {
	$s = strip_tags( (string) $s );
	$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
	return trim( preg_replace( '/[\x00-\x1f\x7f]/u', '', $s ) );
}
function shortcode_atts( $pairs, $atts, $sc = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

require __DIR__ . '/../../wordpress/urbizen-platform/src/Blocks/CadastreBlock.php';

use Urbizen\Platform\Blocks\CadastreBlock;

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-62s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

CadastreBlock::register();
foreach ( $GLOBALS['actions']['init'] as $cb ) { $cb(); }

// --- Enregistrement ---
check( 'Bloc urbizen/cadastre enregistre', isset( $GLOBALS['blocks']['urbizen/cadastre'] ) );
check( 'Shortcode urbizen_cadastre enregistre', isset( $GLOBALS['shortcodes']['urbizen_cadastre'] ) );
check( 'Rendu dynamique (render_callback)', is_callable( $GLOBALS['blocks']['urbizen/cadastre']['render_callback'] ) );
check( 'Leaflet servi localement, pas de CDN',
	! preg_grep( '#unpkg|cdn|jsdelivr#i', $GLOBALS['registered'] ) && (bool) preg_grep( '#vendor/leaflet#', $GLOBALS['registered'] ) );
check( 'Aucune ressource enfilee avant rendu', array() === $GLOBALS['enqueued'] );

// --- Rendu par defaut ---
$html = CadastreBlock::render_block( array() );
check( 'Conteneur data-urbizen-cadastre', str_contains( $html, 'data-urbizen-cadastre="1"' ) );
check( 'Repli noscript present', str_contains( $html, '<noscript>' ) );
check( 'Ressources enfilees au rendu', 2 === count( $GLOBALS['enqueued'] ) );

// --- Equivalence bloc / shortcode ---
$a = CadastreBlock::render_block( array( 'label' => 'Adresse du terrain', 'continueLabel' => 'Suite' ) );
$b = CadastreBlock::render_shortcode( array( 'label' => 'Adresse du terrain', 'continuelabel' => 'Suite' ) );
check( 'Bloc et shortcode : rendu identique', $a === $b );

// --- Echappement ---
$xss  = '"><script>alert(1)</script>';
$html = CadastreBlock::render_block( array( 'label' => $xss, 'placeholder' => $xss ) );
check( 'Aucune balise script injectee', ! str_contains( $html, '<script' ) );
check( 'Payload neutralise et conserve', str_contains( $html, 'alert(1)' ) );
// L'attribut ne doit jamais se refermer prematurement : on verifie que le
// guillemet du payload ressort echappe a l'interieur de data-label.
check( 'Guillemet du payload echappe dans l attribut',
	(bool) preg_match( '/data-label="[^"]*&quot;/', $html ) );
// Un seul element racine : la balise ouvrante n'a pas ete cassee.
check( 'Structure du conteneur intacte',
	1 === preg_match_all( '/<div[^>]*data-urbizen-cadastre/', $html ) && str_ends_with( $html, '</div>' ) );

// --- Hauteur de carte ---
$ok  = CadastreBlock::render_block( array( 'mapHeight' => '420px' ) );
$bad = CadastreBlock::render_block( array( 'mapHeight' => 'expression(alert(1))' ) );
check( 'Hauteur valide acceptee', str_contains( $ok, 'data-map-height="420px"' ) );
check( 'Hauteur invalide rejetee', ! str_contains( $bad, 'data-map-height' ) );


// --- Interface d'edition ---
$meta = $GLOBALS['blocks']['urbizen/cadastre'];
check( 'block.json : editorScript declare', ! empty( $meta['editorScript'] ) );
check( 'Handle editeur enregistre', isset( $GLOBALS['scripts'][ $meta['editorScript'] ] ) );
check( 'Style editeur enregistre', isset( $GLOBALS['styles'][ $meta['editorStyle'] ] ) );
$editor = $GLOBALS['scripts']['urbizen-cadastre-editor'] ?? array();
check( 'Editeur : dependances Gutenberg declarees',
	in_array( 'wp-blocks', $editor['deps'] ?? array(), true )
	&& in_array( 'wp-block-editor', $editor['deps'] ?? array(), true )
	&& in_array( 'wp-components', $editor['deps'] ?? array(), true ) );
check( 'Editeur : ni Leaflet ni script du site public en dependance',
	! in_array( 'leaflet', $editor['deps'] ?? array(), true )
	&& ! in_array( 'urbizen-cadastre', $editor['deps'] ?? array(), true ) );
check( 'editor.js et editor.css presents sur disque',
	is_file( URBIZEN_PLATFORM_DIR . 'blocks/cadastre/editor.js' )
	&& is_file( URBIZEN_PLATFORM_DIR . 'blocks/cadastre/editor.css' ) );

// --- Attributs : declaration unique et coherence ---
$json = json_decode( (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'blocks/cadastre/block.json' ), true );
check( 'Attributs declares dans block.json', 5 === count( $json['attributes'] ) );
check( 'Aucun attribut redeclare en PHP',
	! preg_match( "/'default'\s*=>\s*'[^']+'/", file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Blocks/CadastreBlock.php' ) ) );
$editor_src = file_get_contents( URBIZEN_PLATFORM_DIR . 'blocks/cadastre/editor.js' );
check( 'Aucun attribut redeclare dans editor.js', ! str_contains( $editor_src, 'attributes:' ) );
check( 'Editeur : rendu dynamique (save renvoie null)', (bool) preg_match( '/save:\s*function\s*\(\)\s*\{\s*return null;/', $editor_src ) );
// Le controle vise les APPELS, pas les mentions en commentaire : on retire
// d'abord les commentaires du fichier.
$editor_code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $editor_src );
check( 'Editeur : aucun appel reseau ni carte Leaflet',
	! preg_match( '#geopf|apicarto|data\.gouv|fetch\s*\(|XMLHttpRequest|L\.map|new\s+L\.#i', $editor_code ) );
check( 'Hauteur : meme regle en PHP et dans l editeur',
	str_contains( $editor_src, '/^\\d{1,4}(px|vh|rem|em)$/' ) );

// --- Versions et cache ---
check( 'En-tete et constante du plugin concordent',
	URBIZEN_PLUGIN_HEADER_VERSION === URBIZEN_PLATFORM_VERSION );
check( 'Handles versionnes sur la version du plugin (cache)',
	URBIZEN_PLATFORM_VERSION === ( $GLOBALS['scripts']['urbizen-cadastre']['ver'] ?? '' )
	&& URBIZEN_PLATFORM_VERSION === ( $GLOBALS['styles']['urbizen-cadastre']['ver'] ?? '' )
	&& URBIZEN_PLATFORM_VERSION === ( $GLOBALS['scripts']['urbizen-cadastre-editor']['ver'] ?? '' ) );
check( 'Leaflet versionne separement', '1.9.4' === ( $GLOBALS['scripts']['leaflet']['ver'] ?? '' ) );
check( 'block.json aligne sur la version du plugin', URBIZEN_PLATFORM_VERSION === ( $json['version'] ?? '' ) );

// --- Dependances et ordre de chargement ---
check( 'Script cadastre depend de Leaflet',
	in_array( 'leaflet', $GLOBALS['scripts']['urbizen-cadastre']['deps'] ?? array(), true ) );
check( 'Style cadastre depend du style Leaflet',
	in_array( 'leaflet', $GLOBALS['styles']['urbizen-cadastre']['deps'] ?? array(), true ) );

// --- Pas de double enqueue ---
$GLOBALS['enqueued'] = array();
CadastreBlock::render_block( array() );
CadastreBlock::render_block( array() );
CadastreBlock::render_shortcode( array() );
check( 'Trois rendus : chaque handle enfile une seule fois',
	count( array_unique( $GLOBALS['enqueued'] ) ) === count( array_unique( $GLOBALS['enqueued'] ) )
	&& count( array_unique( $GLOBALS['enqueued'] ) ) === 2 );

// --- Licence Leaflet ---
check( 'Licence Leaflet presente',
	is_file( URBIZEN_PLATFORM_DIR . 'assets/vendor/leaflet/LICENSE' )
	&& str_contains( file_get_contents( URBIZEN_PLATFORM_DIR . 'assets/vendor/leaflet/LICENSE' ), 'BSD 2-Clause' ) );
check( 'En-tete @preserve conserve dans leaflet.js',
	str_contains( substr( file_get_contents( URBIZEN_PLATFORM_DIR . 'assets/vendor/leaflet/leaflet.js' ), 0, 200 ), '@preserve' ) );

echo "\n", 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
