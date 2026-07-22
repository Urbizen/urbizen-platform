<?php
/**
 * Banc : l'infrastructure de schéma, sur un WordPress réel.
 *
 * Ce que les bancs unitaires ne peuvent pas prouver, celui-ci le mesure.
 *
 * **La garantie « zéro requête »** y est établie non par une doublure, mais par
 * le compteur de WordPress lui-même : `$wpdb->num_queries` relevé juste avant
 * et juste après l'appel. Cette mesure ne dépend d'aucune affirmation du code
 * éprouvé — elle dit ce que la base a réellement reçu. Les requêtes de
 * l'amorçage de WordPress sont évidemment hors mesure : le relevé encadre le
 * seul appel Urbizen.
 *
 * **La table des symboles** d'un WordPress chargé sert de seconde couche au
 * contrôle de frontière : au lieu d'une liste de noms écrite à la main, qui
 * vieillirait, on confronte les identifiants du domaine à ce que WordPress
 * définit réellement.
 *
 * **Le verrou** est éprouvé avec deux processus, seule façon de vérifier
 * l'atomicité de l'acquisition.
 */

declare( strict_types = 1 );

require __DIR__ . '/amorce-reelle.php';
require_once __DIR__ . '/amorce-outils.php';

use Urbizen\Platform\Adapter\WpdbGateway;
use Urbizen\Platform\Schema\MigrationCatalogue;
use Urbizen\Platform\Schema\MigrationLock;
use Urbizen\Platform\Schema\MigrationRunner;

urbizen_banc_exiger_cron_desactive();
urbizen_banc_menage_a_la_sortie();

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
 * Verdict et code de sortie.
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
 * Tables Urbizen présentes.
 *
 * @return array<int, string>
 */
function tables_urbizen(): array {
	global $wpdb;

	$trouvees = $wpdb->get_col( "SHOW TABLES LIKE '%urbizen%'" ); // phpcs:ignore

	return is_array( $trouvees ) ? $trouvees : array();
}

/**
 * Options Urbizen présentes.
 *
 * @return int
 */
function options_urbizen(): int {
	global $wpdb;

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE 'urbizen%'" ); // phpcs:ignore
}

// ======================================================================
// 1 · ÉTAT INITIAL
// ======================================================================
$tables_avant  = tables_urbizen();
$options_avant = options_urbizen();

check( '1 · aucune table Urbizen au départ', array() === $tables_avant );
check( '1 · le préfixe est celui de l’installation', '' !== $wpdb->prefix );

$passerelle = new WpdbGateway();

check( '1 · CONSTRUIRE LA PASSERELLE N’INTERROGE PAS',
	$passerelle instanceof WpdbGateway );
check( '1 · elle rend le préfixe réel', $wpdb->prefix === $passerelle->prefixe() );

// ======================================================================
// 2 · CATALOGUE VIDE → DELTA DE REQUÊTES NUL   ← LE CONTRÔLE CENTRAL
// ======================================================================
$catalogue = MigrationCatalogue::plateforme();

check( '2 · le catalogue de la plateforme est vide en E1', $catalogue->est_vide() );

// Le relevé encadre strictement l'appel : ce qui précède, y compris tout
// l'amorçage de WordPress, est hors mesure.
$runner = new MigrationRunner( new WpdbGateway(), $catalogue );

$avant    = (int) $wpdb->num_queries;
$resultat = $runner->executer();
$apres    = (int) $wpdb->num_queries;

check( '2 · CATALOGUE VIDE → DELTA DE REQUÊTES STRICTEMENT NUL', 0 === ( $apres - $avant ) );
check( '2 · l’exécution rend « rien à faire »', $resultat->rien_a_faire() );
check( '2 · qui est un succès', $resultat->reussi() );

// Les deux autres entrées.
$avant = (int) $wpdb->num_queries;
$runner->etat();
check( '2 · etat() : delta nul', 0 === ( (int) $wpdb->num_queries - $avant ) );

$avant = (int) $wpdb->num_queries;
$runner->verifier();
check( '2 · verifier() : delta nul', 0 === ( (int) $wpdb->num_queries - $avant ) );

// Même en enchaînant : rien ne s'accumule.
$avant = (int) $wpdb->num_queries;

for ( $i = 0; $i < 50; $i++ ) {
	( new MigrationRunner( new WpdbGateway(), MigrationCatalogue::plateforme() ) )->executer();
}

check( '2 · CINQUANTE EXÉCUTIONS : TOUJOURS ZÉRO REQUÊTE', 0 === ( (int) $wpdb->num_queries - $avant ) );

