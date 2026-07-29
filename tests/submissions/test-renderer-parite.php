<?php
/**
 * Banc d'essai de l'EXTRACTION du renderer générique (Lot 1, incrément 3).
 *
 * Prouve quatre choses :
 *
 *  - PARITÉ : après extraction, la façade ConceptionRenderer rend, octet pour
 *    octet, le formulaire capturé AVANT extraction (référence figée dans
 *    fixtures/conception-render.expected.html) — seuls le nonce, le jeton, le
 *    retour et le referer, variables par nature, sont normalisés.
 *  - UNE SEULE IMPLÉMENTATION : la mécanique de rendu (progression, champs
 *    techniques, navigation…) ne vit plus que dans StepFormRenderer ; la façade
 *    délègue et ne porte plus que la configuration et les fragments Conception.
 *  - GÉNÉRICITÉ : StepFormRenderer rend une définition fictive à deux étapes,
 *    avec sa propre racine, son action et son nonce, sans produire la moindre
 *    chaîne Conception.
 *  - SÉCURITÉ : action et nonce viennent de la configuration serveur ; aucune
 *    valeur de $_POST/$_GET ne change le rendu technique.
 *
 * Toutes les données sont fictives. Décision : docs/DECISIONS.md D-050 (C).
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Conception\ConceptionAssets;
use Urbizen\Platform\Files\UploadPolicy;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\StepFormRenderConfig;
use Urbizen\Platform\Forms\StepFormRenderer;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Security\AntiSpam;

/**
 * Neutralise UNIQUEMENT les valeurs variables (nonce, jeton, retour, referer).
 *
 * @param string $html HTML rendu.
 * @return string
 */
function normaliser( string $html ): string {
	return preg_replace(
		array(
			'/urbizen_conception_nonce" value="[^"]*"/',
			'/urbizen_token" value="[^"]*"/',
			'/urbizen_return" value="[^"]*"/',
			'/_wp_http_referer" value="[^"]*"/',
		),
		array(
			'urbizen_conception_nonce" value="NONCE"',
			'urbizen_token" value="TOKEN"',
			'urbizen_return" value="RETURN"',
			'_wp_http_referer" value="REFERER"',
		),
		$html
	);
}

/**
 * Rend le formulaire Conception en mode **opérationnel** (formulaire public),
 * de façon déterministe (compteur d'instances remis à zéro).
 *
 * La fixture de parité ancre désormais le rendu OPÉRATIONNEL : défenses réelles
 * (action, nonce, jeton), sans le bandeau d'aperçu (propre au mode preview,
 * couvert séparément). Le mode est forcé côté SERVEUR par le filtre de
 * disponibilité, jamais par une valeur du navigateur.
 *
 * @return string
 */
function rendu_conception(): string {
	add_filter( 'urbizen_conception_public_enabled', static fn() => true );
	ConceptionRenderer::reset();
	ConceptionAssets::register();

	return ConceptionRenderer::render( FormRegistry::get( 'conception' ) );
}

/**
 * Rend le formulaire Conception en mode **aperçu** administrateur (non public).
 *
 * @return string
 */
function rendu_apercu(): string {
	remove_all_filters( 'urbizen_conception_public_enabled' );
	$GLOBALS['wpd_logged_in'] = true;
	$GLOBALS['wpd_can']       = true;
	ConceptionRenderer::reset();
	ConceptionAssets::register();

	return ConceptionRenderer::render( FormRegistry::get( 'conception' ) );
}

// ======================================================================
// A · FAÇADE : l'API publique survit, le rendu délègue
// ======================================================================
check( 'A · ConceptionRenderer existe toujours', class_exists( ConceptionRenderer::class ) );
check( 'A · render() reste appelable et non vide', '' !== rendu_conception() );
check( 'A · reset() reste exposée', method_exists( ConceptionRenderer::class, 'reset' ) );

$src_facade = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Conception/ConceptionRenderer.php' );
check( 'A · la façade délègue à StepFormRenderer::render', str_contains( $src_facade, 'StepFormRenderer::render' ) );

// Une seule implémentation : la mécanique générique n'est PLUS dans la façade.
$marqueurs_generiques = array( '__progression-item', '__navigation', 'function champs_techniques', 'function controle', 'function choix', '<noscript>' );
$fuites_facade        = array_filter( $marqueurs_generiques, static fn( $m ) => str_contains( $src_facade, $m ) );
check( 'A · aucune seconde implémentation du rendu dans la façade', array() === $fuites_facade );

