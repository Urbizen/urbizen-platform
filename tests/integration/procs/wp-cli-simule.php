<?php
/**
 * Processus fils : contexte WP-CLI simulé.
 *
 * Le binaire `wp` n'est pas installé sur la machine de développement. Ce
 * processus reconstitue ce que WP-CLI fournit — la constante `WP_CLI` et la
 * classe du même nom — puis vérifie que l'adaptateur s'enregistre, que ses
 * trois sous-commandes répondent, et qu'aucune n'émet la moindre requête avec
 * un catalogue vide.
 *
 * La doublure est **fidèle sur ce qui compte** : `add_command()` enregistre,
 * `error()` interrompt avec un code non nul, `success()` et `log()` écrivent.
 * C'est exactement la surface qu'emploie l'adaptateur.
 *
 * Rend une ligne JSON, lue et éprouvée par le banc parent.
 */

declare( strict_types = 1 );

define( 'WP_CLI', true );

/**
 * Doublure de WP_CLI.
 */
class WP_CLI {

	/**
	 * Commandes enregistrées.
	 *
	 * @var array<string, string>
	 */
	public static array $commandes = array();

	/**
	 * Messages émis.
	 *
	 * @var array<int, string>
	 */
	public static array $messages = array();

	/**
	 * Une erreur bloquante a-t-elle été émise ?
	 *
	 * @var bool
	 */
	public static bool $erreur = false;

	/**
	 * @param string $nom    Nom de commande.
	 * @param string $classe Classe.
	 * @return void
	 */
	public static function add_command( $nom, $classe ) {
		self::$commandes[ $nom ] = is_string( $classe ) ? $classe : get_class( $classe );
	}

	public static function log( $message ) {
		self::$messages[] = 'log:' . $message;
	}

	public static function success( $message ) {
		self::$messages[] = 'success:' . $message;
	}

	public static function warning( $message ) {
		self::$messages[] = 'warning:' . $message;
	}

	public static function error( $message ) {
		self::$erreur     = true;
		self::$messages[] = 'error:' . $message;
	}
}

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Adapter\WpCliSchemaCommand;

global $wpdb;

WpCliSchemaCommand::register();

$enregistree = isset( WP_CLI::$commandes['urbizen schema'] );

// Les trois sous-commandes, chacune sous mesure de requêtes.
$commande = new WpCliSchemaCommand();
$deltas   = array();

foreach ( array( 'status', 'migrate', 'verify' ) as $sous ) {
	WP_CLI::$messages = array();
	WP_CLI::$erreur   = false;

	$avant = (int) $wpdb->num_queries;
	$commande->$sous();
	$deltas[ $sous ] = (int) $wpdb->num_queries - $avant;
}

$tables = $wpdb->get_col( "SHOW TABLES LIKE '%urbizen%'" ); // phpcs:ignore

echo wp_json_encode(
	array(
		'enregistree' => $enregistree,
		'classe'      => WP_CLI::$commandes['urbizen schema'] ?? '',
		'deltas'      => $deltas,
		'erreur'      => WP_CLI::$erreur,
		'messages'    => WP_CLI::$messages,
		'tables'      => is_array( $tables ) ? count( $tables ) : 0,
	)
);

exit( 0 );
