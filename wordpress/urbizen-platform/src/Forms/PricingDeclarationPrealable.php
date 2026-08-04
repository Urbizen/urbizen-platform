<?php
/**
 * Catalogue tarifaire serveur de la déclaration préalable.
 *
 * Source unique des montants pour le type `declaration_prealable`. Le
 * navigateur affiche une estimation — c'est une commodité d'interface — mais
 * elle n'entre ici sous aucune forme : ni total, ni prix unitaire, ni libellé
 * tarifaire reçus du client ne sont lus. Le serveur recalcule à partir des
 * seules réponses métier retenues par le validateur.
 *
 * Ce que le catalogue tient :
 *
 * 1. **Un socle par nature de projet principal.** Le montant dépend du projet
 *    déclaré, pas d'un tarif unique comme en conception. C'est pourquoi la
 *    stratégie associée implémente {@see PricingStrategyContextuelle} : la
 *    seule sélection d'options ne suffit pas à déterminer le socle.
 * 2. **+100 € par projet supplémentaire valide.** Valide signifie : nature
 *    connue du catalogue, distincte du projet principal, et non déjà comptée.
 *    Un doublon n'est pas facturé deux fois — il est écarté et consigné.
 * 3. **Deux suppléments d'option**, secteur Bâtiments de France et dépôt sur
 *    le guichet numérique, dont la présence est lue dans les réponses
 *    validées, jamais dans un montant annoncé.
 *
 * « Autre » vaut ici **249 €**, le tarif standard : c'est le comportement du
 * prototype validé. Il diverge du permis de construire, où « Autre » reste sur
 * étude. L'écart est délibéré à ce stade et signalé comme tel.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul du prix indicatif d'une déclaration préalable.
 */
final class PricingDeclarationPrealable {

	/**
	 * Socle par nature de projet principal, en euros.
	 *
	 * Les identifiants sont ceux déclarés en `price_id` dans la définition
	 * serveur. Un banc de contrat vérifie qu'ils correspondent exactement aux
	 * valeurs proposées par l'interface : une divergence fait échouer les tests
	 * plutôt que de produire un tarif silencieusement faux.
	 */
	public const NATURES = array(
		'extension'              => 549,
		'abri_annexe'            => 249,
		'garage'                 => 249,
		'carport'                => 249,
		'piscine'                => 249,
		'cloture_mur'            => 189,
		'modification_facade'    => 249,
		'ravalement'             => 249,
		'toiture'                => 249,
		'panneaux_solaires'      => 189,
		'changement_destination' => 249,
		'autre'                  => 249,
	);

	/**
	 * Montant ajouté par projet supplémentaire regroupé dans le même dossier.
	 */
	public const SUPPLEMENT_PROJET = 100;

	/**
	 * Supplément « secteur protégé / Bâtiments de France ».
	 */
	public const SUPPLEMENT_ABF = 80;

	/**
	 * Supplément « dépôt par Urbizen sur le guichet numérique ».
	 */
	public const SUPPLEMENT_DEPOT = 30;

	/**
	 * Plafond de projets supplémentaires acceptés.
	 *
	 * Un dossier réunissant plus de projets que le catalogue ne compte de
	 * natures est nécessairement forgé : les doublons étant écartés, on ne peut
	 * pas dépasser le nombre de natures moins le projet principal. Le plafond
	 * est explicite pour que le refus soit lisible, plutôt que de laisser un
	 * total absurde se construire.
	 */
	public const MAX_PROJETS_SUPPLEMENTAIRES = 11;

	/**
	 * Socles distincts que ce catalogue peut légitimement produire.
	 *
	 * @return array<int, int>
	 */
	public static function socles(): array {
		return array_values( array_unique( array_values( self::NATURES ) ) );
	}

