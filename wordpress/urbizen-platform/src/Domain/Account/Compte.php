<?php
/**
 * Un compte, vu comme **ressource**.
 *
 * À ne pas confondre avec `ActeurCourant`, et la distinction porte tout le
 * modèle d'autorisation :
 *
 *   ActeurCourant  répond à « qui agit ? »      — c'est le SUJET d'une décision
 *   Compte         répond à « sur quoi ? »      — c'est la RESSOURCE visée
 *
 * `Authorization::peut( 'compte.modifier', $compte )` demande si l'acteur
 * courant peut modifier **ce compte-là**. Les deux objets sont indispensables
 * et ne se recouvrent pas : un compte ne porte pas de rôles — ils appartiennent
 * à celui qui agit, pas à ce sur quoi il agit.
 *
 * @package Urbizen\Platform\Domain\Account
 */

namespace Urbizen\Platform\Domain\Account;

/**
 * Compte, immuable.
 */
final class Compte {

	/**
	 * @var int
	 */
	private int $id;

	/**
	 * @var AdresseCourriel
	 */
	private AdresseCourriel $adresse;

	/**
	 * @var bool
	 */
	private bool $verifie;

	/**
	 * Adresse demandée mais non encore confirmée.
	 *
	 * @var AdresseCourriel|null
	 */
	private ?AdresseCourriel $en_attente;

	/**
	 * @param int                  $id         Identifiant WordPress.
	 * @param AdresseCourriel      $adresse    Adresse courante.
	 * @param bool                 $verifie    Adresse confirmée ?
	 * @param AdresseCourriel|null $en_attente Adresse en attente de confirmation.
	 */
	public function __construct(
		int $id,
		AdresseCourriel $adresse,
		bool $verifie = false,
		?AdresseCourriel $en_attente = null
	) {
		$this->id         = $id > 0 ? $id : 0;
		$this->adresse    = $adresse;
		// Un compte sans identifiant n'existe pas ; le déclarer vérifié serait
		// affirmer quelque chose sur personne.
		$this->verifie    = $this->id > 0 && $verifie;
		$this->en_attente = $en_attente;
	}

	/**
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * @return AdresseCourriel
	 */
	public function adresse(): AdresseCourriel {
		return $this->adresse;
	}

	/**
	 * @return bool
	 */
	public function est_verifie(): bool {
		return $this->verifie;
	}

	/**
	 * @return AdresseCourriel|null
	 */
	public function adresse_en_attente(): ?AdresseCourriel {
		return $this->en_attente;
	}

	/**
	 * Un changement d'adresse est-il en cours ?
	 *
	 * @return bool
	 */
	public function a_un_changement_en_cours(): bool {
		return null !== $this->en_attente;
	}

	/**
	 * Adresse que le prochain jeton doit confirmer.
	 *
	 * C'est l'adresse en attente s'il y en a une, l'adresse courante sinon.
	 * Cette valeur entre dans le condensat du jeton : un jeton émis pour une
	 * cible ne peut donc pas en confirmer une autre.
	 *
	 * @return AdresseCourriel
	 */
	public function cible_de_verification(): AdresseCourriel {
		return $this->en_attente ?? $this->adresse;
	}
}
