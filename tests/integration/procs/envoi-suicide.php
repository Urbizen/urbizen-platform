<?php
/**
 * Envoi dont le processus est réellement tué pendant le transport.
 *
 * Il écrit son PID, entre dans le transport, et attend d'être abattu.
 */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Mail\MailTransport;

$id  = (int) getenv( 'URBIZEN_ID' );
$rdv = (string) getenv( 'URBIZEN_RDV' );

add_filter( 'urbizen_mail_lock_ttl', static fn() => 1 );

final class TransportSuspendu implements MailTransport {

	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		$rdv = (string) getenv( 'URBIZEN_RDV' );

		file_put_contents( $rdv . '/pid', (string) getmypid() );
		urbizen_jalon( $rdv . '/transport-commence' );
		sleep( 60 );

		return array( 'ok' => true, 'code' => 'accepted' );
	}
}

MailScheduler::set_transport( new TransportSuspendu() );
MailScheduler::process( $id, time() );
