<?php
/**
 * Banc : mot de passe et adresse, éprouvés contre un vrai WordPress.
 *
 * Ce que les doublures ne peuvent pas prouver :
 *
 *   la mesure du mot de passe en CARACTÈRES tient face au vrai hachage — un mot
 *   de passe de douze points de code, accents ou émojis compris, ouvre bien une
 *   session avec sa valeur EXACTE, et une valeur altérée est refusée ;
 *   l'adresse de cent caractères est relue à l'identique en base, sans
 *   troncature, et cent-un est refusée ;
 *   le mot de passe n'apparaît JAMAIS dans les journaux.
 *
 * Il ne s'exécute que si `URBIZEN_WP_ROOT` désigne une installation jetable.
 */

declare( strict_types = 1 );

require __DIR__ . '/amorce-reelle.php';
require_once __DIR__ . '/amorce-outils.php';

use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\RoleClient;
use Urbizen\Platform\Account\VerificationService;
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

$comptes = new WpComptes();
$service = static function () use ( $comptes ): InscriptionService {
	return new InscriptionService(
		$comptes,
		new VerificationService( $comptes, new WpdbGateway() ),
		new WpdbGateway()
	);
};

RoleClient::installer();

$n = 0;
$adresse_test = static function () use ( &$n ): string {
	return sprintf( 'mdp%d_%s@ex.test', ++$n, substr( md5( (string) mt_rand() ), 0, 6 ) );
};

/**
 * Efface un utilisateur par adresse.
 */
function effacer( string $email ): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_cache_flush();

	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s", $email ) ) as $id ) {
		wp_delete_user( (int) $id );
	}
	wp_cache_flush();
}

// ==========================================================================
// 1 · MOTS DE PASSE ACCEPTÉS — authentification réelle à la valeur EXACTE
// ==========================================================================
$acceptes = array(
	'12 ASCII avec symboles'        => 'P@ssw0rd!123',
	'12 caractères accentués'       => 'garçon123456',
	'12 émojis (48 octets)'         => '🎈🎈🎈🎈🎈🎈🎈🎈🎈🎈🎈🎈',
	'apostrophe et antislash'       => "a'b\\cdef12345",
);

foreach ( $acceptes as $libelle => $mdp ) {
	$email = $adresse_test();
	$r     = $service()->inscrire( $email, $mdp, time() );

	check( "1 · « $libelle » : le compte est créé", true === $r['cree'] );

	$u = get_user_by( 'email', $email );

	check( "1 · « $libelle » : authentification réelle au mot de passe EXACT",
		$u instanceof WP_User && wp_authenticate( $u->user_login, $mdp ) instanceof WP_User );
	check( "1 · « $libelle » : une valeur altérée est refusée",
		$u instanceof WP_User && is_wp_error( wp_authenticate( $u->user_login, $mdp . 'x' ) ) );

	effacer( $email );
}

// ==========================================================================
// 2 · MOTS DE PASSE REFUSÉS — trop courts, ou UTF-8 invalide
// ==========================================================================
$refus = array(
	'11 caractères ASCII'                 => 'abcdEF12345',
	'« garçon12345 » : 11 car., 12 octets' => 'garçon12345',
	'UTF-8 invalide'                      => "abc\xC3\x28defghij",
);

foreach ( $refus as $libelle => $mdp ) {
	$email = $adresse_test();
	$r     = $service()->inscrire( $email, $mdp, time() );

	check( "2 · « $libelle » : refusé", false === $r['cree'] );
	check( "2 · « $libelle » : aucun compte n’est créé", null === get_user_by( 'email', $email ) || false === get_user_by( 'email', $email ) );

	effacer( $email );
}

// ==========================================================================
// 3 · ADRESSE — cent caractères relus à l'identique, cent-un refusée
// ==========================================================================
$a100 = str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 31 ) . '.com'; // 100
$a101 = str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 32 ) . '.com'; // 101

check( '3 · l’adresse d’essai fait bien 100 caractères', 100 === strlen( $a100 ) );

$r100 = $service()->inscrire( $a100, 'MotDePasse12chars', time() );
check( '3 · l’inscription à 100 caractères est acceptée', true === $r100['cree'] );

$stocke = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE ID = %d", (int) $r100['compte'] ) );
check( '3 · l’adresse est relue à l’identique, sans troncature', $stocke === $a100 );
check( '3 · sa longueur stockée est bien 100', 100 === strlen( $stocke ) );
effacer( $a100 );

$r101 = $service()->inscrire( $a101, 'MotDePasse12chars', time() );
check( '3 · l’adresse de 101 caractères est REFUSÉE', false === $r101['cree'] );
effacer( $a101 );

// ==========================================================================
// 4 · AUCUN MOT DE PASSE DANS LES JOURNAUX
// ==========================================================================
// On détourne le journal d'erreurs PHP vers un fichier, on inscrit avec un mot
// de passe porteur d'un jeton unique, puis on vérifie que ce jeton n'apparaît
// nulle part. Le greffon journalise des codes et des identifiants, jamais un
// secret.
$journal = tempnam( sys_get_temp_dir(), 'urbz-log-' );
$ancien  = (string) ini_get( 'error_log' );
ini_set( 'error_log', $journal );

$jeton  = 'SECRET' . substr( md5( (string) mt_rand() ), 0, 8 ) . 'zz';
$email4 = $adresse_test();
$service()->inscrire( $email4, $jeton, time() );
// Et un échec qui journalise (adresse déjà prise non vérifiée), pour couvrir un
// chemin qui écrit dans le journal.
$service()->inscrire( $email4, $jeton, time() );

ini_set( 'error_log', $ancien );

$contenu = is_readable( $journal ) ? (string) file_get_contents( $journal ) : '';
check( '4 · le mot de passe n’apparaît pas dans le journal', '' === $jeton || false === strpos( $contenu, $jeton ) );
@unlink( $journal );
effacer( $email4 );

verdict();
