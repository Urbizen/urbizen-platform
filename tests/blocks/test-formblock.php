<?php
/**
 * Banc d'essai du RACCORDEMENT de FormBlock aux renderers serveur (Lot 1, incr. 4).
 *
 * FormBlock est le point d'entrée sûr : il demande un type, la liste blanche le
 * valide, puis un résolveur serveur (FormRendererResolver) associe le type à son
 * renderer autorisé. Le navigateur ne choisit jamais une classe, un chemin, un
 * callable ni un namespace. Localisation garde son renderer plat ; Conception
 * passe par sa façade, qui délègue à StepFormRenderer.
 *
 * Réutilise le harnais de tests/submissions (pile Conception complète, doubles
 * WordPress, observation des ressources enfilées). Ne complète que les quelques
 * doubles propres au bloc/shortcode.
 *
 * Toutes les données sont fictives. Décision : docs/DECISIONS.md D-050.
 */

require __DIR__ . '/../submissions/bootstrap.php';

// Doubles propres au bloc, absents du harnais submissions.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		$s = strip_tags( (string) $s );
		$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
		return trim( preg_replace( '/[\x00-\x1f\x7f]/u', '', $s ) );
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( $pairs, $atts, $sc = '' ) {
		$out = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $t, $c ) {
		$GLOBALS['shortcodes'][ $t ] = $c;
	}
}
if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( $n, $args = array() ) {}
}

require URBIZEN_PLATFORM_DIR . 'src/Blocks/FormRendererResolver.php';
require URBIZEN_PLATFORM_DIR . 'src/Blocks/FormBlock.php';

use Urbizen\Platform\Blocks\FormBlock;
use Urbizen\Platform\Blocks\FormRendererResolver;
use Urbizen\Platform\Conception\ConceptionAssets;
use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Renderer;

/**
 * Neutralise les valeurs variables du rendu Conception.
 */
function normaliser_bloc( string $html ): string {
	return preg_replace(
		array( '/urbizen_conception_nonce" value="[^"]*"/', '/urbizen_token" value="[^"]*"/', '/urbizen_return" value="[^"]*"/', '/_wp_http_referer" value="[^"]*"/' ),
		array( 'urbizen_conception_nonce" value="NONCE"', 'urbizen_token" value="TOKEN"', 'urbizen_return" value="RETURN"', '_wp_http_referer" value="REFERER"' ),
		$html
	);
}

/** Remet à zéro l'observation des ressources enfilées. */
function reset_assets(): void {
	$GLOBALS['wpd_styles']  = array();
	$GLOBALS['wpd_scripts'] = array();
}

/** Contexte administrateur (aperçu Conception) + compteurs déterministes. */
function contexte_admin(): void {
	$GLOBALS['wpd_logged_in'] = true;
	$GLOBALS['wpd_can']       = true;
	ConceptionRenderer::reset();
	Renderer::reset_instances();
	ConceptionAssets::register();
}

$CONCEPTION_HANDLE = ConceptionAssets::HANDLE_CSS; // 'urbizen-conception'

// ======================================================================
// A · LOCALISATION : renderer plat, aucune ressource Conception
// ======================================================================
contexte_admin();
reset_assets();
$loc = FormBlock::render_block( array( 'formType' => 'localisation' ) );
check( 'A · Localisation résolue par la liste blanche', null !== FormRegistry::get( 'localisation' ) );
check( 'A · Localisation rendue par le renderer plat', str_contains( $loc, 'data-form-type="localisation"' ) && str_contains( $loc, 'data-urbizen-form="1"' ) );
check( 'A · aucune balise/étape Conception dans Localisation', ! str_contains( $loc, 'urbizen-conception' ) && ! str_contains( $loc, 'Plans et pièces graphiques' ) );
check( 'A · ressources de bloc urbizen-form enfilées', in_array( 'urbizen-form', $GLOBALS['wpd_styles'], true ) && in_array( 'urbizen-form', $GLOBALS['wpd_scripts'], true ) );
check( 'A · AUCUNE ressource Conception pour Localisation', ! in_array( $CONCEPTION_HANDLE, $GLOBALS['wpd_styles'], true ) && ! in_array( $CONCEPTION_HANDLE, $GLOBALS['wpd_scripts'], true ) );

// ======================================================================
// B · CONCEPTION : façade → StepFormRenderer, parité fixture, assets propres
// ======================================================================
contexte_admin();
reset_assets();
$conc = FormBlock::render_block( array( 'formType' => 'conception' ) );
check( 'B · Conception résolue par la liste blanche', null !== FormRegistry::get( 'conception' ) );
check( 'B · Conception rendue par sa façade (cartouche présent)', str_contains( $conc, 'Plans et pièces graphiques' ) && str_contains( $conc, 'class="urbizen-conception"' ) );
check( 'B · la façade a délégué à StepFormRenderer (structure générique)', str_contains( $conc, 'urbizen-conception__navigation' ) && 45 === substr_count( $conc, 'data-field="' ) );
$reference = (string) file_get_contents( __DIR__ . '/../submissions/fixtures/conception-render.expected.html' );
check( 'B · sortie FormBlock Conception == fixture de référence (normalisée)', normaliser_bloc( $conc ) === $reference );
check( 'B · ressources Conception enfilées', in_array( $CONCEPTION_HANDLE, $GLOBALS['wpd_styles'], true ) && in_array( $CONCEPTION_HANDLE, $GLOBALS['wpd_scripts'], true ) );
check( 'B · les ressources plates urbizen-form NE sont PAS enfilées pour Conception', ! in_array( 'urbizen-form', $GLOBALS['wpd_styles'], true ) && ! in_array( 'urbizen-form', $GLOBALS['wpd_scripts'], true ) );

