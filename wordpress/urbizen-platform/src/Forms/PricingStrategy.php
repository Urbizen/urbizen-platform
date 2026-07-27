<?php
/**
 * Stratégie tarifaire d'un formulaire.
 *
 * Le prix est **toujours** recalculé côté serveur, à partir des seuls
 * identifiants d'options retenus (jamais d'un montant transmis par le
 * navigateur). Chaque type de formulaire commercial fournit sa propre stratégie
 * — son catalogue, ses règles — derrière ce contrat générique. Le moteur de
 * validation ne connaît aucun catalogue : il extrait la sélection d'options et
 * délègue le calcul à la stratégie résolue depuis le **type serveur**.
 *
 * L'implémentation ne lit aucune superglobale, ne choisit aucune classe depuis
 * une donnée client et n'assouplit jamais la validation.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Contrat de calcul tarifaire serveur.
 */
interface PricingStrategy {

	/**
	 * Recalcule le prix à partir des seuls identifiants d'options retenus.
	 *
	 * @param array<int, string> $selection Identifiants d'options validés.
	 * @return array<string, mixed> Résultat tarifaire ({base:int, options, sur_devis, total:int, devis_requis:bool, ignores}).
	 */
	public function calculate( array $selection ): array;

	/**
	 * Prix de base de cette stratégie, en euros.
	 *
	 * Sert au contrôle de cohérence serveur : le montant persisté doit reposer
	 * sur ce socle, jamais sur une valeur cliente.
	 *
	 * @return int
	 */
	public function base(): int;
}
