<?php
/**
 * Banc d'essai transactionnel et des liens signés.
 *
 * La question tenue ici est simple à formuler et difficile à garantir : après
 * une panne, à n'importe quel moment du traitement, l'état doit être **celui
 * d'avant**. Ni demande partielle, ni fichier orphelin, ni référence consommée,
 * ni jeton brûlé.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Http\FileDownloadController as D;
use Urbizen\Platform\Http\SubmissionController as C;
use Urbizen\Platform\Http\SubmissionResult as R;
use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
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
}

/**
 * Vérifie qu'aucune trace ne subsiste après un échec.
 *
 * @param string $libelle Intitulé du scénario.
 * @param array  $post    Données postées.
 * @return void
 */
function rien_ne_subsiste( string $libelle, array $post ): void {
	$jeton = $post[ C::TOKEN_FIELD ];

	check( "$libelle → aucune demande", array() === $GLOBALS['wpd_posts'] );
	check( "$libelle → aucun fichier permanent", 0 === fx_compte_fichiers() );
	check( "$libelle → aucun staging résiduel", 0 === fx_compte_staging() );
	check( "$libelle → aucune référence réservée",
		0 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX ) ) ) );
	check( "$libelle → jeton libéré", ! AntiSpam::is_used( $jeton, wpd_now() ) );
	check( "$libelle → créneau libéré", 0 === RateLimiter::used( 'conception', serveur(), wpd_now() ) );
	check( "$libelle → aucune donnée personnelle journalisée",
		! preg_match( '/Camille|camille@exemple\.test|plan\.pdf|' . preg_quote( sys_get_temp_dir(), '/' ) . '/', journal() ) );
}

// ======================================================================
// 26 · SUCCÈS
// ======================================================================
neuf();
$sans = traiter( soumission() );

check( 'sans document, la demande réussit', $sans->is_success() );
check( 'files_status vaut none', 'none' === get_post_meta( $sans->id(), '_urbizen_files_status', true ) );
check( 'aucun fichier n’est écrit', 0 === fx_compte_fichiers() );
check( 'aucun staging ne subsiste', 0 === fx_compte_staging() );

neuf();
$un = traiter( soumission(), fx_files( 'croquis_plans', array( array( 'Mon Plan.pdf', fx_copie( fx_pdf() ) ) ) ) );

