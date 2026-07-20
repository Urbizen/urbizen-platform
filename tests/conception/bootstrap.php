<?php
/**
 * Amorce commune des bancs d'essai « conception ».
 *
 * Hors WordPress : les quelques fonctions employées par les définitions et par
 * le registre sont doublées ici. Aucun accès réseau, aucune base de données,
 * aucun fichier écrit.
 */

define( 'ABSPATH', __DIR__ );
define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );

if ( ! function_exists( '__' ) ) {
	function __( $texte, $domaine = '' ) { return $texte; }
	function esc_html__( $texte, $domaine = '' ) { return htmlspecialchars( $texte, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $texte ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $texte ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }
}

require_once URBIZEN_PLATFORM_DIR . 'src/Support/Logger.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/FormDefinition.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/FormRegistry.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/Pricing.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/Validator.php';
require_once URBIZEN_PLATFORM_DIR . 'src/Forms/Renderer.php';

/**
 * Compteur d'échecs, partagé par les bancs.
 *
 * @var int $GLOBALS['fail']
 */
$GLOBALS['fail'] = 0;

/**
 * Consigne le résultat d'un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @return void
 */
function check( string $libelle, bool $reussi ): void {
	if ( ! $reussi ) {
		++$GLOBALS['fail'];
	}

	printf( "%-84s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );
}

/**
 * Charge une définition depuis son fichier, sans passer par le registre.
 *
 * Permet aux bancs de mutation d'altérer un tableau brut puis de le confier à
 * FormDefinition, sans jamais toucher au fichier du dépôt.
 *
 * @param string $type Identifiant.
 * @return array<string, mixed>
 */
function brut( string $type ): array {
	return require URBIZEN_PLATFORM_DIR . 'src/Forms/definitions/' . $type . '.php';
}

/**
 * Construit une FormDefinition à partir d'un tableau brut.
 *
 * @param array<string, mixed> $raw Tableau de définition.
 * @return \Urbizen\Platform\Forms\FormDefinition
 */
function definition( array $raw ): \Urbizen\Platform\Forms\FormDefinition {
	return new \Urbizen\Platform\Forms\FormDefinition(
		(string) ( $raw['type'] ?? '' ),
		(string) ( $raw['title'] ?? '' ),
		(string) ( $raw['submit_label'] ?? '' ),
		$raw['fields'] ?? array(),
		$raw['steps'] ?? array()
	);
}

/**
 * Verdict final d'un banc.
 *
 * @return void
 */
function verdict(): void {
	echo "\n";
	echo 0 === $GLOBALS['fail']
		? "TOUS LES CONTROLES PASSENT\n"
		: $GLOBALS['fail'] . " CONTROLE(S) EN ECHEC\n";
	exit( 0 === $GLOBALS['fail'] ? 0 : 1 );
}
