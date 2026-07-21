<?php
/**
 * Banc d'essai de la notification administrative.
 *
 * Le principe que ce banc défend d'abord : **l'échec d'un courriel n'invalide
 * jamais une demande.** Un dossier reçu reste reçu, que le transport de
 * messagerie fonctionne, refuse, ou disparaisse au milieu d'un envoi.
 *
 * Le second : rien ne part d'une demande qui n'est pas, à l'instant même,
 * pleinement cohérente — statut natif privé, transaction validée, référence
 * attribuée et rattachée, documents dans un état final, aucune transition de
 * Corbeille en cours.
 *
 * Aucun courriel n'est émis : le transport est un double qui enregistre.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';


use Urbizen\Platform\Admin\SubmissionsAdmin;
use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\MailRenderer;
use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Mail\MailTransport;
use Urbizen\Platform\Mail\WordPressMailTransport;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TransactionRecovery;
use Urbizen\Platform\Submissions\TrashGuard;

/**
 * Transport d'essai : il n'envoie rien, il retient.
 */
final class TransportEssai implements MailTransport {

	/** @var array<int, array<string, mixed>> */
	public array $envois = array();

	/** @var bool|string Vrai, faux, ou 'exception'. */
	public $reponse = true;

	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		$this->envois[] = array(
			'to'      => $destinataire,
			'subject' => $sujet,
			'body'    => $corps,
			'headers' => $entetes,
		);

		if ( 'exception' === $this->reponse ) {
			throw new \RuntimeException( 'transport en panne' );
		}

		return true === $this->reponse
			? array( 'ok' => true, 'code' => 'accepted' )
			: array( 'ok' => false, 'code' => 'transport_refused' );
	}
}

$transport = new TransportEssai();

/**
 * Repart d'un état propre, transport neuf.
 */
function neuf(): void {
	global $transport;

	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
	FileCleaner::reset();
	TrashGuard::register();
	MailScheduler::register();

	$transport          = new TransportEssai();
	$transport->reponse = true;
	MailScheduler::set_transport( $transport );

	// Des délais courts : on éprouve la politique, pas la patience.
	add_filter( 'urbizen_mail_retry_delays', static fn() => array( 1 => 0, 2 => 10, 3 => 20, 4 => 30, 5 => 40 ) );
	update_option( 'admin_email', 'dossiers@urbizen.test' );
}

/**
 * Crée une demande finalisée, avec ou sans document.
 *
 * @param int $documents Nombre de documents.
 * @return array{id:int,ref:string}
 */
function demande( int $documents = 0 ): array {
	// La politique de dépôt plafonne à dix documents par bloc : au-delà, on
	// répartit, ce qui éprouve du même coup le regroupement du message.
	$blocs   = array( 'photos', 'plan_terrain' );
	$lots    = array();
	$restant = $documents;

	foreach ( $blocs as $bloc ) {
		$part = min( 10, $restant );

		for ( $i = 0; $i < $part; $i++ ) {
			$lots[ $bloc ][] = array( 'doc-' . $bloc . '-' . $i . '.jpg', fx_copie( fx_jpeg() ) );
		}

		$restant -= $part;
	}

	$files = array();

	foreach ( $lots as $bloc => $fichiers ) {
		$files += fx_files( $bloc, $fichiers );
	}

	$post = soumission();
	$r    = array() === $files ? traiter( $post ) : traiter( $post, $files );

	return array( 'id' => $r->id(), 'ref' => (string) get_post_meta( $r->id(), '_urbizen_reference', true ) );
}

// ======================================================================
// 1 · LA NOTIFICATION FAIT PARTIE DE LA FINALISATION
// ======================================================================
neuf();
$d = demande();

check( '1 · la demande est reçue', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( '1 · transaction committed', 'committed' === ( SubmissionRepository::transaction( $d['id'] )['state'] ?? '' ) );
check( '1 · référence attributed',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );
check( '1 · mail_status = pending', MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '1 · un identifiant de notification est présent',
	1 === preg_match( '/^[0-9a-f]{32}$/', (string) get_post_meta( $d['id'], MailPolicy::META_ID, true ) ) );
check( '1 · aucun courriel n’est encore parti', array() === $transport->envois );
check( '1 · un événement unique est planifié', false !== wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '1 · un seul événement, même après plusieurs appels',
	MailScheduler::schedule( $d['id'] ) && 1 === count( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ) );

// --- l'écriture de l'état pending échoue → la finalisation échoue ---
neuf();
$GLOBALS['wpd_meta_fail'] = MailPolicy::META_ID;
$r                        = traiter( soumission() );
$GLOBALS['wpd_meta_fail'] = '';

check( '1 · sans notification, la finalisation échoue', ! $r->is_success() );
check( '1 · aucune demande ne subsiste', 0 === count( $GLOBALS['wpd_posts'] ) );
check( '1 · aucune référence attribuée à tort',
	array() === array_filter(
		array_keys( $GLOBALS['wpd_options'] ),
		static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX )
			&& 'attributed' === ( get_option( $c )['state'] ?? '' )
	) );

// ======================================================================
// 2 · PANNE ENTRE FINALISATION ET PLANIFICATION
// ======================================================================
neuf();
$d = demande();

// L'événement disparaît, comme si le processus était mort juste avant.
MailScheduler::unschedule( $d['id'] );

