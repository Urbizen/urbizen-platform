<?php
/**
 * Catalogue des formulaires déclarés.
 *
 * Chaque définition est un fichier de `definitions/` renvoyant un tableau.
 * Deux formulaires sont déclarés : `localisation` et `conception`. Le catalogue
 * reste délibérément minimal — pas de découverte dynamique, pas de filtre
 * d'extension tant qu'aucun besoin concret ne le justifie : une liste blanche
 * en dur est la garantie qu'aucune valeur reçue du navigateur ne peut désigner
 * un fichier arbitraire.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Chargement et mise en cache des définitions.
 */
final class FormRegistry {

	/**
	 * Formulaires connus. Toute valeur hors de cette liste est refusée.
	 */
	public const KNOWN = array( 'localisation', 'conception' );

	/**
	 * Définitions déjà chargées.
	 *
	 * @var array<string, FormDefinition>
	 */
	private static array $loaded = array();

	/**
	 * Renvoie une définition, ou null si le type est inconnu ou illisible.
	 *
	 * @param string $type Identifiant du formulaire.
	 * @return FormDefinition|null
	 */
	public static function get( string $type ): ?FormDefinition {
		if ( ! in_array( $type, self::KNOWN, true ) ) {
			return null;
		}

		if ( isset( self::$loaded[ $type ] ) ) {
			return self::$loaded[ $type ];
		}

		$file = URBIZEN_PLATFORM_DIR . 'src/Forms/definitions/' . $type . '.php';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$raw = require $file;

		if ( ! is_array( $raw ) || empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
			return null;
		}

		$definition = new FormDefinition(
			(string) ( $raw['type'] ?? $type ),
			(string) ( $raw['title'] ?? '' ),
			(string) ( $raw['submit_label'] ?? '' ),
			$raw['fields'],
			isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array()
		);

		// Une définition fautive n'interrompt pas la page, mais elle ne passe
		// jamais inaperçue : les anomalies sont des erreurs de développement.
		foreach ( $definition->errors() as $anomalie ) {
			Logger::error( sprintf( 'définition « %s » : %s', $type, $anomalie ) );
		}

		self::$loaded[ $type ] = $definition;

		return self::$loaded[ $type ];
	}

	/**
	 * Type par défaut.
	 */
	public static function default_type(): string {
		return self::KNOWN[0];
	}
}
