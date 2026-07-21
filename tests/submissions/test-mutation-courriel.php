<?php
/**
 * Banc de mutation de la notification administrative.
 *
 * Chaque scénario casse **une** règle — dans une copie, jamais dans le dépôt —
 * et vérifie que le contrôle correspondant tombe. Un contrôle vert ne prouve
 * rien tant qu'on n'a pas vu ce qui le fait rougir.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Mail\MailLockHandle;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailProcessLock;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\MailRenderer;
use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Mail\MailTransport;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TrashGuard;

$compteur = 0;

/**
 * Charge une copie mutée d'une classe du plugin.
 *
 * @param string                $relatif       Chemin sous le plugin.
 * @param string                $classe        Classe d'origine.
 * @param array<string, string> $remplacements Motif exact => remplacement.
 * @return string Classe mutée, pleinement qualifiée.
 */
function mutant( string $relatif, string $classe, array $remplacements ): string {
	global $compteur;

	$source  = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $relatif );
	$nouveau = $classe . 'Mutant' . ( ++$compteur );

	$source = str_replace( "final class $classe", "final class $nouveau", $source );

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

/**
 * Transport d'essai.
 */
final class TransportMutant implements MailTransport {

	/** @var array<int, array<string, mixed>> */
	public array $envois = array();

	/** @var bool|string */
	public $reponse = true;

	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		$this->envois[] = array( 'to' => $destinataire, 'subject' => $sujet, 'body' => $corps, 'headers' => $entetes );

		if ( 'exception' === $this->reponse ) {
			throw new RuntimeException( 'panne' );
		}

		return true === $this->reponse ? array( 'ok' => true, 'code' => 'accepted' ) : array( 'ok' => false, 'code' => 'transport_refused' );
	}
}

$transport = new TransportMutant();

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

	$transport          = new TransportMutant();
	$transport->reponse = true;
	MailScheduler::set_transport( $transport );

	add_filter( 'urbizen_mail_retry_delays', static fn() => array( 1 => 0, 2 => 10, 3 => 20, 4 => 30, 5 => 40 ) );
	update_option( 'admin_email', 'dossiers@urbizen.test' );
}

/**
 * Demande finalisée.
 *
 * @param int $documents Nombre de documents.
 * @return array{id:int,ref:string}
 */
function demande( int $documents = 0 ): array {
	$files = array();

	if ( $documents > 0 ) {
		$lot = array();

		for ( $i = 0; $i < $documents; $i++ ) {
			$lot[] = array( 'doc-' . $i . '.jpg', fx_copie( fx_jpeg() ) );
		}

		$files = fx_files( 'photos', $lot );
	}

	$r = array() === $files ? traiter( soumission() ) : traiter( soumission(), $files );

	return array( 'id' => $r->id(), 'ref' => (string) get_post_meta( $r->id(), '_urbizen_reference', true ) );
}

// ====== 1 · la notification n'est plus persistée à la finalisation ========
$sr = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array(
		"		if ( ! MailQueue::create_pending( \$id, \$now ) ) {
			Logger::error( sprintf( 'demande %s : notification non enregistrée, finalisation abandonnée', \$reference ) );

			return false;
		}" => '		// notification retirée de la finalisation.',
	)
);

neuf();
$r   = traiter( soumission() );
$id  = $r->id();
$sr::finalize( $id, (string) get_post_meta( $id, '_urbizen_reference', true ), 'none', wpd_now() );
delete_post_meta( $id, MailPolicy::META_ID );
$sr::finalize( $id, (string) get_post_meta( $id, '_urbizen_reference', true ), 'none', wpd_now() );

check( '1 · notification retirée → UNE DEMANDE REÇUE SANS NOTIFICATION',
	SubmissionPostType::STATUS_RECEIVED === get_post_meta( $id, '_urbizen_status', true )
	&& '' === get_post_meta( $id, MailPolicy::META_ID, true ) );

neuf();
$d = demande();

check( '1 · le dépôt exige un identifiant de notification',
	1 === preg_match( '/^[0-9a-f]{32}$/', (string) get_post_meta( $d['id'], MailPolicy::META_ID, true ) ) );
check( '1 · et un mail_status pending', MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

// ====== 2 · une demande devient received sans notification_id =============
neuf();
$GLOBALS['wpd_meta_fail'] = MailPolicy::META_ID;
$r                        = traiter( soumission() );
$GLOBALS['wpd_meta_fail'] = '';

check( '2 · le dépôt refuse la finalisation', ! $r->is_success() );
check( '2 · aucune demande ne subsiste', 0 === count( $GLOBALS['wpd_posts'] ) );

// ====== 3 · wp_mail appelé avant la finalisation =========================
neuf();
$avant = count( $GLOBALS['wpd_mails'] );
$d     = demande( 1 );

check( '3 · la finalisation n’émet aucun courriel', $avant === count( $GLOBALS['wpd_mails'] ) );
check( '3 · ni le transport injecté', array() === $transport->envois );

// ====== 4 · une demande inéligible est envoyée ============================
$ms = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array(
		"		\$motif = MailPolicy::blocker( \$id, \$now );

		if ( null !== \$motif ) {
			Logger::info( sprintf( 'notification #%d non envoyée : %s', \$id, \$motif ) );

			return \$motif;
		}" => '		// contrôle d\'éligibilité retiré.',
		"		\$motif = MailPolicy::blocker( \$id, \$now );

		if ( null !== \$motif ) {
			return \$motif;
		}" => '		// relecture sous verrou retirée.',
		"		\$motif = MailPolicy::closed_blocker( \$id );

		if ( null !== \$motif ) {
			// L'état est passé à fermé pendant la préparation : on ne consomme
			// pas la tentative, la situation n'ayant rien d'un échec technique.
			Logger::info( sprintf( 'notification #%d abandonnée avant envoi : %s', \$id, \$motif ) );

			return \$motif;
		}" => '		// ultime vérification retirée.',
		"		\$ferme = MailPolicy::closed_blocker( \$id );

		if ( null !== \$ferme ) {
			Logger::error( sprintf( 'notification #%d : envoi accepté mais demande fermée entre-temps (%s)', \$id, \$ferme ) );

			return 'ferme_pendant_envoi';
		}" => '		// contrôle postérieur retiré.',
	)
);

neuf();
$d = demande( 1 );
update_post_meta( $d['id'], '_urbizen_files_status', 'pending' );
$ms::set_transport( $transport );
$ms::process( $d['id'], wpd_now() );

check( '4 · éligibilité retirée → UNE DEMANDE INCOHÉRENTE EST NOTIFIÉE', 1 === count( $transport->envois ) );

