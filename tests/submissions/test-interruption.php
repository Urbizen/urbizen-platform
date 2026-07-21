<?php
/**
 * Banc d'essai des interruptions brutales.
 *
 * Une exception se rattrape ; une coupure de processus, non. Quand PHP est
 * tué, que le serveur redémarre ou que la connexion tombe pendant l'écriture,
 * ni `catch`, ni `finally`, ni destructeur ne s'exécutent. Le rattrapage ne
 * peut donc pas vivre dans la requête : il lui faut un état **durable**, relu
 * par une requête ultérieure.
 *
 * Ce banc reproduit exactement cela. Chaque scénario construit un état
 * intermédiaire **persistant**, abandonne le traitement sans exécuter aucun
 * rollback, remet les services à zéro comme entre deux requêtes, puis exécute
 * le récupérateur — comme le ferait un cron ou une visite ultérieure.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Http\FileDownloadController as D;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TransactionRecovery;

/**
 * Repart d'un état propre, stockage compris.
 */
function neuf(): void {
	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
	Storage::set_mover( $GLOBALS['fx_mover'] );
}

/**
 * Simule la fin brutale d'une requête et le début d'une autre.
 *
 * Aucun rollback n'est exécuté : seuls les états écrits sur disque et en base
 * subsistent, exactement comme après une coupure. Les objets en mémoire sont
 * réinitialisés comme au démarrage d'un nouveau processus.
 *
 * @return void
 */
function nouvelle_requete(): void {
	Storage::reset();
	Storage::set_mover( $GLOBALS['fx_mover'] );
	\Urbizen\Platform\Files\FileCleaner::reset();
}

/**
 * Données nettoyées d'une demande d'essai.
 *
 * @return array{clean:array<string,mixed>,pricing:array<string,mixed>}
 */
function jeu(): array {
	$v = \Urbizen\Platform\Forms\Validator::validate(
		\Urbizen\Platform\Forms\FormRegistry::get( 'conception' ),
		array(
			'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non',
			'nom' => 'Camille Fictif', 'email' => 'camille@exemple.test', 'rgpd' => '1',
		)
	);

	return array( 'clean' => $v['clean'], 'pricing' => $v['pricing'] );
}

/**
 * Vérifie qu'une transaction abandonnée n'a rien laissé.
 *
 * @param string $point Intitulé du point d'arrêt.
 * @return void
 */
function tout_est_propre( string $point ): void {
	check( "$point → aucun post partiel", array() === $GLOBALS['wpd_posts'] );
	check( "$point → aucun fichier final", 0 === fx_compte_fichiers() );
	check( "$point → aucun staging", 0 === fx_compte_staging() );
	check( "$point → aucune réservation",
		0 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX ) ) ) );
	check( "$point → aucune donnée personnelle résiduelle",
		! preg_match( '/Camille|camille@exemple\.test/', (string) wp_json_encode( array( $GLOBALS['wpd_meta'], $GLOBALS['wpd_options'] ) ) ) );
}

/** Instant suffisamment ancien pour qu'une transaction soit jugée abandonnée. */
$vieux   = wpd_now() - Retention::ABANDON_TTL - 60;
$horodat = gmdate( 'Y-m-d H:i:s', $vieux );
$jeu     = jeu();

// ======================================================================
// A · STAGING CRÉÉ, AUCUNE RÉFÉRENCE
// ======================================================================
neuf();
$staging = Storage::open_staging();
@touch( (string) $staging, $vieux );

check( 'A · un staging existe', 1 === fx_compte_staging() );

nouvelle_requete();
Retention::run_daily( wpd_now() );

tout_est_propre( 'A' );

// ======================================================================
// B · RÉFÉRENCE RESERVED, AUCUN POST
// ======================================================================
neuf();
$ref_b = SubmissionRepository::next_reference( $vieux );

check( 'B · une référence est réservée', '' !== $ref_b );
check( 'B · elle est à l’état reserved', 'reserved' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref_b )['state'] ?? '' ) );

nouvelle_requete();
Retention::run_daily( wpd_now() );

tout_est_propre( 'B' );

// ======================================================================
// C · POST PROCESSING CRÉÉ
// ======================================================================
neuf();
$c = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-c' ) );

