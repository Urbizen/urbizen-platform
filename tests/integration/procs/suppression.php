<?php
/** Tente une suppression définitive. */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Submissions\TrashGuard;

$id     = (int) getenv( 'URBIZEN_ID' );
$rdv    = (string) getenv( 'URBIZEN_RDV' );
$signal = (string) getenv( 'URBIZEN_SIGNAL' );

TrashGuard::register();
FileCleaner::register();

if ( '' !== $signal ) {
	urbizen_attendre( $rdv . '/' . $signal, 20.0 );
}

$resultat = wp_delete_post( $id, true );

file_put_contents( $rdv . '/suppression-resultat', false === $resultat ? 'refusee' : 'aboutie' );
