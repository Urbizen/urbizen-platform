<?php
/**
 * Banc : l'inscription concurrente, sur un WordPress réel.
 *
 * Ce que les doublures ne peuvent pas prouver : que deux processus réels qui
 * présentent la même adresse au même instant ne créent qu'UN compte. Le verrou
 * d'adresse repose sur `GET_LOCK()` — un verrou consultatif lié à la connexion,
 * sans échéance — et ce banc en éprouve les quatre propriétés qui comptent :
 *
 *   A · course normale       — dix processus, un seul compte ;
 *   B · propriétaire lent     — tant que P1 tient, P2 ne crée jamais ;
 *   C · mort du propriétaire  — le verrou se libère seul, sans orphelin ;
 *   D · unicité non prouvée   — un doublon historique fait échouer, sans créer.
 *
 * Il ne s'exécute que si `URBIZEN_WP_ROOT` désigne une installation jetable.
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

/**
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

// --------------------------------------------------------------------------
// Rendez-vous et lancement de processus fils.
// --------------------------------------------------------------------------
$rdv = sys_get_temp_dir() . '/urbizen-conc-' . getmypid();
@mkdir( $rdv, 0700, true );

/**
 * Lance un fils sous `procs/`, en arrière-plan.
 *
 * @param string                $script Nom du script.
 * @param array<string, string> $env    Variables.
 * @return void
 */
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

/**
 * @param string $nom      Fichier de rendez-vous.
 * @param float  $secondes Délai.
 * @return bool
 */
function attendre( string $nom, float $secondes = 20.0 ): bool {
	global $rdv;

	return urbizen_attendre( $rdv . '/' . $nom, $secondes );
}

/**
 * @param string $nom Fichier de rendez-vous.
 * @return string
 */
function lire( string $nom ): string {
	global $rdv;

	return is_readable( $rdv . '/' . $nom ) ? trim( (string) file_get_contents( $rdv . '/' . $nom ) ) : '';
}

/**
 * Combien d'utilisateurs réels portent cette adresse ? (cache purgé)
 *
 * @param string $email Adresse.
 * @return int
 */
function compter_users( string $email ): int {
	global $wpdb;

	wp_cache_flush();

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", $email )
	);
}

/**
 * Le verrou d'adresse est-il LIBRE ? (aucun orphelin)
 *
 * @param string $email Adresse.
 * @return bool
 */
function verrou_libre( string $email ): bool {
	global $wpdb;

	return '1' === (string) $wpdb->get_var(
		$wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', VerrouAdresse::nom_pour( $email ) )
	);
}

/**
 * Rôles réels d'un utilisateur portant cette adresse.
 *
 * @param string $email Adresse.
 * @return array<int, string>
 */
function roles_de( string $email ): array {
	wp_cache_flush();
	$u = get_user_by( 'email', $email );

	return $u instanceof WP_User ? array_values( $u->roles ) : array();
}

/**
 * Efface tout utilisateur portant cette adresse — isolation entre scénarios.
 *
 * @param string $email Adresse.
 * @return void
 */
function nettoyer_adresse( string $email ): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_cache_flush();

	$ids = (array) $wpdb->get_col(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s", $email )
	);

	foreach ( $ids as $id ) {
		wp_delete_user( (int) $id );
	}

	// Filet, si la suppression métier a laissé une ligne (doublon injecté brut).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE user_email = %s", $email ) );
	wp_cache_flush();
}

/**
 * Le VRAI service, câblé comme le contrôleur.
 *
 * @return InscriptionService
 */
function service_inscription(): InscriptionService {
	$comptes = new WpComptes();

	return new InscriptionService(
		$comptes,
		new VerificationService( $comptes, new WpdbGateway() ),
		new WpdbGateway()
	);
}

// --------------------------------------------------------------------------
// Préparation : rôle installé, adresses de test vierges.
// --------------------------------------------------------------------------
RoleClient::installer();
check( 'préparation · le rôle urbizen_client est conforme', ( new WpComptes() )->role_conforme() );

