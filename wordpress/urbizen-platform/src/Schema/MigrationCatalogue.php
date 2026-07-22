<?php
/**
 * Liste ordonnée et explicite des migrations déclarées.
 *
 * **En E1, ce catalogue est vide, et c'est tout son intérêt.** C'est lui que
 * l'exécuteur consulte en premier ; vide, il rend la main avant qu'aucune
 * passerelle n'ait été touchée. Zéro migration déclarée, zéro requête.
 *
 * Le catalogue est un tableau littéral, pas une lecture de répertoire : on ne
 * veut pas qu'un fichier oublié dans un dossier devienne une migration, ni que
 * l'ordre dépende de l'ordre de lecture du système de fichiers.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

use InvalidArgumentException;

/**
 * Catalogue des migrations.
 */
final class MigrationCatalogue {

	/**
	 * Migrations déclarées, dans l'ordre d'application.
	 *
	 * @var array<int, Migration>
	 */
	private array $migrations;

	/**
	 * @param array<int, Migration> $migrations Migrations, dans l'ordre.
	 *
	 * @throws InvalidArgumentException Si un identifiant est vide ou répété.
	 */
	public function __construct( array $migrations = array() ) {
		$vus = array();

		foreach ( $migrations as $migration ) {
			if ( ! $migration instanceof Migration ) {
				throw new InvalidArgumentException( 'le catalogue n’accepte que des migrations' );
			}

			$id = trim( $migration->identifiant() );

			if ( '' === $id ) {
				throw new InvalidArgumentException( 'une migration doit porter un identifiant' );
			}

			if ( isset( $vus[ $id ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'identifiant de migration répété : « %s »', $id )
				);
			}

			$vus[ $id ] = true;
		}

		$this->migrations = array_values( $migrations );
	}

	/**
	 * Catalogue de la plateforme.
	 *
	 * **Vide en E1.** La première migration réelle arrivera au plus tôt en E3,
	 * avec les organisations. Tant que ce tableau reste vide, le greffon ne
	 * crée aucune table, pas même son propre registre.
	 *
	 * @return self
	 */
	public static function plateforme(): self {
		return new self( array() );
	}

	/**
	 * Migrations déclarées, dans l'ordre.
	 *
	 * @return array<int, Migration>
	 */
	public function declarees(): array {
		return $this->migrations;
	}

	/**
	 * Le catalogue est-il vide ?
	 *
	 * @return bool
	 */
	public function est_vide(): bool {
		return array() === $this->migrations;
	}

	/**
	 * Nombre de migrations déclarées.
	 *
	 * @return int
	 */
	public function nombre(): int {
		return count( $this->migrations );
	}

	/**
	 * Identifiants déclarés, dans l'ordre.
	 *
	 * @return array<int, string>
	 */
	public function identifiants(): array {
		$ids = array();

		foreach ( $this->migrations as $migration ) {
			$ids[] = $migration->identifiant();
		}

		return $ids;
	}
}
