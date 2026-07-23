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
// 2 · INSTALLATION EXPLICITE, ET RÉCONCILIATION EN PLACE
// ======================================================================
// (a) Rôle absent.
check( '2 · l’installation réussit', '' === RoleClient::installer() );
check( '2 · le rôle est conforme', RoleClient::est_conforme() );

$role = get_role( RoleClient::ROLE );

check( '2 · UNE SEULE CAPACITÉ', array( 'read' ) === array_keys( array_filter( (array) $role->capabilities ) ) );

// (b) Rôle déjà conforme : rien ne doit bouger, ni en mémoire ni en base.
$avant_conforme = (string) $wpdb->get_var( // phpcs:ignore
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' )
);

check( '2 · l’installation est idempotente', '' === RoleClient::installer() );
check( '2 · ET N’ÉCRIT RIEN',
	$avant_conforme === (string) $wpdb->get_var( // phpcs:ignore
		$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' )
	) );

// (c) Rôle privé de `read`.
get_role( RoleClient::ROLE )->remove_cap( 'read' );

check( '2 · sans read, le rôle est non conforme', false === RoleClient::est_conforme() );
check( '2 · L’INSTALLATION REPOSE read', '' === RoleClient::installer() );
check( '2 · et le rôle est exact',
	array( 'read' ) === array_keys( array_filter( (array) get_role( RoleClient::ROLE )->capabilities ) ) );

// (d) Capacité surnuméraire, ET un utilisateur qui porte déjà le rôle.
$porteur = wp_insert_user(
	array(
		'user_login' => 'urb_porteur_banc',
		'user_email' => 'porteur@banc-urbizen.test',
		'user_pass'  => $mdp,
		'role'       => RoleClient::ROLE,
	)
);

$porteur = (int) $porteur;

check( '2 · un utilisateur porte le rôle', $porteur > 0 );

get_role( RoleClient::ROLE )->add_cap( 'edit_posts' );

check( '2 · une capacité en trop rend non conforme', false === RoleClient::est_conforme() );

// Photographie de l'identifiant du rôle : s'il était retiré puis recréé, les
// utilisateurs le perdraient. C'est exactement ce qu'on interdit.
check( '2 · L’INSTALLATION CORRIGE EN PLACE', '' === RoleClient::installer() );
check( '2 · et le rôle redevient exact',
	array( 'read' ) === array_keys( array_filter( (array) get_role( RoleClient::ROLE )->capabilities ) ) );

wp_cache_delete( $porteur, 'users' );
wp_cache_delete( $porteur, 'user_meta' );
clean_user_cache( $porteur );

check( '2 · L’UTILISATEUR CONSERVE SON RÔLE APRÈS CORRECTION',
	in_array( RoleClient::ROLE, (array) get_userdata( $porteur )->roles, true ) );

// ----------------------------------------------------------------------
// (d bis) LE RÔLE N'EST ABSENT D'AUCUNE ÉCRITURE INTERMÉDIAIRE.
//
// C'est la forme exacte de l'exigence. `remove_role()` ne vide pas le
// `wp_capabilities` des utilisateurs — la métadonnée nomme toujours le rôle, et
// il leur revient s'il est recréé. Le danger n'est donc pas la dépossession
// définitive, c'est la FENÊTRE : entre le retrait et la repose, aucun objet de
// rôle ne répond, `read` est refusée à tout client, et une mort du processus à
// cet instant laisse l'installation sans rôle. On observe donc chaque écriture
// de l'option, et le rôle doit y figurer à chaque fois.
// ----------------------------------------------------------------------
get_role( RoleClient::ROLE )->add_cap( 'edit_posts' );

$photos    = array();
$observer  = static function ( $ancienne, $nouvelle ) use ( &$photos, $porteur ) {
	clean_user_cache( $porteur );

	$photos[] = array(
		'role_present' => isset( ( (array) $nouvelle )[ RoleClient::ROLE ] ),
		'porteur_read' => user_can( $porteur, 'read' ),
	);
};

add_action( 'update_option_' . $wpdb->prefix . 'user_roles', $observer, 10, 2 );
RoleClient::installer();
remove_action( 'update_option_' . $wpdb->prefix . 'user_roles', $observer, 10 );

$sans_role_ecrit = 0;
$sans_read       = 0;

foreach ( $photos as $photo ) {
	if ( ! $photo['role_present'] ) {
		++$sans_role_ecrit;
	}

	if ( ! $photo['porteur_read'] ) {
		++$sans_read;
	}
}

