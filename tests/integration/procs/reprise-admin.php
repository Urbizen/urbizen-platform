<?php
/** Rejoue l'action administrative de reprise, au signal. */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\MailScheduler;

$id  = (int) getenv( 'URBIZEN_ID' );
$rdv = (string) getenv( 'URBIZEN_RDV' );
$moi = (string) getenv( 'URBIZEN_MOI' );

urbizen_attendre( $rdv . '/top', 20.0 );

$resultat = MailQueue::with_lock(
	$id,
	static function ( string $jeton ) use ( $id ) {
		$statut = (string) get_post_meta( $id, MailPolicy::META_STATUS, true );

		if ( ! in_array( $statut, array( MailPolicy::FAILED, MailPolicy::CANCELLED ), true ) ) {
			return false;
		}

		return MailQueue::requeue( $id ) && MailScheduler::schedule_unique( $id, null, $jeton );
	}
);

file_put_contents( $rdv . '/reprise-' . $moi, ( ! empty( $resultat['ok'] ) && true === $resultat['valeur'] ) ? 'faite' : 'sans-effet' );