check( '2 · plus aucun événement', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '2 · la demande reste reçue', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $d['id'], '_urbizen_status', true ) );

$bilan = MailScheduler::reconcile( wpd_now() );

check( '2 · la réconciliation la retrouve', 1 === $bilan['planifiees'] );
check( '2 · l’événement est de nouveau planifié', false !== wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '2 · elle est idempotente', 0 === MailScheduler::reconcile( wpd_now() )['planifiees'] );

// ======================================================================
// 3 · ENVOI NOMINAL
// ======================================================================
neuf();
$d = demande( 2 );

check( '3 · le traitement aboutit', 'sent' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '3 · un seul envoi', 1 === count( $transport->envois ) );
check( '3 · mail_status = sent', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '3 · sent_at est renseigné', '' !== get_post_meta( $d['id'], MailPolicy::META_SENT_AT, true ) );
check( '3 · une tentative comptée', 1 === (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) );
check( '3 · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );
check( '3 · le destinataire est l’adresse d’administration', 'dossiers@urbizen.test' === $transport->envois[0]['to'] );

// --- après sent, plus rien ne repart tout seul ---
check( '3 · un second traitement ne renvoie rien', 'mail_status_non_envoyable' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '3 · toujours un seul envoi', 1 === count( $transport->envois ) );

$bilan = MailScheduler::reconcile( wpd_now() + 86400 );

check( '3 · la réconciliation ne le rejoue pas', 0 === array_sum( $bilan ) );
check( '3 · la référence reste attribuée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );
check( '3 · les documents sont intacts', 2 === fx_compte_fichiers() );

// ======================================================================
// 4 · DESTINATAIRE
// ======================================================================
neuf();
$d = demande();

check( '4 · par défaut, admin_email', 'dossiers@urbizen.test' === MailPolicy::recipient() );

add_filter( 'urbizen_submission_recipient', static fn() => 'filtre@urbizen.test' );

check( '4 · le filtre prime sur admin_email', 'filtre@urbizen.test' === MailPolicy::recipient() );

wpd_clear_filter( 'urbizen_submission_recipient' );
add_filter( 'urbizen_submission_recipient', static fn() => 'pas-une-adresse' );

check( '4 · un filtre invalide est ignoré', 'dossiers@urbizen.test' === MailPolicy::recipient() );

wpd_clear_filter( 'urbizen_submission_recipient' );
update_option( 'admin_email', 'pas-une-adresse-non-plus' );

check( '4 · un admin_email invalide ne passe pas', '' === MailPolicy::recipient() );
check( '4 · et l’envoi est refusé, fermé', 'recipient_unavailable' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '4 · aucun envoi', array() === $transport->envois );

update_option( 'admin_email', 'dossiers@urbizen.test' );

// Une donnée de formulaire ne peut jamais atteindre le destinataire.
neuf();
$d = demande();
$_POST['email'] = 'attaquant@exemple.test';
MailScheduler::process( $d['id'], wpd_now() );

check( '4 · une donnée de formulaire ne choisit pas le destinataire',
	'dossiers@urbizen.test' === $transport->envois[0]['to'] );

$_POST = array();

// ======================================================================
// 5 · ÉLIGIBILITÉ
// ======================================================================
$cas = array(
	'post_status_inattendu'       => static function ( $id ) { get_post( $id )->post_status = 'draft'; },
	'statut_metier_non_final'     => static function ( $id ) { update_post_meta( $id, '_urbizen_status', 'processing' ); },
	'documents_non_finaux'        => static function ( $id ) { update_post_meta( $id, '_urbizen_files_status', 'pending' ); },
	'transaction_non_validee'     => static function ( $id ) {
		$t          = SubmissionRepository::transaction( $id );
		$t['state'] = 'processing';
		update_post_meta( $id, '_urbizen_transaction', (string) wp_json_encode( $t ) );
	},
	'reference_non_attribuee'     => static function ( $id ) {
		$ref          = (string) get_post_meta( $id, '_urbizen_reference', true );
		$res          = get_option( SubmissionRepository::RESERVATION_PREFIX . $ref );
		$res['state'] = 'reserved';
		update_option( SubmissionRepository::RESERVATION_PREFIX . $ref, $res );
	},
	'reservation_autre_demande'   => static function ( $id ) {
		$ref         = (string) get_post_meta( $id, '_urbizen_reference', true );
		$res         = get_option( SubmissionRepository::RESERVATION_PREFIX . $ref );
		$res['post'] = $id + 999;
		update_option( SubmissionRepository::RESERVATION_PREFIX . $ref, $res );
	},
	'reference_divergente'        => static function ( $id ) { update_post_meta( $id, '_urbizen_reference', 'URB-2026-9999' ); },
	'transition_corbeille_active' => static function ( $id ) {
		update_post_meta( $id, TrashGuard::TRANSITION, (string) wp_json_encode( array( 'state' => TrashGuard::PREPARED ) ) );
	},
);

foreach ( $cas as $attendu => $saboter ) {
	neuf();
	$d = demande( 1 );
	$saboter( $d['id'] );

	check( "5 · $attendu → refus", $attendu === MailScheduler::process( $d['id'], wpd_now() ) );
	check( "5 · $attendu → aucun envoi", array() === $transport->envois );
	check( "5 · $attendu → aucun document touché", 1 === fx_compte_fichiers() );
}

foreach ( array( 'deleting', 'delete_failed', 'recovery_failed', TransactionRecovery::INCOHERENT, TrashGuard::STATUS_TRASHED ) as $etat ) {
	neuf();
	$d = demande();
	update_post_meta( $d['id'], '_urbizen_status', $etat );

	check( "5 · état « $etat » → aucun envoi",
		'statut_metier_non_final' === MailScheduler::process( $d['id'], wpd_now() ) && array() === $transport->envois );
}

// Post inexistant.
neuf();

check( '5 · post inexistant → sans effet', 'post_absent' === MailScheduler::process( 4242, wpd_now() ) );
check( '5 · aucun envoi', array() === $transport->envois );

// ======================================================================
// 6 · REPRISES
// ======================================================================
neuf();
$d                  = demande();
$transport->reponse = false;

check( '6 · première tentative en échec', 'echec' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '6 · mail_status = retry', MailPolicy::RETRY === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '6 · une tentative comptée', 1 === (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) );
check( '6 · une échéance est fixée', '' !== get_post_meta( $d['id'], MailPolicy::META_NEXT_ATTEMPT, true ) );
check( '6 · un code technique est retenu', 'transport_refused' === get_post_meta( $d['id'], MailPolicy::META_LAST_ERROR, true ) );
check( '6 · la demande reste reçue', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $d['id'], '_urbizen_status', true ) );

for ( $i = 2; $i <= 5; $i++ ) {
	wpd_avancer( 60 );
	MailScheduler::process( $d['id'], wpd_now() );
}

check( '6 · cinq tentatives, puis abandon', MailPolicy::FAILED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '6 · exactement cinq tentatives', 5 === (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) );
check( '6 · cinq appels au transport, pas six', 5 === count( $transport->envois ) );
check( '6 · plus aucune échéance', '' === get_post_meta( $d['id'], MailPolicy::META_NEXT_ATTEMPT, true ) );

wpd_avancer( 86400 );

check( '6 · un failed ne se rejoue pas tout seul',
	'mail_status_non_envoyable' === MailScheduler::process( $d['id'], wpd_now() ) && 5 === count( $transport->envois ) );
check( '6 · la réconciliation ne le reprend pas', 0 === array_sum( MailScheduler::reconcile( wpd_now() ) ) );
check( '6 · la demande est toujours reçue', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $d['id'], '_urbizen_status', true ) );