check( '2 · la correction a bien écrit l’option', count( $photos ) >= 1 );
check( '2 · LE RÔLE FIGURE DANS CHAQUE ÉCRITURE INTERMÉDIAIRE', 0 === $sans_role_ecrit );
check( '2 · ET LE PORTEUR N’EST JAMAIS PRIVÉ DE read', 0 === $sans_read );

clean_user_cache( $porteur );

// (e) Une capacité surnuméraire posée à `false` compte aussi : elle traîne dans
// l'option, et la laisser rendrait la correction non idempotente.
get_role( RoleClient::ROLE )->add_cap( 'manage_options', false );

check( '2 · une capacité inactive en trop rend non conforme', false === RoleClient::est_conforme() );
check( '2 · l’installation la retire aussi', '' === RoleClient::installer() );
check( '2 · plus aucune clé surnuméraire',
	array( 'read' ) === array_keys( (array) get_role( RoleClient::ROLE )->capabilities ) );

// (f) Échec simulé : l'option des rôles est rendue non écrivable le temps d'une
// correction. `installer()` doit le DIRE, pas l'affirmer corrigé.
get_role( RoleClient::ROLE )->add_cap( 'edit_posts' );

$bloquer = static function ( $valeur, $ancienne ) {
	return $ancienne;
};

add_filter( 'pre_update_option_' . $wpdb->prefix . 'user_roles', $bloquer, 10, 2 );

$motif_echec = RoleClient::installer();

remove_filter( 'pre_update_option_' . $wpdb->prefix . 'user_roles', $bloquer, 10 );

check( '2 · UN ÉCHEC D’ÉCRITURE EST SIGNALÉ, PAS MASQUÉ', 'etat_non_persiste' === $motif_echec );
check( '2 · LE RÔLE N’A PAS ÉTÉ SUPPRIMÉ POUR AUTANT', null !== get_role( RoleClient::ROLE ) );

wp_cache_delete( $porteur, 'users' );
clean_user_cache( $porteur );

check( '2 · et le porteur le garde malgré l’échec',
	in_array( RoleClient::ROLE, (array) get_userdata( $porteur )->roles, true ) );

// (g) Relance après échec : la correction doit aboutir.
$GLOBALS['wp_roles'] = null; // Force la relecture de l'option réelle.
wp_roles();

check( '2 · APRÈS ÉCHEC, LA RELANCE CORRIGE', '' === RoleClient::installer() );
check( '2 · le rôle est de nouveau exact',
	array( 'read' ) === array_keys( (array) get_role( RoleClient::ROLE )->capabilities ) );

wp_cache_delete( $porteur, 'users' );
clean_user_cache( $porteur );

check( '2 · et le porteur l’a conservé de bout en bout',
	in_array( RoleClient::ROLE, (array) get_userdata( $porteur )->roles, true ) );

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

// ----------------------------------------------------------------------
// (e) DEUX COMPTES DISTINCTS DANS LA MÊME REQUÊTE.
//
// Une garde globale ferait taire l'invalidation de B pendant qu'on promeut A.
// C'est le cas qu'un crochet tiers accroché à `profile_update` provoque sans y
// penser : promouvoir A déclenche du code qui touche B.
// ----------------------------------------------------------------------
$ra = $inscr->inscrire( 'gardea@banc-urbizen.test', $mdp );
$rb = $inscr->inscrire( 'gardeb@banc-urbizen.test', $mdp );

$ida = (int) $ra['compte'];
$idb = (int) $rb['compte'];

update_user_meta( $ida, VerificationService::META_VERIFIE, '1' );
update_user_meta( $idb, VerificationService::META_VERIFIE, '1' );
update_user_meta( $ida, VerificationService::META_EN_ATTENTE, 'gardea2@banc-urbizen.test' );

$croise = static function ( $id ) use ( $ida, $idb ) {
	static $fait = false;

	if ( $fait || (int) $id !== $ida ) {
		return;
	}

	$fait = true;

	// Changement EXTERNE sur B, pendant la promotion de A.
	wp_update_user( array( 'ID' => $idb, 'user_email' => 'gardeb2@banc-urbizen.test' ) );
};

add_action( 'profile_update', $croise, 20, 1 );
$comptes->promouvoir_adresse( $ida, 'gardea2@banc-urbizen.test' );
remove_action( 'profile_update', $croise, 20 );

wp_cache_delete( $ida, 'user_meta' );
wp_cache_delete( $idb, 'user_meta' );

check( '6 · A, PROMU PAR NOUS, RESTE VÉRIFIÉ',
	'1' === (string) get_user_meta( $ida, VerificationService::META_VERIFIE, true ) );
