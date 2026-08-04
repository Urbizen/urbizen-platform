<?php
/**
 * Règles métier du permis de construire.
 *
 * Aucune règle propre : les contrôles de cohérence inter-champs sont les mêmes
 * pour tous les parcours et vivent dans {@see ValidationMetierProjets}. Cette
 * classe ne fait que désigner le catalogue et le barème auxquels les appliquer.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Cohérence inter-champs du permis de construire.
 */
final class ValidationMetierPermisConstruire extends ValidationMetierProjets {

	/**
	 * @var class-string<CatalogueProjets>
	 */
	protected const CATALOGUE = CataloguePermisConstruire::class;

	/**
	 * @var class-string<PricingProjets>
	 */
	protected const BAREME = PricingPermisConstruire::class;
}