check( 'C · la demande est en processing', SubmissionPostType::STATUS_PROCESSING === get_post_meta( (int) $c['id'], '_urbizen_status', true ) );
check( 'C · la référence reste reserved', 'reserved' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'] )['state'] ?? '' ) );

nouvelle_requete();
Retention::run_daily( wpd_now() );

tout_est_propre( 'C' );

// ======================================================================
// D et E · FICHIERS DÉPLACÉS DANS LE RÉPERTOIRE FINAL
// ======================================================================
foreach ( array( 'D' => 1, 'E' => 3 ) as $point => $nombre ) {
	neuf();

	$c       = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-' . $point ) );
	$staging = Storage::open_staging();
	$lot     = array();

	for ( $i = 0; $i < $nombre; $i++ ) {
		$v     = \Urbizen\Platform\Files\UploadPolicy::validate_one( array( 'block' => 'photos', 'name' => "p$i.pdf", 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
		$lot[] = Storage::stage( (string) $staging, $v['file'], $i );
	}

	Storage::finalize( (string) $staging, (string) $c['reference'], $lot, $vieux );

	check( "$point · $nombre fichier(s) sont dans le répertoire final", $nombre === fx_compte_fichiers() );
	check( "$point · la référence est encore reserved", 'reserved' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'] )['state'] ?? '' ) );

	nouvelle_requete();
	Retention::run_daily( wpd_now() );

	tout_est_propre( $point );
}

// ======================================================================
// F · MÉTADONNÉES DE FICHIERS ÉCRITES
// ======================================================================
neuf();
$c       = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-f' ) );
$staging = Storage::open_staging();
$v       = \Urbizen\Platform\Files\UploadPolicy::validate_one( array( 'block' => 'photos', 'name' => 'p.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
$meta    = Storage::finalize( (string) $staging, (string) $c['reference'], array( Storage::stage( (string) $staging, $v['file'], 0 ) ), $vieux );
SubmissionRepository::set_files( (int) $c['id'], (array) $meta );

check( 'F · les métadonnées sont écrites', 1 === (int) get_post_meta( (int) $c['id'], '_urbizen_files_count', true ) );
check( 'F · la demande reste processing', SubmissionPostType::STATUS_PROCESSING === get_post_meta( (int) $c['id'], '_urbizen_status', true ) );

nouvelle_requete();
Retention::run_daily( wpd_now() );

tout_est_propre( 'F' );

// ======================================================================
// G · TRANSACTION COMMITTED MAIS RÉFÉRENCE ENCORE RESERVED
// ======================================================================
// Le point de non-retour n'est pas le marqueur `committed` : c'est
// l'attribution définitive de la référence. Une réponse de succès ne part
// qu'après elle. Une référence restée `reserved` signifie donc que la
// transaction n'a jamais abouti — et la conserver maintiendrait des documents
// et des données personnelles sans finalité.
neuf();
$c       = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-g' ) );
$staging = Storage::open_staging();
$v       = \Urbizen\Platform\Files\UploadPolicy::validate_one( array( 'block' => 'photos', 'name' => 'p.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
$meta_g  = Storage::finalize( (string) $staging, (string) $c['reference'], array( Storage::stage( (string) $staging, $v['file'], 0 ) ), $vieux );
SubmissionRepository::set_files( (int) $c['id'], (array) $meta_g );

$tx          = SubmissionRepository::transaction( (int) $c['id'] );
$tx['state'] = 'committed';
update_post_meta( (int) $c['id'], '_urbizen_transaction', (string) wp_json_encode( $tx ) );

check( 'G · la transaction porte committed', 'committed' === SubmissionRepository::transaction( (int) $c['id'] )['state'] );
check( 'G · mais la référence est encore reserved', 'reserved' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'] )['state'] ?? '' ) );
check( 'G · un document est en place', 1 === fx_compte_fichiers() );

nouvelle_requete();
$bilan = Retention::run_daily( wpd_now() );

check( 'G · LA TRANSACTION EST ANNULÉE', 1 === $bilan['abandons'] );

tout_est_propre( 'G' );

// ======================================================================
// H et I · DEMANDE VALIDÉE, RÉFÉRENCE ATTRIBUÉE
// ======================================================================
neuf();
$r = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );

check( 'H · la demande est validée', $r->is_success() );
check( 'H · la référence est attribuée', 'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $r->reference() )['state'] ?? '' ) );

// On vieillit artificiellement la demande, puis on coupe « avant la réponse
// HTTP » : tout est déjà en base et sur disque.
update_post_meta( $r->id(), '_urbizen_created_at_gmt', $horodat );

nouvelle_requete();
$bilan = Retention::run_daily( wpd_now() );

check( 'I · aucune récupération n’a lieu', 0 === $bilan['abandons'] );
check( 'I · LA DEMANDE VALIDÉE EST CONSERVÉE', null !== get_post( $r->id() ) );
check( 'I · LA RÉFÉRENCE ATTRIBUÉE EST CONSERVÉE', 'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $r->reference() )['state'] ?? '' ) );
check( 'I · LE DOCUMENT VALIDÉ EST CONSERVÉ', 1 === fx_compte_fichiers() );
check( 'I · aucune seconde référence n’est créée',
	1 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX ) ) ) );

// ======================================================================
// SITUATION INCOHÉRENTE : ATTRIBUTED MAIS ENCORE PROCESSING
// ======================================================================
neuf();
$r = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );

// On force l'incohérence : la référence est attribuée, mais l'état interne est
// resté « processing ».
update_post_meta( $r->id(), '_urbizen_status', SubmissionPostType::STATUS_PROCESSING );
update_post_meta( $r->id(), '_urbizen_created_at_gmt', $horodat );

$tx          = SubmissionRepository::transaction( $r->id() );
$tx['state'] = 'processing';
update_post_meta( $r->id(), '_urbizen_transaction', (string) wp_json_encode( $tx ) );

nouvelle_requete();
$bilan = Retention::run_daily( wpd_now() );

check( 'incohérence · rien n’est récupéré', 0 === $bilan['abandons'] );
check( 'incohérence · LA DEMANDE EST CONSERVÉE', null !== get_post( $r->id() ) );
check( 'incohérence · LE DOCUMENT EST CONSERVÉ', 1 === fx_compte_fichiers() );
check( 'incohérence · la réservation attribuée est conservée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $r->reference() )['state'] ?? '' ) );
check( 'incohérence · elle est signalée techniquement', str_contains( journal(), 'incoherent' ) );
check( 'incohérence · l’état bloquant est posé', 'incoherent' === get_post_meta( $r->id(), '_urbizen_status', true ) );

// ======================================================================
// GARDE-FOUS DE LA RÉCUPÉRATION
// ======================================================================

// Une transaction récente n'est pas touchée.
neuf();
$c = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => wpd_now(), 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-recent' ) );

nouvelle_requete();
check( 'une transaction récente n’est pas récupérée', 0 === Retention::recover_abandoned( wpd_now() ) );
check( 'sa demande est conservée', null !== get_post( (int) $c['id'] ) );

// Une réservation rattachée à une autre demande bloque la récupération.
neuf();
$c = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-x' ) );
update_option(
	SubmissionRepository::RESERVATION_PREFIX . $c['reference'],
	array( 'state' => 'reserved', 'at' => $vieux, 'post' => (int) $c['id'] + 999 ),
	false
);

nouvelle_requete();
check( 'une réservation rattachée ailleurs bloque la récupération', 0 === Retention::recover_abandoned( wpd_now() ) );
check( 'la demande est conservée', null !== get_post( (int) $c['id'] ) );
check( 'le rattachement incohérent est signalé', str_contains( journal(), 'incoherent' ) );

// Idempotence.
neuf();
SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-i' ) );

nouvelle_requete();
check( 'la récupération traite la transaction', 1 === Retention::recover_abandoned( wpd_now() ) );
check( 'un second passage ne fait rien', 0 === Retention::recover_abandoned( wpd_now() ) );
check( 'un troisième non plus', 0 === Retention::recover_abandoned( wpd_now() ) );

// Le journal ne contient rien de personnel.
check( 'le journal de récupération ne contient aucune donnée personnelle',
	! preg_match( '/Camille|camille@exemple\.test|photo\.jpg|' . preg_quote( URBIZEN_TEST_STORAGE, '/' ) . '/', journal() ) );

// ======================================================================
// J · RÉFÉRENCE ATTRIBUTED MAIS ÉTAT INCOHÉRENT
// ======================================================================
// Quatre incohérences distinctes, toutes traitées de la même façon :
// conservation prudente, aucun téléchargement, signalement technique.
$incoherences = array(
	'transaction non committed' => static function ( int $id ): void {
		$tx          = SubmissionRepository::transaction( $id );
		$tx['state'] = 'processing';
		update_post_meta( $id, '_urbizen_transaction', (string) wp_json_encode( $tx ) );
	},
	'documents restés pending'  => static function ( int $id ): void {
		update_post_meta( $id, '_urbizen_files_status', 'pending' );
	},
	'référence divergente'      => static function ( int $id ): void {
		$tx              = SubmissionRepository::transaction( $id );
		$tx['reference'] = 'URB-2020-0001';
		update_post_meta( $id, '_urbizen_transaction', (string) wp_json_encode( $tx ) );
	},
	'rattachement incorrect'    => static function ( int $id ): void {
		$ref = (string) get_post_meta( $id, '_urbizen_reference', true );
		update_option( SubmissionRepository::RESERVATION_PREFIX . $ref, array( 'state' => 'attributed', 'at' => '', 'post' => $id + 500 ), false );
	},
);

foreach ( $incoherences as $libelle => $casser ) {
	neuf();
	$r  = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );
	$lu = SubmissionRepository::get( $r->id() );
	$p  = SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() );
	parse_str( (string) wp_parse_url( $p, PHP_URL_QUERY ), $params_j );

	check( "J · [$libelle] le lien fonctionne avant l’incohérence", null !== D::locate( $params_j, wpd_now() ) );

	$casser( $r->id() );
	update_post_meta( $r->id(), '_urbizen_status', SubmissionPostType::STATUS_PROCESSING );
	update_post_meta( $r->id(), '_urbizen_created_at_gmt', $horodat );

	nouvelle_requete();
	$bilan = TransactionRecovery::run( wpd_now() );

	check( "J · [$libelle] classée incohérente", 1 === $bilan['incoherent'] );
	check( "J · [$libelle] la demande est conservée", null !== get_post( $r->id() ) );
	check( "J · [$libelle] le document est conservé", 1 === fx_compte_fichiers() );
	check( "J · [$libelle] AUCUN TÉLÉCHARGEMENT POSSIBLE", null === D::locate( $params_j, wpd_now() ) );
	check( "J · [$libelle] l’état bloquant est posé", TransactionRecovery::INCOHERENT === get_post_meta( $r->id(), '_urbizen_status', true ) );
}

// ======================================================================
// K · NETTOYAGE IMPOSSIBLE PENDANT UNE RÉCUPÉRATION
// ======================================================================
neuf();
// Ordre de production : le staging est ouvert d'abord, puis rattaché à la
// transaction. C'est ce rattachement qui permet à la récupération de le
// retrouver après une coupure.
$staging = Storage::open_staging();
$c       = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-k', 'staging' => $staging ) );
$lot_k   = array();

foreach ( array( 'a', 'b' ) as $i => $nom ) {
	$v       = \Urbizen\Platform\Files\UploadPolicy::validate_one( array( 'block' => 'photos', 'name' => "$nom.pdf", 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
	$lot_k[] = Storage::stage( (string) $staging, $v['file'], $i );
}

$meta_k = Storage::finalize( (string) $staging, (string) $c['reference'], $lot_k, $vieux );
SubmissionRepository::set_files( (int) $c['id'], (array) $meta_k );

$tx          = SubmissionRepository::transaction( (int) $c['id'] );
$tx['state'] = 'committed';
update_post_meta( (int) $c['id'], '_urbizen_transaction', (string) wp_json_encode( $tx ) );

$dossier_k = URBIZEN_TEST_STORAGE . '/conception/' . $c['reference'] . '/photos';
@chmod( $dossier_k, 0500 );

check( 'K · deux documents sont en place', 2 === fx_compte_fichiers() );

nouvelle_requete();
$bilan = TransactionRecovery::run( wpd_now() );

check( 'K · la récupération échoue proprement', 1 === $bilan['failed'] && 0 === $bilan['rollback'] );
check( 'K · LE POST EST CONSERVÉ', null !== get_post( (int) $c['id'] ) );
check( 'K · les métadonnées de transaction sont conservées', array() !== SubmissionRepository::transaction( (int) $c['id'] ) );
check( 'K · les chemins relatifs sont conservés', 2 === count( SubmissionRepository::decode_files( (int) $c['id'] ) ) );
check( 'K · LA RÉSERVATION RESTE RESERVED', 'reserved' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'] )['state'] ?? '' ) );
check( 'K · aucune référence n’est passée à attributed',
	0 === count( array_filter(
		array_keys( $GLOBALS['wpd_options'] ),
		static fn( $k ) => str_starts_with( $k, SubmissionRepository::RESERVATION_PREFIX ) && 'attributed' === ( get_option( $k )['state'] ?? '' )
	) ) );
check( 'K · l’état recovery_failed est posé', TransactionRecovery::RECOVERY_FAILED === get_post_meta( (int) $c['id'], '_urbizen_status', true ) );
check( 'K · les documents restent physiquement présents', 2 === fx_compte_fichiers() );

// Aucun téléchargement n'est possible dans cet état.
$lu_k = SubmissionRepository::get( (int) $c['id'] );
$u_k  = SignedLink::url( (int) $c['id'], $lu_k['files'][0]['id'], wpd_now() );
parse_str( (string) wp_parse_url( $u_k, PHP_URL_QUERY ), $params_k );

check( 'K · AUCUN TÉLÉCHARGEMENT N’EST POSSIBLE', null === D::locate( $params_k, wpd_now() ) );

// Le récupérateur reste idempotent tant que la panne dure.
nouvelle_requete();
$bilan = TransactionRecovery::run( wpd_now() );

check( 'K · une seconde tentative échoue de la même façon', 1 === $bilan['failed'] );
check( 'K · rien n’a bougé', 2 === fx_compte_fichiers() && null !== get_post( (int) $c['id'] ) );

// Après rétablissement des permissions, la tentative suivante aboutit.
@chmod( $dossier_k, 0700 );
nouvelle_requete();
$bilan = TransactionRecovery::run( wpd_now() );

check( 'K · APRÈS CORRECTION, LA RÉCUPÉRATION ABOUTIT', 1 === $bilan['rollback'] );
tout_est_propre( 'K' );

// Un nettoyage partiel n'est jamais compté comme un succès : un seul document
// effacé sur deux laisse la transaction en échec.
neuf();
$staging = Storage::open_staging();
$c       = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-k2', 'staging' => $staging ) );
$lot_k   = array();

foreach ( array( 'a', 'b' ) as $i => $nom ) {
	$v       = \Urbizen\Platform\Files\UploadPolicy::validate_one( array( 'block' => 0 === $i ? 'photos' : 'urbanisme', 'name' => "$nom.pdf", 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
	$lot_k[] = Storage::stage( (string) $staging, $v['file'], $i );
}

$meta_k = Storage::finalize( (string) $staging, (string) $c['reference'], $lot_k, $vieux );
SubmissionRepository::set_files( (int) $c['id'], (array) $meta_k );

// Seul le second bloc résiste : le premier document sera bien effacé.
@chmod( URBIZEN_TEST_STORAGE . '/conception/' . $c['reference'] . '/urbanisme', 0500 );

nouvelle_requete();
$bilan = TransactionRecovery::run( wpd_now() );

check( 'K · un nettoyage partiel n’est PAS un succès', 1 === $bilan['failed'] && 0 === $bilan['rollback'] );
check( 'K · le post survit au nettoyage partiel', null !== get_post( (int) $c['id'] ) );
check( 'K · la réservation survit', 'reserved' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'] )['state'] ?? '' ) );

@chmod( URBIZEN_TEST_STORAGE . '/conception/' . $c['reference'] . '/urbanisme', 0700 );
nouvelle_requete();
TransactionRecovery::run( wpd_now() );
tout_est_propre( 'K partiel' );

// ======================================================================
// H · NORMALISATION IDEMPOTENTE
// ======================================================================
neuf();
$r = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );

// L'interruption a précédé la dernière écriture de statut.
update_post_meta( $r->id(), '_urbizen_status', SubmissionPostType::STATUS_PROCESSING );
update_post_meta( $r->id(), '_urbizen_created_at_gmt', $horodat );

nouvelle_requete();
$bilan = TransactionRecovery::run( wpd_now() );

check( 'H · la demande cohérente est normalisée', 1 === $bilan['normalized'] );
check( 'H · son statut devient received', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $r->id(), '_urbizen_status', true ) );
check( 'H · le document est conservé', 1 === fx_compte_fichiers() );
check( 'H · la référence reste attribuée', 'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $r->reference() )['state'] ?? '' ) );
check( 'H · une seule référence existe',
	1 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $k ) => str_starts_with( $k, SubmissionRepository::RESERVATION_PREFIX ) ) ) );

$bilan = TransactionRecovery::run( wpd_now() );

check( 'H · la normalisation est idempotente', 0 === $bilan['normalized'] );
check( 'H · le téléchargement fonctionne de nouveau',
	null !== D::locate( ( static function () use ( $r ) {
		$lu = SubmissionRepository::get( $r->id() );
		parse_str( (string) wp_parse_url( SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() ), PHP_URL_QUERY ), $p );
		return $p;
	} )(), wpd_now() ) );

verdict();
