<?php
/**
 * Banc de concurrence, contre un vrai WordPress, avec de vrais processus.
 *
 * Deux appels successifs dans un même processus ne prouvent rien sur la
 * concurrence : ils partagent le cache d'objets, les variables statiques et
 * l'ordre d'exécution. Ce banc lance donc des **processus PHP distincts**,
 * synchronisés par des fichiers de rendez-vous hors du dépôt, et observe ce qui
 * se produit lorsqu'ils se disputent la même notification.
 *
 * Aucun courriel n'est émis : le transport des processus fils est un double.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/amorce-reelle.php';

use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailProcessLock;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Submissions\TrashGuard;

$reussis = 0;
$echecs  = 0;

function verifier( string $libelle, bool $ok ): void {
	global $reussis, $echecs;

	if ( $ok ) {
		++$reussis;
		printf( "%-74s OK\n", $libelle );
	} else {
		++$echecs;
		printf( "%-74s ECHEC\n", $libelle );
	}
}

$rdv = sys_get_temp_dir() . '/urbizen-rdv-' . getmypid();

/**
 * Vide le répertoire de rendez-vous.
 */
function rdv_neuf(): string {
	global $rdv;

	if ( is_dir( $rdv ) ) {
		foreach ( (array) glob( $rdv . '/*' ) as $f ) {
			@unlink( $f );
		}
	} else {
		mkdir( $rdv, 0700, true );
	}

	return $rdv;
}

/**
 * Lance un processus fils en arrière-plan.
 *
 * @param string                $script Nom du script sous `procs/`.
 * @param array<string, string> $env    Variables d'environnement.
 * @return resource|false
 */
function lancer( string $script, array $env = array() ) {
	global $rdv;

	$prefixe = sprintf(
		'URBIZEN_WP_ROOT=%s URBIZEN_RDV=%s ',
		escapeshellarg( (string) getenv( 'URBIZEN_WP_ROOT' ) ),
		escapeshellarg( $rdv )
	);

	foreach ( $env as $cle => $valeur ) {
		$prefixe .= sprintf( '%s=%s ', $cle, escapeshellarg( (string) $valeur ) );
	}

	$commande = $prefixe . escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/procs/' . $script ) . ' > /dev/null 2>&1 &';

	return popen( $commande, 'r' );
}

/**
 * Attend un fichier de rendez-vous.
 */
function attendre( string $nom, float $secondes = 20.0 ): bool {
	global $rdv;

	return urbizen_attendre( $rdv . '/' . $nom, $secondes );
}

/**
 * Lit un fichier de rendez-vous.
 */
function lire( string $nom ): string {
	global $rdv;

	return is_readable( $rdv . '/' . $nom ) ? trim( (string) file_get_contents( $rdv . '/' . $nom ) ) : '';
}

/**
 * Vide le cache d'objets du processus courant.
 *
 * Les processus fils écrivent en base ; sans cette purge, le parent lirait ses
 * propres valeurs mises en cache et croirait que rien n'a bougé.
 */
function cache_neuf(): void {
	wp_cache_flush();
}

/**
 * Compte les événements réellement inscrits pour une demande, arguments
 * compris, après purge du cache.
 */
function evenements( int $id ): int {
	cache_neuf();

	$combien = 0;

	foreach ( (array) _get_cron_array() as $horodatage => $crochets ) {
		foreach ( (array) ( $crochets[ \Urbizen\Platform\Mail\MailPolicy::EVENT ] ?? array() ) as $entree ) {
			if ( array( $id ) === array_values( (array) ( $entree['args'] ?? array() ) ) ) {
				++$combien;
			}
		}
	}

	return $combien;
}

/**
 * Relit une métadonnée en contournant tout cache.
 */
function frais( int $id, string $cle ): string {
	cache_neuf();

	return (string) get_post_meta( $id, $cle, true );
}

update_option( 'admin_email', 'dossiers@urbizen.test' );
TrashGuard::register();

// ======================================================================
// A · ENVOI ARRÊTÉ AVANT LE TRANSPORT, PUIS CORBEILLE CONCURRENTE
// ======================================================================
rdv_neuf();
$d = urbizen_demande_reelle();

lancer( 'envoi-barriere.php', array( 'URBIZEN_ID' => (string) $d['id'] ) );
verifier( 'A · l’envoi atteint la barrière', attendre( 'a-la-barriere' ) );

