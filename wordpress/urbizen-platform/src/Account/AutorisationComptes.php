<?php
/**
 * Assemble la porte d'autorisation des comptes.
 *
 * Un seul endroit compose le registre : sans lui, chaque appelant
 * enregistrerait ses politiques à sa façon, et deux assemblages divergents
 * finiraient par ne pas décider pareil.
 *
 * Rien n'est accroché à un hook : la façade est construite à la demande, par
 * celui qui pose une question. Une requête qui n'en pose aucune n'en paie pas
 * le coût.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Urbizen\Platform\Domain\Authorization\Authorization;
use Urbizen\Platform\Domain\Authorization\PolicyRegistry;
use Urbizen\Platform\Domain\Authorization\PolitiqueActionVerifiee;
use Urbizen\Platform\Domain\Authorization\PolitiqueCompte;
use Urbizen\Platform\Domain\Authorization\PolitiqueVerification;
use Urbizen\Platform\Domain\Identity\CurrentUserProvider;

/**
 * Fabrique de la façade d'autorisation.
 */
final class AutorisationComptes {

	/**
	 * Registre portant les trois politiques d'E2.
	 *
	 * @return PolicyRegistry
	 */
	public static function registre(): PolicyRegistry {
		$registre = new PolicyRegistry();

		$registre->enregistrer( new PolitiqueCompte() );
		$registre->enregistrer( new PolitiqueVerification() );
		$registre->enregistrer( new PolitiqueActionVerifiee() );

		return $registre;
	}

	/**
	 * Façade prête à l'emploi.
	 *
	 * @param CurrentUserProvider $identite Fournisseur de l'acteur courant.
	 * @return Authorization
	 */
	public static function porte( CurrentUserProvider $identite ): Authorization {
		return new Authorization( self::registre(), $identite );
	}
}
