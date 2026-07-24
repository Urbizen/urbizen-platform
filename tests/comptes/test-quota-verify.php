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

/**
 * Passerelle qui exécute un rappel À L'ACQUISITION D'UN VERROU.
 *
 * Elle enveloppe une vraie `PasserelleOptions` — `final`, donc non héritable —
 * et déclenche le rappel JUSTE AVANT que la réparation n'acquière son verrou.
 *
 * L'interleaving simulé : l'autre processus termine son alignement et libère
 * son verrou (le rappel), PUIS la réparation acquiert le sien (l'INSERT
 * délégué). Déclencher le rappel après l'INSERT simulerait au contraire une
 * écriture concurrente qui violerait le verrou déjà tenu — ce qui n'est pas le
 * scénario.
 */
final class PasserelleAlignante implements Urbizen\Platform\Schema\DatabaseGateway {

	private PasserelleOptions $inner;

	/** @var callable */
	private $a_l_acquisition;

	private bool $declenche = false;

	public function __construct( callable $a_l_acquisition ) {
		$this->inner           = new PasserelleOptions();
		$this->a_l_acquisition = $a_l_acquisition;
	}

	public function prefixe(): string {
		return $this->inner->prefixe();
	}

	public function executer( string $sql, array $parametres = array() ): bool {
		return $this->inner->executer( $sql, $parametres );
	}

	public function valeur( string $sql, array $parametres = array() ): ?string {
		return $this->inner->valeur( $sql, $parametres );
	}

	public function lignes( string $sql, array $parametres = array() ): array {
		return $this->inner->lignes( $sql, $parametres );
	}

	public function lignes_affectees( string $sql, array $parametres = array() ): int {
		// AVANT de poser le verrou de la réparation : l'autre processus vient de
		// finir et de libérer le sien. Une seule fois.
		if ( false !== strpos( $sql, 'INSERT' )
			&& isset( $parametres[0] )
			&& 0 === strpos( (string) $parametres[0], Urbizen\Platform\Account\VerrouCompte::PREFIXE )
			&& ! $this->declenche ) {
			$this->declenche = true;
			( $this->a_l_acquisition )();
		}

		return $this->inner->lignes_affectees( $sql, $parametres );
	}

	/**
	 * Le verrou d'un compte est-il posé dans la passerelle interne ?
	 *
	 * Seule observation exposée : de quoi vérifier qu'aucun verrou ne survit,
	 * sans donner accès au reste de l'état interne.
	 *
	 * @param int $compte Identifiant.
	 * @return bool
	 */
	public function verrou_survit( int $compte ): bool {
		return array_key_exists(
			Urbizen\Platform\Account\VerrouCompte::option_pour( $compte ),
			$this->inner->options
		);
	}

	public function table_existe( string $nom ): bool {
		return $this->inner->table_existe( $nom );
	}