// ======================================================================
// 3 · AUCUNE TRACE LAISSÉE
// ======================================================================
check( '3 · AUCUNE TABLE CRÉÉE', array() === tables_urbizen() );
check( '3 · AUCUNE OPTION CRÉÉE', $options_avant === options_urbizen() );
check( '3 · aucun verrou posé', false === (bool) get_option( MigrationLock::OPTION, false ) );
check( '3 · aucun transient de schéma', false === get_transient( 'urbizen_schema' ) );
check( '3 · le registre n’existe pas', ! $passerelle->table_existe( $wpdb->prefix . MigrationRunner::TABLE ) );

// ======================================================================
// 4 · LA COMMANDE WP-CLI N’EXISTE PAS EN CONTEXTE WEB
// ======================================================================
// Ce banc n'est pas exécuté par WP-CLI : `WP_CLI` n'est donc pas défini, et
// l'enregistrement doit être silencieusement sans effet.
check( '4 · WP_CLI n’est pas défini dans ce contexte', ! defined( 'WP_CLI' ) );

$avant = (int) $wpdb->num_queries;
\Urbizen\Platform\Adapter\WpCliSchemaCommand::register();

check( '4 · L’ENREGISTREMENT HORS WP-CLI N’A AUCUN EFFET', 0 === ( (int) $wpdb->num_queries - $avant ) );
check( '4 · et ne crée toujours aucune table', array() === tables_urbizen() );

// Aucun hook ne porte l'exécuteur.
$accroche = false;

foreach ( array( 'plugins_loaded', 'init', 'admin_init', 'wp_loaded', 'shutdown' ) as $hook ) {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $hook ] ) ) {
		continue;
	}

	foreach ( $wp_filter[ $hook ]->callbacks as $rappels ) {
		foreach ( $rappels as $rappel ) {
			$cible = $rappel['function'];

			if ( is_array( $cible ) ) {
				$classe = is_object( $cible[0] ) ? get_class( $cible[0] ) : (string) $cible[0];

				if ( false !== strpos( $classe, 'MigrationRunner' ) || false !== strpos( $classe, 'SchemaGuard' ) ) {
					$accroche = true;
				}
			}
		}
	}
}

check( '4 · AUCUN HOOK WEB NE PORTE L’EXÉCUTEUR', false === $accroche );

// ======================================================================
// 4 bis · SOUS WP-CLI SIMULÉ
//
// Le binaire `wp` n'est pas installé sur la machine de développement : un
// processus fils reconstitue la constante et la classe que WP-CLI fournit,
// puis exerce les trois sous-commandes. La doublure est fidèle sur la seule
// surface employée par l'adaptateur.
// ======================================================================
$sortie = array();
exec(
	sprintf( '%s %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( __DIR__ . '/procs/wp-cli-simule.php' ) ),
	$sortie,
	$code
);

$cli = json_decode( trim( implode( '', $sortie ) ), true );

check( '4 bis · le processus CLI simulé répond', is_array( $cli ) );
check( '4 bis · LA COMMANDE S’ENREGISTRE SOUS WP-CLI', true === ( $cli['enregistree'] ?? false ) );
check( '4 bis · elle vise la bonne classe',
	'Urbizen\\Platform\\Adapter\\WpCliSchemaCommand' === ( $cli['classe'] ?? '' ) );
check( '4 bis · status : zéro requête', 0 === ( $cli['deltas']['status'] ?? -1 ) );
check( '4 bis · MIGRATE : ZÉRO REQUÊTE', 0 === ( $cli['deltas']['migrate'] ?? -1 ) );
check( '4 bis · verify : zéro requête', 0 === ( $cli['deltas']['verify'] ?? -1 ) );
check( '4 bis · aucune erreur bloquante', false === ( $cli['erreur'] ?? true ) );
check( '4 bis · le message annonce l’absence de requête',
	false !== strpos( implode( '|', $cli['messages'] ?? array() ), 'aucune requête' ) );
check( '4 bis · AUCUNE TABLE CRÉÉE PAR LES COMMANDES', 0 === ( $cli['tables'] ?? -1 ) );

// ======================================================================
// 5 · CAPACITÉS RÉELLES DU MOTEUR
// ======================================================================
// La sonde n'est jamais lancée par le greffon avec un catalogue vide ; ici on
// l'invoque explicitement, comme le ferait une migration qui l'exige.
$garde = new \Urbizen\Platform\Schema\SchemaGuard( new WpdbGateway() );

check( '5 · InnoDB est disponible', $garde->possede( \Urbizen\Platform\Schema\SchemaGuard::INNODB ) );
check( '5 · utf8mb4 est disponible', $garde->possede( \Urbizen\Platform\Schema\SchemaGuard::UTF8MB4 ) );
check( '5 · LES CONTRAINTES CHECK SONT RÉELLEMENT APPLIQUÉES',
	$garde->possede( \Urbizen\Platform\Schema\SchemaGuard::CHECK ) );
check( '5 · une capacité inconnue reste refusée', false === $garde->possede( 'licorne' ) );

// La sonde ne laisse aucune table permanente.
$restes = $wpdb->get_col( "SHOW TABLES LIKE '%urbizen_sonde%'" ); // phpcs:ignore

check( '5 · LA SONDE NE LAISSE AUCUNE TABLE', array() === ( is_array( $restes ) ? $restes : array() ) );

// ======================================================================
// 6 · VERROU, DEUX PROCESSUS
// ======================================================================
delete_option( MigrationLock::OPTION );

$verrou = MigrationLock::acquerir( new WpdbGateway() );

check( '6 · le verrou s’acquiert', null !== $verrou );
$brut_verrou = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MigrationLock::OPTION ) ); // phpcs:ignore
$decode      = json_decode( (string) $brut_verrou, true );

