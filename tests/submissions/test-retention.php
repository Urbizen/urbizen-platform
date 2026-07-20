<?php
/**
 * Banc d'essai de la conservation.
 *
 * Une politique de rétention se juge à deux choses : elle supprime ce qu'elle
 * doit supprimer, et surtout elle ne supprime **jamais** ce qu'elle ne doit
 * pas. Un dossier client effacé par une purge automatique serait une perte
 * irréparable ; ce banc y consacre l'essentiel de son attention.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Submissions\SubmissionPostType;

/**
 * Crée une demande d'essai directement en base.
 *
 * @param string $statut       État métier.
 * @param int    $jours_depuis Ancienneté du dernier contact, en jours.
 * @return int
 */
function demande( string $statut, int $jours_depuis ): int {
	$id = wp_insert_post(
		array(
			'post_type'   => SubmissionPostType::POST_TYPE,
			'post_title'  => 'URB-2026-' . str_pad( (string) $GLOBALS['wpd_next_id'], 4, '0', STR_PAD_LEFT ),
			'post_status' => 'private',
		)
	);

	update_post_meta( $id, '_urbizen_status', $statut );
	update_post_meta( $id, '_urbizen_reference', 'URB-2026-' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT ) );
	update_post_meta( $id, '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( $jours_depuis * 86400 ) ) );

	return (int) $id;
}

wpd_reset();
SubmissionPostType::register_post_type();

// ------------------------------------------------------------ réglages -----
check( 'durée par défaut : 365 jours', 365 === Retention::days() );
check( 'la tâche porte le nom déjà déprogrammé par le Deactivator', 'urbizen_purge_expired' === Retention::HOOK );
check( 'le hook de pré-suppression est déclaré', 'urbizen_before_submission_delete' === Retention::BEFORE_DELETE );
check( 'seuls received et closed sont purgeables', array( 'received', 'closed' ) === Retention::purgeable_statuses() );
check( 'converted n’est jamais purgeable', ! in_array( 'converted', Retention::purgeable_statuses(), true ) );

// ------------------------------------------------------------ purge ---------
$vieille_recue    = demande( 'received', 400 );
$vieille_close    = demande( 'closed', 400 );
$vieille_client   = demande( 'converted', 400 );
$recente_recue    = demande( 'received', 10 );
$juste_avant      = demande( 'received', 364 );
$juste_apres      = demande( 'received', 366 );

check( 'six demandes d’essai créées', 6 === count( $GLOBALS['wpd_posts'] ) );

$supprimees = Retention::purge( wpd_now() );

check( 'trois demandes sont supprimées', 3 === $supprimees );
check( 'une demande reçue de 400 jours est supprimée', null === get_post( $vieille_recue ) );
check( 'une demande close de 400 jours est supprimée', null === get_post( $vieille_close ) );
check( 'une demande de 366 jours est supprimée', null === get_post( $juste_apres ) );
check( 'UN DOSSIER CLIENT DE 400 JOURS EST CONSERVÉ', null !== get_post( $vieille_client ) );
check( 'une demande récente est conservée', null !== get_post( $recente_recue ) );
check( 'une demande de 364 jours est conservée', null !== get_post( $juste_avant ) );
check( 'les métadonnées des demandes supprimées disparaissent aussi',
	! isset( $GLOBALS['wpd_meta'][ $vieille_recue ] ) && ! isset( $GLOBALS['wpd_meta'][ $vieille_close ] ) );

// ------------------------------------------------------------ le hook -------
$appels = array_values( array_filter( $GLOBALS['wpd_done'], static fn( $e ) => Retention::BEFORE_DELETE === $e['hook'] ) );

check( 'le hook de pré-suppression est déclenché trois fois', 3 === count( $appels ) );
check( 'il reçoit l’identifiant et la référence',
	2 === count( $appels[0]['args'] ) && is_int( $appels[0]['args'][0] ) && str_starts_with( (string) $appels[0]['args'][1], 'URB-' ) );

// La PR B2 s'y branchera : on vérifie qu'un abonné est bien appelé, et avant
// que la demande ne disparaisse.
wpd_reset();
SubmissionPostType::register_post_type();

