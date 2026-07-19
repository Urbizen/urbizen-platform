<?php
/**
 * Banc d'essai du rendu serveur du formulaire Urbizen.
 *
 * Hors WordPress : les fonctions utilisées sont doublées ci-dessous. Vérifie
 * l'enregistrement du bloc et du shortcode, l'identité de leurs rendus,
 * l'échappement, l'absence de soumission réseau et la cohérence des champs
 * avec le contrat canonique.
 */

define( 'ABSPATH', __DIR__ );
define( 'URBIZEN_PLATFORM_URL', 'https://exemple.test/wp-content/plugins/urbizen-platform/' );
define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );

preg_match( "/URBIZEN_PLATFORM_VERSION\s*=\s*'([^']+)'/", file_get_contents( URBIZEN_PLATFORM_DIR . 'urbizen-platform.php' ), $m );
define( 'URBIZEN_PLATFORM_VERSION', $m[1] ?? '0.0.0' );
preg_match( "/^ \* Version:\s*(.+)$/m", file_get_contents( URBIZEN_PLATFORM_DIR . 'urbizen-platform.php' ), $mh );
define( 'URBIZEN_PLUGIN_HEADER_VERSION', trim( $mh[1] ?? '' ) );

$GLOBALS['enqueued']   = array();
$GLOBALS['scripts']    = array();
$GLOBALS['styles']     = array();
$GLOBALS['blocks']     = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['actions']    = array();

function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $c; }
function add_shortcode( $t, $c ) { $GLOBALS['shortcodes'][ $t ] = $c; }
function register_block_type( $n, $args = array() ) {
	if ( is_string( $n ) && is_dir( $n ) ) {
		$meta = json_decode( (string) file_get_contents( $n . '/block.json' ), true );
		$args = array_merge( $meta, $args );
		$n    = $meta['name'];
	}
	$GLOBALS['blocks'][ $n ] = $args;
}
function wp_register_script( $h, $s = '', $d = array(), $v = '', $f = false ) {
	$GLOBALS['scripts'][ $h ] = array( 'src' => $s, 'deps' => $d, 'ver' => $v );
}
function wp_register_style( $h, $s = '', $d = array(), $v = '' ) {
	$GLOBALS['styles'][ $h ] = array( 'src' => $s, 'deps' => $d, 'ver' => $v );
}
function wp_enqueue_style( $h ) { $GLOBALS['enqueued'][] = "style:$h"; }
function wp_enqueue_script( $h ) { $GLOBALS['enqueued'][] = "script:$h"; }
function wp_set_script_translations( $h, $d = '' ) {}
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
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

require URBIZEN_PLATFORM_DIR . 'src/Forms/FormDefinition.php';
require URBIZEN_PLATFORM_DIR . 'src/Forms/FormRegistry.php';
require URBIZEN_PLATFORM_DIR . 'src/Forms/Renderer.php';
require URBIZEN_PLATFORM_DIR . 'src/Blocks/FormBlock.php';

use Urbizen\Platform\Blocks\FormBlock;
use Urbizen\Platform\Forms\FormRegistry;

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-66s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

FormBlock::register();
foreach ( $GLOBALS['actions']['init'] as $cb ) { $cb(); }

// --- Enregistrement ---
check( 'Bloc urbizen/formulaire enregistre', isset( $GLOBALS['blocks']['urbizen/formulaire'] ) );
check( 'Shortcode urbizen_formulaire enregistre', isset( $GLOBALS['shortcodes']['urbizen_formulaire'] ) );
check( 'Rendu dynamique (render_callback)', is_callable( $GLOBALS['blocks']['urbizen/formulaire']['render_callback'] ) );
check( 'Aucune ressource enfilee avant rendu', array() === $GLOBALS['enqueued'] );
check( 'Script formulaire independant du cadastre',
	! in_array( 'urbizen-cadastre', $GLOBALS['scripts']['urbizen-form']['deps'] ?? array(), true )
	&& ! in_array( 'leaflet', $GLOBALS['scripts']['urbizen-form']['deps'] ?? array(), true ) );
check( 'Handles versionnes sur la version du plugin',
	URBIZEN_PLATFORM_VERSION === ( $GLOBALS['scripts']['urbizen-form']['ver'] ?? '' )
	&& URBIZEN_PLATFORM_VERSION === ( $GLOBALS['styles']['urbizen-form']['ver'] ?? '' ) );
