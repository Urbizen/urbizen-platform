<?php
/**
 * Processus fils P1 : inscription dont la CONNEXION est tuée juste avant l'INSERT.
 *
 * C'est l'épreuve du fencing face à la reconnexion de `wpdb`. P1 acquiert le
 * verrou GET_LOCK sur sa connexion, entre dans `wp_insert_user`, et, au tout
 * dernier filtre avant l'INSERT (`wp_pre_insert_user_data`), TUE sa propre
 * connexion depuis une connexion latérale. La mort de la connexion libère le
 * verrou. Le processus PHP, lui, reste vivant.
 *
 * P1 attend alors que le parent lance P2 (qui, le verrou libéré, crée le
 * compte), puis laisse l'INSERT de P1 se poursuivre sur la connexion morte.
 *
 * - Sans protection : `wpdb` se reconnecte et **rejoue** l'INSERT sur une
 *   connexion neuve, sans verrou → un second compte apparaît (doublon).
 * - Avec la section non reconnectable : l'INSERT échoue, {@see ConnexionPerdue}
 *   est levée, P1 ne crée rien.
 */

declare( strict_types = 1 );

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;

global $wpdb;

$rdv     = (string) getenv( 'URBIZEN_RDV' );
$adresse = (string) getenv( 'URBIZEN_ADRESSE' );
$mdp     = (string) getenv( 'URBIZEN_MDP' );

// Identifiant de la connexion que `wpdb` (et donc l'INSERT) utilise.
$cid = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );

// Au dernier filtre avant l'INSERT : on tue la connexion de wpdb depuis une
// connexion latérale, puis on attend que P2 ait fini avant de laisser l'INSERT
// se poursuivre sur la connexion morte.
add_filter(
	'wp_pre_insert_user_data',
	static function ( $data ) use ( $cid, $rdv ) {
		$c = @mysqli_connect( 'localhost', DB_USER, DB_PASSWORD, DB_NAME, 0, '/tmp/mysql.sock' );

		if ( $c ) {
			mysqli_query( $c, 'KILL ' . $cid );
			mysqli_close( $c );
		}

		usleep( 100000 );
		file_put_contents( $rdv . '/insert-imminent', '1' );
		urbizen_attendre( $rdv . '/p2-fini', 20.0 );

		return $data;
	},
	PHP_INT_MAX
);

$comptes = new WpComptes();
$service = new InscriptionService(
	$comptes,
	new VerificationService( $comptes, new WpdbGateway() ),
	new WpdbGateway()
);

$r = $service->inscrire( $adresse, $mdp, time() );

file_put_contents(
	$rdv . '/res-p1',
	(string) wp_json_encode(
		array(
			'compte' => (int) $r['compte'],
			'cree'   => (bool) $r['cree'],
			'motif'  => (string) $r['motif'],
		)
	)
);

exit( 0 );
