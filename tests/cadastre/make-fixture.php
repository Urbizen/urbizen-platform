<?php
/**
 * Génère la fixture HTML consommée par test-form.mjs.
 *
 * Le HTML provient du **vrai** `Renderer.php`, avec les mêmes doublures de
 * fonctions WordPress que les autres bancs d'essai. Le test JavaScript ne
 * contient donc aucune copie manuelle de la structure : si le rendu change de
 * façon incompatible, le test échoue au lieu de rester vert sur un gabarit
 * périmé.
 *
 * La fixture ne contient **aucune donnée personnelle** : tous les champs sont
 * rendus vides, seuls les libellés et les attributs techniques y figurent.
 *
 * Usage : php make-fixture.php > fixture.html
 */

define( 'ABSPATH', __DIR__ );
define( 'URBIZEN_PLATFORM_URL', 'https://exemple.test/wp-content/plugins/urbizen-platform/' );
define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );

preg_match( "/URBIZEN_PLATFORM_VERSION\s*=\s*'([^']+)'/", file_get_contents( URBIZEN_PLATFORM_DIR . 'urbizen-platform.php' ), $m );
define( 'URBIZEN_PLATFORM_VERSION', $m[1] ?? '0.0.0' );

$GLOBALS['actions'] = array();

function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $c; }
function add_shortcode( $t, $c ) {}
function register_block_type( $n, $args = array() ) {}
function wp_register_script( ...$x ) {}
function wp_register_style( ...$x ) {}
function wp_enqueue_style( $h ) {}
function wp_enqueue_script( $h ) {}
function wp_set_script_translations( ...$x ) {}
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function shortcode_atts( $pairs, $atts, $sc = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

require URBIZEN_PLATFORM_DIR . 'src/Forms/FormDefinition.php';
require URBIZEN_PLATFORM_DIR . 'src/Forms/FormRegistry.php';
require URBIZEN_PLATFORM_DIR . 'src/Forms/Renderer.php';
require URBIZEN_PLATFORM_DIR . 'src/Blocks/FormBlock.php';

use Urbizen\Platform\Blocks\FormBlock;

FormBlock::register();

foreach ( $GLOBALS['actions']['init'] as $cb ) {
	$cb();
}

/**
 * Trois instances : une par défaut, deux avec des clés distinctes. Le test
 * JavaScript s'appuie sur cet ordre.
 */
$instances = array(
	array( 'storageKey' => 'parcel' ),
	array( 'storageKey' => 'parcel-a', 'formId' => 'A' ),
	array( 'storageKey' => 'parcel-b', 'formId' => 'B' ),
);

$html = '';

foreach ( $instances as $options ) {
	$html .= FormBlock::render_block( $options ) . "\n";
}

// Garde-fou : la fixture ne doit jamais transporter de valeur.
if ( preg_match( '/value="[^"]+"/', $html ) ) {
	fwrite( STDERR, "make-fixture : une valeur non vide est présente dans le rendu, génération refusée.\n" );
	exit( 1 );
}

echo $html;