// --- succès au troisième essai ---
foreach ( array( 2, 3, 5 ) as $rang_succes ) {
	neuf();
	$d                  = demande();
	$transport->reponse = false;

	for ( $i = 1; $i < $rang_succes; $i++ ) {
		MailScheduler::process( $d['id'], wpd_now() );
		wpd_avancer( 60 );
	}

	$transport->reponse = true;
	MailScheduler::process( $d['id'], wpd_now() );

	check( "6 · succès à la tentative $rang_succes", MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
	check( "6 · $rang_succes appel(s) au transport", $rang_succes === count( $transport->envois ) );
}

// --- l'échéance est respectée par la réconciliation ---
neuf();
$d                  = demande();
$transport->reponse = false;
MailScheduler::process( $d['id'], wpd_now() );
MailScheduler::unschedule( $d['id'] );

check( '6 · avant l’échéance, aucune reprise', 0 === MailScheduler::reconcile( wpd_now() )['reprises'] );

wpd_avancer( 60 );

check( '6 · après l’échéance, reprise planifiée', 1 === MailScheduler::reconcile( wpd_now() )['reprises'] );

// ======================================================================
// 7 · CONCURRENCE ET INTERRUPTIONS
// ======================================================================
neuf();
$d = demande();

// Un premier processus a pris le verrou et n'est pas revenu.
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'expires' => wpd_now() + 300 ), '', false );

check( '7 · le second constate le verrou', 'verrou_occupe' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '7 · et n’appelle pas le transport', array() === $transport->envois );
check( '7 · le verrou est en autoload = false', 'no' === wpd_autoload( MailPolicy::LOCK_PREFIX . $d['id'] ) );
check( '7 · et porte une expiration', 0 < (int) ( get_option( MailPolicy::LOCK_PREFIX . $d['id'] )['expires'] ?? 0 ) );

delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '7 · verrou rendu, l’envoi a lieu', 'sent' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '7 · un seul envoi', 1 === count( $transport->envois ) );

// --- panne après le passage à sending ---
neuf();
$d = demande();
MailQueue::mark_sending( $d['id'], 1, wpd_now() );

check( '7 · un sending frais n’est pas repris', 'mail_status_non_envoyable' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '7 · aucun envoi', array() === $transport->envois );

wpd_avancer( MailPolicy::SENDING_TTL + 1 );

check( '7 · un sending périmé est repris', 'sent' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '7 · le même identifiant de notification est réutilisé',
	1 === preg_match( '/^[0-9a-f]{32}$/', (string) get_post_meta( $d['id'], MailPolicy::META_ID, true ) ) );

// --- panne après wp_mail mais avant l'écriture de sent : politique documentée ---
neuf();
$d          = demande();
$identifiant = (string) get_post_meta( $d['id'], MailPolicy::META_ID, true );

// Le transport a accepté, puis le processus est mort : l'état est resté à
// `sending`. C'est le cas que `wp_mail` seul ne permet pas de trancher.
MailQueue::mark_sending( $d['id'], 1, wpd_now() );
wpd_avancer( MailPolicy::SENDING_TTL + 1 );
MailScheduler::reconcile( wpd_now() );