check( '6 · B, CHANGÉ DE L’EXTÉRIEUR, EST INVALIDÉ',
	'' === (string) get_user_meta( $idb, VerificationService::META_VERIFIE, true ) );
check( '6 · aucune garde ne subsiste',
	false === WpComptes::promotion_en_cours( $ida )
	&& false === WpComptes::promotion_en_cours( $idb ) );

// ----------------------------------------------------------------------
// (f) IMBRICATION SUR LE MÊME COMPTE.
//
// Un simple booléen ne survivrait pas : la promotion interne, en se retirant,
// désarmerait celle qui l'englobe. On observe la garde APRÈS le retour de
// l'interne, alors que l'externe court encore.
// ----------------------------------------------------------------------
$rc = $inscr->inscrire( 'gardec@banc-urbizen.test', $mdp );
$idc = (int) $rc['compte'];

update_user_meta( $idc, VerificationService::META_VERIFIE, '1' );

$garde_apres_interne = null;
$profondeur_interne  = 0;

$imbrique = static function ( $id ) use ( $idc, $comptes, &$garde_apres_interne, &$profondeur_interne ) {
	static $fait = false;

	if ( $fait || (int) $id !== $idc ) {
		return;
	}

	$fait = true;

	$profondeur_interne = WpComptes::profondeur_promotion( $idc );

	// Promotion imbriquée sur LE MÊME compte.
	$comptes->promouvoir_adresse( $idc, 'gardec3@banc-urbizen.test' );

	// L'externe court toujours : la garde doit tenir.
	$garde_apres_interne = WpComptes::promotion_en_cours( $idc );
};

add_action( 'profile_update', $imbrique, 20, 1 );
$comptes->promouvoir_adresse( $idc, 'gardec2@banc-urbizen.test' );
remove_action( 'profile_update', $imbrique, 20 );

wp_cache_delete( $idc, 'user_meta' );

check( '6 · l’imbrication a bien eu lieu', 1 === $profondeur_interne );
check( '6 · LA GARDE TIENT APRÈS LE RETOUR DE L’INTERNE', true === $garde_apres_interne );
check( '6 · LA VÉRIFICATION SURVIT À L’IMBRICATION',
	'1' === (string) get_user_meta( $idc, VerificationService::META_VERIFIE, true ) );
check( '6 · et la garde est entièrement levée à la fin',
	0 === WpComptes::profondeur_promotion( $idc ) );

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

// L'inscription a préparé un jeton sans le confirmer. On repart d'un compte
// net : sans effacer l'émission qu'elle a posée, les deux fils seraient écartés
// par elle, et la course qu'on veut observer n'aurait pas lieu.
delete_user_meta( $id8, LimiteEnvois::META );
delete_user_meta( $id8, \Urbizen\Platform\Account\EmissionEnAttente::META );

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
$refus8   = array();

foreach ( $att2 as $f ) {
	$brut = trim( (string) @file_get_contents( $f ) );

	if ( 0 === strpos( $brut, 'prepare:' ) ) {
		++$prepares;
	} else {
		$refus8[] = $brut;
	}

	@unlink( $f );
}

wp_cache_delete( $id8, 'user_meta' );
$quota = LimiteEnvois::decoder( (string) get_user_meta( $id8, LimiteEnvois::META, true ) );

printf( "    (fils 8 : %s)\n", implode( ' | ', $refus8 ) );

check( '8 · les deux fils ont répondu', 2 === $ecrits2 );
check( '8 · EXACTEMENT UN FILS PRÉPARE ET CONFIRME', 1 === $prepares );
// Les deux fils partent au même instant : le second peut être écarté par le
// verrou aussi bien que par l'émission en vol ou le délai. Les trois sont des
// refus légitimes ; ce qui compte, c'est qu'il n'ait rien préparé.
check( '8 · l’autre est refusé, et il le dit',
	1 === count( $refus8 ) && 0 === strpos( (string) $refus8[0], 'refuse:' ) );
check( '8 · AUCUNE INCRÉMENTATION PERDUE', count( $quota['horodatages'] ) === $prepares );
check( '8 · le quota n’est pas corrompu', false === $quota['corrompue'] );
check( '8 · UN SEUL JETON ACTIF SUBSISTE',
	1 === ( metadata_exists( 'user', $id8, J::META_CONDENSAT ) ? 1 : 0 ) );

