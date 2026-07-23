<?php
/**
 * Demande d'émission d'un lien de vérification, vue comme ressource.
 *
 * Elle ne porte **que** de quoi décider *qui* a le droit de demander. Les
 * limites opérationnelles — quota, délai minimal, verrou — n'y figurent pas :
 * elles appartiennent à `VerificationService`, et une politique qui les
 * consulterait mêlerait deux questions distinctes, « en a-t-il le droit ? » et
 * « est-ce possible maintenant ? ».
 *
 * @package Urbizen\Platform\Domain\Account
 */

namespace Urbizen\Platform\Domain\Account;

/**
 * Demande de vérification.
 */
final class DemandeVerification {

	/**
	 * @var Compte
	 */
	private Compte $compte;

	/**
	 * @param Compte $compte Compte visé.
	 */
	public function __construct( Compte $compte ) {
		$this->compte = $compte;
	}

	/**
	 * @return Compte
	 */
	public function compte(): Compte {
		return $this->compte;
	}
}
