<?php
/**
 * Règles métier de la déclaration préalable.
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
 * Cohérence inter-champs de la déclaration préalable.
 */
final class ValidationMetierDeclarationPrealable extends ValidationMetierProjets {

	/**
	 * @var class-string<CatalogueProjets>
	 */
	protected const CATALOGUE = CatalogueDeclarationPrealable::class;

	/**
	 * @var class-string<PricingProjets>
	 */
	protected const BAREME = PricingDeclarationPrealable::class;

	/**
	 * Les deux adresses sont exigées.
	 *
	 * Une déclaration préalable sans adresse de déclarant ou sans adresse de
	 * terrain n'est pas instruisable : la mairie ne saurait ni qui écrit, ni où.
	 * Quand la case « même adresse » est cochée, le terrain a déjà été
	 * reconstruit depuis le déclarant avant d'arriver ici — l'exiger reste donc
	 * juste, et referme la porte sur une charge qui l'aurait vidé après coup.
	 *
	 * @return array<int, string>
	 */
	protected function adresses_exigees(): array {
		return array( AdresseTerrain::DECLARANT, AdresseTerrain::TERRAIN );
	}
}
