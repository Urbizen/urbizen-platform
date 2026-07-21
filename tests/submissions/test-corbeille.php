<?php
/**
 * Banc d'essai du cycle de Corbeille.
 *
 * La Corbeille est un piège discret : `wp_trash_post()` change le
 * `post_status` sans toucher à l'état applicatif. Une demande « retirée » par
 * ce geste banal resterait téléchargeable si rien ne l'en empêchait.
 *
 * Deux verrous sont éprouvés ici, **séparément** : le verrou applicatif
 * (`_urbizen_status` à `trashed`) et le verrou natif (`post_status` pris dans
 * une liste fermée). Chacun doit suffire seul.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Http\FileDownloadController as D;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TransactionRecovery;
use Urbizen\Platform\Submissions\TrashGuard;

/**
 * Repart d'un état propre, gardes enregistrés.
 */
function neuf(): void {
	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
	Storage::set_mover( $GLOBALS['fx_mover'] );
	FileCleaner::reset();
	FileCleaner::register();
	TrashGuard::register();
}

/**
 * Demande valide, avec un document et son lien signé.
 *
 * @param string $statut Statut applicatif souhaité.
 * @return array{id:int,ref:string,params:array<string,mixed>,dossier:string}
 */
function demande( string $statut = 'received' ): array {
	$r = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );

	if ( 'received' !== $statut ) {
		update_post_meta( $r->id(), '_urbizen_status', $statut );
	}

	$lu = SubmissionRepository::get( $r->id() );
	parse_str( (string) wp_parse_url( SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() ), PHP_URL_QUERY ), $params );

	return array(
		'id'      => $r->id(),
		'ref'     => $r->reference(),
		'params'  => $params,
		'dossier' => URBIZEN_TEST_STORAGE . '/conception/' . $r->reference() . '/photos',
	);
}

// ======================================================================
// A · DEMANDE VALIDE, LIEN AUTORISÉ
// ======================================================================
neuf();
$d = demande();

check( 'A · le post porte le statut private', SubmissionPostType::POST_STATUS === get_post( $d['id'] )->post_status );
check( 'A · le téléchargement est autorisé', null !== D::locate( $d['params'], wpd_now() ) );
check( 'A · un document est en place', 1 === fx_compte_fichiers() );

// ======================================================================
// B et C · MISE À LA CORBEILLE
// ======================================================================
$resultat = wp_trash_post( $d['id'] );

check( 'B · la mise à la Corbeille aboutit', false !== $resultat );
check( 'B · _urbizen_status devient trashed', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'B · le statut précédent est mémorisé', 'received' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'C · le post est à la Corbeille', 'trash' === get_post( $d['id'] )->post_status );
check( 'C · L’ANCIEN LIEN EST REFUSÉ', null === D::locate( $d['params'], wpd_now() ) );

