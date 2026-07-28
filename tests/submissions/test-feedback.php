<?php
/**
 * Banc d'essai de la confirmation post-soumission (Lot 2, incrément C1).
 *
 * Vérifie que le résultat public d'une soumission est **émis et vérifié côté
 * serveur** : un jeton signé, borné dans le temps, sans donnée personnelle. Une
 * URL forgée (`?urbizen_submission=success`, `?reference=URB-...`) ne produit
 * **aucune** confirmation fiable ; seule une charge correctement signée et
 * vivante affiche un message. La référence ne voyage que dans le jeton.
 *
 * Toutes les données sont fictives. Aucun courriel, aucun réseau, aucune base
 * réelle.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Http\SubmissionFeedback;
use Urbizen\Platform\Http\SubmissionFeedbackToken;
use Urbizen\Platform\Http\SubmissionResultNotice;
use Urbizen\Platform\Submissions\SubmissionPostType;

const RACINE = 'urbizen-conception';

/** Repart d'un état propre, type de demande enregistré. */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

/** Valeur du jeton de retour présente dans une adresse, ou chaîne vide. */
function val_jeton( string $url ): string {
	$q = array();
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );

	return (string) ( $q[ SubmissionResultNotice::CHAMP ] ?? '' );
}

/** Adresse privée de son jeton : la part lisible, sans le jeton signé. */
function sans_jeton( string $url ): string {
	$q = array();
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	unset( $q[ SubmissionResultNotice::CHAMP ] );

	return (string) parse_url( $url, PHP_URL_PATH ) . ( $q ? '?' . http_build_query( $q ) : '' );
}

/**
 * Forge un jeton VALIDEMENT SIGNÉ pour une charge arbitraire.
 *
 * Le banc tient ici le rôle du détenteur du secret (le sel de test, factice) :
 * il réplique exactement la signature de production pour prouver que la
 * vérification rejette une charge signée mais structurellement fautive — version
 * inconnue, autre formulaire, statut ou catégorie non reconnus, référence mal
 * formée, expiration incohérente. Sans cela, ces gardes ne seraient jamais
 * atteintes (une signature invalide arrête la vérification avant elles).
 *
 * @param array<string, mixed> $charge Charge utile brute.
 * @return string
 */
function forger( array $charge ): string {
	$json = json_encode( $charge );
	$b64  = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
	$sig  = rtrim(
		strtr(
			base64_encode(
				hash_hmac( 'sha256', 'urbizen-feedback-v1|' . $b64, wp_salt( 'auth' ) . '|urbizen-feedback', true )
			),
			'+/',
			'-_'
		),
		'='
	);

	return $b64 . '.' . $sig;
}

/**
 * Charge une copie mutée d'une classe du plugin (mutation de code).
 *
 * @param string                $relatif       Chemin sous le plugin.
 * @param string                $classe        Nom de la classe d'origine.
 * @param array<string, string> $remplacements Motif exact => remplacement.
 * @return string Nom pleinement qualifié de la classe mutée.
 */
function mutant_feedback( string $relatif, string $classe, array $remplacements ): string {
	static $compteur = 0;

	$source  = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $relatif );
	$nouveau = $classe . 'MutantFb' . ( ++$compteur );
	$source  = str_replace( "final class $classe", "final class $nouveau", $source );

	foreach ( $remplacements as $de => $vers ) {
		if ( ! str_contains( $source, $de ) ) {
			throw new RuntimeException( "motif introuvable dans $relatif : $de" );
		}
		$source = str_replace( $de, $vers, $source );
	}

	$fichier = sys_get_temp_dir() . '/urbizen-' . $nouveau . '.php';
	file_put_contents( $fichier, $source );
	require $fichier;
	unlink( $fichier );

	return '\\Urbizen\\Platform\\Http\\' . $nouveau;
}

$T = 1000000000; // Horloge fixe pour les scénarios déterministes.

// ============================ A · SUCCÈS RÉEL ==============================
neuf();
$r = traiter( soumission() );
check( 'A · une soumission valide réussit', $r->is_success() );

