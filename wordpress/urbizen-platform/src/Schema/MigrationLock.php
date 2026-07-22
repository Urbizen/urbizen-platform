<?php
/**
 * Verrou d'exécution des migrations.
 *
 * Ce n'est pas un booléen. Un booléen ne dit pas **qui** détient le verrou, ni
 * **depuis quand** — donc il ne permet ni de refuser une libération étrangère,
 * ni de reprendre après un processus mort. La valeur porte trois champs :
 * propriétaire tiré au hasard, date de création, date d'expiration.
 *
 * Cinq règles en découlent :
 *
 * - **acquisition atomique** — `add_option()` échoue si la clé existe déjà,
 *   c'est la primitive employée pour les références et les notifications ;
 * - **durée bornée** — aucun verrou n'est éternel, aucun appelant n'attend
 *   indéfiniment ;
 * - **reprise d'un verrou expiré**, mais jamais d'un verrou vivant ;
 * - **libération par le seul propriétaire** — un processus ne supprime pas le
 *   verrou d'un autre, même expiré, même s'il croit bien faire ;
 * - **libération dans un `finally`**, à la charge de l'appelant.
 *
 * Course résiduelle assumée : entre la constatation d'expiration et la reprise,
 * deux processus peuvent se croiser. La seconde barrière est la contrainte
 * `UNIQUE` du registre, qui refusera d'enregistrer deux fois la même migration.
 * On ne compte jamais sur une seule défense.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

use Urbizen\Platform\Domain\Support\Ulid;

/**
 * Verrou de migration.
 */
final class MigrationLock {

	/**
	 * Nom de l'option portant le verrou.
	 *
	 * Elle n'existe **qu'à partir de la première migration réelle** : avec un
	 * catalogue vide, l'exécuteur rend la main avant d'arriver ici.
	 */
	public const OPTION = 'urbizen_schema_lock';

	/**
	 * Durée de vie, en secondes.
	 */
	public const TTL = 900;

	/**
	 * Propriétaire de ce verrou.
	 *
	 * @var string
	 */
	private string $proprietaire;

	/**
	 * @var int
	 */
	private int $expire_le;

	/**
	 * @param string $proprietaire Jeton du propriétaire.
	 * @param int    $expire_le    Échéance.
	 */
	private function __construct( string $proprietaire, int $expire_le ) {
		$this->proprietaire = $proprietaire;
		$this->expire_le    = $expire_le;
	}

	/**
	 * Tente d'acquérir.
	 *
	 * @param int|null $maintenant Horloge injectable.
	 * @return self|null `null` si le verrou est tenu par un vivant.
	 */
	public static function acquerir( ?int $maintenant = null ): ?self {
		$maintenant = null === $maintenant ? time() : $maintenant;

		$proprietaire = Ulid::generer();
		$expire_le    = $maintenant + self::TTL;

		$valeur = array(
			'proprietaire' => $proprietaire,
			'cree_le'      => $maintenant,
			'expire_le'    => $expire_le,
		);

		// Chemin normal : personne ne tient le verrou.
		if ( self::poser( $valeur ) ) {
			return new self( $proprietaire, $expire_le );
		}

		$existant = self::lire();

		if ( null === $existant ) {
			// L'option existe mais son contenu est illisible : on ne présume
			// pas qu'elle est libre, on refuse et on laisse un humain voir.
			self::journaliser( 'verrou de migration illisible, acquisition refusée' );

			return null;
		}

		if ( $existant['expire_le'] > $maintenant ) {
			self::journaliser(
				sprintf(
					'verrou de migration occupé jusqu’à %s',
					gmdate( 'Y-m-d H:i:s', $existant['expire_le'] )
				)
			);

			return null;
		}

		// Verrou expiré : on le retire puis on repose atomiquement. La
		// suppression seule ne suffirait pas — c'est `add_option()` qui
		// tranche entre deux repreneurs simultanés.
		self::journaliser(
			sprintf(
				'verrou de migration expiré depuis %d s, reprise',
				$maintenant - $existant['expire_le']
			)
		);

		self::retirer();

		if ( self::poser( $valeur ) ) {
			return new self( $proprietaire, $expire_le );
		}

		return null;
	}

	/**
	 * Libère, **si et seulement si** ce processus est le propriétaire.
	 *
	 * @return bool Vrai si la libération a eu lieu.
	 */
	public function liberer(): bool {
		$existant = self::lire();

		if ( null === $existant ) {
			return false;
		}

		if ( ! hash_equals( $existant['proprietaire'], $this->proprietaire ) ) {
			// Notre verrou a expiré et un autre l'a repris : le supprimer
			// interromprait son travail.
			self::journaliser( 'libération refusée : le verrou appartient à un autre processus' );

			return false;
		}

		return self::retirer();
	}

	/**
	 * Jeton du propriétaire.
	 *
	 * @return string
	 */
	public function proprietaire(): string {
		return $this->proprietaire;
	}

	/**
	 * @return int
	 */
	public function expire_le(): int {
		return $this->expire_le;
	}

	/**
	 * Ce verrou est-il encore valable ?
	 *
	 * @param int|null $maintenant Horloge injectable.
	 * @return bool
	 */
	public function est_vivant( ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;

		return $this->expire_le > $maintenant;
	}

	/**
	 * Pose atomiquement.
	 *
	 * @param array<string, mixed> $valeur Valeur.
	 * @return bool
	 */
	private static function poser( array $valeur ): bool {
		if ( ! function_exists( 'add_option' ) ) {
			return false;
		}

		// `autoload = no` : ce verrou n'a rien à faire dans le cache d'options
		// chargé à chaque requête.
		return (bool) add_option( self::OPTION, $valeur, '', 'no' );
	}

	/**
	 * Lit le verrou existant, ou `null` s'il est absent ou malformé.
	 *
	 * @return array{proprietaire: string, cree_le: int, expire_le: int}|null
	 */
	private static function lire(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$brut = get_option( self::OPTION, null );

		if ( ! is_array( $brut ) ) {
			return null;
		}

		foreach ( array( 'proprietaire', 'cree_le', 'expire_le' ) as $cle ) {
			if ( ! isset( $brut[ $cle ] ) ) {
				return null;
			}
		}

		$proprietaire = (string) $brut['proprietaire'];

		if ( ! Ulid::est_valide( $proprietaire ) ) {
			return null;
		}

		return array(
			'proprietaire' => $proprietaire,
			'cree_le'      => (int) $brut['cree_le'],
			'expire_le'    => (int) $brut['expire_le'],
		);
	}

	/**
	 * Retire l'option.
	 *
	 * @return bool
	 */
	private static function retirer(): bool {
		if ( ! function_exists( 'delete_option' ) ) {
			return false;
		}

		return (bool) delete_option( self::OPTION );
	}

	/**
	 * Journalise, si le journal est disponible.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function journaliser( string $message ): void {
		if ( class_exists( '\Urbizen\Platform\Support\Logger' ) ) {
			\Urbizen\Platform\Support\Logger::info( $message );
		}
	}
}