$mdp = 'MotDePasseValide123';
$a_a = 'conc_a@ex.test';
$a_b = 'conc_b@ex.test';
$a_c = 'conc_c@ex.test';
$a_d = 'conc_d@ex.test';

foreach ( array( $a_a, $a_b, $a_c, $a_d ) as $e ) {
	nettoyer_adresse( $e );
}

// ==========================================================================
// A · COURSE NORMALE — dix processus, une seule adresse
// ==========================================================================
$n = 10;

for ( $i = 1; $i <= $n; $i++ ) {
	lancer(
		'inscrire-adresse.php',
		array(
			'URBIZEN_ADRESSE' => $a_a,
			'URBIZEN_MDP'     => $mdp,
			'URBIZEN_RANG'    => (string) $i,
		)
	);
}

// Barrière : on attend que les dix soient prêts, puis on donne le top.
$tous_prets = true;

for ( $i = 1; $i <= $n; $i++ ) {
	$tous_prets = $tous_prets && attendre( 'pret-' . $i, 20.0 );
}

check( 'A · les dix processus sont prêts sur la barrière', $tous_prets );

urbizen_jalon( $rdv . '/go' );

$ids  = array();
$cree = 0;

for ( $i = 1; $i <= $n; $i++ ) {
	attendre( 'res-' . $i, 20.0 );
	$r = json_decode( lire( 'res-' . $i ), true );

	if ( is_array( $r ) ) {
		$ids[] = (int) ( $r['compte'] ?? 0 );

		if ( ! empty( $r['cree'] ) ) {
			++$cree;
		}
	}
}

$ids_uniques = array_values( array_unique( array_filter( $ids ) ) );

check( 'A · exactement UN compte est créé en base', 1 === compter_users( $a_a ) );
check( 'A · les dix résultats désignent le même identifiant', 1 === count( $ids_uniques ) );
check( 'A · cet identifiant est bien celui du compte en base',
	1 === count( $ids_uniques ) && (int) ( get_user_by( 'email', $a_a )->ID ?? -1 ) === $ids_uniques[0] );
check( 'A · un seul processus a créé, les autres ont retrouvé', 1 === $cree );
check( 'A · le compte ne porte QUE le rôle urbizen_client', array( 'urbizen_client' ) === roles_de( $a_a ) );
check( 'A · aucun subscriber', ! in_array( 'subscriber', roles_de( $a_a ), true ) );
check( 'A · aucun verrou d’adresse résiduel', verrou_libre( $a_a ) );

// ==========================================================================
// B · PROPRIÉTAIRE LENT — tant que P1 tient, P2 ne crée jamais
// ==========================================================================
lancer( 'tenir-verrou.php', array( 'URBIZEN_ADRESSE' => $a_b ) );

check( 'B · P1 acquiert et tient le verrou', attendre( 'tenu', 20.0 ) && 'acquis' === lire( 'tenu' ) );

// Pendant que P1 tient : une autre connexion ne peut PAS acquérir (attente 0),
// et donc rien n'est créé.
$sonde = VerrouAdresse::acquerir( new WpdbGateway(), $a_b, 0 );
check( 'B · une autre connexion est refusée pendant que P1 tient', null === $sonde );
check( 'B · rien n’est créé tant que P1 tient', 0 === compter_users( $a_b ) );

// P2 (le vrai service, attente bornée) démarre : il BLOQUE dans GET_LOCK.
lancer(
	'inscrire-adresse.php',
	array(
		'URBIZEN_ADRESSE' => $a_b,
		'URBIZEN_MDP'     => $mdp,
		'URBIZEN_RANG'    => 'b',
	)
);
urbizen_jalon( $rdv . '/go' ); // le go existe déjà, mais on le garantit
usleep( 800000 ); // laisse P2 atteindre GET_LOCK et s'y bloquer

check( 'B · P2 est bloqué : toujours aucun compte pendant que P1 tient',
	0 === compter_users( $a_b ) && ! is_readable( $rdv . '/res-b' ) );

