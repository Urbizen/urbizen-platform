<?php
/**
 * Stratégie tarifaire du parcours « permis de construire ».
 *
 * Adaptateur mince : elle **ne recopie aucun montant** et ne décide d'aucune
 * règle. Tout vit dans {@see PricingPermisConstruire} et {@see ProjetsPricingStrategy} ;
 * cette classe ne fait que désigner le barème, afin que le type serveur y soit
 * associé sans qu'un second catalogue existe.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul tarifaire du permis de construire, exposé comme stratégie.
 */
final class PermisConstruirePricingStrategy extends ProjetsPricingStrategy {

	/**
	 * Barème employé.
	 *
	 * @var class-string<PricingProjets>
	 */
	protected const BAREME = PricingPermisConstruire::class;
}