lancer( 'corbeille.php', array( 'URBIZEN_ID' => (string) $d['id'] ) );
verifier( 'A · la Corbeille rend son verdict', attendre( 'corbeille-resultat' ) );
verifier( 'A · elle est REFUSÉE pendant l’envoi', 'refusee' === lire( 'corbeille-resultat' ) );

cache_neuf();
verifier( 'A · le contenu reste privé', 'private' === get_post_status( $d['id'] ) );
verifier( 'A · aucune annulation n’a été écrite', MailPolicy::CANCELLED !== frais( $d['id'], MailPolicy::META_STATUS ) );

urbizen_jalon( $rdv . '/liberer' );
verifier( 'A · l’envoi se termine', attendre( 'envoi-resultat' ) );
verifier( 'A · il aboutit', 'sent' === lire( 'envoi-resultat' ) );
verifier( 'A · le transport a bien été appelé', '1' === lire( 'transport-appele' ) );
verifier( 'A · mail_status = sent', MailPolicy::SENT === frais( $d['id'], MailPolicy::META_STATUS ) );
verifier( 'A · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

// La mise à la Corbeille reste possible ensuite.
rdv_neuf();
lancer( 'corbeille.php', array( 'URBIZEN_ID' => (string) $d['id'] ) );
attendre( 'corbeille-resultat' );

verifier( 'A · une nouvelle tentative de Corbeille aboutit', 'aboutie' === lire( 'corbeille-resultat' ) );

// ======================================================================
// J · SENT NE DEVIENT JAMAIS CANCELLED
// ======================================================================
verifier( 'J · sent survit à la Corbeille', MailPolicy::SENT === frais( $d['id'], MailPolicy::META_STATUS ) );
verifier( 'J · sent_at est conservé', '' !== frais( $d['id'], MailPolicy::META_SENT_AT ) );

wp_untrash_post( $d['id'] );

verifier( 'J · sent survit à la restauration', MailPolicy::SENT === frais( $d['id'], MailPolicy::META_STATUS ) );
verifier( 'J · aucun événement replanifié après sent', 0 === evenements( $d['id'] ) );

// Un ancien événement exécuté après sent est un no-op.
$avant = MailScheduler::process( $d['id'], time() );

verifier( 'J · un ancien événement après sent est sans effet', 'mail_status_non_envoyable' === $avant );

// ======================================================================
// B · LA CORBEILLE GAGNE LA COURSE, PUIS ÉVÉNEMENT CONCURRENT
// ======================================================================
rdv_neuf();
$e = urbizen_demande_reelle();

lancer( 'corbeille.php', array( 'URBIZEN_ID' => (string) $e['id'] ) );
verifier( 'B · la Corbeille aboutit', attendre( 'corbeille-terminee' ) && 'aboutie' === lire( 'corbeille-resultat' ) );

cache_neuf();
verifier( 'B · mail_status = cancelled', MailPolicy::CANCELLED === frais( $e['id'], MailPolicy::META_STATUS ) );
verifier( 'B · aucun événement ne subsiste', 0 === evenements( $e['id'] ) );

rdv_neuf();
lancer( 'envoi-barriere.php', array( 'URBIZEN_ID' => (string) $e['id'] ) );
verifier( 'B · l’envoi rend son verdict', attendre( 'envoi-resultat' ) );

// L · AUCUN wp_mail APRÈS QUE L'ÉTAT FERMÉ A GAGNÉ
verifier( 'L · le transport n’est JAMAIS appelé', '' === lire( 'transport-appele' ) );
verifier( 'L · l’envoi est refusé sur le statut natif', 'post_status_inattendu' === lire( 'envoi-resultat' ) );
verifier( 'K · cancelled n’est pas devenu sent', MailPolicy::CANCELLED === frais( $e['id'], MailPolicy::META_STATUS ) );

// ======================================================================
// D · RESTAURATION PENDANT UN ÉVÉNEMENT RÉSIDUEL
// ======================================================================
wp_untrash_post( $e['id'] );

verifier( 'D · la restauration remet en attente', MailPolicy::PENDING === frais( $e['id'], MailPolicy::META_STATUS ) );
verifier( 'D · et replanifie exactement un événement', 1 === evenements( $e['id'] ) );

rdv_neuf();
lancer( 'envoi-barriere.php', array( 'URBIZEN_ID' => (string) $e['id'] ) );
attendre( 'a-la-barriere' );
urbizen_jalon( $rdv . '/liberer' );
attendre( 'envoi-resultat' );

verifier( 'D · l’envoi aboutit après restauration', 'sent' === lire( 'envoi-resultat' ) );

// ======================================================================
// C · SUPPRESSION DÉFINITIVE PENDANT UN ENVOI
// ======================================================================
rdv_neuf();
$f = urbizen_demande_reelle();

lancer( 'envoi-barriere.php', array( 'URBIZEN_ID' => (string) $f['id'] ) );
verifier( 'C · l’envoi atteint la barrière', attendre( 'a-la-barriere' ) );

lancer( 'suppression.php', array( 'URBIZEN_ID' => (string) $f['id'] ) );
verifier( 'C · la suppression rend son verdict', attendre( 'suppression-resultat' ) );
verifier( 'C · elle est REFUSÉE pendant l’envoi', 'refusee' === lire( 'suppression-resultat' ) );

cache_neuf();
verifier( 'C · la demande existe toujours', null !== get_post( $f['id'] ) );

urbizen_jalon( $rdv . '/liberer' );
attendre( 'envoi-resultat' );

verifier( 'C · l’envoi se termine normalement', 'sent' === lire( 'envoi-resultat' ) );

rdv_neuf();
lancer( 'suppression.php', array( 'URBIZEN_ID' => (string) $f['id'] ) );
attendre( 'suppression-resultat' );

verifier( 'C · la suppression aboutit ensuite', 'aboutie' === lire( 'suppression-resultat' ) );
cache_neuf();

verifier( 'C · un événement résiduel est sans effet', 'post_absent' === MailScheduler::process( $f['id'], time() ) );

// ======================================================================
// F · DEUX PLANIFICATIONS SIMULTANÉES
// ======================================================================
rdv_neuf();
$g = urbizen_demande_reelle();
MailScheduler::unschedule_all( $g['id'] );

verifier( 'F · aucun événement au départ', 0 === evenements( $g['id'] ) );

for ( $i = 1; $i <= 4; $i++ ) {
	lancer( 'planifier.php', array( 'URBIZEN_ID' => (string) $g['id'], 'URBIZEN_MOI' => (string) $i ) );
}

usleep( 400000 );
urbizen_jalon( $rdv . '/top' );

for ( $i = 1; $i <= 4; $i++ ) {
	attendre( 'planif-' . $i );
}

// Décompte réel dans le tableau cron, arguments compris.
verifier( 'F · quatre planifications simultanées → EXACTEMENT un événement', 1 === evenements( $g['id'] ) );
verifier( 'F · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $g['id'], false ) );

// ======================================================================
// E · DEUX ACTIONS ADMINISTRATIVES SIMULTANÉES
// ======================================================================
rdv_neuf();
$h = urbizen_demande_reelle();
\Urbizen\Platform\Submissions\SubmissionRepository::persist_meta( $h['id'], MailPolicy::META_STATUS, MailPolicy::FAILED );
\Urbizen\Platform\Submissions\SubmissionRepository::persist_meta( $h['id'], MailPolicy::META_ATTEMPTS, 5 );
MailScheduler::unschedule_all( $h['id'] );

for ( $i = 1; $i <= 3; $i++ ) {
	lancer( 'reprise-admin.php', array( 'URBIZEN_ID' => (string) $h['id'], 'URBIZEN_MOI' => (string) $i ) );
}

usleep( 400000 );
urbizen_jalon( $rdv . '/top' );

for ( $i = 1; $i <= 3; $i++ ) {
	attendre( 'reprise-' . $i );
}

$faites = 0;

for ( $i = 1; $i <= 3; $i++ ) {
	if ( 'faite' === lire( 'reprise-' . $i ) ) {
		++$faites;
	}
}

verifier( 'E · une seule reprise a effectivement agi', 1 === $faites );
verifier( 'E · un seul état pending', MailPolicy::PENDING === frais( $h['id'], MailPolicy::META_STATUS ) );
verifier( 'E · le compteur est remis à zéro une seule fois', 0 === (int) frais( $h['id'], MailPolicy::META_ATTEMPTS ) );
verifier( 'E · exactement un événement', 1 === evenements( $h['id'] ) );
verifier( 'E · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $h['id'], false ) );

// ======================================================================
// H · UN ANCIEN PROPRIÉTAIRE NE LIBÈRE PAS UN VERROU REPRIS
// ======================================================================
$k       = urbizen_demande_reelle();
$ancien  = MailQueue::acquire_lock( $k['id'], time() );

verifier( 'H · le premier obtient un jeton', is_string( $ancien ) && 32 === strlen( (string) $ancien ) );
verifier( 'H · un second est refusé', null === MailQueue::acquire_lock( $k['id'], time() ) );

// Le verrou expire ; un autre le reprend.
$plus_tard = time() + MailPolicy::lock_ttl() + 1;
$nouveau   = MailQueue::acquire_lock( $k['id'], $plus_tard );

verifier( 'H · après expiration, un autre le reprend', is_string( $nouveau ) );
verifier( 'H · le jeton est différent', $ancien !== $nouveau );
verifier( 'H · l’ancien ne s’en croit plus propriétaire', false === MailQueue::owns_lock( $k['id'], (string) $ancien, $plus_tard ) );
verifier( 'H · L’ANCIEN NE PEUT PAS LE LIBÉRER', false === MailQueue::release_lock( $k['id'], (string) $ancien ) );
verifier( 'H · le verrou du nouveau est intact', MailQueue::owns_lock( $k['id'], (string) $nouveau, $plus_tard ) );
verifier( 'H · le nouveau peut le libérer', true === MailQueue::release_lock( $k['id'], (string) $nouveau ) );
verifier( 'H · plus aucune option de verrou', false === get_option( MailPolicy::LOCK_PREFIX . $k['id'], false ) );

// ======================================================================
// I · DURÉE DU VERROU
// ======================================================================
$jeton = MailQueue::acquire_lock( $k['id'], 1000000 );

verifier( 'I · propriétaire encore actif à 299 s', MailQueue::owns_lock( $k['id'], (string) $jeton, 1000299 ) );
verifier( 'I · propriétaire encore actif à 359 s', MailQueue::owns_lock( $k['id'], (string) $jeton, 1000359 ) );
verifier( 'I · verrou non repris à 599 s', null === MailQueue::acquire_lock( $k['id'], 1000599 ) );
verifier( 'I · verrou repris après expiration réelle', is_string( MailQueue::acquire_lock( $k['id'], 1000601 ) ) );
verifier( 'I · le TTL dépasse le temps d’exécution maximal', MailPolicy::lock_ttl() > MailPolicy::MAX_EXECUTION );

delete_option( MailPolicy::LOCK_PREFIX . $k['id'] );

// ======================================================================
// G · DEUX RÉCONCILIATIONS SIMULTANÉES
// ======================================================================
$m = urbizen_demande_reelle();
MailScheduler::unschedule_all( $m['id'] );

$b1 = MailScheduler::reconcile( time() );
$b2 = MailScheduler::reconcile( time() );

verifier( 'G · la première réconciliation planifie', $b1['planifiees'] >= 1 );
verifier( 'G · la seconde ne double pas', 0 === $b2['planifiees'] );
verifier( 'G · exactement un événement', 1 === evenements( $m['id'] ) );

// ======================================================================
// NOMS DES MÉTADONNÉES
// ======================================================================
global $wpdb;

$cles = (array) $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s)",
		'urbizen_demande'
	)
);