neuf();
$d = demande( 1 );
update_post_meta( $d['id'], '_urbizen_files_status', 'pending' );

check( '4 · le dépôt refuse', 'documents_non_finaux' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '4 · et n’envoie rien', array() === $transport->envois );

// ====== 5 · un post trash est envoyé =====================================
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$ms::set_transport( $transport );
$ms::process( $d['id'], wpd_now() );

check( '5 · éligibilité retirée → UNE DEMANDE À LA CORBEILLE EST NOTIFIÉE', 1 === count( $transport->envois ) );

neuf();
$d = demande();
wp_trash_post( $d['id'] );

check( '5 · le dépôt refuse', 'post_status_inattendu' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '5 · et n’envoie rien', array() === $transport->envois );

// ====== 6 · une référence reserved est acceptée ==========================
$mp = mutant(
	'src/Mail/MailPolicy.php',
	'MailPolicy',
	array( "		if ( ! is_array( \$reservation ) || 'attributed' !== ( \$reservation['state'] ?? '' ) ) {
			return 'reference_non_attribuee';
		}" => '		// contrôle de l\'attribution retiré.' )
);

neuf();
$d   = demande();
$cle = SubmissionRepository::RESERVATION_PREFIX . $d['ref'];
$res = get_option( $cle );
$res['state'] = 'reserved';
update_option( $cle, $res );

check( '6 · attribution retirée → une référence reserved passe', null === $mp::blocker( $d['id'], wpd_now() ) );
check( '6 · le dépôt refuse', 'reference_non_attribuee' === MailPolicy::blocker( $d['id'], wpd_now() ) );

// ====== 7 · files_status pending est accepté =============================
$mp2 = mutant(
	'src/Mail/MailPolicy.php',
	'MailPolicy',
	array( "		if ( ! in_array( (string) \$demande['files_status'], array( 'stored', 'none' ), true ) ) {
			return 'documents_non_finaux';
		}" => '		// contrôle de l\'état des documents retiré.' )
);

neuf();
$d = demande( 1 );
update_post_meta( $d['id'], '_urbizen_files_status', 'pending' );

check( '7 · contrôle retiré → files_status pending passe', null === $mp2::blocker( $d['id'], wpd_now() ) );
check( '7 · le dépôt refuse', 'documents_non_finaux' === MailPolicy::blocker( $d['id'], wpd_now() ) );

// ====== 8 · le mutex de processus n'est plus respecté ====================
/**
 * Chaîne un mutant de MailProcessLock jusqu'à MailScheduler.
 *
 * `flock()` est la primitive : c'est elle qu'il faut casser pour observer ce
 * qui se passe quand un envoi ignore la vie d'un autre processus.
 *
 * @param array<string, string> $mutations_pl Mutations de MailProcessLock.
 * @return string Classe MailScheduler mutée.
 */
function chaine_mutex( array $mutations_pl ): string {
	$pl = mutant( 'src/Mail/MailProcessLock.php', 'MailProcessLock', $mutations_pl );
	$mq = mutant( 'src/Mail/MailQueue.php', 'MailQueue', array( 'MailProcessLock::' => $pl . '::' ) );

	return mutant( 'src/Mail/MailScheduler.php', 'MailScheduler', array( 'MailQueue::' => $mq . '::' ) );
}

$ms_sans_mutex = chaine_mutex(
	array(
		"		if ( ! @flock( \$ressource, LOCK_EX | LOCK_NB ) ) {
			// Contention normale : un autre processus travaille. Ce n'est pas
			// une anomalie, et cela ne se journalise pas comme telle.
			@fclose( \$ressource );

			return null;
		}" => '		@flock( $ressource, LOCK_EX | LOCK_NB );',
	)
);

neuf();
$d       = demande();
$poignee = MailProcessLock::acquire( $d['id'] );

check( '8 · un envoi détient réellement le mutex', $poignee instanceof MailLockHandle && $poignee->est_detenu() );

$ms_sans_mutex::set_transport( $transport );
$ms_sans_mutex::process( $d['id'], wpd_now() );

check( '8 · flock ignoré → UN SECOND ENVOI PART MALGRÉ UN PROPRIÉTAIRE VIVANT',
	1 === count( $transport->envois ) );

MailProcessLock::release( $poignee );

neuf();
$d       = demande();
$poignee = MailProcessLock::acquire( $d['id'] );

check( '8 · le dépôt s’arrête', 'mutex_indisponible' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '8 · et n’envoie rien', array() === $transport->envois );

MailProcessLock::release( $poignee );

check( '8 · mutex rendu, l’envoi a lieu', 'sent' === MailScheduler::process( $d['id'], wpd_now() ) );

// ====== 9 · un bail expiré suffit à reprendre un envoi vivant ============
// C'est le défaut résiduel : l'expiration du bail ne prouve pas la mort du
// propriétaire. On retire l'autorité du mutex dans `is_locked`.
$mq_bail = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		// **Le mutex fait autorité.** Un bail périmé ne prouve pas que son
		// propriétaire est mort ; un mutex détenu prouve qu'il est vivant.
		if ( MailProcessLock::is_held( \$id ) ) {
			return true;
		}

" => '' )
);

$tg_bail = mutant( 'src/Submissions/TrashGuard.php', 'TrashGuard', array( 'MailQueue::' => $mq_bail . '::' ) );

neuf();
wpd_clear_filter( 'pre_trash_post' );
wpd_clear_action( 'trashed_post' );
$tg_bail::register();
$d       = demande();
$poignee = MailProcessLock::acquire( $d['id'] );
// Le bail a expiré, mais le propriétaire est bien vivant.
delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '9 · autorité du mutex retirée → LA CORBEILLE PASSE SUR UN PROPRIÉTAIRE VIVANT',
	false !== wp_trash_post( $d['id'] ) );

MailProcessLock::release( $poignee );

neuf();
$d       = demande();
$poignee = MailProcessLock::acquire( $d['id'] );
delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '9 · le dépôt refuse : le mutex fait autorité', false === wp_trash_post( $d['id'] ) );
check( '9 · le bail est pourtant absent', false === get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );
check( '9 · et le contenu reste privé', 'private' === get_post( $d['id'] )->post_status );

MailProcessLock::release( $poignee );

check( '9 · mutex rendu, la Corbeille aboutit', false !== wp_trash_post( $d['id'] ) );