// ======================================================================
// 8 bis · UNE ÉMISSION EN VOL FERME LE COMPTE   ← correctif du protocole
// ======================================================================
// Deux fils préparent. Le premier N'a PAS confirmé — il est censé être en train
// d'envoyer son courriel. Le second ne doit repartir sous aucun prétexte : sans
// cela, deux courriels partent et le premier lien est déjà mort.
$r9  = $inscr->inscrire( 'envol@banc-urbizen.test', $mdp );
$id9 = (int) $r9['compte'];

// L'inscription a déjà posé une émission : on repart d'un compte net.
delete_user_meta( $id9, LimiteEnvois::META );
delete_user_meta( $id9, \Urbizen\Platform\Account\EmissionEnAttente::META );

/*
 * Les fils sont ÉCHELONNÉS, à dessein. Un départ simultané ferait écarter les
 * suivants par le verrou — un refus légitime, mais qui ne prouverait rien de
 * l'émission en vol, puisque le verrou n'est tenu que le temps de la
 * préparation. En les espaçant, le verrou est libre depuis longtemps quand ils
 * arrivent : leur refus ne peut plus venir que de l'état qu'on vient d'ajouter.
 */
$depart3 = microtime( true ) + 6.0;
$att3    = array();

foreach ( array( 0.0, 1.5, 2.5, 3.5 ) as $i => $decalage ) {
	$fichier = $dossier . '/v-' . $i . '.txt';
	$att3[]  = $fichier;

	exec(
		sprintf(
			'%s %s %s %s %s attendre > /dev/null 2>&1 &',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( __DIR__ . '/procs/preparer-jeton.php' ),
			escapeshellarg( (string) $id9 ),
			escapeshellarg( $fichier ),
			escapeshellarg( (string) ( $depart3 + $decalage ) )
		)
	);
}

$limite = microtime( true ) + 45.0;

do {
	usleep( 250000 );
	$ecrits3 = 0;

	foreach ( $att3 as $f ) {
		if ( is_file( $f ) && '' !== trim( (string) @file_get_contents( $f ) ) ) {
			++$ecrits3;
		}
	}
} while ( $ecrits3 < 4 && microtime( true ) < $limite );

$prepares3 = 0;
$attentes3 = 0;
$emissions = array();
$bruts3    = array();

foreach ( $att3 as $f ) {
	$brut     = trim( (string) @file_get_contents( $f ) );
	$bruts3[] = 0 === strpos( $brut, 'prepare:' ) ? 'prepare' : $brut;

	if ( 0 === strpos( $brut, 'prepare:' ) ) {
		++$prepares3;
		$emissions[] = substr( $brut, 8 );
	} elseif ( 'refuse:emission_en_attente' === $brut ) {
		++$attentes3;
	}

	@unlink( $f );
}

@rmdir( $dossier );

wp_cache_delete( $id9, 'user_meta' );

printf( "    (fils 8 bis : %s)\n", implode( ' | ', $bruts3 ) );

check( '8 bis · les quatre fils ont répondu', 4 === $ecrits3 );
check( '8 bis · UN SEUL PRÉPARE', 1 === $prepares3 );
check( '8 bis · LES TROIS AUTRES SONT REFUSÉS POUR ÉMISSION EN VOL', 3 === $attentes3 );
check( '8 bis · aucun quota consommé : rien n’a été confirmé',
	! metadata_exists( 'user', $id9, LimiteEnvois::META ) );
check( '8 bis · une seule émission est posée',
	metadata_exists( 'user', $id9, \Urbizen\Platform\Account\EmissionEnAttente::META ) );

$posee = \Urbizen\Platform\Account\EmissionEnAttente::decoder(
	(string) get_user_meta( $id9, \Urbizen\Platform\Account\EmissionEnAttente::META, true )
);

check( '8 bis · c’est bien celle du fils qui a réussi',
	null !== $posee && ( $emissions[0] ?? '' ) === $posee['id'] );
check( '8 bis · UN SEUL JETON ACTIF', metadata_exists( 'user', $id9, J::META_CONDENSAT ) );
check( '8 bis · un identifiant d’émission inventé ne clôt rien',
	false === $verif->confirmer_emission( $id9, 'AAAAAAAAAAAAAAAAAAAAAAAAAA' ) );

wp_cache_delete( $id9, 'user_meta' );

check( '8 bis · le titulaire, lui, confirme',
	$verif->confirmer_emission( $id9, (string) ( $emissions[0] ?? '' ) ) );

wp_cache_delete( $id9, 'user_meta' );

check( '8 bis · et le quota porte alors exactement un créneau',
	1 === count( LimiteEnvois::decoder( (string) get_user_meta( $id9, LimiteEnvois::META, true ) )['horodatages'] ) );

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
