<?php
/**
 * Verrou **temporaire** par compte.
 *
 * Deux enseignements de `MigrationLock` sont repris, sans coupler les comptes
 * aux migrations : le verrou passe par `DatabaseGateway`, l'interface générique
 * d'E1, jamais par `MigrationLock` lui-même.
 *
 * **Premier enseignement — le compare-et-échange.** Reprendre un verrou expiré
 * par « lire, supprimer, reposer » laisse une fenêtre : entre la lecture et la
 * suppression, un processus neuf peut acquérir légitimement, et la suppression
 * inconditionnelle emporte son verrou. Les trois opérations qui touchent un
 * verrou existant — reprise, libération, prolongation — portent donc une
 * condition sur la valeur exacte lue, et exigent **une ligne touchée
 * précisément**.
 *
 * **Second enseignement — ce n'est pas une marque permanente.** Le verrou dit
 * « quelqu'un travaille ici en ce moment », jamais « ceci a déjà servi ». Un
 * échec le libère ; à défaut, il expire. C'est ce qui permet à un jeton
 * légitime de rester utilisable après une panne : rien de définitif n'est
 * inscrit sur le chemin de l'échec.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Urbizen\Platform\Domain\Support\Ulid;
use Urbizen\Platform\Schema\DatabaseGateway;

/**
 * Verrou de compte, à compare-et-échange.
 */
final class VerrouCompte {

	/**
	 * Préfixe des options de verrou.
	 */
	public const PREFIXE = 'urbizen_compte_lock_';

	/**
	 * Durée de vie, en secondes.
	 *
	 * Courte : une opération de compte dure des millisecondes. Un verrou
	 * long transformerait un processus mort en blocage durable.
	 */
	public const TTL = 60;

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * @var string
	 */
	private string $option;

	/**
	 * @var string
	 */
	private string $proprietaire;

	/**
	 * Valeur exacte en base, telle qu'on l'y a écrite.
	 *
	 * @var string
	 */
	private string $valeur_courante;

	/**
	 * @var int
	 */
	private int $expire_le;

	/**
	 * @var bool
	 */
	private bool $actif = true;

	/**
	 * @param DatabaseGateway $db              Passerelle.
	 * @param string          $option          Nom d'option.
	 * @param string          $proprietaire    Jeton du propriétaire.
	 * @param string          $valeur_courante Valeur en base.
	 * @param int             $expire_le       Échéance.
	 */
	private function __construct(
		DatabaseGateway $db,
		string $option,
		string $proprietaire,
		string $valeur_courante,
		int $expire_le
	) {
		$this->db              = $db;
		$this->option          = $option;
		$this->proprietaire    = $proprietaire;
		$this->valeur_courante = $valeur_courante;
		$this->expire_le       = $expire_le;
	}

	/**
	 * Nom d'option pour un compte.
	 *
	 * L'identifiant est passé par un condensat : le nom d'option ne révèle
	 * alors pas quels comptes sont actifs à qui lirait la table.
	 *
	 * @param int $compte Identifiant.
	 * @return string
	 */
	public static function option_pour( int $compte ): string {
		return self::PREFIXE . substr( hash( 'sha256', (string) $compte ), 0, 32 );
	}

