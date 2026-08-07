<?php
/**
 * Barème de la déclaration préalable.
 *
 * Toutes les natures sont chiffrables : une DP n'a pas de cas sur étude. « Autre »
 * y vaut le tarif standard, ce qui la distingue du permis de construire — l'écart
 * est un arbitrage produit, pas un oubli.
 *
 * Tout le calcul — projets supplémentaires, suppléments, ordre du récapitulatif,
 * plafonds dérivés — vit dans {@see PricingProjets}. Ce catalogue ne porte que
 * ses socles : c'est la seule chose qui distingue un barème d'un autre, et la
 * seule chose qu'un arbitrage produit fait bouger.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Socles de la déclaration préalable.
 */
final class PricingDeclarationPrealable extends PricingProjets {

	/**
	 * Socle par nature de projet principal, en euros.
	 *
	 * Aucune valeur nulle : toute déclaration préalable est chiffrable.
	 *
	 * @var array<string, int|null>
	 */
	public const NATURES = array(
		'extension'              => 549,
		'abri_annexe'            => 249,
		'garage'                 => 249,
		'carport'                => 249,
		'piscine'                => 249,
		'cloture_mur'            => 189,
		'modification_facade'    => 249,
		'ravalement'             => 249,
		'toiture'                => 249,
		'panneaux_solaires'      => 189,
		'changement_destination' => 249,
		'autre'                  => 249,
	);
}