check( 'avec un document, la demande réussit', $un->is_success() );
check( 'files_status vaut stored', 'stored' === get_post_meta( $un->id(), '_urbizen_files_status', true ) );
check( 'un fichier est écrit', 1 === fx_compte_fichiers() );
check( 'aucun staging ne subsiste', 0 === fx_compte_staging() );
check( 'une seule référence est attribuée', 1 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX ) ) ) );
check( 'la réservation est bien attribuée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $un->reference() )['state'] ?? '' ) );

$lu = SubmissionRepository::get( $un->id() );
$f  = $lu['files'][0];

check( 'les métadonnées comptent les dix clés prévues',
	array( 'id', 'block', 'original_name', 'stored_name', 'relative_path', 'extension', 'mime', 'size', 'sha256', 'stored_at_gmt' ) === array_keys( $f ) );
check( 'le décompte est enregistré', 1 === (int) get_post_meta( $un->id(), '_urbizen_files_count', true ) );
check( 'la taille totale est enregistrée', $f['size'] === (int) get_post_meta( $un->id(), '_urbizen_files_total_size', true ) );
check( 'le nom d’origine est conservé en métadonnée', 'Mon Plan.pdf' === $f['original_name'] );
check( 'le nom d’origine n’est pas le nom physique', ! str_contains( $f['stored_name'], 'Mon' ) );

// --- vingt documents ---
neuf();
$vingt = array();

foreach ( array( 'croquis_plans', 'plan_terrain', 'photos', 'inspirations_docs' ) as $bloc ) {
	$liste = array();

	for ( $i = 0; $i < 5; $i++ ) {
		$liste[] = array( "d$i.pdf", fx_copie( fx_pdf() ) );
	}

	$vingt = array_merge( $vingt, fx_files( $bloc, $liste ) );
}

$r = traiter( soumission(), $vingt );

check( 'vingt documents sont acceptés', $r->is_success() );
check( 'vingt fichiers sont écrits', 20 === fx_compte_fichiers() );
check( 'vingt métadonnées sont enregistrées', 20 === (int) get_post_meta( $r->id(), '_urbizen_files_count', true ) );
check( 'aucun staging ne subsiste', 0 === fx_compte_staging() );

// ======================================================================
// 26 · PANNES
// ======================================================================

// --- validation du lot en échec ---
neuf();
$post = soumission();
traiter( $post, fx_files( 'croquis_plans', array( array( 'dessin.svg', fx_copie( fx_svg() ) ) ) ) );
rien_ne_subsiste( 'lot invalide', $post );

// --- stockage indisponible ---
neuf();
wpd_clear_filter( 'urbizen_private_storage_dir' );
add_filter( 'urbizen_private_storage_dir', static fn() => rtrim( ABSPATH, '/' ) . '/interdit' );
Storage::reset();

$post = soumission();
$r    = traiter( $post, fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );

check( 'stockage indisponible → storage_unavailable', R::STORAGE_UNAVAILABLE === $r->code() );
check( 'aucune demande', array() === $GLOBALS['wpd_posts'] );
check( 'jeton libéré', ! AntiSpam::is_used( $post[ C::TOKEN_FIELD ], wpd_now() ) );
check( 'aucun répertoire créé dans la racine publique', ! is_dir( rtrim( ABSPATH, '/' ) . '/interdit' ) );

// --- déplacement vers le staging en échec ---
neuf();
$post = soumission();
$r    = traiter(
	$post,
	fx_files( 'photos', array( array( 'p.jpg', sys_get_temp_dir() . '/urbizen-inexistant-' . getmypid() ) ) )
);

check( 'fichier temporaire absent → structure invalide', R::UPLOAD_INVALID_STRUCTURE === $r->code() );
rien_ne_subsiste( 'temporaire absent', $post );

// --- création du post en échec ---
neuf();
$post                     = soumission();
$GLOBALS['wpd_insert_fail'] = true;
$r                        = traiter( $post, fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );
$GLOBALS['wpd_insert_fail'] = false;

check( 'création du post en échec → persistence_failed', R::PERSISTENCE_FAILED === $r->code() );
rien_ne_subsiste( 'post impossible', $post );

// --- écriture des métadonnées de fichiers en échec ---
neuf();
$post                     = soumission();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files';
$r                        = traiter( $post, fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );
$GLOBALS['wpd_meta_fail'] = '';

check( 'métadonnées de documents en échec → refus', ! $r->is_success() );
rien_ne_subsiste( 'métadonnées de documents', $post );

// --- finalisation en échec ---
neuf();
$post                     = soumission();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files_status';
$r                        = traiter( $post, fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );
$GLOBALS['wpd_meta_fail'] = '';

check( 'finalisation en échec → refus', ! $r->is_success() );
rien_ne_subsiste( 'finalisation', $post );

// --- interruption après le premier document d'un lot ---
// Le second fichier n'existe pas : le premier a déjà été déposé au staging.
neuf();
$post = soumission();
$r    = traiter(
	$post,
	fx_files(
		'photos',
		array(
			array( 'a.jpg', fx_copie( fx_jpeg() ) ),
			array( 'b.jpg', sys_get_temp_dir() . '/urbizen-inexistant-' . getmypid() ),
		)
	)
);

check( 'un lot partiellement invalide est refusé en bloc', ! $r->is_success() );
rien_ne_subsiste( 'lot partiel', $post );

// --- seconde tentative après un échec ---
neuf();
$post = soumission();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files';
traiter( $post, fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );
$GLOBALS['wpd_meta_fail'] = '';

$reprise = traiter( $post, fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );

check( 'après un échec, la personne peut réessayer avec le même jeton', $reprise->is_success() );
// Le compteur a avancé lors de la tentative ratée : la série saute un rang.
// C'est le comportement acté en D-017 — faire reculer un compteur rouvrirait
// la course que la réservation atomique vient de fermer.
check( 'la seconde tentative obtient la référence suivante', str_ends_with( $reprise->reference(), '-0002' ) );
check( 'la référence abandonnée n’est plus réservée',
	null === get_option( SubmissionRepository::RESERVATION_PREFIX . 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001', null ) );
check( 'un seul fichier existe', 1 === fx_compte_fichiers() );

// ======================================================================
// 27 · LIENS SIGNÉS
// ======================================================================
neuf();
$r  = traiter( soumission(), fx_files( 'croquis_plans', array( array( 'Plan Masse.pdf', fx_copie( fx_pdf() ) ) ) ) );
$id = $r->id();
$lu = SubmissionRepository::get( $id );
$fid = $lu['files'][0]['id'];

check( 'durée par défaut : 14 jours', 1209600 === SignedLink::ttl() );

$url = SignedLink::url( $id, $fid, wpd_now() );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

check( 'le lien porte les six paramètres attendus',
	array( 'action', 'v', 'submission', 'file', 'expires', 'signature' ) === array_keys( $params ) );
check( 'l’échéance est à 14 jours', wpd_now() + 1209600 === (int) $params['expires'] );
check( 'le lien est valide', SignedLink::verify( $params, wpd_now() )['ok'] );
check( 'le document est retrouvé', null !== D::locate( $params, wpd_now() ) );

// L'URL ne porte rien de métier.
// Le domaine du site figure évidemment dans l'URL ; ce qui ne doit pas s'y
// trouver, c'est toute donnée métier ou personnelle.
foreach ( array( 'Plan', 'Masse', '.pdf', 'conception/', $lu['files'][0]['sha256'], 'Camille', 'camille@', URBIZEN_TEST_STORAGE ) as $motif ) {
	check( 'l’URL ne contient pas : ' . substr( $motif, 0, 30 ), ! str_contains( $url, $motif ) );
}

// --- altérations ---
$alterations = array(
	'signature modifiée'   => array( 'signature' => str_repeat( 'a', 64 ) ),
	'échéance repoussée'   => array( 'expires' => wpd_now() + 99999999 ),
	'autre demande'        => array( 'submission' => $id + 1 ),
	'autre fichier'        => array( 'file' => str_repeat( 'b', 32 ) ),
	'version du schéma'    => array( 'v' => 2 ),
	'identifiant non hex'  => array( 'file' => str_repeat( 'z', 32 ) ),
);

foreach ( $alterations as $libelle => $modif ) {
	$p = array_merge( $params, $modif );
	check( "lien refusé : $libelle", ! SignedLink::verify( $p, wpd_now() )['ok'] && null === D::locate( $p, wpd_now() ) );
}

check( 'un lien expiré est refusé', ! SignedLink::verify( $params, (int) $params['expires'] + 1 )['ok'] );
check( 'un lien vaut jusqu’à son échéance incluse', SignedLink::verify( $params, (int) $params['expires'] )['ok'] );
check( 'des paramètres vides sont refusés', ! SignedLink::verify( array(), wpd_now() )['ok'] );

// --- durée ajustable ---
add_filter( 'urbizen_signed_link_ttl', static fn() => 3600 );
check( 'la durée est ajustable par filtre', 3600 === SignedLink::ttl() );
$court = SignedLink::url( $id, $fid, wpd_now() );
parse_str( (string) wp_parse_url( $court, PHP_URL_QUERY ), $pc );
check( 'le lien court expire dans une heure', wpd_now() + 3600 === (int) $pc['expires'] );
wpd_clear_filter( 'urbizen_signed_link_ttl' );

// --- régénération ---
$nouveau = SignedLink::url( $id, $fid, wpd_now() + 100 );
parse_str( (string) wp_parse_url( $nouveau, PHP_URL_QUERY ), $pn );

check( 'un nouveau lien peut être émis', $pn['signature'] !== $params['signature'] );
check( 'il désigne le même document', $pn['file'] === $params['file'] );
check( 'le fichier n’a pas été modifié', 1 === fx_compte_fichiers() );
check( 'l’ancien lien reste valide jusqu’à son échéance', SignedLink::verify( $params, wpd_now() )['ok'] );

// --- métadonnée de chemin falsifiée ---
$falsifie = $lu['files'];
$falsifie[0]['relative_path'] = '../../../etc/passwd';
SubmissionRepository::set_files( $id, $falsifie );

check( 'un chemin falsifié dans les métadonnées ne donne rien', null === D::locate( $params, wpd_now() ) );

SubmissionRepository::set_files( $id, $lu['files'] );
check( 'le chemin légitime fonctionne de nouveau', null !== D::locate( $params, wpd_now() ) );

// --- fichier puis demande supprimés ---
Storage::delete_files( $r->reference(), $lu['files'] );
check( 'un fichier supprimé n’est plus servi', null === D::locate( $params, wpd_now() ) );

wp_delete_post( $id, true );
check( 'une demande supprimée ne sert plus rien', null === D::locate( $params, wpd_now() ) );
check( 'une demande inexistante ne révèle rien', null === D::locate( array_merge( $params, array( 'submission' => 99999 ) ), wpd_now() ) );

// --- nom proposé au téléchargement ---
$noms = array(
	array( "plan\r\nSet-Cookie: x=1", 'pdf' ),
	array( 'plan"; filename="autre', 'pdf' ),
	array( '../../etc/passwd', 'pdf' ),
	array( '', 'pdf' ),
);

foreach ( $noms as $cas ) {
	$propose = D::filename( $cas[0], $cas[1] );
	check(
		'nom de téléchargement assaini : ' . substr( str_replace( array( "\r", "\n" ), '\\n', $cas[0] ), 0, 28 ),
		! preg_match( "/[\r\n\"';\/\\\\]/", $propose ) && str_ends_with( $propose, '.pdf' )
	);
}

check( 'un nom sans extension en reçoit une', 'croquis.pdf' === D::filename( 'croquis', 'pdf' ) );
check( 'un nom déjà correct est conservé', 'croquis.pdf' === D::filename( 'croquis.pdf', 'pdf' ) );

// ======================================================================
// 28 · RÉTENTION
// ======================================================================
neuf();
$r         = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );
$reference = $r->reference();
$cle_ref   = SubmissionRepository::RESERVATION_PREFIX . $reference;

check( 'la demande et son document existent', $r->is_success() && 1 === fx_compte_fichiers() );

FileCleaner::register();
update_post_meta( $r->id(), '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );

$bilan = Retention::run_daily( wpd_now() );

check( 'la demande est purgée', 1 === $bilan['demandes'] && null === get_post( $r->id() ) );
check( 'LE DOCUMENT EST EFFACÉ AVEC LA DEMANDE', 0 === fx_compte_fichiers() );
check( 'le répertoire de la référence a disparu', ! is_dir( URBIZEN_TEST_STORAGE . '/conception/' . $reference ) );
check( 'LA RÉSERVATION ATTRIBUÉE SURVIT', 'attributed' === ( get_option( $cle_ref )['state'] ?? '' ) );

update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
check( 'la référence n’est pas réattribuée', SubmissionRepository::next_reference( wpd_now() ) !== $reference );

// --- suppression manuelle dans l'administration ---
neuf();
FileCleaner::register();
FileCleaner::reset();
$r = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );

check( 'un document existe', 1 === fx_compte_fichiers() );

$verdict = apply_filters( 'pre_delete_post', null, get_post( $r->id() ), true );

check( 'une suppression manuelle efface aussi le document', 0 === fx_compte_fichiers() );
check( 'la suppression n’est pas bloquée', false !== $verdict );
check( 'files_status passe à deleted', 'deleted' === get_post_meta( $r->id(), '_urbizen_files_status', true ) );

// --- idempotence ---
check( 'une seconde suppression signale already_deleted', 'already_deleted' === FileCleaner::delete( $r->id(), $r->reference() )['code'] );
check( 'une demande inexistante ne casse rien', 'already_deleted' === FileCleaner::delete( 999999, 'URB-2026-0001' )['code'] );
check( 'un identifiant nul est refusé', 'unsafe_path' === FileCleaner::delete( 0, 'URB-2026-0001' )['code'] );

// --- une demande sans document ---
neuf();
FileCleaner::register();
$sans = traiter( soumission() );
check( 'une demande sans document se supprime sans erreur', 'already_deleted' === FileCleaner::delete( $sans->id(), $sans->reference() )['code'] );

// --- une référence mal formée est refusée ---
neuf();
FileCleaner::register();
$r = traiter( soumission(), fx_files( 'photos', array( array( 'photo.jpg', fx_copie( fx_jpeg() ) ) ) ) );
check( 'une référence mal formée est refusée', 'unsafe_path' === FileCleaner::delete( $r->id(), '../etc' )['code'] );
check( 'et le document est conservé', 1 === fx_compte_fichiers() );

// --- le hook transmet bien deux arguments ---
neuf();
$recus = array();
add_action( 'urbizen_before_submission_delete', static function ( $id, $ref ) use ( &$recus ) { $recus[] = array( $id, $ref ); }, 10, 2 );
$r = traiter( soumission() );
update_post_meta( $r->id(), '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );
Retention::purge( wpd_now() );

check( 'le hook transmet l’identifiant et la référence',
	1 === count( $recus ) && is_int( $recus[0][0] ) && str_starts_with( (string) $recus[0][1], 'URB-' ) );

// --- staging abandonné ---
neuf();
$maintenant = time();
$recent     = Storage::open_staging();
$vieux      = Storage::open_staging();
@touch( (string) $vieux, $maintenant - Storage::STAGING_TTL - 10 );

$bilan = Retention::run_daily( $maintenant );

check( 'un staging expiré est nettoyé par la tâche quotidienne', 1 === $bilan['staging'] );
check( 'un staging récent est conservé', 1 === fx_compte_staging() );
check( 'le nettoyage est idempotent', 0 === Retention::run_daily( $maintenant )['staging'] );

// --- un fichier final non prouvé orphelin est conservé ---
neuf();
$dossier = URBIZEN_TEST_STORAGE . '/conception/URB-2026-0777/photos';
@mkdir( $dossier, 0700, true );
file_put_contents( $dossier . '/URB-2026-0777-photos-' . str_repeat( 'a', 32 ) . '.pdf', '%PDF-1.4' );

Retention::run_daily( time() );

check( 'UN FICHIER FINAL SANS DEMANDE EST CONSERVÉ, PAS SUPPRIMÉ', 1 === fx_compte_fichiers() );

// ======================================================================
// JOURNAUX
// ======================================================================
neuf();
$r = traiter( soumission(), fx_files( 'croquis_plans', array( array( 'Plan Confidentiel.pdf', fx_copie( fx_pdf() ) ) ) ) );

$log = journal();

check( 'le journal mentionne la référence', str_contains( $log, $r->reference() ) );
check( 'le journal donne un décompte de documents', 1 === preg_match( '/\d+ document\(s\), \d+ octets/', $log ) );

foreach ( array(
	'nom d’origine'      => 'Plan Confidentiel',
	'nom physique'       => 'croquis_plans-',
	'chemin relatif'     => 'conception/URB',
	'chemin absolu'      => URBIZEN_TEST_STORAGE,
	'répertoire système' => sys_get_temp_dir(),
	'nom du demandeur'   => 'Camille',
	'adresse'            => 'exemple.test',
	'adresse IP'         => '203.0.113',
) as $libelle => $motif ) {
	check( "le journal ne contient pas : $libelle", ! str_contains( $log, $motif ) );
}

// ======================================================================
// VERROUILLAGE DES LIENS PENDANT UNE SUPPRESSION
// ======================================================================
// Une signature valable ne suffit pas : l'état de la demande commande.

/**
 * Prépare une demande à deux documents et son lien signé.
 *
 * @return array{r:mixed,params:array<string,mixed>,params2:array<string,mixed>,dossier:string}
 */
function preparer_deux_documents(): array {
	neuf();
	FileCleaner::reset();

	$files = array_merge(
		fx_files( 'photos', array( array( 'a.jpg', fx_copie( fx_jpeg() ) ) ) ),
		fx_files( 'urbanisme', array( array( 'b.pdf', fx_copie( fx_pdf() ) ) ) )
	);

	$r  = traiter( soumission(), $files );
	$lu = SubmissionRepository::get( $r->id() );

	parse_str( (string) wp_parse_url( SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() ), PHP_URL_QUERY ), $p1 );
	parse_str( (string) wp_parse_url( SignedLink::url( $r->id(), $lu['files'][1]['id'], wpd_now() ), PHP_URL_QUERY ), $p2 );

	return array( 'r' => $r, 'params' => $p1, 'params2' => $p2, 'dossier' => URBIZEN_TEST_STORAGE . '/conception/' . $r->reference() . '/urbanisme' );
}

$cas = preparer_deux_documents();

// 1 · lien valide avant suppression.
check( 'lien · valide avant toute suppression', null !== D::locate( $cas['params'], wpd_now() ) );
check( 'lien · le second aussi', null !== D::locate( $cas['params2'], wpd_now() ) );

// 2 · refusé dès le passage à deleting.
update_post_meta( $cas['r']->id(), '_urbizen_status', SubmissionPostType::STATUS_DELETING );

check( 'lien · REFUSÉ dès le passage à deleting', null === D::locate( $cas['params'], wpd_now() ) );
check( 'lien · le second aussi', null === D::locate( $cas['params2'], wpd_now() ) );
check( 'lien · le document est pourtant toujours là', 2 === fx_compte_fichiers() );

// 3 · premier fichier effacé, second impossible.
$cas = preparer_deux_documents();
@chmod( $cas['dossier'], 0500 );

$resultat = FileCleaner::delete( $cas['r']->id(), $cas['r']->reference() );

check( 'suppression partielle · signalée comme telle', 'partial_failure' === $resultat['code'] );
check( 'suppression partielle · un document effacé, un en échec', 1 === $resultat['deleted'] && 1 === $resultat['failed'] );
check( 'suppression partielle · le second est physiquement présent', 1 === fx_compte_fichiers() );
check( 'suppression partielle · l’état passe à delete_failed', 'delete_failed' === get_post_meta( $cas['r']->id(), '_urbizen_status', true ) );
check( 'suppression partielle · les métadonnées sont conservées', 2 === (int) get_post_meta( $cas['r']->id(), '_urbizen_files_count', true ) );

// 4 et 5 · l'ancien lien du document restant est néanmoins refusé.
check( 'suppression partielle · L’ANCIEN LIEN DU DOCUMENT RESTANT EST REFUSÉ', null === D::locate( $cas['params2'], wpd_now() ) );
check( 'suppression partielle · celui du document effacé aussi', null === D::locate( $cas['params'], wpd_now() ) );

// 9 · réponse extérieure identique à celle d'un fichier inexistant.
$inexistant = array_merge( $cas['params2'], array( 'submission' => 999999 ) );
check( 'suppression partielle · même réponse que pour une demande inexistante',
	D::locate( $cas['params2'], wpd_now() ) === D::locate( $inexistant, wpd_now() ) );

// 6 · la suppression du contenu reste bloquée.
FileCleaner::reset();
check( 'suppression partielle · wp_delete_post reste bloqué',
	false === FileCleaner::guard_delete( null, get_post( $cas['r']->id() ), true ) );

// 7 et 8 · après correction, la nouvelle tentative aboutit.
@chmod( $cas['dossier'], 0700 );
FileCleaner::reset();
$resultat = FileCleaner::delete( $cas['r']->id(), $cas['r']->reference() );

check( 'nouvelle tentative · réussit', 'success' === $resultat['code'] );
check( 'nouvelle tentative · plus aucun document', 0 === fx_compte_fichiers() );
check( 'nouvelle tentative · le statut redevient exploitable', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $cas['r']->id(), '_urbizen_status', true ) );

FileCleaner::reset();
check( 'nouvelle tentative · la suppression du contenu est autorisée',
	false !== FileCleaner::guard_delete( null, get_post( $cas['r']->id() ), true ) );

// --- les états qui autorisent, et ceux qui interdisent ---
neuf();
$r  = traiter( soumission(), fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );
$lu = SubmissionRepository::get( $r->id() );
parse_str( (string) wp_parse_url( SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() ), PHP_URL_QUERY ), $p );

