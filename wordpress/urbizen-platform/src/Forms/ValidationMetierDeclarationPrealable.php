<?php
/**
 * Règles métier de la déclaration préalable.
 *
 * Ce que la définition sait déjà faire, et qui n'est pas repris ici : rejeter
 * une valeur hors liste fermée, un texte trop long, un nombre hors bornes. Ce
 * qu'elle ne sait pas faire, et qui est le sujet de cette classe : juger la
 * **cohérence entre les champs**.
 *
 * Quatre règles, et une raison pour chacune :
 *
 * 1. **Le projet principal doit être une nature connue.** Le champ est en liste
 *    fermée, donc la définition suffirait — sauf si le catalogue et la
 *    définition venaient à diverger. Le contrôle est redondant, volontairement.
 * 2. **Un projet supplémentaire ne répète pas le projet principal.** Sinon le
 *    même travail serait compté deux fois dans le dossier, une fois au socle et
 *    une fois à 100 €.
 * 3. **Pas deux fois la même nature en supplément.** Un dossier ne regroupe pas
 *    deux fois la même chose ; un doublon est soit une erreur d'interface, soit
 *    une requête forgée.
 * 4. **Un nombre de projets plausible.** La borne découle du catalogue :
 *    doublons interdits et principal exclu, on ne peut pas dépasser le nombre
 *    de natures moins une.
 *
 * Ces règles **refusent** au lieu d'écarter. Le catalogue tarifaire reste
 * défensif de son côté — il ne facture pas ce qu'il ne reconnaît pas — mais un
 * calcul prudent ne vaut pas acceptation : une demande incohérente ne doit pas
 * être enregistrée sous prétexte qu'elle a été correctement chiffrée.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Cohérence inter-champs d'une déclaration préalable.
 */
final class ValidationMetierDeclarationPrealable implements ValidationMetier {

	/**
	 * Contrôle les réponses nettoyées.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string>
	 */
	public function valider( array $clean ): array {
		$erreurs = array();

		$principal = $this->chaine( $clean['nature'] ?? null );

		// --- 1 · nature principale ---
		if ( '' === $principal || null === CatalogueDeclarationPrealable::libelle_nature( $principal ) ) {
			$erreurs['nature'] = 'projet_inconnu';
		}

		// --- 2 à 4 · projets supplémentaires ---
		$supplements = $clean['projets_supplementaires'] ?? array();

		if ( ! is_array( $supplements ) ) {
			// Une valeur scalaire là où une liste est attendue trahit une requête
			// qui n'a pas été composée par le formulaire.
			$erreurs['projets_supplementaires'] = 'projet_malforme';

			return $erreurs;
		}

		if ( count( $supplements ) > PricingDeclarationPrealable::max_projets_supplementaires() ) {
			$erreurs['projets_supplementaires'] = 'projets_trop_nombreux';

			return $erreurs;
		}

		$vus = array();

		foreach ( $supplements as $candidat ) {
			$nature = $this->chaine( $candidat );

			if ( '' === $nature || null === CatalogueDeclarationPrealable::libelle_nature( $nature ) ) {
				// Couvre d'un coup la valeur hors catalogue et l'identifiant mal
				// formé — majuscule, accent, espace, slash : rien de tout cela
				// n'est un identifiant canonique, donc rien de tout cela n'est
				// connu du catalogue.
				$erreurs['projets_supplementaires'] = 'projet_inconnu';

				return $erreurs;
			}

			if ( $nature === $principal ) {
				$erreurs['projets_supplementaires'] = 'projet_identique_au_principal';

				return $erreurs;
			}

			if ( isset( $vus[ $nature ] ) ) {
				$erreurs['projets_supplementaires'] = 'projet_en_double';

				return $erreurs;
			}

			$vus[ $nature ] = true;
		}

		return $erreurs;
	}

	/**
	 * Réduit une valeur reçue à une chaîne exploitable.
	 *
	 * Tout ce qui n'est pas une chaîne — tableau imbriqué, objet, nombre — rend
	 * la chaîne vide, donc un refus. On ne convertit rien : une valeur d'un
	 * autre type n'est pas une nature mal orthographiée, c'est une requête qui
	 * ne vient pas du formulaire.
	 *
	 * @param mixed $valeur Valeur reçue.
	 * @return string
	 */
	private function chaine( $valeur ): string {
		return is_string( $valeur ) ? trim( $valeur ) : '';
	}
}