// ======================================================================
// C · TYPE ABSENT : repli historique sur Localisation
// ======================================================================
contexte_admin();
$absent = FormBlock::render_block( array() );
check( 'C · type absent → formulaire par défaut (Localisation)', str_contains( $absent, 'data-form-type="localisation"' ) );
check( 'C · default_type reste Localisation', 'localisation' === FormRegistry::default_type() );

// ======================================================================
// D · TYPE INCONNU/INVALIDE : repli sûr, jamais de renderer arbitraire
// ======================================================================
$hostiles = array(
	'dp', 'pc', 'cerfa', 'pcmi', 'permis-general',
	'../conception', 'conception.php', 'FormRegistry',
	'http://x/y', 'concéption', str_repeat( 'a', 200 ),
);
$echecs_d = array();
foreach ( $hostiles as $mauvais ) {
	contexte_admin();
	reset_assets();
	$h = FormBlock::render_block( array( 'formType' => $mauvais ) );
	$ok = str_contains( $h, 'data-form-type="localisation"' )      // repli sûr
		&& ! str_contains( $h, 'urbizen-conception' )               // aucun renderer Conception
		&& ! str_contains( $h, '<script' )                          // aucune injection
		&& ! in_array( $CONCEPTION_HANDLE, $GLOBALS['wpd_styles'], true ); // aucun asset métier indu
	if ( ! $ok ) {
		$echecs_d[] = $mauvais;
	}
}
check( 'D · tout type inconnu/invalide → repli Localisation, sans renderer ni asset métier', array() === $echecs_d );

// ======================================================================
// E · RENDERER MANQUANT : échec sûr et explicite (fixture de test)
// ======================================================================
// Un type enregistré dans la liste blanche mais SANS renderer autorisé : le
// résolveur échoue proprement (null), sans jamais déduire une classe.
if ( ! FormRegistry::has( 'devis_test' ) ) {
	FormRegistry::register( 'devis_test' );
}
$def_bidon = new FormDefinition( 'devis_test', 'Devis', 'Envoyer', array(), array() );
check( 'E · aucun renderer autorisé pour un type sans entrée', false === FormRendererResolver::has( 'devis_test' ) );
check( 'E · render() sur type sans renderer → null (échec sûr)', null === FormRendererResolver::render( 'devis_test', $def_bidon ) );
check( 'E · needs_block_assets() d’un type sans renderer → false', false === FormRendererResolver::needs_block_assets( 'devis_test' ) );

// ======================================================================
// F · SHORTCODE : même liste blanche, même résolveur
// ======================================================================
contexte_admin();
$sc_absent = FormBlock::render_shortcode( array() );
check( 'F · shortcode sans type → Localisation', str_contains( $sc_absent, 'data-form-type="localisation"' ) );

contexte_admin();
$sc_loc = FormBlock::render_shortcode( array( 'formtype' => 'localisation' ) );
check( 'F · shortcode Localisation → renderer plat', str_contains( $sc_loc, 'data-form-type="localisation"' ) && ! str_contains( $sc_loc, 'urbizen-conception' ) );

contexte_admin();
$sc_conc = FormBlock::render_shortcode( array( 'formtype' => 'conception' ) );
check( 'F · shortcode Conception → façade Conception', str_contains( $sc_conc, 'class="urbizen-conception"' ) && str_contains( $sc_conc, 'Plans et pièces graphiques' ) );

$echecs_f = array();
foreach ( array( 'dp', '../x', 'FormRegistry', 'http://x' ) as $mauvais ) {
	contexte_admin();
	$h = FormBlock::render_shortcode( array( 'formtype' => $mauvais ) );
	if ( ! str_contains( $h, 'data-form-type="localisation"' ) || str_contains( $h, 'urbizen-conception' ) ) {
		$echecs_f[] = $mauvais;
	}
}
check( 'F · shortcode type inconnu/traversal/classe/URL → repli Localisation', array() === $echecs_f );

// ======================================================================
// G · SUPERGLOBALES : elles ne choisissent jamais le renderer
// ======================================================================
contexte_admin();
$propre = FormBlock::render_block( array( 'formType' => 'localisation' ) );
$_POST  = array( 'formType' => 'conception', 'renderer' => 'ConceptionRenderer', 'class' => 'Urbizen\\Platform\\Conception\\ConceptionRenderer', 'path' => '../conception', 'action' => 'urbizen_conception' );
$_GET   = array( 'formType' => 'conception', 'renderer' => 'ConceptionRenderer' );
contexte_admin();
$pollue = FormBlock::render_block( array( 'formType' => 'localisation' ) );
$_POST  = array();
$_GET   = array();
check( 'G · $_POST/$_GET ne détournent pas la résolution du renderer', $propre === $pollue );
check( 'G · le rendu reste Localisation malgré la pollution', str_contains( $pollue, 'data-form-type="localisation"' ) && ! str_contains( $pollue, 'urbizen-conception' ) );

// Le résolveur n'expose aucun chargement dynamique (scan statique du CODE seul :
// les commentaires, qui nomment volontairement ces motifs pour dire qu'ils sont
// proscrits, sont retirés avant l'analyse).
$src_resolveur = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Blocks/FormRendererResolver.php' );
$code_seul     = '';
foreach ( token_get_all( $src_resolveur ) as $tok ) {
	if ( is_array( $tok ) ) {
		if ( in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code_seul .= $tok[1];
	} else {
		$code_seul .= $tok;
	}
}
$dangers = array( 'new $', 'class_exists(', 'call_user_func', 'require $', 'include $', '$_POST', '$_GET', '$_REQUEST' );
$fuites  = array_filter( $dangers, static fn( $d ) => str_contains( $code_seul, $d ) );
check( 'G · le résolveur ne contient aucun chargement dynamique client (code seul)', array() === $fuites );

verdict();
