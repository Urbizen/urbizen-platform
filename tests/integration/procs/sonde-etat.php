<?php
/**
 * Observe l'état pendant qu'un envoi est en vol, et tente les transitions.
 */

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailProcessLock;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Submissions\TrashGuard;

$id  = (int) getenv( 'URBIZEN_ID' );
$rdv = (string) getenv( 'URBIZEN_RDV' );

add_filter( 'urbizen_mail_lock_ttl', static fn() => 1 );

TrashGuard::register();
FileCleaner::register();

urbizen_attendre( $rdv . '/transport-commence', 25.0 );

// On laisse le bail expirer largement — mais le propriétaire vit toujours.
sleep( 3 );
wp_cache_flush();

$bail = get_option( MailPolicy::LOCK_PREFIX . $id, null );

file_put_contents(
	$rdv . '/sonde',
	wp_json_encode(
		array(
			'bail_present' => is_array( $bail ),
			'bail_expire'  => is_array( $bail ) ? ( time() >= (int) ( $bail['expires'] ?? 0 ) ) : null,
			'mutex_tenu'   => MailProcessLock::is_held( $id ),
			'is_locked'    => MailQueue::is_locked( $id, time() ),
			'corbeille'    => false === wp_trash_post( $id ) ? 'refusee' : 'ABOUTIE',
			'suppression'  => false === wp_delete_post( $id, true ) ? 'refusee' : 'ABOUTIE',
			'reprise_bail' => null === MailQueue::acquire_lock( $id, time() ) ? 'refusee' : 'OBTENUE',
		)
	)
);
