<?php
/**
 * Amorce commune des bancs d'essai « forms » (socle multi-formulaire).
 *
 * Hors WordPress : les rares fonctions employées par le registre et les
 * définitions sont doublées ici. Aucun accès réseau, aucune base de données,
 * aucun fichier du dépôt écrit. Les journaux (Logger → error_log) sont détournés
 * vers un fichier temporaire pour ne pas polluer la sortie du banc.
 */

define( 'ABSPATH', __DIR__ );
define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );
define( 'URBIZEN_TESTING', true ); // autorise FormRegistry::reset(), inopérant en production.

ini_set( 'log_errors', '1' );
ini_set( 'error_log', sys_get_temp_dir() . '/urbizen-forms-test.log' );

if ( ! function_exists( '__' ) ) {
	function __( $texte, $domaine = '' ) { return $texte; }
	function esc_html__( $texte, $domaine = '' ) { return htmlspecialchars( $texte, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $texte ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $texte ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }
}

require_once URBIZEN_PLATFORM_DIR . 'src/Support/Logger.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/FormDefinition.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/FormRegistry.php';
// Les définitions demandent à AdresseTerrain les noms canoniques de chaque rôle
// d'adresse : sans autoloader, ce banc doit le charger lui-même.
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/AdresseTerrain.php';

/**
 * Compteur d'échecs partagé.
 *
 * @var int $GLOBALS['fail']
 */
$GLOBALS['fail'] = 0;

/**
 * Consigne le résultat d'un contrôle.
 */
function check( string $libelle, bool $reussi ): void {
	if ( ! $reussi ) {
		++$GLOBALS['fail'];
	}

	printf( "%-84s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );
}

/**
 * Verdict final d'un banc.
 */
function verdict(): void {
	echo "\n";
	echo 0 === $GLOBALS['fail']
		? "TOUS LES CONTROLES PASSENT\n"
		: $GLOBALS['fail'] . " CONTROLE(S) EN ECHEC\n";
	exit( 0 === $GLOBALS['fail'] ? 0 : 1 );
}