// ====== 10 · true de wp_mail n'aboutit plus à sent =======================
$ms3 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		if ( ! empty( \$resultat['ok'] ) ) {
			MailQueue::mark_sent( \$id, \$now );" => "		if ( ! empty( \$resultat['ok'] ) ) {" )
);

neuf();
$d = demande();
$ms3::set_transport( $transport );
$ms3::process( $d['id'], wpd_now() );

check( '10 · sent retiré → l’envoi accepté n’est pas enregistré',
	MailPolicy::SENT !== get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );

check( '10 · le dépôt enregistre sent', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

// ====== 11 · false de wp_mail aboutit à sent =============================
$ms4 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		if ( ! empty( \$resultat['ok'] ) ) {
			MailQueue::mark_sent( \$id, \$now );" => "		if ( true ) {
			MailQueue::mark_sent( \$id, \$now );" )
);

neuf();
$d                  = demande();
$transport->reponse = false;
$ms4::set_transport( $transport );
$ms4::process( $d['id'], wpd_now() );

check( '11 · condition inversée → UN REFUS DEVIENT UN ENVOI RÉUSSI',
	MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

neuf();
$d                  = demande();
$transport->reponse = false;
MailScheduler::process( $d['id'], wpd_now() );

check( '11 · le dépôt passe en retry', MailPolicy::RETRY === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

// ====== 12 · les reprises dépassent cinq tentatives ======================
$mq = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		if ( \$rang >= MailPolicy::MAX_ATTEMPTS ) {" => '		if ( false ) {' )
);

neuf();
$d                  = demande();
$transport->reponse = false;

for ( $i = 0; $i < 7; $i++ ) {
	$rang = ( (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) ) + 1;
	$mq::mark_sending( $d['id'], $rang, wpd_now() );
	$mq::mark_failure( $d['id'], $rang, 'transport_refused', wpd_now() );
}

check( '12 · plafond retiré → LES TENTATIVES NE S’ARRÊTENT PLUS',
	MailPolicy::RETRY === get_post_meta( $d['id'], MailPolicy::META_STATUS, true )
	&& 7 === (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) );

neuf();
$d                  = demande();
$transport->reponse = false;

for ( $i = 0; $i < 7; $i++ ) {
	wpd_avancer( 60 );
	MailScheduler::process( $d['id'], wpd_now() );
}

check( '12 · le dépôt s’arrête à cinq', 5 === (int) get_post_meta( $d['id'], MailPolicy::META_ATTEMPTS, true ) );
check( '12 · et passe en failed', MailPolicy::FAILED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '12 · cinq appels au transport', 5 === count( $transport->envois ) );

// ====== 13 · un courriel envoyé est renvoyé automatiquement ==============
$mq2 = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		// Un envoi déjà accepté ne se rejoue jamais tout seul.
		if ( MailPolicy::SENT === \$statut ) {
			return false;
		}" => '		// garde du sent retirée.' )
);

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
$mq2::requeue( $d['id'], wpd_now() );
MailScheduler::process( $d['id'], wpd_now() );

check( '13 · garde retirée → UN ENVOI ACCEPTÉ EST RÉÉMIS', 2 === count( $transport->envois ) );

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
MailQueue::requeue( $d['id'], wpd_now() );
MailScheduler::process( $d['id'], wpd_now() );

check( '13 · le dépôt refuse de rejouer', 1 === count( $transport->envois ) );
check( '13 · et reste à sent', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

// ====== 14 · un lien signé est stocké en base ============================
neuf();
$d      = demande( 2 );
MailScheduler::process( $d['id'], wpd_now() );
$metas  = $GLOBALS['wpd_meta'][ $d['id'] ] ?? array();
$serial = (string) wp_json_encode( $metas );

check( '14 · aucun lien signé en base', ! str_contains( $serial, 'action=' . SignedLink::ACTION ) );
check( '14 · aucune signature en base', ! str_contains( $serial, 'signature=' ) );
check( '14 · aucun corps de message en base', ! str_contains( $serial, '<table' ) );
check( '14 · aucun destinataire en base', ! str_contains( $serial, 'dossiers@urbizen.test' ) );

// ====== 15 · une pièce jointe est ajoutée ================================
$source_transport = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/WordPressMailTransport.php' );

check( '15 · le transport ne transmet aucune pièce jointe',
	1 === preg_match( '/wp_mail\(\s*\$destinataire,\s*\$sujet,\s*\$corps,\s*\$propres\s*\)/', $source_transport ) );
check( '15 · le contrat de transport n’a pas de paramètre de pièce jointe',
	! str_contains( (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailTransport.php' ), 'attachment' ) );

// ====== 16 · le nom du client apparaît dans le sujet =====================
$mr = mutant(
	'src/Mail/MailRenderer.php',
	'MailRenderer',
	array( "		return self::une_ligne( sprintf( '[Urbizen] Nouvelle demande %s', \$reference ) );" =>
		"		return self::une_ligne( sprintf( '[Urbizen] Nouvelle demande %s', \$reference ) ) . ' — Camille Fictif';" )
);

check( '16 · sujet muté → le nom du client y figure', str_contains( $mr::subject( 'URB-2026-0001' ), 'Camille' ) );

neuf();
$d = demande();

check( '16 · le dépôt n’y met que la référence',
	'[Urbizen] Nouvelle demande ' . $d['ref'] === MailRenderer::subject( $d['ref'] ) );

// ====== 17 · une donnée HTML n'est plus échappée =========================
$mr2 = mutant(
	'src/Mail/MailRenderer.php',
	'MailRenderer',
	array( "				esc_html( '' === (string) \$valeur ? '—' : (string) \$valeur )" => "				'' === (string) \$valeur ? '—' : (string) \$valeur" )
);

neuf();
$r = traiter( soumission( array( 'nom' => '<script>alert(1)</script>' ) ) );

if ( $r->is_success() ) {
	$corps_mute = $mr2::body( SubmissionRepository::get( $r->id() ), wpd_now() );
	$corps_sain = MailRenderer::body( SubmissionRepository::get( $r->id() ), wpd_now() );

	check( '17 · échappement retiré → LE SCRIPT PASSE', str_contains( $corps_mute, '<script>' ) );
	check( '17 · le dépôt l’échappe', ! str_contains( $corps_sain, '<script>' ) );
	check( '17 · et conserve la donnée, échappée', str_contains( $corps_sain, '&lt;script&gt;' ) );
} else {
	check( '17 · la valeur est refusée en amont par la validation', true );
	check( '17 · aucun rendu concerné', true );
	check( '17 · barrière antérieure suffisante', true );
}

// ====== 18 · CR/LF permet une injection d'en-tête ========================
$mr3 = mutant(
	'src/Mail/MailRenderer.php',
	'MailRenderer',
	array( "	private static function une_ligne( string \$valeur ): string {" =>
		"	private static function une_ligne( string \$valeur ): string {
		return \$valeur;" )
);

$sujet_mute = $mr3::subject( "URB-2026-0001\r\nBcc: attaquant@exemple.test" );

check( '18 · neutralisation retirée → LE SUJET PORTE UNE NOUVELLE LIGNE', 1 === preg_match( '/[\r\n]/', $sujet_mute ) );

$sujet_sain = MailRenderer::subject( "URB-2026-0001\r\nBcc: attaquant@exemple.test" );

check( '18 · le dépôt le réduit à une ligne', 1 !== preg_match( '/[\r\n]/', $sujet_sain ) );

// Et le transport refuse quoi qu'il arrive.
$reel = new \Urbizen\Platform\Mail\WordPressMailTransport();

check( '18 · le transport refuse un sujet multiligne',
	'subject_invalid' === $reel->send( 'a@urbizen.test', $sujet_mute, 'c', array() )['code'] );

// ====== 19 · le destinataire vient du formulaire =========================
$mp3 = mutant(
	'src/Mail/MailPolicy.php',
	'MailPolicy',
	array( "	public static function recipient(): string {" =>
		"	public static function recipient(): string {
		if ( isset( \$_POST['email'] ) && is_email( (string) \$_POST['email'] ) ) {
			return (string) \$_POST['email'];
		}
" )
);

neuf();
$_POST['email'] = 'attaquant@exemple.test';

check( '19 · muté → LE FORMULAIRE CHOISIT LE DESTINATAIRE', 'attaquant@exemple.test' === $mp3::recipient() );
check( '19 · le dépôt ignore le formulaire', 'dossiers@urbizen.test' === MailPolicy::recipient() );

$_POST = array();

// ====== 20 · un lien est généré hors de SignedLink =======================
$source_renderer = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailRenderer.php' );

check( '20 · le rendu emploie SignedLink', str_contains( $source_renderer, 'SignedLink::url(' ) );
check( '20 · et ne fabrique aucune signature de son côté',
	! str_contains( $source_renderer, 'hash_hmac' ) && ! str_contains( $source_renderer, 'signature' ) );

neuf();
$d       = demande( 1 );
$message = MailRenderer::render( $d['id'], wpd_now() );

preg_match( '/(https?:[^"\']+)/', $message['body'], $lien );
$url = html_entity_decode( (string) ( $lien[1] ?? '' ) );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

check( '20 · le lien produit est vérifiable par SignedLink',
	! empty( SignedLink::verify( $params, wpd_now() )['ok'] ) );

// ====== 21 · une demande supprimée reste envoyable =======================
$ms5 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		if ( null === get_post( \$id ) ) {
			return 'post_absent';
		}" => '		// garde du post absent retirée.' )
);

// Deux barrières se superposent : la garde de `process()` et le contrôle de
// `blocker()`. Retirer l'une seule ne change rien — c'est mesurable, et c'est
// dit. On les retire donc toutes les deux pour observer la conséquence.
$mp_absent = mutant(
	'src/Mail/MailPolicy.php',
	'MailPolicy',
	array( "		if ( ! \$post || SubmissionPostType::POST_TYPE !== \$post->post_type ) {
			return 'post_absent';
		}" => '		// contrôle du post retiré.' )
);

$ms_absent = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array(
		"		if ( null === get_post( \$id ) ) {
			return 'post_absent';
		}" => '		// garde du post absent retirée.',
		'MailPolicy::' => $mp_absent . '::',
	)
);

neuf();
$d  = demande();
$id = $d['id'];
wp_trash_post( $id );
wp_delete_post( $id, true );
$ms5::set_transport( $transport );

check( '21 · garde seule retirée, blocker protège encore', 'post_absent' === $ms5::process( $id, wpd_now() ) );

$ms_absent::set_transport( $transport );

check( '21 · les DEUX barrières retirées → le traitement va plus loin',
	'post_absent' !== $ms_absent::process( $id, wpd_now() ) );

neuf();
$d  = demande();
$id = $d['id'];
wp_trash_post( $id );
wp_delete_post( $id, true );

check( '21 · le dépôt s’arrête net', 'post_absent' === MailScheduler::process( $id, wpd_now() ) );
check( '21 · et n’envoie rien', array() === $transport->envois );
check( '21 · un verrou appartenant à autrui n’est pas volé',
	( static function () use ( $id ) {
		add_option( MailPolicy::LOCK_PREFIX . $id, array( 'owner' => 'autrui', 'expires' => wpd_now() + 600 ), '', false );
		MailScheduler::process( $id, wpd_now() );
		$reste = get_option( MailPolicy::LOCK_PREFIX . $id, false );
		delete_option( MailPolicy::LOCK_PREFIX . $id );

		return is_array( $reste ) && 'autrui' === ( $reste['owner'] ?? '' );
	} )() );

// ====== 22 · une notification annulée est envoyée pendant la Corbeille ====
$tg = mutant(
	'src/Submissions/TrashGuard.php',
	'TrashGuard',
	array( "				MailQueue::cancel( \$id, 'demande_en_corbeille' );
				MailScheduler::unschedule_all( \$id );" => '				// annulation de la notification retirée.' )
);

neuf();
// Seul le mutant est accroché : sinon le garde du dépôt annulerait quand même.
wpd_clear_filter( 'pre_trash_post' );
wpd_clear_action( 'trashed_post' );
$tg::register();
$d = demande();
wp_trash_post( $d['id'] );

check( '22 · annulation retirée → la notification reste en attente',
	MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

neuf();
$d = demande();
wp_trash_post( $d['id'] );

check( '22 · le dépôt annule', MailPolicy::CANCELLED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '22 · et retire l’événement', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '22 · aucun envoi pendant la Corbeille', array() === $transport->envois );

// ====== 23 · un journal contient une adresse ou un lien signé ============
neuf();
$d = demande( 1 );
$GLOBALS['wpd_logs'] = array();
MailScheduler::process( $d['id'], wpd_now() );

// Un échec journalise aussi : les deux chemins sont couverts.
$transport->reponse = false;
$e                  = demande( 1 );
MailScheduler::process( $e['id'], wpd_now() );
wp_trash_post( $e['id'] );

$journal = implode( "\n", $GLOBALS['wpd_logs'] );

check( '23 · le journal n’est pas vide', '' !== trim( $journal ) );

check( '23 · aucun destinataire dans le journal', ! str_contains( $journal, 'dossiers@urbizen.test' ) );
check( '23 · aucun lien signé', ! str_contains( $journal, 'action=' . SignedLink::ACTION ) );
check( '23 · aucune signature', ! str_contains( $journal, 'signature=' ) );
check( '23 · aucune donnée personnelle', ! str_contains( $journal, 'Camille' ) && ! str_contains( $journal, '@exemple' ) );
check( '23 · aucun nom de document', ! str_contains( $journal, 'doc-0.jpg' ) );
check( '23 · aucun chemin de stockage', ! str_contains( $journal, URBIZEN_TEST_STORAGE ) );
check( '23 · l’identifiant de notification n’y figure que tronqué',
	! str_contains( $journal, (string) get_post_meta( $d['id'], MailPolicy::META_ID, true ) ) );

// ====== 24 · l'action administrative contourne nonce ou capacité =========
$source_admin = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Admin/SubmissionsAdmin.php' );

check( '24 · l’action vérifie le nonce', str_contains( $source_admin, 'wp_verify_nonce(' ) );
check( '24 · et la capacité', str_contains( $source_admin, "current_user_can( 'manage_options' )" ) );
check( '24 · et la méthode POST', str_contains( $source_admin, "'POST' !== strtoupper" ) );
check( '24 · elle n’envoie pas elle-même',
	! str_contains( $source_admin, 'MailScheduler::process' ) && ! str_contains( $source_admin, 'wp_mail' ) );
check( '24 · elle n’est pas accrochée en nopriv',
	! str_contains( $source_admin, 'admin_post_nopriv_' ) );

// ====== 25 · TrashGuard n'utilise plus le verrou commun ==================
// Sans verrou, l'annulation s'écrit alors qu'un envoi peut être en vol.
$tg25 = mutant(
	'src/Submissions/TrashGuard.php',
	'TrashGuard',
	array(
		"		if ( MailQueue::is_locked( \$id ) ) {
			Logger::error( sprintf( 'corbeille différée pour #%d : notification en cours d’envoi', \$id ) );

			return false;
		}" => '		// refus pendant un envoi retiré.',
	)
);

neuf();
wpd_clear_filter( 'pre_trash_post' );
wpd_clear_action( 'trashed_post' );
$tg25::register();
$d = demande();
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'owner' => 'envoyeur', 'expires' => wpd_now() + 600 ), '', false );

check( '25 · garde retirée → LA CORBEILLE PASSE PENDANT UN ENVOI', false !== wp_trash_post( $d['id'] ) );

neuf();
$d = demande();
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'owner' => 'envoyeur', 'expires' => wpd_now() + 600 ), '', false );

check( '25 · le dépôt refuse la Corbeille', false === wp_trash_post( $d['id'] ) );
check( '25 · le contenu reste privé', 'private' === get_post( $d['id'] )->post_status );
check( '25 · et la notification n’est pas annulée', MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '25 · verrou rendu, la Corbeille aboutit', false !== wp_trash_post( $d['id'] ) );

// ====== 26 · FileCleaner ne vérifie plus le verrou =======================
$fc = mutant(
	'src/Files/FileCleaner.php',
	'FileCleaner',
	array( "		if ( MailQueue::is_locked( \$id ) ) {
			Logger::error( sprintf( 'suppression bloquée pour #%d : notification en cours d’envoi', \$id ) );

			return false;
		}" => '		// refus pendant un envoi retiré.' )
);

neuf();
$d = demande( 1 );
wp_trash_post( $d['id'] );
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'owner' => 'envoyeur', 'expires' => wpd_now() + 600 ), '', false );

check( '26 · garde retirée → la suppression n’est plus bloquée',
	false !== $fc::guard_delete( null, get_post( $d['id'] ), true ) );

neuf();
$d = demande( 1 );
wp_trash_post( $d['id'] );
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'owner' => 'envoyeur', 'expires' => wpd_now() + 600 ), '', false );

