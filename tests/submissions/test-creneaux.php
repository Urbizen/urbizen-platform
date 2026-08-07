<?php
/**
 * Banc d'essai des créneaux de notification.
 *
 * Ce que ce banc défend tient en deux propositions, et elles se contredisent
 * juste assez pour mériter des contrôles :
 *
 * 1. **Rien ne bouge pour l'existant.** Le créneau administratif écrit dans
 *    les clés historiques, verrouille sous le même nom, et pose l'événement
 *    cron avec les mêmes arguments. Une demande enregistrée avant ce
 *    changement, un verrou en cours, un événement déjà inscrit : tous restent
 *    lisibles et traitables, sans reprise de données.
 * 2. **Un second créneau est réellement séparé.** États, verrous, mutex,
 *    événements : rien n'est partagé. L'un peut échouer, être retenté,
 *    annulé, sans que l'autre en sache quoi que ce soit.
 *
 * La deuxième proposition est la raison d'être du travail ; la première est ce
 * qui permet de le livrer sans migration. Un banc qui n'éprouverait que la
 * seconde laisserait passer exactement la régression qui coûterait cher.
 *
 * Aucun courriel n'est émis. Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailProcessLock;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Mail\NotificationSlot;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\TrashGuard;

/**
 * Repart d'un état propre.
 */
function neuf(): void {
	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
	FileCleaner::reset();
	TrashGuard::register();
	MailScheduler::register();
	update_option( 'admin_email', 'dossiers@urbizen.test' );
}

/**
 * Un contenu de demande, sans passer par la route.
 *
 * Ce banc éprouve la file, pas la soumission : un post suffit, et le rester
 * évite de faire dépendre les contrôles de créneaux d'un pipeline entier.
 *
 * @return int
 */
function post_demande(): int {
	return (int) wp_insert_post(
		array(
			'post_type'   => SubmissionPostType::POST_TYPE,
			'post_title'  => 'Demande fictive',
			'post_status' => 'private',
		)
	);
}

$admin  = NotificationSlot::admin( 42 );
$client = NotificationSlot::client( 42 );

// ======================================================================
// 1 · ADRESSAGE — le créneau historique ne se distingue de rien
// ======================================================================

check( '1 · le créneau administratif rend la clé de méta historique, nue',
	MailPolicy::META_STATUS === $admin->cle( MailPolicy::META_STATUS ) );
check( '1 · sa clé de verrou est l’identifiant seul',
	'42' === $admin->cle_verrou() );
check( '1 · ses arguments cron sont l’identifiant seul',
	array( 42 ) === $admin->args_cron() );
check( '1 · il se reconnaît comme administratif', true === $admin->est_admin() );

check( '2 · le créneau client suffixe la clé de méta',
	MailPolicy::META_STATUS . '__customer_acknowledgement' === $client->cle( MailPolicy::META_STATUS ) );
check( '2 · sa clé de verrou porte l’identifiant ET le type',
	'42__customer_acknowledgement' === $client->cle_verrou() );
check( '2 · ses arguments cron portent le type',
	array( 42, 'customer_acknowledgement' ) === $client->args_cron() );
check( '2 · il ne se prend pas pour l’administratif', false === $client->est_admin() );

check( '3 · deux créneaux ne partagent aucune clé de méta',
	$admin->cle( MailPolicy::META_ID ) !== $client->cle( MailPolicy::META_ID )
	&& $admin->cle( MailPolicy::META_ATTEMPTS ) !== $client->cle( MailPolicy::META_ATTEMPTS ) );
check( '3 · ni aucune clé de verrou', $admin->cle_verrou() !== $client->cle_verrou() );
check( '3 · l’idempotence distingue les deux messages d’une même référence',
	'URB-2026-0001:admin_notification' === $admin->idempotence( 'URB-2026-0001' )
	&& 'URB-2026-0001:customer_acknowledgement' === $client->idempotence( 'URB-2026-0001' ) );

// ======================================================================
// 2 · LISTE BLANCHE — un type inconnu n'est jamais adressable
// ======================================================================