// ======================================================================
// B · PARITÉ : rendu APRÈS extraction == référence figée AVANT extraction
// ======================================================================
$reference = (string) file_get_contents( __DIR__ . '/fixtures/conception-render.expected.html' );
$apres     = normaliser( rendu_conception() );
check( 'B · le rendu Conception est inchangé, octet pour octet', $apres === $reference );
check( 'B · les six étapes sont présentes', 6 === substr_count( $apres, '__etape"' ) );
check( 'B · les 44 champs sont présents', 44 === substr_count( $apres, 'data-field="' ) );
check( 'B · les 16 conditions visible_if sont présentes', 16 === substr_count( $apres, 'data-visible-if="' ) );

// ======================================================================
// C · RENDERER DIRECT : la définition Conception + configuration historique
// ======================================================================
// Configuration bâtie à partir des SOURCES PUBLIQUES canoniques (constantes du
// contrôleur, politique d'upload) — jamais d'une valeur parallèle.
$cfg_historique = new StepFormRenderConfig(
	root: ConceptionRenderer::RACINE,
	instance_id: 'urbizen-conception-direct',
	form_action_url: admin_url( 'admin-post.php' ),
	action: SubmissionController::ACTION,
	nonce_action: SubmissionController::NONCE_ACTION,
	nonce_field: SubmissionController::NONCE_FIELD,
	token_field: SubmissionController::TOKEN_FIELD,
	token: AntiSpam::issue_token(),
	honeypot_field: SubmissionController::HONEYPOT_FIELD,
	return_field: SubmissionController::RETURN_FIELD,
	return_url: 'https://exemple.test/',
	file_accept: '.' . implode( ',.', array_keys( UploadPolicy::TYPES ) ),
);
$direct = StepFormRenderer::render( FormRegistry::get( 'conception' ), $cfg_historique );

// Le cœur du formulaire est produit par le renderer générique lui-même.
check( 'C · rendu direct : six étapes', 6 === substr_count( $direct, '__etape"' ) );
check( 'C · rendu direct : 44 champs', 44 === substr_count( $direct, 'data-field="' ) );
check( 'C · rendu direct : action historique urbizen_conception',
	str_contains( $direct, 'name="action" value="' . SubmissionController::ACTION . '"' ) );
check( 'C · rendu direct : champ nonce, jeton, pot de miel, retour',
	str_contains( $direct, SubmissionController::NONCE_FIELD )
		&& str_contains( $direct, SubmissionController::TOKEN_FIELD )
		&& str_contains( $direct, SubmissionController::HONEYPOT_FIELD )
		&& str_contains( $direct, SubmissionController::RETURN_FIELD ) );
check( 'C · rendu direct : enctype multipart et navigation et noscript',
	str_contains( $direct, 'enctype="multipart/form-data"' )
		&& str_contains( $direct, '__navigation' )
		&& str_contains( $direct, '<noscript>' ) );

// Les fragments propres à Conception ne sont PAS dans le renderer générique :
// avec une configuration sans fragments, ils n'apparaissent pas. Ils sont donc
// bien injectés par la seule façade.
check( 'C · sans fragments : pas de cartouche Conception', ! str_contains( $direct, 'Plans et pièces graphiques' ) );
check( 'C · sans fragments : pas de consignes de dépôt', ! str_contains( $direct, 'Formats acceptés' ) );
check( 'C · sans fragments : pas de consentement de brouillon', ! str_contains( $direct, 'consentement-brouillon' ) );

// ======================================================================
// D · GÉNÉRICITÉ : une définition fictive, une racine et une action autres
// ======================================================================
$def_fictive = new FormDefinition(
	'devis_fictif',
	'Devis express',
	'Envoyer la demande',
	array(
		array( 'name' => 'nom_projet', 'type' => 'text', 'label' => 'Nom du projet', 'step' => 'coordonnees', 'required' => true ),
		array( 'name' => 'budget', 'type' => 'number', 'label' => 'Budget indicatif', 'step' => 'coordonnees', 'min' => 0 ),
		array(
			'name'    => 'gabarit',
			'type'    => 'radio',
			'label'   => 'Gabarit',
			'step'    => 'details',
			'options' => array(
				array( 'value' => 'petit', 'label' => 'Petit' ),
				array( 'value' => 'grand', 'label' => 'Grand' ),
			),
		),
		array( 'name' => 'remarques', 'type' => 'textarea', 'label' => 'Remarques', 'step' => 'details' ),
	),
	array(
		array( 'id' => 'coordonnees', 'label' => 'Coordonnées', 'title' => 'Vos coordonnées' ),
		array( 'id' => 'details', 'label' => 'Détails', 'title' => 'Le projet' ),
	)
);
check( 'D · la définition fictive est valide', array() === $def_fictive->errors() );