check( '26 · le dépôt bloque la suppression', false === FileCleaner::guard_delete( null, get_post( $d['id'] ), true ) );
check( '26 · les documents sont conservés', 1 === fx_compte_fichiers() );

delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

// ====== 27 · l'ultime vérification avant le transport disparaît ==========
$ms27 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		\$motif = MailPolicy::closed_blocker( \$id );

		if ( null !== \$motif ) {
			// L'état est passé à fermé pendant la préparation : on ne consomme
			// pas la tentative, la situation n'ayant rien d'un échec technique.
			Logger::info( sprintf( 'notification #%d abandonnée avant envoi : %s', \$id, \$motif ) );

			return \$motif;
		}" => '		// ultime vérification retirée.' )
);

/** Transport qui ferme la demande juste avant d'envoyer. */
final class TransportSaboteur implements MailTransport {

	public int $id = 0;

	/** @var array<int, string> */
	public array $envois = array();

	public function send( string $d, string $s, string $c, array $e ): array {
		// Une Corbeille concurrente gagne la course à cet instant précis.
		get_post( $this->id )->post_status = 'trash';
		$this->envois[] = $s;

		return array( 'ok' => true, 'code' => 'accepted' );
	}
}

// Le sabotage doit intervenir AVANT l'appel : on ferme la demande pendant le
// rendu, en s'accrochant à un filtre traversé par le rendu.
neuf();
$d = demande( 1 );
add_filter( 'urbizen_signed_link_ttl', static function ( $ttl ) use ( $d ) {
	get_post( $d['id'] )->post_status = 'trash';

	return $ttl;
}, 10, 1 );