check( '4 · un type hors liste ne produit aucun créneau',
	null === NotificationSlot::pour( 42, 'facture' ) );
check( '4 · ni la chaîne vide', null === NotificationSlot::pour( 42, '' ) );
check( '4 · les deux types connus, eux, en produisent',
	null !== NotificationSlot::pour( 42, NotificationSlot::ADMIN )
	&& null !== NotificationSlot::pour( 42, NotificationSlot::CLIENT ) );
check( '4 · un type inconnu ne retombe PAS silencieusement sur l’administratif',
	null === NotificationSlot::pour( 42, 'admin' ) );

// ======================================================================
// 3 · CRON — les événements anciens restent traitables
// ======================================================================

check( '5 · un événement sans type désigne la notification interne',
	NotificationSlot::depuis_cron( 42 )->est_admin() );
check( '5 · un événement au type corrompu aussi, plutôt que d’être abandonné',
	NotificationSlot::depuis_cron( 42, 'n’importe quoi' )->est_admin() );
check( '5 · un type non textuel également',
	NotificationSlot::depuis_cron( 42, array( 'x' ) )->est_admin() );
check( '5 · un événement typé désigne bien son créneau',
	NotificationSlot::CLIENT === NotificationSlot::depuis_cron( 42, 'customer_acknowledgement' )->type );

neuf();
$id = post_demande();

// L'événement historique — un seul argument — est celui que pose encore le
// créneau administratif. C'est ce qui rend invisibles les doublons.
MailScheduler::schedule_unique( $id );
check( '6 · le créneau administratif pose l’événement à UN argument',
	false !== wp_next_scheduled( MailPolicy::EVENT, array( $id ) ) );

// Reposer n'en crée pas un second : `wp_next_scheduled()` reconnaît les
// arguments, donc l'événement déjà inscrit avant ce changement est trouvé.
MailScheduler::schedule_unique( $id );
check( '6 · le reposer n’en crée pas un second',
	1 === count( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ?? array() ) );

MailScheduler::schedule_unique( $id, null, null, NotificationSlot::client( $id ) );
check( '7 · le créneau client pose SON propre événement, à deux arguments',
	false !== wp_next_scheduled( MailPolicy::EVENT, array( $id, 'customer_acknowledgement' ) ) );
check( '7 · les deux coexistent',
	2 === count( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ?? array() ) );

$retires = MailScheduler::unschedule_all( $id, NotificationSlot::client( $id ) );
check( '8 · retirer le créneau client ne retire que le sien', 1 === $retires );
check( '8 · l’événement administratif survit',
	false !== wp_next_scheduled( MailPolicy::EVENT, array( $id ) ) );

MailScheduler::unschedule_all( $id );
check( '8 · et le retirer à son tour vide la file',
	array() === ( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ?? array() ) );

// ======================================================================
// 4 · ÉTATS — la façade statique écrit exactement où elle écrivait
// ======================================================================

neuf();
$id = post_demande();

MailQueue::create_pending( $id, 1000 );

check( '9 · sans créneau, l’état s’écrit dans la clé historique',
	MailPolicy::PENDING === (string) get_post_meta( $id, MailPolicy::META_STATUS, true ) );
check( '9 · aucune clé suffixée n’apparaît',
	'' === (string) get_post_meta( $id, MailPolicy::META_STATUS . '__admin_notification', true ) );
check( '9 · et `state()` relit bien cet état',
	MailPolicy::PENDING === MailQueue::state( $id )['status'] );

// Le même appel, créneau administratif explicite : rigoureusement identique.
$avant = MailQueue::state( $id );
check( '10 · créneau administratif explicite ≡ appel sans créneau',
	$avant === MailQueue::state( $id, NotificationSlot::admin( $id ) ) );

// ======================================================================
// 5 · INDÉPENDANCE — deux créneaux, deux sorts
// ======================================================================

neuf();
$id     = post_demande();
$client = NotificationSlot::client( $id );

