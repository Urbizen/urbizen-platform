<?php
/**
 * Port : « qui agit ? »
 *
 * Le domaine pose la question, un adaptateur y répond. C'est cette indirection
 * qui permet à `src/Domain/` de ne jamais appeler WordPress, et aux bancs
 * d'essai de jouer n'importe quel acteur sans installer quoi que ce soit.
 *
 * @package Urbizen\Platform\Domain\Identity
 */

namespace Urbizen\Platform\Domain\Identity;

/**
 * Fournisseur de l'acteur courant.
 */
interface CurrentUserProvider {

	/**
	 * Rend l'acteur courant.
	 *
	 * **Ne rend jamais `null`.** Un visiteur non authentifié est rendu comme
	 * `ActeurCourant::anonyme()`. Une implémentation qui ne saurait pas
	 * répondre doit rendre l'anonyme, jamais lever ni deviner.
	 *
	 * @return ActeurCourant
	 */
	public function acteur(): ActeurCourant;
}