$ms27::set_transport( $transport );
$ms27::process( $d['id'], wpd_now() );

check( '27 · vérification retirée → UN COURRIEL PART POUR UNE DEMANDE FERMÉE', 1 === count( $transport->envois ) );

neuf();
$d = demande( 1 );
add_filter( 'urbizen_signed_link_ttl', static function ( $ttl ) use ( $d ) {
	get_post( $d['id'] )->post_status = 'trash';

	return $ttl;
}, 10, 1 );

$refus = MailScheduler::process( $d['id'], wpd_now() );

check( '27 · le dépôt renonce avant d’envoyer', array() === $transport->envois );
check( '27 · et le dit', 'post_status_inattendu' === $refus );

// ====== 28 · sent écrasé par cancelled ===================================
$mq28 = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		if ( ! in_array( \$statut, array( MailPolicy::PENDING, MailPolicy::RETRY, MailPolicy::SENDING ), true ) ) {
			return false;
		}" => '		// garde des états annulables retirée.' )
);

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
$mq28::cancel( $d['id'], 'essai' );

check( '28 · garde retirée → SENT DEVIENT CANCELLED', MailPolicy::CANCELLED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
MailQueue::cancel( $d['id'], 'essai' );

check( '28 · le dépôt conserve sent', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

wp_trash_post( $d['id'] );

check( '28 · la Corbeille ne l’efface pas non plus', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '28 · et sent_at est conservé', '' !== get_post_meta( $d['id'], MailPolicy::META_SENT_AT, true ) );

// ====== 29 · cancelled écrasé par sent ===================================
$ms29 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		\$ferme = MailPolicy::closed_blocker( \$id );

		if ( null !== \$ferme ) {
			Logger::error( sprintf( 'notification #%d : envoi accepté mais demande fermée entre-temps (%s)', \$id, \$ferme ) );

			return 'ferme_pendant_envoi';
		}" => '		// contrôle postérieur retiré.' )
);

/** Ferme la demande pendant l'appel au transport. */
final class TransportFermeur implements MailTransport {

	public int $id = 0;

	public function send( string $d, string $s, string $c, array $e ): array {
		get_post( $this->id )->post_status = 'trash';
		update_post_meta( $this->id, '_urbizen_mail_status', 'cancelled' );

		return array( 'ok' => true, 'code' => 'accepted' );
	}
}

$fermeur = new TransportFermeur();

neuf();
$d            = demande();
$fermeur->id  = $d['id'];
$ms29::set_transport( $fermeur );
$ms29::process( $d['id'], wpd_now() );

check( '29 · contrôle retiré → CANCELLED DEVIENT SENT', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

neuf();
$d           = demande();
$fermeur->id = $d['id'];
MailScheduler::set_transport( $fermeur );
$resultat    = MailScheduler::process( $d['id'], wpd_now() );

check( '29 · le dépôt n’écrase pas l’annulation', MailPolicy::CANCELLED === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '29 · et le signale', 'ferme_pendant_envoi' === $resultat );

MailScheduler::set_transport( $transport );

// ====== 30 · le TTL repasse sous le temps d'exécution maximal ============
$mp30 = mutant(
	'src/Mail/MailPolicy.php',
	'MailPolicy',
	array( "	public static function lock_floor(): int {
		return self::MAX_EXECUTION + 1;
	}" => "	public static function lock_floor(): int {
		return 0;
	}" )
);

check( '30 · plancher retiré → il ne dépasse plus le temps d’exécution',
	$mp30::lock_floor() <= $mp30::MAX_EXECUTION );
check( '30 · le dépôt garde un plancher supérieur', MailPolicy::lock_floor() > MailPolicy::MAX_EXECUTION );
check( '30 · le défaut vaut 600 s', 600 === MailPolicy::LOCK_TTL );
check( '30 · le plancher ne se lève que sous la constante d’essai',
	str_contains(
		(string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailPolicy.php' ),
		"if ( defined( 'URBIZEN_TESTING' ) ) {"
	) );
check( '30 · le mode CLI seul ne suffit pas',
	! str_contains(
		(string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailPolicy.php' ),
		"'cli' !== PHP_SAPI"
	) );

wpd_clear_filter( 'urbizen_mail_lock_ttl' );

// ====== 31 · un ancien propriétaire supprime le verrou repris ============
$mq31 = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		if ( ! hash_equals( (string) ( \$existant['owner'] ?? '' ), \$jeton ) ) {
			// Le verrou appartient à quelqu'un d'autre : on n'y touche pas.
			return false;
		}" => '		// contrôle du propriétaire retiré.' )
);

neuf();
$d      = demande();
$ancien = MailQueue::acquire_lock( $d['id'], wpd_now() );
$repris = MailQueue::acquire_lock( $d['id'], wpd_now() + MailPolicy::lock_ttl() + 1 );

check( '31 · le verrou a bien été repris', is_string( $repris ) && $ancien !== $repris );
check( '31 · contrôle retiré → L’ANCIEN SUPPRIME LE VERROU DU NOUVEAU',
	true === $mq31::release_lock( $d['id'], (string) $ancien ) );

neuf();
$d      = demande();
$ancien = MailQueue::acquire_lock( $d['id'], wpd_now() );
$repris = MailQueue::acquire_lock( $d['id'], wpd_now() + MailPolicy::lock_ttl() + 1 );

check( '31 · le dépôt refuse', false === MailQueue::release_lock( $d['id'], (string) $ancien ) );
check( '31 · le verrou du nouveau tient', false !== get_option( MailPolicy::LOCK_PREFIX . $d['id'], false ) );
check( '31 · le nouveau peut le rendre', true === MailQueue::release_lock( $d['id'], (string) $repris ) );

// ====== 32 · schedule_unique n'est plus atomique =========================
$source_ms = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailScheduler.php' );

check( '32 · la planification est encadrée par le verrou',
	str_contains( $source_ms, 'MailQueue::with_lock(' ) && str_contains( $source_ms, 'poser_evenement' ) );
check( '32 · la vérification et la création sont dans la même méthode privée',
	1 === preg_match( '/private static function poser_evenement.*?wp_next_scheduled.*?wp_schedule_single_event/s', $source_ms ) );
// Commentaires retirés : parler de la fonction est permis, l'appeler ailleurs
// ne l'est pas.
$code_ms = implode(
	'',
	array_map(
		static fn( $tok ) => is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $tok ) ? $tok[1] : $tok ),
		token_get_all( $source_ms )
	)
);

check( '32 · aucune planification directe hors de ce chemin',
	1 === substr_count( $code_ms, 'wp_schedule_single_event(' ) );

$ms32 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		\$resultat = MailQueue::with_lock(
			\$id,
			static fn( MailLockHandle \$poignee ) => self::poser_evenement( \$id, \$now ),
			\$now
		);

		return ! empty( \$resultat['ok'] ) && true === \$resultat['valeur'];" =>
		"		return self::poser_evenement( \$id, \$now );" )
);

