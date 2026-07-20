<?php
/**
 * Banc d'essai de la concurrence et de la planification.
 *
 * Les trois questions que la revue de la PR #18 a soulevées, éprouvées
 * chacune par un entrelacement **déterministe** — pas par une répétition qui
 * espère tomber sur la course.
 *
 * Le principe : au lieu de lancer deux processus et d'espérer qu'ils se
 * télescopent, on reproduit l'entrelacement à la main. La requête A s'arrête
 * juste après avoir choisi son candidat, B s'exécute entièrement, puis A
 * reprend. C'est reproductible à chaque exécution.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

/**
 * Repart d'un état propre.
 */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

// ======================================================================
// 1 · DEUX REQUÊTES CONCURRENTES SUR LE MÊME JETON
// ======================================================================
neuf();

$jeton = AntiSpam::issue_token( wpd_now() - 60 );

// A et B ont toutes deux passé signature et dates : elles se présentent
// ensemble devant la réservation.
$a = AntiSpam::reserve_token( $jeton, wpd_now() );
$b = AntiSpam::reserve_token( $jeton, wpd_now() );

check( '1 · une seule des deux requêtes obtient le jeton', $a xor $b );
check( '1 · c’est la première qui l’obtient', true === $a && false === $b );

// Le point décisif : B est refusée **avant** que A n'ait fini d'écrire.
neuf();
$post = soumission();

$reserve_par_a = AntiSpam::reserve_token( $post[ \Urbizen\Platform\Http\SubmissionController::TOKEN_FIELD ], wpd_now() );
$pendant       = traiter( $post );

check( '1 · A réserve', $reserve_par_a );
check( '1 · B est refusée pendant le traitement de A, avant toute persistance',
	SubmissionResult::DUPLICATE_SUBMISSION === $pendant->code() );
check( '1 · aucune demande n’a été créée par B', array() === $GLOBALS['wpd_posts'] );

// La réservation survit à une purge de cache — c'est une option, pas un transient.
neuf();
$post = soumission();
$un   = traiter( $post );

check( '1 · la première soumission réussit', $un->is_success() );

wpd_purger_caches();
$deux = traiter( $post );

check( '1 · APRÈS PURGE DU CACHE, le rejeu reste refusé', SubmissionResult::DUPLICATE_SUBMISSION === $deux->code() );
check( '1 · une seule demande existe', 1 === count( $GLOBALS['wpd_posts'] ) );

// Un échec corrigible rend le jeton.
neuf();
$post   = soumission( array( 'nature' => 'chateau_fort' ) );
$refuse = traiter( $post );

check( '1 · une validation en échec est refusée', SubmissionResult::VALIDATION_FAILED === $refuse->code() );
check( '1 · le jeton est rendu', ! AntiSpam::is_used( $post[ \Urbizen\Platform\Http\SubmissionController::TOKEN_FIELD ], wpd_now() ) );

$post['nature'] = 'maison';
$corrige        = traiter( $post );

check( '1 · la personne peut corriger et renvoyer avec le même jeton', $corrige->is_success() );

// ======================================================================
// 2 · ENTRELACEMENT DE DEUX ALLOCATIONS DE RÉFÉRENCE
// ======================================================================
neuf();

// A choisit son candidat et le réserve, mais n'a pas encore écrit sa demande.
$candidat_a = SubmissionRepository::next_reference( wpd_now() );

check( '2 · A obtient URB-…-0001', str_ends_with( $candidat_a, '-0001' ) );

// B arrive au même instant. Le compteur, remis en arrière, la fait viser le
// même rang — exactement la course que le compteur seul ne savait pas arbitrer.
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$candidat_b = SubmissionRepository::next_reference( wpd_now() );

check( '2 · B vise le même rang mais reçoit la référence suivante', $candidat_b !== $candidat_a );
check( '2 · B obtient URB-…-0002', str_ends_with( $candidat_b, '-0002' ) );
check( '2 · aucune demande n’existe encore : la course est bien sur la réservation', array() === $GLOBALS['wpd_posts'] );

// Chacune écrit ensuite sa demande : deux demandes, deux références.
$def        = \Urbizen\Platform\Forms\FormRegistry::get( 'conception' );
$validation = \Urbizen\Platform\Forms\Validator::validate(
	$def,
	array( 'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non', 'nom' => 'Camille Fictif', 'email' => 'camille@exemple.test', 'rgpd' => '1' )
);

neuf();
$refs = array();

for ( $i = 1; $i <= 6; $i++ ) {
	$c = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );
	$refs[] = $c['reference'];
}