	/**
	 * Tente d'acquérir le verrou d'un compte.
	 *
	 * @param DatabaseGateway $db         Passerelle.
	 * @param int             $compte     Identifiant.
	 * @param int|null        $maintenant Horloge injectable.
	 * @return self|null `null` si un autre processus travaille sur ce compte.
	 */
	public static function acquerir( DatabaseGateway $db, int $compte, ?int $maintenant = null ): ?self {
		if ( $compte <= 0 ) {
			return null;
		}

		$maintenant   = null === $maintenant ? time() : $maintenant;
		$option       = self::option_pour( $compte );
		$proprietaire = Ulid::generer();
		$expire_le    = $maintenant + self::TTL;
		$valeur       = self::encoder( $proprietaire, $maintenant, $expire_le );

		// Chemin 1 : personne ne tient le verrou. L'index unique sur
		// `option_name` tranche entre deux insertions simultanées.
		$pose = $db->lignes_affectees(
			sprintf(
				'INSERT INTO `%s` ( option_name, option_value, autoload ) VALUES ( %%s, %%s, %%s )',
				self::table( $db )
			),
			array( $option, $valeur, 'no' )
		);

		if ( 1 === $pose ) {
			self::vider_cache( $option );

			return new self( $db, $option, $proprietaire, $valeur, $expire_le );
		}

		// Chemin 2 : quelqu'un tient, ou tenait.
		$brut = $db->valeur(
			sprintf( 'SELECT option_value FROM `%s` WHERE option_name = %%s', self::table( $db ) ),
			array( $option )
		);

		if ( null === $brut ) {
			return null;
		}

		$existant = self::decoder( $brut );

		if ( null === $existant || $existant['expire_le'] > $maintenant ) {
			// Illisible ou vivant : on refuse plutôt que de présumer libre.
			return null;
		}

		// Reprise d'un verrou expiré, par compare-et-échange sur la valeur
		// exacte lue. Deux repreneurs simultanés : un seul touche une ligne.
		$repris = $db->lignes_affectees(
			sprintf(
				'UPDATE `%s` SET option_value = %%s WHERE option_name = %%s AND option_value = %%s',
				self::table( $db )
			),
			array( $valeur, $option, $brut )
		);

		if ( 1 !== $repris ) {
			return null;
		}

		self::vider_cache( $option );

		return new self( $db, $option, $proprietaire, $valeur, $expire_le );
	}

	/**
	 * Libère, **si et seulement si** la valeur en base est encore la nôtre.
	 *
	 * La condition porte sur la valeur complète, pas seulement sur le nom :
	 * une instance dont le verrou a expiré et été repris ne peut donc pas
	 * supprimer celui de son successeur.
	 *
	 * @return bool
	 */
	public function liberer(): bool {
		if ( ! $this->actif ) {
			return false;
		}

		$supprimees = $this->db->lignes_affectees(
			sprintf(
				'DELETE FROM `%s` WHERE option_name = %%s AND option_value = %%s',
				self::table( $this->db )
			),
			array( $this->option, $this->valeur_courante )
		);

		$this->actif = false;

		self::vider_cache( $this->option );

		return 1 === $supprimees;
	}

	/**
	 * @return string
	 */
	public function proprietaire(): string {
		return $this->proprietaire;
	}

	/**
	 * @param int|null $maintenant Horloge injectable.
	 * @return bool
	 */
	public function est_vivant( ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;

		return $this->actif && $this->expire_le > $maintenant;
	}

	/**
	 * @param DatabaseGateway $db Passerelle.
	 * @return string
	 */
	private static function table( DatabaseGateway $db ): string {
		return $db->prefixe() . 'options';
	}

	/**
	 * Encode en JSON : la valeur stockée doit être prévisible au caractère
	 * près pour servir de condition dans un `WHERE`.
	 *
	 * @param string $proprietaire Jeton.
	 * @param int    $cree_le      Création.
	 * @param int    $expire_le    Échéance.
	 * @return string
	 */
	private static function encoder( string $proprietaire, int $cree_le, int $expire_le ): string {
		return (string) json_encode(
			array(
				'proprietaire' => $proprietaire,
				'cree_le'      => $cree_le,
				'expire_le'    => $expire_le,
			)
		);
	}

	/**
	 * @param string $brut Valeur brute.
	 * @return array{proprietaire: string, cree_le: int, expire_le: int}|null
	 */
	private static function decoder( string $brut ): ?array {
		$decode = json_decode( $brut, true );

		if ( ! is_array( $decode ) ) {
			return null;
		}

		foreach ( array( 'proprietaire', 'cree_le', 'expire_le' ) as $cle ) {
			if ( ! isset( $decode[ $cle ] ) ) {
				return null;
			}
		}

		if ( ! Ulid::est_valide( (string) $decode['proprietaire'] ) ) {
			return null;
		}

		return array(
			'proprietaire' => (string) $decode['proprietaire'],
			'cree_le'      => (int) $decode['cree_le'],
			'expire_le'    => (int) $decode['expire_le'],
		);
	}

	/**
	 * @param string $option Nom d'option.
	 * @return void
	 */
	private static function vider_cache( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}
}
