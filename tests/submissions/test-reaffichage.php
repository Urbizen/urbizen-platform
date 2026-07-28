<?php
/**
 * Banc d'essai du réaffichage serveur des valeurs et erreurs (Lot 2, C2B).
 *
 * Après un rejet de validation, le renderer réaffiche les valeurs NETTOYÉES et
 * les erreurs PUBLIQUES par champ, avec un résumé accessible — tout échappé,
 * jamais de consentement pré-coché, jamais de valeur de fichier. Sans reprise,
 * la sortie est strictement inchangée. L'aperçu ne consomme aucune reprise.
 *
 * Toutes les données sont fictives. Aucun courriel, aucun réseau, aucune base.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Conception\ConceptionAssets;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\StepFormRenderer;
use Urbizen\Platform\Forms\StepFormRenderConfig;
use Urbizen\Platform\Forms\StepFormRenderState;
use Urbizen\Platform\Forms\ValidationMessages;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionRecovery;
use Urbizen\Platform\Http\SubmissionRecoveryStore;
use Urbizen\Platform\Http\SubmissionFeedback;
use Urbizen\Platform\Http\SubmissionFeedbackToken;
use Urbizen\Platform\Http\SubmissionResultNotice;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Files\UploadPolicy;

$compteur = 0;

/** Harnais de mutation. */
function mutant( string $relatif, string $classe, array $remplacements ): string {
	global $compteur;
	$source  = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $relatif );
	$nouveau = $classe . 'Mutant' . ( ++$compteur );
	$source  = str_replace( "final class $classe", "final class $nouveau", $source );
	foreach ( $remplacements as $de => $vers ) {
		if ( ! str_contains( $source, $de ) ) {
			throw new RuntimeException( "motif introuvable dans $relatif : $de" );
		}
		$source = str_replace( $de, $vers, $source );
	}
	preg_match( '/^namespace\s+([^;]+);/m', $source, $ns );
	$fichier = sys_get_temp_dir() . '/urbizen-' . $nouveau . '.php';
	file_put_contents( $fichier, $source );
	require $fichier;
	unlink( $fichier );
	return '\\' . trim( $ns[1] ) . '\\' . $nouveau;
}

$def = FormRegistry::get( 'conception' );

/** Configuration technique historique (comme le rendu opérationnel). */
function cfg( string $racine = 'urbizen-conception' ): StepFormRenderConfig {
	return new StepFormRenderConfig(
		root: $racine,
		instance_id: 'urbizen-conception-1',
		form_action_url: 'https://exemple.test/wp-admin/admin-post.php',
		action: SubmissionController::ACTION,
		nonce_action: SubmissionController::NONCE_ACTION,
		nonce_field: SubmissionController::NONCE_FIELD,
		token_field: SubmissionController::TOKEN_FIELD,
		token: 'JETON',
		honeypot_field: SubmissionController::HONEYPOT_FIELD,
		return_field: SubmissionController::RETURN_FIELD,
		return_url: 'https://exemple.test/',
		file_accept: '.' . implode( ',.', array_keys( UploadPolicy::TYPES ) ),
	);
}

// ======================== A · RÉAFFICHAGE DES VALEURS =====================
$hostile = 'Camille <script>"x"&\'';
$state   = StepFormRenderState::reprise(
	array(
		'nom'           => $hostile,
		'email'         => 'camille@exemple.test',
		'a_terrain'     => 'non',
		'pieces_detail' => "Ligne 1\nLigne 2 <b>gras</b>",
		'rgpd'          => true,
	),
	array(
		'nom' => ValidationMessages::message( 'requis' ),
	),
	ValidationMessages::globale( 'champs' )
);

$html = StepFormRenderer::render( $def, cfg(), $state );