check( '2 · six créations donnent six références', 6 === count( array_filter( $refs ) ) );
check( '2 · aucune référence en double', 6 === count( array_unique( $refs ) ) );
check( '2 · six demandes distinctes coexistent', 6 === count( $GLOBALS['wpd_posts'] ) );
check( '2 · aucune demande n’a été écrasée', 6 === count( array_unique( array_column( array_map( 'get_post', array_keys( $GLOBALS['wpd_posts'] ) ), 'post_title' ) ) ) );

// Le compteur seul ne suffit pas : remis à zéro, la réservation prend le relais.
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$septieme = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );

check( '2 · compteur remis à zéro : la référence reste unique', ! in_array( $septieme['reference'], $refs, true ) );
check( '2 · aucune demande écrasée', 7 === count( $GLOBALS['wpd_posts'] ) );

// Une référence réservée puis abandonnée est libérée.
neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$echec                    = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );
$GLOBALS['wpd_meta_fail'] = '';

check( '2 · une persistance en échec est signalée', empty( $echec['ok'] ) );
check( '2 · la référence abandonnée est libérée',
	null === get_option( SubmissionRepository::RESERVATION_PREFIX . 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001', null ) );

// La référence libérée n'est pas *bloquée*. Le compteur, lui, a déjà avancé :
// la série saute un rang. C'est assumé — un compteur qu'on ferait reculer
// rouvrirait précisément la course que la réservation vient de fermer. Ce qui
// compte est qu'aucune référence ne reste définitivement inutilisable.
$annee = (int) gmdate( 'Y', wpd_now() );
update_option( SubmissionRepository::SEQUENCE_OPTION, array( $annee => 0 ) );
$reprise = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );

check( '2 · la référence libérée n’est pas bloquée : elle est réattribuable', str_ends_with( $reprise['reference'], '-0001' ) );

// Une référence attribuée reste réservée pour toujours.
$cle_attribuee = SubmissionRepository::RESERVATION_PREFIX . $reprise['reference'];

check( '2 · une référence attribuée porte l’état « attributed »', 'attributed' === ( get_option( $cle_attribuee )['state'] ?? '' ) );
check( '2 · elle mémorise la demande associée', $reprise['id'] === ( get_option( $cle_attribuee )['post'] ?? 0 ) );
check( '2 · aucune réservation n’est autoloadée', 'no' === wpd_autoload( $cle_attribuee ) );

check( '2 · le nettoyage ne touche jamais une référence attribuée',
	0 === SubmissionRepository::cleanup_abandoned_references( wpd_now() + 999999 )
	&& null !== get_option( $cle_attribuee, null ) );

// Une réservation orpheline ancienne est nettoyée ; une récente ne l'est pas.
neuf();
SubmissionRepository::reserve_reference( 'URB-2026-0500', wpd_now() - SubmissionRepository::RESERVATION_TTL - 1 );
SubmissionRepository::reserve_reference( 'URB-2026-0501', wpd_now() );

check( '2 · nettoyage : une réservation orpheline ancienne part', 1 === SubmissionRepository::cleanup_abandoned_references( wpd_now() ) );
check( '2 · nettoyage : une réservation récente reste', null !== get_option( SubmissionRepository::RESERVATION_PREFIX . 'URB-2026-0501', null ) );
check( '2 · nettoyage : idempotent', 0 === SubmissionRepository::cleanup_abandoned_references( wpd_now() ) );

// Une référence historique, créée avant le mécanisme, est bien évitée.
neuf();
$ancien = wp_insert_post( array( 'post_type' => SubmissionPostType::POST_TYPE, 'post_status' => 'private' ) );
update_post_meta( $ancien, '_urbizen_reference', 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001' );

$suivante = SubmissionRepository::next_reference( wpd_now() );

check( '2 · une référence historique sans réservation est évitée', ! str_ends_with( $suivante, '-0001' ) );

// ======================================================================
// 3 · SIX SOUMISSIONS VALIDES SIMULTANÉES
// ======================================================================
neuf();

$resultats = array();

for ( $i = 1; $i <= 6; $i++ ) {
	$resultats[] = traiter( soumission() );
}

$reussies = array_filter( $resultats, static fn( $r ) => $r->is_success() );

check( '3 · six soumissions valides : exactement cinq réussissent', 5 === count( $reussies ) );
check( '3 · la sixième est limitée', SubmissionResult::RATE_LIMITED === $resultats[5]->code() );
check( '3 · cinq demandes en base, pas six', 5 === count( $GLOBALS['wpd_posts'] ) );
check( '3 · cinq références distinctes', 5 === count( array_unique( array_map( static fn( $r ) => $r->reference(), $reussies ) ) ) );

// Le point décisif de la revue : une erreur corrigible ne coûte pas un créneau.
neuf();

for ( $i = 1; $i <= 5; $i++ ) {
	$r = traiter( soumission( array( 'nature' => 'chateau_fort' ) ) );
	check( sprintf( '4 · erreur de validation %d refusée', $i ), SubmissionResult::VALIDATION_FAILED === $r->code() );
}

check( '4 · CINQ ERREURS DE VALIDATION NE CONSOMMENT AUCUN CRÉNEAU', 0 === RateLimiter::used( 'conception', serveur(), wpd_now() ) );

$valide = traiter( soumission() );

check( '4 · une demande valide reste possible après cinq erreurs', $valide->is_success() );

// Idem pour un fichier refusé et pour une persistance en échec.
neuf();
$fichier = array( 'croquis_plans' => array( 'name' => array( 'plan.pdf' ), 'tmp_name' => array( '/tmp/x' ), 'error' => array( UPLOAD_ERR_OK ), 'size' => array( 10 ) ) );

traiter( soumission(), $fichier );
check( '4 · un fichier refusé ne consomme aucun créneau', 0 === RateLimiter::used( 'conception', serveur(), wpd_now() ) );

neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$r                        = traiter( soumission() );
$GLOBALS['wpd_meta_fail'] = '';

check( '4 · une persistance en échec est signalée', SubmissionResult::PERSISTENCE_FAILED === $r->code() );
check( '4 · une persistance en échec rend le créneau', 0 === RateLimiter::used( 'conception', serveur(), wpd_now() ) );
check( '4 · elle rend aussi le jeton', 0 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, AntiSpam::OPTION_PREFIX ) ) ) );

