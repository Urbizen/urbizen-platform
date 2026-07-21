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
// Le cœur appelle `wp_delete_post( $id )` **sans** forçage : le chemin protégé
// par `pre_delete_post` est donc bien emprunté, et son troisième argument vaut
// `false`.
check( 'Q/R · le vidage automatique passe par wp_delete_post', str_contains( $code_double, 'wp_delete_post( $id )' ) );
check( 'Q/R · sans forçage, comme le cœur', ! str_contains( $code_double, 'wp_delete_post( $id, true )' ) );

$force_vu = null;
add_filter( 'pre_delete_post', static function ( $c, $p, $f ) use ( &$force_vu ) { $force_vu = $f; return $c; }, 1, 3 );

$e = demande();
wp_trash_post( $e['id'] );
$GLOBALS['wpd_meta'][ $e['id'] ]['_wp_trash_meta_time'] = wpd_now() - ( 40 * 86400 );
wp_scheduled_delete( 30 );

check( 'Q/R · pre_delete_post reçoit bien force = false', false === $force_vu );

// Une demande sans `_wp_trash_meta_time` n'est pas remontée par la requête du
// cœur : elle ne doit pas être purgée.
$f = demande();
wp_trash_post( $f['id'] );
unset( $GLOBALS['wpd_meta'][ $f['id'] ]['_wp_trash_meta_time'] );

check( 'Q/R · sans _wp_trash_meta_time, aucune purge', 0 === wp_scheduled_delete( 30 ) && null !== get_post( $f['id'] ) );

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


// ======================================================================
// STATUT NATIF APRÈS RESTAURATION
// ======================================================================
// Depuis WordPress 5.6, un contenu non joint restauré repart en `draft`. Une
// demande finalisée porte `private` : sans filtre, ses documents deviendraient
// définitivement inaccessibles.

// --- 1 · le comportement du cœur, sans notre filtre ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
wpd_clear_filter( 'wp_untrash_post_status' );
wp_untrash_post( $d['id'] );

check( '1 · sans filtre, WordPress restaure en draft', 'draft' === get_post( $d['id'] )->post_status );
check( '1 · le statut métier n’est donc PAS restauré', 'received' !== get_post_meta( $d['id'], '_urbizen_status', true ) );
check( '1 · et aucun téléchargement n’est possible', null === D::locate( $d['params'], wpd_now() ) );

// --- 2 · le filtre est enregistré avec trois arguments ---
$source_tg = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Submissions/TrashGuard.php' );

check( '2 · wp_untrash_post_status est enregistré', str_contains( $source_tg, "add_filter( 'wp_untrash_post_status'" ) );
check( '2 · avec trois arguments et une priorité assumée', str_contains( $source_tg, "'untrash_status' ), 20, 3 )" ) );
// Le commentaire a le droit d'expliquer pourquoi on ne l'emploie pas ; le code,
// non. On compare donc le code, commentaires retirés.
$code_tg = implode(
	'',
	array_map(
		static fn( $tok ) => is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $tok ) ? $tok[1] : $tok ),
		token_get_all( $source_tg )
	)
);

check( '2 · sans installer wp_untrash_post_set_previous_status globalement',
	! str_contains( $code_tg, 'wp_untrash_post_set_previous_status' ) );

// --- 3, 4 · le filtre et les autres types de contenu ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

check( '3 · une demande cohérente revient à private', 'private' === TrashGuard::untrash_status( 'draft', $d['id'], 'private' ) );

$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'trash', 'post_title' => 'Page' ) );

check( '4 · un autre type garde le draft proposé', 'draft' === TrashGuard::untrash_status( 'draft', $page, 'publish' ) );
check( '4 · et garde aussi un publish proposé', 'publish' === TrashGuard::untrash_status( 'publish', $page, 'publish' ) );

// --- 5, 6, 7 · le statut natif précédent ---
check( '5 · précédent private → private', 'private' === TrashGuard::untrash_status( 'draft', $d['id'], 'private' ) );

foreach ( array( 'draft', 'publish', 'pending', 'future', '', 'statut-inconnu' ) as $precedent ) {
	check( '6/7 · précédent « ' . ( '' === $precedent ? '(vide)' : $precedent ) . ' » → refusé',
		'draft' === TrashGuard::untrash_status( 'draft', $d['id'], $precedent ) );
}

// La restauration elle-même est bloquée si le précédent n'est pas private.
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_meta'][ $d['id'] ]['_wp_trash_meta_status'] = 'draft';

