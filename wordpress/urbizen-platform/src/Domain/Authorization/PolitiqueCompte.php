<?php
/**
 * Politique du compte : on n'agit que sur le sien.
 *
 * Aucun rôle n'ouvre cette porte. Ni `urbizen_client`, ni `administrator` :
 * la seule question posée est « est-ce le compte de celui qui agit ? ».
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use Urbizen\Platform\Domain\Account\Compte;
use Urbizen\Platform\Domain\Identity\ActeurCourant;

/**
 * Accès à un compte.
 */
final class PolitiqueCompte implements ResourcePolicy {

	public const VOIR     = 'compte.voir';
	public const MODIFIER = 'compte.modifier';

	/**
	 * @return string
	 */
	public function gere(): string {
		return Compte::class;
	}

	/**
	 * @param ActeurCourant $acteur    Acteur.
	 * @param string        $action    Action.
	 * @param object        $ressource Ressource.
	 * @return Decision
	 */
	public function decider( ActeurCourant $acteur, string $action, object $ressource ): Decision {
		if ( ! $ressource instanceof Compte ) {
			return Decision::non( 'ressource_inattendue' );
		}

		if ( ! in_array( $action, array( self::VOIR, self::MODIFIER ), true ) ) {
			return Decision::non( 'action_inconnue' );
		}

		if ( $acteur->est_anonyme() ) {
			return Decision::non( 'anonyme' );
		}

		// `est_le_meme_que` refuse déjà deux anonymes ; on construit l'acteur
		// correspondant au compte plutôt que de comparer deux entiers nus, pour
		// que la règle passe par la seule méthode qui sache dire « la même
		// personne ».
		$proprietaire = new ActeurCourant( $ressource->id() );

		if ( ! $acteur->est_le_meme_que( $proprietaire ) ) {
			return Decision::non( 'compte_d_autrui' );
		}

		return Decision::oui( 'proprietaire' );
	}
}
