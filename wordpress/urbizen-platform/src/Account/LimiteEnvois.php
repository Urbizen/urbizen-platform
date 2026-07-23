<?php
/**
 * Quota d'émissions, porté par **une seule** métadonnée.
 *
 * Un tableau JSON de zéro à trois horodatages GMT, dans
 * `_urbizen_verif_envois`. La première version envisagée réservait une option
 * par créneau : cela multipliait les lignes à durée de vie mal définie, pour un
 * besoin qui tient dans un tableau de trois entiers.
 *
 * **La sûreté ne vient pas de cette classe, mais de son appelant** : toute
 * lecture-modification-écriture se fait sous `VerrouCompte`. Sans ce verrou,
 * deux émissions simultanées liraient le même tableau et l'une écraserait
 * l'autre — la mise à jour perdue que l'on cherche précisément à éviter. Cette
 * classe est donc pure : elle transforme des tableaux, elle ne persiste rien.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

/**
 * Règles de quota.
 */
final class LimiteEnvois {

	/**
	 * Clé du **miroir**, au format 0.11.0 : une liste d'horodatages.
	 *
	 * Elle n'est **jamais lue pour décider**. Elle n'existe que pour que du
	 * code 0.11.0, après un retour arrière, continue de trouver un quota. La
	 * lire en recours quand la source est illisible transformerait un état
	 * incompris en autorisation — l'inversion exacte que tout le reste écarte.
	 */
	public const META = '_urbizen_verif_envois';

	/**
	 * Clé de la **source de vérité** : une liste de `{a, e}`.
	 *
	 * `a` est l'horodatage du créneau, `e` l'identifiant de l'émission qui l'a
	 * consommé. L'identifiant vit dans le même enregistrement que l'effet :
	 * un marqueur séparé n'aurait fait que déplacer la fenêtre pendant
	 * laquelle une seconde confirmation décompte un second créneau.
	 */
	public const META_SOURCE = '_urbizen_verif_emissions';

	/**
	 * Nombre maximal d'émissions confirmées par fenêtre.
	 */
	public const MAX = 3;

	/**
	 * Fenêtre glissante, en secondes.
	 */
	public const FENETRE = 86400;

	/**
	 * Délai minimal entre deux émissions confirmées, en secondes.
	 */
	public const DELAI_MINIMAL = 60;

	/**
	 * Décode la métadonnée.
	 *
	 * **Une valeur corrompue est traitée comme pleine, jamais comme vide.**
	 * Considérer l'illisible comme « aucun envoi » élargirait les droits
	 * exactement là où l'on ne comprend plus l'état.
	 *
	 * @param string|null $brut Valeur stockée.
	 * @return array{horodatages: array<int, int>, corrompue: bool}
	 */
	public static function decoder( ?string $brut ): array {
		if ( null === $brut || '' === $brut ) {
			return array( 'horodatages' => array(), 'corrompue' => false );
		}

		$decode = json_decode( $brut, true );

		// Une LISTE est attendue. Un objet JSON — `{"a":1}` — se décode en
		// tableau associatif et passerait pour une liste d'un élément si l'on
		// se contentait d'itérer ses valeurs. Ce n'est pas notre format : on
		// le refuse plutôt que de l'interpréter.
		if ( ! is_array( $decode ) || ! array_is_list( $decode ) ) {
			return array( 'horodatages' => array(), 'corrompue' => true );
		}

		$propres = array();

		foreach ( $decode as $valeur ) {
			if ( ! is_int( $valeur ) && ! ( is_string( $valeur ) && ctype_digit( $valeur ) ) ) {
				return array( 'horodatages' => array(), 'corrompue' => true );
			}

			$propres[] = (int) $valeur;
		}

		if ( count( $propres ) > self::MAX ) {
			return array( 'horodatages' => array(), 'corrompue' => true );
		}

		sort( $propres );

		return array( 'horodatages' => $propres, 'corrompue' => false );
	}

	/**
	 * @param array<int, int> $horodatages Horodatages.
	 * @return string
	 */
	public static function encoder( array $horodatages ): string {
		sort( $horodatages );

		return (string) json_encode( array_values( $horodatages ) );
	}

	/**
	 * Retire les horodatages sortis de la fenêtre.
	 *
	 * @param array<int, int> $horodatages Horodatages.
	 * @param int             $maintenant  Horloge.
	 * @return array<int, int>
	 */
	public static function purger( array $horodatages, int $maintenant ): array {
		$limite = $maintenant - self::FENETRE;
		$restes = array();

		foreach ( $horodatages as $quand ) {
			if ( $quand > $limite ) {
				$restes[] = $quand;
			}
		}

		sort( $restes );

		return $restes;
	}

