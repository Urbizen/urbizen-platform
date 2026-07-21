<?php
/**
 * Envoi dont le transport dure **plus longtemps que le bail d'option**.
 *
 * C'est le scénario que le bail ne sait pas décrire : le propriétaire est
 * vivant, son échéance est dépassée. Le mutex de processus, lui, le sait.
 */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Mail\MailTransport;

$id     = (int) getenv( 'URBIZEN_ID' );
$rdv    = (string) getenv( 'URBIZEN_RDV' );
$duree  = (int) ( getenv( 'URBIZEN_DUREE' ) ?: 6 );

// Bail volontairement très court : seul le mutex protège encore.
add_filter( 'urbizen_mail_lock_ttl', static fn() => 1 );

final class TransportLent implements MailTransport {

	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		$rdv   = (string) getenv( 'URBIZEN_RDV' );
		$duree = (int) ( getenv( 'URBIZEN_DUREE' ) ?: 6 );

		urbizen_jalon( $rdv . '/transport-commence' );
		sleep( $duree );
		file_put_contents( $rdv . '/transport-termine', '1' );

		return array( 'ok' => true, 'code' => 'accepted' );
	}
}

MailScheduler::set_transport( new TransportLent() );
urbizen_jalon( $rdv . '/envoi-demarre' );

file_put_contents( $rdv . '/envoi-resultat', MailScheduler::process( $id, time() ) );
