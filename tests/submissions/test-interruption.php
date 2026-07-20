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
use Urbizen\Platform\Submissions\SubmissionRepository;

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
// G · FINALISATION PARTIELLE : COMMITTED MAIS RÉFÉRENCE ENCORE RESERVED
// ======================================================================
// Cas volontairement ambigu : le marqueur de validation est posé, mais la
// référence n'a pas été attribuée. On ne détruit rien.
neuf();
$c = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx-g' ) );

$tx          = SubmissionRepository::transaction( (int) $c['id'] );
$tx['state'] = 'committed';
update_post_meta( (int) $c['id'], '_urbizen_transaction', (string) wp_json_encode( $tx ) );

nouvelle_requete();
$bilan = Retention::run_daily( wpd_now() );

check( 'G · une transaction committed n’est PAS récupérée', 0 === $bilan['abandons'] );
check( 'G · la demande est conservée', null !== get_post( (int) $c['id'] ) );
check( 'G · la réservation est conservée', null !== get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'], null ) );
check( 'G · l’incohérence est signalée', str_contains( journal(), 'committed mais restée processing' ) );

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
check( 'incohérence · elle est signalée techniquement', str_contains( journal(), 'réservation non « reserved » — conservée' ) );

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
check( 'le rattachement incohérent est signalé', str_contains( journal(), 'rattachée à une autre demande' ) );

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

verdict();
