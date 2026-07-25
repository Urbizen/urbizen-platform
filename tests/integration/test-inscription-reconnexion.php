<?php
/**
 * Banc : l'inscription face à la RECONNEXION de wpdb, sur un WordPress réel.
 *
 * `GET_LOCK()` seul ne suffit pas à un fencing dans WordPress : si la connexion
 * qui tient le verrou meurt, `wpdb` se reconnecte et **rejoue** l'écriture sur
 * une connexion neuve, qui ne tient plus le verrou. Ce banc reproduit
 * exactement ce défaut :
 *
 *   1. P1 acquiert le verrou, puis sa connexion est tuée juste avant l'INSERT ;
 *   2. le verrou est libéré par la mort de la connexion ;
 *   3. P2 crée le compte ;
 *   4. l'INSERT de P1 se poursuit sur la connexion morte.
 *
 * Avec la section non reconnectable, l'INSERT de P1 échoue (ConnexionPerdue) et
 * un seul compte existe. Sans elle, `wpdb` rejoue l'INSERT et un doublon
 * apparaît — ce banc tombe alors, ce qui est précisément son rôle.
 *
 * Ne s'exécute que si `URBIZEN_WP_ROOT` désigne une installation jetable.
 */

declare( strict_types = 1 );

require __DIR__ . '/amorce-reelle.php';
require_once __DIR__ . '/amorce-outils.php';

use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\RoleClient;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Account\VerrouAdresse;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;

urbizen_banc_exiger_cron_desactive();

global $wpdb;

$reussis = 0;
$echecs  = 0;
$rates   = array();

function check( string $libelle, bool $vrai ): void {
	global $reussis, $echecs, $rates;

	if ( $vrai ) {
		++$reussis;
		printf( "%-72s OK\n", $libelle );
		return;
	}

	++$echecs;
	$rates[] = $libelle;
	printf( "%-72s ECHEC\n", $libelle );
}

function verdict(): void {
	global $reussis, $echecs, $rates;

	printf( "\n%d contrôle(s) réussi(s), %d en échec\n", $reussis, $echecs );

	foreach ( $rates as $libelle ) {
		echo "  - $libelle\n";
	}

	exit( $echecs > 0 ? 1 : 0 );
}

$rdv = sys_get_temp_dir() . '/urbizen-reco-' . getmypid();
@mkdir( $rdv, 0700, true );

function lancer( string $script, array $env = array() ): void {
	global $rdv;

	$prefixe = sprintf(
		'URBIZEN_WP_ROOT=%s URBIZEN_RDV=%s ',
		escapeshellarg( (string) getenv( 'URBIZEN_WP_ROOT' ) ),
		escapeshellarg( $rdv )
	);

	foreach ( $env as $cle => $valeur ) {
		$prefixe .= sprintf( '%s=%s ', $cle, escapeshellarg( (string) $valeur ) );
	}

	$commande = $prefixe . escapeshellcmd( PHP_BINARY ) . ' -d error_reporting=22519 -d display_errors=0 '
		. escapeshellarg( __DIR__ . '/procs/' . $script ) . ' > /dev/null 2>&1 &';

	$flux = popen( $commande, 'r' );

	if ( is_resource( $flux ) ) {
		pclose( $flux );
	}
}

function attendre( string $nom, float $secondes = 20.0 ): bool {
	global $rdv;

	return urbizen_attendre( $rdv . '/' . $nom, $secondes );
}

function lire( string $nom ): string {
	global $rdv;

	return is_readable( $rdv . '/' . $nom ) ? trim( (string) file_get_contents( $rdv . '/' . $nom ) ) : '';
}

function compter_users( string $email ): int {
	global $wpdb;
	wp_cache_flush();

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", $email ) );
}

function verrou_libre( string $email ): bool {
	global $wpdb;

	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', VerrouAdresse::nom_pour( $email ) ) );
}

function nettoyer_adresse( string $email ): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_cache_flush();

	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s", $email ) ) as $id ) {
		wp_delete_user( (int) $id );
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE user_email = %s", $email ) );
	wp_cache_flush();
}

RoleClient::installer();

$mdp     = 'MotDePasseValide123';
$adresse = 'reco@ex.test';
nettoyer_adresse( $adresse );

// P1 démarre : il acquiert le verrou, puis se fera tuer la connexion au dernier
// filtre avant l'INSERT.
lancer( 'inscrire-connexion-tuee.php', array( 'URBIZEN_ADRESSE' => $adresse, 'URBIZEN_MDP' => $mdp ) );

check( 'P1 atteint le point d’INSERT et tue sa connexion', attendre( 'insert-imminent', 20.0 ) );

// La connexion morte a libéré le verrou : il doit être redevenu libre.
$libre = false;

for ( $i = 0; $i < 60 && ! $libre; $i++ ) {
	$libre = verrou_libre( $adresse );
	usleep( 50000 );
}

check( 'le verrou est libre après la mort de la connexion de P1', $libre );
check( 'aucun compte n’existe encore', 0 === compter_users( $adresse ) );

// P2 crée le compte, verrou en main (sur la connexion du parent).
$comptes = new WpComptes();
$p2      = new InscriptionService( $comptes, new VerificationService( $comptes, new WpdbGateway() ), new WpdbGateway() );
$r2      = $p2->inscrire( $adresse, $mdp, time() );

check( 'P2 crée le compte, verrou en main', true === $r2['cree'] );
check( 'après P2, exactement un compte', 1 === compter_users( $adresse ) );

// On laisse l'INSERT de P1 se poursuivre sur sa connexion morte.
file_put_contents( $rdv . '/p2-fini', '1' );

check( 'P1 rend son verdict', attendre( 'res-p1', 20.0 ) );
$rp1 = json_decode( lire( 'res-p1' ), true );

// LE contrôle discriminant : l'INSERT de P1 n'a PAS été rejoué sur une nouvelle
// connexion. Sans la protection, il y aurait deux comptes.
check( 'AUCUN doublon : exactement UN compte au terme de l’entrelacement', 1 === compter_users( $adresse ) );
check( 'P1 (connexion perdue) n’a rien créé', is_array( $rp1 ) && false === (bool) $rp1['cree'] );
check( 'P1 échoue explicitement par « connexion_perdue »', is_array( $rp1 ) && 'connexion_perdue' === (string) $rp1['motif'] );
check( 'le compte restant est bien celui de P2', (int) ( get_user_by( 'email', $adresse )->ID ?? -1 ) === (int) $r2['compte'] );
check( 'le compte de P2 ne porte que le rôle urbizen_client',
	array( 'urbizen_client' ) === array_values( ( get_user_by( 'email', $adresse )->roles ?? array() ) ) );
check( 'aucun verrou résiduel', verrou_libre( $adresse ) );

nettoyer_adresse( $adresse );
array_map( 'unlink', (array) glob( $rdv . '/*' ) );
@rmdir( $rdv );

check( 'sortie · l’adresse de test est rendue', 0 === compter_users( $adresse ) );

verdict();
