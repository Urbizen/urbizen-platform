<?php
/**
 * Adaptateur commun entre un barème de projets et le contrat tarifaire.
 *
 * Les stratégies des autorisations d'urbanisme ne décident de rien : tout vit
 * dans leur catalogue. Cette classe expose n'importe lequel d'entre eux derrière
 * {@see PricingStrategyContextuelle}, de sorte qu'ajouter un parcours ne demande
 * qu'une constante — et surtout, qu'aucune règle ne soit recopiée.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul tarifaire d'un parcours d'autorisation, exposé comme stratégie.
 */
abstract class ProjetsPricingStrategy implements PricingStrategyContextuelle {

	/**
	 * Classe du barème employé. Redéclarée par chaque stratégie concrète.
	 *
	 * @var class-string<PricingProjets>
	 */
	protected const BAREME = PricingProjets::class;

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
		$bareme = static::BAREME;

		return $bareme::compute( $selection, array() );
	}

	/**
	 * Calcul complet, à partir des réponses nettoyées.
	 *
	 * @param array<int, mixed>    $selection Identifiants d'options.
	 * @param array<string, mixed> $contexte  Réponses nettoyées.
	 * @return array<string, mixed>
	 */
	public function calculate_with_context( array $selection, array $contexte ): array {
		$bareme = static::BAREME;

		return $bareme::compute( $selection, $contexte );
	}

	/**
	 * Socle de référence, conservé pour le contrat historique.
	 *
	 * La vérification de cohérence du contrôleur passe par
	 * {@see self::accepts_base()} : le socle réel dépend de la nature déclarée,
	 * et un barème peut n'en produire aucun.
	 *
	 * @return int
	 */
	public function base(): int {
		$bareme = static::BAREME;
		$socles = $bareme::socles();

		return (int) ( $socles[0] ?? 0 );
	}

	/**
	 * Ce socle figure-t-il au catalogue ?
	 *
	 * `null` n'est pas une valeur manquante : c'est un socle volontairement non
	 * chiffré. Il n'est accepté que d'un barème qui comporte réellement une
	 * nature sur étude — sinon un tarif nul passerait pour légitime là où il
	 * trahirait un calcul défaillant.
	 *
	 * @param int|null $base Socle calculé.
	 * @return bool
	 */
	public function accepts_base( ?int $base ): bool {
		$bareme = static::BAREME;

		if ( null === $base ) {
			return $bareme::admet_sur_etude();
		}

		return in_array( $base, $bareme::socles(), true );
	}
}
