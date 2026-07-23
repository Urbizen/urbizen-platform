<?php
/**
 * Politique des actions réservées aux adresses confirmées.
 *
 * C'est la porte que franchiront E3 et E4. Elle ne connaît qu'une règle, et
 * cette règle ne dépend d'aucun rôle : l'adresse est-elle vérifiée ?
 *
 * Un administrateur non vérifié est refusé comme n'importe qui — exister dans
 * `wp_users` ne prouve pas qu'une adresse a été confirmée.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use Urbizen\Platform\Domain\Account\ActionVerifiee;
use Urbizen\Platform\Domain\Identity\ActeurCourant;

/**
 * Action exigeant une adresse vérifiée.
 */
final class PolitiqueActionVerifiee implements ResourcePolicy {

	public const EXECUTER = 'action.executer';

	/**
	 * @return string
	 */
	public function gere(): string {
		return ActionVerifiee::class;
	}

	/**
	 * @param ActeurCourant $acteur    Acteur.
	 * @param string        $action    Action.
	 * @param object        $ressource Ressource.
	 * @return Decision
	 */
	public function decider( ActeurCourant $acteur, string $action, object $ressource ): Decision {
		if ( ! $ressource instanceof ActionVerifiee ) {
			return Decision::non( 'ressource_inattendue' );
		}

		if ( self::EXECUTER !== $action ) {
			return Decision::non( 'action_inconnue' );
		}

		if ( $acteur->est_anonyme() ) {
			return Decision::non( 'anonyme' );
		}

		if ( ! $acteur->courriel_verifie() ) {
			return Decision::non( 'courriel_non_verifie' );
		}

		return Decision::oui( 'courriel_verifie' );
	}
}