check( 'A · la valeur texte est réaffichée et ÉCHAPPÉE', str_contains( $html, 'value="Camille &lt;script&gt;&quot;x&quot;&amp;' ) && ! str_contains( $html, '<script>"x"' ) );
check( 'A · l’adresse est réaffichée', str_contains( $html, 'value="camille@exemple.test"' ) );
check( 'A · le textarea porte le contenu échappé', str_contains( $html, '&lt;b&gt;gras&lt;/b&gt;' ) && ! str_contains( $html, '<b>gras</b>' ) );
check( 'A · l’option radio « non » est cochée', 1 === preg_match( '/data-field="a_terrain".*?value="non" checked/s', $html ) );
check( 'A · le CONSENTEMENT n’est JAMAIS pré-coché', ! preg_match( '/data-field="rgpd".*?checked/s', $html ) );
check( 'A · aucun champ fichier ne porte de valeur', ! preg_match( '/type="file"[^>]*value=/', $html ) );

// ======================== B · ERREURS PAR CHAMP + RÉSUMÉ ==================
check( 'B · le message d’erreur est réaffiché sur le champ', 1 === preg_match( '/data-urbizen-field-error="nom">Ce champ est obligatoire\./', $html ) );
check( 'B · le contrôle porte aria-invalid', 1 === preg_match( '/data-field="nom".*?aria-invalid="true"/s', $html ) );
check( 'B · le résumé est présent et marqué', str_contains( $html, 'data-urbizen-error-summary="1"' ) && ! str_contains( $html, '__erreurs" id="urbizen-conception-1-erreurs" role="alert" aria-live="assertive" tabindex="-1" hidden' ) );
check( 'B · le résumé lie l’erreur au champ', str_contains( $html, 'href="#urbizen-conception-1-nom"' ) );
check( 'B · l’erreur globale figure au résumé', str_contains( $html, 'Certaines informations' ) );
check( 'B · aucun code technique brut affiché', ! str_contains( $html, '>requis<' ) );

// ======================== C · SANS REPRISE → INCHANGÉ =====================
$vide = StepFormRenderer::render( $def, cfg(), StepFormRenderState::vide() );
$sans = StepFormRenderer::render( $def, cfg() );
check( 'C · état vide == aucun argument (sortie identique)', $vide === $sans );
check( 'C · sans reprise : aucun aria-invalid', ! str_contains( $vide, 'aria-invalid' ) );
check( 'C · sans reprise : aucun value= injecté sur le champ nom', ! preg_match( '/id="urbizen-conception-1-nom"[^>]*value=/', $vide ) );
check( 'C · sans reprise : résumé masqué et vide', str_contains( $vide, '__erreurs-liste"></ul>' ) && str_contains( $vide, 'tabindex="-1" hidden>' ) );
check( 'C · sans reprise : conteneurs d’erreur masqués et vides', str_contains( $vide, '__erreur" id="urbizen-conception-1-nom-aide" hidden></p>' ) );

// ======================== D · PRÉSENTATEUR DE MESSAGES ====================
check( 'D · requis → message public', str_contains( ValidationMessages::message( 'requis' ), 'obligatoire' ) );
check( 'D · email_invalide → message public', str_contains( ValidationMessages::message( 'email_invalide' ), 'électronique' ) );
check( 'D · code INCONNU → message générique (jamais le code)', 'code_bidon_xyz' !== ValidationMessages::message( 'code_bidon_xyz' ) && str_contains( ValidationMessages::message( 'code_bidon_xyz' ), 'validée' ) );

// ======================== E · INTÉGRATION ConceptionRenderer ==============
/** Force le rendu opérationnel (public) et le compteur déterministe. */
function operationnel(): void {
	wpd_reset();
	add_filter( 'urbizen_conception_public_enabled', static fn() => true );
	ConceptionRenderer::reset();
	ConceptionAssets::register();
}