check( 'En-tete et constante du plugin concordent', URBIZEN_PLUGIN_HEADER_VERSION === URBIZEN_PLATFORM_VERSION );
$meta = $GLOBALS['blocks']['urbizen/formulaire'];
check( 'block.json aligne sur la version du plugin', URBIZEN_PLATFORM_VERSION === ( $meta['version'] ?? '' ) );
check( 'Attributs declares dans block.json : formType, storageKey, formId',
	array( 'formType', 'storageKey', 'formId' ) === array_keys( $meta['attributes'] ) );
check( 'Handle editeur enregistre', isset( $GLOBALS['scripts'][ $meta['editorScript'] ] ) );

// --- Rendu ---
$html = FormBlock::render_block( array() );
check( 'Conteneur data-urbizen-form', str_contains( $html, 'data-urbizen-form="1"' ) );
check( 'Type et cle de stockage exposes',
	str_contains( $html, 'data-form-type="localisation"' ) && str_contains( $html, 'data-storage-key="parcel"' ) );
check( 'Ressources enfilees au rendu', 2 === count( $GLOBALS['enqueued'] ) );
check( 'Repli noscript present', str_contains( $html, '<noscript>' ) );

// --- Aucune soumission reseau ---
check( 'Formulaire sans action', ! preg_match( '/<form[^>]*\saction\s*=/', $html ) );
check( 'Formulaire sans method', ! preg_match( '/<form[^>]*\smethod\s*=/', $html ) );
check( 'Aucun nonce expose', ! preg_match( '/nonce|_wpnonce/i', $html ) );
check( 'Aucun endpoint dans le rendu', ! preg_match( '#/wp-json|admin-ajax#', $html ) );

// --- Champs attendus ---
$def = FormRegistry::get( 'localisation' );
check( 'Definition localisation chargee', null !== $def );
check( '6 champs visibles', 6 === count( $def->visible_fields() ) );
check( '8 champs techniques masques', 8 === count( $def->hidden_fields() ) );

$visibles = array_column( $def->visible_fields(), 'name' );
check( 'Champs visibles conformes a la demande',
	array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'cad_section', 'cad_numero', 'terrain_superficie' ) === $visibles );

$caches = array_column( $def->hidden_fields(), 'name' );
check( 'Champs techniques conformes a la demande',
	array( 'adresse_code_commune', 'parcelle_code_commune', 'terrain_latitude', 'terrain_longitude',
		'cad_prefixe', 'cad_identifiant', 'schema_version', 'confirme_le' ) === $caches );

// --- Contrat : chemins et geometrie ---
$chemins = array_column( $def->fields(), 'from' );
$attendus = array(
	'address.label', 'address.postcode', 'address.city', 'parcel.section', 'parcel.number', 'parcel.surfaceM2',
	'address.cityCode', 'parcel.communeCode', 'location.latitude', 'location.longitude',
	'parcel.prefix', 'parcel.id', 'schemaVersion', 'confirmedAt',
);
check( 'Chemins du contrat canonique corrects', $attendus === $chemins );
check( 'AUCUN champ de geometrie', ! preg_match( '/geometry|geometrie/i', implode( ' ', $chemins ) . ' ' . $html ) );
check( 'Codes commune adresse et parcelle distincts',
	in_array( 'address.cityCode', $chemins, true ) && in_array( 'parcel.communeCode', $chemins, true ) );

// --- Surface : mention obligatoire ---
check( 'Mention « surface cadastrale indicative » visible',
	str_contains( $html, 'Surface cadastrale indicative' )
	&& str_contains( $html, 'plusieurs parcelles' ) );
check( 'Surface modifiable (champ non readonly ni disabled)',
	preg_match( '/name="terrain_superficie"[^>]*>/', $html, $mm )
	&& ! str_contains( $mm[0], 'readonly' ) && ! str_contains( $mm[0], 'disabled' ) );
check( 'Surface non obligatoire', ! preg_match( '/name="terrain_superficie"[^>]*required/', $html ) );

// --- Aucune valeur preremplie cote serveur ---
check( 'Aucun champ prerempli par le serveur',
	0 === preg_match( '/<input[^>]*value="(?!")[^"]+"/', $html ) );

// --- Bloc et shortcode identiques ---
$a = FormBlock::render_block( array( 'storageKey' => 'terrain-a', 'formId' => 'A' ) );
$b = FormBlock::render_shortcode( array( 'storagekey' => 'terrain-a', 'formid' => 'A' ) );
// Chaque rendu porte son propre prefixe d'instance (uf-1, uf-2...) : c'est
// justement ce qui garantit l'unicite des identifiants. On neutralise ce
// numero pour comparer la logique de rendu, qui doit etre identique.
$sans_instance = static fn( $h ) => preg_replace( '/\buf-\d+\b/', 'uf-N', $h );
check( 'Bloc et shortcode : rendu identique (hors numero d instance)', $sans_instance( $a ) === $sans_instance( $b ) );
check( 'Deux rendus successifs ont des prefixes differents', $a !== $b );
check( 'Cle de stockage personnalisee prise en compte', str_contains( $a, 'data-storage-key="terrain-a"' ) );

