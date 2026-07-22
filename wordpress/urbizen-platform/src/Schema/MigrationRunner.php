<?php
/**
 * Exécuteur de migrations.
 *
 * **Il n'est accroché à aucun hook.** Ni `plugins_loaded`, ni `init`, ni
 * `admin_init`, ni l'activation. Une visite publique, une visite de
 * l'administration ou un déploiement partiel ne peuvent pas le déclencher. Son
 * seul point d'entrée prévu est la commande `wp urbizen schema migrate`, donc
 * un geste explicite, tracé, dont le code de sortie peut arrêter un
 * déploiement.
 *
 * Le motif est simple : faire dépendre l'état d'un schéma du trafic, c'est
 * confier une migration au premier visiteur venu, éventuellement plusieurs à
 * la fois, éventuellement sur un greffon à moitié copié.
 *
 * **Garantie tenue par les trois premières lignes de `executer()`** : catalogue
 * vide, retour immédiat, aucun appel à la passerelle. Aucune table, aucune
 * option, aucun verrou, aucun transient.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

use Throwable;

/**
 * Applique les migrations déclarées.
 */
final class MigrationRunner {

	/**
	 * Nom court du registre, préfixe non compris.
	 */
	public const TABLE = 'urbizen_migration';

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * @var MigrationCatalogue
	 */
	private MigrationCatalogue $catalogue;

	/**
	 * @param DatabaseGateway    $db        Passerelle.
	 * @param MigrationCatalogue $catalogue Catalogue.
	 */
	public function __construct( DatabaseGateway $db, MigrationCatalogue $catalogue ) {
		// Aucune requête ici. Construire n'interroge pas : c'est ce qui rend
		// la garantie « catalogue vide, zéro SQL » vérifiable.
		$this->db        = $db;
		$this->catalogue = $catalogue;
	}