foreach ( array( 'received', 'converted', 'closed' ) as $etat ) {
	update_post_meta( $r->id(), '_urbizen_status', $etat );
	check( "état autorisant le téléchargement : $etat", null !== D::locate( $p, wpd_now() ) );
}

foreach ( array( 'processing', 'deleting', 'delete_failed', 'recovery_failed', 'incoherent', 'inconnu' ) as $etat ) {
	update_post_meta( $r->id(), '_urbizen_status', $etat );
	check( "état interdisant le téléchargement : $etat", null === D::locate( $p, wpd_now() ) );
}

update_post_meta( $r->id(), '_urbizen_status', 'received' );

// --- les autres conditions cumulatives ---
$ruptures = array(
	'files_status non stored' => static function ( int $id ): void {
		update_post_meta( $id, '_urbizen_files_status', 'pending' );
	},
	'transaction non committed' => static function ( int $id ): void {
		$tx          = SubmissionRepository::transaction( $id );
		$tx['state'] = 'processing';
		update_post_meta( $id, '_urbizen_transaction', (string) wp_json_encode( $tx ) );
	},
	'référence divergente' => static function ( int $id ): void {
		$tx              = SubmissionRepository::transaction( $id );
		$tx['reference'] = 'URB-2020-0001';
		update_post_meta( $id, '_urbizen_transaction', (string) wp_json_encode( $tx ) );
	},
	'réservation non attribuée' => static function ( int $id ): void {
		$ref = (string) get_post_meta( $id, '_urbizen_reference', true );
		update_option( SubmissionRepository::RESERVATION_PREFIX . $ref, array( 'state' => 'reserved', 'at' => 0, 'post' => $id ), false );
	},
	'réservation rattachée ailleurs' => static function ( int $id ): void {
		$ref = (string) get_post_meta( $id, '_urbizen_reference', true );
		update_option( SubmissionRepository::RESERVATION_PREFIX . $ref, array( 'state' => 'attributed', 'at' => '', 'post' => $id + 42 ), false );
	},
	'réservation absente' => static function ( int $id ): void {
		delete_option( SubmissionRepository::RESERVATION_PREFIX . (string) get_post_meta( $id, '_urbizen_reference', true ) );
	},
);

foreach ( $ruptures as $libelle => $casser ) {
	neuf();
	$r  = traiter( soumission(), fx_files( 'photos', array( array( 'p.jpg', fx_copie( fx_jpeg() ) ) ) ) );
	$lu = SubmissionRepository::get( $r->id() );
	parse_str( (string) wp_parse_url( SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() ), PHP_URL_QUERY ), $p );

	check( "condition · [$libelle] le lien fonctionne d’abord", null !== D::locate( $p, wpd_now() ) );
	$casser( $r->id() );
	check( "condition · [$libelle] puis il est refusé", null === D::locate( $p, wpd_now() ) );
}


verdict();
