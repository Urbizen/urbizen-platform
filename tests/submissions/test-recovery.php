<?php
/**
 * Banc d'essai du canal serveur de reprise (Lot 2, C2A).
 *
 * La reprise conserve, brièvement et derrière un identifiant OPAQUE à usage
 * unique, les valeurs NETTOYÉES et les erreurs PUBLIQUES d'un rejet corrigeable.
 * Aucune donnée dans l'URL, aucun POST brut, aucun fichier, aucun consentement,
 * aucun champ hors définition. C2A ne réaffiche rien (ce sera C2B).
 *
 * Toutes les données sont fictives. Aucun courriel, aucun réseau, aucune base.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Http\SubmissionRecovery;
use Urbizen\Platform\Http\SubmissionRecoveryStore;
use Urbizen\Platform\Http\SubmissionFeedback;
use Urbizen\Platform\Http\SubmissionFeedbackToken;
use Urbizen\Platform\Http\SubmissionResultNotice;
use Urbizen\Platform\Submissions\SubmissionPostType;

$compteur = 0;

/** Harnais de mutation : lit le vrai source, applique la mutation, charge une classe distincte. */
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

/** Repart d'un état propre. */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

$ID32 = str_repeat( 'a', 32 );

// ======================== A · OBJET : FILTRAGE À LA DÉFINITION =============
$def   = FormRegistry::get( 'conception' );
$clean = array(
	'nature'           => 'maison',                        // texte déclaré → conservé
	'nom'              => 'Camille Fictif',                // déclaré → conservé
	'options_tarifees' => array( 'facades' ),              // multi déclaré → conservé (liste)
	'rgpd'             => true,                            // consentement → EXCLU
	'photos'           => array( 'x.jpg' ),                // fichier → EXCLU
	'inconnu_xyz'      => 'donnée hors définition',        // hors définition → EXCLU
	'surfaces'         => array( 'chambre_1' => 12 ),      // famille DESCOPÉE (C2C) → hors définition → EXCLU
);
$errors = array(
	'nom'                 => 'requis',                // déclaré → conservé
	'surfaces[chambre_1]' => 'hors_bornes',           // clé héritée descopée → écartée → globale générique
	'rgpd'                => 'requis',                // consentement déclaré → erreur conservée (valeur non)
	'cle_inconnue'        => 'boom',                  // clé inconnue → écartée → globale générique
	'situation'           => '<script>alert(1)</script>', // champ déclaré, code non conforme → normalisé
);

$rec = SubmissionRecovery::from_validation( 'conception', $def, $clean, $errors );

check( 'A · valeur texte déclarée conservée', 'Camille Fictif' === ( $rec->values['nom'] ?? null ) );
check( 'A · valeur multi déclarée conservée (liste)', array( 'facades' ) === ( $rec->values['options_tarifees'] ?? null ) );
check( 'A · famille surfaces DESCOPÉE non conservée', ! array_key_exists( 'surfaces', $rec->values ) );
check( 'A · CONSENTEMENT non conservé (re-confirmation)', ! array_key_exists( 'rgpd', $rec->values ) );
check( 'A · FICHIER non conservé', ! array_key_exists( 'photos', $rec->values ) );
check( 'A · champ hors définition non conservé', ! array_key_exists( 'inconnu_xyz', $rec->values ) );
check( 'A · jamais plus de champs que la définition', array() === array_diff( array_keys( $rec->values ), array_column( $def->fields(), 'name' ) ) );
check( 'A · erreur de champ déclaré conservée', 'requis' === ( $rec->errors['nom'] ?? null ) );
check( 'A · clé d’erreur surfaces[…] descopée écartée', ! array_key_exists( 'surfaces[chambre_1]', $rec->errors ) );
check( 'A · erreur de consentement conservée (mais pas sa valeur)', 'requis' === ( $rec->errors['rgpd'] ?? null ) );
check( 'A · clé d’erreur inconnue écartée', ! array_key_exists( 'cle_inconnue', $rec->errors ) );
check( 'A · clé inconnue → erreur globale générique', 'champs' === $rec->global_error );
check( 'A · code d’erreur non conforme normalisé (aucune valeur brute)', 'invalide' === ( $rec->errors['situation'] ?? null ) );

