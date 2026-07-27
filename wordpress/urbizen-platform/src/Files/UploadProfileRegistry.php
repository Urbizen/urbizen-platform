<?php
/**
 * Registre serveur des profils d'upload, par type de formulaire.
 *
 * Le pipeline de soumission résout le **type depuis la route serveur** (jamais
 * depuis `$_POST`), puis demande ici le profil autorisé. La table est **privée,
 * immuable, écrite en dur** : le navigateur ne choisit jamais un profil, ne
 * transmet jamais un `upload_profile`, un `block` ou un nom de classe qui
 * sélectionnerait une politique. Un type inconnu n'a **aucun** profil (`null`) —
 * l'appelant en fait un refus sûr, jamais un « tout est autorisé ».
 *
 * Ce registre vit dans `Files` et ne dépend **d'aucun** espace de noms métier
 * (Conception, DP, PC, CERFA). Il n'associe qu'une **chaîne** de type à des
 * bornes ; le profil « conception » réutilise à l'identique les constantes de
 * {@see UploadPolicy} — source unique, aucune divergence.
 *
 * Un seul profil commercial ouvre les dépôts : `conception`. `localisation` ne
 * passe par aucune route de soumission commerciale : il n'a **aucun** profil
 * (`null`), sans ambiguïté avec un profil ouvert. Aucun profil DP, PC, PCMI,
 * permis ou CERFA n'existe.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Table de confiance : type de formulaire → profil d'upload autorisé.
 */
final class UploadProfileRegistry {

	/**
	 * Profil autorisé pour un type, ou null si le type n'en a aucun.
	 *
	 * @param string $type Type de formulaire, résolu par la route serveur.
	 * @return UploadProfile|null
	 */
	public static function for_type( string $type ): ?UploadProfile {
		switch ( $type ) {
			case 'conception':
				// Profil Conception, assemblé depuis les constantes historiques :
				// mêmes blocs, formats, quantités et tailles, à l'octet près.
				return UploadPolicy::conception_profile();

			default:
				// Tout autre type — dont « localisation » — n'a pas de profil.
				return null;
		}
	}

	/**
	 * Profil autorisé pour un type, ou **exception contrôlée** si absent.
	 *
	 * Pour les appelants qui SAVENT qu'un profil doit exister (chemins de
	 * production Conception, tests). Ne retombe jamais sur un profil implicite.
	 *
	 * @param string $type Type de formulaire.
	 * @return UploadProfile
	 * @throws \RuntimeException Si aucun profil n'est associé au type.
	 */
	public static function require_for_type( string $type ): UploadProfile {
		$profil = self::for_type( $type );

		if ( null === $profil ) {
			throw new \RuntimeException( sprintf( 'aucun profil d\'upload pour le type « %s »', $type ) );
		}

		return $profil;
	}

	/**
	 * Un type dispose-t-il d'un profil d'upload autorisé ?
	 *
	 * @param string $type Type de formulaire.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return null !== self::for_type( $type );
	}
}
