<?php
/**
 * Verrou d'exécution des migrations.
 *
 * ## Le défaut que ce fichier corrige
 *
 * Une première version reprenait un verrou expiré en trois temps : lire,
 * supprimer, reposer. `add_option()` étant atomique, il paraissait suffire à
 * départager deux repreneurs. **Il ne suffisait pas**, et pas pour la raison
 * qu'on croyait :
 *
 *   P1 lit un verrou expiré.
 *   P2 lit le même verrou expiré.
 *   P1 supprime.
 *   P3 — processus neuf, pas un repreneur — acquiert légitimement.
 *   P2 supprime  ← il détruit le verrou tout neuf de P3.
 *   P2 acquiert.
 *   → P2 et P3 se croient tous deux propriétaires.
 *
 * La suppression était **inconditionnelle** : elle emportait ce qui se
 * trouvait là, y compris un verrou vivant posé entre-temps.
 *
 * ## Ce qui le remplace
 *
 * Un **compare-et-échange** : on ne remplace la valeur que si elle est encore,
 * octet pour octet, celle qu'on a lue, et l'on exige que la base rende
 * exactement une ligne touchée. Deux repreneurs simultanés lisent la même
 * valeur ; le premier à écrire la change, le second ne trouve plus sa
 * condition et repart les mains vides.
 *
 * Les trois opérations qui touchent un verrou existant — reprise,
 * renouvellement, libération — passent toutes par cette condition. Aucune ne
 * peut atteindre le verrou d'un autre.
 *
 * La valeur est du JSON, et non un tableau PHP sérialisé : le contenu stocké
 * est alors parfaitement prévisible, donc comparable dans un `WHERE`.
 *
 * ## Ce que la contrainte `UNIQUE` du registre ne fait pas
 *
 * Elle empêche d'**inscrire** deux fois la même migration. Elle intervient
 * après `appliquer()`. Elle ne peut donc pas empêcher deux processus de
 * l'exécuter en même temps — seul le verrou le peut. La présenter comme une
 * seconde barrière contre l'exécution concurrente était une erreur.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

use Urbizen\Platform\Domain\Support\Ulid;

/**
 * Verrou de migration, à compare-et-échange.
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
	 * Seuil de renouvellement : en deçà, le propriétaire prolonge.
	 */
	public const SEUIL_RENOUVELLEMENT = 300;

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * @var string
	 */
	private string $proprietaire;

	/**
	 * Valeur exacte actuellement en base, telle qu'on l'y a écrite.
	 *
	 * C'est la clé du compare-et-échange : sans elle, on ne pourrait pas
	 * exiger « remplace uniquement si c'est encore ma valeur ».
	 *
	 * @var string
	 */
	private string $valeur_courante;

	/**
	 * @var int
	 */
	private int $expire_le;

	/**
	 * Ce verrou a-t-il été perdu ou rendu ?
	 *
	 * @var bool
	 */
	private bool $actif = true;

	/**
	 * @param DatabaseGateway $db              Passerelle.
	 * @param string          $proprietaire    Jeton du propriétaire.
	 * @param string          $valeur_courante Valeur en base.
	 * @param int             $expire_le       Échéance.
	 */
	private function __construct(
		DatabaseGateway $db,
		string $proprietaire,
		string $valeur_courante,
		int $expire_le
	) {
		$this->db              = $db;
		$this->proprietaire    = $proprietaire;
		$this->valeur_courante = $valeur_courante;
		$this->expire_le       = $expire_le;
	}

	/**
	 * Tente d'acquérir.
	 *
	 * Deux chemins, tous deux atomiques :
	 *
	 * - **verrou absent** — `INSERT` sur une colonne à index unique : deux
	 *   processus simultanés, un seul passe, l'autre reçoit une erreur ;
	 * - **verrou expiré** — `UPDATE ... WHERE option_value = <valeur lue>` :
	 *   deux repreneurs simultanés, un seul touche une ligne.
	 *
	 * Un verrou vivant n'est jamais touché.
	 *
	 * @param DatabaseGateway $db         Passerelle.
	 * @param int|null        $maintenant Horloge injectable.
	 * @return self|null
	 */
	public static function acquerir( DatabaseGateway $db, ?int $maintenant = null ): ?self {
		$maintenant = null === $maintenant ? time() : $maintenant;

		$proprietaire = Ulid::generer();
		$expire_le    = $maintenant + self::TTL;
		$valeur       = self::encoder( $proprietaire, $maintenant, $expire_le );

		// Chemin 1 : personne ne tient le verrou.
		$pose = $db->lignes_affectees(
			sprintf(
				'INSERT INTO `%s` ( option_name, option_value, autoload ) VALUES ( %%s, %%s, %%s )',
				self::table( $db )
			),
			array( self::OPTION, $valeur, 'no' )
		);

		if ( 1 === $pose ) {
			self::vider_cache();

			return new self( $db, $proprietaire, $valeur, $expire_le );
		}

		// Chemin 2 : quelqu'un tient, ou tenait.
		$brut = self::lire_brut( $db );

		if ( null === $brut ) {
			// L'option existe mais on n'a pas pu la lire : on refuse plutôt
			// que de présumer le champ libre.
			self::journaliser( 'verrou de migration illisible, acquisition refusée' );

			return null;
		}

		$existant = self::decoder( $brut );

		if ( null === $existant ) {
			self::journaliser( 'verrou de migration malformé, acquisition refusée' );

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

		// Reprise par compare-et-échange. La condition porte sur la valeur
		// **exacte** qu'on vient de lire : si elle a changé entre-temps — parce
		// qu'un autre a repris, ou parce qu'un processus neuf a acquis — la
		// mise à jour ne touche aucune ligne et nous repartons sans rien.
		$repris = $db->lignes_affectees(
			sprintf(
				'UPDATE `%s` SET option_value = %%s WHERE option_name = %%s AND option_value = %%s',
				self::table( $db )
			),
			array( $valeur, self::OPTION, $brut )
		);

		if ( 1 !== $repris ) {
			self::journaliser( 'reprise du verrou perdue au profit d’un autre processus' );

			return null;
		}

		self::vider_cache();
		self::journaliser(
			sprintf( 'verrou de migration expiré depuis %d s, repris', $maintenant - $existant['expire_le'] )
		);

		return new self( $db, $proprietaire, $valeur, $expire_le );
	}

	/**
	 * Prolonge, si et seulement si nous détenons encore le verrou.
	 *
	 * Même primitive que la reprise : la condition porte sur notre propre
	 * valeur. Si un autre processus nous a repris — parce que nous avons
	 * dépassé l'échéance —, la mise à jour ne touche rien et nous l'apprenons.
	 *
	 * @param int|null $maintenant Horloge injectable.
	 * @return bool Faux si le verrou est perdu ; l'appelant DOIT alors s'arrêter.
	 */
	public function renouveler( ?int $maintenant = null ): bool {
		if ( ! $this->actif ) {
			return false;
		}

		$maintenant = null === $maintenant ? time() : $maintenant;
		$expire_le  = $maintenant + self::TTL;
		$nouvelle   = self::encoder( $this->proprietaire, $maintenant, $expire_le );

		$touchees = $this->db->lignes_affectees(
			sprintf(
				'UPDATE `%s` SET option_value = %%s WHERE option_name = %%s AND option_value = %%s',
				self::table( $this->db )
			),
			array( $nouvelle, self::OPTION, $this->valeur_courante )
		);

		if ( 1 !== $touchees ) {
			// Nous ne détenons plus rien. On se marque inactif pour qu'une
			// libération ultérieure ne puisse pas emporter le verrou d'autrui.
			$this->actif = false;

			self::journaliser( 'renouvellement du verrou refusé : propriété perdue' );

			return false;
		}

		self::vider_cache();

		$this->valeur_courante = $nouvelle;
		$this->expire_le       = $expire_le;

		return true;
	}

	/**
	 * Prolonge si l'échéance approche.
	 *
	 * @param int|null $maintenant Horloge injectable.
	 * @return bool Faux uniquement si un renouvellement nécessaire a échoué.
	 */
	public function renouveler_si_besoin( ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;

		if ( ( $this->expire_le - $maintenant ) > self::SEUIL_RENOUVELLEMENT ) {
			return true;
		}

		return $this->renouveler( $maintenant );
	}

	/**
	 * Libère, **si et seulement si** la valeur en base est encore la nôtre.
	 *
	 * La condition n'est pas seulement le nom de l'option : c'est la valeur
	 * complète. Une instance dont le verrou a expiré et été repris ne peut donc
	 * pas supprimer celui de son successeur.
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
			array( self::OPTION, $this->valeur_courante )
		);

		$this->actif = false;

		self::vider_cache();

		if ( 1 !== $supprimees ) {
			self::journaliser( 'libération sans effet : le verrou ne nous appartenait plus' );

			return false;
		}

		return true;
	}

	/**
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
	 * @param int|null $maintenant Horloge injectable.
	 * @return bool
	 */
	public function est_vivant( ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;

		return $this->actif && $this->expire_le > $maintenant;
	}

	/**
	 * Table des options de l'installation.
	 *
	 * @param DatabaseGateway $db Passerelle.
	 * @return string
	 */
	private static function table( DatabaseGateway $db ): string {
		return $db->prefixe() . 'options';
	}

	/**
	 * Encode la valeur du verrou.
	 *
	 * JSON, et non un tableau sérialisé : le contenu stocké doit être
	 * prévisible au caractère près pour servir de condition dans un `WHERE`.
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
	 * Décode, ou `null` si la valeur est inexploitable.
	 *
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
	 * Lit la valeur brute, sans cache.
	 *
	 * On interroge la base directement plutôt que `get_option()` : un cache
	 * d'objets rendrait une valeur périmée, et un compare-et-échange fondé sur
	 * une lecture périmée ne compare rien.
	 *
	 * @param DatabaseGateway $db Passerelle.
	 * @return string|null
	 */
	private static function lire_brut( DatabaseGateway $db ): ?string {
		return $db->valeur(
			sprintf( 'SELECT option_value FROM `%s` WHERE option_name = %%s', self::table( $db ) ),
			array( self::OPTION )
		);
	}

	/**
	 * Invalide le cache d'options de WordPress.
	 *
	 * Nous écrivons en SQL direct : sans cette purge, `get_option()` continuerait
	 * de rendre l'ancienne valeur dans la même requête.
	 *
	 * @return void
	 */
	private static function vider_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
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