// ======================== B · STORE : DÉPÔT ET USAGE UNIQUE ================
neuf();
$id = SubmissionRecoveryStore::store( $rec );
check( 'B · identifiant opaque 32-hex', 1 === preg_match( '/^[0-9a-f]{32}$/', $id ) );

$cles = array_keys( $GLOBALS['wpd_transients'] );
check( 'B · une seule clé de transient', 1 === count( $cles ) );
check( 'B · clé préfixée, SANS l’identifiant lisible', str_starts_with( $cles[0], 'urbizen_rec_' ) && ! str_contains( $cles[0], $id ) );
check( 'B · la clé ne contient aucune valeur soumise', ! str_contains( $cles[0], 'Camille' ) && ! str_contains( $cles[0], 'nom' ) );

$lu = SubmissionRecoveryStore::consume( $id );
check( 'B · consommation : reprise retrouvée', $lu instanceof SubmissionRecovery && 'Camille Fictif' === ( $lu->values['nom'] ?? null ) );
check( 'B · USAGE UNIQUE : deuxième consommation vide', null === SubmissionRecoveryStore::consume( $id ) );
check( 'B · le transient est supprimé après consommation', array() === $GLOBALS['wpd_transients'] );

// Expiration.
neuf();
$id2 = SubmissionRecoveryStore::store( $rec );
$GLOBALS['wpd_now'] = wpd_now() + SubmissionRecoveryStore::TTL + 1;
check( 'B · une reprise expirée n’est plus lisible', null === SubmissionRecoveryStore::consume( $id2 ) );

// Suppression explicite.
neuf();
$id3 = SubmissionRecoveryStore::store( $rec );
SubmissionRecoveryStore::delete( $id3 );
check( 'B · delete() rend la reprise inaccessible', null === SubmissionRecoveryStore::consume( $id3 ) );

// Deux dépôts → deux identifiants distincts (aléa).
neuf();
check( 'B · deux dépôts → identifiants distincts', SubmissionRecoveryStore::store( $rec ) !== SubmissionRecoveryStore::store( $rec ) );

// ======================== C · SÉCURITÉ DE L’IDENTIFIANT ===================
foreach ( array(
	'null'         => null,
	'vide'         => '',
	'trop court'   => str_repeat( 'a', 31 ),
	'trop long'    => str_repeat( 'a', 33 ),
	'non hexa'     => str_repeat( 'g', 32 ),
	'majuscules'   => str_repeat( 'A', 32 ),
	'unicode'      => 'éà' . str_repeat( 'a', 28 ),
	'traversal'    => '../../etc/passwd',
	'url'          => 'http://exemple.test/x',
	'tableau'      => array( 'a' ),
) as $libelle => $entree ) {
	check( 'C · identifiant « ' . $libelle . ' » → null, sans erreur', null === SubmissionRecoveryStore::consume( $entree ) );
}

// Charge corrompue / version / type.
neuf();
set_transient( 'urbizen_rec_' . substr( hash_hmac( 'sha256', $ID32, wp_salt( 'auth' ) . '|urbizen-recovery' ), 0, 40 ), 'pas du json', SubmissionRecoveryStore::TTL );
check( 'C · charge non-JSON → null', null === SubmissionRecoveryStore::consume( $ID32 ) );
check( 'C · from_payload : version inconnue → null', null === SubmissionRecovery::from_payload( array( 'v' => 2, 't' => 'conception', 'values' => array(), 'errors' => array(), 'global' => '' ) ) );
check( 'C · from_payload : autre type → null', null === SubmissionRecovery::from_payload( array( 'v' => 1, 't' => 'localisation', 'values' => array(), 'errors' => array(), 'global' => '' ) ) );
check( 'C · from_payload : structure de valeurs invalide → null', null === SubmissionRecovery::from_payload( array( 'v' => 1, 't' => 'conception', 'values' => 'pas un tableau', 'errors' => array(), 'global' => '' ) ) );