check( '6 · restauration BLOQUÉE si le précédent natif est draft', false === wp_untrash_post( $d['id'] ) );
check( '6 · le post reste à la Corbeille', 'trash' === get_post( $d['id'] )->post_status );
check( '6 · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );

// --- 8 · transition seulement préparée ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$tr          = TrashGuard::transition( $d['id'] );
$tr['state'] = TrashGuard::PREPARED;
update_post_meta( $d['id'], TrashGuard::TRANSITION, (string) wp_json_encode( $tr ) );

check( '8 · le filtre refuse une transition préparée', 'draft' === TrashGuard::untrash_status( 'draft', $d['id'], 'private' ) );
check( '8 · et la restauration est bloquée', false === wp_untrash_post( $d['id'] ) );

// --- 9 à 12 · restauration complète, statut métier exact ---
foreach ( array( 'received', 'converted', 'closed' ) as $statut ) {
	neuf();
	$d = demande( $statut );
	wp_trash_post( $d['id'] );

	check( "15 · [$statut] téléchargement refusé avant restauration", null === D::locate( $d['params'], wpd_now() ) );

	wp_untrash_post( $d['id'] );

	check( "9 · [$statut] post_status final private", 'private' === get_post( $d['id'] )->post_status );
	check( "10/11/12 · [$statut] statut métier exact restauré", $statut === get_post_meta( $d['id'], '_urbizen_status', true ) );
	check( "21 · [$statut] métadonnées temporaires supprimées",
		'' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) && array() === TrashGuard::transition( $d['id'] ) );
	check( "18 · [$statut] téléchargement de nouveau possible", null !== D::locate( $d['params'], wpd_now() ) );
}

// --- 13, 14 · statut natif final inattendu ---
foreach ( array( 'draft', 'publish', 'pending', 'statut-inconnu' ) as $final ) {
	neuf();
	$d = demande();
	wp_trash_post( $d['id'] );

	// Un greffon tiers, exécuté après le nôtre, impose un autre statut.
	add_filter( 'wp_untrash_post_status', static fn( $s ) => $final, 30, 3 );
	wp_untrash_post( $d['id'] );

	check( "13/14 · statut final « $final » → statut métier NON restauré",
		! in_array( get_post_meta( $d['id'], '_urbizen_status', true ), SubmissionPostType::downloadable_statuses(), true ) );
	check( "13/14 · statut final « $final » → aucun téléchargement", null === D::locate( $d['params'], wpd_now() ) );
	check( "13/14 · statut final « $final » → état signalé", TransactionRecovery::INCOHERENT === get_post_meta( $d['id'], '_urbizen_status', true ) );
	check( "13/14 · statut final « $final » → métadonnées conservées",
		'' !== get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) && array() !== TrashGuard::transition( $d['id'] ) );
}

// --- 16, 17 · téléchargement pendant et après la seule restauration native ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

$pendant = null;
add_filter( 'wp_untrash_post_status', static function ( $s ) use ( $d, &$pendant ) {
	$pendant = D::locate( $d['params'], wpd_now() );
	return $s;
}, 30, 3 );

wp_untrash_post( $d['id'] );

check( '16 · téléchargement refusé PENDANT la restauration', null === $pendant );

neuf();
$d = demande();
wp_trash_post( $d['id'] );

// La restauration native aboutit, mais le hook postérieur n'est pas exécuté.
get_post( $d['id'] )->post_status = 'private';

check( '17 · après la seule restauration native, téléchargement refusé', null === D::locate( $d['params'], wpd_now() ) );

// --- 19 · l'écriture native échoue ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

$GLOBALS['wpd_untrash_fail'] = true;
$resultat                    = wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

