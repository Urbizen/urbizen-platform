<?php
/**
 * Banc d'essai des défenses : jeton signé, pot de miel, limitation de débit.
 *
 * Trois signaux indépendants, éprouvés séparément. Aucun n'est suffisant seul,
 * et aucun ne doit conserver de trace de l'origine d'une requête.
 *
 * Toutes les adresses employées appartiennent aux plages de documentation
 * RFC 5737 : aucune adresse réelle ne figure dans ce banc.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Submissions\SubmissionPostType;

SubmissionPostType::register_post_type();

// ====================================================== JETON SIGNÉ =========
$maintenant = wpd_now();
$jeton      = AntiSpam::issue_token( $maintenant - 60 );

check( 'le jeton comporte trois champs séparés par un point', 3 === count( explode( '.', $jeton ) ) );
check( 'un jeton normal est accepté', AntiSpam::verify_token( $jeton, $maintenant )['ok'] );
check( 'deux jetons émis à la même seconde diffèrent', AntiSpam::issue_token( $maintenant ) !== AntiSpam::issue_token( $maintenant ) );

// --- signature ---
list( $id, $emis, $sig ) = explode( '.', $jeton );

$falsifie = AntiSpam::verify_token( $id . '.' . $emis . '.' . str_repeat( '0', strlen( $sig ) ), $maintenant );
check( 'une signature fausse est refusée', ! $falsifie['ok'] && 'invalid_antispam_token' === $falsifie['code'] );

// Reculer l'heure d'émission ferait croire à un jeton ancien : la signature
// couvre cette valeur, la manœuvre échoue.
$recule = AntiSpam::verify_token( $id . '.' . ( (int) $emis - 5000 ) . '.' . $sig, $maintenant );
check( 'reculer l’heure d’émission invalide la signature', ! $recule['ok'] && 'invalid_antispam_token' === $recule['code'] );

check( 'un jeton vide est refusé', ! AntiSpam::verify_token( '', $maintenant )['ok'] );
check( 'un jeton mal formé est refusé', ! AntiSpam::verify_token( 'nimporte.quoi', $maintenant )['ok'] );
check( 'un identifiant non hexadécimal est refusé', ! AntiSpam::verify_token( 'ZZZZ.' . $emis . '.' . $sig, $maintenant )['ok'] );

// --- fraîcheur ---
$rapide = AntiSpam::issue_token( $maintenant - 1 );
check( 'un jeton soumis en 1 seconde est refusé', 'token_too_fast' === AntiSpam::verify_token( $rapide, $maintenant )['code'] );

$limite = AntiSpam::issue_token( $maintenant - AntiSpam::MIN_SECONDS );
check( 'un jeton soumis au délai exact est accepté', AntiSpam::verify_token( $limite, $maintenant )['ok'] );

$vieux = AntiSpam::issue_token( $maintenant - AntiSpam::MAX_AGE - 1 );
check( 'un jeton de plus de 24 h est refusé', 'token_expired' === AntiSpam::verify_token( $vieux, $maintenant )['code'] );

$juste = AntiSpam::issue_token( $maintenant - AntiSpam::MAX_AGE );
check( 'un jeton de 24 h exactement est encore accepté', AntiSpam::verify_token( $juste, $maintenant )['ok'] );

$futur = AntiSpam::issue_token( $maintenant + 500 );
check( 'un jeton daté du futur est refusé', ! AntiSpam::verify_token( $futur, $maintenant )['ok'] );

// --- réemploi ---
check( 'un jeton neuf n’est pas marqué comme utilisé', ! AntiSpam::is_used( $jeton ) );
AntiSpam::mark_used( $jeton );
check( 'un jeton consommé est reconnu comme tel', AntiSpam::is_used( $jeton ) );
check( 'un jeton consommé est refusé comme doublon', 'duplicate_submission' === AntiSpam::verify_token( $jeton, $maintenant )['code'] );

$autre = AntiSpam::issue_token( $maintenant - 60 );
check( 'consommer un jeton n’affecte pas les autres', AntiSpam::verify_token( $autre, $maintenant )['ok'] );

// --- rien de brut n'est conservé ---
$stockage = wp_json_encode( $GLOBALS['wpd_transients'] );

check( 'le jeton brut n’est stocké nulle part', ! str_contains( (string) $stockage, $jeton ) );
check( 'la signature n’est stockée nulle part', ! str_contains( (string) $stockage, $sig ) );
check( 'l’identifiant du jeton n’est stocké nulle part', ! str_contains( (string) $stockage, $id ) );

// --- le délai minimal est ajustable ---
add_filter( 'urbizen_antispam_min_seconds', static fn() => 30 );
check( 'le délai minimal est ajustable par filtre', 30 === AntiSpam::min_seconds() );
check( 'un jeton de 10 s est alors refusé', 'token_too_fast' === AntiSpam::verify_token( AntiSpam::issue_token( $maintenant - 10 ), $maintenant )['code'] );
wpd_clear_filter( 'urbizen_antispam_min_seconds' );
check( 'le délai revient à 3 s sans filtre', 3 === AntiSpam::min_seconds() );

// ======================================================= POT DE MIEL ========
wpd_reset();
SubmissionPostType::register_post_type();

$r = traiter( soumission() );
check( 'un pot de miel vide laisse passer', $r->is_success() );

$avant = count( $GLOBALS['wpd_posts'] );
$r     = traiter( soumission( array( SubmissionController::HONEYPOT_FIELD => 'https://exemple-robot.test' ) ) );

check( 'un pot de miel rempli est refusé', ! $r->is_success() && 'spam_honeypot' === $r->code() );
check( 'aucune demande n’est enregistrée', count( $GLOBALS['wpd_posts'] ) === $avant );
check( 'la valeur du pot de miel n’apparaît pas dans le journal', ! str_contains( journal(), 'exemple-robot' ) );
check( 'le journal ne nomme pas le champ piégé', ! str_contains( journal(), SubmissionController::HONEYPOT_FIELD ) );

$r = traiter( soumission( array( SubmissionController::HONEYPOT_FIELD => '   ' ) ) );
check( 'un pot de miel n’ayant que des espaces laisse passer', $r->is_success() );

// ================================================ LIMITATION DE DÉBIT =======
wpd_reset();
SubmissionPostType::register_post_type();

$s = serveur();

check( 'valeurs par défaut : 5 par heure', 5 === RateLimiter::max() && 3600 === RateLimiter::window() );

$autorisees = 0;

for ( $i = 1; $i <= 5; $i++ ) {
	if ( RateLimiter::allow( 'conception', $s, wpd_now() ) ) {
		++$autorisees;
	}
}

check( 'les cinq premières soumissions passent', 5 === $autorisees );
check( 'la sixième est refusée', ! RateLimiter::allow( 'conception', $s, wpd_now() ) );
check( 'la septième aussi', ! RateLimiter::allow( 'conception', $s, wpd_now() ) );

// Une tentative refusée ne doit pas repousser la fin de la fenêtre.
check( 'à 59 minutes, toujours refusé', ! RateLimiter::allow( 'conception', $s, wpd_now() + 3540 ) );
check( 'à 60 minutes, la fenêtre est rouverte', RateLimiter::allow( 'conception', $s, wpd_now() + 3600 ) );

// --- origines indépendantes ---
wpd_reset();
$a = serveur( array( 'REMOTE_ADDR' => '203.0.113.10' ) );
$b = serveur( array( 'REMOTE_ADDR' => '198.51.100.20' ) );

for ( $i = 1; $i <= 5; $i++ ) {
	RateLimiter::allow( 'conception', $a, wpd_now() );
}

check( 'une origine épuisée est bloquée', ! RateLimiter::allow( 'conception', $a, wpd_now() ) );
check( 'une autre origine reste libre', RateLimiter::allow( 'conception', $b, wpd_now() ) );
check( 'deux origines ont des clés distinctes', RateLimiter::key( 'conception', $a ) !== RateLimiter::key( 'conception', $b ) );
check( 'deux compartiments ont des clés distinctes', RateLimiter::key( 'conception', $a ) !== RateLimiter::key( 'contact', $a ) );

// --- aucune adresse conservée ---
$stockage = wp_json_encode( array( $GLOBALS['wpd_transients'], $GLOBALS['wpd_options'], $GLOBALS['wpd_meta'] ) );

check( 'aucune adresse brute dans les transients, options ou métadonnées',
	! str_contains( (string) $stockage, '203.0.113.10' ) && ! str_contains( (string) $stockage, '198.51.100.20' ) );
check( 'aucune adresse brute dans le journal',
	! str_contains( journal(), '203.0.113.10' ) && ! str_contains( journal(), '198.51.100.20' ) );
check( 'la clé ne contient pas l’adresse', ! str_contains( RateLimiter::key( 'conception', $a ), '203.0.113' ) );
check( 'la clé est un condensat de longueur fixe', 1 === preg_match( '/^urbizen_rl_[0-9a-f]{40}$/', RateLimiter::key( 'conception', $a ) ) );

// --- en-têtes de proxy : jamais crus sur parole ---
$menteur = serveur( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.99', 'HTTP_X_REAL_IP' => '198.51.100.98', 'HTTP_CLIENT_IP' => '198.51.100.97' ) );

check( 'X-Forwarded-For est ignoré par défaut', '203.0.113.10' === RateLimiter::origin( $menteur ) );
check( 'un faux en-tête ne change pas la clé', RateLimiter::key( 'conception', $menteur ) === RateLimiter::key( 'conception', $a ) );

wpd_reset();
for ( $i = 1; $i <= 5; $i++ ) {
	RateLimiter::allow( 'conception', $a, wpd_now() );
}
check( 'un faux X-Forwarded-For ne permet pas de contourner la limite', ! RateLimiter::allow( 'conception', $menteur, wpd_now() ) );

// Un hébergement derrière un proxy de confiance peut le déclarer, mais c'est
// une décision explicite, jamais un comportement par défaut.
add_filter( 'urbizen_trusted_proxy_header', static fn() => 'X-Forwarded-For' );
check( 'un proxy déclaré par filtre est honoré', '198.51.100.99' === RateLimiter::origin( $menteur ) );
check( 'une chaîne de proxys retient la première adresse',
	'198.51.100.99' === RateLimiter::origin( serveur( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.5' ) ) ) );
check( 'sans l’en-tête, on retombe sur REMOTE_ADDR', '203.0.113.10' === RateLimiter::origin( $a ) );
wpd_clear_filter( 'urbizen_trusted_proxy_header' );

// --- limites ajustables ---
// La remise à zéro efface aussi les filtres : elle vient donc en premier.
wpd_reset();
add_filter( 'urbizen_rate_limit_max', static fn() => 2 );
add_filter( 'urbizen_rate_limit_window', static fn() => 60 );

check( 'la limite est ajustable par filtre', 2 === RateLimiter::max() );
check( 'la fenêtre est ajustable par filtre', 60 === RateLimiter::window() );
check( '1re passe', RateLimiter::allow( 'conception', $a, wpd_now() ) );
check( '2e passe', RateLimiter::allow( 'conception', $a, wpd_now() ) );
check( '3e refusée', ! RateLimiter::allow( 'conception', $a, wpd_now() ) );
check( 'après 60 s, rouverte', RateLimiter::allow( 'conception', $a, wpd_now() + 60 ) );
wpd_clear_filter( 'urbizen_rate_limit_max' );
wpd_clear_filter( 'urbizen_rate_limit_window' );

verdict();