MailQueue::create_pending( $id, 1000 );
MailQueue::create_pending( $id, 1000, $client );

check( '11 · les deux créneaux sont en attente',
	MailPolicy::PENDING === MailQueue::state( $id )['status']
	&& MailPolicy::PENDING === MailQueue::state( $id, $client )['status'] );
check( '11 · avec des identifiants de notification distincts',
	'' !== MailQueue::state( $id )['notification_id']
	&& MailQueue::state( $id )['notification_id'] !== MailQueue::state( $id, $client )['notification_id'] );

MailQueue::mark_sent( $id, 2000 );

check( '12 · marquer l’administratif envoyé laisse le client en attente',
	MailPolicy::SENT === MailQueue::state( $id )['status']
	&& MailPolicy::PENDING === MailQueue::state( $id, $client )['status'] );

// La séquence réelle : le compteur de tentatives est écrit au passage en
// `sending`, pas à l'échec. L'éprouver autrement ne prouverait rien.
MailQueue::mark_sending( $id, 1, 2000, $client );

check( '13 · passer le client en envoi laisse l’administratif envoyé',
	MailPolicy::SENT === MailQueue::state( $id )['status']
	&& MailPolicy::SENDING === MailQueue::state( $id, $client )['status'] );

MailQueue::mark_failure( $id, 1, 'transport_refused', 2000, $client );

check( '13 · l’échec du client ne touche pas l’administratif',
	MailPolicy::SENT === MailQueue::state( $id )['status'] );
check( '13 · le client, lui, est en reprise',
	MailPolicy::RETRY === MailQueue::state( $id, $client )['status'] );
check( '13 · avec sa propre tentative comptée',
	0 === MailQueue::state( $id )['attempts'] && 1 === MailQueue::state( $id, $client )['attempts'] );
check( '13 · et sa propre erreur',
	'' === MailQueue::state( $id )['last_error']
	&& 'transport_refused' === MailQueue::state( $id, $client )['last_error'] );

MailQueue::cancel( $id, 'demande_corbeille', $client );

check( '14 · annuler le client n’annule pas l’administratif',
	MailPolicy::SENT === MailQueue::state( $id )['status']
	&& MailPolicy::CANCELLED === MailQueue::state( $id, $client )['status'] );

// ======================================================================
// 6 · VERROUS — le bail d'option est propre au créneau
// ======================================================================

neuf();
$id     = post_demande();
$client = NotificationSlot::client( $id );

$jeton_admin = MailQueue::acquire_lock( $id, 1000 );

check( '15 · le bail administratif est pris', is_string( $jeton_admin ) && '' !== $jeton_admin );
check( '15 · sous la clé d’option historique',
	is_array( get_option( MailPolicy::LOCK_PREFIX . $id, null ) ) );
check( '15 · le reprendre échoue', null === MailQueue::acquire_lock( $id, 1000 ) );

$jeton_client = MailQueue::acquire_lock( $id, 1000, $client );

check( '16 · le bail client se prend malgré l’administratif détenu',
	is_string( $jeton_client ) && '' !== $jeton_client );
check( '16 · sous sa propre clé d’option',
	is_array( get_option( MailPolicy::LOCK_PREFIX . $id . '__customer_acknowledgement', null ) ) );
check( '16 · les deux jetons diffèrent', $jeton_admin !== $jeton_client );

check( '17 · chaque créneau ne reconnaît que son jeton',
	MailQueue::owns_lock( $id, $jeton_admin, 1000 )
	&& ! MailQueue::owns_lock( $id, $jeton_client, 1000 )
	&& MailQueue::owns_lock( $id, $jeton_client, 1000, $client )
	&& ! MailQueue::owns_lock( $id, $jeton_admin, 1000, $client ) );

check( '18 · rendre le bail client ne rend pas l’administratif',
	MailQueue::release_lock( $id, $jeton_client, $client )
	&& null === get_option( MailPolicy::LOCK_PREFIX . $id . '__customer_acknowledgement', null )
	&& is_array( get_option( MailPolicy::LOCK_PREFIX . $id, null ) ) );