check( '19 · wp_untrash_post échoue', false === $resultat );
check( '19 · le post reste à la Corbeille', 'trash' === get_post( $d['id'] )->post_status );
check( '19 · _urbizen_status reste trashed', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( '19 · les métadonnées temporaires restent', array() !== TrashGuard::transition( $d['id'] ) );
check( '19 · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
// Le cœur a effacé ses métadonnées natives AVANT d'échouer : sans réparation,
// une seconde tentative reçoit un statut natif précédent vide et se voit
// refusée. Ce n'est pas un assouplissement du contrôle, c'est sa correction.
check( '19 · les métadonnées natives ont bien disparu',
	'' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );
check( '19 · une seconde tentative SANS réparation est refusée', false === wp_untrash_post( $d['id'] ) );
check( '19 · après réparation, la tentative aboutit',
	TrashGuard::repair_native( $d['id'], wpd_now() ) && false !== wp_untrash_post( $d['id'] ) );
check( '19 · et rétablit private', 'private' === get_post( $d['id'] )->post_status );

// --- 20 · l'écriture du statut métier échoue ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

$GLOBALS['wpd_meta_fail'] = '_urbizen_status';
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_meta_fail'] = '';

check( '20 · post_status est bien revenu à private', 'private' === get_post( $d['id'] )->post_status );
check( '20 · le statut métier n’est pas devenu téléchargeable',
	! in_array( get_post_meta( $d['id'], '_urbizen_status', true ), SubmissionPostType::downloadable_statuses(), true ) );
check( '20 · les métadonnées temporaires sont conservées',
	'' !== get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) && array() !== TrashGuard::transition( $d['id'] ) );
check( '20 · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( '20 · aucun document ni référence supprimé', 1 === fx_compte_fichiers()
	&& 'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $d['ref'] )['state'] ?? '' ) );

// --- 22 · action groupée de restauration ---
neuf();
$lot = array( demande(), demande( 'converted' ), demande( 'closed' ) );

foreach ( $lot as $une ) {
	wp_trash_post( $une['id'] );
}

foreach ( $lot as $une ) {
	wp_untrash_post( $une['id'] );
}

$prives = 0;
$exacts = array( 'received', 'converted', 'closed' );

foreach ( $lot as $i => $une ) {
	if ( 'private' === get_post( $une['id'] )->post_status && $exacts[ $i ] === get_post_meta( $une['id'], '_urbizen_status', true ) ) {
		++$prives;
	}
}

check( '22 · les trois demandes reviennent indépendamment à private et à leur statut exact', 3 === $prives );

// --- 23, 24 · interopérabilité avec un greffon tiers ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

// Tiers exécuté AVANT le nôtre : notre règle a le dernier mot.
add_filter( 'wp_untrash_post_status', static fn( $s ) => 'publish', 5, 3 );
wp_untrash_post( $d['id'] );

check( '23 · un tiers proposant publish avant nous → private final', 'private' === get_post( $d['id'] )->post_status );
check( '23 · la demande ne devient jamais publish', 'publish' !== get_post( $d['id'] )->post_status );
check( '23 · le statut métier est restauré', 'received' === get_post_meta( $d['id'], '_urbizen_status', true ) );

neuf();
$d = demande();
wp_trash_post( $d['id'] );
add_filter( 'wp_untrash_post_status', static fn( $s ) => 'draft', 5, 3 );
wp_untrash_post( $d['id'] );

check( '24 · un tiers proposant draft avant nous → private final', 'private' === get_post( $d['id'] )->post_status );
check( '24 · le téléchargement redevient possible', null !== D::locate( $d['params'], wpd_now() ) );

// Tiers exécuté APRÈS le nôtre : la barrière postérieure prend le relais.
neuf();
$d = demande();
wp_trash_post( $d['id'] );
add_filter( 'wp_untrash_post_status', static fn( $s ) => 'publish', 30, 3 );
wp_untrash_post( $d['id'] );

check( 'interop · un tiers après nous impose publish', 'publish' === get_post( $d['id'] )->post_status );
check( 'interop · LA BARRIÈRE POSTÉRIEURE REFUSE LA RESTAURATION',
	TransactionRecovery::INCOHERENT === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'interop · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( 'interop · aucune donnée exposée, métadonnées conservées', array() !== TrashGuard::transition( $d['id'] ) );


// ======================================================================
// RESTAURATION NATIVE INTERROMPUE
// ======================================================================
// Le cœur efface `_wp_trash_meta_status` et `_wp_trash_meta_time` AVANT
// d'écrire le nouveau statut. Un échec de cette écriture laisse le contenu à
// la Corbeille sans aucune trace native de sa provenance.

// --- fidélité de la doublure, contrôle nommé exigé ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );

$hooks                       = array();
add_action( 'untrashed_post', static function ( $id ) use ( &$hooks ) { $hooks[] = 'untrashed_post'; }, 99, 2 );
add_action( 'untrash_post', static function ( $id ) use ( &$hooks ) { $hooks[] = 'untrash_post'; }, 99, 2 );

$GLOBALS['wpd_untrash_fail'] = true;
$resultat                    = wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

check( 'fidélité · wp_untrash_post rend false', false === $resultat );
check( 'fidélité · le post est encore trash', 'trash' === get_post( $d['id'] )->post_status );
check( 'fidélité · _wp_trash_meta_status est absent', '' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );
check( 'fidélité · _wp_trash_meta_time est absente', '' === get_post_meta( $d['id'], TrashGuard::NATIVE_TIME, true ) );
check( 'fidélité · untrash_post a été exécuté', in_array( 'untrash_post', $hooks, true ) );
check( 'fidélité · untrashed_post n’a PAS été exécuté', ! in_array( 'untrashed_post', $hooks, true ) );

$vu = null;
add_filter( 'pre_untrash_post', static function ( $c, $p, $prec ) use ( &$vu ) { $vu = $prec; return $c; }, 1, 3 );
wp_untrash_post( $d['id'] );

check( 'fidélité · un nouvel appel natif reçoit un previous_status vide', '' === $vu );

// --- A · premier échec après suppression des métadonnées natives ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

check( 'A · post_status = trash', 'trash' === get_post( $d['id'] )->post_status );
check( 'A · métadonnées natives absentes',
	'' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) && '' === get_post_meta( $d['id'], TrashGuard::NATIVE_TIME, true ) );
check( 'A · _urbizen_status reste trashed', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'A · transition completed conservée', TrashGuard::COMPLETED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'A · statut métier mémorisé conservé', 'received' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) );
check( 'A · téléchargement refusé', null === D::locate( $d['params'], wpd_now() ) );

// --- B · seconde tentative sans réparation ---
check( 'B · restauration refusée', false === wp_untrash_post( $d['id'] ) );
check( 'B · le post reste trash', 'trash' === get_post( $d['id'] )->post_status );
check( 'B · aucune réactivation', null === D::locate( $d['params'], wpd_now() ) );
check( 'B · statut métier toujours non restauré', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );

// --- C · réparation cohérente ---
check( 'C · la réparation aboutit', true === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'C · _wp_trash_meta_status redevient private', 'private' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );
// L'horodatage natif doit être **exactement** celui de la mise à la Corbeille,
// converti en timestamp Unix. Une borne large ne prouverait rien : la chaîne
// GMT « 2026-07-21 09:52:27 » passe un `(int) … <= now` en valant 2026.
$prepare_c = (string) ( TrashGuard::transition( $d['id'] )['prepared_at'] ?? '' );
$attendu_c = (int) strtotime( $prepare_c . ' UTC' );
$ecrit_c   = get_post_meta( $d['id'], TrashGuard::NATIVE_TIME, true );

check( 'C · prepared_at est bien une chaîne GMT', 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $prepare_c ) );
check( 'C · _wp_trash_meta_time égale strtotime(prepared_at UTC)', $attendu_c === (int) $ecrit_c );
check( 'C · c’est un entier, pas la chaîne GMT', is_int( $ecrit_c ) );
check( 'C · la chaîne GMT n’est jamais écrite telle quelle', $prepare_c !== (string) $ecrit_c );
check( 'C · valeur strictement positive', (int) $ecrit_c > 0 );
check( 'C · aucune substitution par l’heure courante', (int) $ecrit_c !== wpd_now() || $attendu_c === wpd_now() );
check( 'C · aucune prolongation du séjour en Corbeille', (int) $ecrit_c <= $attendu_c );
check( 'C · post_status inchangé', 'trash' === get_post( $d['id'] )->post_status );
check( 'C · statut métier inchangé', TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'C · aucun téléchargement avant la fin', null === D::locate( $d['params'], wpd_now() ) );
check( 'C · aucun verrou résiduel', false === get_option( TrashGuard::REPAIR_LOCK_PREFIX . $d['id'], false ) );
check( 'C · réparation idempotente', true === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'C · toujours une seule valeur native', 'private' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );

