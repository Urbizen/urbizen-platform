<?php
/**
 * Amorce des bancs de comptes.
 *
 * Sans WordPress : le domaine n'a pas le droit d'en dépendre, et les services
 * passent par des ports dont les doublures suffisent. C'est ce qui rend
 * éprouvables les cas de course, les échecs partiels et les états corrompus —
 * précisément ce qu'une installation réelle rendrait pénible à provoquer.
 */

declare( strict_types = 1 );

define( 'URBIZEN_SRC', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/' );
define( 'URBIZEN_TEST_SECRET', 'secret-de-banc-e2' );

/*
 * Les fichiers de `src/Mail/` portent la garde `defined( 'ABSPATH' ) || exit;`
 * — celle qui empêche d'appeler un fichier d'extension directement par son URL.
 * Hors WordPress, elle termine le processus SANS RIEN DIRE, code de sortie 0 :
 * un banc muet passerait alors pour un banc vert.
 *
 * La doublure de `tests/submissions/` définit `ABSPATH` pour cette raison ; on
 * fait ici la même chose, et pour la même raison.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$urbizen_fichiers = array(
	'Domain/Identity/ActeurCourant.php',
	'Domain/Identity/CurrentUserProvider.php',
	'Domain/Authorization/Decision.php',
	'Domain/Authorization/ResourcePolicy.php',
	'Domain/Authorization/RefusParDefaut.php',
	'Domain/Authorization/PolicyRegistry.php',
	'Domain/Authorization/Authorization.php',
	'Domain/Support/Ulid.php',
	'Schema/DatabaseGateway.php',
	'Domain/Account/AdresseCourriel.php',
	'Domain/Account/Compte.php',
	'Domain/Account/DemandeVerification.php',
	'Domain/Account/ActionVerifiee.php',
	'Domain/Authorization/PolitiqueCompte.php',
	'Domain/Authorization/PolitiqueVerification.php',
	'Domain/Authorization/PolitiqueActionVerifiee.php',
	'Account/ComptesGateway.php',
	'Account/VerrouCompte.php',
	'Account/LimiteEnvois.php',
	'Account/JetonVerification.php',
	'Account/EmissionEnAttente.php',
	'Account/ResultatEmission.php',
	'Account/VerificationService.php',
	'Account/InscriptionService.php',
	'Account/RoleClient.php',
	'Account/AutorisationComptes.php',
	'Account/LienVerification.php',
	'Account/CourrielVerification.php',
	'Mail/MailTransport.php',
	'Account/EnvoiVerification.php',
);

foreach ( $urbizen_fichiers as $urbizen_fichier ) {
	$chemin = URBIZEN_SRC . $urbizen_fichier;

	if ( ! is_readable( $chemin ) ) {
		fwrite( STDERR, "fichier introuvable : $urbizen_fichier\n" );
		exit( 2 );
	}

	require_once $chemin;
}

require_once __DIR__ . '/logger-double.php';

$GLOBALS['urbizen_ok']     = 0;
$GLOBALS['urbizen_echecs'] = array();

/**
 * Contrôle unitaire.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @return void
 */
function check( string $libelle, bool $reussi ): void {
	if ( $reussi ) {
		++$GLOBALS['urbizen_ok'];
		printf( "%-78s OK\n", $libelle );
		return;
	}

	$GLOBALS['urbizen_echecs'][] = $libelle;
	printf( "%-78s ECHEC\n", $libelle );
}

/**
 * Verdict et code de sortie.
 *
 * @return void
 */
function verdict(): void {
	$echecs = count( $GLOBALS['urbizen_echecs'] );

	echo "\n";

	if ( 0 === $echecs ) {
		printf( "TOUS LES CONTROLES PASSENT (%d)\n", $GLOBALS['urbizen_ok'] );
		exit( 0 );
	}

	printf( "%d CONTROLE(S) EN ECHEC sur %d\n", $echecs, $GLOBALS['urbizen_ok'] + $echecs );

	foreach ( $GLOBALS['urbizen_echecs'] as $libelle ) {
		echo "  - $libelle\n";
	}

	exit( 1 );
}
