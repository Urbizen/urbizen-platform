<?php
/**
 * Associe un type de ressource à sa politique.
 *
 * Deux règles tiennent la sûreté de ce registre.
 *
 * **Un enregistrement n'écrase jamais le précédent.** Une seconde politique
 * pour la même ressource est une erreur de programmation, pas une préférence :
 * si on la laissait passer silencieusement, l'ordre de chargement déciderait
 * des droits.
 *
 * **Une classe non enregistrée n'a pas de politique**, et la façade en tire un
 * refus. Le registre ne devine pas par héritage : une sous-classe n'hérite pas
 * des droits de son parent tant qu'on ne l'a pas écrit.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use InvalidArgumentException;

/**
 * Registre des politiques.
 */
final class PolicyRegistry {

	/**
	 * Politiques, indexées par nom de classe de ressource.
	 *
	 * @var array<string, ResourcePolicy>
	 */
	private array $politiques = array();

	/**
	 * Enregistre une politique.
	 *
	 * @param ResourcePolicy $politique Politique.
	 * @return void
	 *
	 * @throws InvalidArgumentException Si la classe visée est vide ou déjà couverte.
	 */
	public function enregistrer( ResourcePolicy $politique ): void {
		$classe = trim( $politique->gere() );

		if ( '' === $classe ) {
			throw new InvalidArgumentException( 'une politique doit déclarer la classe qu’elle couvre' );
		}

		if ( isset( $this->politiques[ $classe ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'une politique couvre déjà « %s »', $classe )
			);
		}

		$this->politiques[ $classe ] = $politique;
	}

	/**
	 * Politique couvrant cette ressource, ou `null`.
	 *
	 * La correspondance est **exacte**. Une sous-classe n'est pas couverte par
	 * la politique de son parent : hériter des droits est une décision, elle
	 * doit s'écrire.
	 *
	 * @param object $ressource Ressource.
	 * @return ResourcePolicy|null
	 */
	public function pour( object $ressource ): ?ResourcePolicy {
		$classe = get_class( $ressource );

		return $this->politiques[ $classe ] ?? null;
	}

	/**
	 * Une politique couvre-t-elle cette classe ?
	 *
	 * @param string $classe Nom de classe.
	 * @return bool
	 */
	public function couvre( string $classe ): bool {
		return isset( $this->politiques[ trim( $classe ) ] );
	}

	/**
	 * Classes couvertes, triées.
	 *
	 * @return array<int, string>
	 */
	public function classes_couvertes(): array {
		$classes = array_keys( $this->politiques );
		sort( $classes );

		return $classes;
	}
}
