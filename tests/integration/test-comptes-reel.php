<?php
/**
 * Banc : le socle des comptes, sur un WordPress réel.
 *
 * Ce que les doublures ne peuvent pas prouver :
 *
 *   `wp_insert_user` attribue bien le rôle, et refuse sans lui ;
 *   `profile_update` se déclenche vraiment, et la garde tient ;
 *   la consommation concurrente d'un jeton par plusieurs processus n'en laisse
 *   réussir qu'un seul ;
 *   une visite publique n'écrit aucune option de rôle.
 */

declare( strict_types = 1 );

require __DIR__ . '/amorce-reelle.php';
require_once __DIR__ . '/amorce-outils.php';

use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\JetonVerification as J;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\RoleClient;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;

urbizen_banc_exiger_cron_desactive();

global $wpdb;

$reussis = 0;
$echecs  = 0;
$rates   = array();

/**
 * Contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $vrai    Résultat.
 * @return void
 */
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

/**
 * Verdict.
 *
 * @return void
 */
function verdict(): void {
	global $reussis, $echecs, $rates;

	printf( "\n%d contrôle(s) réussi(s), %d en échec\n", $reussis, $echecs );

	foreach ( $rates as $libelle ) {
		echo "  - $libelle\n";
	}

	exit( $echecs > 0 ? 1 : 0 );
}

/**
 * Supprime les comptes de banc.
 *
 * @return int
 */
function menage_comptes(): int {
	global $wpdb;

	// `wp_delete_user()` appartient à l'administration : hors de ce contexte,
	// le fichier n'est pas chargé.
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	$ids = $wpdb->get_col( // phpcs:ignore
		"SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@banc-urbizen.test'"
	);

	$n = 0;

	foreach ( (array) $ids as $id ) {
		wp_delete_user( (int) $id );
		++$n;
	}

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_compte_lock_%'" ); // phpcs:ignore

	return $n;
}

menage_comptes();
register_shutdown_function( 'menage_comptes' );

$comptes = new WpComptes();
$db      = new WpdbGateway();
$verif   = new VerificationService( $comptes, $db );
$inscr   = new InscriptionService( $comptes, $verif );
$mdp     = 'motdepasse-de-banc-1';

// ======================================================================
// 1 · LE RÔLE N'EST PAS INSTALLÉ AUTOMATIQUEMENT
// ======================================================================
remove_role( RoleClient::ROLE );

check( '1 · le rôle est absent au départ', false === RoleClient::est_conforme() );
check( '1 · le motif est nommé', 'role_absent' === RoleClient::motif_de_non_conformite() );

// Une inscription sans rôle ne doit RIEN créer.
$avant_utilisateurs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore
$refus = $inscr->inscrire( 'sansrole@banc-urbizen.test', $mdp );

check( '1 · SANS RÔLE, L’INSCRIPTION EST REFUSÉE', false === $refus['cree'] );
check( '1 · avec le motif exact', 'role_non_conforme' === $refus['motif'] );
check( '1 · AUCUN UTILISATEUR N’EST CRÉÉ',
	$avant_utilisateurs === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) ); // phpcs:ignore

// ======================================================================
// 2 · INSTALLATION EXPLICITE
// ======================================================================
check( '2 · l’installation réussit', '' === RoleClient::installer() );
check( '2 · le rôle est conforme', RoleClient::est_conforme() );

$role = get_role( RoleClient::ROLE );

check( '2 · UNE SEULE CAPACITÉ', array( 'read' ) === array_keys( array_filter( (array) $role->capabilities ) ) );
check( '2 · l’installation est idempotente', '' === RoleClient::installer() );

// Une capacité surnuméraire est retirée.
$role->add_cap( 'edit_posts' );

check( '2 · une capacité en trop rend non conforme', false === RoleClient::est_conforme() );
check( '2 · l’installation la retire', '' === RoleClient::installer() );
check( '2 · et le rôle redevient exact',
	array( 'read' ) === array_keys( array_filter( (array) get_role( RoleClient::ROLE )->capabilities ) ) );

// ======================================================================
// 3 · INSCRIPTION RÉELLE
// ======================================================================
$r = $inscr->inscrire( '  Claire@Banc-Urbizen.TEST ', $mdp );

check( '3 · le compte est créé', true === $r['cree'] );

$id = (int) $r['compte'];
$u  = get_userdata( $id );