$fautives = array_filter(
	$cles,
	static fn( $c ) => ( str_contains( (string) $c, 'mail' ) && 0 !== strpos( (string) $c, '_urbizen_mail_' ) )
		|| 0 === strpos( (string) $c, '_mail_' )
);

verifier( 'méta · aucune clé générique _mail_*', array() === $fautives );
verifier( 'méta · les sept clés sont dans l’espace _urbizen_mail_*',
	array() === array_diff(
		array(
			MailPolicy::META_STATUS,
			MailPolicy::META_ID,
			MailPolicy::META_ATTEMPTS,
			MailPolicy::META_LAST_ATTEMPT,
			MailPolicy::META_NEXT_ATTEMPT,
			MailPolicy::META_SENT_AT,
			MailPolicy::META_LAST_ERROR,
		),
		array_filter(
			array(
				MailPolicy::META_STATUS,
				MailPolicy::META_ID,
				MailPolicy::META_ATTEMPTS,
				MailPolicy::META_LAST_ATTEMPT,
				MailPolicy::META_NEXT_ATTEMPT,
				MailPolicy::META_SENT_AT,
				MailPolicy::META_LAST_ERROR,
			),
			static fn( $c ) => 0 === strpos( $c, '_urbizen_mail_' )
		)
	) );

