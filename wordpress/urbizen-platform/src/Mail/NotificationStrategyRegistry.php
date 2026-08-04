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
	 * Types de formulaire dont le demandeur reçoit un accusé de réception.
	 *
	 * Liste blanche, et non un drapeau par formulaire : écrire à une personne
	 * doit être une décision explicite, prise ici, type par type. Un formulaire
	 * absent de cette liste n'envoie rien à qui que ce soit — c'est le défaut,
	 * et c'est le bon défaut.
	 *
	 * @var array<int, string>
	 */
	private const ACCUSE_CLIENT = array( 'declaration_prealable' );

	/**
	 * Stratégie autorisée pour un couple (type, créneau).
	 *
	 * C'est ici que se joue la séparation entre les deux messages d'une même
	 * demande : le créneau administratif reçoit la stratégie interne du type, le
	 * créneau client reçoit l'accusé — et seulement si le type y a droit. Sans
	 * stratégie, le planificateur n'envoie rien et le dit ; il ne retombe jamais
	 * sur l'autre créneau.
	 *
	 * @param string           $type Type de formulaire, résolu côté serveur.
	 * @param NotificationSlot $slot Créneau visé.
	 * @return NotificationStrategy|null
	 */
	public static function for_slot( string $type, NotificationSlot $slot ): ?NotificationStrategy {
		if ( $slot->est_admin() ) {
			return self::for_type( $type );
		}

		if ( NotificationSlot::CLIENT === $slot->type && in_array( $type, self::ACCUSE_CLIENT, true ) ) {
			return new CustomerAcknowledgementStrategy();
		}

		return null;
	}

	/**
	 * Un type prévoit-il un accusé de réception pour le demandeur ?
	 *
	 * @param string $type Type de formulaire.
	 * @return bool
	 */
	public static function has_customer_acknowledgement( string $type ): bool {
		return in_array( $type, self::ACCUSE_CLIENT, true );
	}

	/**
	 * Stratégie interne autorisée pour un type, ou null si le type n'en a aucune.
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
