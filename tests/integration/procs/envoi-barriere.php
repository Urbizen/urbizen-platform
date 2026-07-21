<?php
/**
 * Processus d'envoi qui s'arrête juste avant le transport.
 *
 * Il pose un jalon, attend l'autorisation, puis laisse l'envoi se terminer.
 * C'est le seul moyen d'observer ce qui se passe pendant qu'un envoi est
 * réellement en vol.
 */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Mail\MailTransport;

$id  = (int) getenv( 'URBIZEN_ID' );
$rdv = (string) getenv( 'URBIZEN_RDV' );

final class TransportBarriere implements MailTransport {

	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		$rdv = (string) getenv( 'URBIZEN_RDV' );

		urbizen_jalon( $rdv . '/a-la-barriere' );
		urbizen_attendre( $rdv . '/liberer', 20.0 );
		file_put_contents( $rdv . '/transport-appele', '1' );

		return array( 'ok' => true, 'code' => 'accepted' );
	}
}

MailScheduler::set_transport( new TransportBarriere() );

file_put_contents( $rdv . '/envoi-resultat', MailScheduler::process( $id, time() ) );
