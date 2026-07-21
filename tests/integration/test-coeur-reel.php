<?php
/**
 * Banc d'intégration exécuté contre un **vrai** WordPress.
 *
 * Les doublures sont une commodité, pas une preuve. Ce banc éprouve les mêmes
 * garanties contre le cœur réel : ordre des hooks, statut par défaut après
 * restauration, moment exact de suppression des métadonnées natives, nombre
 * d'arguments transmis, et cycle complet d'une demande Urbizen.
 *
 * Il ne touche pas à la production : il attend une installation jetable, dont
 * le chemin est donné par la variable d'environnement `URBIZEN_WP_ROOT`. Sans
 * elle, il s'abstient et le signale, sans échouer.
 *
 * Toutes les données sont fictives.
 */

$racine = (string) getenv( 'URBIZEN_WP_ROOT' );

if ( '' === $racine || ! is_readable( $racine . '/wp-load.php' ) ) {
	fwrite( STDERR, "banc réel ignoré : URBIZEN_WP_ROOT non défini ou illisible\n" );
	exit( 0 );
}

require $racine . '/wp-load.php';

$reussis = 0;
$echecs  = 0;

function verifier( string $libelle, bool $ok ): void {
	global $reussis, $echecs;

	if ( $ok ) {
		++$reussis;
		printf( "%-72s OK\n", $libelle );
	} else {
		++$echecs;
		printf( "%-72s ECHEC\n", $libelle );
	}
}

// --- le plugin, chargé sans passer par l'activation WordPress ---
define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );

foreach (
	array(
		'src/Support/Logger.php',
		'src/Support/Reference.php',
		'src/Support/OptionsScan.php',
		'src/Forms/FormDefinition.php',
		'src/Forms/FormRegistry.php',
		'src/Forms/Pricing.php',
		'src/Forms/Validator.php',
		'src/Submissions/SubmissionPostType.php',
		'src/Submissions/SubmissionRepository.php',
		'src/Submissions/TransactionRecovery.php',
		'src/Submissions/TrashGuard.php',
	) as $fichier
) {
	require_once URBIZEN_PLATFORM_DIR . $fichier;
}

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TrashGuard;

SubmissionPostType::register_post_type();

// ======================================================================
// 1 · ORDRE RÉEL DE wp_trash_post
// ======================================================================
$trace = array();

add_action( 'wp_trash_post', static function () use ( &$trace ) { $trace[] = 'wp_trash_post'; }, 10, 2 );
add_filter( 'pre_trash_post', static function ( $c, $p, $prec ) use ( &$trace ) {
	$trace[] = 'pre_trash_post(' . func_num_args() . ',' . $prec . ')';
	return $c;
}, 10, 3 );
add_action( 'trashed_post', static function () use ( &$trace ) { $trace[] = 'trashed_post(' . func_num_args() . ')'; }, 10, 2 );

$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Page jetable', 'post_status' => 'publish' ) );
wp_trash_post( $page );

verifier( '1 · pre_trash_post précède wp_trash_post',
	array_search( 'pre_trash_post(3,publish)', $trace, true ) < array_search( 'wp_trash_post', $trace, true ) );
verifier( '1 · trashed_post vient en dernier', 'trashed_post(2)' === end( $trace ) );
verifier( '8 · pre_trash_post reçoit trois arguments', in_array( 'pre_trash_post(3,publish)', $trace, true ) );
verifier( '7 · trashed_post reçoit deux arguments', in_array( 'trashed_post(2)', $trace, true ) );
verifier( '1 · _wp_trash_meta_status vaut publish', 'publish' === get_post_meta( $page, '_wp_trash_meta_status', true ) );

// ======================================================================
// 2 · ORDRE RÉEL DE wp_untrash_post ET STATUT PAR DÉFAUT
// ======================================================================
$trace = array();

add_filter( 'pre_untrash_post', static function ( $c, $p, $prec ) use ( &$trace ) {
	$trace[] = 'pre_untrash_post(' . func_num_args() . ',' . $prec . ')';
	return $c;
}, 10, 3 );
add_action( 'untrash_post', static function () use ( &$trace ) { $trace[] = 'untrash_post'; }, 10, 2 );
add_filter( 'wp_untrash_post_status', static function ( $s, $id, $prec ) use ( &$trace ) {
	$trace[] = 'wp_untrash_post_status(' . func_num_args() . ',propose=' . $s . ',prec=' . $prec . ')';
	return $s;
}, 10, 3 );
add_action( 'untrashed_post', static function () use ( &$trace ) { $trace[] = 'untrashed_post'; }, 10, 2 );