check( '7 · un envoi abandonné redevient traitable',
	in_array( get_post_meta( $d['id'], MailPolicy::META_STATUS, true ), array( MailPolicy::RETRY, MailPolicy::PENDING ), true ) );

MailScheduler::process( $d['id'], wpd_now() );

check( '7 · au moins une fois : le doublon est assumé', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '7 · et reste reconnaissable à son identifiant',
	str_contains( implode( ' ', $transport->envois[0]['headers'] ), $identifiant ) );

// --- deux processus concurrents ---
neuf();
$d = demande();

$premier = MailScheduler::process( $d['id'], wpd_now() );
$second  = MailScheduler::process( $d['id'], wpd_now() );

check( '7 · deux traitements successifs : un seul envoi', 1 === count( $transport->envois ) );
check( '7 · le second constate l’état déjà traité', 'sent' === $premier && 'mail_status_non_envoyable' === $second );

// --- l'événement exécuté deux fois ---
neuf();
$d = demande();
MailScheduler::handle_event( $d['id'] );
MailScheduler::handle_event( $d['id'] );

check( '7 · un événement rejoué n’envoie qu’une fois', 1 === count( $transport->envois ) );

// ======================================================================
// 8 · CONTENU
// ======================================================================
neuf();
$d       = demande( 0 );
$message = MailRenderer::render( $d['id'], wpd_now() );

check( '8 · sans document, le message est rendu', is_array( $message ) );
check( '8 · le sujet porte la référence', str_contains( $message['subject'], $d['ref'] ) );
check( '8 · le sujet ne porte aucune donnée personnelle',
	! str_contains( $message['subject'], 'Camille' ) && ! str_contains( $message['subject'], '@exemple' )
	&& ! str_contains( $message['subject'], '0100000000' ) );
check( '8 · sans document, aucun lien signé', ! str_contains( $message['body'], SignedLink::ACTION ) );
check( '8 · et le message le dit', str_contains( $message['body'], 'Aucun document' ) );
check( '8 · en-tête de type HTML', in_array( 'Content-Type: text/html; charset=UTF-8', $message['headers'], true ) );
check( '8 · en-tête d’identification technique',
	(bool) preg_grep( '/^X-Urbizen-Notification-ID: [0-9a-f]{32}$/', $message['headers'] ) );

neuf();
$d       = demande( 1 );
$message = MailRenderer::render( $d['id'], wpd_now() );

check( '8 · avec un document, un lien signé', 1 === substr_count( $message['body'], 'action=' . SignedLink::ACTION ) );

neuf();
$d       = demande( 20 );
$message = MailRenderer::render( $d['id'], wpd_now() );

check( '8 · vingt documents, vingt liens', 20 === substr_count( $message['body'], 'action=' . SignedLink::ACTION ) );

preg_match_all( '/file=([a-z0-9]+)/i', $message['body'], $ids );

check( '8 · les liens sont tous distincts', 20 === count( array_unique( $ids[1] ) ) );
check( '8 · les documents sont regroupés par bloc', str_contains( $message['body'], 'Photographies' ) );
check( '8 · le décompte est exact', str_contains( $message['body'], '20 document(s)' ) );

preg_match( '/expires=(\d+)/', $message['body'], $exp );

check( '8 · les liens expirent 14 jours après le rendu',
	( wpd_now() + ( 14 * DAY_IN_SECONDS ) ) === (int) ( $exp[1] ?? 0 ) );
check( '8 · aucune pièce jointe : le message n’en mentionne aucune',
	! str_contains( $message['body'], 'attachment' ) && ! str_contains( $message['body'], 'base64' ) );

// --- données exclues ---
$files = SubmissionRepository::decode_files( $d['id'] );

foreach ( array( 'relative_path', 'stored_name', 'sha256' ) as $champ ) {
	check(
		"8 · le message ne contient aucun « $champ »",
		! str_contains( $message['body'], (string) ( $files[0][ $champ ] ?? '§introuvable§' ) )
	);
}

check( '8 · aucun identifiant de transaction',
	! str_contains( $message['body'], (string) ( SubmissionRepository::transaction( $d['id'] )['id'] ?? '§introuvable§' ) ) );
check( '8 · aucun jeton anti-spam', ! str_contains( $message['body'], 'urbizen_token' ) );
check( '8 · aucun nonce', ! str_contains( $message['body'], '_wpnonce' ) && ! str_contains( $message['body'], 'urbizen_conception_nonce' ) );
check( '8 · aucune adresse IP', 1 !== preg_match( '/\b\d{1,3}(\.\d{1,3}){3}\b/', $message['body'] ) );
check( '8 · aucun chemin absolu', ! str_contains( $message['body'], URBIZEN_TEST_STORAGE ) );
check( '8 · lisible sans styles',
	str_contains( wp_strip_all_tags( $message['body'] ), $d['ref'] )
	&& str_contains( wp_strip_all_tags( $message['body'] ), 'Documents' ) );

