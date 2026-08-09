<?php
/**
 * Règles métier communes aux autorisations d'urbanisme.
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
 * Les règles sont les mêmes d'un parcours à l'autre ; seuls le catalogue des
 * natures et le barème qui en dérive le plafond changent. Les écrire deux fois
 * aurait garanti qu'un correctif finisse par n'être appliqué qu'à l'un des deux.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Cohérence inter-champs d'une demande d'autorisation.
 */
abstract class ValidationMetierProjets implements ValidationMetier {

	/**
	 * Catalogue des natures. Redéclaré par chaque validateur concret.
	 *
	 * @var class-string<CatalogueProjets>
	 */
	protected const CATALOGUE = CatalogueProjets::class;

	/**
	 * Barème dont dérive le plafond de projets supplémentaires.
	 *
	 * @var class-string<PricingProjets>
	 */
	protected const BAREME = PricingProjets::class;

	/**
	 * Régime d'autorisation servi par ce parcours, au sens de
	 * {@see QualificationUrbanisme}. Chaîne vide : le parcours ne sert aucun
	 * régime — la conception, par exemple — et ne peut donc rien contredire.
	 *
	 * @var string
	 */
	protected const REGIME = '';

	/**
	 * Les adresses que ce parcours exige, par rôle.
	 *
	 * Vide par défaut : un parcours qui ne dit rien n'exige rien, et une adresse
	 * absente de la charge y reste un cas légitime — la conception sans terrain.
	 * Un parcours qui, lui, ne peut pas aboutir sans adresse le déclare, et
	 * l'absence devient alors une erreur plutôt qu'un silence.
	 *
	 * @return array<int, string> Rôles au sens de {@see AdresseTerrain}.
	 */
	protected function adresses_exigees(): array {
		return array();
	}

