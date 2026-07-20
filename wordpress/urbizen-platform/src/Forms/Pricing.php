<?php
/**
 * Catalogue tarifaire serveur de la conception de plans.
 *
 * Source unique de vérité des montants. Le navigateur peut afficher un total
 * pour informer le visiteur ; il n'en est jamais la source. Un montant reçu
 * d'un formulaire est ignoré sans exception : seuls les identifiants d'options
 * sont lus, et le total est recalculé ici.
 *
 * Trois règles commerciales sont exécutoires dans ce fichier :
 *
 * 1. `pack_ftc` **remplace** `facades`, `toiture` et `coupe`. Un panier
 *    contenant le pack et les trois options individuelles vaut le prix du pack,
 *    jamais leur somme.
 * 2. Les prestations sur devis ne sont **jamais** additionnées. Elles lèvent un
 *    indicateur et sortent du calcul.
 * 3. La remise de 200 € sur un futur permis de construire n'existe pas ici. Ce
 *    n'est pas une réduction du prix de la conception mais un avantage sur une
 *    prestation ultérieure : aucune fonction de cette classe ne la soustrait,
 *    et aucun montant n'en est dérivé.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Calcul du prix indicatif d'une demande de conception.
 */
final class Pricing {

	/**
	 * Prix de la prestation de base, en euros.
	 */
	public const BASE = 449;

	/**
	 * Options chiffrées, en euros.
	 *
	 * `modifs_sup` figure au catalogue mais n'est pas exposée dans la
	 * définition initiale : la série supplémentaire se propose à la livraison,
	 * quand le besoin est constaté, et non au moment de la commande.
	 *
	 * @var array<string, int>
	 */
	public const OPTIONS = array(
		'facades'    => 149,
		'toiture'    => 99,
		'coupe'      => 99,
		'pack_ftc'   => 299,
		'masse'      => 149,
		'vue3d'      => 149,
		'modifs_sup' => 99,
	);

	/**
	 * Options remplacées par le pack.
	 *
	 * @var array<int, string>
	 */
	public const PACK_REMPLACE = array( 'facades', 'toiture', 'coupe' );

	/**
	 * Identifiant du pack.
	 */
	public const PACK = 'pack_ftc';

	/**
	 * Prestations non chiffrables automatiquement.
	 *
	 * @var array<int, string>
	 */
	public const SUR_DEVIS = array( 'insertion3d', 'complexe', 'particulier' );

	/**
	 * Montant déduit d'un futur permis de construire, en euros.
	 *
	 * Constante d'affichage exclusivement. Elle n'entre dans aucun calcul de
	 * cette classe — c'est délibéré et cela doit le rester.
	 */
	public const REMISE_PERMIS_FUTUR = 200;

	/**
	 * Calcule le prix indicatif à partir des seuls identifiants reçus.
	 *
	 * @param array<int, mixed> $selection Identifiants d'options.
	 * @return array{
	 *     base:int,
	 *     options:array<int,array{id:string,price:int}>,
	 *     sur_devis:array<int,string>,
	 *     total:int,
	 *     devis_requis:bool,
	 *     ignores:array<int,string>
	 * }
	 */
	public static function compute( array $selection ): array {
		$ids       = array();
		$sur_devis = array();
		$ignores   = array();

		foreach ( $selection as $brut ) {
			if ( ! is_string( $brut ) ) {
				$ignores[] = gettype( $brut );
				continue;
			}

			if ( in_array( $brut, self::SUR_DEVIS, true ) ) {
				$sur_devis[ $brut ] = true;
				continue;
			}

			if ( ! isset( self::OPTIONS[ $brut ] ) ) {
				$ignores[] = $brut;
				continue;
			}

			$ids[ $brut ] = true;
		}

		// Le pack tient lieu des trois options qu'il comprend. La suppression a
		// lieu **avant** le calcul : le total ne peut donc pas les cumuler.
		if ( isset( $ids[ self::PACK ] ) ) {
			foreach ( self::PACK_REMPLACE as $remplacee ) {
				unset( $ids[ $remplacee ] );
			}
		}

		$options = array();
		$total   = self::BASE;

		// L'ordre du catalogue prime sur l'ordre de réception : deux paniers
		// identiques produisent le même récapitulatif, quel que soit l'ordre
		// des cases cochées par le visiteur.
		foreach ( self::OPTIONS as $id => $prix ) {
			if ( ! isset( $ids[ $id ] ) ) {
				continue;
			}

			$options[] = array(
				'id'    => $id,
				'price' => $prix,
			);

			$total += $prix;
		}

		$sur_devis = array_values(
			array_filter( self::SUR_DEVIS, static fn( $id ) => isset( $sur_devis[ $id ] ) )
		);

		return array(
			'base'         => self::BASE,
			'options'      => $options,
			'sur_devis'    => $sur_devis,
			'total'        => $total,
			'devis_requis' => array() !== $sur_devis,
			'ignores'      => array_values( array_unique( $ignores ) ),
		);
	}

	/**
	 * Identifiants chiffrés connus du catalogue.
	 *
	 * @return array<int, string>
	 */
	public static function known_ids(): array {
		return array_keys( self::OPTIONS );
	}

	/**
	 * Prix d'une option, ou null si l'identifiant est inconnu ou sur devis.
	 *
	 * @param string $id Identifiant.
	 * @return int|null
	 */
	public static function price( string $id ): ?int {
		return self::OPTIONS[ $id ] ?? null;
	}
}
