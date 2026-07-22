<?php
/**
 * Amorce des bancs du domaine.
 *
 * Volontairement **minimale et sans WordPress** : ces bancs éprouvent du code
 * qui n'a pas le droit d'en dépendre. Si l'un d'eux réclamait un jour une
 * fonction WordPress pour passer, ce serait la preuve que la frontière a été
 * franchie — et le banc de frontière le dirait au même moment.
 *
 * Les classes sont chargées à la main, sans l'autoloader du greffon : celui-ci
 * exige `URBIZEN_PLATFORM_DIR` et un contexte WordPress.
 */

declare( strict_types = 1 );

define( 'URBIZEN_SRC', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/' );

$urbizen_domaine = array(
	'Domain/Identity/ActeurCourant.php',
	'Domain/Identity/CurrentUserProvider.php',
	'Domain/Authorization/Decision.php',
	'Domain/Authorization/ResourcePolicy.php',
	'Domain/Authorization/RefusParDefaut.php',
	'Domain/Authorization/PolicyRegistry.php',
	'Domain/Authorization/Authorization.php',
	'Domain/Support/Ulid.php',
	'Schema/DatabaseGateway.php',
	'Schema/Migration.php',
	'Schema/MigrationCatalogue.php',
	'Schema/ResultatMigration.php',
	'Schema/MigrationLock.php',
	'Schema/SchemaGuard.php',
	'Schema/MigrationRunner.php',
);

foreach ( $urbizen_domaine as $urbizen_fichier ) {
	$urbizen_chemin = URBIZEN_SRC . $urbizen_fichier;

	if ( ! is_readable( $urbizen_chemin ) ) {
		fwrite( STDERR, "fichier introuvable : $urbizen_fichier\n" );
		exit( 2 );
	}

	require_once $urbizen_chemin;
}

/*
 * Doublures minimales des trois primitives d'options.
 *
 * `Schema/` a le droit d'appeler WordPress — ce n'est pas le domaine, c'est
 * l'infrastructure. Ces trois doublures suffisent à éprouver la logique
 * d'expiration et de propriété du verrou sans base de données ; la concurrence
 * réelle, elle, est éprouvée par le banc d'intégration avec deux processus.
 *
 * `add_option()` est **fidèle sur le seul point qui compte** : elle échoue si
 * la clé existe déjà. C'est cette atomicité qui fait le verrou.
 */
$GLOBALS['urbizen_options'] = array();

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $cle, $valeur = '', $deprecie = '', $autoload = 'yes' ) {
		unset( $deprecie, $autoload );

		if ( array_key_exists( $cle, $GLOBALS['urbizen_options'] ) ) {
			return false;
		}

		$GLOBALS['urbizen_options'][ $cle ] = $valeur;

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $cle, $defaut = false ) {
		return array_key_exists( $cle, $GLOBALS['urbizen_options'] )
			? $GLOBALS['urbizen_options'][ $cle ]
			: $defaut;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $cle ) {
		if ( ! array_key_exists( $cle, $GLOBALS['urbizen_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['urbizen_options'][ $cle ] );

		return true;
	}
}

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
 * Rend le verdict et le code de sortie.
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
