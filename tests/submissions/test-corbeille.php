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

// ======================================================================
// TRANSITION DURABLE : PREPARED CONTRE COMPLETED
// ======================================================================

/**
 * Ajoute un filtre qui court-circuite la mise à la Corbeille après la nôtre.
 *
 * Reproduit exactement le cas redouté : notre invalidation est écrite, puis
 * un tiers empêche WordPress de changer le `post_status`.
 *
 * @return void
 */
function saboter_corbeille(): void {
	add_filter(
		'pre_trash_post',
		static function ( $court, $post = null, $prec = '' ) {
			return ( is_object( $post ) && SubmissionPostType::POST_TYPE === $post->post_type ) ? false : $court;
		},
		20,
		3
	);
}

// --- A · mise à la Corbeille normale ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

check( 'A · la transition est confirmée', TrashGuard::COMPLETED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'A · le statut précédent y figure', 'received' === ( TrashGuard::transition( $d['id'] )['previous'] ?? '' ) );
check( 'A · la transition porte une date technique', 1 === preg_match( '/^\d{4}-\d{2}-\d{2} /', (string) ( TrashGuard::transition( $d['id'] )['prepared_at'] ?? '' ) ) );
check( 'A · aucune donnée personnelle dans la transition',
	array( 'state', 'previous', 'prepared_at' ) === array_keys( TrashGuard::transition( $d['id'] ) ) );
check( 'A · elle n’est plus seulement préparée', ! TrashGuard::is_prepared_only( $d['id'] ) );

// --- B et C · un tiers court-circuite la mise à la Corbeille ---
neuf();
$d = demande();
saboter_corbeille();

$resultat = wp_trash_post( $d['id'] );

check( 'B · la mise à la Corbeille échoue', false === $resultat );
check( 'B · le post_status reste private', SubmissionPostType::POST_STATUS === get_post( $d['id'] )->post_status );
check( 'C · _urbizen_status reste trashed', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'C · AUCUN TÉLÉCHARGEMENT', null === D::locate( $d['params'], wpd_now() ) );
check( 'C · le statut précédent est conservé', 'received' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'C · la transition reste prepared', TrashGuard::PREPARED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'C · l’état transitoire est reconnaissable', TrashGuard::is_prepared_only( $d['id'] ) );

// --- D et E · nouvelle tentative ---
wpd_clear_filter( 'pre_trash_post' );
TrashGuard::register();

$avant_memoire    = get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true );
$avant_transition = TrashGuard::transition( $d['id'] );
$resultat         = wp_trash_post( $d['id'] );

check( 'D · la nouvelle tentative est autorisée', false !== $resultat );
check( 'E · le post passe à trash', 'trash' === get_post( $d['id'] )->post_status );
check( 'E · la transition est confirmée', TrashGuard::COMPLETED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'E · le statut précédent n’a pas changé', $avant_memoire === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'E · la date de préparation est conservée',
	( $avant_transition['prepared_at'] ?? '' ) === ( TrashGuard::transition( $d['id'] )['prepared_at'] ?? '' ) );
