<?php
/**
 * Catalogue canonique du permis de construire.
 *
 * Même rôle que celui de la déclaration préalable, et volontairement la même
 * mécanique : seule la table des natures change. Les pièces, les préfixes et
 * tous les helpers viennent de {@see CatalogueProjets}.
 *
 * Les six natures sont celles des cartes du formulaire PC, sous leurs
 * identifiants canoniques. Les libellés lus par le client sont conservés au
 * caractère près — « Maison neuve », et non « Maison individuelle », alors que
 * l'identifiant est `maison_individuelle` : c'est précisément ce que permet la
 * séparation entre identifiant et libellé, et un banc de contrat vérifie que
 * les deux formulaires proposent exactement ces couples.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Natures de projet du permis de construire.
 */
final class CataloguePermisConstruire extends CatalogueProjets {

	/**
	 * Natures de projet : identifiant canonique → libellé lu par le client.
	 *
	 * L'ordre est celui des cartes du formulaire.
	 *
	 * @var array<string, string>
	 */
	public const NATURES = array(
		'maison_individuelle'    => 'Maison neuve',
		'extension'              => 'Extension',
		'annexe_garage'          => 'Annexe / garage',
		'surelevation'           => 'Surélévation',
		'changement_destination' => 'Changement de destination',
		'autre'                  => 'Autre',
	);
}
