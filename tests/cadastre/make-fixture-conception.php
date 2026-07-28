<?php
/**
 * Produit le HTML **réel** du formulaire de conception, pour le banc jsdom.
 *
 * Le banc JavaScript doit travailler sur ce que le serveur produit vraiment,
 * pas sur un gabarit écrit à la main : une divergence entre les deux ferait
 * passer des tests pour des preuves.
 */

require dirname( __DIR__ ) . '/submissions/bootstrap.php';

use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Conception\ConceptionSchema;
use Urbizen\Platform\Forms\FormRegistry;

wpd_reset();
$GLOBALS['wpd_logged_in'] = true;
$GLOBALS['wpd_can']       = true;

// Fixture OPÉRATIONNELLE : le banc JavaScript travaille sur le formulaire public
// réel (défenses réelles, formulaire soumissible). L'aperçu inerte (C3) est
// couvert séparément par le banc, en injectant le marqueur serveur sur cette base.
add_filter( 'urbizen_conception_public_enabled', static fn() => true );

$def   = FormRegistry::get( 'conception' );
$html  = ConceptionRenderer::render( $def );
$schema = ConceptionSchema::build( $def );

printf(
	"<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\"><title>Conception</title></head><body>\n%s\n<script id=\"schema\" type=\"application/json\">%s</script>\n</body></html>\n",
	$html,
	wp_json_encode( $schema )
);
