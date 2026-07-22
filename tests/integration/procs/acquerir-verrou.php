<?php
/**
 * Processus fils : tente d'acquérir le verrou de migration.
 *
 * Écrit `acquis` ou `refuse` sur la sortie standard, puis libère s'il a
 * obtenu le verrou. Un second processus réel est le seul moyen d'éprouver
 * l'atomicité de l'acquisition : dans un seul processus, `add_option()` peut
 * paraître atomique sans l'être.
 */

declare( strict_types = 1 );

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Schema\MigrationLock;

$verrou = MigrationLock::acquerir();

if ( null === $verrou ) {
	echo 'refuse';
	exit( 0 );
}

echo 'acquis';

// On rend ce qu'on a pris : le banc parent contrôle l'état après coup.
$verrou->liberer();

exit( 0 );
