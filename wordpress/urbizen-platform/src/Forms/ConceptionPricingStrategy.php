<?php
/**
 * Stratégie tarifaire du parcours de conception.
 *
 * Adaptateur mince : elle **ne recopie aucun montant** et ne change aucune
 * règle. Le catalogue et le calcul historiques restent dans {@see Pricing}
 * (source canonique unique) ; cette classe ne fait que les exposer derrière le
 * contrat générique {@see PricingStrategy}, afin que le type serveur
 * « conception » y soit associé sans que le moteur générique ne connaisse le
 * catalogue.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul tarifaire de Conception, exposé comme stratégie.
 */
final class ConceptionPricingStrategy implements PricingStrategy {

	/**
	 * Recalcule le prix Conception, à l'identique de l'existant.
	 *
	 * @param array<int, string> $selection Identifiants d'options validés.
	 * @return array<string, mixed>
	 */
	public function calculate( array $selection ): array {
		return Pricing::compute( $selection );
	}

	/**
	 * Prix de base Conception (449 €), depuis le catalogue canonique.
	 *
	 * @return int
	 */
	public function base(): int {
		return Pricing::BASE;
	}
}
