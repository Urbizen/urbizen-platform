<?php
/**
 * Nombres saisis par une personne, et non par une machine.
 *
 * En France, « huit mètres et demi » s'écrit `8,5`. Le formulaire le refusait :
 * un `input type="number"` rend une valeur vide sur une virgule, et PHP fait
 * pire en silence — `(float) "8,5"` vaut **8**, pas 8,5. Un bassin de 8,5 m
 * serait devenu un bassin de 8 m sans que rien ne le signale.
 *
 * D'où ce normaliseur, et d'où ses trois refus de complaisance :
 *
 * 1. **Aucune conversion implicite.** `(int)`, `(float)` et `is_numeric()` sont
 *    tous trop indulgents ou trop stricts au mauvais endroit. On analyse la
 *    chaîne, on la valide entièrement, et on rend un verdict explicite.
 * 2. **Une valeur vide reste vide.** Elle ne devient jamais `0`. La différence
 *    entre « le client n'a pas mesuré » et « le client a mesuré zéro » est
 *    exactement ce qu'un dossier d'urbanisme ne doit pas perdre.
 * 3. **Une valeur ambiguë est refusée, pas devinée.** `8,5.2` pourrait vouloir
 *    dire plusieurs choses ; aucune n'est assez probable pour être choisie à la
 *    place de la personne.
 *
 * L'issue est un tableau à trois clés — `etat`, `valeur`, `raison` — plutôt
 * qu'un nombre ou `false`. Rendre `false` obligerait l'appelant à distinguer
 * « absent » de « invalide » par un `===`, et cette distinction se perdrait au
 * premier refactor.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Normalisation des nombres décimaux et entiers saisis à la française.
 */
final class NombreLocalise {

	/** Le champ n'a pas été renseigné. Ce n'est pas une erreur. */
	public const ABSENT = 'absent';

	/** La valeur est exploitable, et `valeur` porte sa forme canonique. */
	public const VALIDE = 'valide';

	/** La chaîne ne décrit pas un nombre. */
	public const FORMAT = 'format';

	/** La valeur est un nombre, mais hors des bornes métier. */
	public const BORNE = 'borne';

	/**
	 * Décimales conservées à la persistance.
	 *
	 * Deux suffisent à tout ce que ce formulaire mesure : un bassin au
	 * centimètre près, une surface au centième de mètre carré. Au-delà, on
	 * persisterait du bruit de saisie en le faisant passer pour de la précision.
	 */
	public const DECIMALES = 2;

	/**
	 * Normalise une mesure décimale.
	 *
	 * @param mixed      $brut   Valeur reçue.
	 * @param float|null $min    Borne basse incluse, ou null.
	 * @param float|null $max    Borne haute incluse, ou null.
	 * @param bool       $strict Refuser zéro : une mesure renseignée à 0 n'est
	 *                           pas une mesure, c'est une case remplie par
	 *                           habitude.
	 * @return array{etat:string,valeur:float|null,raison:string}
	 */
	public static function decimal( $brut, ?float $min = null, ?float $max = null, bool $strict = false ): array {
		$chaine = self::chaine( $brut );

		if ( '' === $chaine ) {
			return self::issue( self::ABSENT, null, '' );
		}

		$canonique = self::canoniser( $chaine );

		if ( null === $canonique ) {
			return self::issue( self::FORMAT, null, 'nombre_illisible' );
		}

		$valeur = (float) $canonique;

		if ( ! is_finite( $valeur ) ) {
			return self::issue( self::FORMAT, null, 'nombre_non_fini' );
		}

		$valeur = round( $valeur, self::DECIMALES );

		if ( $strict && $valeur <= 0.0 ) {
			return self::issue( self::BORNE, null, 'mesure_nulle' );
		}

		if ( null !== $min && $valeur < $min ) {
			return self::issue( self::BORNE, null, 'sous_borne' );
		}

		if ( null !== $max && $valeur > $max ) {
			return self::issue( self::BORNE, null, 'au_dessus_borne' );
		}

		return self::issue( self::VALIDE, $valeur, '' );
	}

	/**
	 * Normalise un comptage entier.
	 *
	 * Un nombre de panneaux, de niveaux ou de logements n'a pas de décimales, et
	 * `3,5` n'y est pas une valeur à arrondir : c'est une saisie qui n'a pas de
	 * sens, et l'arrondir silencieusement inventerait une réponse.
	 *
	 * @param mixed    $brut Valeur reçue.
	 * @param int|null $min  Borne basse incluse, ou null.
	 * @param int|null $max  Borne haute incluse, ou null.
	 * @return array{etat:string,valeur:int|null,raison:string}
	 */
	public static function entier( $brut, ?int $min = 0, ?int $max = null ): array {
		$chaine = self::chaine( $brut );

		if ( '' === $chaine ) {
			return self::issue( self::ABSENT, null, '' );
		}

		if ( 1 !== preg_match( '/^[+-]?\d+$/', $chaine ) ) {
			return self::issue( self::FORMAT, null, 'entier_illisible' );
		}

		$valeur = (int) $chaine;

		if ( null !== $min && $valeur < $min ) {
			return self::issue( self::BORNE, null, 'sous_borne' );
		}

		if ( null !== $max && $valeur > $max ) {
			return self::issue( self::BORNE, null, 'au_dessus_borne' );
		}

		return self::issue( self::VALIDE, $valeur, '' );
	}