check( '6 · il est visible en base, en JSON prévisible', is_array( $decode ) );
check( '6 · la valeur stockée porte notre propriétaire',
	( $decode['proprietaire'] ?? '' ) === $verrou->proprietaire() );
check( '6 · elle porte une échéance future', (int) ( $decode['expire_le'] ?? 0 ) > time() );

// Un second processus réel tente d'acquérir pendant que nous tenons le verrou.
$script = __DIR__ . '/procs/acquerir-verrou.php';
$racine = (string) getenv( 'URBIZEN_WP_ROOT' );

$sortie = array();
$code   = 0;
exec( sprintf( '%s %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $script ) ), $sortie, $code );

$reponse = trim( implode( '', $sortie ) );

check( '6 · UN SECOND PROCESSUS EST REFUSÉ PENDANT QUE NOUS TENONS', 'refuse' === $reponse );

check( '6 · nous libérons', $verrou->liberer() );
check( '6 · l’option a disparu', false === get_option( MigrationLock::OPTION, false ) );

// Une fois libéré, le second processus passe.
$sortie = array();
exec( sprintf( '%s %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $script ) ), $sortie, $code );

check( '6 · une fois libéré, un autre processus acquiert', 'acquis' === trim( implode( '', $sortie ) ) );

delete_option( MigrationLock::OPTION );

// ======================================================================
// 6 bis · REPRISE D'UN VERROU EXPIRÉ, SOUS CONCURRENCE RÉELLE
//
// Le parent pose un verrou DÉJÀ ÉCHU, puis lance six fils qui attendent le
// même instant avant de tenter la reprise. C'est la fenêtre exacte que
// l'ancienne implémentation laissait ouverte — lire, supprimer, reposer.
//
// Exigence : exactement un fils obtient le verrou.
// ======================================================================
delete_option( MigrationLock::OPTION );

$passerelle_verrou = new WpdbGateway();

// Un verrou expiré, écrit tel quel : propriétaire valide, échéance dépassée.
$fantome = wp_json_encode(
	array(
		'proprietaire' => \Urbizen\Platform\Domain\Support\Ulid::generer(),
		'cree_le'      => time() - 10000,
		'expire_le'    => time() - 5000,
	)
);

$wpdb->query( // phpcs:ignore
	$wpdb->prepare(
		"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
		MigrationLock::OPTION,
		$fantome
	)
);
wp_cache_delete( MigrationLock::OPTION, 'options' );
wp_cache_delete( 'alloptions', 'options' );

check( '6 bis · un verrou expiré est en place',
	$fantome === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MigrationLock::OPTION ) ) ); // phpcs:ignore

$fils      = 6;
$dossier   = sys_get_temp_dir() . '/urbizen-verrou-' . getmypid();
@mkdir( $dossier, 0700, true );

// Départ commun, assez loin pour que tous aient fini d'amorcer WordPress.
$depart  = microtime( true ) + 6.0;
$script  = __DIR__ . '/procs/reprendre-verrou-expire.php';
$attente = array();

for ( $i = 0; $i < $fils; $i++ ) {
	$fichier      = $dossier . '/fils-' . $i . '.txt';
	$attente[]    = $fichier;

	// En arrière-plan : les fils doivent courir ensemble, pas l'un après
	// l'autre.
	exec(
		sprintf(
			'%s %s %s %s > /dev/null 2>&1 &',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $script ),
			escapeshellarg( $fichier ),
			escapeshellarg( (string) $depart )
		)
	);
}

// On laisse le temps aux fils d'amorcer, de courir, puis d'écrire.
$limite = microtime( true ) + 40.0;

do {
	usleep( 250000 );
	$ecrits = 0;

	foreach ( $attente as $fichier ) {
		if ( is_file( $fichier ) && '' !== trim( (string) @file_get_contents( $fichier ) ) ) {
			++$ecrits;
		}
	}
} while ( $ecrits < $fils && microtime( true ) < $limite );

$acquis     = 0;
$refuses    = 0;
$possesseurs = array();

foreach ( $attente as $fichier ) {
	$ligne = trim( (string) @file_get_contents( $fichier ) );

	if ( 0 === strpos( $ligne, 'acquis:' ) ) {
		++$acquis;
		$possesseurs[] = substr( $ligne, 7 );
	} elseif ( 'refuse' === $ligne ) {
		++$refuses;
	}

	@unlink( $fichier );
}

@rmdir( $dossier );

check( sprintf( '6 bis · les %d fils ont tous répondu', $fils ), $fils === ( $acquis + $refuses ) );
check( '6 bis · EXACTEMENT UN FILS OBTIENT LE VERROU EXPIRÉ', 1 === $acquis );
check( '6 bis · tous les autres sont refusés', $fils - 1 === $refuses );
check( '6 bis · un seul propriétaire distinct', 1 === count( array_unique( $possesseurs ) ) );

delete_option( MigrationLock::OPTION );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", MigrationLock::OPTION ) ); // phpcs:ignore

check( '6 bis · aucun verrou résiduel',
	null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MigrationLock::OPTION ) ) ); // phpcs:ignore

