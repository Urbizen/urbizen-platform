<?php
/**
 * Registre serveur des stratégies de notification, par type de formulaire.
 *
 * La notification résout sa stratégie depuis le **type serveur** de la demande
 * persistée (`_urbizen_form_type`, écrit par la route serveur), jamais depuis
 * `$_POST`, un `mail_strategy`, un destinataire, un sujet ou un nom de template.
 * La table est **privée, immuable, écrite en dur** : le navigateur ne choisit ni
 * la stratégie, ni le destinataire, ni le sujet. Un type sans stratégie n'en a
 * **aucune** (`null`) — jamais de repli sur Conception, jamais de courriel
 * inventé.
 *
 * Seule la notification interne `conception` existe. `localisation` — sans
 * soumission commerciale persistante — n'a aucune stratégie. Aucune stratégie
 * DP, PC, PCMI, permis ou CERFA, aucun accusé de réception au demandeur.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Table de confiance : type de formulaire → stratégie de notification autorisée.
 */
final class NotificationStrategyRegistry {

	/**
	 * Stratégie autorisée pour un type, ou null si le type n'en a aucune.
	 *
	 * @param string $type Type de formulaire, résolu côté serveur.
	 * @return NotificationStrategy|null
	 */
	public static function for_type( string $type ): ?NotificationStrategy {
		switch ( $type ) {
			case 'conception':
				return new ConceptionNotificationStrategy();

			case 'declaration_prealable':
				return new DeclarationPrealableNotificationStrategy();

			default:
				// Tout autre type — dont « localisation » — n'a pas de stratégie.
				return null;
		}
	}

	/**
	 * Un type dispose-t-il d'une stratégie de notification ?
	 *
	 * @param string $type Type de formulaire.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return null !== self::for_type( $type );
	}

	/**
	 * Stratégie d'un type, ou **exception contrôlée** si absente.
	 *
	 * Pour les appelants qui SAVENT qu'une stratégie doit exister. Ne retombe
	 * jamais sur une stratégie implicite.
	 *
	 * @param string $type Type de formulaire.
	 * @return NotificationStrategy
	 * @throws \RuntimeException Si aucune stratégie n'est associée au type.
	 */
	public static function require_for_type( string $type ): NotificationStrategy {
		$strategie = self::for_type( $type );

		if ( null === $strategie ) {
			throw new \RuntimeException( sprintf( 'aucune stratégie de notification pour le type « %s »', $type ) );
		}

		return $strategie;
	}
}
