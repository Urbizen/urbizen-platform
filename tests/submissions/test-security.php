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
check( 'un jeton neuf n’est pas marqué comme utilisé', ! AntiSpam::is_used( $jeton, $maintenant ) );
check( 'la première réservation aboutit', AntiSpam::reserve_token( $jeton, $maintenant ) );
check( 'la seconde réservation du même jeton échoue', ! AntiSpam::reserve_token( $jeton, $maintenant ) );
check( 'un jeton réservé est reconnu comme utilisé', AntiSpam::is_used( $jeton, $maintenant ) );
check( 'un jeton réservé est refusé comme doublon', 'duplicate_submission' === AntiSpam::verify_token( $jeton, $maintenant )['code'] );

AntiSpam::consume_token( $jeton, $maintenant );
check( 'après consommation, le jeton reste refusé', 'duplicate_submission' === AntiSpam::verify_token( $jeton, $maintenant )['code'] );
check( 'l’état stocké est « consumed »', 'consumed' === ( get_option( AntiSpam::option_key( $jeton ) )['state'] ?? '' ) );

$autre = AntiSpam::issue_token( $maintenant - 60 );
check( 'consommer un jeton n’affecte pas les autres', AntiSpam::verify_token( $autre, $maintenant )['ok'] );

// --- réservation libérable : une erreur corrigible ne brûle pas le jeton ---
$libere = AntiSpam::issue_token( $maintenant - 60 );
AntiSpam::reserve_token( $libere, $maintenant );
AntiSpam::release_token( $libere );
check( 'un jeton libéré redevient utilisable', AntiSpam::verify_token( $libere, $maintenant )['ok'] );
check( 'un jeton libéré ne laisse aucune option', null === get_option( AntiSpam::option_key( $libere ), null ) );

// --- une purge de cache ne rend pas un jeton rejouable ---
$apres_purge = AntiSpam::issue_token( $maintenant - 60 );
AntiSpam::reserve_token( $apres_purge, $maintenant );
AntiSpam::consume_token( $apres_purge, $maintenant );
wpd_purger_caches();
check( 'APRÈS PURGE DU CACHE, un jeton consommé reste refusé',
	'duplicate_submission' === AntiSpam::verify_token( $apres_purge, $maintenant )['code'] );
check( 'la réservation survit à la purge : c’est une option, pas un transient',
	is_array( get_option( AntiSpam::option_key( $apres_purge ), null ) ) );
check( 'aucune réservation n’est autoloadée', 'no' === wpd_autoload( AntiSpam::option_key( $apres_purge ) ) );

// --- nettoyage des réservations expirées ---
check( 'nettoyage : rien à faire à cet instant', 0 === AntiSpam::cleanup_expired_tokens( $maintenant ) );
$avant   = count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, AntiSpam::OPTION_PREFIX ) ) );
$purgees = AntiSpam::cleanup_expired_tokens( $maintenant + AntiSpam::MAX_AGE + 1 );
check( 'nettoyage : toutes les réservations expirées partent', $purgees === $avant && $avant > 0 );
check( 'nettoyage : plus aucune réservation ne subsiste',
	array() === array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, AntiSpam::OPTION_PREFIX ) ) );
check( 'nettoyage : idempotent', 0 === AntiSpam::cleanup_expired_tokens( $maintenant + AntiSpam::MAX_AGE + 1 ) );
check( 'nettoyage : rien de sensible dans le journal',
	! str_contains( journal(), $jeton ) && ! str_contains( journal(), AntiSpam::option_key( $jeton ) ) );

// --- rien de brut n'est conservé ---
$stockage = wp_json_encode( array( $GLOBALS['wpd_options'], array_keys( $GLOBALS['wpd_options'] ), $GLOBALS['wpd_transients'] ) );

check( 'le jeton brut n’est stocké nulle part', ! str_contains( (string) $stockage, $jeton ) );
check( 'la signature n’est stockée nulle part', ! str_contains( (string) $stockage, $sig ) );
check( 'l’identifiant du jeton n’est stocké nulle part', ! str_contains( (string) $stockage, $id ) );
check( 'le nom d’option est un condensat de longueur fixe',
	1 === preg_match( '/^urbizen_tok_[0-9a-f]{40}$/', AntiSpam::option_key( $jeton ) ) );