check( 'E · toujours aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );

// --- F · trois tentatives échouées de suite ---
neuf();
$d = demande( 'converted' );
saboter_corbeille();

for ( $i = 1; $i <= 3; $i++ ) {
	wp_trash_post( $d['id'] );
}

check( 'F · un seul statut précédent mémorisé', 'converted' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'F · une seule transition, restée prepared', TrashGuard::PREPARED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'F · le statut précédent de la transition est exact', 'converted' === ( TrashGuard::transition( $d['id'] )['previous'] ?? '' ) );
check( 'F · aucune réactivation du lien', null === D::locate( $d['params'], wpd_now() ) );
check( 'F · le post reste private', SubmissionPostType::POST_STATUS === get_post( $d['id'] )->post_status );

// --- G · l'écriture native échoue après l'invalidation ---
neuf();
$d = demande();
$GLOBALS['wpd_trash_fail'] = true;
$resultat = wp_trash_post( $d['id'] );
$GLOBALS['wpd_trash_fail'] = false;

check( 'G · la mise à la Corbeille échoue', false === $resultat );
check( 'G · l’état préparé est conservé', TrashGuard::PREPARED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'G · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( 'G · la tentative est rejouable', false !== wp_trash_post( $d['id'] ) );
check( 'G · et aboutit', 'trash' === get_post( $d['id'] )->post_status );

// --- H · invalidée sans transition ---
neuf();
$d = demande();
update_post_meta( $d['id'], '_urbizen_status', TrashGuard::STATUS_TRASHED );

check( 'H · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( 'H · la mise à la Corbeille est refusée', false === wp_trash_post( $d['id'] ) );

$bilan = TrashGuard::reconcile();

check( 'H · la réconciliation la marque incohérente', 1 === $bilan['incoherentes'] );
check( 'H · l’état devient incoherent', TransactionRecovery::INCOHERENT === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'H · le document est conservé', 1 === fx_compte_fichiers() );
check( 'H · le post est conservé', null !== get_post( $d['id'] ) );
check( 'H · toujours aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );

// --- I · à la Corbeille mais transition seulement préparée ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

// On force le retour en arrière de la confirmation, comme si trashed_post
// n'avait pas abouti.
$tr          = TrashGuard::transition( $d['id'] );
$tr['state'] = TrashGuard::PREPARED;
update_post_meta( $d['id'], TrashGuard::TRANSITION, (string) wp_json_encode( $tr ) );

check( 'I · la restauration est BLOQUÉE', false === wp_untrash_post( $d['id'] ) );
check( 'I · le post reste à la Corbeille', 'trash' === get_post( $d['id'] )->post_status );
check( 'I · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );

// --- K · réconciliation d'un hook postérieur défaillant ---
$bilan = TrashGuard::reconcile();

check( 'K · la réconciliation confirme la transition', 1 === $bilan['confirmees'] );
check( 'K · la transition devient completed', TrashGuard::COMPLETED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'K · aucun document n’a été touché', 1 === fx_compte_fichiers() );
check( 'K · toujours aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( 'K · la restauration redevient possible', false !== wp_untrash_post( $d['id'] ) );

// --- J · restauration d'une transition confirmée ---
neuf();
$d = demande( 'closed' );
wp_trash_post( $d['id'] );
wp_untrash_post( $d['id'] );

check( 'J · le statut exact est restauré', 'closed' === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'J · la métadonnée de statut précédent disparaît', '' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'J · LA TRANSITION DISPARAÎT', array() === TrashGuard::transition( $d['id'] ) );
check( 'J · le téléchargement redevient possible', null !== D::locate( $d['params'], wpd_now() ) );

// --- L · action groupée mixte ---
neuf();
$ok     = demande();
$bloque = demande( 'processing' );

wp_trash_post( $ok['id'] );
wp_trash_post( $bloque['id'] );

check( 'L · la demande valide part à la Corbeille', 'trash' === get_post( $ok['id'] )->post_status );
check( 'L · sa transition est confirmée', TrashGuard::COMPLETED === ( TrashGuard::transition( $ok['id'] )['state'] ?? '' ) );
check( 'L · la demande en processing reste private', SubmissionPostType::POST_STATUS === get_post( $bloque['id'] )->post_status );
check( 'L · elle n’a aucune transition', array() === TrashGuard::transition( $bloque['id'] ) );
check( 'L · aucun téléchargement pour l’une comme pour l’autre',
	null === D::locate( $ok['params'], wpd_now() ) && null === D::locate( $bloque['params'], wpd_now() ) );

// --- M · autre type de contenu ---
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page' ) );

check( 'M · un autre type passe sans effet', false !== wp_trash_post( $page ) );
check( 'M · aucune transition ne lui est posée', '' === get_post_meta( $page, TrashGuard::TRANSITION, true ) );

// --- N · rétention sur une transition préparée ---
neuf();
$d = demande();
saboter_corbeille();
wp_trash_post( $d['id'] );
wpd_clear_filter( 'pre_trash_post' );
TrashGuard::register();

update_post_meta( $d['id'], '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );
\Urbizen\Platform\Privacy\Retention::purge( wpd_now() );

check( 'N · LA RÉTENTION NE SUPPRIME PAS UN ÉTAT PRÉPARÉ', null !== get_post( $d['id'] ) );
check( 'N · le document est conservé', 1 === fx_compte_fichiers() );
check( 'N · la référence attribuée est conservée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );
check( 'N · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( 'N · la mise à la Corbeille reste possible', false !== wp_trash_post( $d['id'] ) );

// --- O · suppression définitive dans l'état préparé ---
neuf();
$d = demande();
saboter_corbeille();
wp_trash_post( $d['id'] );
wpd_clear_filter( 'pre_trash_post' );
TrashGuard::register();
FileCleaner::register();

$resultat = wp_delete_post( $d['id'], true );

check( 'O · LA SUPPRESSION DÉFINITIVE EST BLOQUÉE', false === $resultat );
check( 'O · le post est conservé', null !== get_post( $d['id'] ) );
check( 'O · AUCUN FICHIER ORPHELIN', 1 === fx_compte_fichiers() && 1 === (int) get_post_meta( $d['id'], '_urbizen_files_count', true ) );
check( 'O · le refus est journalisé', str_contains( journal(), 'transition de Corbeille non confirmée' ) );

// Une fois la Corbeille confirmée, la suppression redevient possible.
FileCleaner::reset();
wp_trash_post( $d['id'] );
FileCleaner::reset();

check( 'O · après confirmation, la suppression aboutit', false !== wp_delete_post( $d['id'], true ) );
check( 'O · le document est effacé', 0 === fx_compte_fichiers() );

// ======================================================================
// CONCURRENCE
// ======================================================================
neuf();
$d = demande( 'converted' );

// Deux préparations quasi simultanées : la seconde ne doit rien écraser.
TrashGuard::guard_trash( null, get_post( $d['id'] ), 'private' );
$memoire_a    = get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true );
$transition_a = TrashGuard::transition( $d['id'] );

TrashGuard::guard_trash( null, get_post( $d['id'] ), 'private' );

check( 'concurrence · un seul statut précédent mémorisé', $memoire_a === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'concurrence · converted n’est pas inversé', 'converted' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'concurrence · une seule transition, non contradictoire', $transition_a === TrashGuard::transition( $d['id'] ) );
check( 'concurrence · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );

// L'état final n'est jamais l'une des combinaisons interdites.
$statut_wp  = get_post( $d['id'] )->post_status;
$statut_app = get_post_meta( $d['id'], '_urbizen_status', true );
$etat_tr    = (string) ( TrashGuard::transition( $d['id'] )['state'] ?? '' );

check( 'concurrence · état final : private + prepared, ou trash + completed',
	( SubmissionPostType::POST_STATUS === $statut_wp && TrashGuard::PREPARED === $etat_tr )
	|| ( 'trash' === $statut_wp && TrashGuard::COMPLETED === $etat_tr ) );
check( 'concurrence · jamais private + état téléchargeable',
	! ( SubmissionPostType::POST_STATUS === $statut_wp && in_array( $statut_app, SubmissionPostType::downloadable_statuses(), true ) ) );
check( 'concurrence · jamais trash + received', ! ( 'trash' === $statut_wp && 'received' === $statut_app ) );

wp_trash_post( $d['id'] );
wp_untrash_post( $d['id'] );

check( 'concurrence · la restauration rend bien converted', 'converted' === get_post_meta( $d['id'], '_urbizen_status', true ) );


verdict();