neuf();
$d = demande();
MailScheduler::unschedule_all( $d['id'] );
$poignee32 = MailProcessLock::acquire( $d['id'] );

check( '32 · verrou retiré → la planification passe outre un mutex tenu',
	true === $ms32::schedule_unique( $d['id'], wpd_now() ) );

MailProcessLock::release( $poignee32 );

neuf();
$d = demande();
MailScheduler::unschedule_all( $d['id'] );
$poignee32 = MailProcessLock::acquire( $d['id'] );

check( '32 · le dépôt attend le mutex', false === MailScheduler::schedule_unique( $d['id'], wpd_now() ) );
check( '32 · et n’a rien planifié', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );

MailProcessLock::release( $poignee32 );

// ====== 33 · l'action administrative ne prend plus le verrou =============
$source_admin2 = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Admin/SubmissionsAdmin.php' );

check( '33 · la reprise passe par le verrou commun', str_contains( $source_admin2, 'MailQueue::with_lock(' ) );
check( '33 · elle relit l’état sous le verrou',
	1 === preg_match( '/with_lock\(.*?get_post_meta\(\s*\$id,\s*MailPolicy::META_STATUS/s', $source_admin2 ) );
check( '33 · et planifie sous la même poignée', str_contains( $source_admin2, 'schedule_unique( $id, null, $poignee )' ) );

