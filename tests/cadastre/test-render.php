<?php
/**
 * Banc d'essai hors WordPress : vérifie que bloc et shortcode produisent
 * exactement le même rendu, que les attributs hostiles sont échappés et que
 * les ressources sont enfilées à la demande. Aucun accès réseau, aucune base.
 */

define( 'ABSPATH', __DIR__ );
define( 'URBIZEN_PLATFORM_URL', 'https://exemple.test/wp-content/plugins/urbizen-platform/' );
const URBIZEN_PLATFORM_VERSION = '0.1.0';

$GLOBALS['enqueued']   = array();
$GLOBALS['registered'] = array();
$GLOBALS['blocks']     = array();
$GLOBALS['shortcodes'] = array();

function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $c; }
function add_shortcode( $t, $c ) { $GLOBALS['shortcodes'][ $t ] = $c; }
function register_block_type( $n, $args ) { $GLOBALS['blocks'][ $n ] = $args; }
function wp_register_style( $h, $s = '', $d = array(), $v = '' ) { $GLOBALS['registered'][] = "style:$h => $s"; }
function wp_register_script( $h, $s = '', $d = array(), $v = '', $f = false ) { $GLOBALS['registered'][] = "script:$h => $s"; }
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
check( 'Ressources enfilees au rendu', 4 === count( $GLOBALS['enqueued'] ) );

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

echo "\n", 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