	/**
	 * Calcule le prix à partir des identifiants retenus et du contexte validé.
	 *
	 * @param array<int, mixed>    $selection Identifiants d'options (`price_id`).
	 * @param array<string, mixed> $contexte  Réponses nettoyées par le validateur.
	 * @return array{
	 *     base:int,
	 *     options:array<int,array{id:string,price:int}>,
	 *     sur_devis:array<int,string>,
	 *     total:int,
	 *     devis_requis:bool,
	 *     ignores:array<int,string>
	 * }
	 */
	public static function compute( array $selection, array $contexte = array() ): array {
		$ignores = array();
		$nature  = null;

		// Une seule nature principale : le formulaire est en choix unique, et le
		// serveur ne se laisse pas imposer un panier. Au-delà du premier
		// identifiant connu, tout est écarté et consigné.
		foreach ( $selection as $brut ) {
			if ( ! is_string( $brut ) ) {
				$ignores[] = gettype( $brut );
				continue;
			}

			if ( ! isset( self::NATURES[ $brut ] ) ) {
				$ignores[] = $brut;
				continue;
			}

			if ( null === $nature ) {
				$nature = $brut;
				continue;
			}

			$ignores[] = $brut;
		}

		// Aucune nature reconnue : le socle vaut le tarif standard. Le validateur
		// exige déjà la nature, et un banc de contrat compare le catalogue à la
		// définition ; cette branche n'est atteinte que si les deux ont divergé.
		$base    = null === $nature ? self::NATURES['autre'] : self::NATURES[ $nature ];
		$options = array();
		$total   = $base;

		foreach ( self::projets_supplementaires( $contexte, $nature, $ignores ) as $projet ) {
			$options[] = array(
				'id'    => 'projet_supplementaire:' . $projet,
				'price' => self::SUPPLEMENT_PROJET,
			);

			$total += self::SUPPLEMENT_PROJET;
		}

		if ( self::option_active( $contexte, 'abf', 'oui' ) ) {
			$options[] = array(
				'id'    => 'secteur_abf',
				'price' => self::SUPPLEMENT_ABF,
			);

			$total += self::SUPPLEMENT_ABF;
		}

		if ( self::option_active( $contexte, 'depot_guichet', 'oui' ) ) {
			$options[] = array(
				'id'    => 'depot_guichet',
				'price' => self::SUPPLEMENT_DEPOT,
			);

			$total += self::SUPPLEMENT_DEPOT;
		}

		return array(
			'base'         => $base,
			'options'      => $options,
			'sur_devis'    => array(),
			'total'        => $total,
			'devis_requis' => false,
			'ignores'      => array_values( array_unique( $ignores ) ),
		);
	}

	/**
	 * Natures supplémentaires facturables, dans l'ordre du catalogue.
	 *
	 * Trois filtres, dans cet ordre : nature connue, distincte du projet
	 * principal, non déjà retenue. L'ordre du catalogue prime sur l'ordre de
	 * réception, pour que deux dossiers identiques produisent le même
	 * récapitulatif.
	 *
	 * @param array<string, mixed> $contexte  Réponses nettoyées.
	 * @param string|null          $principal Identifiant de la nature principale.
	 * @param array<int, string>   $ignores   Écarts, modifiés sur place.
	 * @return array<int, string>
	 */
	private static function projets_supplementaires( array $contexte, ?string $principal, array &$ignores ): array {
		$brut = $contexte['projets_supplementaires'] ?? array();

		if ( ! is_array( $brut ) ) {
			return array();
		}

		if ( count( $brut ) > self::MAX_PROJETS_SUPPLEMENTAIRES ) {
			$ignores[] = 'projets_supplementaires:trop_nombreux';

			return array();
		}

		$retenus = array();

		foreach ( $brut as $candidat ) {
			if ( ! is_string( $candidat ) || ! isset( self::NATURES[ $candidat ] ) ) {
				$ignores[] = 'projet_inconnu';
				continue;
			}

			if ( null !== $principal && $candidat === $principal ) {
				$ignores[] = 'projet_identique_au_principal:' . $candidat;
				continue;
			}

			if ( isset( $retenus[ $candidat ] ) ) {
				$ignores[] = 'projet_en_double:' . $candidat;
				continue;
			}

			$retenus[ $candidat ] = true;
		}

		return array_values(
			array_filter(
				array_keys( self::NATURES ),
				static fn( $id ) => isset( $retenus[ $id ] )
			)
		);
	}

	/**
	 * Une option est-elle active dans les réponses validées ?
	 *
	 * @param array<string, mixed> $contexte Réponses nettoyées.
	 * @param string               $champ    Nom du champ.
	 * @param string               $attendue Valeur qui active l'option.
	 * @return bool
	 */
	private static function option_active( array $contexte, string $champ, string $attendue ): bool {
		$valeur = $contexte[ $champ ] ?? null;

		if ( is_array( $valeur ) ) {
			return in_array( $attendue, array_map( 'strval', $valeur ), true );
		}

		return is_scalar( $valeur ) && $attendue === (string) $valeur;
	}
}
