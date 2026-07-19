<?php
/**
 * Catalogue des formulaires déclarés.
 *
 * Chaque définition est un fichier de `definitions/` renvoyant un tableau.
 * Un seul formulaire existe à ce jour : `localisation`. Le catalogue reste
 * délibérément minimal — pas de découverte dynamique, pas de filtre
 * d'extension tant qu'aucun second cas ne le justifie.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Chargement et mise en cache des définitions.
 */
final class FormRegistry {

	/**
	 * Formulaires connus. Toute valeur hors de cette liste est refusée.
	 */
	public const KNOWN = array( 'localisation' );

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

		self::$loaded[ $type ] = new FormDefinition(
			(string) ( $raw['type'] ?? $type ),
			(string) ( $raw['title'] ?? '' ),
			(string) ( $raw['submit_label'] ?? '' ),
			$raw['fields']
		);

		return self::$loaded[ $type ];
	}

	/**
	 * Type par défaut.
	 */
	public static function default_type(): string {
		return self::KNOWN[0];
	}
}
