<?php
/**
 * Adaptateur : WordPress → `ActeurCourant`.
 *
 * C'est le seul endroit du chemin d'autorisation qui connaisse WordPress. Il
 * traduit, il ne décide pas : aucune règle d'accès ne doit apparaître ici.
 *
 * @package Urbizen\Platform\Adapter
 */

namespace Urbizen\Platform\Adapter;

use Urbizen\Platform\Domain\Identity\ActeurCourant;
use Urbizen\Platform\Domain\Identity\CurrentUserProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Fournit l'acteur courant depuis la session WordPress.
 */
final class WpCurrentUser implements CurrentUserProvider {

	/**
	 * Clé de métadonnée portant la vérification de l'adresse.
	 *
	 * Elle n'est **écrite par personne en E1** : le compte et sa vérification
	 * appartiennent à E2. Elle est lue dès maintenant pour que l'adaptateur
	 * n'ait pas à changer quand E2 la posera.
	 */
	public const META_COURRIEL_VERIFIE = '_urbizen_courriel_verifie';

	/**
	 * Rend l'acteur courant, ou l'anonyme.
	 *
	 * Aucune de ces situations ne lève : hors WordPress, sans session, ou
	 * avec un utilisateur inattendu, on rend l'anonyme. Une exception ici
	 * remonterait dans une couche qui, en la rattrapant mal, pourrait
	 * conclure à un accès.
	 *
	 * @return ActeurCourant
	 */
	public function acteur(): ActeurCourant {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return ActeurCourant::anonyme();
		}

		$utilisateur = wp_get_current_user();

		if ( ! is_object( $utilisateur ) || empty( $utilisateur->ID ) ) {
			return ActeurCourant::anonyme();
		}

		$id = (int) $utilisateur->ID;

		if ( $id <= 0 ) {
			return ActeurCourant::anonyme();
		}

		$roles = array();

		if ( isset( $utilisateur->roles ) && is_array( $utilisateur->roles ) ) {
			$roles = $utilisateur->roles;
		}

		return new ActeurCourant( $id, $roles, $this->courriel_verifie( $id ) );
	}

	/**
	 * L'adresse de cet utilisateur est-elle vérifiée ?
	 *
	 * Tant qu'E2 n'a rien écrit, la métadonnée est absente et la réponse est
	 * **non**. C'est le sens voulu : non vérifié par défaut.
	 *
	 * @param int $id Identifiant WordPress.
	 * @return bool
	 */
	private function courriel_verifie( int $id ): bool {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		$valeur = get_user_meta( $id, self::META_COURRIEL_VERIFIE, true );

		// Comparaison stricte à '1' : ni « 0 », ni chaîne vide, ni « false »
		// ne doivent pouvoir passer pour une vérification.
		return '1' === (string) $valeur;
	}
}
