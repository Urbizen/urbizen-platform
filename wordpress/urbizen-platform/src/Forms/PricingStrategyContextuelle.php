<?php
/**
 * Stratégie tarifaire dont le calcul dépend des réponses, pas seulement des
 * options cochées.
 *
 * Le contrat historique {@see PricingStrategy} suppose deux choses vraies pour
 * la conception, fausses pour les autorisations d'urbanisme :
 *
 * 1. **Un socle unique.** En conception, `base()` est une constante. En
 *    déclaration préalable, le socle dépend de la nature du projet : 189, 249
 *    ou 549 €. Un contrôleur qui compare le socle calculé à une constante
 *    rejetterait tout dossier qui n'est pas au tarif standard.
 * 2. **Un prix entièrement porté par des options.** Les projets supplémentaires
 *    d'une DP ne sont pas des cases à cocher : ils arrivent dans un champ
 *    répété. Le validateur les nettoie, mais ils ne produisent aucun
 *    `price_id`, donc la sélection seule ne suffit pas à les facturer.
 *
 * Cette interface ajoute donc deux capacités, sans rien retirer : le calcul
 * reçoit les **réponses déjà nettoyées** par le validateur, et le contrôleur
 * peut demander à la stratégie si un socle donné fait partie de ceux qu'elle
 * sait produire. La garde du contrôleur reste entière — elle interroge la
 * stratégie au lieu de comparer à une constante — et rien n'est jamais lu
 * depuis la requête.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Contrat des stratégies dont le socle varie selon les réponses.
 */
interface PricingStrategyContextuelle extends PricingStrategy {

	/**
	 * Calcule le prix à partir des options **et** des réponses validées.
	 *
	 * Le contexte est celui produit par {@see Validator::validate()} : des
	 * valeurs déjà nettoyées, bornées et conformes à la définition. Aucune
	 * donnée brute de requête n'y transite.
	 *
	 * @param array<int, mixed>    $selection Identifiants d'options retenus.
	 * @param array<string, mixed> $contexte  Réponses nettoyées.
	 * @return array<string, mixed>
	 */
	public function calculate_with_context( array $selection, array $contexte ): array;

	/**
	 * Ce socle fait-il partie de ceux que la stratégie sait produire ?
	 *
	 * Permet au contrôleur de vérifier la cohérence du prix persisté sans
	 * exiger un socle unique. Une valeur hors catalogue reste refusée.
	 *
	 * @param int $base Socle calculé.
	 * @return bool
	 */
	public function accepts_base( int $base ): bool;
}
