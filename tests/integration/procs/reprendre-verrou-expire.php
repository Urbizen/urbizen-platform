<?php
/**
 * Processus fils : reprend un verrou **expiré**, au signal.
 *
 * Le parent pose un verrou déjà échu, puis lance N fils qui attendent tous le
 * même instant avant de tenter la reprise. C'est la seule façon d'éprouver la
 * fenêtre exacte que l'ancienne implémentation laissait ouverte : lire,
 * supprimer, reposer.
 *
 * Chaque fils écrit une ligne dans son fichier de résultat :
 *
 *   acquis:<proprietaire>   il détient le verrou
 *   refuse                  un autre l'a eu
 *
 * Le parent exige **exactement un** `acquis`.
 *
 * Usage : php reprendre-verrou-expire.php <fichier-resultat> <horodatage-de-depart>
 */

declare( strict_types = 1 );

$resultat = $argv[1] ?? '';
$depart   = (float) ( $argv[2] ?? 0 );

if ( '' === $resultat ) {
	fwrite( STDERR, "fichier de résultat attendu\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Adapter\WpdbGateway;
use Urbizen\Platform\Schema\MigrationLock;

// Tout le monde s'aligne sur le même instant : l'amorçage de WordPress dure
// assez longtemps pour désynchroniser les fils si on ne les resynchronisait
// pas ici, et la course ne serait alors jamais réelle.
$attente = $depart - microtime( true );

if ( $attente > 0 ) {
	usleep( (int) ( $attente * 1000000 ) );
}

$verrou = MigrationLock::acquerir( new WpdbGateway() );

if ( null === $verrou ) {
	file_put_contents( $resultat, 'refuse' );
	exit( 0 );
}

// On garde le verrou un instant : si un second fils croyait l'avoir, il
// aurait le temps de l'écrire, et le parent verrait deux « acquis ».
usleep( 200000 );

file_put_contents( $resultat, 'acquis:' . $verrou->proprietaire() );

$verrou->liberer();

exit( 0 );
