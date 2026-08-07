<?php
/**
 * Barème du permis de construire.
 *
 * Cinq natures chiffrables, et une sur étude. « Autre » recouvre ici des projets
 * dont l'ampleur n'est pas bornée — un bâtiment d'activité, une opération mixte —
 * et pour lesquels annoncer un prix avant examen serait un engagement en l'air.
 * Son socle vaut donc `null`, et le mécanisme commun en tire un tarif sur étude
 * plutôt qu'un montant de repli.
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
 * Socles du permis de construire.
 */
final class PricingPermisConstruire extends PricingProjets {

	/**
	 * Socle par nature de projet principal, en euros.
	 *
	 * `autre` vaut `null` : le socle ne peut pas être arrêté sans examen du
	 * projet. Ce n'est pas zéro, et ce n'est pas une valeur manquante — c'est un
	 * refus explicite de chiffrer, que {@see PricingProjets::compute()} traduit en
	 * `pricing_status = sur_etude`.
	 *
	 * @var array<string, int|null>
	 */
	public const NATURES = array(
		'maison_individuelle'    => 849,
		'extension'              => 649,
		'surelevation'           => 649,
		'changement_destination' => 649,
		'annexe_garage'          => 449,
		'autre'                  => null,
	);
}