// --- D · réparation puis nouvel échec natif ---
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

check( 'D · l’état reste fermé', 'trash' === get_post( $d['id'] )->post_status );
check( 'D · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );
check( 'D · aucune perte des métadonnées Urbizen',
	'received' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true )
	&& TrashGuard::COMPLETED === ( TrashGuard::transition( $d['id'] )['state'] ?? '' ) );
check( 'D · la réparation reste possible', true === TrashGuard::repair_native( $d['id'], wpd_now() ) );

// --- E · réparation puis restauration complète ---
check( 'E · la restauration aboutit', false !== wp_untrash_post( $d['id'] ) );
check( 'E · post_status = private', 'private' === get_post( $d['id'] )->post_status );
check( 'E · statut métier exact restauré', 'received' === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'E · métadonnées natives supprimées par WordPress',
	'' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) && '' === get_post_meta( $d['id'], TrashGuard::NATIVE_TIME, true ) );
check( 'E · métadonnées temporaires Urbizen supprimées',
	'' === get_post_meta( $d['id'], TrashGuard::PRE_TRASH, true ) && array() === TrashGuard::transition( $d['id'] ) );
check( 'E · téléchargement de nouveau possible', null !== D::locate( $d['params'], wpd_now() ) );

// --- E bis · le statut métier exact survit au cycle complet ---
foreach ( array( 'converted', 'closed' ) as $statut ) {
	neuf();
	$d = demande( $statut );
	wp_trash_post( $d['id'] );
	$GLOBALS['wpd_untrash_fail'] = true;
	wp_untrash_post( $d['id'] );
	$GLOBALS['wpd_untrash_fail'] = false;
	TrashGuard::repair_native( $d['id'], wpd_now() );
	wp_untrash_post( $d['id'] );

	check( "E · [$statut] restauré exactement après réparation", $statut === get_post_meta( $d['id'], '_urbizen_status', true ) );
	check( "E · [$statut] post_status = private", 'private' === get_post( $d['id'] )->post_status );
}