// --- Robustesse des attributs ---
$xss = '"><script>alert(1)</script>';
$h   = FormBlock::render_block( array( 'storageKey' => $xss, 'formId' => $xss, 'formType' => $xss ) );
check( 'Attributs hostiles : aucune balise injectee', ! str_contains( $h, '<script' ) );
// sanitize_text_field retire d'abord les balises, le filtre ne laisse ensuite
// que des caracteres surs : on verifie la propriete, pas une chaine exacte.
preg_match( '/data-storage-key="([^"]*)"/', $h, $k );
check( 'Cle de stockage nettoyee : caracteres surs uniquement',
	isset( $k[1] ) && 1 === preg_match( '/^[A-Za-z0-9_:-]+$/', $k[1] )
	&& ! str_contains( $k[1], 'script' ) && ! str_contains( $k[1], '<' ) );
check( 'Type inconnu : repli sur le formulaire par defaut', str_contains( $h, 'data-form-type="localisation"' ) );
check( 'Cle vide : repli sur la valeur par defaut',
	str_contains( FormBlock::render_block( array( 'storageKey' => '@@@' ) ), 'data-storage-key="parcel"' ) );

// --- Pas de double enfilage ---
$GLOBALS['enqueued'] = array();
FormBlock::render_block( array() );
FormBlock::render_block( array( 'storageKey' => 'autre' ) );
FormBlock::render_shortcode( array() );
check( 'Trois rendus : chaque handle enfile une seule fois', 2 === count( array_unique( $GLOBALS['enqueued'] ) ) );

// --- I-1 : identifiants uniques des le rendu serveur, sur trois formulaires ---
\Urbizen\Platform\Forms\Renderer::reset_instances();
$page = FormBlock::render_block( array( 'storageKey' => 'un' ) )
	. FormBlock::render_block( array( 'storageKey' => 'deux' ) )
	. FormBlock::render_shortcode( array( 'storagekey' => 'trois' ) );

preg_match_all( '/\bid="([^"]+)"/', $page, $mm );
$ids = $mm[1];
check( 'Trois rendus : au moins 18 identifiants produits', count( $ids ) >= 18 );
check( 'Trois rendus : AUCUN identifiant duplique', count( $ids ) === count( array_unique( $ids ) ) );

preg_match_all( '/<label[^>]*for="([^"]+)"/', $page, $ml );
check( 'Trois rendus : chaque label vise un identifiant existant',
	count( $ml[1] ) === count( array_intersect( $ml[1], $ids ) ) );
check( 'Trois rendus : aucun label duplique', count( $ml[1] ) === count( array_unique( $ml[1] ) ) );

// --- I-2 : accessibilite des messages d'erreur ---
preg_match_all( '/<p class="uf-error" id="([^"]+)"([^>]*)>/', $page, $me );
check( 'Chaque champ visible a un conteneur d erreur identifie', count( $me[1] ) === 18 );
check( 'Identifiants des messages d erreur uniques', count( $me[1] ) === count( array_unique( $me[1] ) ) );
check( 'Messages d erreur annonces sans interruption (aria-live)',
	count( array_filter( $me[2], static fn( $a ) => str_contains( $a, 'aria-live="polite"' ) ) ) === 18 );

preg_match_all( '/<input[^>]*class="uf-input"[^>]*>/', $page, $mi );
$relies = 0;
$avec_note = 0;
foreach ( $mi[0] as $input ) {
	preg_match( '/aria-describedby="([^"]+)"/', $input, $d );
	if ( empty( $d[1] ) ) { continue; }
	$refs = explode( ' ', $d[1] );
	if ( array_intersect( $refs, $me[1] ) ) { $relies++; }
	if ( array_filter( $refs, static fn( $r ) => str_ends_with( $r, '-note' ) ) ) { $avec_note++; }
}
check( 'Chaque champ visible est relie a son message d erreur', 18 === $relies );
check( 'La note d aide reste referencee a cote du message', 3 === $avec_note );

// --- Registre ---
check( 'Type inconnu refuse par le registre', null === FormRegistry::get( 'inexistant' ) );
check( 'Type par defaut disponible', 'localisation' === FormRegistry::default_type() );

echo "\n", 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