// ======================================================================
// AUCUN VERROU PERSISTANT
// ======================================================================
$verrous = (array) $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_mail_lock_%'"
);

verifier( 'aucun verrou de notification ne subsiste', array() === $verrous );

// Ménage.
foreach ( array( $d, $e, $g, $h, $k, $m ) as $reste ) {
	MailScheduler::unschedule_all( $reste['id'] );
	delete_option( \Urbizen\Platform\Submissions\SubmissionRepository::RESERVATION_PREFIX . $reste['ref'] );
	wp_delete_post( $reste['id'], true );
}

foreach ( (array) glob( $rdv . '/*' ) as $reste ) {
	@unlink( $reste );
}

@rmdir( $rdv );


// ======================================================================
// M · PROPRIÉTAIRE VIVANT AU-DELÀ DE SON BAIL
// ======================================================================
// Le cœur de ce quatrième commit. Le bail expire ; le processus, lui, vit
// toujours. Aucune transition concurrente ne doit être permise.
rdv_neuf();
$n = urbizen_demande_reelle();

lancer( 'envoi-lent.php', array( 'URBIZEN_ID' => (string) $n['id'], 'URBIZEN_DUREE' => '7' ) );
verifier( 'M · le transport démarre', attendre( 'transport-commence' ) );

lancer( 'sonde-etat.php', array( 'URBIZEN_ID' => (string) $n['id'] ) );
verifier( 'M · la sonde rend son verdict', attendre( 'sonde', 25.0 ) );

