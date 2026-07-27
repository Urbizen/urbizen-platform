<?php
/**
 * Registre serveur des stratégies tarifaires, par type de formulaire.
 *
 * Le calcul du prix résout sa stratégie depuis le **type serveur** (issu de la
 * route / de la définition en liste blanche), jamais depuis `$_POST`,
 * `pricing_strategy`, `price`, `amount`, un attribut de bloc ou un nom de
 * classe. La table est **privée, immuable, écrite en dur** : le navigateur ne
 * choisit ni la stratégie ni le montant. Un type sans stratégie n'en a **aucune**
 * (`null`) — jamais de repli sur Conception, jamais de prix inventé.
 *
 * Un seul type commercial est tarifé : `conception`. `localisation` — sans
 * pipeline commercial persistant — n'a aucune stratégie. Aucune stratégie DP,
 * PC, PCMI, permis ou CERFA n'existe.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Table de confiance : type de formulaire → stratégie tarifaire autorisée.
 */
final class PricingStrategyRegistry {

	/**
	 * Stratégie autorisée pour un type, ou null si le type n'en a aucune.
	 *
	 * @param string $type Type de formulaire, résolu côté serveur.
	 * @return PricingStrategy|null
	 */
	public static function for_type( string $type ): ?PricingStrategy {
		switch ( $type ) {
			case 'conception':
				return new ConceptionPricingStrategy();

			default:
				// Tout autre type — dont « localisation » — n'a pas de stratégie.
				return null;
		}
	}

	/**
	 * Un type dispose-t-il d'une stratégie tarifaire ?
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
	 * @return PricingStrategy
	 * @throws \RuntimeException Si aucune stratégie n'est associée au type.
	 */
	public static function require_for_type( string $type ): PricingStrategy {
		$strategie = self::for_type( $type );

		if ( null === $strategie ) {
			throw new \RuntimeException( sprintf( 'aucune stratégie tarifaire pour le type « %s »', $type ) );
		}

		return $strategie;
	}
}