$cfg_fictive = new StepFormRenderConfig(
	root: 'devis-express',
	instance_id: 'devis-express-1',
	form_action_url: 'https://exemple.test/wp-admin/admin-post.php',
	action: 'urbizen_devis_fictif',
	nonce_action: 'urbizen_devis_fictif_submit',
	nonce_field: 'urbizen_devis_fictif_nonce',
	token_field: 'urbizen_devis_token',
	token: 'JETON_FICTIF',
	honeypot_field: 'devis_site_web',
	return_field: 'urbizen_devis_return',
	return_url: 'https://exemple.test/devis/',
	file_accept: '.pdf',
);
$fictif = StepFormRenderer::render( $def_fictive, $cfg_fictive );

check( 'D · racine fictive appliquée', str_contains( $fictif, 'class="devis-express"' ) );
check( 'D · deux étapes fictives', 2 === substr_count( $fictif, '__etape"' ) );
check( 'D · les quatre champs fictifs', 4 === substr_count( $fictif, 'data-field="' ) );
check( 'D · action fictive dans le champ caché', str_contains( $fictif, 'name="action" value="urbizen_devis_fictif"' ) );
check( 'D · jeton fictif rendu', str_contains( $fictif, 'name="urbizen_devis_token" value="JETON_FICTIF"' ) );
check( 'D · types conservés (radio + textarea)', str_contains( $fictif, 'type="radio"' ) && str_contains( $fictif, '<textarea' ) );

$interdits = array( 'conception', 'urbizen-conception', 'urbizen_conception', 'Plans et pièces graphiques', 'consentement-brouillon', 'Formats acceptés' );
$fuites    = array_filter( $interdits, static fn( $s ) => str_contains( $fictif, $s ) );
check( 'D · aucune chaîne Conception dans le rendu générique', array() === $fuites );

// ======================================================================
// E · SÉCURITÉ : le rendu ne lit aucune superglobale
// ======================================================================
$rendu_propre  = normaliser( rendu_conception() );
$_POST         = array( 'action' => 'urbizen_pirate', 'form_type' => 'dp', 'urbizen_token' => 'injecte', SubmissionController::NONCE_FIELD => 'forge' );
$_GET          = array( 'action' => 'urbizen_pirate' );
$rendu_pollue  = normaliser( rendu_conception() );
$_POST         = array();
$_GET          = array();
check( 'E · une pollution de $_POST/$_GET ne change pas le rendu', $rendu_propre === $rendu_pollue );
check( 'E · l’action rendue reste celle du serveur, pas celle de $_POST',
	str_contains( $rendu_pollue, 'name="action" value="' . SubmissionController::ACTION . '"' )
		&& ! str_contains( $rendu_pollue, 'urbizen_pirate' ) );

// StepFormRenderer ne dépend d'aucune chaîne ni namespace Conception (scan statique).
$src_generique = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Forms/StepFormRenderer.php' );
$interdits_src = array( 'Urbizen\\Platform\\Conception', 'ConceptionRenderer', 'urbizen_conception', 'urbizen-conception', 'UploadPolicy', 'SubmissionController', "=== 'conception'", 'Plans et pièces' );
$fuites_src    = array_filter( $interdits_src, static fn( $s ) => str_contains( $src_generique, $s ) );
check( 'E · StepFormRenderer sans dépendance ni chaîne Conception', array() === $fuites_src );

// ======================================================================
// F · APERÇU INERTE (Lot 2, C3) : représentatif mais NON soumissible
// ======================================================================
// Le mode est choisi PAR LE SERVEUR ; une pollution de superglobale « preview »
// ne l'active ni ne le désactive.
wpd_reset();
$_GET  = array( 'preview' => '1' );
$_POST = array( 'preview' => '1' );
$GLOBALS['wpd_create_nonce_calls'] = 0;
$apercu        = rendu_apercu();
$nonces_apercu = (int) $GLOBALS['wpd_create_nonce_calls'];
$_GET  = array();
$_POST = array();

