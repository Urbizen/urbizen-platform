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

	/**
	 * Les deux adresses sont exigées.
	 *
	 * Un permis de construire sans adresse de déclarant ou sans adresse de
	 * terrain n'est pas instruisable : la mairie ne saurait ni qui écrit, ni où
	 * construire.
	 *
	 * **Le terrain est exigé y compris quand la case « même adresse » est
	 * cochée**, et ce n'est pas une contradiction : à ce stade, il a déjà été
	 * reconstruit depuis le déclarant validé par le contrôleur. L'exiger reste
	 * donc juste, et referme la porte sur une charge qui l'aurait vidé après la
	 * reconstruction. Le seul cas où l'exigence tomberait — un parcours qui ne
	 * pose pas d'adresse du tout — ne concerne pas le permis.
	 *
	 * @return array<int, string>
	 */
	protected function adresses_exigees(): array {
		return array( AdresseTerrain::DECLARANT, AdresseTerrain::TERRAIN );
	}
}