// E-1 · avec reprise : valeurs réaffichées + réponse non cacheable.
operationnel();
$rec = SubmissionRecovery::from_validation( $def->type(), $def, array( 'nom' => 'Camille Fictif' ), array( 'nom' => 'requis' ) );
$id  = SubmissionRecoveryStore::store( $rec );
$_GET = array( SubmissionResultNotice::CHAMP => SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'validation', $id ) ) );
$rendu = ConceptionRenderer::render( $def );
check( 'E-1 · la valeur reprise est réaffichée', str_contains( $rendu, 'value="Camille Fictif"' ) );
check( 'E-1 · l’erreur reprise est affichée', str_contains( $rendu, 'data-urbizen-field-error="nom"' ) );
check( 'E-1 · réponse marquée NON cacheable', true === ( $GLOBALS['wpd_nocache'] ?? false ) );

// E-2 · consommation UNIQUE : un second rendu dans la même requête n'a plus rien.
$rendu2 = ConceptionRenderer::render( $def );
check( 'E-2 · le second rendu (même requête) n’a plus la reprise', ! str_contains( $rendu2, 'value="Camille Fictif"' ) && ! str_contains( $rendu2, 'data-urbizen-field-error' ) );
$_GET = array();

// E-3 · sans reprise : réponse NON marquée, rendu inchangé.
operationnel();
$_GET = array();
$rendu_sans = ConceptionRenderer::render( $def );
check( 'E-3 · sans reprise : réponse non marquée nocache', false === ( $GLOBALS['wpd_nocache'] ?? false ) );
check( 'E-3 · sans reprise : aucun aria-invalid', ! str_contains( $rendu_sans, 'aria-invalid' ) );

// E-4 · APERÇU : aucune reprise consommée, aucun nocache, aucune valeur.
wpd_reset();
remove_all_filters( 'urbizen_conception_public_enabled' );
$GLOBALS['wpd_logged_in'] = true;
$GLOBALS['wpd_can']       = true;
$idA = SubmissionRecoveryStore::store( $rec );
$_GET = array( SubmissionResultNotice::CHAMP => SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'validation', $idA ) ) );
ConceptionRenderer::reset();
ConceptionAssets::register();
$apercu = ConceptionRenderer::render( $def );
check( 'E-4 · aperçu : aucune valeur reprise réaffichée', ! str_contains( $apercu, 'value="Camille Fictif"' ) && ! str_contains( $apercu, 'data-urbizen-field-error' ) );
check( 'E-4 · aperçu : aucune réponse marquée nocache', false === ( $GLOBALS['wpd_nocache'] ?? false ) );
check( 'E-4 · aperçu : la reprise N’EST PAS consommée (encore disponible)', null !== SubmissionRecoveryStore::consume( $idA ) );
$_GET = array();

// E-5 · CYCLE SANS JAVASCRIPT (C2C) : reprise de la surface GLOBALE et de la
// distribution libre — JAMAIS de ventilation par pièce — + nouveaux nonce/jeton.
// Un surfaces[clé] artificiel est ignoré, jamais réaffiché.
operationnel();
$vs   = Validator::validate( $def, array( 'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non', 'nom' => '', 'email' => 'x@y.test', 'rgpd' => '1', 'surface' => '120', 'pieces_detail' => 'Deux chambres au nord.', 'surfaces' => array( 'chambre_1' => '12' ) ) );
$recS = SubmissionRecovery::from_validation( 'conception', $def, $vs['clean'], $vs['errors'] );
$idS  = SubmissionRecoveryStore::store( $recS );
$_GET = array( SubmissionResultNotice::CHAMP => SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'validation', $idS ) ) );
$rS   = ConceptionRenderer::render( $def );
$_GET = array();
check( 'E-5 · no-JS : la surface GLOBALE est réaffichée', str_contains( $rS, 'value="120"' ) );
check( 'E-5 · no-JS : la distribution libre est réaffichée', str_contains( $rS, 'Deux chambres au nord.' ) );
check( 'E-5 · no-JS : AUCUNE ventilation par pièce (surfaces[…])', ! str_contains( $rS, 'name="surfaces[' ) && ! str_contains( $rS, 'name="surfaces"' ) );
check( 'E-5 · no-JS : l’erreur du champ (nom) est affichée', str_contains( $rS, 'data-urbizen-field-error="nom"' ) );
check( 'E-5 · no-JS : un NOUVEAU nonce est rendu', 1 === preg_match( '/name="urbizen_conception_nonce" value="[a-f0-9]{6,}"/', $rS ) );
check( 'E-5 · no-JS : un NOUVEAU jeton anti-robot est rendu', 1 === preg_match( '/name="urbizen_token" value="[0-9a-f]{32}\.\d+\.[0-9a-f]{64}"/', $rS ) );