	/**
	 * Une nouvelle émission est-elle permise ?
	 *
	 * @param array{horodatages: array<int, int>, corrompue: bool} $etat       État décodé.
	 * @param int                                                  $maintenant Horloge.
	 * @return string Chaîne vide si permis, motif de refus sinon.
	 */
	public static function motif_de_refus( array $etat, int $maintenant ): string {
		if ( ! empty( $etat['corrompue'] ) ) {
			return 'quota_illisible';
		}

		$restes = self::purger( $etat['horodatages'], $maintenant );

		if ( count( $restes ) >= self::MAX ) {
			return 'quota_epuise';
		}

		if ( array() !== $restes ) {
			$dernier = max( $restes );

			if ( ( $maintenant - $dernier ) < self::DELAI_MINIMAL ) {
				return 'delai_minimal';
			}
		}

		return '';
	}

	/**
	 * Ajoute une émission confirmée.
	 *
	 * @param array<int, int> $horodatages Horodatages.
	 * @param int             $maintenant  Horloge.
	 * @return array<int, int>
	 */
	public static function confirmer( array $horodatages, int $maintenant ): array {
		$restes   = self::purger( $horodatages, $maintenant );
		$restes[] = $maintenant;

		sort( $restes );

		// Filet : jamais plus que le maximum, même si l'appelant s'égarait.
		if ( count( $restes ) > self::MAX ) {
			$restes = array_slice( $restes, -self::MAX );
		}

		return $restes;
	}

	// ==================================================================
	// SOURCE DE VÉRITÉ — `{a, e}`, seule lue pour décider.
	// ==================================================================

	/**
	 * Décode la source.
	 *
	 * **Absente n'est pas corrompue**, et la distinction porte tout le reste :
	 * absente déclenche l'amorçage depuis le miroir, corrompue ferme le compte
	 * à toute émission. Les confondre reviendrait soit à ouvrir sur un état
	 * illisible, soit à condamner un compte que l'on n'a simplement jamais
	 * migré.
	 *
	 * Au-delà de `MAX` entrées, l'état est déclaré corrompu. On ne tronque pas
	 * pour retomber sur quelque chose de lisible : tronquer choisirait quelles
	 * émissions oublier, et c'est exactement la décision que l'on n'a pas les
	 * moyens de prendre.
	 *
	 * @param string|null $brut Valeur stockée.
	 * @return array{entrees: array<int, array{a: int, e: string}>, corrompue: bool, absente: bool}
	 */
	public static function decoder_source( ?string $brut ): array {
		if ( null === $brut || '' === $brut ) {
			return array( 'entrees' => array(), 'corrompue' => false, 'absente' => true );
		}

		$decode = json_decode( $brut, true );

		if ( ! is_array( $decode ) || ! array_is_list( $decode ) ) {
			return array( 'entrees' => array(), 'corrompue' => true, 'absente' => false );
		}

		$propres = array();

		foreach ( $decode as $entree ) {
			if ( ! is_array( $entree ) || ! array_key_exists( 'a', $entree ) || ! array_key_exists( 'e', $entree ) ) {
				return array( 'entrees' => array(), 'corrompue' => true, 'absente' => false );
			}

			$quand = $entree['a'];

			if ( ! is_int( $quand ) && ! ( is_string( $quand ) && ctype_digit( $quand ) ) ) {
				return array( 'entrees' => array(), 'corrompue' => true, 'absente' => false );
			}

			if ( ! is_string( $entree['e'] ) ) {
				return array( 'entrees' => array(), 'corrompue' => true, 'absente' => false );
			}

			$propres[] = array( 'a' => (int) $quand, 'e' => $entree['e'] );
		}

		if ( count( $propres ) > self::MAX ) {
			return array( 'entrees' => array(), 'corrompue' => true, 'absente' => false );
		}

		usort(
			$propres,
			static function ( array $g, array $d ): int {
				return $g['a'] <=> $d['a'];
			}
		);

		return array( 'entrees' => $propres, 'corrompue' => false, 'absente' => false );
	}

	/**
	 * @param array<int, array{a: int, e: string}> $entrees Entrées.
	 * @return string
	 */
	public static function encoder_source( array $entrees ): string {
		usort(
			$entrees,
			static function ( array $g, array $d ): int {
				return $g['a'] <=> $d['a'];
			}
		);

		return (string) json_encode( array_values( $entrees ) );
	}