// ======================== D · JETON : EXTENSION « k » =====================
$T = 1000000000;

$jeton_v = SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'validation', $ID32 ), $T );
$fb_v    = SubmissionFeedbackToken::verify( $jeton_v, $T );
check( 'D · erreur validation → k transporté et vérifié', null !== $fb_v && $ID32 === $fb_v->recovery_id );

$fb_succ = SubmissionFeedbackToken::verify( SubmissionFeedbackToken::issue( SubmissionFeedback::succes( 'conception', 'URB-2026-0001' ), $T ), $T );
check( 'D · un succès ne porte jamais de k', null !== $fb_succ && null === $fb_succ->recovery_id );

$fb_tech = SubmissionFeedbackToken::verify( SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'technical', $ID32 ), $T ), $T );
check( 'D · une erreur de sécurité (technical) n’ouvre pas de reprise', null !== $fb_tech && null === $fb_tech->recovery_id );

/** Forge un jeton validement signé pour une charge arbitraire (détenteur du secret de test). */
function forger( array $charge ): string {
	$json = json_encode( $charge );
	$b64  = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
	$sig  = rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'urbizen-feedback-v1|' . $b64, wp_salt( 'auth' ) . '|urbizen-feedback', true ) ), '+/', '-_' ), '=' );

	return $b64 . '.' . $sig;
}