check( 'le nom d’option tient dans la limite de option_name', strlen( AntiSpam::option_key( $jeton ) ) <= 191 );

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
// Politique : cinq demandes **réellement enregistrées** par heure et par
// origine. Une erreur corrigible ne doit pas brûler un créneau.
wpd_reset();
SubmissionPostType::register_post_type();

$s = serveur();

check( 'valeurs par défaut : 5 par heure', 5 === RateLimiter::max() && 3600 === RateLimiter::window() );

$creneaux = array();

for ( $i = 1; $i <= 5; $i++ ) {
	$creneaux[] = RateLimiter::reserve( 'conception', $s, wpd_now() );
}

check( 'cinq créneaux sont réservables', 5 === count( array_filter( $creneaux ) ) );
check( 'les cinq créneaux sont distincts', 5 === count( array_unique( $creneaux ) ) );
check( 'le sixième est refusé', null === RateLimiter::reserve( 'conception', $s, wpd_now() ) );
check( 'cinq créneaux comptés comme occupés', 5 === RateLimiter::used( 'conception', $s, wpd_now() ) );

// --- libérer rend le créneau immédiatement ---
RateLimiter::release( $creneaux[2] );

check( 'libérer un créneau le rend disponible', 4 === RateLimiter::used( 'conception', $s, wpd_now() ) );
$repris = RateLimiter::reserve( 'conception', $s, wpd_now() );
check( 'le créneau libéré est repris', null !== $repris );
check( 'le quota est de nouveau atteint', null === RateLimiter::reserve( 'conception', $s, wpd_now() ) );

// --- confirmer garde le créneau jusqu'à la fin de la fenêtre ---
RateLimiter::confirm( $creneaux[0], wpd_now() );

check( 'un créneau confirmé reste occupé', 5 === RateLimiter::used( 'conception', $s, wpd_now() ) );
check( 'l’état stocké est « confirmed »', 'confirmed' === ( get_option( $creneaux[0] )['state'] ?? '' ) );
check( 'confirmer ne repousse pas la fin de la fenêtre',
	(int) get_option( $creneaux[0] )['expires'] === wpd_now() + 3600 );

// --- la fenêtre s'écoule ---
check( 'à 59 minutes, toujours refusé', null === RateLimiter::reserve( 'conception', $s, wpd_now() + 3540 ) );
check( 'à 60 minutes, un créneau se libère', null !== RateLimiter::reserve( 'conception', $s, wpd_now() + 3600 ) );

// --- une purge de cache ne perd pas la comptabilisation ---
wpd_reset();

for ( $i = 1; $i <= 5; $i++ ) {
	RateLimiter::confirm( RateLimiter::reserve( 'conception', $s, wpd_now() ), wpd_now() );
}

wpd_purger_caches();

check( 'APRÈS PURGE DU CACHE, les cinq créneaux confirmés tiennent', 5 === RateLimiter::used( 'conception', $s, wpd_now() ) );
check( 'après purge, le sixième reste refusé', null === RateLimiter::reserve( 'conception', $s, wpd_now() ) );
check( 'aucun créneau n’est autoloadé', 'no' === wpd_autoload( RateLimiter::key( 'conception', $s ) . '_0' ) );

// --- origines indépendantes ---
wpd_reset();
$a = serveur( array( 'REMOTE_ADDR' => '203.0.113.10' ) );
$b = serveur( array( 'REMOTE_ADDR' => '198.51.100.20' ) );

for ( $i = 1; $i <= 5; $i++ ) {
	RateLimiter::reserve( 'conception', $a, wpd_now() );
}

check( 'une origine épuisée est bloquée', null === RateLimiter::reserve( 'conception', $a, wpd_now() ) );
check( 'une autre origine reste libre', null !== RateLimiter::reserve( 'conception', $b, wpd_now() ) );
check( 'deux origines ont des préfixes distincts', RateLimiter::key( 'conception', $a ) !== RateLimiter::key( 'conception', $b ) );
check( 'deux compartiments ont des préfixes distincts', RateLimiter::key( 'conception', $a ) !== RateLimiter::key( 'contact', $a ) );