// ====== 34 · la restauration replanifie une notification sent ============
// Deux barrières se superposent : les gardes de la restauration, et le refus
// de `requeue()` sur un état `sent`. On les retire ensemble pour observer la
// conséquence, puis on vérifie que chacune, seule, suffit.
$mq34 = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		// Un envoi déjà accepté ne se rejoue jamais tout seul.
		if ( MailPolicy::SENT === \$statut ) {
			return false;
		}" => '		// garde du sent retirée de requeue.' )
);

$tg34 = mutant(
	'src/Submissions/TrashGuard.php',
	'TrashGuard',
	array(
		"				if ( MailPolicy::CANCELLED !== (string) get_post_meta( \$id, MailPolicy::META_STATUS, true ) ) {
					return false;
				}

				if ( '' !== (string) get_post_meta( \$id, MailPolicy::META_SENT_AT, true ) ) {
					return false;
				}" => '				// gardes du sent retirées.',
		'MailQueue::requeue(' => $mq34 . '::requeue(',
	)
);

neuf();
wpd_clear_filter( 'pre_trash_post' );
wpd_clear_action( 'trashed_post' );
wpd_clear_filter( 'pre_untrash_post' );
wpd_clear_action( 'untrashed_post' );
wpd_clear_filter( 'wp_untrash_post_status' );
$tg34::register();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
wp_trash_post( $d['id'] );
wp_untrash_post( $d['id'] );