	/**
	 * Contrôle les réponses nettoyées.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string>
	 */
	public function valider( array $clean ): array {
		$catalogue = static::CATALOGUE;
		$bareme    = static::BAREME;
		$erreurs   = array();

		// --- 0 · les adresses ---
		// Elles ne dépendent d'aucune nature : elles se jugent d'abord. Un
		// parcours qui n'en déclare pas n'est pas concerné — la clé de mode est
		// alors absente de la charge nettoyée, et la règle ne s'applique pas.
		//
		// Les adresses que le parcours **exige** sont, elles, réclamées même
		// absentes : depuis que l'obligation ne repose plus sur `required` —
		// le validateur générique ne sait pas combiner « mode » et « même
		// adresse que le déclarant » — c'est ici qu'une charge ayant retiré le
		// mode pour passer entre les gouttes est rattrapée.
		$exigees = $this->adresses_exigees();

		foreach ( AdresseTerrain::toutes() as $adresse ) {
			$erreurs += $adresse->verifier( $clean, in_array( $adresse->role(), $exigees, true ) );
		}

		$principal = $this->chaine( $clean['nature'] ?? null );

		// --- 1 · nature principale ---
		if ( '' === $principal || null === $catalogue::libelle_nature( $principal ) ) {
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

		if ( count( $supplements ) > $bareme::max_projets_supplementaires() ) {
			$erreurs['projets_supplementaires'] = 'projets_trop_nombreux';

			return $erreurs;
		}

		$vus = array();

		foreach ( $supplements as $candidat ) {
			$nature = $this->chaine( $candidat );

			if ( '' === $nature || null === $catalogue::libelle_nature( $nature ) ) {
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

		$erreurs += $this->regime_incompatible( $clean );

		return $erreurs;
	}

	/**
	 * Le régime déclaré est-il manifestement incompatible avec le projet décrit ?
	 *
	 * Le navigateur n'est pas une barrière. Avant ce contrôle, une extension de
	 * soixante mètres carrés traversait tout le formulaire de déclaration
	 * préalable sans qu'une seule vérification ne s'y oppose — `sp_creee` n'avait
	 * qu'un `min: 0`, et aucune règle métier ne regardait les surfaces. Le
	 * dossier partait en mairie sous un régime que le Code de l'urbanisme ne
	 * permettait pas.
	 *
	 * Ce contrôle ne rejette une incertitude que lorsqu'elle provient d'une donnée
	 * déterminante que le formulaire sait recueillir pour cette nature. Retirer
	 * les surfaces d'une extension ou la couverture d'une piscine ne doit pas
	 * devenir un contournement trivial. Les incertitudes extérieures au formulaire
	 * (zone, protection, qualification locale) restent, elles, non bloquantes.
	 * Une conclusion certaine opposée au régime du formulaire arrête toujours la
	 * soumission.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string>
	 */
	private function regime_incompatible( array $clean ): array {
		$regime = static::REGIME;

		if ( '' === $regime ) {
			return array();
		}

		$donnees = $this->donnees_qualification( $clean );
		$verdict = QualificationUrbanisme::qualifier( $donnees );

		if ( QualificationUrbanisme::A_CONFIRMER === $verdict['status'] ) {
			$obligatoires = array(
				'extension' => array( 'sp_creee', 'emprise_creee' ),
				'piscine'   => array( 'bassin_m2', 'couverte', 'hauteur_couverture_m' ),
			);
			$determinants_formulaire = $obligatoires[ $donnees['projet'] ?? '' ] ?? array();
			$manquants               = array_intersect( $verdict['missing'], $determinants_formulaire );

			if ( array() !== $manquants ) {
				$instruction = 'piscine' === ( $donnees['projet'] ?? '' )
					? __( 'Renseignez la surface du bassin, indiquez s’il sera couvert et, le cas échéant, la hauteur de la couverture.', 'urbizen-platform' )
					: __( 'Renseignez la surface de plancher créée et l’emprise au sol créée.', 'urbizen-platform' );

				return array(
					'regime' => sprintf(
						/* translators: %s: instruction propre à la nature du projet */
						__( 'Les données nécessaires pour vérifier la formalité sont incomplètes ou invalides. %s', 'urbizen-platform' ),
						$instruction
					),
				);
			}

			return array();
		}

		if ( QualificationUrbanisme::AUCUNE === $verdict['status'] ) {
			return array(
				'regime' => __( 'Les caractéristiques indiquées ne justifient pas ce formulaire d’autorisation. Urbizen reprend contact pour vérifier les règles locales avant tout dépôt.', 'urbizen-platform' ),
			);
		}

		if ( QualificationUrbanisme::DP !== $verdict['status'] && QualificationUrbanisme::PCMI !== $verdict['status'] ) {
			return array();
		}

		if ( $verdict['status'] === $regime ) {
			return array();
		}

		$vers = QualificationUrbanisme::PCMI === $verdict['status']
			? __( 'un permis de construire', 'urbizen-platform' )
			: __( 'une déclaration préalable', 'urbizen-platform' );

		return array(
			'regime' => sprintf(
				/* translators: 1: régime déterminé, 2: motif, 3: article du code de l'urbanisme */
				__( 'D’après les surfaces indiquées, ce projet relève %1$s. %2$s (%3$s) Urbizen reprend contact pour vous orienter vers le bon dossier.', 'urbizen-platform' ),
				$vers,
				$verdict['reason'],
				(string) $verdict['rule']
			),
		);
	}

	/**
	 * Traduit une charge de formulaire en données de qualification.
	 *
	 * Les noms diffèrent de part et d'autre : le formulaire parle de `nature`,
	 * le moteur de `projet`. Cette table est le seul endroit qui les rapproche.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, mixed>
	 */
	private function donnees_qualification( array $clean ): array {
		$natures = array(
			'extension'           => 'extension',
			'surelevation'        => 'extension',
			'garage'              => 'garage',
			'annexe_garage'       => 'garage',
			'abri_annexe'         => 'abri',
			'carport'             => 'pergola',
			'piscine'             => 'piscine',
			'modification_facade' => 'facade',
			'ravalement'          => 'facade',
			'toiture'             => 'toiture',
			'panneaux_solaires'   => 'solaire',
			'changement_destination' => 'transformation',
			'maison_individuelle' => 'maison',
			'autre'               => 'autre',
		);

		$nature = $this->chaine( $clean['nature'] ?? '' );

		if ( ! isset( $natures[ $nature ] ) ) {
			return array();
		}

		$donnees = array( 'projet' => $natures[ $nature ] );

		foreach ( array( 'sp_creee', 'sp_totale', 'emprise_creee' ) as $champ ) {
			if ( array_key_exists( $champ, $clean ) && '' !== $clean[ $champ ] ) {
				$donnees[ $champ ] = $clean[ $champ ];
			}
		}

		if ( array_key_exists( 'surface_bassin_m2', $clean ) && '' !== $clean['surface_bassin_m2'] ) {
			$donnees['bassin_m2'] = $clean['surface_bassin_m2'];
		}

		if ( array_key_exists( 'presence_abri_piscine', $clean ) ) {
			if ( 'oui' === $clean['presence_abri_piscine'] ) {
				$donnees['couverte'] = true;
			} elseif ( 'non' === $clean['presence_abri_piscine'] ) {
				$donnees['couverte'] = false;
			}
		}

		if ( array_key_exists( 'hauteur_abri_m', $clean ) && '' !== $clean['hauteur_abri_m'] ) {
			$donnees['hauteur_couverture_m'] = $clean['hauteur_abri_m'];
		}

		return $donnees;
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
