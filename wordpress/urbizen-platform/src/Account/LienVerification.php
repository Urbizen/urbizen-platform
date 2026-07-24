<?php
/**
 * Fabrication et relecture de l'URL de vérification.
 *
 * La classe est **pure** : elle ne connaît ni WordPress, ni la requête
 * courante. L'adresse de base lui est remise par son appelant, qui est le seul
 * à savoir où `admin-post.php` se trouve. C'est ce qui la rend éprouvable sans
 * WordPress, et ce qui interdit qu'une URL se fabrique à deux endroits.
 *
 * **Le jeton voyage dans la chaîne de requête, et c'est assumé.** Le parcours
 * doit fonctionner sans JavaScript : un fragment `#` resterait côté navigateur
 * et ne serait lisible par aucun formulaire sans script. Le jeton apparaît
 * donc dans les journaux d'accès du serveur et de tout intermédiaire. Ce qui
 * borne le risque : 24 heures de validité, usage unique, invalidation à la
 * consommation, et le fait qu'une vérification ne donne aucune session.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

/**
 * URL de vérification.
 */
final class LienVerification {

	/**
	 * Nom de l'action `admin-post`.
	 */
	public const ACTION = 'urbizen_verification';

	/**
	 * Paramètre portant l'identifiant de compte.
	 */
	public const PARAM_COMPTE = 'c';

	/**
	 * Paramètre portant le jeton.
	 */
	public const PARAM_JETON = 't';

	/**
	 * Fabrique l'URL de vérification.
	 *
	 * @param string $base    URL de `admin-post.php`, sans chaîne de requête.
	 * @param int    $compte  Identifiant de compte.
	 * @param string $jeton   Jeton brut.
	 * @return string URL complète, ou chaîne vide si un argument est inutilisable.
	 */
	public static function pour( string $base, int $compte, string $jeton ): string {
		if ( '' === $base || $compte <= 0 || '' === $jeton ) {
			return '';
		}

		// Un jeton de forme inattendue ne fabrique pas d'URL : mieux vaut
		// aucune adresse qu'une adresse qui ne vérifiera jamais rien.
		if ( ! JetonVerification::forme_valide( $jeton ) ) {
			return '';
		}

		$separateur = ( false === strpos( $base, '?' ) ) ? '?' : '&';

		return $base . $separateur . http_build_query(
			array(
				'action'            => self::ACTION,
				self::PARAM_COMPTE  => $compte,
				self::PARAM_JETON   => $jeton,
			)
		);
	}

	/**
	 * Relit les paramètres d'une requête de vérification.
	 *
	 * Ne valide rien d'autre que la **forme** : ni le compte, ni le jeton ne
	 * sont interrogés contre le stockage. C'est `VerificationService` qui
	 * décide, et lui seul.
	 *
	 * @param array<string, mixed> $query Paramètres de requête.
	 * @return array{compte: int, jeton: string}|null
	 */
	public static function lire( array $query ): ?array {
		$brut_compte = $query[ self::PARAM_COMPTE ] ?? null;
		$brut_jeton  = $query[ self::PARAM_JETON ] ?? null;

		if ( ! is_scalar( $brut_compte ) || ! is_string( $brut_jeton ) ) {
			return null;
		}

		$compte = (string) $brut_compte;

		if ( ! ctype_digit( $compte ) || (int) $compte <= 0 ) {
			return null;
		}

		if ( ! JetonVerification::forme_valide( $brut_jeton ) ) {
			return null;
		}

		return array( 'compte' => (int) $compte, 'jeton' => $brut_jeton );
	}
}