// On autorise P1 à libérer. P2, débloqué, poursuit et crée l'unique compte.
urbizen_jalon( $rdv . '/liberer-verrou' );

check( 'B · P1 libère proprement', attendre( 'libere', 20.0 ) && '1' === lire( 'libere' ) );
check( 'B · P2 aboutit une fois le verrou rendu', attendre( 'res-b', 20.0 ) );
check( 'B · au total, exactement UN compte — jamais deux', 1 === compter_users( $a_b ) );
check( 'B · aucun verrou résiduel après la course', verrou_libre( $a_b ) );

// ==========================================================================
// C · MORT DU PROPRIÉTAIRE — le verrou se libère seul, sans orphelin
// ==========================================================================
lancer( 'tenir-puis-mourir.php', array( 'URBIZEN_ADRESSE' => $a_c ) );

check( 'C · le propriétaire acquiert le verrou puis meurt', attendre( 'tenu-mort', 20.0 ) && 'acquis' === lire( 'tenu-mort' ) );

// La connexion morte relâche le verrou : il redevient libre, sans échéance à
// attendre. On patiente au plus quelques centièmes que l'OS ferme la socket.
$libre = false;

for ( $essai = 0; $essai < 50 && ! $libre; $essai++ ) {
	$libre = verrou_libre( $a_c );
	usleep( 50000 );
}

check( 'C · le verrou est redevenu LIBRE après la mort — aucun orphelin', $libre );

// La demande suivante réussit normalement.
$rc = service_inscription()->inscrire( $a_c, $mdp, time() );
check( 'C · la demande suivante réussit', true === $rc['cree'] );
check( 'C · elle crée exactement un compte', 1 === compter_users( $a_c ) );
check( 'C · aucun verrou résiduel', verrou_libre( $a_c ) );

// ==========================================================================
// D · UNICITÉ NON PROUVÉE — un doublon historique fait échouer, sans créer
// ==========================================================================
// On injecte DEUX comptes portant la même adresse, à la main, comme le ferait
// un doublon antérieur au verrou. Le service doit refuser, ne rien préparer,
// ne créer aucun troisième compte, et n'en supprimer aucun.
wp_insert_user(
	array(
		'user_login' => 'urb_dup_1',
		'user_pass'  => $mdp,
		'user_email' => $a_d,
		'role'       => 'urbizen_client',
	)
);
// Second, en contournant la vérification d'unicité de WordPress (insertion brute).
$wpdb->insert(
	$wpdb->users,
	array(
		'user_login'      => 'urb_dup_2',
		'user_pass'       => wp_hash_password( $mdp ),
		'user_email'      => $a_d,
		'user_registered' => current_time( 'mysql', true ),
	)
);
wp_cache_flush();

check( 'D · deux comptes portent bien la même adresse (doublon historique)', 2 === compter_users( $a_d ) );

$rd = service_inscription()->inscrire( $a_d, $mdp, time() );

check( 'D · l’inscription échoue de façon restrictive', 'unicite_non_prouvee' === $rd['motif'] );
check( 'D · rien n’est tenu pour créé', false === $rd['cree'] );
check( 'D · aucun jeton n’est préparé', null === $rd['emission'] );
check( 'D · aucun TROISIÈME compte n’est créé', 2 === compter_users( $a_d ) );
check( 'D · aucun verrou résiduel', verrou_libre( $a_d ) );

// --------------------------------------------------------------------------
// Sortie : on rend les adresses de test, on efface le rendez-vous.
// --------------------------------------------------------------------------
foreach ( array( $a_a, $a_b, $a_c, $a_d ) as $e ) {
	nettoyer_adresse( $e );
}

array_map( 'unlink', (array) glob( $rdv . '/*' ) );
@rmdir( $rdv );

check( 'sortie · toutes les adresses de test sont rendues',
	0 === compter_users( $a_a ) + compter_users( $a_b ) + compter_users( $a_c ) + compter_users( $a_d ) );

verdict();