$ref = $r->reference();
$url = SubmissionController::redirect_url( $r, array( SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/' ), 'conception' );

check( 'A · un jeton de retour est présent dans l’URL', str_contains( $url, SubmissionResultNotice::CHAMP . '=' ) );

$fb = SubmissionFeedbackToken::verify( val_jeton( $url ) );
check( 'A · le jeton se vérifie et porte un succès', null !== $fb && $fb->est_succes() );
check( 'A · le jeton porte la référence serveur', null !== $fb && $ref === $fb->reference );

// La page d'arrivée affiche la confirmation à partir du jeton (lecture GET confinée).
$_GET = array( SubmissionResultNotice::CHAMP => val_jeton( $url ) );
$html = SubmissionResultNotice::html_courante( RACINE );
check( 'A · la confirmation affiche un statut accessible', str_contains( $html, 'role="status"' ) );
check( 'A · la confirmation affiche la référence', str_contains( $html, htmlspecialchars( $ref, ENT_QUOTES, 'UTF-8' ) ) );
check( 'A · la confirmation ne se présente pas comme une alerte', ! str_contains( $html, 'role="alert"' ) );
// C1 bis : marqueur serveur, unique source de vérité du client (jamais l'URL).
check( 'A · la notice porte le marqueur serveur success', str_contains( $html, 'data-urbizen-feedback-status="success"' ) );
check( 'A · aucun marqueur error dans une notice de succès', ! str_contains( $html, 'data-urbizen-feedback-status="error"' ) );
$_GET = array();

// ============================ B · ERREUR RÉELLE ============================
neuf();
$re = traiter( soumission( array( 'nom' => '' ) ) );
check( 'B · une soumission invalide échoue', ! $re->is_success() && SubmissionResult::VALIDATION_FAILED === $re->code() );

$url_e = SubmissionController::redirect_url( $re, array( SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/' ), 'conception' );
$fb_e  = SubmissionFeedbackToken::verify( val_jeton( $url_e ) );
check( 'B · le jeton porte une erreur de catégorie publique', null !== $fb_e && ! $fb_e->est_succes() && 'validation' === $fb_e->categorie_erreur );
check( 'B · le jeton d’erreur ne porte aucune référence', null !== $fb_e && null === $fb_e->reference );

$_GET  = array( SubmissionResultNotice::CHAMP => val_jeton( $url_e ) );
$html_e = SubmissionResultNotice::html_courante( RACINE );
check( 'B · le message d’erreur est une alerte accessible', str_contains( $html_e, 'role="alert"' ) );
check( 'B · la notice d’erreur porte le marqueur serveur error', str_contains( $html_e, 'data-urbizen-feedback-status="error"' ) );
check( 'B · aucun marqueur success dans une notice d’erreur', ! str_contains( $html_e, 'data-urbizen-feedback-status="success"' ) );
check( 'B · le message d’erreur ne révèle aucun code interne', ! str_contains( $html_e, 'validation_failed' ) && ! str_contains( $html_e, 'nom' ) );
check( 'B · le message d’erreur n’invente aucune référence', ! str_contains( $html_e, 'URB-' ) );
$_GET = array();

// La correspondance code interne → catégorie publique ne fuit jamais le pipeline.
$categorie = static function ( string $code ): ?string {
	$u  = SubmissionController::redirect_url( SubmissionResult::failure( $code ), array(), 'conception' );
	$fb = SubmissionFeedbackToken::verify( val_jeton( $u ) );

	return null === $fb ? null : $fb->categorie_erreur;
};

check( 'B · rate_limited → catégorie rate_limited', 'rate_limited' === $categorie( SubmissionResult::RATE_LIMITED ) );
check( 'B · upload trop lourd → catégorie validation', 'validation' === $categorie( SubmissionResult::UPLOAD_TOO_LARGE ) );
check( 'B · corps trop grand → catégorie validation', 'validation' === $categorie( SubmissionResult::REQUEST_TOO_LARGE ) );
check( 'B · persistance → catégorie technique (opaque)', 'technical' === $categorie( SubmissionResult::PERSISTENCE_FAILED ) );
check( 'B · nonce invalide → catégorie technique (défense opaque)', 'technical' === $categorie( SubmissionResult::INVALID_NONCE ) );
check( 'B · pot de miel → catégorie technique (défense opaque)', 'technical' === $categorie( SubmissionResult::SPAM_HONEYPOT ) );

// ============================ C · ABSENCE DE FEEDBACK ======================
$_GET = array();
check( 'C · sans jeton, aucun message n’est rendu', '' === SubmissionResultNotice::html_courante( RACINE ) );

// ============================ D · URL FORGÉE ===============================
$_GET = array( 'urbizen_submission' => 'success', 'reference' => 'URB-9999-9999', 'error' => 'boom' );
check( 'D · statut/référence bruts, sans jeton → rien', '' === SubmissionResultNotice::html_courante( RACINE ) );

foreach ( array(
	'success',
	'URB-2026-0001',
	'validation',
	'aaaa.bbbb',
) as $forge ) {
	$_GET = array( SubmissionResultNotice::CHAMP => $forge );
	check( 'D · jeton forgé « ' . substr( $forge, 0, 16 ) . ' » → rien', '' === SubmissionResultNotice::html_courante( RACINE ) );
}
$_GET = array();

// ============================ E · FEEDBACK ALTÉRÉ ==========================
$ok = SubmissionFeedbackToken::issue( SubmissionFeedback::succes( 'conception', 'URB-2026-0001' ), $T );
check( 'E · un jeton fraîchement émis se vérifie', null !== SubmissionFeedbackToken::verify( $ok, $T ) );
check( 'E · valide à l’instant exact d’expiration', null !== SubmissionFeedbackToken::verify( $ok, $T + SubmissionFeedbackToken::TTL ) );
check( 'E · rejeté une seconde après expiration', null === SubmissionFeedbackToken::verify( $ok, $T + SubmissionFeedbackToken::TTL + 1 ) );

$charge_sig = explode( '.', $ok );
check( 'E · charge altérée d’un caractère → rejet', null === SubmissionFeedbackToken::verify( ( 'A' === $charge_sig[0][0] ? 'B' : 'A' ) . substr( $charge_sig[0], 1 ) . '.' . $charge_sig[1], $T ) );
check( 'E · signature altérée d’un caractère → rejet', null === SubmissionFeedbackToken::verify( $charge_sig[0] . '.' . ( 'A' === $charge_sig[1][0] ? 'B' : 'A' ) . substr( $charge_sig[1], 1 ), $T ) );
check( 'E · jeton tronqué → rejet', null === SubmissionFeedbackToken::verify( substr( $ok, 0, -1 ), $T ) );

// Charges VALIDEMENT SIGNÉES mais structurellement fautives : chaque garde mord.
check( 'E · version inconnue → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 2, 't' => 'conception', 's' => 'success', 'x' => $T + 100, 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'E · feedback d’un AUTRE formulaire → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'localisation', 's' => 'success', 'x' => $T + 100, 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'E · statut inconnu → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'peut-etre', 'x' => $T + 100 ) ), $T ) );
check( 'E · succès sans référence → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => $T + 100 ) ), $T ) );
check( 'E · référence mal formée (même signée) → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => $T + 100, 'r' => '<script>' ) ), $T ) );
check( 'E · catégorie d’erreur inconnue → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'error', 'x' => $T + 100, 'e' => 'inconnue' ) ), $T ) );
check( 'E · erreur sans catégorie → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'error', 'x' => $T + 100 ) ), $T ) );
check( 'E · expiration non entière → rejet', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => 'demain', 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'E · sanité : une charge bien formée et signée se vérifie', null !== SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => $T + 100, 'r' => 'URB-2026-0001' ) ), $T ) );

// ============================ 10 · SÉCURITÉ DE L’ENTRÉE ====================
$entrees = array(
	'null'            => null,
	'vide'            => '',
	'espaces'         => '   ',
	'sans point'      => 'abcdef',
	'deux points'     => 'a.b.c',
	'trop long'       => str_repeat( 'a', 600 ),
	'unicode'         => 'éà.çù',
	'slash/traversal' => '../../etc/passwd',
	'url'             => 'http://exemple.test/x',
	'plus et slash'   => 'ab+cd/ef.gh',
);

foreach ( $entrees as $libelle => $entree ) {
	check( '10 · entrée « ' . $libelle . ' » → rejet', null === SubmissionFeedbackToken::verify( $entree, $T ) );
}

check( '10 · un tableau à la place d’une chaîne → rejet, sans erreur fatale', null === SubmissionFeedbackToken::verify( array( 'x' ), $T ) );

// ============================ F · ÉCHAPPEMENT ==============================
// La référence est échappée à la source de rendu, même si une charge fautive
// (jamais produite en vrai, ici forcée) portait des métacaractères.
$hostile = '<script>alert("x")</script>"\'é/&';
$html_x  = SubmissionResultNotice::render( SubmissionFeedback::succes( 'conception', $hostile ), RACINE );
check( 'F · aucune balise script brute dans le rendu', ! str_contains( $html_x, '<script>' ) );
check( 'F · les métacaractères sont échappés', str_contains( $html_x, '&lt;script&gt;' ) );
check( 'F · le rendu reste un statut accessible', str_contains( $html_x, 'role="status"' ) );

// ============================ G · AUCUNE PII DANS L’URL ====================
neuf();
$rg  = traiter( soumission() );
check( 'G · la soumission chiffrée réussit', $rg->is_success() );

$urlg = SubmissionController::redirect_url(
	$rg,
	array(
		SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/',
		'nom'                              => 'Camille Fictif',
		'email'                            => 'camille@exemple.test',
		'tel'                              => '0100000000',
		'message'                          => 'Bonjour Urbizen',
	),
	'conception'
);

foreach ( array( 'Camille', 'camille', '0100000000', 'Bonjour', 'message', 'email' ) as $motif ) {
	check( 'G · la part lisible de l’URL ne contient pas « ' . $motif . ' »', ! str_contains( sans_jeton( $urlg ), $motif ) );
}

$fbg = SubmissionFeedbackToken::verify( val_jeton( $urlg ) );
check( 'G · le jeton ne porte que la référence, aucune saisie', null !== $fbg && $fbg->est_succes() && $rg->reference() === $fbg->reference );

// ======================== H · BORNE HAUTE D'EXPIRATION (H1) ================
// L'expiration doit rester dans une fenêtre bornée : ni passée, ni arbitrairement
// lointaine. Le jeton est émis pour TTL secondes ; une tolérance d'horloge faible
// est admise sur la borne haute uniquement.
$TTL = SubmissionFeedbackToken::TTL;
$val = static fn( int $x ) => forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => $x, 'r' => 'URB-2026-0001' ) );

check( 'H · jeton valide (x = now + 100) → accepté', null !== SubmissionFeedbackToken::verify( $val( $T + 100 ), $T ) );
check( 'H · expiré (x = now - 1) → rejeté', null === SubmissionFeedbackToken::verify( $val( $T - 1 ), $T ) );
check( 'H · exactement à la borne haute (now + TTL) → accepté', null !== SubmissionFeedbackToken::verify( $val( $T + $TTL ), $T ) );
check( 'H · borne haute + tolérance (now + TTL + 5) → accepté', null !== SubmissionFeedbackToken::verify( $val( $T + $TTL + 5 ), $T ) );
check( 'H · une seconde au-delà de la tolérance (now + TTL + 6) → rejeté', null === SubmissionFeedbackToken::verify( $val( $T + $TTL + 6 ), $T ) );
check( 'H · expiration TRÈS lointaine (now + 10 ans) → rejetée', null === SubmissionFeedbackToken::verify( $val( $T + 315360000 ), $T ) );
check( 'H · expiration = PHP_INT_MAX → rejetée (aucun overflow)', null === SubmissionFeedbackToken::verify( $val( PHP_INT_MAX ), $T ) );

// Types non entiers, toujours rejetés (jamais de fatale).
check( 'H · x flottant → rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => 1000000100.5, 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'H · x chaîne numérique → rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => '1000000100', 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'H · x null → rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => null, 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'H · x tableau → rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => array( 1 ), 'r' => 'URB-2026-0001' ) ), $T ) );
check( 'H · x négatif → rejeté', null === SubmissionFeedbackToken::verify( forger( array( 'v' => 1, 't' => 'conception', 's' => 'success', 'x' => -5, 'r' => 'URB-2026-0001' ) ), $T ) );

// Mutant : borne haute RETIRÉE → une expiration arbitrairement lointaine passe.
$mBorne = mutant_feedback(
	'src/Http/SubmissionFeedbackToken.php',
	'SubmissionFeedbackToken',
	array( 'if ( $expire > $now + self::TTL + self::TOLERANCE_HORLOGE ) {' => 'if ( false ) {' )
);
check( 'H · mutant → borne haute retirée : un jeton de 10 ans est ACCEPTÉ', null !== $mBorne::verify( $val( $T + 315360000 ), $T ) );
check( 'H · le vrai jeton REJETTE toujours l’expiration lointaine', null === SubmissionFeedbackToken::verify( $val( $T + 315360000 ), $T ) );

verdict();