// --- F · demande incohérente ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;
update_post_meta( $d['id'], '_urbizen_status', TransactionRecovery::INCOHERENT );

check( 'F · aucune réparation', false === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'F · métadonnées natives toujours absentes', '' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );
check( 'F · aucune restauration', false === wp_untrash_post( $d['id'] ) );
check( 'F · aucun téléchargement', null === D::locate( $d['params'], wpd_now() ) );

// --- G · référence non attributed ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

$cle          = SubmissionRepository::RESERVATION_PREFIX . $d['ref'];
$reservation  = get_option( $cle );
$reservation['state'] = 'reserved';
update_option( $cle, $reservation );

check( 'G · réparation refusée si la référence est reserved', false === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'G · aucune métadonnée native écrite', '' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );

// --- H · files_status non final ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;
update_post_meta( $d['id'], '_urbizen_files_status', 'pending' );

check( 'H · réparation refusée si files_status = pending', false === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'H · aucune métadonnée native écrite', '' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );

// --- I · deux réparations concurrentes ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

// Une première requête prend le verrou et ne le rend pas : elle est encore en
// vol lorsque la seconde arrive.
$occupe = add_option( TrashGuard::REPAIR_LOCK_PREFIX . $d['id'], array( 'expires' => wpd_now() + 60 ), '', false );

check( 'I · le verrou est bien pris', true === $occupe );
check( 'I · la seconde tentative n’écrit rien', false === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'I · aucune valeur contradictoire', '' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );

delete_option( TrashGuard::REPAIR_LOCK_PREFIX . $d['id'] );
TrashGuard::repair_native( $d['id'], wpd_now() );

check( 'I · une fois le verrou rendu, la réparation aboutit', 'private' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );
check( 'I · une seule valeur native, jamais draft ni publish',
	! in_array( get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ), array( 'draft', 'publish' ), true ) );
check( 'I · la seconde constate simplement que c’est déjà fait', true === TrashGuard::repair_native( $d['id'], wpd_now() ) );
check( 'I · aucun verrou résiduel', false === get_option( TrashGuard::REPAIR_LOCK_PREFIX . $d['id'], false ) );

// Un verrou périmé ne bloque pas éternellement.
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;
add_option( TrashGuard::REPAIR_LOCK_PREFIX . $d['id'], array( 'expires' => wpd_now() - 1 ), '', false );

check( 'I · un verrou périmé est repris', true === TrashGuard::repair_native( $d['id'], wpd_now() ) );

// --- reconcile répare automatiquement ---
neuf();
$d = demande();
wp_trash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = true;
wp_untrash_post( $d['id'] );
$GLOBALS['wpd_untrash_fail'] = false;

$bilan = TrashGuard::reconcile();

check( 'reconcile · la demande est comptée réparée', 1 === ( $bilan['reparees'] ?? 0 ) );
check( 'reconcile · l’état natif est rétabli', 'private' === get_post_meta( $d['id'], TrashGuard::NATIVE_STATUS, true ) );
check( 'reconcile · aucun téléchargement pour autant', null === D::locate( $d['params'], wpd_now() ) );
check( 'reconcile · le statut métier n’est pas restauré par la réparation',
	TrashGuard::STATUS_TRASHED === get_post_meta( $d['id'], '_urbizen_status', true ) );
check( 'reconcile · idempotente', 0 === ( TrashGuard::reconcile()['reparees'] ?? -1 ) );


verdict();
