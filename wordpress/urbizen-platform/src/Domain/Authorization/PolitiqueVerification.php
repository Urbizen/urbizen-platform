<?php
/**
 * Politique de demande de vérification : « en a-t-il le droit ? ».
 *
 * Elle ne consulte **ni** le quota, **ni** `RateLimiter`, **ni** les
 * métadonnées de limitation, **ni** l'état d'un verrou. Ce sont deux questions
 * différentes, et les mêler produirait une politique qu'on ne saurait plus
 * tester : « a-t-il le droit ? » relève de l'autorisation, « est-ce possible
 * maintenant ? » relève de `VerificationService`.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use Urbizen\Platform\Domain\Account\DemandeVerification;
use Urbizen\Platform\Domain\Identity\ActeurCourant;

/**
 * Droit de demander une vérification.
 */
final class PolitiqueVerification implements ResourcePolicy {

	public const DEMANDER = 'verification.demander';

	/**
	 * @return string
	 */
	public function gere(): string {
		return DemandeVerification::class;
	}

	/**
	 * @param ActeurCourant $acteur    Acteur.
	 * @param string        $action    Action.
	 * @param object        $ressource Ressource.
	 * @return Decision
	 */
	public function decider( ActeurCourant $acteur, string $action, object $ressource ): Decision {
		if ( ! $ressource instanceof DemandeVerification ) {
			return Decision::non( 'ressource_inattendue' );
		}

		if ( self::DEMANDER !== $action ) {
			return Decision::non( 'action_inconnue' );
		}

		if ( $acteur->est_anonyme() ) {
			return Decision::non( 'anonyme' );
		}

		if ( ! $acteur->est_le_meme_que( new ActeurCourant( $ressource->compte()->id() ) ) ) {
			return Decision::non( 'compte_d_autrui' );
		}

		return Decision::oui( 'proprietaire' );
	}
}