// ======================== F · MUTANTS ====================================
// F1 · le consentement serait pré-coché.
$m1 = mutant(
	'src/Forms/StepFormRenderer.php',
	'StepFormRenderer',
	array( "case 'consent':\n\t\t\t\t// JAMAIS pré-coché : le consentement est re-confirmé à chaque envoi.\n\t\t\t\treturn sprintf( '<input type=\"checkbox\" value=\"1\"%s>', \$commun );" => "case 'consent':\n\t\t\t\treturn sprintf( '<input type=\"checkbox\" value=\"1\"%s%s>', \$commun, ( is_scalar( \$reprise ) ? ' checked' : '' ) );" )
);
$hm1 = $m1::render( $def, cfg(), $state );
check( 'F1 · garde retirée → le consentement est pré-coché', preg_match( '/data-field="rgpd".*?checked/s', $hm1 ) );
check( 'F1 · le vrai renderer ne coche jamais le consentement', ! preg_match( '/data-field="rgpd".*?checked/s', StepFormRenderer::render( $def, cfg(), $state ) ) );

// F2 · valeur rendue SANS échappement.
$m2 = mutant(
	'src/Forms/StepFormRenderer.php',
	'StepFormRenderer',
	array( "return '' === \$scalaire ? '' : sprintf( ' value=\"%s\"', esc_attr( \$scalaire ) );" => "return '' === \$scalaire ? '' : sprintf( ' value=\"%s\"', \$scalaire );" )
);
$hm2 = $m2::render( $def, cfg(), $state );
check( 'F2 · échappement retiré → la balise brute apparaît', str_contains( $hm2, '<script>"x"' ) );
check( 'F2 · le vrai renderer échappe', ! str_contains( StepFormRenderer::render( $def, cfg(), $state ), '<script>"x"' ) );

// F3 · code inconnu affiché brut.
$m3 = mutant(
	'src/Forms/ValidationMessages.php',
	'ValidationMessages',
	array( "return __( 'Cette information n’a pas pu être validée.', 'urbizen-platform' );" => 'return $code;' )
);
check( 'F3 · défaut muté → le code brut est renvoyé', 'code_bidon_xyz' === $m3::message( 'code_bidon_xyz' ) );
check( 'F3 · le vrai présentateur masque le code', 'code_bidon_xyz' !== ValidationMessages::message( 'code_bidon_xyz' ) );

// ======================== G · DESCOPE (C2C) : plus de famille par pièce ====
// Une reprise contenant une structure « surfaces » (héritée) ne produit AUCUN
// contrôle par pièce : le champ n'existe plus, la structure est ignorée au rendu.
$stateF = StepFormRenderState::reprise(
	array( 'surfaces' => array( 'sejour' => '25', 'chambre_1' => '12' ), 'surface' => '95' ),
	array(),
	''
);
$hF = StepFormRenderer::render( $def, cfg(), $stateF );
check( 'G · aucun contrôle par pièce (surfaces[…]) rendu', ! str_contains( $hF, 'name="surfaces[' ) );
check( 'G · aucun contrôle « surfaces » (pluriel) rendu', ! str_contains( $hF, 'name="surfaces"' ) );
check( 'G · la surface GLOBALE reste réaffichée', str_contains( $hF, 'value="95"' ) );

verdict();
