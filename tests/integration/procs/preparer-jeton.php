<?php
/**
 * Processus fils : prépare une émission, au signal.
 *
 * Deux fils préparent en même temps pour le même compte. Le verrou doit les
 * sérialiser : jamais deux jetons actifs, jamais d'incrémentation perdue.
 *
 * Usage : php preparer-jeton.php <compte> <fichier> <depart>
 */

declare( strict_types = 1 );

$compte   = (int) ( $argv[1] ?? 0 );
$resultat = (string) ( $argv[2] ?? '' );
$depart   = (float) ( $argv[3] ?? 0 );

if ( $compte <= 0 || '' === $resultat ) {
	fwrite( STDERR, "arguments attendus\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;

$attente = $depart - microtime( true );

if ( $attente > 0 ) {
	usleep( (int) ( $attente * 1000000 ) );
}

$service = new VerificationService( new WpComptes(), new WpdbGateway() );
$r       = $service->preparer( $compte );

if ( $r->est_prepare() ) {
	// Confirmation immédiate : c'est elle qui touche le quota, et c'est donc
	// elle qui pourrait perdre une incrémentation.
	$service->confirmer_emission( $compte );

	// Le jeton n'est PAS écrit : un fichier de banc n'a pas à le porter. Seul
	// son condensat, déjà en base, permettra au parent de compter.
	file_put_contents( $resultat, 'prepare' );

	exit( 0 );
}

file_put_contents( $resultat, 'refuse:' . $r->motif() );

exit( 0 );