	public function derniere_erreur(): string {
		return $this->inner->derniere_erreur();
	}
}

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

	/**
	 * Clés dont l'écriture REND VRAI sans rien retenir.
	 *
	 * C'est le cas le plus traître : la commande croit avoir réparé.
	 *
	 * @var array<int, string>
	 */
	public static array $ecritures_menteuses = array();

	/** @var array<string, int> */
	public static array $tentatives = array();

	public static bool $verrou_indisponible = false;

	public static function reset(): void {
		self::$log                 = array();
		self::$warnings            = array();
		self::$succes              = array();
		self::$erreurs             = array();
		self::$metas               = array();
		self::$ecritures_refusees  = array();
		self::$ecritures_menteuses = array();
		self::$tentatives         = array();
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
	CliDouble::$tentatives[ $cle ] = ( CliDouble::$tentatives[ $cle ] ?? 0 ) + 1;

	if ( in_array( $cle, CliDouble::$ecritures_refusees, true ) ) {
		return false;
	}

	// Annonce un succès sans rien retenir.
	if ( in_array( $cle, CliDouble::$ecritures_menteuses, true ) ) {
		return true;
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
	// La passerelle est INJECTÉE : `WpdbGateway` exige un `$wpdb` réel, que le
	// banc n'a pas. Aucune couture statique n'existe côté production.
	$cmd = new WpCliAccountsCommand( $GLOBALS['banc_db'] );

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
	// Un compteur qui NE SE RÉINITIALISE PAS : les verrous vivent dans la
	// passerelle partagée, et deux comptes de même identifiant partageraient un
	// verrou entre deux scénarios.
	static $suivant = 100;

	$id = $suivant++;

	CliDouble::$metas[ $id ] = array(
		LimiteEnvois::META_SOURCE => $source,
		LimiteEnvois::META        => $miroir,
	);

	return $id;
}

/**
 * Le verrou d'un compte survit-il dans la passerelle INJECTÉE ?
 *
 * `VerrouCompte` écrit dans la `DatabaseGateway` qu'on lui donne, pas dans
 * `WpDouble::$options`. Inspecter ce dernier réussirait même si le verrou
 * survivait : c'est le mauvais magasin.
 *
 * @param int $compte Identifiant.
 * @return bool
 */
function verrou_survit_banc_db( int $compte ): bool {
	return array_key_exists(
		Urbizen\Platform\Account\VerrouCompte::option_pour( $compte ),
		$GLOBALS['banc_db']->options
	);
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
	false !== strpos( implode( ' ', CliDouble::$warnings ), 'écriture refusée' ) );
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

// Le verrou est déjà posé : `add_option()` refusera de le reprendre. On garde
// l'objet pour le libérer ensuite, faute de quoi il polluerait la passerelle
// partagée des scénarios suivants.
$verrou5 = Urbizen\Platform\Account\VerrouCompte::acquerir( $GLOBALS['banc_db'], $id7 );

$avant7 = CliDouble::$metas[ $id7 ];
$err    = lancer( true );

check( '5 · VERROU INDISPONIBLE : code de sortie non nul', true === $err );
check( '5 · signalé', false !== strpos( implode( ' ', CliDouble::$warnings ), 'verrou indisponible' ) );
check( '5 · AUCUNE ÉCRITURE', $avant7 === CliDouble::$metas[ $id7 ] );
check( '5 · le verrou volontaire est bien présent avant libération',
	true === verrou_survit_banc_db( $id7 ) );

// Libéré : il ne doit pas peser sur la suite.
$verrou5->liberer();
check( '5 · et il disparaît une fois libéré', false === verrou_survit_banc_db( $id7 ) );

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

check( '7 · AUCUN VERROU NE SURVIT à une sortie en erreur — dans la passerelle INJECTÉE',
	false === verrou_survit_banc_db( $id8 ) );

// ======================================================================
// 8 · CONCURRENCE RÉELLE — le miroir s'aligne À L'ACQUISITION DU VERROU
// ======================================================================
CliDouble::reset();
WpDouble::reset();

// Miroir DIVERGENT au constat initial : reparer_miroir() DOIT être atteinte.
$id9 = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

// La passerelle aligne le miroir au moment où le verrou est posé — après le
// constat, avant la relecture sous verrou.
$aligne = 0;

$db_conc = new PasserelleAlignante(
	static function () use ( $id9, $miroir_ok, &$aligne ): void {
		CliDouble::$metas[ $id9 ][ LimiteEnvois::META ] = $miroir_ok;
		$aligne++;
	}
);

// Toute écriture de métadonnée pendant la réparation serait de trop : le
// miroir est déjà bon quand la relecture sous verrou le regarde.
CliDouble::$ecritures_refusees = array( LimiteEnvois::META );

$cmd8 = new WpCliAccountsCommand( $db_conc );
$err  = false;

try {
	$cmd8->quota_verify( array(), array( 'repair-mirror' => true ) );
} catch ( SortieHttp $e ) {
	$err = true;
}

check( '8 · le constat initial a VU la divergence',
	false !== strpos( implode( ' ', CliDouble::$warnings ), 'miroir divergent' ) );
check( '8 · reparer_miroir() A ÉTÉ RÉELLEMENT ATTEINTE (verrou posé)', 1 === $aligne );
check( '8 · la relecture sous verrou constate l\'alignement : SUCCÈS', false === $err );
check( '8 · une réparation est comptée',
	false !== strpos( implode( ' ', CliDouble::$log ), 'RELUS : 1' ) );
check( '8 · AUCUNE ÉCRITURE N\'EST MÊME TENTÉE — la relecture pré-écriture l\'a court-circuitée',
	0 === ( CliDouble::$tentatives[ LimiteEnvois::META ] ?? 0 ) );
check( '8 · le miroir posé par la course tient',
	$miroir_ok === CliDouble::$metas[ $id9 ][ LimiteEnvois::META ] );
check( '8 · la source est intacte', $source_ok === CliDouble::$metas[ $id9 ][ LimiteEnvois::META_SOURCE ] );
check( '8 · le verrou de la réparation a été libéré', false === $db_conc->verrou_survit( $id9 ) );

// ======================================================================
// 9 · ÉCRITURE « RÉUSSIE » MAIS RELECTURE DIVERGENTE
// ======================================================================
CliDouble::reset();
WpDouble::reset();
$id10 = compte_avec( $source_ok, LimiteEnvois::encoder( array( $t ) ) );

// L'écriture rend vrai, mais n'est pas retenue : seul le relu fait foi.
CliDouble::$ecritures_menteuses = array( LimiteEnvois::META );

$err = lancer( true );

check( '9 · ÉCRITURE NON RETENUE : code de sortie non nul', true === $err );
check( '9 · signalée comme relecture divergente',
	false !== strpos( implode( ' ', CliDouble::$warnings ), 'relecture divergente' ) );
check( '9 · aucune réparation annoncée',
	false !== strpos( implode( ' ', CliDouble::$log ), 'RELUS : 0' ) );
check( '9 · LA SOURCE EST INTACTE',
	$source_ok === CliDouble::$metas[ $id10 ][ LimiteEnvois::META_SOURCE ] );

// ======================================================================
// 10 · LE MARQUEUR « corrompue » DU MIROIR N'EST JAMAIS PERDU
// ======================================================================
$source_vide = LimiteEnvois::encoder_source( array() );

// ── Lecture seule : source valide VIDE + miroir CORROMPU ──────────────
CliDouble::reset();
WpDouble::reset();
$id11 = compte_avec( $source_vide, 'nawak' );

$avant11 = CliDouble::$metas[ $id11 ];
$err     = lancer( false );

check( '10 · source vide + miroir corrompu : DIVERGENCE, pas « conforme »', true === $err );
check( '10 · elle est signalée', false !== strpos( implode( ' ', CliDouble::$warnings ), 'miroir divergent' ) );
check( '10 · LECTURE SEULE : aucune écriture', $avant11 === CliDouble::$metas[ $id11 ] );

// ── Réparation : le miroir corrompu devient un état valide VIDE ───────
CliDouble::reset();
WpDouble::reset();
$id12 = compte_avec( $source_vide, 'nawak' );

$err = lancer( true );

check( '10 · réparation d\'un miroir corrompu : SUCCÈS', false === $err );
check( '10 · le miroir est désormais un état valide vide',
	false === LimiteEnvois::decoder( CliDouble::$metas[ $id12 ][ LimiteEnvois::META ] )['corrompue']
	&& array() === LimiteEnvois::decoder( CliDouble::$metas[ $id12 ][ LimiteEnvois::META ] )['horodatages'] );
check( '10 · et il n\'est plus « nawak »', 'nawak' !== CliDouble::$metas[ $id12 ][ LimiteEnvois::META ] );
check( '10 · la source reste intacte, octet par octet',
	$source_vide === CliDouble::$metas[ $id12 ][ LimiteEnvois::META_SOURCE ] );

// ── Écriture « réussie » laissant le miroir CORROMPU ──────────────────
CliDouble::reset();
WpDouble::reset();
$id13 = compte_avec( $source_vide, 'nawak' );

// L'écriture rend vrai mais ne retient rien : le miroir reste « nawak ».
CliDouble::$ecritures_menteuses = array( LimiteEnvois::META );

$err = lancer( true );

check( '10 · écriture non retenue laissant un miroir corrompu : ÉCHEC', true === $err );
check( '10 · signalée comme relecture divergente',
	false !== strpos( implode( ' ', CliDouble::$warnings ), 'relecture divergente' ) );
check( '10 · AUCUNE réparation n\'est annoncée',
	false !== strpos( implode( ' ', CliDouble::$log ), 'RELUS : 0' ) );
check( '10 · la source reste intacte', $source_vide === CliDouble::$metas[ $id13 ][ LimiteEnvois::META_SOURCE ] );

// ── Le verrou est libéré dans TOUS ces cas ────────────────────────────
// Les trois comptes sont passés par `lancer()`, donc par la passerelle
// injectée `$GLOBALS['banc_db']` — c'est là qu'un verrou survivrait.
check( '10 · aucun verrou ne survit (compte source vide + miroir corrompu, lecture seule)',
	false === verrou_survit_banc_db( $id11 ) );
check( '10 · aucun verrou ne survit (réparation d\'un miroir corrompu)',
	false === verrou_survit_banc_db( $id12 ) );
check( '10 · aucun verrou ne survit (écriture non retenue)',
	false === verrou_survit_banc_db( $id13 ) );

verdict();