	/**
	 * Applique ce qui manque.
	 *
	 * @param int|null $maintenant Horloge injectable.
	 * @return ResultatMigration
	 */
	public function executer( ?int $maintenant = null ): ResultatMigration {
		// (1) Le catalogue d'abord. C'est un tableau PHP : aucune requête.
		if ( $this->catalogue->est_vide() ) {
			return ResultatMigration::rien();
		}

		$verrou = MigrationLock::acquerir( $this->db, $maintenant );

		if ( null === $verrou ) {
			return ResultatMigration::echec( '', 'verrou_indisponible' );
		}

		try {
			return $this->appliquer_series( $verrou, $maintenant );
		} catch ( Throwable $e ) {
			return ResultatMigration::echec( '', 'exception : ' . $e->getMessage() );
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * État du schéma, sans rien appliquer.
	 *
	 * @return ResultatMigration
	 */
	public function etat(): ResultatMigration {
		if ( $this->catalogue->est_vide() ) {
			return ResultatMigration::rien();
		}

		$appliquees = $this->deja_appliquees();
		$manquantes = array();

		foreach ( $this->catalogue->declarees() as $migration ) {
			if ( ! in_array( $migration->identifiant(), $appliquees, true ) ) {
				$manquantes[] = $migration->identifiant();
			}
		}

		if ( array() === $manquantes ) {
			return ResultatMigration::succes( array(), $appliquees );
		}

		return ResultatMigration::echec(
			$manquantes[0],
			sprintf( '%d migration(s) manquante(s)', count( $manquantes ) ),
			array(),
			$appliquees
		);
	}

	/**
	 * Rejoue `verifier()` sur toutes les migrations déclarées.
	 *
	 * C'est le contrôle final d'un déploiement : le registre affirme qu'on a
	 * appliqué, cette méthode demande à la base si c'est vrai.
	 *
	 * @return ResultatMigration
	 */
	public function verifier(): ResultatMigration {
		if ( $this->catalogue->est_vide() ) {
			return ResultatMigration::rien();
		}

		$verifiees = array();

		foreach ( $this->catalogue->declarees() as $migration ) {
			if ( ! $migration->verifier( $this->db ) ) {
				return ResultatMigration::echec(
					$migration->identifiant(),
					'etat_attendu_absent',
					array(),
					$verifiees
				);
			}

			$verifiees[] = $migration->identifiant();
		}

		return ResultatMigration::succes( array(), $verifiees );
	}

	/**
	 * Applique la série, verrou déjà tenu.
	 *
	 * @param MigrationLock $verrou     Verrou détenu.
	 * @param int|null      $maintenant Horloge.
	 * @return ResultatMigration
	 */
	private function appliquer_series( MigrationLock $verrou, ?int $maintenant ): ResultatMigration {
		if ( ! $this->assurer_registre() ) {
			return ResultatMigration::echec( '', 'registre_indisponible' );
		}

		$deja       = $this->deja_appliquees();
		$appliquees = array();
		$garde      = new SchemaGuard( $this->db );

		foreach ( $this->catalogue->declarees() as $migration ) {
			$id = $migration->identifiant();

			if ( in_array( $id, $deja, true ) ) {
				continue;
			}

			/*
			 * Avant chaque migration, on prolonge si l'échéance approche — et
			 * l'on s'arrête si la propriété est perdue.
			 *
			 * Sans cela, une série longue verrait son verrou expirer en cours
			 * de route, un second processus le reprendrait légitimement, et
			 * deux `appliquer()` tourneraient de concert. La contrainte
			 * `UNIQUE` du registre n'y changerait rien : elle intervient
			 * APRÈS l'application, quand le mal est fait.
			 */
			if ( ! $verrou->renouveler_si_besoin( $maintenant ) ) {
				return ResultatMigration::echec( $id, 'verrou_perdu', $appliquees, $deja );
			}

			$absente = $garde->premiere_absente( $migration->prerequis() );

			if ( '' !== $absente ) {
				return ResultatMigration::echec(
					$id,
					'capacite_absente : ' . $absente,
					$appliquees,
					$deja
				);
			}

			$debut = microtime( true );

			try {
				$migration->appliquer( $this->db );
			} catch ( Throwable $e ) {
				return ResultatMigration::echec(
					$id,
					'appliquer a levé : ' . $e->getMessage(),
					$appliquees,
					$deja
				);
			}

			// Rien n'est inscrit avant que la base ait confirmé l'état
			// attendu : un registre qui affirme un succès inexistant est pire
			// qu'un registre vide.
			if ( ! $migration->verifier( $this->db ) ) {
				return ResultatMigration::echec( $id, 'verification_negative', $appliquees, $deja );
			}

			if ( ! $this->inscrire( $id, (int) round( ( microtime( true ) - $debut ) * 1000 ), $maintenant ) ) {
				return ResultatMigration::echec( $id, 'inscription_registre_refusee', $appliquees, $deja );
			}

			$appliquees[] = $id;
		}

		return ResultatMigration::succes( $appliquees, $deja );
	}

	/**
	 * Crée le registre s'il manque.
	 *
	 * **Cette méthode n'est jamais atteinte avec un catalogue vide** : c'est
	 * ce qui garantit qu'E1 ne crée aucune table.
	 *
	 * La contrainte `UNIQUE` sur l'identifiant est la seconde barrière contre
	 * une double application : même si le verrou tombait, la base refuserait
	 * la seconde inscription.
	 *
	 * @return bool
	 */
	private function assurer_registre(): bool {
		$table = $this->table();

		if ( $this->db->table_existe( $table ) ) {
			return true;
		}

		$cree = $this->db->executer(
			sprintf(
				'CREATE TABLE IF NOT EXISTS `%s` (
					id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					migration    VARCHAR(64)     NOT NULL,
					applique_le  DATETIME        NOT NULL,
					duree_ms     INT UNSIGNED    NOT NULL DEFAULT 0,
					PRIMARY KEY (id),
					UNIQUE KEY uk_migration (migration)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
				$table
			)
		);

		return $cree && $this->db->table_existe( $table );
	}

	/**
	 * Identifiants déjà inscrits.
	 *
	 * @return array<int, string>
	 */
	private function deja_appliquees(): array {
		$table = $this->table();

		if ( ! $this->db->table_existe( $table ) ) {
			return array();
		}

		$lignes = $this->db->lignes(
			sprintf( 'SELECT migration FROM `%s` ORDER BY migration ASC', $table )
		);

		$ids = array();

		foreach ( $lignes as $ligne ) {
			if ( isset( $ligne['migration'] ) ) {
				$ids[] = (string) $ligne['migration'];
			}
		}

		return $ids;
	}

	/**
	 * Inscrit une migration appliquée.
	 *
	 * @param string   $identifiant Identifiant.
	 * @param int      $duree_ms    Durée.
	 * @param int|null $maintenant  Horloge.
	 * @return bool
	 */
	private function inscrire( string $identifiant, int $duree_ms, ?int $maintenant ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;

		return $this->db->executer(
			sprintf(
				'INSERT INTO `%s` ( migration, applique_le, duree_ms ) VALUES ( %%s, %%s, %%d )',
				$this->table()
			),
			array( $identifiant, gmdate( 'Y-m-d H:i:s', $maintenant ), $duree_ms )
		);
	}

	/**
	 * Nom complet du registre.
	 *
	 * Le préfixe est **toujours calculé**, jamais écrit en dur : une
	 * installation dont le préfixe n'est pas `wp_` doit fonctionner sans
	 * retouche.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefixe() . self::TABLE;
	}
}