// ======================================================================
// 9 · ÉCHAPPEMENT ET INJECTION
// ======================================================================
$hostiles = array(
	'balise'     => '<b>gras</b>',
	'script'     => '<script>alert(1)</script>',
	'guillemets' => 'il a dit "bonjour"',
	'apostrophe' => "l'aphérèse d'Anaïs",
	'esperluette' => 'Dupont & Fils',
	'unicode'    => 'Émile Ünïcode 日本語',
	'crlf'       => "ligne1\r\nBcc: attaquant@exemple.test",
	'longue'     => str_repeat( 'A', 5000 ),
	'lien'       => '<a href="https://malveillant.test">clic</a>',
	'onerror'    => '" onerror="alert(1)',
);

foreach ( $hostiles as $nom => $valeur ) {
	neuf();
	$r = traiter( soumission( array( 'nom' => $valeur ) ) );

	if ( ! $r->is_success() ) {
		// La validation a refusé la valeur : c'est une barrière antérieure,
		// parfaitement acceptable.
		check( "9 · [$nom] refusé par la validation, aucun rendu", true );
		continue;
	}

	$message = MailRenderer::render( $r->id(), wpd_now() );
	$corps   = $message['body'];

	// Une valeur hostile a le droit d'**apparaître** dans le message : c'est la
	// donnée du dossier. Ce qu'elle ne doit jamais faire, c'est agir. On
	// vérifie donc l'absence de la forme *active*, pas celle du texte.
	check( "9 · [$nom] aucun script exécutable", ! str_contains( $corps, '<script' ) );
	check( "9 · [$nom] aucune balise injectée par la donnée", ! str_contains( $corps, '<b>' ) && ! str_contains( $corps, '<a href="https://malveillant' ) );
	check( "9 · [$nom] aucun attribut sorti de son contexte", 1 !== preg_match( '/<[a-z]+[^>]*\son[a-z]+\s*=/i', $corps ) );
	check( "9 · [$nom] aucun lien actif vers un tiers", 1 !== preg_match( '/href=["\']?https?:\/\/malveillant/i', $corps ) );
	check( "9 · [$nom] le sujet reste sur une ligne", 1 !== preg_match( '/[\r\n]/', $message['subject'] ) );
	check( "9 · [$nom] aucun en-tête supplémentaire",
		2 === count( $message['headers'] ) && ! str_contains( implode( '|', $message['headers'] ), 'Bcc' ) );
	check( "9 · [$nom] le destinataire est inchangé", 'dossiers@urbizen.test' === $message['to'] );
	check( "9 · [$nom] le HTML reste équilibré",
		substr_count( $corps, '<table' ) === substr_count( $corps, '</table>' ) );
}

// --- nom de document hostile ---
neuf();
$r = traiter(
	soumission(),
	fx_files( 'photos', array( array( '<script>alert(1)</script>.jpg', fx_copie( fx_jpeg() ) ) ) )
);

if ( $r->is_success() ) {
	$message = MailRenderer::render( $r->id(), wpd_now() );

	check( '9 · nom de document hostile échappé', ! str_contains( $message['body'], '<script>' ) );
	check( '9 · le lien reste valide', str_contains( $message['body'], 'action=' . SignedLink::ACTION ) );
} else {
	check( '9 · nom de document hostile refusé au dépôt', true );
	check( '9 · aucun rendu nécessaire', true );
}

// ======================================================================
// 10 · TRANSPORT WORDPRESS
// ======================================================================
$reel = new WordPressMailTransport();

$GLOBALS['wpd_mail_retour'] = true;

check( '10 · wp_mail true → accepté', true === $reel->send( 'a@urbizen.test', 'Sujet', '<p>corps</p>', array() )['ok'] );

$GLOBALS['wpd_mail_retour'] = false;

check( '10 · wp_mail false → refus', 'transport_refused' === $reel->send( 'a@urbizen.test', 'Sujet', '<p>corps</p>', array() )['code'] );

$GLOBALS['wpd_mail_retour'] = 'exception';

check( '10 · une exception est absorbée', 'transport_exception' === $reel->send( 'a@urbizen.test', 'Sujet', '<p>c</p>', array() )['code'] );

$GLOBALS['wpd_mail_retour'] = true;
$avant                      = count( $GLOBALS['wpd_mails'] );

check( '10 · destinataire invalide → refus', 'recipient_invalid' === $reel->send( 'pas-une-adresse', 'S', 'c', array() )['code'] );
check( '10 · sujet avec CRLF → refus', 'subject_invalid' === $reel->send( 'a@urbizen.test', "S\r\nBcc: x@y.test", 'c', array() )['code'] );
check( '10 · en-tête avec CRLF → refus', 'header_invalid' === $reel->send( 'a@urbizen.test', 'S', 'c', array( "X: 1\r\nBcc: x@y.test" ) )['code'] );
check( '10 · aucun de ces refus n’a appelé wp_mail', $avant === count( $GLOBALS['wpd_mails'] ) );

// ======================================================================
// 11 · CORBEILLE, RESTAURATION, SUPPRESSION
// ======================================================================
neuf();
$d = demande( 1 );
wp_trash_post( $d['id'] );

check( '11 · la Corbeille annule la notification', MailPolicy::CANCELLED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '11 · l’événement est retiré', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '11 · aucun envoi pendant la Corbeille',
	'post_status_inattendu' === MailScheduler::process( $d['id'], wpd_now() ) && array() === $transport->envois );

wp_untrash_post( $d['id'] );

check( '11 · la restauration remet la notification en attente',
	MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '11 · et replanifie', false !== wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '11 · l’envoi devient possible', 'sent' === MailScheduler::process( $d['id'], wpd_now() ) );