check( 'F · l’aperçu porte le marqueur serveur « preview »', str_contains( $apercu, 'data-urbizen-render-mode="preview"' ) );
check( 'F · notice d’aperçu accessible et non soumissible', str_contains( $apercu, '__apercu' ) && str_contains( $apercu, 'ne peut pas être envoyé' ) );
check( 'F · structure visuelle conservée : six étapes', 6 === substr_count( $apercu, '__etape"' ) );
check( 'F · structure visuelle conservée : 44 champs', 44 === substr_count( $apercu, 'data-field="' ) );
check( 'F · AUCUN champ nonce en aperçu', ! str_contains( $apercu, SubmissionController::NONCE_FIELD ) );
check( 'F · AUCUN champ jeton anti-robot en aperçu', ! str_contains( $apercu, 'name="' . SubmissionController::TOKEN_FIELD . '"' ) );
check( 'F · AUCUNE action opérationnelle en aperçu', ! str_contains( $apercu, 'value="' . SubmissionController::ACTION . '"' ) );
check( 'F · le bouton d’envoi est désactivé', str_contains( $apercu, '__bouton--envoyer" data-action="envoyer" disabled aria-disabled="true"' ) );
check( 'F · COMPTAGE DIRECT : zéro nonce généré en aperçu', 0 === $nonces_apercu );
// AUCUN effet de bord du simple rendu d'aperçu : ni demande, ni courriel, ni
// réservation (aucun jeton n'est même émis).
check( 'F · aperçu : aucune demande créée', array() === ( $GLOBALS['wpd_posts'] ?? array() ) );
check( 'F · aperçu : aucun courriel', array() === ( $GLOBALS['wpd_mails'] ?? array() ) );

// Même un jeton C1 valide dans l'URL n'affiche AUCUN feedback en aperçu.
wpd_reset();
$_GET = array(
	\Urbizen\Platform\Http\SubmissionResultNotice::CHAMP => \Urbizen\Platform\Http\SubmissionFeedbackToken::issue(
		\Urbizen\Platform\Http\SubmissionFeedback::succes( 'conception', 'URB-2026-0001' )
	),
);
$apercu_fb = rendu_apercu();
$_GET      = array();
check( 'F · l’aperçu n’affiche aucun feedback C1', ! str_contains( $apercu_fb, 'data-urbizen-feedback-status' ) && ! str_contains( $apercu_fb, 'URB-2026-0001' ) );

// CONTRE-ÉPREUVE : le rendu OPÉRATIONNEL génère bien nonce et jeton réels.
wpd_reset();
$GLOBALS['wpd_create_nonce_calls'] = 0;
$op        = rendu_conception();
$nonces_op = (int) $GLOBALS['wpd_create_nonce_calls'];

check( 'F · COMPTAGE DIRECT : le rendu opérationnel génère un nonce', 1 === $nonces_op );
check( 'F · le rendu opérationnel porte un jeton anti-robot signé', 1 === preg_match( '/name="' . SubmissionController::TOKEN_FIELD . '" value="[0-9a-f]{32}\.[0-9]+\.[0-9a-f]{64}"/', $op ) );
check( 'F · le rendu opérationnel n’a PAS le marqueur preview', ! str_contains( $op, 'data-urbizen-render-mode' ) );
check( 'F · le rendu opérationnel n’a PAS la notice d’aperçu', ! str_contains( $op, '__apercu' ) );

// ======================================================================
// G · IDENTIFIANT D'INSTANCE (H1) : produit serveur, déterministe, borné
// ======================================================================
$idInstance = static function ( string $html ): string {
	return 1 === preg_match( '/data-urbizen-form-instance="([^"]+)"/', $html, $m ) ? $m[1] : '';
};

add_filter( 'urbizen_conception_public_enabled', static fn() => true );

// Une même « requête » : deux instances rendues à la suite → 1 puis 2, ordonnées.
ConceptionRenderer::reset();
$g1 = ConceptionRenderer::render( FormRegistry::get( 'conception' ) );
$g2 = ConceptionRenderer::render( FormRegistry::get( 'conception' ) );
check( 'G · première instance : identifiant borné urbizen-conception-1', 'urbizen-conception-1' === $idInstance( $g1 ) );
check( 'G · seconde instance : urbizen-conception-2 (ordre déterministe)', 'urbizen-conception-2' === $idInstance( $g2 ) );
check( 'G · le format est borné (aucune valeur libre)', 1 === preg_match( '/^urbizen-conception-\d+$/', $idInstance( $g1 ) ) );

// Une nouvelle « requête » (reset) sur une page de MÊME composition : la première
// instance retrouve EXACTEMENT le même identifiant → stable soumission ↔ réponse.
ConceptionRenderer::reset();
$g3 = ConceptionRenderer::render( FormRegistry::get( 'conception' ) );
check( 'G · reset : la 1re instance retrouve le même identifiant (stable)', $idInstance( $g1 ) === $idInstance( $g3 ) );

// L'aperçu inerte NE porte PAS l'attribut (non soumissible → pas de corrélation).
$apercuG = rendu_apercu();
check( 'G · aperçu inerte : aucun identifiant d’instance (non soumissible)', '' === $idInstance( $apercuG ) );

verdict();