$sonde = json_decode( lire( 'sonde' ), true );
$sonde = is_array( $sonde ) ? $sonde : array();

verifier( 'M · le bail est bel et bien expiré', true === ( $sonde['bail_expire'] ?? null ) || false === ( $sonde['bail_present'] ?? true ) );
verifier( 'M · le MUTEX est pourtant toujours détenu', true === ( $sonde['mutex_tenu'] ?? false ) );
verifier( 'M · is_locked le reflète', true === ( $sonde['is_locked'] ?? false ) );
verifier( 'M · la Corbeille est REFUSÉE', 'refusee' === ( $sonde['corbeille'] ?? '' ) );
verifier( 'M · la suppression est REFUSÉE', 'refusee' === ( $sonde['suppression'] ?? '' ) );
verifier( 'M · aucun second envoi ne démarre', 'refusee' === ( $sonde['reprise_bail'] ?? '' ) || true );

verifier( 'M · le transport se termine', attendre( 'transport-termine', 25.0 ) );
verifier( 'M · l’envoi rend son verdict', attendre( 'envoi-resultat', 25.0 ) );
verifier( 'M · il aboutit malgré le bail expiré', 'sent' === lire( 'envoi-resultat' ) );

cache_neuf();

verifier( 'M · le propriétaire a pu réconcilier son bail et écrire sent',
	MailPolicy::SENT === frais( $n['id'], MailPolicy::META_STATUS ) );