// --- aucune adresse conservée ---
$stockage = wp_json_encode( array( $GLOBALS['wpd_options'], array_keys( $GLOBALS['wpd_options'] ), $GLOBALS['wpd_transients'], $GLOBALS['wpd_meta'] ) );

check( 'aucune adresse brute dans les options, transients ou métadonnées',
	! str_contains( (string) $stockage, '203.0.113.10' ) && ! str_contains( (string) $stockage, '198.51.100.20' ) );
check( 'aucune adresse brute dans le journal',
	! str_contains( journal(), '203.0.113.10' ) && ! str_contains( journal(), '198.51.100.20' ) );
check( 'le préfixe est un condensat de longueur fixe',
	1 === preg_match( '/^urbizen_rl_[0-9a-f]{32}$/', RateLimiter::key( 'conception', $a ) ) );
check( 'le nom de créneau tient dans la limite de option_name',
	strlen( RateLimiter::key( 'conception', $a ) . '_4' ) <= 191 );

// --- en-têtes de proxy : jamais crus sur parole ---
$menteur = serveur( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.99', 'HTTP_X_REAL_IP' => '198.51.100.98', 'HTTP_CLIENT_IP' => '198.51.100.97' ) );

check( 'X-Forwarded-For est ignoré par défaut', '203.0.113.10' === RateLimiter::origin( $menteur ) );
check( 'un faux en-tête ne change pas le compartiment', RateLimiter::key( 'conception', $menteur ) === RateLimiter::key( 'conception', $a ) );
check( 'un faux X-Forwarded-For ne permet pas de contourner la limite',
	null === RateLimiter::reserve( 'conception', $menteur, wpd_now() ) );

add_filter( 'urbizen_trusted_proxy_header', static fn() => 'X-Forwarded-For' );
check( 'un proxy déclaré par filtre est honoré', '198.51.100.99' === RateLimiter::origin( $menteur ) );
check( 'une chaîne de proxys retient la première adresse',
	'198.51.100.99' === RateLimiter::origin( serveur( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.5' ) ) ) );
check( 'sans l’en-tête, on retombe sur REMOTE_ADDR', '203.0.113.10' === RateLimiter::origin( $a ) );
wpd_clear_filter( 'urbizen_trusted_proxy_header' );

// --- nettoyage des créneaux expirés ---
$avant   = count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, RateLimiter::OPTION_PREFIX ) ) );
check( 'nettoyage : rien à faire dans la fenêtre', 0 === RateLimiter::cleanup_expired_slots( wpd_now() ) );
$purges = RateLimiter::cleanup_expired_slots( wpd_now() + 3601 );
check( 'nettoyage : tous les créneaux expirés partent', $purges === $avant && $avant > 0 );
check( 'nettoyage : idempotent', 0 === RateLimiter::cleanup_expired_slots( wpd_now() + 3601 ) );

// --- limites ajustables ---
wpd_reset();
add_filter( 'urbizen_rate_limit_max', static fn() => 2 );
add_filter( 'urbizen_rate_limit_window', static fn() => 60 );

check( 'la limite est ajustable par filtre', 2 === RateLimiter::max() );
check( 'la fenêtre est ajustable par filtre', 60 === RateLimiter::window() );
check( '1re réservation', null !== RateLimiter::reserve( 'conception', $a, wpd_now() ) );
check( '2e réservation', null !== RateLimiter::reserve( 'conception', $a, wpd_now() ) );
check( '3e refusée', null === RateLimiter::reserve( 'conception', $a, wpd_now() ) );
check( 'après 60 s, rouverte', null !== RateLimiter::reserve( 'conception', $a, wpd_now() + 60 ) );
wpd_clear_filter( 'urbizen_rate_limit_max' );
wpd_clear_filter( 'urbizen_rate_limit_window' );

// --- une valeur de créneau étrangère est ignorée ---
RateLimiter::release( 'option_du_site' );
RateLimiter::confirm( 'option_du_site', wpd_now() );
check( 'release et confirm refusent un nom hors préfixe', null === get_option( 'option_du_site', null ) );

verdict();
