<?php
/**
 * Contrat des règles métier qu'une définition ne sait pas exprimer.
 *
 * Une définition juge chaque champ isolément : type, longueur, appartenance à
 * une liste fermée. Elle ne sait rien dire de ce qui lie deux champs entre eux
 * — qu'un projet supplémentaire ne peut pas répéter le projet principal, qu'une
 * liste ne peut pas contenir deux fois la même valeur, qu'un nombre d'entrées
 * doit rester plausible. Ces règles ne sont pas décoratives : sans elles, une
 * requête forgée passe la validation de forme, et le catalogue tarifaire se
 * contente de ne pas facturer ce qu'il ne comprend pas. La demande serait alors
 * **acceptée** avec un contenu incohérent.
 *
 * D'où cette étape : elle s'intercale entre la validation de forme et le calcul
 * du prix, et refuse la demande au lieu de la nettoyer en silence. Le refus est
 * corrigeable — aucune écriture n'a eu lieu — et porte des messages destinés à
 * une personne, pas des codes internes.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Règles métier applicables à un type de formulaire.
 */
interface ValidationMetier {

	/**
	 * Contrôle les réponses déjà nettoyées et rend les erreurs constatées.
	 *
	 * Reçoit la sortie de {@see Validator::validate()} : des valeurs conformes
	 * à la définition. Rend un tableau `champ => code`, vide si tout va bien.
	 * Les codes sont traduits par {@see ValidationMessages::message()} ; aucun
	 * message n'est composé ici, et aucune valeur reçue n'y est recopiée.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string>
	 */
	public function valider( array $clean ): array;
}