MailQueue::release_lock( $id, $jeton_admin );

// ======================================================================
// 7 · MUTEX — le fichier technique aussi est propre au créneau
// ======================================================================

neuf();
$id     = post_demande();
$client = NotificationSlot::client( $id );

$chemin_admin = MailProcessLock::chemin( $id );

check( '19 · le mutex administratif garde son chemin historique',
	$chemin_admin === MailProcessLock::chemin( $id, NotificationSlot::admin( $id ) ) );
check( '19 · le mutex client en a un autre',
	$chemin_admin !== MailProcessLock::chemin( $id, $client ) );

$poignee_admin = MailProcessLock::acquire( $id );

check( '20 · le mutex administratif est détenu', null !== $poignee_admin );
check( '20 · il se signale comme tel', true === MailProcessLock::is_held( $id ) );
check( '20 · le mutex client, lui, est libre',
	false === MailProcessLock::is_held( $id, $client ) );

$poignee_client = MailProcessLock::acquire( $id, $client );

check( '21 · et il se prend, sans attendre l’administratif', null !== $poignee_client );

MailProcessLock::release( $poignee_client );
MailProcessLock::release( $poignee_admin );

// `is_locked()` s'appuie sur le mutex ET sur le bail : les deux couches
// doivent être interrogées pour le bon créneau, faute de quoi un envoi client
// paraîtrait en cours parce qu'un envoi administratif l'est.
neuf();
$id     = post_demande();
$client = NotificationSlot::client( $id );

MailQueue::acquire_lock( $id, 1000 );

check( '22 · l’administratif est vu verrouillé', true === MailQueue::is_locked( $id, 1000 ) );
check( '22 · le client ne l’est pas', false === MailQueue::is_locked( $id, 1000, $client ) );

// ======================================================================
// 8 · SECTION CRITIQUE — with_lock() transmet le créneau de bout en bout
// ======================================================================

neuf();
$id     = post_demande();
$client = NotificationSlot::client( $id );

$r = MailQueue::with_lock(
	$id,
	static function () use ( $id, $client ) {
		// Sous le verrou administratif, le créneau client doit rester
		// entièrement disponible — mutex compris.
		$imbrique = MailQueue::with_lock( $id, static fn() => 'client_travaille', 1000, $client );

		return $imbrique['ok'] && 'client_travaille' === $imbrique['valeur'];
	},
	1000
);

check( '23 · la section critique administrative aboutit', ! empty( $r['ok'] ) );
check( '23 · et le créneau client y travaille en parallèle, sans interblocage',
	true === $r['valeur'] );

check( '24 · les deux baux sont rendus après coup',
	null === get_option( MailPolicy::LOCK_PREFIX . $id, null )
	&& null === get_option( MailPolicy::LOCK_PREFIX . $id . '__customer_acknowledgement', null ) );
check( '24 · et les deux mutex aussi',
	false === MailProcessLock::is_held( $id )
	&& false === MailProcessLock::is_held( $id, $client ) );

// ======================================================================
// 9 · SOURCE UNIQUE — aucune composition de clé n'est écrite en double
// ======================================================================

$source_queue = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailQueue.php' );

check( '25 · la file ne compose plus aucune clé de méta en direct',
	1 !== preg_match( '/get_post_meta\(\s*\$id,\s*MailPolicy::META_/', $source_queue )
	&& 1 !== preg_match( '/=>\s*MailPolicy::META_/', $source_queue ) );
check( '25 · ni aucune clé de verrou',
	! str_contains( $source_queue, 'MailPolicy::LOCK_PREFIX . $id' ) );
check( '25 · toute clé passe par le créneau',
	str_contains( $source_queue, '$slot->cle( MailPolicy::' )
	&& str_contains( $source_queue, '$slot->cle_verrou()' ) );

$source_slot = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/NotificationSlot.php' );

check( '26 · le suffixe des clés n’est écrit qu’une fois par usage',
	2 === substr_count( $source_slot, "'__' . \$this->type" ) );

exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