$vus = array();
// Deux arguments déclarés : le hook en transmet deux, et WordPress plafonne
// ce qu'il transmet au nombre déclaré. La PR B2 devra faire de même.
add_action( Retention::BEFORE_DELETE, static function ( $id, $ref ) use ( &$vus ) {
	// La demande doit exister encore : sinon impossible de retrouver ses fichiers.
	$vus[] = array( 'ref' => $ref, 'existe_encore' => null !== get_post( $id ) );
}, 10, 2 );

demande( 'received', 400 );
Retention::purge( wpd_now() );

check( 'un abonné au hook est appelé', 1 === count( $vus ) );
check( 'la demande existe encore au moment du hook', true === $vus[0]['existe_encore'] );

// ------------------------------------------------------------ le filtre -----
wpd_reset();
SubmissionPostType::register_post_type();
add_filter( 'urbizen_retention_days', static fn() => 30 );

check( 'la durée est ajustable par filtre', 30 === Retention::days() );

$vieille = demande( 'received', 40 );
$jeune   = demande( 'received', 20 );

Retention::purge( wpd_now() );

check( 'avec 30 jours, une demande de 40 jours part', null === get_post( $vieille ) );
check( 'avec 30 jours, une demande de 20 jours reste', null !== get_post( $jeune ) );
wpd_clear_filter( 'urbizen_retention_days' );

// Une durée nulle ou négative effacerait tout : elle est ramenée à un minimum.
wpd_reset();
add_filter( 'urbizen_retention_days', static fn() => 0 );
check( 'une durée de 0 jour est ramenée à 1', 1 === Retention::days() );
wpd_clear_filter( 'urbizen_retention_days' );

wpd_reset();
add_filter( 'urbizen_retention_days', static fn() => -100 );
check( 'une durée négative est ramenée à 1', 1 === Retention::days() );
wpd_clear_filter( 'urbizen_retention_days' );

// ------------------------------------------------- seconde barrière ---------
// Même si la requête de métadonnées renvoyait un dossier client, la relecture
// de l'état l'écarterait.
wpd_reset();
SubmissionPostType::register_post_type();

$client = demande( 'converted', 400 );
add_filter( 'urbizen_retention_days', static fn() => 1 );
Retention::purge( wpd_now() );
wpd_clear_filter( 'urbizen_retention_days' );

check( 'un dossier client survit même à une durée d’un jour', null !== get_post( $client ) );

// --------------------------------------------------------- planification ---
wpd_reset();

check( 'aucune tâche au départ', false === wp_next_scheduled( Retention::HOOK ) );

Retention::schedule();
$premier = wp_next_scheduled( Retention::HOOK );

check( 'la tâche est programmée', false !== $premier );

Retention::schedule();

check( 'programmer deux fois ne crée pas de doublon', $premier === wp_next_scheduled( Retention::HOOK ) );

Retention::unschedule();

check( 'la tâche est déprogrammée', false === wp_next_scheduled( Retention::HOOK ) );

Retention::unschedule();

check( 'déprogrammer deux fois ne pose pas de problème', false === wp_next_scheduled( Retention::HOOK ) );

Retention::register();

check( 'la purge est accrochée à la tâche', has_action( Retention::HOOK ) );

// ------------------------------------------------------------- journal ------
wpd_reset();
SubmissionPostType::register_post_type();
demande( 'received', 400 );
Retention::purge( wpd_now() );

check( 'la purge est journalisée', str_contains( journal(), 'rétention' ) );
check( 'le journal indique le nombre et la durée', 1 === preg_match( '/\d+ demande\(s\) supprimée\(s\) après \d+ jours/', journal() ) );
check( 'le journal ne cite aucune référence supprimée', ! str_contains( journal(), 'URB-2026-' ) );

// --------------------------------------------------- aucune table SQL -------
$source = file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Privacy/Retention.php' );

check( 'aucune requête SQL directe', ! preg_match( '/\b(CREATE TABLE|dbDelta|\$wpdb)\b/i', (string) $source ) );

verdict();
