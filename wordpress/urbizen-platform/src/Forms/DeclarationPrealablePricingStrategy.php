<?php
/**
 * Stratégie tarifaire du parcours « déclaration préalable ».
 *
 * Adaptateur mince : elle **ne recopie aucun montant** et ne décide d'aucune
 * règle. Tout vit dans {@see PricingDeclarationPrealable} ; cette classe ne
 * fait que l'exposer derrière le contrat générique, afin que le type serveur
 * `declaration_prealable` y soit associé sans qu'un second catalogue existe.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul tarifaire de la déclaration préalable, exposé comme stratégie.
 */
final class DeclarationPrealablePricingStrategy implements PricingStrategyContextuelle {

	/**
	 * Calcul sans contexte : socle et options d'abonnement seules.
	 *
	 * Ce chemin ne facture aucun projet supplémentaire ni supplément, faute de
	 * réponses à lire. Il n'est emprunté que par un appelant qui ignore le
	 * contrat contextuel ; le validateur, lui, passe toujours le contexte.
	 *
	 * @param array<int, mixed> $selection Identifiants d'options.
	 * @return array<string, mixed>
	 */
	public function calculate( array $selection ): array {
		return PricingDeclarationPrealable::compute( $selection, array() );
	}

	/**
	 * Calcul complet, à partir des réponses nettoyées.
	 *
	 * @param array<int, mixed>    $selection Identifiants d'options.
	 * @param array<string, mixed> $contexte  Réponses nettoyées.
	 * @return array<string, mixed>
	 */
	public function calculate_with_context( array $selection, array $contexte ): array {
		return PricingDeclarationPrealable::compute( $selection, $contexte );
	}

	/**
	 * Socle de référence : le tarif standard.
	 *
	 * Conservé pour le contrat historique. La vérification de cohérence du
	 * contrôleur passe par {@see self::accepts_base()}, le socle réel dépendant
	 * de la nature déclarée.
	 *
	 * @return int
	 */
	public function base(): int {
		return PricingDeclarationPrealable::NATURES['autre'];
	}

	/**
	 * Ce socle figure-t-il au catalogue ?
	 *
	 * @param int $base Socle calculé.
	 * @return bool
	 */
	public function accepts_base( int $base ): bool {
		return in_array( $base, PricingDeclarationPrealable::socles(), true );
	}
}
