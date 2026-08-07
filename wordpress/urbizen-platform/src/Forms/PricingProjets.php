<?php
/**
 * Socle commun du calcul tarifaire des autorisations d'urbanisme.
 *
 * Les règles sont les mêmes pour la déclaration préalable et le permis de
 * construire : un socle par nature de projet principal, un montant fixe par
 * projet supplémentaire regroupé, deux suppléments à cocher. Seule la table des
 * socles diffère. Le calcul vit donc ici une seule fois.
 *
 * **Le tarif sur étude est traité par le mécanisme, pas par une exception.** Un
 * socle vaut `null` quand la nature ne peut pas être chiffrée sans examen — le
 * « Autre » du permis de construire. Dans ce cas :
 *
 * - `base` vaut `null` et `total` vaut `null`, mais **les deux clés existent** :
 *   un total absent et un total volontairement non chiffré ne veulent pas dire
 *   la même chose, et les gardes en aval doivent pouvoir les distinguer. C'est
 *   pourquoi elles emploient `array_key_exists()` et non `isset()` ;
 * - `pricing_status` vaut `sur_etude` ;
 * - les suppléments restent listés **et chiffrés**, parce qu'ils sont connus.
 *   Ce qui ne peut pas être connu, c'est leur somme avec un socle qui n'existe
 *   pas encore. Additionner les seuls suppléments produirait un « total » de
 *   180 € pour un dossier qui en coûtera plusieurs centaines : un chiffre faux
 *   est pire qu'une absence de chiffre.
 *
 * Aucun montant de repli n'est inventé, nulle part.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul du prix indicatif d'une autorisation d'urbanisme.
 */
abstract class PricingProjets {

	/**
	 * Socle par nature de projet principal, en euros ; `null` pour sur étude.
	 *
	 * Redéclarée par chaque catalogue tarifaire concret. Les identifiants sont
	 * ceux déclarés en `price_id` dans la définition serveur. Un banc de contrat
	 * vérifie qu'ils correspondent exactement aux valeurs proposées par
	 * l'interface : une divergence fait échouer les tests plutôt que de produire
	 * un tarif silencieusement faux.
	 *
	 * @var array<string, int|null>
	 */
	public const NATURES = array();

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
	 * Statut d'un tarif entièrement chiffré.
	 */
	public const STATUT_ESTIME = 'estime';

	/**
	 * Statut d'un tarif dont le socle ne peut pas être chiffré sans examen.
	 */
	public const STATUT_SUR_ETUDE = 'sur_etude';

	/**
	 * Plafond de projets supplémentaires acceptés.
	 *
	 * La limite n'est pas un chiffre choisi : elle **découle** du catalogue.
	 * Les doublons étant interdits et le projet principal exclu des
	 * suppléments, un dossier ne peut pas réunir plus de natures distinctes
	 * qu'il n'en existe, moins celle du projet principal. Toute liste plus
	 * longue est nécessairement forgée.
	 *
	 * La dériver plutôt que l'écrire évite qu'une nature ajoutée un jour au
	 * catalogue laisse derrière elle un plafond devenu faux.
	 *
	 * @return int
	 */
	public static function max_projets_supplementaires(): int {
		return count( static::NATURES ) - 1;
	}

	/**
	 * Socles chiffrés distincts que ce catalogue peut légitimement produire.
	 *
	 * Les natures sur étude n'y figurent pas : elles ne produisent aucun socle,
	 * et la garde du contrôleur les reconnaît à leur `base` nulle.
	 *
	 * @return array<int, int>
	 */
	public static function socles(): array {
		$chiffres = array_filter( array_values( static::NATURES ), static fn( $v ) => null !== $v );

		return array_values( array_unique( $chiffres ) );
	}

	/**
	 * Ce catalogue comporte-t-il au moins une nature sur étude ?
	 *
	 * @return bool
	 */
	public static function admet_sur_etude(): bool {
		return in_array( null, array_values( static::NATURES ), true );
	}

