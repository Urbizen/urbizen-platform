<?php
/**
 * Banc d'essai du ROUTAGE SERVEUR des soumissions (Lot 1, incrément 2).
 *
 * Garantit qu'aucune valeur du navigateur ne choisit le pipeline : le type est
 * résolu côté serveur depuis la table action→type. Un « form_type » ou une
 * « action » présents dans le POST ne peuvent que CONFIRMER la résolution ;
 * s'ils la contredisent, la requête est refusée AVANT tout effet de bord
 * (aucune demande, aucune référence, aucun courriel, aucun fichier). Décision :
 * docs/DECISIONS.md D-050 (B).
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Submissions\SubmissionPostType;

/**
 * Repart d'un état propre, type de demande enregistré.
 */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

/**
 * Traite une soumission sur une route serveur EXPLICITE (comme le ferait un
 * hook), en passant la route au contrôleur — jamais via le POST.
 *
 * @param array<string, mixed>                                    $post  Données postées.
 * @param array{action: string, form_type: string, nonce_action: string} $route Route serveur.
 * @return SubmissionResult
 */
function traiter_route( array $post, array $route ): SubmissionResult {
	return SubmissionController::process( $post, array(), serveur(), wpd_now(), $route );
}

// ================= 1 · RÉSOLUTION SERVEUR SUR LE CHEMIN NOMINAL =============
neuf();
$r = traiter( soumission() );
check( '1 · une soumission normale réussit', $r->is_success() );
check( '1 · le type persisté est résolu côté serveur (« conception »)',
	'conception' === get_post_meta( $r->id(), '_urbizen_form_type', true ) );

// ================= 2 · UN « form_type » FALSIFIÉ NE DÉTOURNE RIEN ==========
// Il ne choisit pas le pipeline : contredisant la résolution serveur, il est
// rejeté — et rien n'est enregistré.
$falsifies = array(
	'form_type=dp'         => array( 'form_type' => 'dp' ),
	'form_type=pc'         => array( 'form_type' => 'pc' ),
	'form_type=pcmi'       => array( 'form_type' => 'pcmi' ),
	'form_type=localisation' => array( 'form_type' => 'localisation' ),
	'form_type vide'       => array( 'form_type' => '' ),
	'action=urbizen_file'  => array( 'action' => 'urbizen_file' ),
	'action vide'          => array( 'action' => '' ),
);

foreach ( $falsifies as $libelle => $extra ) {
	neuf();
	$r = traiter( array_merge( soumission(), $extra ) );

	check( sprintf( '2 · %-24s → invalid_form', $libelle ),
		! $r->is_success() && SubmissionResult::INVALID_FORM === $r->code() );
	check( sprintf( '2 · %-24s → aucune demande', $libelle ), array() === $GLOBALS['wpd_posts'] );
	check( sprintf( '2 · %-24s → aucune référence', $libelle ), '' === $r->reference() );
	check( sprintf( '2 · %-24s → aucun courriel', $libelle ), array() === $GLOBALS['wpd_mails'] );
}

// ================= 3 · UNE VALEUR COHÉRENTE EST INOFFENSIVE ================
// Un « form_type »/« action » ÉGAL à la résolution serveur ne fait que
// confirmer : la soumission aboutit normalement (la valeur est ensuite retirée
// avant validation, elle n'ajoute aucun champ).
neuf();
$r = traiter( array_merge( soumission(), array( 'form_type' => 'conception' ) ) );
check( '3 · form_type=conception (cohérent) → succès', $r->is_success() );
check( '3 · un « form_type » cohérent n’altère pas le type persisté',
	'conception' === get_post_meta( $r->id(), '_urbizen_form_type', true ) );

neuf();
$r = traiter( array_merge( soumission(), array( 'action' => 'urbizen_conception' ) ) );
check( '3 · action=urbizen_conception (cohérente) → succès', $r->is_success() );

// ================= 4 · LE NONCE EST LIÉ À L’ACTION DU FORMULAIRE ===========
// Un nonce forgé pour une autre action ne vaut pas pour ce formulaire.
neuf();
$r = traiter( array_merge( soumission(), array( \Urbizen\Platform\Http\SubmissionController::NONCE_FIELD => wp_create_nonce( 'urbizen_autre_form' ) ) ) );
check( '4 · nonce d’un autre formulaire → invalid_nonce', ! $r->is_success() && SubmissionResult::INVALID_NONCE === $r->code() );
check( '4 · aucune demande créée', array() === $GLOBALS['wpd_posts'] );