// Un refus de sécurité ne consomme pas non plus de créneau : il intervient
// avant la réservation.
neuf();
traiter( soumission( array( \Urbizen\Platform\Http\SubmissionController::HONEYPOT_FIELD => 'robot' ) ) );
traiter( array_merge( soumission(), array( \Urbizen\Platform\Http\SubmissionController::NONCE_FIELD => 'faux' ) ) );

check( '4 · un refus de sécurité ne consomme aucun créneau', 0 === RateLimiter::used( 'conception', serveur(), wpd_now() ) );
check( '4 · un refus de sécurité n’est pas présenté comme une demande', array() === $GLOBALS['wpd_posts'] );

// Deux origines restent indépendantes de bout en bout.
neuf();
$a = serveur( array( 'REMOTE_ADDR' => '203.0.113.10' ) );
$b = serveur( array( 'REMOTE_ADDR' => '198.51.100.20' ) );

for ( $i = 1; $i <= 5; $i++ ) {
	traiter( soumission(), array(), $a );
}

check( '4 · la première origine est épuisée', SubmissionResult::RATE_LIMITED === traiter( soumission(), array(), $a )->code() );
check( '4 · la seconde origine passe toujours', traiter( soumission(), array(), $b )->is_success() );

// ======================================================================
// 5 · PLANIFICATION DE LA RÉTENTION
// ======================================================================

// Scénario 1 — installation et activation neuves.
wpd_reset();
Retention::schedule();
check( '5.1 · activation neuve : la tâche est programmée', false !== wp_next_scheduled( Retention::HOOK ) );

// Scénario 2 — 0.5.0 déjà actif, fichiers remplacés par 0.6.0 : le hook
// d'activation ne se déclenche PAS. C'est le cas réel de la production.
wpd_reset();
check( '5.2 · avant montée de version : aucune tâche', false === wp_next_scheduled( Retention::HOOK ) );

Retention::register();
do_action( 'init' );

check( '5.2 · MISE À JOUR SANS RÉACTIVATION : la tâche est programmée', false !== wp_next_scheduled( Retention::HOOK ) );
check( '5.2 · aucune désactivation manuelle n’est nécessaire', has_action( 'init' ) );

// Scénario 3 — chargements répétés.
$premier = wp_next_scheduled( Retention::HOOK );

for ( $i = 1; $i <= 10; $i++ ) {
	do_action( 'init' );
}

check( '5.3 · dix chargements ne créent aucun doublon', $premier === wp_next_scheduled( Retention::HOOK ) );
check( '5.3 · une seule tâche est enregistrée', 1 === count( $GLOBALS['wpd_cron'] ) );