	/**
	 * Forme canonique d'une mesure, telle qu'elle sera persistée.
	 *
	 * `8.5` et non `8,5` : la virgule est une convention d'affichage, pas de
	 * stockage. Ce qui est enregistré doit se relire de la même façon quelle que
	 * soit la langue de celui qui le lit.
	 *
	 * @param float $valeur Valeur normalisée.
	 * @return string
	 */
	public static function canonique( float $valeur ): string {
		// `rtrim` retire les zéros inutiles : 34.00 se persiste « 34 », pas
		// « 34.00 » — une précision affichée qui n'existe pas est un mensonge.
		$texte = number_format( $valeur, self::DECIMALES, '.', '' );

		return str_contains( $texte, '.' ) ? rtrim( rtrim( $texte, '0' ), '.' ) : $texte;
	}

	/**
	 * Écriture française d'une valeur, pour l'affichage.
	 *
	 * @param float  $valeur Valeur normalisée.
	 * @param string $unite  Unité accolée, ou chaîne vide.
	 * @return string
	 */
	public static function afficher( float $valeur, string $unite = '' ): string {
		$texte = str_replace( '.', ',', self::canonique( $valeur ) );

		return '' === $unite ? $texte : $texte . ' ' . $unite;
	}

	/**
	 * Réduit une valeur reçue à une chaîne exploitable.
	 *
	 * Un tableau, un objet ou un booléen ne sont pas des nombres mal écrits :
	 * ce sont des valeurs qui ne viennent pas du formulaire.
	 *
	 * @param mixed $brut Valeur reçue.
	 * @return string
	 */
	private static function chaine( $brut ): string {
		if ( is_int( $brut ) || is_float( $brut ) ) {
			return is_finite( (float) $brut ) ? (string) $brut : '';
		}

		// Ni tableau, ni objet, ni booléen : ce ne sont pas des nombres mal
		// écrits mais des valeurs qui ne viennent pas du formulaire. On les
		// distingue d'une absence par un marqueur que `canoniser()` refusera.
		if ( ! is_string( $brut ) ) {
			return null === $brut ? '' : "\0";
		}

		// Les espaces, y compris l'insécable que produisent certains claviers et
		// la plupart des copier-coller depuis un tableur.
		return trim( str_replace( array( "\xc2\xa0", "\xe2\x80\xaf", ' ' ), '', $brut ) );
	}

	/**
	 * Convertit une écriture humaine en littéral décimal, ou rend null.
	 *
	 * Ce qui est refusé, et pourquoi :
	 *
	 * - **deux séparateurs** (`8,5,2`) : aucune lecture n'est évidente ;
	 * - **virgule ET point** (`8,5.2`) : la personne a mélangé deux conventions,
	 *   deviner laquelle prime reviendrait à choisir à sa place ;
	 * - **notation scientifique** (`1e3`) : personne ne mesure une piscine ainsi,
	 *   et l'accepter ouvrirait la porte à des valeurs énormes écrites court ;
	 * - **tout le reste** qui n'est pas un chiffre, un signe ou un séparateur.
	 *
	 * @param string $chaine Chaîne déjà débarrassée de ses espaces.
	 * @return string|null Littéral à point décimal, ou null si illisible.
	 */
	private static function canoniser( string $chaine ): ?string {
		$virgules = substr_count( $chaine, ',' );
		$points   = substr_count( $chaine, '.' );

		if ( $virgules > 1 || $points > 1 || ( $virgules > 0 && $points > 0 ) ) {
			return null;
		}

		$normalise = str_replace( ',', '.', $chaine );

		// Un seul motif, complet et ancré : pas de notation scientifique, pas de
		// caractère résiduel, pas de point isolé.
		if ( 1 !== preg_match( '/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $normalise ) ) {
			return null;
		}

		return $normalise;
	}

	/**
	 * Compose une issue.
	 *
	 * @param string    $etat   État.
	 * @param float|int|null $valeur Valeur normalisée.
	 * @param string    $raison Code technique, jamais montré au client.
	 * @return array{etat:string,valeur:float|int|null,raison:string}
	 */
	private static function issue( string $etat, $valeur, string $raison ): array {
		return array(
			'etat'   => $etat,
			'valeur' => $valeur,
			'raison' => $raison,
		);
	}
}