check( '3 · L’ADRESSE EST NORMALISÉE', 'claire@banc-urbizen.test' === $u->user_email );
check( '3 · le rôle attribué est le bon', in_array( RoleClient::ROLE, (array) $u->roles, true ) );
check( '3 · ET PAS default_role', false === in_array( 'subscriber', (array) $u->roles, true ) );
check( '3 · l’identifiant est opaque', 1 === preg_match( '/^urb_[0-9a-z]{26}$/', $u->user_login ) );
check( '3 · LE COMPTE N’EST PAS VÉRIFIÉ', '' === (string) get_user_meta( $id, VerificationService::META_VERIFIE, true ) );
check( '3 · un jeton est préparé', null !== $r['emission'] && $r['emission']->est_prepare() );

// ======================================================================
// 4 · CONNEXION PAR ADRESSE — capacité native de WordPress
// ======================================================================
$signon = wp_authenticate( 'claire@banc-urbizen.test', $mdp );

check( '4 · WORDPRESS AUTHENTIFIE PAR ADRESSE',
	! is_wp_error( $signon ) && (int) $signon->ID === $id );

$mauvais = wp_authenticate( 'claire@banc-urbizen.test', 'mauvais-mot-de-passe' );

check( '4 · un mauvais mot de passe est refusé', is_wp_error( $mauvais ) );

// ======================================================================
// 5 · CONSOMMATION RÉELLE
// ======================================================================
$jeton = $r['emission']->jeton();

check( '5 · le jeton est consommé', '' === $verif->consommer( $id, $jeton ) );
check( '5 · LE COMPTE EST VÉRIFIÉ',
	'1' === (string) get_user_meta( $id, VerificationService::META_VERIFIE, true ) );
check( '5 · le condensat est supprimé', ! metadata_exists( 'user', $id, J::META_CONDENSAT ) );
check( '5 · une seconde utilisation échoue', 'jeton_absent' === $verif->consommer( $id, $jeton ) );

// ======================================================================
// 6 · PROFILE_UPDATE
// ======================================================================
// (a) Mise à jour SANS changement d'adresse : la vérification doit survivre.
wp_update_user( array( 'ID' => $id, 'first_name' => 'Claire' ) );

check( '6 · SANS CHANGEMENT D’ADRESSE, LA VÉRIFICATION SURVIT',
	'1' === (string) get_user_meta( $id, VerificationService::META_VERIFIE, true ) );

// (b) Promotion par le flux Urbizen : la garde doit empêcher l'invalidation.
update_user_meta( $id, VerificationService::META_EN_ATTENTE, 'claire2@banc-urbizen.test' );
$promue = $comptes->promouvoir_adresse( $id, 'claire2@banc-urbizen.test' );

check( '6 · la promotion réussit', $promue );
check( '6 · LA GARDE A EMPÊCHÉ L’INVALIDATION',
	'1' === (string) get_user_meta( $id, VerificationService::META_VERIFIE, true ) );
check( '6 · LA GARDE EST RETIRÉE APRÈS COUP', false === WpComptes::promotion_en_cours( $id ) );

// (c) Changement EXTERNE : la vérification doit tomber.
wp_update_user( array( 'ID' => $id, 'user_email' => 'externe@banc-urbizen.test' ) );

check( '6 · UN CHANGEMENT EXTERNE INVALIDE LA VÉRIFICATION',
	'' === (string) get_user_meta( $id, VerificationService::META_VERIFIE, true ) );
check( '6 · et efface le jeton en cours', ! metadata_exists( 'user', $id, J::META_CONDENSAT ) );
check( '6 · et l’adresse en attente', ! metadata_exists( 'user', $id, VerificationService::META_EN_ATTENTE ) );

// (d) La garde survit à une exception : identifiant absent → échec, garde nette.
$comptes->promouvoir_adresse( 999999, 'fantome@banc-urbizen.test' );

check( '6 · la garde est nette après un échec de promotion',
	false === WpComptes::promotion_en_cours( 999999 ) );

// ======================================================================
// 7 · CONSOMMATION CONCURRENTE   ← un seul processus doit réussir
// ======================================================================
$r7 = $inscr->inscrire( 'course@banc-urbizen.test', $mdp );
$id7 = (int) $r7['compte'];
$j7  = $r7['emission']->jeton();

$fils    = 5;
$dossier = sys_get_temp_dir() . '/urbizen-comptes-' . getmypid();
@mkdir( $dossier, 0700, true );

$depart  = microtime( true ) + 6.0;
$script  = __DIR__ . '/procs/consommer-jeton.php';
$attente = array();

for ( $i = 0; $i < $fils; $i++ ) {
	$fichier   = $dossier . '/c-' . $i . '.txt';
	$attente[] = $fichier;

	exec(
		sprintf(
			'%s %s %s %s %s %s > /dev/null 2>&1 &',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $script ),
			escapeshellarg( (string) $id7 ),
			escapeshellarg( $j7 ),
			escapeshellarg( $fichier ),
			escapeshellarg( (string) $depart )
		)
	);
}