// --- une notification déjà envoyée n'est jamais réémise ---
neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
wp_trash_post( $d['id'] );

check( '11 · un envoi accepté n’est pas annulé', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

wp_untrash_post( $d['id'] );

check( '11 · et n’est pas réémis à la restauration',
	MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) && 1 === count( $transport->envois ) );

// --- transition seulement préparée ---
neuf();
$d = demande();
$GLOBALS['wpd_trash_fail'] = true;
wp_trash_post( $d['id'] );
$GLOBALS['wpd_trash_fail'] = false;

// L'invalidation applicative a eu lieu, l'écriture native a échoué : le
// dossier est dans un état transitoire. Peu importe lequel des deux verrous
// répond en premier — aucun envoi ne doit avoir lieu.
$refus = MailScheduler::process( $d['id'], wpd_now() );

check( '11 · transition préparée → refus', 'sent' !== $refus && array() === $transport->envois );
check( '11 · le motif est technique et sans donnée personnelle',
	in_array( $refus, array( 'transition_corbeille_active', 'statut_metier_non_final' ), true ) );
check( '11 · la transition est bien active', TrashGuard::PREPARED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );

// --- suppression définitive ---
neuf();
$d = demande( 1 );
$id = $d['id'];
wp_trash_post( $id );
wp_delete_post( $id, true );

check( '11 · la demande est supprimée', null === get_post( $id ) );
check( '11 · l’événement résiduel est sans effet', 'post_absent' === MailScheduler::process( $id, wpd_now() ) );
check( '11 · aucun envoi', array() === $transport->envois );
check( '11 · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $id, false ) );
check( '11 · la réservation attribuée n’est pas touchée',
	null !== get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'], null ) );

// ======================================================================
// 12 · ADMINISTRATION
// ======================================================================
$GLOBALS['wpd_can'] = true;

neuf();
$d = demande();

// --- colonnes ---
$colonnes = SubmissionsAdmin::columns( array( 'cb' => '<input>' ) );

check( '12 · la colonne de notification existe', isset( $colonnes['urbizen_mail'] ) );

ob_start();
SubmissionsAdmin::render_column( 'urbizen_mail', $d['id'] );
$rendu = (string) ob_get_clean();

check( '12 · elle affiche l’état', str_contains( $rendu, 'En attente' ) );
check( '12 · aucun destinataire affiché', ! str_contains( $rendu, 'dossiers@urbizen.test' ) );
check( '12 · aucun corps ni lien signé', ! str_contains( $rendu, SignedLink::ACTION ) && ! str_contains( $rendu, '<table' ) );
check( '12 · aucune donnée personnelle', ! str_contains( $rendu, 'Camille' ) && ! str_contains( $rendu, '@exemple' ) );

$transport->reponse = false;
MailScheduler::process( $d['id'], wpd_now() );

ob_start();
SubmissionsAdmin::render_column( 'urbizen_mail', $d['id'] );
$rendu = (string) ob_get_clean();

check( '12 · le nombre de tentatives est exact', str_contains( $rendu, '1 tentative' ) );
check( '12 · aucune erreur technique détaillée', ! str_contains( $rendu, 'transport_refused' ) );

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );

ob_start();
SubmissionsAdmin::render_column( 'urbizen_mail', $d['id'] );
$rendu = (string) ob_get_clean();

check( '12 · un envoi accepté affiche sa date',
	str_contains( $rendu, 'Envoyée' ) && str_contains( $rendu, (string) get_post_meta( $d['id'], MailPolicy::META_SENT_AT, true ) ) );

// --- la colonne est muette sans la capacité ---
$GLOBALS['wpd_can'] = false;

ob_start();
SubmissionsAdmin::render_column( 'urbizen_mail', $d['id'] );

check( '12 · rien ne s’affiche sans la capacité', '' === (string) ob_get_clean() );

$GLOBALS['wpd_can'] = true;

// --- éligibilité de l'action de reprise ---
neuf();
$d = demande();

check( '12 · une notification pending n’est pas « à réessayer »', false === SubmissionsAdmin::retry_allowed( $d['id'] ) );

update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::FAILED );

check( '12 · une notification failed l’est', true === SubmissionsAdmin::retry_allowed( $d['id'] ) );

update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::CANCELLED );

check( '12 · une notification cancelled aussi', true === SubmissionsAdmin::retry_allowed( $d['id'] ) );

update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::SENT );

check( '12 · une notification envoyée, non', false === SubmissionsAdmin::retry_allowed( $d['id'] ) );

// Demande inéligible par ailleurs.
update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::FAILED );
update_post_meta( $d['id'], '_urbizen_files_status', 'pending' );

check( '12 · une demande inéligible n’est pas reprenable', false === SubmissionsAdmin::retry_allowed( $d['id'] ) );

update_post_meta( $d['id'], '_urbizen_files_status', 'none' );

$GLOBALS['wpd_can'] = false;

check( '12 · sans manage_options, aucune reprise', false === SubmissionsAdmin::retry_allowed( $d['id'] ) );

$GLOBALS['wpd_can'] = true;

// --- l'action elle-même ---
/**
 * Joue l'action de reprise et rend l'URL de redirection.
 *
 * @param array<string, string> $post   Corps de la requête.
 * @param string                $methode Méthode HTTP.
 * @return string
 */
