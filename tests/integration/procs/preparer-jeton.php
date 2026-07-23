<?php
/**
 * Processus fils : prépare une émission, au signal.
 *
 * Deux fils préparent en même temps pour le même compte. Le verrou doit les
 * sérialiser, et le nouvel état d'émission en attente doit interdire au second
 * de repartir : jamais deux courriels pour une même demande, jamais deux jetons
 * actifs, jamais d'incrémentation perdue.
 *
 * Le quatrième argument, facultatif, demande de NE PAS confirmer — c'est ainsi
 * qu'on reproduit un appelant encore en train d'envoyer son courriel.
 *
 * Usage : php preparer-jeton.php <compte> <fichier> <depart> [confirmer|attendre]
 */

declare( strict_types = 1 );

$compte   = (int) ( $argv[1] ?? 0 );
$resultat = (string) ( $argv[2] ?? '' );
$depart   = (float) ( $argv[3] ?? 0 );
$suite    = (string) ( $argv[4] ?? 'confirmer' );

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
	if ( 'confirmer' === $suite ) {
		// Confirmation immédiate : c'est elle qui touche le quota, et c'est
		// donc elle qui pourrait perdre une incrémentation.
		$service->confirmer_emission( $compte, $r->emission_id() );
	}

	// Ni le jeton, ni l'adresse ne sont écrits : un fichier de banc n'a pas à
	// les porter. Seul l'identifiant d'émission ressort, pour que le parent
	// puisse éprouver qu'un ancien identifiant ne clôt rien.
	file_put_contents( $resultat, 'prepare:' . $r->emission_id() );

	exit( 0 );
}

file_put_contents( $resultat, 'refuse:' . $r->motif() );

exit( 0 );
