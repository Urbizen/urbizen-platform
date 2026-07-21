<?php
/**
 * Contrôle de fidélité de la doublure, exécutable isolément.
 *
 * Ce banc est lancé dans un sous-processus par `test-mutation.php`, une fois
 * avec la doublure du dépôt et une fois avec des copies mutées. Il encode le
 * contrat que le cœur de WordPress 7.0.2 impose à `wp_untrash_post()` :
 * les métadonnées natives de Corbeille sont effacées **avant** l'écriture du
 * statut, et `untrashed_post` n'est pas exécuté si cette écriture échoue.
 *
 * Il rend un code de sortie non nul dès qu'un point du contrat tombe.
 */

require __DIR__ . '/bootstrap.php';


$echecs = 0;

function fid( string $nom, bool $ok ): void {
	global $echecs;

	if ( ! $ok ) {
		++$echecs;
		fwrite( STDERR, "FIDELITE ECHEC · $nom\n" );
	}
}

// Aucun greffon n'est accroché : ce banc éprouve le **cœur simulé**, pas
// TrashGuard. Un garde qui court-circuiterait `pre_untrash_post` masquerait
// tout le reste du contrat.
$id = wp_insert_post( array( 'post_type' => 'urbizen_demande', 'post_status' => 'private' ) );

$vus = array();
add_action( 'untrash_post', static function () use ( &$vus ) { $vus[] = 'untrash_post'; }, 99, 2 );
add_action( 'untrashed_post', static function () use ( &$vus ) { $vus[] = 'untrashed_post'; }, 99, 2 );

$GLOBALS['wpd_meta'][ $id ]['_wp_trash_meta_status'] = 'private';
$GLOBALS['wpd_meta'][ $id ]['_wp_trash_meta_time']   = wpd_now();
get_post( $id )->post_status                         = 'trash';

$GLOBALS['wpd_untrash_fail'] = true;
$resultat                    = wp_untrash_post( $id );
$GLOBALS['wpd_untrash_fail'] = false;

fid( 'wp_untrash_post rend false', false === $resultat );
fid( 'le post est encore trash', 'trash' === get_post( $id )->post_status );
fid( '_wp_trash_meta_status est absent', '' === get_post_meta( $id, '_wp_trash_meta_status', true ) );
fid( '_wp_trash_meta_time est absente', '' === get_post_meta( $id, '_wp_trash_meta_time', true ) );
fid( 'untrash_post a été exécuté', in_array( 'untrash_post', $vus, true ) );
fid( 'untrashed_post n’a pas été exécuté', ! in_array( 'untrashed_post', $vus, true ) );

$recu = null;
add_filter( 'pre_untrash_post', static function ( $c, $p, $prec ) use ( &$recu ) { $recu = $prec; return $c; }, 1, 3 );
wp_untrash_post( $id );

fid( 'un nouvel appel reçoit un previous_status vide', '' === $recu );

exit( $echecs > 0 ? 1 : 0 );