// Scénario 4 — tâche déjà présente.
Retention::ensure_scheduled();
Retention::ensure_scheduled();
check( '5.4 · ensure_scheduled est idempotente', $premier === wp_next_scheduled( Retention::HOOK ) );

// Scénario 5 — désactivation.
Retention::unschedule();
check( '5.5 · la désactivation retire la tâche', false === wp_next_scheduled( Retention::HOOK ) );
check( '5.5 · aucune tâche résiduelle', array() === $GLOBALS['wpd_cron'] );

// Scénario 6 — réactivation.
Retention::schedule();
check( '5.6 · la réactivation la recrée', false !== wp_next_scheduled( Retention::HOOK ) );
check( '5.6 · et une seule fois', 1 === count( $GLOBALS['wpd_cron'] ) );

// La programmation est journalisée sans rien de sensible.
check( '5 · la programmation est journalisée', str_contains( journal(), 'tâche quotidienne programmée' ) );

// ======================================================================
// 6 · MÉNAGE QUOTIDIEN
// ======================================================================
neuf();

// De quoi nettoyer : un jeton expiré, un créneau expiré, une réservation
// abandonnée, et une demande hors délai.
AntiSpam::reserve_token( AntiSpam::issue_token( wpd_now() ), wpd_now() );
RateLimiter::reserve( 'conception', serveur(), wpd_now() );
SubmissionRepository::reserve_reference( 'URB-2026-0900', wpd_now() );

$vieille = wp_insert_post( array( 'post_type' => SubmissionPostType::POST_TYPE, 'post_status' => 'private' ) );
update_post_meta( $vieille, '_urbizen_status', 'received' );
update_post_meta( $vieille, '_urbizen_reference', 'URB-2026-0900' );
update_post_meta( $vieille, '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );

$plus_tard = wpd_now() + AntiSpam::MAX_AGE + 1;
$bilan     = Retention::run_daily( $plus_tard );

check( '6 · le passage quotidien supprime la demande expirée', 1 === $bilan['demandes'] );
check( '6 · il nettoie le jeton expiré', 1 === $bilan['jetons'] );
check( '6 · il nettoie le créneau expiré', 1 === $bilan['creneaux'] );
check( '6 · il libère la réservation abandonnée', 1 === $bilan['references'] );
check( '6 · plus aucune option technique ne subsiste',
	array() === array_filter(
		array_keys( $GLOBALS['wpd_options'] ),
		static fn( $c ) => str_starts_with( $c, 'urbizen_tok_' ) || str_starts_with( $c, 'urbizen_rl_' ) || str_starts_with( $c, 'urbizen_ref_' )
	) );

$second = Retention::run_daily( $plus_tard );

// Six compteurs : demandes, jetons, créneaux, références, staging, abandons.
check( '6 · le passage est idempotent', array( 0, 0, 0, 0, 0, 0 ) === array_values( $second ) );
check( '6 · le journal ne cite ni jeton, ni condensat, ni référence',
	! preg_match( '/urbizen_tok_[0-9a-f]|urbizen_rl_[0-9a-f]|URB-2026-0900/', journal() ) );
check( '6 · le journal ne donne que des décomptes', str_contains( journal(), 'ménage :' ) );

// Le ménage est accroché à la tâche quotidienne, pas seulement appelable.
wpd_reset();
Retention::register();
check( '6 · le ménage est accroché à la tâche quotidienne', has_action( Retention::HOOK ) );

// ======================================================================
// 7 · OPTIONS TECHNIQUES : AUCUNE N'EST AUTOLOADÉE
// ======================================================================
neuf();
traiter( soumission() );

$techniques = array_filter(
	array_keys( $GLOBALS['wpd_options'] ),
	static fn( $c ) => str_starts_with( $c, 'urbizen_' )
);

$autoloadees = array_values( array_filter( $techniques, static fn( $c ) => 'no' !== wpd_autoload( $c ) ) );

check( '7 · des options techniques ont bien été créées', count( $techniques ) >= 3 );
check( '7 · AUCUNE option technique n’est autoloadée', array() === $autoloadees );

if ( array() !== $autoloadees ) {
	echo '    autoloadée : ' . implode( ' | ', $autoloadees ) . "\n";
}

check( '7 · aucune donnée personnelle dans les options techniques',
	! preg_match( '/Camille|exemple\.test|203\.0\.113/', (string) wp_json_encode( array( $techniques, array_map( 'get_option', $techniques ) ) ) ) );

verdict();
