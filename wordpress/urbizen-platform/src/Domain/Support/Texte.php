<?php
/**
 * Mesure du texte reçu, en points de code Unicode, **sans mbstring**.
 *
 * Deux règles gouvernent tout ce qui borne une longueur dans la plateforme —
 * la taille d'un mot de passe, celle d'une adresse : on valide d'abord que la
 * chaîne est de l'UTF-8 bien formé, puis on la mesure en **caractères** (points
 * de code), jamais en octets. « garçon12345 » fait douze octets mais onze
 * caractères ; les compter en octets accepterait à tort un mot de passe trop
 * court, et tronquerait une adresse au mauvais endroit.
 *
 * **Aucune dépendance à l'extension `mbstring`.** Elle n'est pas garantie dans
 * tout hébergement WordPress, et un appel direct à `mb_strlen()` ou
 * `mb_check_encoding()` lèverait une erreur fatale là où elle manque. PCRE, lui,
 * est toujours présent : le modificateur `u` valide l'UTF-8, et `/./us` compte
 * un point de code par correspondance. C'est la **seule** règle de mesure du
 * dépôt, partagée par le domaine et les services, pour qu'ils ne divergent
 * jamais.
 *
 * @package Urbizen\Platform\Domain\Support
 */

namespace Urbizen\Platform\Domain\Support;

/**
 * Validation et mesure UTF-8 par PCRE.
 */
final class Texte {

	/**
	 * La chaîne est-elle de l'UTF-8 bien formé ?
	 *
	 * Le motif vide avec le modificateur `u` échoue sur une séquence d'octets
	 * qui ne compose pas de l'UTF-8 valide : `preg_match()` rend alors `false`,
	 * pas `1`. On exige `1` — ni `0`, ni `false`.
	 *
	 * @param string $valeur Chaîne éprouvée.
	 * @return bool
	 */
	public static function est_utf8( string $valeur ): bool {
		return 1 === preg_match( '//u', $valeur );
	}

	/**
	 * Nombre de points de code, ou `-1` si la chaîne n'est pas de l'UTF-8 valide.
	 *
	 * `/./us` associe chaque point de code une fois — le `s` fait que `.` prend
	 * aussi le saut de ligne. Sur des octets mal formés, `preg_match_all()` rend
	 * `false` ; on le traduit en `-1`, un sentinel qu'un appelant compare sans
	 * jamais le prendre pour une longueur. La chaîne vide vaut `0`.
	 *
	 * @param string $valeur Chaîne mesurée.
	 * @return int Points de code, ou `-1` sur UTF-8 invalide.
	 */
	public static function longueur( string $valeur ): int {
		if ( '' === $valeur ) {
			return 0;
		}

		$compte = preg_match_all( '/./us', $valeur );

		return false === $compte ? -1 : $compte;
	}

	/**
	 * La chaîne est-elle un UTF-8 valide d'au moins `$minimum` caractères ?
	 *
	 * Regroupe les deux règles en une, pour que « douze caractères » se dise
	 * d'un seul appel, au même endroit, partout.
	 *
	 * @param string $valeur  Chaîne éprouvée.
	 * @param int    $minimum Nombre minimal de points de code.
	 * @return bool
	 */
	public static function au_moins( string $valeur, int $minimum ): bool {
		$longueur = self::longueur( $valeur );

		return $longueur >= 0 && $longueur >= $minimum;
	}

	/**
	 * La chaîne est-elle un UTF-8 valide d'au plus `$maximum` caractères ?
	 *
	 * @param string $valeur  Chaîne éprouvée.
	 * @param int    $maximum Nombre maximal de points de code.
	 * @return bool
	 */
	public static function au_plus( string $valeur, int $maximum ): bool {
		$longueur = self::longueur( $valeur );

		return $longueur >= 0 && $longueur <= $maximum;
	}
}
