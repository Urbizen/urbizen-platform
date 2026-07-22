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
	 * Clé de métadonnée.
	 */
	public const META = '_urbizen_verif_envois';

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
}