// ============ 5 · ROUTE FICTIVE : nonces liés, le POST ne détourne pas =======
// Une seconde route existe UNIQUEMENT ici (jamais enregistrée en production) :
// action, type et nonce distincts. Le type fictif est enregistré dans la fixture.
FormRegistry::register( 'devis_test' );
$route_fictive = array(
	'action'       => 'urbizen_test_fictif',
	'form_type'    => 'devis_test',
	'nonce_action' => 'urbizen_test_fictif_submit',
);
$nonce_fictif = wp_create_nonce( 'urbizen_test_fictif_submit' );

// (a) le nonce Conception ne vaut pas pour la route fictive.
neuf();
$r = traiter_route( soumission(), $route_fictive );
check( '5 · nonce Conception refusé sur la route fictive', SubmissionResult::INVALID_NONCE === $r->code() );

// (b) le nonce fictif FRANCHIT l'étape nonce de la route fictive (l'échec vient
//     plus loin — définition « devis_test » absente —, PAS du nonce).
neuf();
$r = traiter_route( array_merge( soumission(), array( SubmissionController::NONCE_FIELD => $nonce_fictif ) ), $route_fictive );
check( '5 · nonce fictif accepté par la route fictive (échec ultérieur ≠ nonce)', SubmissionResult::INVALID_NONCE !== $r->code() );

// (c) le nonce fictif ne vaut pas pour Conception (route par défaut).
neuf();
$r = traiter( array_merge( soumission(), array( SubmissionController::NONCE_FIELD => $nonce_fictif ) ) );
check( '5 · nonce fictif refusé sur Conception', SubmissionResult::INVALID_NONCE === $r->code() );

// (d) form_type=conception dans le POST ne transforme PAS la route fictive.
neuf();
$r = traiter_route(
	array_merge( soumission(), array( SubmissionController::NONCE_FIELD => $nonce_fictif, 'form_type' => 'conception' ) ),
	$route_fictive
);
check( '5 · form_type=conception ne détourne pas la route fictive → invalid_form', SubmissionResult::INVALID_FORM === $r->code() );

// (e) action=urbizen_conception dans le POST ne transforme PAS la route fictive.
neuf();
$r = traiter_route(
	array_merge( soumission(), array( SubmissionController::NONCE_FIELD => $nonce_fictif, 'action' => 'urbizen_conception' ) ),
	$route_fictive
);
check( '5 · action=urbizen_conception ne détourne pas la route fictive → invalid_form', SubmissionResult::INVALID_FORM === $r->code() );

// (f) une route au type non enregistré est rejetée sans effet.
neuf();
$r = traiter_route( soumission(), array( 'action' => 'x', 'form_type' => 'inexistant', 'nonce_action' => 'y' ) );
check( '5 · route au type non enregistré → invalid_form', SubmissionResult::INVALID_FORM === $r->code() );
check( '5 · route inconnue → aucune demande', array() === $GLOBALS['wpd_posts'] );

// ============ 6 · FICHIER RÉEL HORS PROFIL SUR LA ROUTE CONCEPTION ============
// Un vrai fichier déposé dans un bloc interdit par le profil Conception ne doit
// pas être silencieusement écarté : la soumission est rejetée AVANT tout effet
// de bord (aucune demande, aucune référence, aucun courriel, aucun staging).
neuf();
$fichiers_hostiles = array(
	'pc_documents' => array( 'name' => 'x.pdf', 'type' => 'application/pdf', 'tmp_name' => fx_pdf(), 'error' => UPLOAD_ERR_OK, 'size' => 1 ),
);
$r = SubmissionController::process( soumission(), $fichiers_hostiles, serveur(), wpd_now(), null );
check( '6 · fichier réel dans un bloc interdit → upload_invalid_structure', ! $r->is_success() && SubmissionResult::UPLOAD_INVALID_STRUCTURE === $r->code() );
check( '6 · aucune demande', array() === $GLOBALS['wpd_posts'] );
check( '6 · aucune référence', '' === $r->reference() );
check( '6 · aucun courriel', array() === $GLOBALS['wpd_mails'] );

verdict();
