<?php
/**
 * L'acteur qui agit, vu par le domaine.
 *
 * Le domaine ne connaît **jamais** `WP_User`. Il reçoit un objet de valeur
 * immuable qui ne porte que ce dont une décision d'accès a besoin : un
 * identifiant, des rôles globaux, et le fait que l'adresse soit vérifiée.
 *
 * **Un visiteur anonyme est un acteur explicite**, d'identifiant `0`, jamais
 * `null`. C'est délibéré : une politique qui reçoit `null` finit tôt ou tard
 * par écrire `if ( ! $acteur )` et à en tirer la mauvaise conclusion. Ici,
 * l'anonyme se présente et se fait refuser comme les autres.
 *
 * @package Urbizen\Platform\Domain\Identity
 */

namespace Urbizen\Platform\Domain\Identity;

/**
 * Acteur courant, immuable.
 */
final class ActeurCourant {

	/**
	 * Identifiant réservé à l'anonyme.
	 */
	public const ANONYME = 0;

	/**
	 * Identifiant de l'acteur, `0` s'il est anonyme.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Rôles globaux, normalisés, sans doublon.
	 *
	 * Ils servent la navigation et les politiques qui les consultent
	 * **explicitement**. Aucun rôle n'accorde quoi que ce soit par lui-même.
	 *
	 * @var array<int, string>
	 */
	private array $roles;

	/**
	 * L'adresse de courriel est-elle vérifiée ?
	 *
	 * @var bool
	 */
	private bool $courriel_verifie;

	/**
	 * @param int                $id               Identifiant, négatif ramené à l'anonyme.
	 * @param array<int, string> $roles            Rôles globaux.
	 * @param bool               $courriel_verifie Adresse vérifiée.
	 */
	public function __construct( int $id = self::ANONYME, array $roles = array(), bool $courriel_verifie = false ) {
		// Un identifiant négatif n'existe pas dans WordPress ; le refuser
		// silencieusement vaut mieux que de propager une valeur absurde.
		$this->id = $id > 0 ? $id : self::ANONYME;

		$propres = array();

		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) ) {
				continue;
			}

			$role = strtolower( trim( $role ) );

			if ( '' !== $role && ! in_array( $role, $propres, true ) ) {
				$propres[] = $role;
			}
		}

		sort( $propres );

		$this->roles = $propres;

		// Un anonyme n'a pas d'adresse vérifiée, quoi qu'on lui passe.
		$this->courriel_verifie = self::ANONYME === $this->id ? false : $courriel_verifie;
	}

	/**
	 * Fabrique l'acteur anonyme.
	 *
	 * @return self
	 */
	public static function anonyme(): self {
		return new self();
	}

	/**
	 * Identifiant, `0` pour l'anonyme.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * @return bool
	 */
	public function est_anonyme(): bool {
		return self::ANONYME === $this->id;
	}

	/**
	 * Rôles globaux, triés.
	 *
	 * @return array<int, string>
	 */
	public function roles(): array {
		return $this->roles;
	}

	/**
	 * Porte-t-il ce rôle global ?
	 *
	 * Répondre `true` n'autorise rien : seule une politique décide, et elle
	 * doit consulter ce rôle explicitement.
	 *
	 * @param string $role Rôle recherché.
	 * @return bool
	 */
	public function a_role( string $role ): bool {
		return in_array( strtolower( trim( $role ) ), $this->roles, true );
	}

	/**
	 * @return bool
	 */
	public function courriel_verifie(): bool {
		return $this->courriel_verifie;
	}

	/**
	 * Deux acteurs sont-ils la même personne ?
	 *
	 * L'anonyme n'est identique à personne, pas même à un autre anonyme :
	 * deux visiteurs non authentifiés ne sont pas le même individu.
	 *
	 * @param self $autre Acteur comparé.
	 * @return bool
	 */
	public function est_le_meme_que( self $autre ): bool {
		if ( $this->est_anonyme() || $autre->est_anonyme() ) {
			return false;
		}

		return $this->id === $autre->id;
	}
}
