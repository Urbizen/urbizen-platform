<?php
/** Planifie l'événement d'une demande, au signal, pour éprouver l'atomicité. */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Mail\MailScheduler;

$id  = (int) getenv( 'URBIZEN_ID' );
$rdv = (string) getenv( 'URBIZEN_RDV' );
$moi = (string) getenv( 'URBIZEN_MOI' );

urbizen_attendre( $rdv . '/top', 20.0 );

file_put_contents( $rdv . '/planif-' . $moi, MailScheduler::schedule_unique( $id, time() ) ? 'ok' : 'ko' );
