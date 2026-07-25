<?php
/**
 * Processus fils : ACQUIERT le verrou d'adresse et le TIENT.
 *
 * Il modélise « P1 dans sa section critique » : tant qu'il vit et tient, aucun
 * autre processus ne peut entrer. Il signale « tenu », attend l'autorisation
 * de libérer (jusqu'à une borne), relâche, signale « libéré ». Aucune échéance
 * n'entre en jeu : `GET_LOCK()` tient tant que ce processus vit.
 */

declare( strict_types = 1 );

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Account\VerrouAdresse;
use Urbizen\Platform\Adapter\WpdbGateway;

$rdv     = (string) getenv( 'URBIZEN_RDV' );
$adresse = (string) getenv( 'URBIZEN_ADRESSE' );

$verrou = VerrouAdresse::acquerir( new WpdbGateway(), $adresse, 5 );

if ( null === $verrou ) {
	file_put_contents( $rdv . '/tenu', 'refuse' );
	exit( 1 );
}

file_put_contents( $rdv . '/tenu', 'acquis' );

// On tient jusqu'à ce que le parent l'autorise (ou une borne de sûreté).
urbizen_attendre( $rdv . '/liberer-verrou', 15.0 );

$ok = $verrou->liberer();

file_put_contents( $rdv . '/libere', $ok ? '1' : '0' );

exit( 0 );
