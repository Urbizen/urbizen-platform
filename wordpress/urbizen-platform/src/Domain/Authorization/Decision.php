<?php
/**
 * Verdict d'une politique : autorisé ou non, **et pourquoi**.
 *
 * Le motif n'est pas décoratif. Il sert au journal et aux bancs d'essai : un
 * test qui vérifie seulement « c'est refusé » passerait encore si le refus
 * venait d'une faute de frappe dans le nom de l'action. En exigeant le motif,
 * on vérifie que le refus vient bien de la règle qu'on croit éprouver.
 *
 * Le motif n'est **jamais** montré à l'utilisateur : il décrit une règle
 * interne, et détaillé au mauvais endroit il renseignerait un attaquant sur
 * ce qui existe.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

/**
 * Décision immuable.
 */
final class Decision {

	/**
	 * Motif employé quand aucune politique ne couvre la ressource.
	 */
	public const AUCUNE_POLITIQUE = 'aucune_politique';

	/**
	 * @var bool
	 */
	private bool $autorisee;

	/**
	 * @var string
	 */
	private string $motif;

	/**
	 * @param bool   $autorisee Sens de la décision.
	 * @param string $motif     Motif technique, non destiné à l'utilisateur.
	 */
	private function __construct( bool $autorisee, string $motif ) {
		$this->autorisee = $autorisee;
		$this->motif     = '' === trim( $motif ) ? 'sans_motif' : trim( $motif );
	}

	/**
	 * Autorise, en disant sur quelle règle.
	 *
	 * @param string $motif Règle appliquée.
	 * @return self
	 */
	public static function oui( string $motif ): self {
		return new self( true, $motif );
	}

	/**
	 * Refuse, en disant pourquoi.
	 *
	 * @param string $motif Règle appliquée.
	 * @return self
	 */
	public static function non( string $motif ): self {
		return new self( false, $motif );
	}

	/**
	 * @return bool
	 */
	public function autorisee(): bool {
		return $this->autorisee;
	}

	/**
	 * @return string
	 */
	public function motif(): string {
		return $this->motif;
	}

	/**
	 * Forme lisible, pour le journal.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return ( $this->autorisee ? 'oui' : 'non' ) . ' (' . $this->motif . ')';
	}
}
