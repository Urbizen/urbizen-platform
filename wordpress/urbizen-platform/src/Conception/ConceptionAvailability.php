<?php
/**
 * Politique d'accès au formulaire de conception.
 *
 * **Fermée par défaut, et sur le serveur.** Un formulaire masqué en CSS ou
 * désactivé en JavaScript reste servi : son schéma, son nonce et son jeton
 * partent avec la page. Ici, un visiteur anonyme n'obtient rien du tout — ni
 * balise, ni schéma, ni feuille de style.
 *
 * Un paramètre d'URL ne peut jamais suffire à ouvrir le formulaire. Seuls un
 * filtre serveur ou une constante, tous deux hors de portée du navigateur,
 * pourront le faire à l'étape F.
 *
 * @package Urbizen\Platform\Conception
 */

namespace Urbizen\Platform\Conception;

defined( 'ABSPATH' ) || exit;

/**
 * Qui a le droit de voir le formulaire, et pourquoi.
 */
final class ConceptionAvailability {

	/**
	 * Constante serveur d'ouverture publique, prévue pour la PR F.
	 */
	public const CONSTANTE_PUBLIQUE = 'URBIZEN_CONCEPTION_PUBLIC';

	/**
	 * Capacité exigée pour l'aperçu.
	 */
	public const CAPACITE_APERCU = 'manage_options';

	/**
	 * Le formulaire est-il ouvert au public ?
	 *
	 * Défaut : **non**. La valeur ne peut venir que du serveur.
	 *
	 * @return bool
	 */
	public static function is_public(): bool {
		if ( defined( self::CONSTANTE_PUBLIQUE ) && true === constant( self::CONSTANTE_PUBLIQUE ) ) {
			return true;
		}

		/**
		 * Ouvre le formulaire de conception au public.
		 *
		 * Réservé à la mise en ligne commerciale. Aucun paramètre de requête,
		 * aucun cookie et aucune donnée de formulaire ne peut atteindre ce
		 * filtre : il se règle dans le code du serveur.
		 *
		 * @param bool $ouvert Faux par défaut.
		 */
		return true === apply_filters( 'urbizen_conception_public_enabled', false );
	}

	/**
	 * L'utilisateur courant peut-il prévisualiser le formulaire ?
	 *
	 * Exige une session authentifiée **et** la capacité d'administration. Ni
	 * l'une ni l'autre ne se falsifient depuis le navigateur.
	 *
	 * @return bool
	 */
	public static function can_preview(): bool {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( self::CAPACITE_APERCU );
	}

	/**
	 * Le formulaire doit-il être rendu dans le contexte courant ?
	 *
	 * @return bool
	 */
	public static function can_render(): bool {
		if ( self::is_public() ) {
			return true;
		}

		return self::can_preview();
	}

	/**
	 * Motif technique du refus, pour le journal.
	 *
	 * Ne contient aucune donnée personnelle, et ne distingue pas un visiteur
	 * d'un autre : seulement l'état de la politique.
	 *
	 * @return string
	 */
	public static function blocker(): string {
		if ( self::can_render() ) {
			return '';
		}

		return self::is_public() ? 'apercu_refuse' : 'formulaire_non_public';
	}
}
