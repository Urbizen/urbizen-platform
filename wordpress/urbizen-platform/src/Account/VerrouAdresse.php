<?php
/**
 * Verrou **temporaire** par adresse canonique, pour sérialiser l'inscription.
 *
 * `trouver_par_adresse()` puis `creer()` n'est pas atomique : WordPress ne pose
 * aucune contrainte SQL `UNIQUE` sur `user_email`, donc deux inscriptions
 * simultanées peuvent toutes deux constater l'absence avant d'insérer, et créer
 * deux utilisateurs pour la même boîte. Ce verrou ferme cette course.
 *
 * Il reprend le compare-et-échange de {@see VerrouCompte} — insertion via
 * l'index unique sur `option_name`, reprise conditionnelle d'un verrou expiré,
 * libération conditionnée à la valeur exacte — mais s'en distingue sur un point :
 *
 * **Le nom d'option ne porte jamais l'adresse en clair.** Il dérive d'un HMAC de
 * l'adresse canonique avec le secret du site : personne ne peut, en lisant la
 * table des options, retrouver quelles adresses s'inscrivent, ni fabriquer le
 * nom du verrou d'une adresse ciblée sans connaître le secret.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Urbizen\Platform\Domain\Support\Ulid;
use Urbizen\Platform\Schema\DatabaseGateway;

/**
 * Verrou d'inscription dérivé de l'adresse, à compare-et-échange.
 */
final class VerrouAdresse {

	/**
	 * Préfixe des options de verrou.
	 */
	public const PREFIXE = 'urbizen_adresse_lock_';

	/**
	 * Durée de vie, en secondes. Courte : une inscription dure des
	 * millisecondes ; un verrou long transformerait un processus mort en
	 * blocage durable de toute nouvelle inscription sur cette adresse.
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
	 * Secret du site, servant de clé au HMAC du nom d'option.
	 *
	 * `wp_salt()` est le secret de WordPress. Le repli n'est atteint qu'en
	 * l'absence de WordPress — les bancs sans WP — et n'affaiblit pas la
	 * production, qui dispose toujours de `wp_salt()`.
	 *
	 * @return string
	 */
	private static function secret(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return (string) wp_salt( 'auth' );
		}

		return 'urbizen-adresse-lock-secret-hors-wordpress';
	}

	/**
	 * Nom d'option pour une adresse canonique.
	 *
	 * HMAC-SHA-256 de l'adresse avec le secret du site : le nom ne révèle pas
	 * l'adresse et ne peut être forgé sans le secret.
	 *
	 * @param string $adresse Adresse canonique.
	 * @return string
	 */
	public static function option_pour( string $adresse ): string {
		return self::PREFIXE . substr( hash_hmac( 'sha256', $adresse, self::secret() ), 0, 32 );
	}

	/**
	 * Tente d'acquérir le verrou d'une adresse.
	 *
	 * @param DatabaseGateway $db         Passerelle.
	 * @param string          $adresse    Adresse canonique, non vide.
	 * @param int|null        $maintenant Horloge injectable.
	 * @return self|null `null` si une autre inscription est en cours sur cette adresse.
	 */
	public static function acquerir( DatabaseGateway $db, string $adresse, ?int $maintenant = null ): ?self {
		if ( '' === $adresse ) {
			return null;
		}

		$maintenant   = null === $maintenant ? time() : $maintenant;
		$option       = self::option_pour( $adresse );
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
