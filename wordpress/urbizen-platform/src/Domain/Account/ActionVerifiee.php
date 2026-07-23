<?php
/**
 * Action exigeant une adresse confirmée.
 *
 * C'est la porte que franchiront les phases suivantes : commander, payer,
 * télécharger un livrable, inviter un collaborateur, créer un projet. E2 ne
 * déclare aucune de ces actions — elle pose la ressource et sa politique, pour
 * que E3 et E4 n'aient pas à réinventer la règle.
 *
 * @package Urbizen\Platform\Domain\Account
 */

namespace Urbizen\Platform\Domain\Account;

/**
 * Action réservée aux comptes vérifiés.
 */
final class ActionVerifiee {

	/**
	 * @var string
	 */
	private string $nom;

	/**
	 * @param string $nom Nom technique de l'action, pour le motif et le journal.
	 */
	public function __construct( string $nom ) {
		$this->nom = trim( $nom );
	}

	/**
	 * @return string
	 */
	public function nom(): string {
		return $this->nom;
	}
}