wp_untrash_post( $page );

verifier( '9 · pre_untrash_post reçoit trois arguments', in_array( 'pre_untrash_post(3,publish)', $trace, true ) );
verifier( '2 · untrash_post suit pre_untrash_post',
	array_search( 'pre_untrash_post(3,publish)', $trace, true ) < array_search( 'untrash_post', $trace, true ) );
verifier( '4 · wp_untrash_post_status reçoit trois arguments',
	in_array( 'wp_untrash_post_status(3,propose=draft,prec=publish)', $trace, true ) );
verifier( '3 · le statut proposé par défaut est draft',
	in_array( 'wp_untrash_post_status(3,propose=draft,prec=publish)', $trace, true ) );
verifier( '3 · une page restaurée retombe donc en draft, PAS en publish', 'draft' === get_post_status( $page ) );
verifier( '2 · untrashed_post vient en dernier', 'untrashed_post' === end( $trace ) );
verifier( '2 · les métadonnées natives sont supprimées après réussite',
	'' === get_post_meta( $page, '_wp_trash_meta_status', true ) && '' === get_post_meta( $page, '_wp_trash_meta_time', true ) );

wp_delete_post( $page, true );

// ======================================================================
// 5 · MÉTADONNÉES NATIVES SUPPRIMÉES **AVANT** wp_update_post
// 6 · untrashed_post ABSENT EN CAS D'ÉCHEC
// ======================================================================
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Page jetable 2', 'post_status' => 'publish' ) );
wp_trash_post( $page );

$vues     = array();
$apres    = false;
$echec_ok = false;

// Observation au moment exact de l'écriture. `wp_insert_post_data` est appelé
// par `wp_update_post()` juste avant la requête : à cet instant, les
// métadonnées natives doivent **déjà** avoir disparu.
//
// L'observation se fait sur une restauration qui réussit : le filtre d'échec
// employé plus bas (`wp_insert_post_empty_content`) court-circuite
// `wp_insert_post()` **avant** ce point, et ne permettrait rien d'observer.
$observateur = static function ( $data ) use ( &$vues, $page ) {
	if ( 'trash' !== ( $data['post_status'] ?? '' ) ) {
		$vues['status_pendant'] = get_post_meta( $page, '_wp_trash_meta_status', true );
		$vues['time_pendant']   = get_post_meta( $page, '_wp_trash_meta_time', true );
	}

	return $data;
};

add_filter( 'wp_insert_post_data', $observateur, 10, 1 );
wp_untrash_post( $page );
remove_filter( 'wp_insert_post_data', $observateur, 10 );

verifier( '5 · pendant wp_update_post, _wp_trash_meta_status a DÉJÀ disparu', '' === ( $vues['status_pendant'] ?? 'absent-du-hook' ) );
verifier( '5 · pendant wp_update_post, _wp_trash_meta_time a DÉJÀ disparu', '' === ( $vues['time_pendant'] ?? 'absent-du-hook' ) );

// L'échec proprement dit, sur une nouvelle mise à la Corbeille.
wp_trash_post( $page );

add_filter( 'wp_insert_post_empty_content', '__return_true' );
add_action( 'untrashed_post', static function () use ( &$apres ) { $apres = true; }, 10, 2 );

$resultat = wp_untrash_post( $page );

remove_filter( 'wp_insert_post_empty_content', '__return_true' );
verifier( '6 · wp_untrash_post rend false', false === $resultat );
verifier( '6 · le post est resté à la Corbeille', 'trash' === get_post_status( $page ) );
verifier( '6 · untrashed_post n’a PAS été exécuté', false === $apres );
verifier( '6 · les métadonnées natives sont perdues',
	'' === get_post_meta( $page, '_wp_trash_meta_status', true ) && '' === get_post_meta( $page, '_wp_trash_meta_time', true ) );

$recu = 'non-appele';
add_filter( 'pre_untrash_post', static function ( $c, $p, $prec ) use ( &$recu ) { $recu = $prec; return $c; }, 5, 3 );
wp_untrash_post( $page );