// ======================================================================
// 7 · FRONTIÈRE, CONFRONTÉE AUX SYMBOLES RÉELS DE WORDPRESS
// ======================================================================
// Seconde couche du contrôle de frontière : au lieu d'une liste écrite à la
// main, on confronte les identifiants appelés par le domaine à ce que ce
// WordPress définit réellement. Cette couche ne vieillit pas.
$fonctions_wp = get_defined_functions()['internal'];
$fonctions_wp = array_merge( $fonctions_wp, get_defined_functions()['user'] );
$fonctions_wp = array_flip( array_map( 'strtolower', $fonctions_wp ) );

$classes_wp = array_flip( array_map( 'strtolower', get_declared_classes() ) );

$racine_domaine = URBIZEN_PLATFORM_DIR . 'src/Domain';
$fautifs        = array();

$iterateur = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine_domaine ) );

foreach ( $iterateur as $fichier ) {
	if ( ! $fichier->isFile() || 'php' !== $fichier->getExtension() ) {
		continue;
	}

	$jetons = token_get_all( (string) file_get_contents( $fichier->getPathname() ) );
	$utiles = array();

	foreach ( $jetons as $jeton ) {
		if ( is_array( $jeton ) ) {
			if ( in_array( $jeton[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE ), true ) ) {
				continue;
			}

			$utiles[] = array( $jeton[0], $jeton[1] );
			continue;
		}

		$utiles[] = array( -1, $jeton );
	}

	for ( $i = 0, $n = count( $utiles ); $i < $n; $i++ ) {
		if ( T_STRING !== $utiles[ $i ][0] ) {
			continue;
		}

		$nom       = strtolower( $utiles[ $i ][1] );
		$suivant   = $utiles[ $i + 1 ][1] ?? '';
		$precedent = $utiles[ $i - 1 ][1] ?? '';

		if ( '(' !== $suivant || '->' === $precedent || '::' === $precedent || 'function' === $precedent ) {
			continue;
		}

		// Une fonction connue de WordPress mais absente de PHP nu est une
		// dépendance : PHP seul ne la fournirait pas.
		if ( isset( $fonctions_wp[ $nom ] ) && ! function_exists( '\\' . $nom ) ) {
			continue;
		}

		if ( 0 === strpos( $nom, 'wp_' ) || 0 === strpos( $nom, 'get_' ) && isset( $fonctions_wp[ $nom ] ) ) {
			// `get_class` est une fonction PHP : on ne retient que ce qui
			// n'existe pas en PHP nu.
			if ( ! in_array( $nom, get_defined_functions()['internal'], true ) ) {
				$fautifs[] = basename( $fichier->getPathname() ) . ' → ' . $nom . '()';
			}
		}
	}
}

check( '7 · LE DOMAINE N’APPELLE AUCUNE FONCTION WORDPRESS', array() === $fautifs );

if ( array() !== $fautifs ) {
	foreach ( $fautifs as $f ) {
		echo "      $f\n";
	}
}

check( '7 · la table des symboles a bien été chargée', count( $classes_wp ) > 50 );

// ======================================================================
// SORTIE
// ======================================================================
check( 'sortie · aucune table Urbizen', array() === tables_urbizen() );
check( 'sortie · aucune option ajoutée', $options_avant === options_urbizen() );
check( 'sortie · aucun verrou résiduel', false === get_option( MigrationLock::OPTION, false ) );

verdict();
