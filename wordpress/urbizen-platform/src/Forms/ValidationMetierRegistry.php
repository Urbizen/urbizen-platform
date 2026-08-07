<?php
/**
 * Table de confiance : type de formulaire → règles métier autorisées.
 *
 * Même principe que les registres tarifaire et d'upload : la résolution part du
 * **type serveur**, jamais d'une valeur de requête, et un type sans règles n'en
 * hérite d'aucune — pas de repli sur celles d'un autre formulaire. Un parcours
 * qui n'a pas de cohérence inter-champs à faire respecter passe simplement
 * cette étape.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Résolution des règles métier par type de formulaire.
 */
final class ValidationMetierRegistry {

	/**
	 * Règles applicables à un type, ou null s'il n'en a aucune.
	 *
	 * @param string $type Type de formulaire, résolu côté serveur.
	 * @return ValidationMetier|null
	 */
	public static function for_type( string $type ): ?ValidationMetier {
		switch ( $type ) {
			case 'declaration_prealable':
				return new ValidationMetierDeclarationPrealable();

			case 'permis_construire':
				return new ValidationMetierPermisConstruire();

			default:
				// Conception et localisation n'ont pas de règle inter-champs :
				// leur cohérence tient entièrement dans la définition.
				return null;
		}
	}

	/**
	 * Un type a-t-il des règles métier ?
	 *
	 * @param string $type Type de formulaire.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return null !== self::for_type( $type );
	}
}
