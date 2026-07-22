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
