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
use Urbizen\Platform\Mail\MailPolicy;
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
		"			\$motif = MailPolicy::blocker( \$id, \$now );

			if ( null !== \$motif ) {
				return \$motif;
			}" => '			// relecture sous verrou retirée.',
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

// ====== 8 · le verrou n'est plus pris ====================================
$ms2 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "		if ( ! MailQueue::acquire_lock( \$id, \$now ) ) {
			// Une autre requête traite cette notification en ce moment même.
			return 'verrou_occupe';
		}" => '		// verrou retiré.' )
);

neuf();
$d = demande();
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'expires' => wpd_now() + 300 ), '', false );
$ms2::set_transport( $transport );
$ms2::process( $d['id'], wpd_now() );

check( '8 · verrou retiré → LE SECOND PROCESSUS ENVOIE QUAND MÊME', 1 === count( $transport->envois ) );

neuf();
$d = demande();
add_option( MailPolicy::LOCK_PREFIX . $d['id'], array( 'expires' => wpd_now() + 300 ), '', false );

check( '8 · le dépôt s’arrête', 'verrou_occupe' === MailScheduler::process( $d['id'], wpd_now() ) );
check( '8 · et n’envoie rien', array() === $transport->envois );

// ====== 9 · deux processus envoient deux courriels ordinaires =============
// Deux barrières se superposent : le verrou, et la fraîcheur de l'état
// « sending ». On les retire toutes les deux pour observer le doublon, puis
// on vérifie que chacune, seule, l'empêche.
$mp_concurrent = mutant(
	'src/Mail/MailPolicy.php',
	'MailPolicy',
	array( "	public static function sending_is_stale( int \$id, int \$now ): bool {" =>
		"	public static function sending_is_stale( int \$id, int \$now ): bool {
		return true;" )
);

$ms_concurrent = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array(
		"		if ( ! MailQueue::acquire_lock( \$id, \$now ) ) {
			// Une autre requête traite cette notification en ce moment même.
			return 'verrou_occupe';
		}" => '		// verrou retiré.',
		'MailPolicy::' => $mp_concurrent . '::',
	)
);

neuf();
$d = demande();
$ms_concurrent::set_transport( $transport );
$ms_concurrent::process( $d['id'], wpd_now() );
update_post_meta( $d['id'], $mp_concurrent::META_STATUS, $mp_concurrent::SENDING );
$ms_concurrent::process( $d['id'], wpd_now() );

check( '9 · sans verrou ni fraîcheur → DEUX ENVOIS ORDINAIRES', 2 === count( $transport->envois ) );

// Verrou seul retiré : la fraîcheur de l'état protège encore.
neuf();
$d = demande();
$ms2::set_transport( $transport );
$ms2::process( $d['id'], wpd_now() );
update_post_meta( $d['id'], MailPolicy::META_STATUS, MailPolicy::SENDING );
update_post_meta( $d['id'], MailPolicy::META_LAST_ATTEMPT, gmdate( 'Y-m-d H:i:s', wpd_now() ) );
$ms2::process( $d['id'], wpd_now() );

check( '9 · verrou seul retiré, la fraîcheur protège', 1 === count( $transport->envois ) );

neuf();
$d = demande();
MailScheduler::process( $d['id'], wpd_now() );
MailScheduler::process( $d['id'], wpd_now() );

check( '9 · le dépôt n’envoie qu’une fois', 1 === count( $transport->envois ) );

// ====== 10 · true de wp_mail n'aboutit plus à sent =======================
$ms3 = mutant(
	'src/Mail/MailScheduler.php',
	'MailScheduler',
	array( "			if ( ! empty( \$resultat['ok'] ) ) {
				MailQueue::mark_sent( \$id, \$now );" => "			if ( ! empty( \$resultat['ok'] ) ) {" )
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
	array( "			if ( ! empty( \$resultat['ok'] ) ) {" => '			if ( true ) {' )
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
			MailQueue::release_lock( \$id );

			return 'post_absent';
		}" => '		// garde du post absent retirée.' )
);

neuf();
$d  = demande();
$id = $d['id'];
wp_trash_post( $id );
wp_delete_post( $id, true );
add_option( MailPolicy::LOCK_PREFIX . $id, array( 'expires' => wpd_now() + 300 ), '', false );
$ms5::set_transport( $transport );
$ms5::process( $id, wpd_now() );

check( '21 · garde retirée → LE VERROU D’UNE DEMANDE SUPPRIMÉE SUBSISTE',
	false !== get_option( MailPolicy::LOCK_PREFIX . $id, false ) );

neuf();
$d  = demande();
$id = $d['id'];
wp_trash_post( $id );
wp_delete_post( $id, true );
add_option( MailPolicy::LOCK_PREFIX . $id, array( 'expires' => wpd_now() + 300 ), '', false );

check( '21 · le dépôt s’arrête net', 'post_absent' === MailScheduler::process( $id, wpd_now() ) );
check( '21 · et nettoie le verrou', false === get_option( MailPolicy::LOCK_PREFIX . $id, false ) );
check( '21 · et n’envoie rien', array() === $transport->envois );

// ====== 22 · une notification annulée est envoyée pendant la Corbeille ====
$tg = mutant(
	'src/Submissions/TrashGuard.php',
	'TrashGuard',
	array( "		MailQueue::cancel( \$id, 'demande_en_corbeille' );
		MailScheduler::unschedule( \$id );" => '		// annulation de la notification retirée.' )
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

verdict();