	/**
	 * Socle par défaut, employé quand aucune nature n'a pu être reconnue.
	 *
	 * `autre` quand il existe, faute de quoi la première nature du catalogue.
	 * Cette branche n'est atteinte que si le catalogue et la définition ont
	 * divergé — le validateur exige la nature, et un banc de contrat compare les
	 * deux listes.
	 *
	 * @return string
	 */
	protected static function nature_par_defaut(): string {
		return array_key_exists( 'autre', static::NATURES )
			? 'autre'
			: (string) array_key_first( static::NATURES );
	}

	/**
	 * Calcule le prix à partir des identifiants retenus et du contexte validé.
	 *
	 * @param array<int, mixed>    $selection Identifiants d'options (`price_id`).
	 * @param array<string, mixed> $contexte  Réponses nettoyées par le validateur.
	 * @return array{
	 *     base:int|null,
	 *     options:array<int,array{id:string,price:int}>,
	 *     sur_devis:array<int,string>,
	 *     total:int|null,
	 *     pricing_status:string,
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

			if ( ! array_key_exists( $brut, static::NATURES ) ) {
				$ignores[] = $brut;
				continue;
			}

			if ( null === $nature ) {
				$nature = $brut;
				continue;
			}

			$ignores[] = $brut;
		}

		$retenue = null === $nature ? static::nature_par_defaut() : $nature;
		$base    = static::NATURES[ $retenue ];

		// Sur étude : le socle n'existe pas encore. Les suppléments, eux, sont
		// connus et se chiffrent — mais rien ne les additionne à un socle absent.
		$sur_etude = null === $base;
		$options   = array();
		$total     = $sur_etude ? null : $base;

		foreach ( static::projets_supplementaires( $contexte, $nature, $ignores ) as $projet ) {
			$options[] = array(
				'id'    => 'projet_supplementaire:' . $projet,
				'price' => static::SUPPLEMENT_PROJET,
			);

			if ( ! $sur_etude ) {
				$total += static::SUPPLEMENT_PROJET;
			}
		}

		foreach ( array( 'abf' => 'secteur_abf', 'depot_guichet' => 'depot_guichet' ) as $champ => $id ) {
			if ( ! static::option_active( $contexte, $champ, 'oui' ) ) {
				continue;
			}

			$montant   = 'abf' === $champ ? static::SUPPLEMENT_ABF : static::SUPPLEMENT_DEPOT;
			$options[] = array(
				'id'    => $id,
				'price' => $montant,
			);

			if ( ! $sur_etude ) {
				$total += $montant;
			}
		}

		return array(
			'base'           => $base,
			'options'        => $options,
			'sur_devis'      => array(),
			'total'          => $total,
			'pricing_status' => $sur_etude ? static::STATUT_SUR_ETUDE : static::STATUT_ESTIME,
			'devis_requis'   => $sur_etude,
			'ignores'        => array_values( array_unique( $ignores ) ),
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
	protected static function projets_supplementaires( array $contexte, ?string $principal, array &$ignores ): array {
		$brut = $contexte['projets_supplementaires'] ?? array();

		if ( ! is_array( $brut ) ) {
			return array();
		}

		if ( count( $brut ) > static::max_projets_supplementaires() ) {
			$ignores[] = 'projets_supplementaires:trop_nombreux';

			return array();
		}

		$retenus = array();

		foreach ( $brut as $candidat ) {
			if ( ! is_string( $candidat ) || ! array_key_exists( $candidat, static::NATURES ) ) {
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
				array_keys( static::NATURES ),
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
	protected static function option_active( array $contexte, string $champ, string $attendue ): bool {
		$valeur = $contexte[ $champ ] ?? null;

		if ( is_array( $valeur ) ) {
			return in_array( $attendue, array_map( 'strval', $valeur ), true );
		}

		return is_scalar( $valeur ) && $attendue === (string) $valeur;
	}
}