	/**
	 * Amorce la source depuis les horodatages hérités du miroir.
	 *
	 * Chaque horodatage devient `{a: t, e: ''}`. Un identifiant vide ne peut
	 * **jamais** correspondre : ces créneaux bornent donc le quota sans jamais
	 * autoriser une confirmation à se croire déjà faite. C'est la direction
	 * sûre — deviner un identifiant les rendrait reconnaissables, et une
	 * confirmation légitime serait alors ignorée.
	 *
	 * @param array<int, int> $horodatages Horodatages hérités.
	 * @return array<int, array{a: int, e: string}>
	 */
	public static function amorcer_depuis_miroir( array $horodatages ): array {
		$entrees = array();

		foreach ( $horodatages as $quand ) {
			$entrees[] = array( 'a' => (int) $quand, 'e' => '' );
		}

		return $entrees;
	}

	/**
	 * Horodatages dérivés de la source — ce que le miroir doit contenir.
	 *
	 * @param array<int, array{a: int, e: string}> $entrees Entrées.
	 * @return array<int, int>
	 */
	public static function horodatages_de( array $entrees ): array {
		$horodatages = array();

		foreach ( $entrees as $entree ) {
			$horodatages[] = (int) $entree['a'];
		}

		sort( $horodatages );

		return $horodatages;
	}

	/**
	 * L'émission figure-t-elle déjà dans la source ?
	 *
	 * **Un identifiant vide ne correspond jamais**, des deux côtés. Sans cette
	 * règle, un créneau hérité — amorcé avec `e: ''` — ferait passer une
	 * confirmation nouvelle pour un rejeu, et le créneau ne serait pas
	 * décompté.
	 *
	 * @param array<int, array{a: int, e: string}> $entrees     Entrées.
	 * @param string                               $emission_id Identifiant.
	 * @return bool
	 */
	public static function contient_emission( array $entrees, string $emission_id ): bool {
		if ( '' === $emission_id ) {
			return false;
		}

		foreach ( $entrees as $entree ) {
			if ( '' !== $entree['e'] && hash_equals( (string) $entree['e'], $emission_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Purge la source de ce qui est sorti de la fenêtre.
	 *
	 * @param array<int, array{a: int, e: string}> $entrees    Entrées.
	 * @param int                                  $maintenant Horloge.
	 * @return array<int, array{a: int, e: string}>
	 */
	public static function purger_source( array $entrees, int $maintenant ): array {
		$limite = $maintenant - self::FENETRE;
		$restes = array();

		foreach ( $entrees as $entree ) {
			if ( (int) $entree['a'] > $limite ) {
				$restes[] = $entree;
			}
		}

		usort(
			$restes,
			static function ( array $g, array $d ): int {
				return $g['a'] <=> $d['a'];
			}
		);

		return $restes;
	}

	/**
	 * Ajoute un créneau consommé par une émission nommée.
	 *
	 * @param array<int, array{a: int, e: string}> $entrees     Entrées.
	 * @param int                                  $maintenant  Horloge.
	 * @param string                               $emission_id Identifiant.
	 * @return array<int, array{a: int, e: string}>
	 */
	public static function ajouter_emission( array $entrees, int $maintenant, string $emission_id ): array {
		$restes   = self::purger_source( $entrees, $maintenant );
		$restes[] = array( 'a' => $maintenant, 'e' => $emission_id );

		usort(
			$restes,
			static function ( array $g, array $d ): int {
				return $g['a'] <=> $d['a'];
			}
		);

		if ( count( $restes ) > self::MAX ) {
			$restes = array_slice( $restes, -self::MAX );
		}

		return $restes;
	}

	/**
	 * Adapte la source à la forme attendue par `motif_de_refus()`.
	 *
	 * La règle de refus est **une seule**, et elle ne se duplique pas : la
	 * source fournit ses horodatages, l'état corrompu se propage tel quel.
	 *
	 * @param array{entrees: array<int, array{a: int, e: string}>, corrompue: bool, absente: bool} $source Source décodée.
	 * @return array{horodatages: array<int, int>, corrompue: bool}
	 */
	public static function etat_depuis_source( array $source ): array {
		return array(
			'horodatages' => self::horodatages_de( $source['entrees'] ),
			'corrompue'   => ! empty( $source['corrompue'] ),
		);
	}
}
