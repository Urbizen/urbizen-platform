<?php
/**
 * Politique terminale : refuse tout.
 *
 * C'est la réponse à toute ressource qu'aucune politique ne couvre. Elle est
 * une **classe**, et non un `return false` enfoui dans la façade, pour trois
 * raisons : elle se teste, elle se remplace jamais par mégarde, et une
 * mutation qui la retire fait tomber un contrôle nommé.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use Urbizen\Platform\Domain\Identity\ActeurCourant;

/**
 * Refus par défaut.
 */
final class RefusParDefaut implements ResourcePolicy {

	/**
	 * Marque signalant que cette politique ne vise aucune classe précise.
	 */
	public const TOUTES = '*';

	/**
	 * @return string
	 */
	public function gere(): string {
		return self::TOUTES;
	}

	/**
	 * Refuse, quels que soient l'acteur, l'action et la ressource.
	 *
	 * @param ActeurCourant $acteur    Ignoré.
	 * @param string        $action    Ignorée.
	 * @param object        $ressource Ignorée.
	 * @return Decision
	 */
	public function decider( ActeurCourant $acteur, string $action, object $ressource ): Decision {
		unset( $acteur, $action, $ressource );

		return Decision::non( Decision::AUCUNE_POLITIQUE );
	}
}