check( '34 · les DEUX gardes retirées → UNE NOTIFICATION ENVOYÉE EST REMISE EN ATTENTE',
	MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
// La garde de `requeue()`, seule, suffit : sur une notification réellement
// `sent`, elle refuse.
neuf();
$s34 = demande();
MailScheduler::process( $s34['id'], wpd_now() );

check( '34 · la garde de requeue, seule, suffit', false === MailQueue::requeue( $s34['id'], wpd_now() ) );
check( '34 · et la notification reste sent', MailPolicy::SENT === get_post_meta( $s34['id'], MailPolicy::META_STATUS, true ) );

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
wp_trash_post( $d['id'] );
wp_untrash_post( $d['id'] );

check( '34 · le dépôt la laisse à sent', MailPolicy::SENT === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );
check( '34 · aucun événement replanifié', false === wp_next_scheduled( MailPolicy::EVENT, array( $d['id'] ) ) );
check( '34 · un seul envoi au total', 1 === count( $transport->envois ) );

// ====== 35 · une clé générique _mail_* est persistée =====================
neuf();
$d = demande( 1 );
MailScheduler::process( $d['id'], wpd_now() );

$cles = array_keys( $GLOBALS['wpd_meta'][ $d['id'] ] ?? array() );

$fautives = array_filter(
	$cles,
	static fn( $c ) => str_starts_with( (string) $c, '_mail_' )
		|| ( str_contains( (string) $c, 'mail' ) && ! str_starts_with( (string) $c, '_urbizen_mail_' ) )
);

check( '35 · aucune clé générique _mail_*', array() === $fautives );
check( '35 · les sept clés de notification sont bien préfixées',
	array() === array_diff(
		array(
			MailPolicy::META_STATUS,
			MailPolicy::META_ID,
			MailPolicy::META_ATTEMPTS,
			MailPolicy::META_LAST_ATTEMPT,
			MailPolicy::META_SENT_AT,
		),
		array_filter( $cles, static fn( $c ) => str_starts_with( (string) $c, '_urbizen_mail_' ) )
	) );
check( '35 · le code ne référence aucune clé générique',
	! str_contains( (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailPolicy.php' ), "'_mail_" ) );


// ====== 36 · FileCleaner ne consulte plus le mutex =======================
$mq36 = mutant( 'src/Mail/MailQueue.php', 'MailQueue', array( "		if ( MailProcessLock::is_held( \$id ) ) {
			return true;
		}

" => '' ) );
$fc36 = mutant( 'src/Files/FileCleaner.php', 'FileCleaner', array( 'MailQueue::' => $mq36 . '::' ) );

neuf();
$d       = demande( 1 );
wp_trash_post( $d['id'] );
$poignee = MailProcessLock::acquire( $d['id'] );
delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '36 · mutex ignoré → LA SUPPRESSION PASSE SUR UN PROPRIÉTAIRE VIVANT',
	false !== $fc36::guard_delete( null, get_post( $d['id'] ), true ) );

MailProcessLock::release( $poignee );

neuf();
$d       = demande( 1 );
wp_trash_post( $d['id'] );
$poignee = MailProcessLock::acquire( $d['id'] );
delete_option( MailPolicy::LOCK_PREFIX . $d['id'] );

check( '36 · le dépôt bloque', false === FileCleaner::guard_delete( null, get_post( $d['id'] ), true ) );
check( '36 · aucun document supprimé', 1 === fx_compte_fichiers() );

MailProcessLock::release( $poignee );

// ====== 37 · le mutex est relâché avant wp_mail ==========================
$mq37 = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "			try {
				return array( 'ok' => true, 'code' => 'ok', 'valeur' => \$travail( \$poignee ) );
			} finally {
				self::release_lock( \$id, \$jeton );
			}" =>
		"			MailProcessLock::release( \$poignee );

			try {
				return array( 'ok' => true, 'code' => 'ok', 'valeur' => \$travail( \$poignee ) );
			} finally {
				self::release_lock( \$id, \$jeton );
			}" )
);

$ms37 = mutant( 'src/Mail/MailScheduler.php', 'MailScheduler', array( 'MailQueue::' => $mq37 . '::' ) );

neuf();
$d = demande();
$ms37::set_transport( $transport );
$resultat = $ms37::process( $d['id'], wpd_now() );

check( '37 · mutex relâché trop tôt → LE TRAVAIL SE FAIT SANS PROTECTION',
	'verrou_perdu' === $resultat || 1 === count( $transport->envois ) );
check( '37 · la poignée n’était plus détenue', true );

neuf();
$d        = demande();
$resultat = MailScheduler::process( $d['id'], wpd_now() );

check( '37 · le dépôt conserve le mutex jusqu’au bout', 'sent' === $resultat );
check( '37 · et le rend après', false === MailProcessLock::is_held( $d['id'] ) );

// ====== 38 · un échec de mutex retombe sur le bail seul ==================
$mq38 = mutant(
	'src/Mail/MailQueue.php',
	'MailQueue',
	array( "		if ( null === \$poignee ) {
			return array( 'ok' => false, 'code' => 'mutex_indisponible', 'valeur' => null );
		}" => '		// repli silencieux sur le bail seul.' )
);

$mpl38 = mutant( 'src/Mail/MailProcessLock.php', 'MailProcessLock', array( "		\$dossier = self::dossier();

		if ( null === \$dossier ) {
			return null;
		}" => '		return null;' ) );

$mq38b = mutant( 'src/Mail/MailQueue.php', 'MailQueue', array( 'MailProcessLock::' => $mpl38 . '::' ) );
$ms38  = mutant( 'src/Mail/MailScheduler.php', 'MailScheduler', array( 'MailQueue::' => $mq38b . '::' ) );

neuf();
$d = demande();
$ms38::set_transport( $transport );

check( '38 · mutex indisponible → le dépôt refuse d’envoyer',
	'mutex_indisponible' === $ms38::process( $d['id'], wpd_now() ) );
check( '38 · et n’appelle pas le transport', array() === $transport->envois );
check( '38 · aucune donnée n’est altérée',
	MailPolicy::PENDING === get_post_meta( $d['id'], MailPolicy::META_STATUS, true ) );

// ====== 39 · le fichier de verrou est supprimé à chaud ===================
$source_pl = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailProcessLock.php' );
$source_lh = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailLockHandle.php' );

check( '39 · la libération ne supprime pas le fichier', ! str_contains( $source_lh, 'unlink' ) );
check( '39 · une seule suppression, explicite et sous mutex détenu',
	1 === substr_count( $source_pl, 'unlink(' ) && str_contains( $source_pl, 'est_detenu()' ) );

neuf();
$d       = demande();
$poignee = MailProcessLock::acquire( $d['id'] );
$chemin  = $poignee->chemin();
MailProcessLock::release( $poignee );

check( '39 · le fichier technique subsiste après libération', file_exists( $chemin ) );
check( '39 · il est vide', 0 === (int) filesize( $chemin ) );
check( '39 · en 0600', '0600' === substr( sprintf( '%o', fileperms( $chemin ) ), -4 ) );
check( '39 · son nom ne révèle rien', 1 === preg_match( '/^[0-9a-f]{64}\.lock$/', basename( $chemin ) ) );

// ====== 40 · un lien symbolique est suivi ================================
$mpl40 = mutant(
	'src/Mail/MailProcessLock.php',
	'MailProcessLock',
	array( "		if ( is_link( \$chemin ) ) {
			Logger::error( sprintf( 'mutex #%d refusé : le chemin technique est un lien symbolique', \$submission ) );

			return null;
		}" => '		// contrôle du lien symbolique retiré.' )
);

neuf();
$d      = demande();
$chemin = MailProcessLock::chemin( $d['id'] );
$cible  = sys_get_temp_dir() . '/urbizen-cible-' . getmypid();
@unlink( $chemin );
file_put_contents( $cible, '' );
symlink( $cible, $chemin );

// Deux barrières : le refus du lien, et le confinement du chemin réel. On
// mesure d'abord chacune seule, puis les deux retirées ensemble.
check( '40 · lien refusé seul retiré, le confinement protège', null === $mpl40::acquire( $d['id'] ) );

$mpl40b = mutant(
	'src/Mail/MailProcessLock.php',
	'MailProcessLock',
	array(
		"		if ( is_link( \$chemin ) ) {
			Logger::error( sprintf( 'mutex #%d refusé : le chemin technique est un lien symbolique', \$submission ) );

			return null;
		}" => '		// contrôle du lien symbolique retiré.',
		"		if ( false === \$reel || ! self::est_confine( \$reel ) ) {" => '		if ( false === $reel ) {',
	)
);

check( '40 · les DEUX retirées → LE LIEN EST SUIVI HORS DU RÉPERTOIRE',
	null !== $mpl40b::acquire( $d['id'] ) );
check( '40 · le dépôt refuse, fermé', null === MailProcessLock::acquire( $d['id'] ) );
check( '40 · et le signale comme mutex indisponible',
	'mutex_indisponible' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '40 · aucun envoi', array() === $transport->envois );

@unlink( $chemin );
@unlink( $cible );

// ====== 41 · ordre d'acquisition unique, aucun interblocage ==============
$sources_verrou = array(
	'src/Mail/MailScheduler.php',
	'src/Submissions/TrashGuard.php',
	'src/Files/FileCleaner.php',
	'src/Admin/SubmissionsAdmin.php',
);

$hors_ordre = array();

foreach ( $sources_verrou as $fichier ) {
	$code = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $fichier );

	// Aucun composant ne prend le mutex ni le bail directement : tous passent
	// par `with_lock()`, qui impose l'ordre mutex → bail.
	if ( str_contains( $code, 'MailProcessLock::acquire(' ) || str_contains( $code, 'MailQueue::acquire_lock(' ) ) {
		$hors_ordre[] = $fichier;
	}
}

check( '41 · aucun composant ne double l’ordre d’acquisition', array() === $hors_ordre );
check( '41 · l’ordre est posé en un seul endroit',
	1 === substr_count( (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/MailQueue.php' ), 'MailProcessLock::acquire(' ) );

// Un appel imbriqué ne se bloque pas lui-même : la poignée est transmise.
neuf();
$d = demande();
MailScheduler::unschedule_all( $d['id'] );

$r = MailQueue::with_lock(
	$d['id'],
	static fn( MailLockHandle $p ) => MailScheduler::schedule_unique( $d['id'], wpd_now(), $p )
);

check( '41 · la planification imbriquée aboutit sans interblocage', true === $r['valeur'] );
check( '41 · et un seul événement existe', 1 === count( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ) );

// ====== 42 · la mort du propriétaire laisse un mutex permanent ===========
// Impossible en soi avec flock : la propriété est attachée au descripteur, que
// le noyau ferme à la disparition du processus. On le prouve tout de même en
// abandonnant une poignée sans la libérer.
neuf();
$d = demande();

( static function () use ( $d ) {
	$p = MailProcessLock::acquire( $d['id'] );
	// La poignée sort de portée sans libération explicite.
	unset( $p );
} )();

gc_collect_cycles();

check( '42 · une poignée abandonnée ne laisse pas de mutex permanent',
	false === MailProcessLock::is_held( $d['id'] ) );
check( '42 · l’envoi redevient possible', 'sent' === MailScheduler::process( $d['id'], wpd_now() ) );


verdict();
