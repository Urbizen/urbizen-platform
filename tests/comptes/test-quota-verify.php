<?php
/**
 * Banc : la commande `wp urbizen accounts quota-verify`, EXÉCUTÉE.
 *
 * Une recherche lexicale constate une forme ; elle ne dit rien de ce qui se
 * passe quand l'écriture est refusée ou le verrou pris. Ce banc appelle la
 * commande pour de vrai, contre des doublures de WordPress.
 *
 * Le point qui porte tout : **la source n'est jamais modifiée**. La commande
 * constate, et ne répare que le miroir — la valeur dérivée, celle qui n'est
 * jamais lue pour décider.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp-double.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\LimiteEnvois;

$GLOBALS['banc_db'] = new PasserelleOptions();

$t = 1785000000;

// ----------------------------------------------------------------------
// Doublure de WP_CLI et des métadonnées utilisateur
// ----------------------------------------------------------------------

/**
 * État observable de la commande.
 */
final class CliDouble {

	/** @var array<int, string> */
	public static array $log = array();

	/** @var array<int, string> */
	public static array $warnings = array();

	/** @var array<int, string> */
	public static array $succes = array();

	/** @var array<int, string> */
	public static array $erreurs = array();

	/** @var array<int, array<string, string>> */
	public static array $metas = array();

	/** @var array<int, string> */
	public static array $ecritures_refusees = array();

	public static bool $verrou_indisponible = false;

	public static function reset(): void {
		self::$log                 = array();
		self::$warnings            = array();
		self::$succes              = array();
		self::$erreurs             = array();
		self::$metas               = array();
		self::$ecritures_refusees  = array();
		self::$verrou_indisponible = false;
	}
}

CliDouble::reset();

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Doublure de WP_CLI. `error()` lève : en production elle termine le
	 * processus, et un banc qui poursuivrait n'éprouverait pas la même chose.
	 */
	class WP_CLI {

		public static function add_command( string $nom, $classe ): void {
		}

		public static function log( string $m ): void {
			CliDouble::$log[] = $m;
		}

		public static function warning( string $m ): void {
			CliDouble::$warnings[] = $m;
		}

		public static function success( string $m ): void {
			CliDouble::$succes[] = $m;
		}

		public static function error( string $m ): void {
			CliDouble::$erreurs[] = $m;

			throw new SortieHttp( 'cli_error' );
		}
	}
}

function get_users( array $args = array() ): array {
	return array_keys( CliDouble::$metas );
}

function get_user_meta( int $id, string $cle, bool $unique = false ) {
	return CliDouble::$metas[ $id ][ $cle ] ?? '';
}

function update_user_meta( int $id, string $cle, $valeur ) {
	if ( in_array( $cle, CliDouble::$ecritures_refusees, true ) ) {
		return false;
	}

	CliDouble::$metas[ $id ][ $cle ] = (string) $valeur;

	return true;
}

require URBIZEN_SRC . 'Adapter/WpCliAccountsCommand.php';

use Urbizen\Platform\Adapter\WpCliAccountsCommand;

/**
 * Exécute la commande et rend vrai si elle s'est terminée en erreur.
 *
 * @param bool $reparer Passer `--repair-mirror`.
 * @return bool
 */
function lancer( bool $reparer ): bool {
	// La passerelle du verrou est substituée : `WpdbGateway` exige un `$wpdb`
	// réel, que le banc n'a pas et n'a pas à simuler.
	WpCliAccountsCommand::substituer_passerelle( $GLOBALS['banc_db'] );

	$cmd = new WpCliAccountsCommand();

	try {
		$cmd->quota_verify( array(), $reparer ? array( 'repair-mirror' => true ) : array() );
	} catch ( SortieHttp $e ) {
		return true;
	}

	return false;
}

/**
 * Prépare un compte avec une source donnée et un miroir donné.
 *
 * @param string $source Valeur brute de la source.
 * @param string $miroir Valeur brute du miroir.
 * @return int
 */
function compte_avec( string $source, string $miroir ): int {
	$id = 100 + count( CliDouble::$metas );

	CliDouble::$metas[ $id ] = array(
		LimiteEnvois::META_SOURCE => $source,
		LimiteEnvois::META        => $miroir,
	);

	return $id;
}

$source_ok = LimiteEnvois::encoder_source(
	array( array( 'a' => $t, 'e' => 'aaa' ), array( 'a' => $t + 10, 'e' => 'bbb' ) )
);
$miroir_ok = LimiteEnvois::encoder( array( $t, $t + 10 ) );

// ======================================================================
// 1 · LECTURE SEULE — aucune écriture, aucun verrou
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

$avant = CliDouble::$metas[ $id ];
$err   = lancer( false );

check( '1 · divergence constatée : code de sortie non nul', true === $err );
check( '1 · elle est signalée', 1 === count( CliDouble::$warnings ) );
check( '1 · LECTURE SEULE : AUCUNE écriture', $avant === CliDouble::$metas[ $id ] );
check( '1 · aucun verrou pris', array() === WpDouble::$options );

// Aucun écart : succès, sans erreur.
CliDouble::reset();
$id2 = compte_avec( $source_ok, $miroir_ok );
$err = lancer( false );

check( '1 · miroir conforme : aucune erreur', false === $err );
check( '1 · et la commande dit qu\'elle n\'a rien écrit',
	array() !== CliDouble::$succes
	&& false !== strpos( CliDouble::$succes[0], 'aucune écriture' ) );

