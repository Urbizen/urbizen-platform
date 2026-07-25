<?php
/**
 * Adaptateur : port SQL → `$wpdb`.
 *
 * **Aucune requête dans le constructeur.** C'est une exigence, pas un détail
 * de style : la garantie « catalogue vide, zéro requête » ne tiendrait pas si
 * instancier la passerelle interrogeait la base.
 *
 * Les paramètres passent par `$wpdb->prepare()`. Les noms de tables, eux, ne
 * peuvent pas être préparés — ils sont construits à partir du préfixe de
 * l'installation et de constantes du greffon, jamais d'une entrée.
 *
 * @package Urbizen\Platform\Adapter
 */

namespace Urbizen\Platform\Adapter;

use Urbizen\Platform\Schema\ConnexionPerdue;
use Urbizen\Platform\Schema\DatabaseGateway;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Passerelle SQL sur `$wpdb`.
 */
final class WpdbGateway implements DatabaseGateway {

	/**
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Valeur de `reconnect_retries` sauvegardée le temps d'une section non
	 * reconnectable, ou `null` hors d'une telle section.
	 *
	 * @var int|null
	 */
	private ?int $reconnexions_sauvees = null;

	/**
	 * Filtre `wp_die_handler` posé le temps de la section, conservé pour être
	 * retiré exactement.
	 *
	 * @var callable|null
	 */
	private $filtre_die = null;

	/**
	 * @param wpdb|null $wpdb Passerelle WordPress ; celle du contexte par défaut.
	 */
	public function __construct( ?wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * @return string
	 */
	public function prefixe(): string {
		return (string) $this->wpdb->prefix;
	}

	/**
	 * @param string             $sql        Instruction.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return bool
	 */
	public function executer( string $sql, array $parametres = array() ): bool {
		$prete = $this->preparer( $sql, $parametres );

		// `query()` rend `false` en cas d'erreur, un entier sinon — y compris
		// `0` pour « zéro ligne touchée », qui n'est pas un échec.
		$retour = $this->wpdb->query( $prete ); // phpcs:ignore WordPress.DB

		return false !== $retour;
	}

	/**
	 * @param string             $sql        Requête.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return string|null
	 */
	public function valeur( string $sql, array $parametres = array() ): ?string {
		$valeur = $this->wpdb->get_var( $this->preparer( $sql, $parametres ) ); // phpcs:ignore WordPress.DB

		return null === $valeur ? null : (string) $valeur;
	}

	/**
	 * @param string             $sql        Requête.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return array<int, array<string, mixed>>
	 */
	public function lignes( string $sql, array $parametres = array() ): array {
		$lignes = $this->wpdb->get_results( $this->preparer( $sql, $parametres ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return is_array( $lignes ) ? $lignes : array();
	}

	/**
	 * @param string             $sql        Instruction.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return int
	 */
	public function lignes_affectees( string $sql, array $parametres = array() ): int {
		$retour = $this->wpdb->query( $this->preparer( $sql, $parametres ) ); // phpcs:ignore WordPress.DB

		// `query()` rend `false` sur erreur et un entier sinon — y compris `0`,
		// qui signifie « aucune ligne ne correspondait ». Pour un
		// compare-et-échange, cette distinction est tout l'enjeu.
		return false === $retour ? -1 : (int) $retour;
	}

	/**
	 * @param string $nom Nom complet.
	 * @return bool
	 */
	public function table_existe( string $nom ): bool {
		// `SHOW TABLES LIKE` accepte un paramètre préparé : le nom est ici une
		// valeur, pas un identifiant.
		$trouve = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $nom )
		);

		return (string) $trouve === $nom;
	}

	/**
	 * @return string
	 */
	public function derniere_erreur(): string {
		return (string) $this->wpdb->last_error;
	}

	/**
	 * Interdit la reconnexion de `wpdb` : aucune écriture ne peut être rejouée
	 * sur une connexion neuve après la perte de la connexion courante.
	 *
	 * Deux leviers, tous deux rétablis par {@see autoriser_reconnexion()} :
	 *
	 * - `reconnect_retries = 0` : `wpdb::query()` détecte « le serveur est
	 *   parti » (erreur 2006), appelle `check_connection()`, mais celle-ci ne
	 *   tente plus aucune reconnexion — la requête ne sera pas rejouée ;
	 * - un gestionnaire `wp_die` qui **lève** {@see ConnexionPerdue} : sans
	 *   reconnexion, `check_connection()` finit par appeler `dead_db()`, qui
	 *   afficherait la page « Error establishing a database connection » et
	 *   terminerait le processus. On l'intercepte pour en faire une exception
	 *   attrapable, et transformer la mort en échec restrictif.
	 *
	 * @return void
	 */
	public function interdire_reconnexion(): void {
		$this->reconnexions_sauvees    = (int) $this->wpdb->reconnect_retries;
		$this->wpdb->reconnect_retries = 0;

		// Ce filtre rend un gestionnaire `wp_die` qui lève au lieu de terminer
		// le processus. On conserve la fermeture exacte pour la retirer.
		$this->filtre_die = static function () {
			return static function () {
				throw new ConnexionPerdue( 'connexion a la base perdue en section critique' );
			};
		};

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_die_handler', $this->filtre_die, PHP_INT_MAX );
		}
	}

	/**
	 * Rétablit la reconnexion normale de `wpdb` et retire le gestionnaire.
	 *
	 * @return void
	 */
	public function autoriser_reconnexion(): void {
		if ( null !== $this->reconnexions_sauvees ) {
			$this->wpdb->reconnect_retries = $this->reconnexions_sauvees;
			$this->reconnexions_sauvees    = null;
		}

		if ( null !== $this->filtre_die && function_exists( 'remove_filter' ) ) {
			remove_filter( 'wp_die_handler', $this->filtre_die, PHP_INT_MAX );
		}

		$this->filtre_die = null;
	}

	/**
	 * Prépare, ou rend l'instruction telle quelle si elle n'a pas de paramètre.
	 *
	 * `prepare()` sans paramètre est une erreur de WordPress ; le SQL sans
	 * substituant est ici entièrement écrit par le greffon, jamais reçu.
	 *
	 * @param string             $sql        Instruction.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return string
	 */
	private function preparer( string $sql, array $parametres ): string {
		if ( array() === $parametres ) {
			return $sql;
		}

		return (string) $this->wpdb->prepare( $sql, $parametres );
	}
}