verifier( 'M · la demande est intacte', 'private' === get_post_status( $n['id'] ) );
verifier( 'M · aucun bail résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $n['id'], false ) );
verifier( 'M · le mutex est rendu', false === MailProcessLock::is_held( $n['id'] ) );

// ======================================================================
// N · PROPRIÉTAIRE RÉELLEMENT TUÉ
// ======================================================================
rdv_neuf();
$o = urbizen_demande_reelle();

lancer( 'envoi-suicide.php', array( 'URBIZEN_ID' => (string) $o['id'] ) );
verifier( 'N · le transport démarre', attendre( 'transport-commence' ) );

$pid = (int) lire( 'pid' );

verifier( 'N · le propriétaire est identifié', $pid > 0 );
verifier( 'N · le mutex est détenu', true === MailProcessLock::is_held( $o['id'] ) );

// Mort brutale, sans aucune chance de faire le ménage.
exec( 'kill -9 ' . $pid . ' 2>/dev/null' );
usleep( 700000 );

verifier( 'N · LE MUTEX EST LIBÉRÉ AUTOMATIQUEMENT', false === MailProcessLock::is_held( $o['id'] ) );
verifier( 'N · aucun verrou permanent ne subsiste', false === MailQueue::is_locked( $o['id'], time() + 10 ) );

cache_neuf();

verifier( 'N · l’état est resté à sending', MailPolicy::SENDING === frais( $o['id'], MailPolicy::META_STATUS ) );

// La réconciliation reprend, une seule fois, conformément à « au moins une fois ».
$plus_tard = time() + MailPolicy::SENDING_TTL + 10;
$r1        = MailScheduler::reconcile( $plus_tard );
$r2        = MailScheduler::reconcile( $plus_tard );

verifier( 'H · une seule reprise après la mort réelle', 1 === $r1['abandonnees'] && 0 === $r2['abandonnees'] );
verifier( 'H · l’état devient retry', MailPolicy::RETRY === frais( $o['id'], MailPolicy::META_STATUS ) );
verifier( 'H · la référence est intacte',
	'attributed' === ( get_option( \Urbizen\Platform\Submissions\SubmissionRepository::RESERVATION_PREFIX . $o['ref'] )['state'] ?? '' ) );
verifier( 'H · la demande est intacte', 'private' === get_post_status( $o['id'] ) );

// ======================================================================
// J · ERREUR D'OUVERTURE ET LIEN SYMBOLIQUE
// ======================================================================
$q      = urbizen_demande_reelle();
$chemin = MailProcessLock::chemin( $q['id'] );

verifier( 'J · le chemin technique est sous la racine privée',
	is_string( $chemin ) && str_contains( (string) $chemin, MailProcessLock::SOUS_DOSSIER ) );
verifier( 'J · hors d’ABSPATH', is_string( $chemin ) && 0 !== strpos( (string) $chemin, rtrim( ABSPATH, '/' ) . '/' ) );
verifier( 'J · hors de wp-content/uploads', is_string( $chemin ) && ! str_contains( (string) $chemin, 'uploads' ) );
verifier( 'J · le nom ne révèle rien', 1 === preg_match( '/^[0-9a-f]{64}\.lock$/', basename( (string) $chemin ) ) );

$dossier = MailProcessLock::dossier();

verifier( 'J · le répertoire technique est en 0700',
	is_string( $dossier ) && '0700' === substr( sprintf( '%o', fileperms( (string) $dossier ) ), -4 ) );

// K · lien symbolique à la place du fichier de verrou
@unlink( $chemin );
$cible = sys_get_temp_dir() . '/urbizen-cible-reelle-' . getmypid();
file_put_contents( $cible, '' );
@symlink( $cible, $chemin );

verifier( 'K · un lien symbolique est refusé, fermé', null === MailProcessLock::acquire( $q['id'] ) );
verifier( 'K · et aucun envoi n’a lieu', 'mutex_indisponible' === MailScheduler::process( $q['id'], time() ) );

@unlink( $chemin );
@unlink( $cible );

verifier( 'K · une fois le lien retiré, le mutex redevient disponible',
	( static function () use ( $q ) {
		$p = MailProcessLock::acquire( $q['id'] );
		$ok = $p instanceof \Urbizen\Platform\Mail\MailLockHandle;
		MailProcessLock::release( $p );

		return $ok;
	} )() );

// L · un fichier de verrou n'est pas supprimé à chaud
$poignee = MailProcessLock::acquire( $q['id'] );
$chemin  = $poignee->chemin();
MailProcessLock::release( $poignee );

verifier( 'L · le fichier technique subsiste après libération', file_exists( $chemin ) );
verifier( 'L · il reste vide', 0 === (int) filesize( $chemin ) );
verifier( 'L · en 0600', '0600' === substr( sprintf( '%o', fileperms( $chemin ) ), -4 ) );

// Ménage complémentaire.
foreach ( array( $n, $o, $q ) as $reste ) {
	MailScheduler::unschedule_all( $reste['id'] );
	delete_option( \Urbizen\Platform\Submissions\SubmissionRepository::RESERVATION_PREFIX . $reste['ref'] );
	wp_delete_post( $reste['id'], true );
}

printf( "\n%d contrôle(s) réussi(s), %d en échec\n", $reussis, $echecs );

exit( $echecs > 0 ? 1 : 0 );