check( 'D · k sur un SUCCÈS (même signé) → jeton rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => $T + 100, 'r' => 'URB-2026-0001', 'k' => $ID32 ) ), $T ) );
check( 'D · k sur une erreur NON-validation → jeton rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'error', 'x' => $T + 100, 'e' => 'technical', 'k' => $ID32 ) ), $T ) );
check( 'D · k mal formé → jeton rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'error', 'x' => $T + 100, 'e' => 'validation', 'k' => 'pas-un-id' ) ), $T ) );
check( 'D · un jeton C1 SANS k (validation) reste valide', null !== SubmissionFeedbackToken::verify( SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'validation' ), $T ), $T ) );

// ======================== E · NOTICE : consume_recovery ===================
neuf();
$idE = SubmissionRecoveryStore::store( $rec );
$_GET = array( SubmissionResultNotice::CHAMP => SubmissionFeedbackToken::issue( SubmissionFeedback::erreur( 'conception', 'validation', $idE ) ) );
$rE   = SubmissionResultNotice::consume_recovery();
check( 'E · consume_recovery renvoie la reprise', $rE instanceof SubmissionRecovery && 'Camille Fictif' === ( $rE->values['nom'] ?? null ) );
check( 'E · usage unique : deuxième appel vide', null === SubmissionResultNotice::consume_recovery() );
$_GET = array();

$_GET = array( SubmissionResultNotice::CHAMP => SubmissionFeedbackToken::issue( SubmissionFeedback::succes( 'conception', 'URB-2026-0001' ) ) );
check( 'E · un feedback de SUCCÈS → aucune reprise', null === SubmissionResultNotice::consume_recovery() );
$_GET = array();

$_GET = array( SubmissionResultNotice::CHAMP => 'jeton.forge', 'k' => $ID32 );
check( 'E · un k BRUT dans l’URL (sans feedback signé) → rien', null === SubmissionResultNotice::consume_recovery() );
$_GET = array();

// ======================== F · INTÉGRATION CONTRÔLEUR ======================
// F-A · rejet de validation corrigeable → reprise stockée, k dans le feedback.
neuf();
$rA = traiter( soumission( array( 'nom' => '' ) ) );
check( 'F-A · rejet de validation', ! $rA->is_success() && SubmissionResult::VALIDATION_FAILED === $rA->code() );
check( 'F-A · un identifiant de reprise est produit', 1 === preg_match( '/^[0-9a-f]{32}$/', $rA->recovery_id() ) );
check( 'F-A · aucune demande créée', array() === $GLOBALS['wpd_posts'] );
check( 'F-A · aucun courriel', array() === $GLOBALS['wpd_mails'] );

$urlA = SubmissionController::redirect_url( $rA, array( SubmissionController::RETURN_FIELD => '/p/' ), 'conception' );
$qA   = array();
parse_str( (string) parse_url( $urlA, PHP_URL_QUERY ), $qA );
$fbA  = SubmissionFeedbackToken::verify( $qA[ SubmissionResultNotice::CHAMP ] ?? '' );
check( 'F-A · le feedback signé porte le k du résultat', null !== $fbA && $rA->recovery_id() === $fbA->recovery_id && 1 === preg_match( '/^[0-9a-f]{32}$/', (string) $fbA->recovery_id ) );
check( 'F-A · AUCUNE valeur soumise dans l’URL', ! str_contains( $urlA, 'Camille' ) && ! str_contains( $urlA, 'nom' ) && ! str_contains( $urlA, 'requis' ) );
$recA = SubmissionRecoveryStore::consume( (string) $fbA->recovery_id );
check( 'F-A · la reprise contient les valeurs nettoyées, sans consentement', $recA instanceof SubmissionRecovery && ! array_key_exists( 'rgpd', $recA->values ) );
check( 'F-A · la reprise porte l’erreur du champ fautif', 'requis' === ( $recA->errors['nom'] ?? null ) );

// F-B · nonce invalide → aucune reprise.
neuf();
$rB = traiter( array_merge( soumission( array( 'nom' => '' ) ), array( SubmissionController::NONCE_FIELD => 'forge' ) ) );
check( 'F-B · nonce invalide', SubmissionResult::INVALID_NONCE === $rB->code() );
check( 'F-B · AUCUNE reprise après erreur de sécurité', '' === $rB->recovery_id() && array() === $GLOBALS['wpd_transients'] );

// F-C · pot de miel → aucune reprise.
neuf();
$rC = traiter( soumission( array( 'nom' => '', SubmissionController::HONEYPOT_FIELD => 'robot' ) ) );
check( 'F-C · pot de miel', SubmissionResult::SPAM_HONEYPOT === $rC->code() );
check( 'F-C · aucune reprise, aucun dépôt', '' === $rC->recovery_id() && array() === $GLOBALS['wpd_transients'] );

// F-D/E · rate limit et erreur interne → aucun k (via redirect_url).
$sans_k = static function ( string $code ): bool {
	$u = SubmissionController::redirect_url( SubmissionResult::failure( $code ), array(), 'conception' );
	$q = array();
	parse_str( (string) parse_url( $u, PHP_URL_QUERY ), $q );
	$fb = SubmissionFeedbackToken::verify( $q[ SubmissionResultNotice::CHAMP ] ?? '' );

	return null !== $fb && null === $fb->recovery_id;
};
check( 'F-D · rate limit → aucun k', $sans_k( SubmissionResult::RATE_LIMITED ) );
check( 'F-E · erreur interne (persistance) → aucun k', $sans_k( SubmissionResult::PERSISTENCE_FAILED ) );

// F-F · succès → aucune reprise.
neuf();
$rF = traiter( soumission() );
check( 'F-F · succès', $rF->is_success() );
check( 'F-F · aucun k, aucun dépôt de reprise', '' === $rF->recovery_id() && array() === $GLOBALS['wpd_transients'] );

// F-H · orphelin : delete() est le filet si le feedback ne peut être émis.
neuf();
$idH = SubmissionRecoveryStore::store( $rec );
SubmissionRecoveryStore::delete( $idH );
check( 'F-H · un dépôt orphelin peut être supprimé sans lecture', array() === $GLOBALS['wpd_transients'] );

// ======================== G · MUTANTS =====================================
// G1 · le consentement (et les fichiers) seraient conservés → re-confirmation cassée.
$m1 = mutant(
	'src/Http/SubmissionRecovery.php',
	'SubmissionRecovery',
	array( 'in_array( $types[ $nom ], self::TYPES_EXCLUS, true )' => 'false' )
);
$rec_mut = $m1::from_validation( 'conception', $def, $clean, $errors );
check( 'G1 · exclusion retirée → le consentement est conservé', array_key_exists( 'rgpd', $rec_mut->values ) );
check( 'G1 · le vrai code exclut le consentement', ! array_key_exists( 'rgpd', SubmissionRecovery::from_validation( 'conception', $def, $clean, $errors )->values ) );

// G2 · l'identifiant n'est plus aléatoire → collision.
$m2 = mutant(
	'src/Http/SubmissionRecoveryStore.php',
	'SubmissionRecoveryStore',
	array( '$id = bin2hex( random_bytes( 16 ) );' => "\$id = str_repeat( 'b', 32 );" )
);
neuf();
check( 'G2 · id figé → deux dépôts identiques', $m2::store( $rec ) === $m2::store( $rec ) );
neuf();
check( 'G2 · le vrai store produit des id distincts', SubmissionRecoveryStore::store( $rec ) !== SubmissionRecoveryStore::store( $rec ) );

// G3 · pas de suppression après consommation → rejeu possible.
$m3 = mutant(
	'src/Http/SubmissionRecoveryStore.php',
	'SubmissionRecoveryStore',
	array( 'delete_transient( $cle );' => '// suppression retirée' )
);
neuf();
$idm = $m3::store( $rec );
$m3::consume( $idm );
check( 'G3 · suppression retirée → deuxième consommation NON vide', null !== $m3::consume( $idm ) );
neuf();
$idr = SubmissionRecoveryStore::store( $rec );
SubmissionRecoveryStore::consume( $idr );
check( 'G3 · le vrai store : deuxième consommation vide', null === SubmissionRecoveryStore::consume( $idr ) );

// G4 · la vérification accepte un k pour une erreur de sécurité → reprise indue.
$m4 = mutant(
	'src/Http/SubmissionFeedbackToken.php',
	'SubmissionFeedbackToken',
	array( "'validation' !== \$categorie || ! is_string( \$k )" => 'false || ! is_string( $k )' )
);
// Deux gardes protègent cette propriété (le jeton ET SubmissionFeedback::erreur) :
// en mutant celle du jeton, l'observable est que le jeton technical+k devient
// ACCEPTÉ (au lieu d'être rejeté en bloc) — la seconde garde annule ensuite le k.
$forge_tech = forger( array( 'v' => 1, 't' => 'conception', 's' => 'error', 'x' => $T + 100, 'e' => 'technical', 'k' => $ID32 ) );
check( 'G4 · garde du jeton retirée → un jeton technical+k est ACCEPTÉ', null !== $m4::verify( $forge_tech, $T ) );
check( 'G4 · le vrai jeton REJETTE en bloc un k hors validation', null === SubmissionFeedbackToken::verify( $forge_tech, $T ) );

// G5 · from_payload accepte un autre type → reprise d'un autre formulaire consommée.
$m5 = mutant(
	'src/Http/SubmissionRecovery.php',
	'SubmissionRecovery',
	array( "'conception' !== \$type" => 'false' )
);
$charge_loc = array( 'v' => 1, 't' => 'localisation', 'values' => array( 'x' => '1' ), 'errors' => array(), 'global' => '' );
check( 'G5 · garde retirée → une reprise d’un autre type est acceptée', null !== $m5::from_payload( $charge_loc ) );
check( 'G5 · le vrai objet rejette un autre type', null === SubmissionRecovery::from_payload( $charge_loc ) );

verdict();
