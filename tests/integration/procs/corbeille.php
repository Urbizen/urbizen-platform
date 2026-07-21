<?php
/** Met une demande à la Corbeille, une fois le signal donné. */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Submissions\TrashGuard;

$id     = (int) getenv( 'URBIZEN_ID' );
$rdv    = (string) getenv( 'URBIZEN_RDV' );
$signal = (string) getenv( 'URBIZEN_SIGNAL' );

TrashGuard::register();

if ( '' !== $signal ) {
	urbizen_attendre( $rdv . '/' . $signal, 20.0 );
}

$resultat = wp_trash_post( $id );

file_put_contents( $rdv . '/corbeille-resultat', false === $resultat ? 'refusee' : 'aboutie' );
urbizen_jalon( $rdv . '/corbeille-terminee' );
