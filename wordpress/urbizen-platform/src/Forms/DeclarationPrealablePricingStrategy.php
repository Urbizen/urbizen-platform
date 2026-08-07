<?php
/**
 * Stratégie tarifaire du parcours « déclaration préalable ».
 *
 * Adaptateur mince : elle **ne recopie aucun montant** et ne décide d'aucune
 * règle. Tout vit dans {@see PricingDeclarationPrealable} et {@see ProjetsPricingStrategy} ;
 * cette classe ne fait que désigner le barème, afin que le type serveur y soit
 * associé sans qu'un second catalogue existe.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul tarifaire de la déclaration préalable, exposé comme stratégie.
 */
final class DeclarationPrealablePricingStrategy extends ProjetsPricingStrategy {

	/**
	 * Barème employé.
	 *
	 * @var class-string<PricingProjets>
	 */
	protected const BAREME = PricingDeclarationPrealable::class;
}