function jouer_reprise( array $post, string $methode = 'POST' ): string {
	$GLOBALS['wpd_redirects']    = array();
	$GLOBALS['wpd_redirect_leve'] = true;

	$anciens_post   = $_POST;
	$ancienne_req   = $_REQUEST;
	$ancienne_meth  = $_SERVER['REQUEST_METHOD'] ?? '';

	$_POST                     = $post;
	$_REQUEST                  = $post;
	$_SERVER['REQUEST_METHOD'] = $methode;

	try {
		SubmissionsAdmin::handle_retry();
	} catch ( \Throwable $e ) {
		// La doublure lève au moment de la redirection : le `exit` du code de
		// production n'est jamais atteint, et le banc garde la main.
	}

	$GLOBALS['wpd_redirect_leve'] = false;

	$_POST                     = $anciens_post;
	$_REQUEST                  = $ancienne_req;
	$_SERVER['REQUEST_METHOD'] = $ancienne_meth;

	return (string) ( $GLOBALS['wpd_redirects'][0] ?? '' );
}

neuf();
$d = demande();
update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::FAILED );
MailScheduler::unschedule( $d['id'] );

$nonce = wp_create_nonce( SubmissionsAdmin::NONCE_RETRY . $d['id'] );

check( '12 · sans nonce, refus',
	str_contains( jouer_reprise( array( 'submission' => (string) $d['id'] ) ), 'refused' ) );
check( '12 · le statut n’a pas bougé', MailPolicy::FAILED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

check( '12 · nonce invalide, refus',
	str_contains( jouer_reprise( array( 'submission' => (string) $d['id'], '_wpnonce' => 'faux' ) ), 'refused' ) );

check( '12 · méthode GET, refus',
	str_contains( jouer_reprise( array( 'submission' => (string) $d['id'], '_wpnonce' => $nonce ), 'GET' ), 'refused' ) );

$GLOBALS['wpd_can'] = false;

check( '12 · sans capacité, refus',
	str_contains( jouer_reprise( array( 'submission' => (string) $d['id'], '_wpnonce' => $nonce ) ), 'refused' ) );

$GLOBALS['wpd_can'] = true;

foreach ( array( '0', '-1', '01', '1.5', 'abc', '' ) as $brut ) {
	check(
		sprintf( '12 · identifiant « %s » refusé', '' === $brut ? '(vide)' : $brut ),
		str_contains( jouer_reprise( array( 'submission' => $brut, '_wpnonce' => $nonce ) ), 'refused' )
	);
}

check( '12 · aucune de ces tentatives n’a envoyé', array() === $transport->envois );
check( '12 · aucune n’a replanifié', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );

// --- reprise légitime ---
$url = jouer_reprise( array( 'submission' => (string) $d['id'], '_wpnonce' => $nonce ) );

check( '12 · la reprise aboutit', str_contains( $url, 'requeued' ) );
check( '12 · la notification repasse en attente', MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '12 · le compteur de tentatives repart de zéro', 0 === (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) );
check( '12 · un événement unique est planifié', false !== wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '12 · UN SEUL événement', 1 === count( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ) );
check( '12 · AUCUN envoi dans la requête d’administration', array() === $transport->envois );

// --- une notification envoyée ne se reprend pas ---
update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::SENT );

check( '12 · une notification envoyée est refusée',
	str_contains( jouer_reprise( array( 'submission' => (string) $d['id'], '_wpnonce' => $nonce ) ), 'refused' ) );
check( '12 · elle reste envoyée', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );


// ======================================================================
// 13 · VERROU : PROPRIÉTÉ ET DURÉE
// ======================================================================
neuf();
$d = demande();

$jeton = MailQueue::acquire_lock( $d['id'], wpd_now() );

check( '13 · le verrou rend un jeton', is_string( $jeton ) && 32 === strlen( (string) $jeton ) );
check( '13 · il ne contient aucune donnée personnelle',
	1 === preg_match( '/^[0-9a-f]{32}$/', (string) $jeton ) );
check( '13 · le verrou porte un propriétaire et une échéance',
	is_array( get_option( MailPolicy::LOCK_PREFIX . $d['id'] ) )
	&& isset( get_option( MailPolicy::LOCK_PREFIX . $d['id'] )['owner'], get_option( MailPolicy::LOCK_PREFIX . $d['id'] )['expires'] ) );
check( '13 · et rien d’autre',
	array( 'owner', 'expires' ) === array_keys( get_option( MailPolicy::LOCK_PREFIX . $d['id'] ) ) );