verifier( '6 · une seconde tentative reçoit un previous_status vide', '' === $recu );

wp_delete_post( $page, true );

// ======================================================================
// 10 · CYCLE URBIZEN COMPLET VERS private
// ======================================================================
foreach ( $GLOBALS['wp_filter']['pre_untrash_post']->callbacks ?? array() as $prio => $rappels ) {
	foreach ( array_keys( $rappels ) as $cle ) {
		unset( $GLOBALS['wp_filter']['pre_untrash_post']->callbacks[ $prio ][ $cle ] );
	}
}

TrashGuard::register();

$validation = Validator::validate(
	FormRegistry::get( 'conception' ),
	array(
		'nature'    => 'maison',
		'situation' => 'terrain_nu',
		'a_terrain' => 'non',
		'nom'       => 'Camille Fictif',
		'email'     => 'camille@exemple.test',
		'tel'       => '0100000000',
		'rgpd'      => '1',
	)
);

$creation = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => time() ) );
$id       = (int) ( $creation['id'] ?? 0 );
$ref      = (string) ( $creation['reference'] ?? '' );

verifier( '10 · la soumission réussit sur un vrai WordPress', ! empty( $creation['ok'] ) );
verifier( '10 · post_status = private', 'private' === get_post_status( $id ) );
verifier( '10 · _urbizen_status = received', 'received' === get_post_meta( $id, '_urbizen_status', true ) );
verifier( '10 · transaction committed', 'committed' === ( SubmissionRepository::transaction( $id )['state'] ?? '' ) );
verifier( '10 · référence attributed',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );
verifier( '10 · la réservation est rattachée à la demande',
	$id === (int) ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['post'] ?? 0 ) );

// La récupération transactionnelle, une heure plus tard, ne doit rien emporter.
$bilan = \Urbizen\Platform\Submissions\TransactionRecovery::run( time() + 7200 );

verifier( '10 · aucun rollback une heure plus tard', 0 === $bilan['rollback'] );
verifier( '10 · la demande existe toujours', null !== get_post( $id ) );

wp_trash_post( $id );

verifier( '10 · mise à la Corbeille : post_status = trash', 'trash' === get_post_status( $id ) );
verifier( '10 · _urbizen_status = trashed', 'trashed' === get_post_meta( $id, '_urbizen_status', true ) );
verifier( '10 · _wp_trash_meta_status = private', 'private' === get_post_meta( $id, '_wp_trash_meta_status', true ) );

wp_untrash_post( $id );

verifier( '10 · RESTAURATION : post_status revient à private', 'private' === get_post_status( $id ) );
verifier( '10 · le statut métier exact est restauré', 'received' === get_post_meta( $id, '_urbizen_status', true ) );
verifier( '10 · les métadonnées temporaires Urbizen sont supprimées',
	'' === get_post_meta( $id, TrashGuard::PRE_TRASH, true ) && array() === TrashGuard::transition( $id ) );

// Réparation après échec natif, sur le vrai cœur.
wp_trash_post( $id );
add_filter( 'wp_insert_post_empty_content', '__return_true' );
$echoue = wp_untrash_post( $id );
remove_filter( 'wp_insert_post_empty_content', '__return_true' );

verifier( '10 · échec natif : la restauration rend false', false === $echoue );
verifier( '10 · l’état natif est perdu', '' === get_post_meta( $id, '_wp_trash_meta_status', true ) );
verifier( '10 · sans réparation, la restauration reste refusée', false === wp_untrash_post( $id ) );
verifier( '10 · la réparation aboutit', true === TrashGuard::repair_native( $id, time() ) );
verifier( '10 · _wp_trash_meta_status redevient private', 'private' === get_post_meta( $id, '_wp_trash_meta_status', true ) );
verifier( '10 · la restauration aboutit alors', false !== wp_untrash_post( $id ) );
verifier( '10 · et rend bien private', 'private' === get_post_status( $id ) );
verifier( '10 · avec le statut métier exact', 'received' === get_post_meta( $id, '_urbizen_status', true ) );

// Ménage : ce banc ne laisse rien derrière lui.
delete_option( SubmissionRepository::RESERVATION_PREFIX . $ref );
wp_delete_post( $id, true );

printf( "\n%d contrôle(s) réussi(s), %d en échec\n", $reussis, $echecs );

exit( $echecs > 0 ? 1 : 0 );
