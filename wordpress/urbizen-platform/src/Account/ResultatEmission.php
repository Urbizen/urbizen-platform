<?php
/**
 * Résultat d'une préparation d'émission.
 *
 * E2.1 n'envoie aucun courriel : elle **prépare** un jeton et le rend à son
 * appelant. Trois états doivent donc se distinguer sans ambiguïté :
 *
 *   préparé   le jeton existe, le quota n'est PAS encore consommé
 *   confirmé  l'appelant a réussi son envoi, le quota est consommé
 *   annulé    l'envoi a échoué, le quota reste intact et le jeton reste valide
 *
 * C'est ce qui permettra à E2.2 de confirmer après coup, une fois le courriel
 * réellement parti — et de ne pas décompter un envoi qui n'a jamais eu lieu.
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
	 * @param bool   $prepare   Préparation réussie ?
	 * @param string $motif     Motif technique.
	 * @param string $jeton     Jeton brut.
	 * @param string $cible     Adresse visée.
	 * @param int    $expire_le Échéance.
	 */
	private function __construct(
		bool $prepare,
		string $motif,
		string $jeton = '',
		string $cible = '',
		int $expire_le = 0
	) {
		$this->prepare   = $prepare;
		$this->motif     = $motif;
		$this->jeton     = $jeton;
		$this->cible     = $cible;
		$this->expire_le = $expire_le;
	}

	/**
	 * @param string $jeton     Jeton brut.
	 * @param string $cible     Adresse visée.
	 * @param int    $expire_le Échéance.
	 * @return self
	 */
	public static function prepare( string $jeton, string $cible, int $expire_le ): self {
		return new self( true, 'prepare', $jeton, $cible, $expire_le );
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
}
