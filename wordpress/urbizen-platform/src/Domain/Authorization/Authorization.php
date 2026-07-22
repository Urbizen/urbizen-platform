<?php
/**
 * Porte unique d'autorisation.
 *
 * Tout le reste de la plateforme pose ses questions ici, et nulle part
 * ailleurs. Aucune vue, aucun gabarit, aucun contrôleur ne doit appeler
 * `current_user_can()` : c'est ainsi qu'une règle finit par exister en deux
 * exemplaires divergents, et qu'un correctif appliqué à l'un laisse l'autre
 * ouvert.
 *
 * **Refus par défaut, sans exception.** Une ressource qu'aucune politique ne
 * couvre est refusée. Il n'existe pas de repli permissif, pas de mode
 * « développement », et **`administrator` n'est pas un court-circuit
 * implicite** : si un administrateur doit passer, une politique doit l'écrire,
 * ce qui la rend testable et révocable.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use Urbizen\Platform\Domain\Identity\ActeurCourant;
use Urbizen\Platform\Domain\Identity\CurrentUserProvider;

/**
 * Façade d'autorisation.
 */
final class Authorization {

	/**
	 * @var PolicyRegistry
	 */
	private PolicyRegistry $registre;

	/**
	 * @var CurrentUserProvider
	 */
	private CurrentUserProvider $identite;

	/**
	 * @var ResourcePolicy
	 */
	private ResourcePolicy $repli;

	/**
	 * @param PolicyRegistry      $registre Registre des politiques.
	 * @param CurrentUserProvider $identite Fournisseur de l'acteur courant.
	 * @param ResourcePolicy|null $repli    Politique terminale ; refus par défaut.
	 */
	public function __construct(
		PolicyRegistry $registre,
		CurrentUserProvider $identite,
		?ResourcePolicy $repli = null
	) {
		$this->registre = $registre;
		$this->identite = $identite;
		$this->repli    = $repli ?? new RefusParDefaut();
	}

	/**
	 * L'acteur courant peut-il faire cela ?
	 *
	 * @param string $action    Action, par exemple « projet.voir ».
	 * @param object $ressource Ressource visée.
	 * @return bool
	 */
	public function peut( string $action, object $ressource ): bool {
		return $this->decider( $action, $ressource )->autorisee();
	}

	/**
	 * Décide, en rendant le motif.
	 *
	 * @param string $action    Action.
	 * @param object $ressource Ressource.
	 * @return Decision
	 */
	public function decider( string $action, object $ressource ): Decision {
		return $this->decider_pour( $this->identite->acteur(), $action, $ressource );
	}

	/**
	 * Décide pour un acteur désigné.
	 *
	 * Utile aux tâches de fond et aux bancs d'essai, qui n'ont pas d'acteur
	 * courant. Le chemin de décision reste **strictement le même** : rien
	 * n'est allégé sous prétexte qu'on n'est pas dans une requête.
	 *
	 * @param ActeurCourant $acteur    Acteur.
	 * @param string        $action    Action.
	 * @param object        $ressource Ressource.
	 * @return Decision
	 */
	public function decider_pour( ActeurCourant $acteur, string $action, object $ressource ): Decision {
		$action = trim( $action );

		// Une action vide n'est pas une question ; y répondre « oui » serait
		// autoriser l'indéterminé.
		if ( '' === $action ) {
			return Decision::non( 'action_vide' );
		}

		$politique = $this->registre->pour( $ressource );

		if ( null === $politique ) {
			return $this->repli->decider( $acteur, $action, $ressource );
		}

		return $politique->decider( $acteur, $action, $ressource );
	}
}
