<?php
/**
 * Résultat structuré et immuable d'une exécution.
 *
 * Un booléen ne suffit pas : « rien à faire » et « tout était déjà appliqué »
 * sont deux succès, mais un déploiement doit pouvoir les distinguer, et un
 * échec doit dire **laquelle** des migrations a cédé et pourquoi.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

/**
 * Résultat d'exécution.
 */
final class ResultatMigration {

	/**
	 * Aucun catalogue : rien n'a été tenté, aucune requête émise.
	 */
	public const RIEN = 'rien';

	/**
	 * Au moins une migration a été appliquée.
	 */
	public const APPLIQUEES = 'appliquees';

	/**
	 * Tout était déjà en place.
	 */
	public const DEJA_A_JOUR = 'deja_a_jour';

	/**
	 * Une migration a échoué ; les suivantes n'ont pas été tentées.
	 */
	public const ECHEC = 'echec';

	/**
	 * @var string
	 */
	private string $etat;

	/**
	 * @var array<int, string>
	 */
	private array $appliquees;

	/**
	 * @var array<int, string>
	 */
	private array $deja_appliquees;

	/**
	 * @var string
	 */
	private string $migration_en_echec;

	/**
	 * @var string
	 */
	private string $motif;

	/**
	 * @param string             $etat               État.
	 * @param array<int, string> $appliquees         Identifiants appliqués.
	 * @param array<int, string> $deja_appliquees    Identifiants déjà en place.
	 * @param string             $migration_en_echec Identifiant fautif.
	 * @param string             $motif              Motif technique.
	 */
	private function __construct(
		string $etat,
		array $appliquees = array(),
		array $deja_appliquees = array(),
		string $migration_en_echec = '',
		string $motif = ''
	) {
		$this->etat               = $etat;
		$this->appliquees         = array_values( $appliquees );
		$this->deja_appliquees    = array_values( $deja_appliquees );
		$this->migration_en_echec = $migration_en_echec;
		$this->motif              = $motif;
	}

	/**
	 * Catalogue vide : rien n'a été tenté.
	 *
	 * @return self
	 */
	public static function rien(): self {
		return new self( self::RIEN, array(), array(), '', 'catalogue_vide' );
	}

	/**
	 * Succès.
	 *
	 * @param array<int, string> $appliquees      Appliquées à l'instant.
	 * @param array<int, string> $deja_appliquees Déjà en place.
	 * @return self
	 */
	public static function succes( array $appliquees, array $deja_appliquees ): self {
		return new self(
			array() === $appliquees ? self::DEJA_A_JOUR : self::APPLIQUEES,
			$appliquees,
			$deja_appliquees
		);
	}

	/**
	 * Échec.
	 *
	 * @param string             $identifiant     Migration fautive, vide si générique.
	 * @param string             $motif           Motif technique.
	 * @param array<int, string> $appliquees      Appliquées avant l'échec.
	 * @param array<int, string> $deja_appliquees Déjà en place.
	 * @return self
	 */
	public static function echec(
		string $identifiant,
		string $motif,
		array $appliquees = array(),
		array $deja_appliquees = array()
	): self {
		return new self( self::ECHEC, $appliquees, $deja_appliquees, $identifiant, $motif );
	}

	/**
	 * @return string
	 */
	public function etat(): string {
		return $this->etat;
	}

	/**
	 * @return bool
	 */
	public function reussi(): bool {
		return self::ECHEC !== $this->etat;
	}

	/**
	 * Rien n'a été tenté, aucune requête émise.
	 *
	 * @return bool
	 */
	public function rien_a_faire(): bool {
		return self::RIEN === $this->etat;
	}

	/**
	 * @return array<int, string>
	 */
	public function appliquees(): array {
		return $this->appliquees;
	}

	/**
	 * @return array<int, string>
	 */
	public function deja_appliquees(): array {
		return $this->deja_appliquees;
	}

	/**
	 * @return string
	 */
	public function migration_en_echec(): string {
		return $this->migration_en_echec;
	}

	/**
	 * @return string
	 */
	public function motif(): string {
		return $this->motif;
	}

	/**
	 * Forme lisible, pour la sortie WP-CLI et le journal.
	 *
	 * @return string
	 */
	public function resume(): string {
		switch ( $this->etat ) {
			case self::RIEN:
				return 'aucune migration déclarée : rien à faire';

			case self::DEJA_A_JOUR:
				return sprintf( 'schéma à jour (%d migration(s) déjà appliquée(s))', count( $this->deja_appliquees ) );

			case self::APPLIQUEES:
				return sprintf(
					'%d migration(s) appliquée(s) : %s',
					count( $this->appliquees ),
					implode( ', ', $this->appliquees )
				);

			default:
				return sprintf(
					'échec sur « %s » : %s',
					'' === $this->migration_en_echec ? 'aucune migration précise' : $this->migration_en_echec,
					$this->motif
				);
		}
	}
}