// ======================================================================
// D · LE FICHIER EXISTE ENCORE
// ======================================================================
check( 'D · le document est toujours physiquement présent', 1 === fx_compte_fichiers() );
check( 'D · ses métadonnées sont conservées', 1 === (int) get_post_meta( $d['id'], '_urbizen_files_count', true ) );
check( 'D · la référence attribuée est conservée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );
check( 'D · le lien reste néanmoins refusé', null === D::locate( $d['params'], wpd_now() ) );

// ======================================================================
// E · post_status FORCÉ SANS TOUCHER À L'ÉTAT APPLICATIF
// ======================================================================
// C'est le cas d'un autre greffon, ou d'un appel direct en base : le verrou
// applicatif ne joue pas, seul le verrou natif protège.
neuf();
$d = demande();

check( 'E · le lien fonctionne d’abord', null !== D::locate( $d['params'], wpd_now() ) );

get_post( $d['id'] )->post_status = 'trash';

check( 'E · _urbizen_status est resté received', 'received' === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'E · LE LIEN EST REFUSÉ PAR LE SEUL VERROU NATIF', null === D::locate( $d['params'], wpd_now() ) );

// ======================================================================
// S · STATUT WORDPRESS INCONNU
// ======================================================================
foreach ( array( 'trash', 'draft', 'pending', 'future', 'auto-draft', 'inherit', 'publish', 'statut-inconnu', '' ) as $statut ) {
	neuf();
	$d = demande();
	get_post( $d['id'] )->post_status = $statut;

	check( 'S · post_status refusé : ' . ( '' === $statut ? '(vide)' : $statut ), null === D::locate( $d['params'], wpd_now() ) );
}

neuf();
$d = demande();
check( 'S · seul private autorise le téléchargement', null !== D::locate( $d['params'], wpd_now() ) );

// ======================================================================
// F · ÉCRITURE DE L'ÉTAT IMPOSSIBLE
// ======================================================================
neuf();
$d = demande();

$GLOBALS['wpd_meta_fail'] = '_urbizen_status';
$resultat                 = wp_trash_post( $d['id'] );
$GLOBALS['wpd_meta_fail'] = '';

check( 'F · LA MISE À LA CORBEILLE EST BLOQUÉE', false === $resultat );
check( 'F · le post reste en private', SubmissionPostType::POST_STATUS === get_post( $d['id'] )->post_status );
check( 'F · l’état applicatif est inchangé', 'received' === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'F · le document est conservé', 1 === fx_compte_fichiers() );
check( 'F · le refus est journalisé sans donnée personnelle',
	str_contains( journal(), 'corbeille refusée' ) && ! str_contains( journal(), 'Camille' ) );

// Un état non restaurable interdit également la mise à la Corbeille.
foreach ( array( 'processing', 'deleting', 'delete_failed', 'recovery_failed', 'incoherent' ) as $etat ) {
	neuf();
	$d = demande( $etat );

	check( "F · corbeille refusée depuis l’état $etat", false === wp_trash_post( $d['id'] ) );
	check( "F · le post reste en private depuis $etat", SubmissionPostType::POST_STATUS === get_post( $d['id'] )->post_status );
}

// ======================================================================
// G · ACTION GROUPÉE
// ======================================================================
neuf();
$lot = array( demande(), demande(), demande() );

foreach ( $lot as $une ) {
	wp_trash_post( $une['id'] );
}

$invalidees = 0;
$refusees   = 0;

foreach ( $lot as $une ) {
	if ( TrashGuard::STATUS_TRASHED === get_post_meta( $une['id'], '_urbizen_status', true ) ) {
		++$invalidees;
	}

	if ( null === D::locate( $une['params'], wpd_now() ) ) {
		++$refusees;
	}
}

check( 'G · les trois demandes sont invalidées individuellement', 3 === $invalidees );
check( 'G · les trois liens sont refusés', 3 === $refusees );
check( 'G · les trois documents sont conservés', 3 === fx_compte_fichiers() );

// ======================================================================
// H, I et J · RESTAURATION EXACTE
// ======================================================================
foreach ( array( 'received', 'converted', 'closed' ) as $statut ) {
	neuf();
	$d = demande( $statut );

	wp_trash_post( $d['id'] );
	check( "restauration · [$statut] mise à la Corbeille", 'trash' === get_post( $d['id'] )->post_status );

	$resultat = wp_untrash_post( $d['id'] );

	check( "restauration · [$statut] elle aboutit", false !== $resultat );
	check( "restauration · [$statut] LE STATUT EXACT EST RÉTABLI", $statut === get_post_meta( $d['id'], '_urbizen_status', true ) );
	check( "restauration · [$statut] la métadonnée temporaire disparaît", '' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
	check( "restauration · [$statut] le post revient en private", SubmissionPostType::POST_STATUS === get_post( $d['id'] )->post_status );
	check( "restauration · [$statut] le téléchargement redevient possible", null !== D::locate( $d['params'], wpd_now() ) );
}

// ======================================================================
// K et L · RESTAURATION BLOQUÉE
// ======================================================================
$blocages = array(
	'référence non attribuée' => static function ( array $d ): void {
		update_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'], array( 'state' => 'reserved', 'at' => 0, 'post' => $d['id'] ), false );
	},
	'réservation rattachée ailleurs' => static function ( array $d ): void {
		update_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'], array( 'state' => 'attributed', 'at' => '', 'post' => $d['id'] + 77 ), false );
	},
	'référence divergente' => static function ( array $d ): void {
		$tx              = SubmissionRepository::transaction( $d['id'] );
		$tx['reference'] = 'URB-2020-0001';
		update_post_meta( $d['id'], '_urbizen_transaction', (string) wp_json_encode( $tx ) );
	},
	'transaction non validée' => static function ( array $d ): void {
		$tx          = SubmissionRepository::transaction( $d['id'] );
		$tx['state'] = 'processing';
		update_post_meta( $d['id'], '_urbizen_transaction', (string) wp_json_encode( $tx ) );
	},
	'documents restés pending' => static function ( array $d ): void {
		update_post_meta( $d['id'], '_urbizen_files_status', 'pending' );
	},
	'statut mémorisé non restaurable' => static function ( array $d ): void {
		update_post_meta( $d['id'], TrashGuard::PRE_TRASH, 'incoherent' );
	},
	'métadonnées incomplètes' => static function ( array $d ): void {
		delete_post_meta( $d['id'], '_urbizen_pricing' );
	},
);

foreach ( $blocages as $libelle => $casser ) {
	neuf();
	$d = demande();
	wp_trash_post( $d['id'] );
	$casser( $d );

	$resultat = wp_untrash_post( $d['id'] );

	check( "K/L · [$libelle] restauration BLOQUÉE", false === $resultat );
	check( "K/L · [$libelle] le post reste à la Corbeille", 'trash' === get_post( $d['id'] )->post_status );
	check( "K/L · [$libelle] le document est conservé", 1 === fx_compte_fichiers() );
	check( "K/L · [$libelle] aucun téléchargement", null === D::locate( $d['params'], wpd_now() ) );
	check( "K/L · [$libelle] la référence est conservée", null !== get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'], null ) );
}

// ======================================================================
// M · RESTAURATION APPLICATIVE ÉCHOUÉE APRÈS SORTIE DE CORBEILLE
// ======================================================================
neuf();
$d = demande();
wp_trash_post( $d['id'] );

$GLOBALS['wpd_meta_fail'] = '_urbizen_status';
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_meta_fail'] = '';

check( 'M · le post est sorti de la Corbeille', 'trash' !== get_post( $d['id'] )->post_status );
check( 'M · l’état applicatif n’est PAS revenu à received', 'received' !== get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'M · AUCUN TÉLÉCHARGEMENT N’EST POSSIBLE', null === D::locate( $d['params'], wpd_now() ) );
check( 'M · l’échec est signalé', str_contains( journal(), 'restauration' ) );

// ======================================================================
// N, O et P · SUPPRESSION DÉFINITIVE DEPUIS LA CORBEILLE
// ======================================================================
neuf();
$d = demande();
wp_trash_post( $d['id'] );

check( 'N · la demande est à la Corbeille avec son document', 1 === fx_compte_fichiers() );

$resultat = wp_delete_post( $d['id'], true );

check( 'N · la suppression définitive aboutit', false !== $resultat );
check( 'N · le document est effacé', 0 === fx_compte_fichiers() );
check( 'N · le post a disparu', null === get_post( $d['id'] ) );
check( 'N · LA RÉFÉRENCE ATTRIBUÉE EST CONSERVÉE',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );

// --- O · unlink en échec ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
@chmod( $d['dossier'], 0500 );

$resultat = wp_delete_post( $d['id'], true );

check( 'O · LA SUPPRESSION EST BLOQUÉE', false === $resultat );
check( 'O · le post reste à la Corbeille', null !== get_post( $d['id'] ) && 'trash' === get_post( $d['id'] )->post_status );
check( 'O · les métadonnées sont conservées', 1 === (int) get_post_meta( $d['id'], '_urbizen_files_count', true ) );
check( 'O · l’état passe à delete_failed', 'delete_failed' === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'O · le document est toujours là', 1 === fx_compte_fichiers() );
check( 'O · aucun téléchargement n’est possible', null === D::locate( $d['params'], wpd_now() ) );
check( 'O · la référence est conservée', null !== get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'], null ) );

// --- P · seconde tentative après correction ---
@chmod( $d['dossier'], 0700 );
FileCleaner::reset();

$resultat = wp_delete_post( $d['id'], true );

check( 'P · APRÈS CORRECTION, LA SUPPRESSION ABOUTIT', false !== $resultat );
check( 'P · le document est effacé', 0 === fx_compte_fichiers() );
check( 'P · le post a disparu', null === get_post( $d['id'] ) );
check( 'P · la référence attribuée survit',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );

// ======================================================================
// Q et R · VIDAGE AUTOMATIQUE DE LA CORBEILLE
// ======================================================================
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_meta'][ $d['id'] ]['_wp_trash_meta_time'] = wpd_now() - ( 40 * 86400 );

$supprimes = wp_scheduled_delete( 30 );

check( 'Q · le vidage automatique supprime la demande', 1 === $supprimes );
check( 'Q · le document est effacé', 0 === fx_compte_fichiers() );
check( 'Q · le post a disparu', null === get_post( $d['id'] ) );
check( 'Q · la référence attribuée est conservée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );

// --- R · vidage automatique avec nettoyage impossible ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_meta'][ $d['id'] ]['_wp_trash_meta_time'] = wpd_now() - ( 40 * 86400 );
@chmod( $d['dossier'], 0500 );

$supprimes = wp_scheduled_delete( 30 );

check( 'R · le vidage automatique n’emporte rien', 0 === $supprimes );
check( 'R · le post est conservé', null !== get_post( $d['id'] ) );
check( 'R · le document est conservé', 1 === fx_compte_fichiers() );
check( 'R · aucun téléchargement n’est possible', null === D::locate( $d['params'], wpd_now() ) );
check( 'R · les métadonnées sont conservées', 1 === (int) get_post_meta( $d['id'], '_urbizen_files_count', true ) );
@chmod( $d['dossier'], 0700 );

// Le vidage emprunte bien le chemin protégé : aucun second mécanisme.
$code_double = (string) file_get_contents( __DIR__ . '/wp-double.php' );
check( 'Q/R · le vidage automatique passe par wp_delete_post', str_contains( $code_double, 'wp_delete_post( $id, true )' ) );

// ======================================================================
// T · APPEL DIRECT SUR UNE DEMANDE À LA CORBEILLE
// ======================================================================
neuf();
$d = demande();
wp_trash_post( $d['id'] );

$corbeille  = D::locate( $d['params'], wpd_now() );
$inexistant = D::locate( array_merge( $d['params'], array( 'submission' => 999999 ) ), wpd_now() );

check( 'T · une demande à la Corbeille donne le même résultat qu’une demande inexistante',
	$corbeille === $inexistant && null === $corbeille );

// ======================================================================
// IDEMPOTENCE ET NON-RÉGRESSION DU CYCLE
// ======================================================================
neuf();
$d = demande();

wp_trash_post( $d['id'] );
$memoire = get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true );

// Une seconde mise à la Corbeille ne doit pas écraser la mémoire.
TrashGuard::guard_trash( null, get_post( $d['id'] ), 'trash' );

check( 'idempotence · la mémoire du statut n’est pas écrasée', $memoire === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'idempotence · l’état reste trashed', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );

// Les autres types de contenu ne sont pas affectés.
$autre = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Une page' ) );

check( 'les autres types de contenu passent sans effet', null === TrashGuard::guard_trash( null, get_post( $autre ), 'publish' ) );
check( 'et leur restauration aussi', null === TrashGuard::guard_untrash( null, get_post( $autre ), 'publish' ) );

// Une demande à la Corbeille reste purgeable par la rétention.
neuf();
$d = demande();
wp_trash_post( $d['id'] );
update_post_meta( $d['id'], '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );

\Urbizen\Platform\Privacy\Retention::purge( wpd_now() );

check( 'rétention · une demande à la Corbeille reste purgeable', null === get_post( $d['id'] ) );
check( 'rétention · son document est effacé', 0 === fx_compte_fichiers() );
check( 'rétention · sa référence attribuée survit',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );

verdict();
