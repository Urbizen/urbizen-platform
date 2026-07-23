<?php
/**
 * Résultat d'une préparation d'émission.
 *
 * E2.1 n'envoie aucun courriel : elle **prépare** un jeton et le rend à son
 * appelant. Trois états doivent donc se distinguer sans ambiguïté :
 *
 *   préparé   le jeton existe, le quota n'est PAS encore consommé, et le compte
 *             est fermé à toute autre préparation
 *   confirmé  l'appelant a réussi son envoi, le quota est consommé, le jeton
 *             reste actif jusqu'à sa consommation
 *   annulé    l'envoi a échoué : le quota reste intact, le jeton est DÉTRUIT,
 *             et une nouvelle préparation redevient possible
 *
 * L'**identifiant d'émission** rendu ici est ce qui permet de clore exactement
 * l'émission que l'on a soi-même préparée. Sans lui, un appelant lent
 * confirmerait ou annulerait l'émission d'un autre.
 *
 * Le jeton brut n'est rendu qu'ici et n'est jamais stocké : après annulation il
 * est définitivement perdu, et il n'y a rien à « renvoyer ». C'est voulu — E2.2
 * doit repréparer, pas rejouer.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

/**
 * Résultat immuable d'une préparation.
 */
final class ResultatEmission {

	/**
	 * @var bool
	 */
	private bool $prepare;

	/**
	 * @var string
	 */
	private string $motif;

	/**
	 * Jeton brut, à ne jamais journaliser ni afficher.
	 *
	 * @var string
	 */
	private string $jeton;

	/**
	 * @var string
	 */
	private string $cible;

	/**
	 * @var int
	 */
	private int $expire_le;

	/**
	 * @var string
	 */
	private string $emission_id;

	/**
	 * @var int
	 */
	private int $generation;

	/**
	 * @param bool   $prepare     Préparation réussie ?
	 * @param string $motif       Motif technique.
	 * @param string $jeton       Jeton brut.
	 * @param string $cible       Adresse visée.
	 * @param int    $expire_le   Échéance du jeton.
	 * @param string $emission_id Identifiant d'émission.
	 * @param int    $generation  Génération du jeton.
	 */
	private function __construct(
		bool $prepare,
		string $motif,
		string $jeton = '',
		string $cible = '',
		int $expire_le = 0,
		string $emission_id = '',
		int $generation = 0
	) {
		$this->prepare     = $prepare;
		$this->motif       = $motif;
		$this->jeton       = $jeton;
		$this->cible       = $cible;
		$this->expire_le   = $expire_le;
		$this->emission_id = $emission_id;
		$this->generation  = $generation;
	}

	/**
	 * @param string $jeton       Jeton brut.
	 * @param string $cible       Adresse visée.
	 * @param int    $expire_le   Échéance du jeton.
	 * @param string $emission_id Identifiant d'émission.
	 * @param int    $generation  Génération du jeton.
	 * @return self
	 */
	public static function prepare(
		string $jeton,
		string $cible,
		int $expire_le,
		string $emission_id,
		int $generation
	): self {
		return new self( true, 'prepare', $jeton, $cible, $expire_le, $emission_id, $generation );
	}

	/**
	 * @param string $motif Motif technique.
	 * @return self
	 */
	public static function refuse( string $motif ): self {
		return new self( false, $motif );
	}

	/**
	 * @return bool
	 */
	public function est_prepare(): bool {
		return $this->prepare;
	}

	/**
	 * @return string
	 */
	public function motif(): string {
		return $this->motif;
	}

	/**
	 * Jeton brut.
	 *
	 * **Ne doit jamais être journalisé, affiché, ni rendu par WP-CLI.** Il n'a
	 * qu'une destination : le corps du courriel, en E2.2.
	 *
	 * @return string
	 */
	public function jeton(): string {
		return $this->jeton;
	}

	/**
	 * @return string
	 */
	public function cible(): string {
		return $this->cible;
	}

	/**
	 * @return int
	 */
	public function expire_le(): int {
		return $this->expire_le;
	}

	/**
	 * Identifiant de cette émission.
	 *
	 * À présenter à `confirmer_emission()` ou `annuler_emission()`. Il n'est pas
	 * secret — il ne donne aucun droit sur le jeton — mais il est nécessaire :
	 * sans lui, un appelant lent clôturerait l'émission d'un autre.
	 *
	 * @return string
	 */
	public function emission_id(): string {
		return $this->emission_id;
	}

	/**
	 * @return int
	 */
	public function generation(): int {
		return $this->generation;
	}
}
