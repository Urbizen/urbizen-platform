<?php
/**
 * Table de confiance : type de formulaire → catalogue de projets.
 *
 * Tout ce qui doit nommer un projet devant un humain — réponse JSON, accusé de
 * réception, notification interne — a besoin du catalogue **du type de la
 * demande**, pas d'un catalogue choisi à l'écriture du code. Sans ce registre,
 * chaque consommateur nommait la déclaration préalable en dur, et un permis de
 * construire se serait retrouvé avec des libellés vides : `libelle_nature()`
 * rend `null` hors catalogue, ce qui est le bon comportement mais ferait
 * disparaître le projet du récapitulatif sans rien signaler.
 *
 * Le type est toujours celui **résolu côté serveur** depuis la route, jamais une
 * valeur cliente. Un type inconnu ne produit aucun catalogue plutôt que de
 * retomber sur celui d'un autre parcours.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Résolution du catalogue de projets d'un type de formulaire.
 */
final class CatalogueRegistry {

	/**
	 * Catalogue d'un type, ou null si le type n'en a aucun.
	 *
	 * @param string $type Type de formulaire, résolu côté serveur.
	 * @return class-string<CatalogueProjets>|null
	 */
	public static function for_type( string $type ): ?string {
		switch ( $type ) {
			case 'declaration_prealable':
				return CatalogueDeclarationPrealable::class;

			case 'permis_construire':
				return CataloguePermisConstruire::class;

			default:
				// « conception » et « localisation » ne décrivent pas des natures
				// de projet au sens du catalogue : ils n'en ont pas.
				return null;
		}
	}

	/**
	 * Libellé client d'une nature, pour un type donné.
	 *
	 * @param string $type   Type de formulaire.
	 * @param string $nature Identifiant canonique.
	 * @return string|null
	 */
	public static function libelle_nature( string $type, string $nature ): ?string {
		$catalogue = self::for_type( $type );

		return null === $catalogue ? null : $catalogue::libelle_nature( $nature );
	}

	/**
	 * Libellé client d'un type de pièce, pour un type donné.
	 *
	 * @param string $type  Type de formulaire.
	 * @param string $piece Identifiant canonique.
	 * @return string|null
	 */
	public static function libelle_piece( string $type, string $piece ): ?string {
		$catalogue = self::for_type( $type );

		return null === $catalogue ? null : $catalogue::libelle_piece( $piece );
	}
}