check( '13 · autoload = false', 'no' === wpd_autoload( MailPolicy::LOCK_PREFIX . $d['id'] ) );
check( '13 · un second appel est refusé', null === MailQueue::acquire_lock( $d['id'], wpd_now() ) );
check( '13 · le propriétaire se reconnaît', MailQueue::owns_lock( $d['id'], (string) $jeton, wpd_now() ) );
check( '13 · un jeton inventé, non', false === MailQueue::owns_lock( $d['id'], str_repeat( 'a', 32 ), wpd_now() ) );
check( '13 · un jeton vide, non plus', false === MailQueue::owns_lock( $d['id'], '', wpd_now() ) );
check( '13 · un tiers ne peut pas libérer', false === MailQueue::release_lock( $d['id'], str_repeat( 'b', 32 ) ) );
check( '13 · le verrou tient toujours', false !== get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );
check( '13 · le propriétaire libère', true === MailQueue::release_lock( $d['id'], (string) $jeton ) );
check( '13 · plus aucune option', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

// --- durée ---
$jeton = MailQueue::acquire_lock( $d['id'], 1000000 );

check( '13 · propriétaire actif à 299 s', MailQueue::owns_lock( $d['id'], (string) $jeton, 1000299 ) );
check( '13 · propriétaire actif à 359 s', MailQueue::owns_lock( $d['id'], (string) $jeton, 1000359 ) );
check( '13 · verrou non repris à 599 s', null === MailQueue::acquire_lock( $d['id'], 1000599 ) );

$repris = MailQueue::acquire_lock( $d['id'], 1000601 );

check( '13 · verrou repris après expiration', is_string( $repris ) );
check( '13 · avec un jeton neuf', $repris !== $jeton );
check( '13 · L’ANCIEN NE PEUT PLUS LIBÉRER', false === MailQueue::release_lock( $d['id'], (string) $jeton ) );
check( '13 · ni se croire propriétaire', false === MailQueue::owns_lock( $d['id'], (string) $jeton, 1000601 ) );

MailQueue::release_lock( $d['id'], (string) $repris );

check( '13 · le TTL dépasse le temps d’exécution maximal', MailPolicy::lock_ttl() > MailPolicy::MAX_EXECUTION );
check( '13 · un filtre ne peut pas descendre sous ce plancher',
	( static function () {
		add_filter( 'urbizen_mail_lock_ttl', static fn() => 10 );
		$ttl = MailPolicy::lock_ttl();
		wpd_clear_filter( 'urbizen_mail_lock_ttl' );

		return $ttl > MailPolicy::MAX_EXECUTION;
	} )() );

// --- with_lock ---
neuf();
$d = demande();

$resultat = MailQueue::with_lock( $d['id'], static fn( string $j ) => 'travail-' . strlen( $j ) );

check( '13 · with_lock exécute et rend la valeur', 'travail-32' === $resultat['valeur'] );
check( '13 · et libère derrière lui', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

$resultat = MailQueue::with_lock(
	$d['id'],
	static function ( string $j ) use ( $d ) {
		// Verrou occupé pendant le travail : un second appel doit échouer.
		return MailQueue::with_lock( $d['id'], static fn() => 'jamais' );
	}
);

check( '13 · un appel imbriqué constate le verrou', 'verrou_occupe' === ( $resultat['valeur']['code'] ?? '' ) );
check( '13 · et le verrou est bien rendu à la fin', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

// Une exception ne laisse pas de verrou derrière elle.
try {
	MailQueue::with_lock( $d['id'], static function () { throw new RuntimeException( 'panne' ); } );
} catch ( Throwable $e ) {
	// attendu
}

check( '13 · une exception ne laisse aucun verrou', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

// ======================================================================
// 14 · SÉRIALISATION DES TRANSITIONS
// ======================================================================
neuf();
$d = demande();

// Un envoi est en vol : le verrou est tenu par un autre.
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'owner' => 'envoyeur', 'expires' => wpd_now() + 600 ), '', false );

check( '14 · la Corbeille est refusée pendant un envoi', false === wp_trash_post( $d['id'] ) );
check( '14 · le contenu reste privé', 'private' === get_post( $d['id'] )->post_status );
check( '14 · la notification n’est pas annulée', MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '14 · la suppression est refusée aussi', false === FileCleaner::guard_delete( null, get_post( $d['id'] ), true ) );
check( '14 · aucun document supprimé', 0 === fx_compte_fichiers() );

delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '14 · verrou rendu, la Corbeille aboutit', false !== wp_trash_post( $d['id'] ) );
check( '14 · et la notification est annulée', MailPolicy::CANCELLED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '14 · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

// --- l'état fermé gagne : aucun envoi ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

check( '14 · un envoi ultérieur ne fait rien', 'post_status_inattendu' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '14 · et n’appelle pas le transport', array() === $transport->envois );
check( '14 · cancelled n’est pas devenu sent', MailPolicy::CANCELLED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

// --- planification atomique ---
neuf();
$d = demande();
MailScheduler::unschedule_all( $d['id'] );

check( '14 · aucun événement au départ', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );

$a = MailScheduler::schedule_unique( $d['id'], wpd_now() );
$b = MailScheduler::schedule_unique( $d['id'], wpd_now() );
$c = MailScheduler::schedule_unique( $d['id'], wpd_now() );

check( '14 · trois planifications, un seul événement',
	$a && $b && $c && 1 === count( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ) );
check( '14 · aucun verrou résiduel', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );

// Un verrou tenu par un tiers empêche toute planification.
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'owner' => 'autrui', 'expires' => wpd_now() + 600 ), '', false );
MailScheduler::unschedule_all( $d['id'] );

check( '14 · verrou tenu → aucune planification', false === MailScheduler::schedule_unique( $d['id'], wpd_now() ) );
check( '14 · et aucun événement créé', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );

delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

// --- unschedule_all retire les doublons ---
wp_schedule_single_event( wpd_now(), MailPolicy::EVENT, array( $d['id'] ) );

check( '14 · unschedule_all retire tout', 1 === MailScheduler::unschedule_all( $d['id'] ) );
check( '14 · plus aucun événement', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '14 · et il est idempotent', 0 === MailScheduler::unschedule_all( $d['id'] ) );


verdict();