// ======================================================================
// 2 · RÉPARATION RÉUSSIE — et PROUVÉE par relecture
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id3 = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

$err = lancer( true );

check( '2 · la réparation aboutit', false === $err );
check( '2 · LE MIROIR DÉRIVE DÉSORMAIS DE LA SOURCE',
	LimiteEnvois::decoder( CliDouble::$metas[ $id3 ][ LimiteEnvois::META ] )['horodatages']
	=== LimiteEnvois::horodatages_de( LimiteEnvois::decoder_source( $source_ok )['entrees'] ) );
check( '2 · LA SOURCE EST INTACTE, à l\'octet près',
	$source_ok === CliDouble::$metas[ $id3 ][ LimiteEnvois::META_SOURCE ] );
check( '2 · le compte rendu parle de relecture',
	false !== strpos( implode( ' ', CliDouble::$log ), 'RELUS' ) );

// ======================================================================
// 3 · ÉCRITURE REFUSÉE — comptée comme ÉCHEC, jamais comme réparation
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id4 = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

CliDouble::$ecritures_refusees = array( LimiteEnvois::META );

$err = lancer( true );

check( '3 · ÉCRITURE REFUSÉE : code de sortie non nul', true === $err );
check( '3 · elle est signalée comme telle',
	false !== strpos( implode( ' ', CliDouble::$warnings ), 'écriture du miroir refusée' ) );
check( '3 · AUCUNE réparation n\'est annoncée',
	false !== strpos( implode( ' ', CliDouble::$log ), 'RELUS : 0' ) );
check( '3 · une réparation échouée est comptée',
	false !== strpos( implode( ' ', CliDouble::$log ), 'échouées : 1' ) );
check( '3 · la source reste intacte',
	$source_ok === CliDouble::$metas[ $id4 ][ LimiteEnvois::META_SOURCE ] );

// ======================================================================
// 4 · SOURCE CORROMPUE — jamais réparée, jamais écrite
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id5 = compte_avec( 'nawak', $miroir_ok );

$avant5 = CliDouble::$metas[ $id5 ];
$err    = lancer( true );

check( '4 · SOURCE CORROMPUE : code de sortie non nul', true === $err );
check( '4 · signalée comme irréparable',
	false !== strpos( implode( ' ', CliDouble::$warnings ), 'aucune réparation possible' ) );
check( '4 · AUCUNE ÉCRITURE, ni source ni miroir', $avant5 === CliDouble::$metas[ $id5 ] );

// Source ABSENTE : ce n'est pas une divergence.
CliDouble::reset();
$id6 = compte_avec( '', LimiteEnvois::encoder( array( $t ) ) );
$err = lancer( true );

check( '4 · source ABSENTE : aucune divergence, aucune erreur', false === $err );
check( '4 · et le miroir hérité est laissé tel quel',
	LimiteEnvois::encoder( array( $t ) ) === CliDouble::$metas[ $id6 ][ LimiteEnvois::META ] );

// ======================================================================
// 5 · VERROU INDISPONIBLE — échec, et la source ne bouge pas
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id7 = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

// Le verrou est déjà posé : `add_option()` refusera de le reprendre.
Urbizen\Platform\Account\VerrouCompte::acquerir( $GLOBALS['banc_db'], $id7 );

$avant7 = CliDouble::$metas[ $id7 ];
$err    = lancer( true );

check( '5 · VERROU INDISPONIBLE : code de sortie non nul', true === $err );
check( '5 · signalé', false !== strpos( implode( ' ', CliDouble::$warnings ), 'verrou indisponible' ) );
check( '5 · AUCUNE ÉCRITURE', $avant7 === CliDouble::$metas[ $id7 ] );

// ======================================================================
// 6 · LA SOURCE N'EST JAMAIS ÉCRITE, quel que soit le scénario
// ======================================================================
$sources_apres = array();

foreach ( array( true, false ) as $mode ) {
	CliDouble::reset();
	WpDouble::reset();

	$a = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );
	$b = compte_avec( 'nawak', $miroir_ok );
	$c = compte_avec( $source_ok, $miroir_ok );

	lancer( $mode );

	$sources_apres[] = array(
		CliDouble::$metas[ $a ][ LimiteEnvois::META_SOURCE ],
		CliDouble::$metas[ $b ][ LimiteEnvois::META_SOURCE ],
		CliDouble::$metas[ $c ][ LimiteEnvois::META_SOURCE ],
	);
}

check( '6 · META_SOURCE EST IDENTIQUE dans tous les scénarios, réparation comprise',
	array( $source_ok, 'nawak', $source_ok ) === $sources_apres[0]
	&& array( $source_ok, 'nawak', $source_ok ) === $sources_apres[1] );

// ======================================================================
// 7 · LE VERROU EST LIBÉRÉ AVANT TOUTE SORTIE EN ERREUR
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id8 = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

CliDouble::$ecritures_refusees = array( LimiteEnvois::META );

lancer( true );

$verrous = 0;

foreach ( WpDouble::$options as $cle => $valeur ) {
	if ( 0 === strpos( (string) $cle, Urbizen\Platform\Account\VerrouCompte::PREFIXE ) ) {
		$verrous++;
	}
}

check( '7 · AUCUN VERROU NE SURVIT à une sortie en erreur', 0 === $verrous );

verdict();
