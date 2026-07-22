<?php
/**
 * Processus fils : consomme un jeton, au signal.
 *
 * Plusieurs fils reçoivent **le même jeton** et tentent de le consommer au même
 * instant. Exactement un doit réussir : c'est le verrou, doublé de la
 * suppression du condensat, qui doit l'assurer.
 *
 * Usage : php consommer-jeton.php <compte> <jeton> <fichier> <depart>
 */

declare( strict_types = 1 );

$compte   = (int) ( $argv[1] ?? 0 );
$jeton    = (string) ( $argv[2] ?? '' );
$resultat = (string) ( $argv[3] ?? '' );
$depart   = (float) ( $argv[4] ?? 0 );

if ( $compte <= 0 || '' === $jeton || '' === $resultat ) {
	fwrite( STDERR, "arguments attendus\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;

// Tous les fils s'alignent sur le même instant : l'amorçage de WordPress dure
// assez longtemps pour les désynchroniser, et la course ne serait pas réelle.
$attente = $depart - microtime( true );

if ( $attente > 0 ) {
	usleep( (int) ( $attente * 1000000 ) );
}

$service = new VerificationService( new WpComptes(), new WpdbGateway() );
$motif   = $service->consommer( $compte, $jeton );

file_put_contents( $resultat, '' === $motif ? 'succes' : ( 'echec:' . $motif ) );

exit( 0 );
