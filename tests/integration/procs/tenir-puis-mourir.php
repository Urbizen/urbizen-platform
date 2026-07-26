<?php
/**
 * Processus fils : ACQUIERT le verrou d'adresse, puis MEURT sans le libérer.
 *
 * C'est l'épreuve du fencing : un bail à échéance laisserait ici un verrou
 * orphelin jusqu'à expiration, fenêtre pendant laquelle deux processus se
 * croiraient seuls. `GET_LOCK()` n'a pas d'échéance — il est lié à la
 * connexion, que la mort du processus ferme. Le verrou se libère donc **de
 * lui-même**, sans fenêtre à deux propriétaires.
 *
 * Le processus signale « tenu » puis s'arrête brutalement, SANS appeler
 * `liberer()`. Le parent vérifie ensuite que le verrou est redevenu libre.
 */

declare( strict_types = 1 );

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Account\VerrouAdresse;
use Urbizen\Platform\Adapter\WpdbGateway;

$rdv     = (string) getenv( 'URBIZEN_RDV' );
$adresse = (string) getenv( 'URBIZEN_ADRESSE' );

$verrou = VerrouAdresse::acquerir( new WpdbGateway(), $adresse, 5 );

if ( null === $verrou ) {
	file_put_contents( $rdv . '/tenu-mort', 'refuse' );
	exit( 1 );
}

file_put_contents( $rdv . '/tenu-mort', 'acquis' );

// Mort brutale : on ne libère PAS. La connexion se ferme avec le processus, et
// le moteur relâche le verrou. `exit()` sans `liberer()` est délibéré.
exit( 0 );