$limite = microtime( true ) + 45.0;

do {
	usleep( 250000 );
	$ecrits = 0;

	foreach ( $attente as $f ) {
		if ( is_file( $f ) && '' !== trim( (string) @file_get_contents( $f ) ) ) {
			++$ecrits;
		}
	}
} while ( $ecrits < $fils && microtime( true ) < $limite );

$succes = 0;

foreach ( $attente as $f ) {
	if ( 'succes' === trim( (string) @file_get_contents( $f ) ) ) {
		++$succes;
	}

	@unlink( $f );
}

check( sprintf( '7 · les %d fils ont répondu', $fils ), $fils === $ecrits );
check( '7 · EXACTEMENT UN FILS CONSOMME LE JETON', 1 === $succes );

// Le parent a son propre cache de métadonnées : ce qu'un fils vient d'écrire
// en base n'y figure pas.
wp_cache_delete( $id7, 'user_meta' );

check( '7 · le compte est vérifié une fois',
	'1' === (string) get_user_meta( $id7, VerificationService::META_VERIFIE, true ) );

// ======================================================================
// 8 · ÉMISSIONS CONCURRENTES — aucune incrémentation perdue
// ======================================================================
$r8  = $inscr->inscrire( 'quota@banc-urbizen.test', $mdp );
$id8 = (int) $r8['compte'];

// L'inscription a préparé un jeton sans le confirmer : le quota est vide.
delete_user_meta( $id8, LimiteEnvois::META );

$depart2 = microtime( true ) + 6.0;
$att2    = array();

for ( $i = 0; $i < 2; $i++ ) {
	$fichier = $dossier . '/p-' . $i . '.txt';
	$att2[]  = $fichier;

	exec(
		sprintf(
			'%s %s %s %s %s > /dev/null 2>&1 &',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( __DIR__ . '/procs/preparer-jeton.php' ),
			escapeshellarg( (string) $id8 ),
			escapeshellarg( $fichier ),
			escapeshellarg( (string) $depart2 )
		)
	);
}

$limite = microtime( true ) + 45.0;

do {
	usleep( 250000 );
	$ecrits2 = 0;

	foreach ( $att2 as $f ) {
		if ( is_file( $f ) && '' !== trim( (string) @file_get_contents( $f ) ) ) {
			++$ecrits2;
		}
	}
} while ( $ecrits2 < 2 && microtime( true ) < $limite );

$prepares = 0;

foreach ( $att2 as $f ) {
	if ( 'prepare' === trim( (string) @file_get_contents( $f ) ) ) {
		++$prepares;
	}

	@unlink( $f );
}

@rmdir( $dossier );

wp_cache_delete( $id8, 'user_meta' );
$quota = LimiteEnvois::decoder( (string) get_user_meta( $id8, LimiteEnvois::META, true ) );

check( '8 · les deux fils ont répondu', 2 === $ecrits2 );
check( '8 · AUCUNE INCRÉMENTATION PERDUE', count( $quota['horodatages'] ) === $prepares );
check( '8 · le quota n’est pas corrompu', false === $quota['corrompue'] );
check( '8 · UN SEUL JETON ACTIF SUBSISTE',
	1 === ( metadata_exists( 'user', $id8, J::META_CONDENSAT ) ? 1 : 0 ) );

// ======================================================================
// 9 · UNE VISITE PUBLIQUE N'ÉCRIT AUCUN RÔLE
// ======================================================================
$avant_roles = (string) $wpdb->get_var( // phpcs:ignore
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' )
);

remove_role( RoleClient::ROLE );

$sans_role = (string) $wpdb->get_var( // phpcs:ignore
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' )
);

// On rejoue l'amorçage du greffon : il ne doit rien réinstaller.
do_action( 'init' );

$apres_init = (string) $wpdb->get_var( // phpcs:ignore
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' )
);

check( '9 · L’AMORÇAGE NE RECRÉE PAS LE RÔLE', $sans_role === $apres_init );
check( '9 · le rôle est bien absent après retrait', false === strpos( $apres_init, RoleClient::ROLE ) );

RoleClient::installer();

check( '9 · l’installation explicite le remet', RoleClient::est_conforme() );

// ======================================================================
// SORTIE
// ======================================================================
$restes = (int) $wpdb->get_var( // phpcs:ignore
	"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_compte_lock_%'"
);

check( 'sortie · aucun verrou de compte résiduel', 0 === $restes );

verdict();
