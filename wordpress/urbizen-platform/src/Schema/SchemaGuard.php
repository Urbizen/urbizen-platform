<?php
/**
 * Vérifie que le moteur offre réellement ce qu'une migration exige.
 *
 * Deux principes.
 *
 * **On sonde, on ne déduit pas d'un numéro de version.** MariaDB 10.2 annonce
 * les contraintes `CHECK` et, selon la configuration, ne les applique pas
 * toujours comme on l'attend. Un numéro décrit un binaire ; seule une
 * insertion refusée prouve qu'une contrainte mord.
 *
 * **On ne sonde que sur demande.** La sonde n'est exécutée que dans le cadre
 * d'une commande de migration ou de vérification qui l'exige, jamais au
 * chargement du greffon, jamais sur une visite. Avec un catalogue vide, cette
 * classe n'est pas même instanciée.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

use Urbizen\Platform\Domain\Support\Ulid;

/**
 * Sonde des capacités du moteur.
 */
final class SchemaGuard {

	/**
	 * Capacités connues.
	 */
	public const INNODB  = 'innodb';
	public const CHECK   = 'check';
	public const UTF8MB4 = 'utf8mb4';

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * Résultats déjà obtenus, pour ne pas sonder deux fois dans un même appel.
	 *
	 * @var array<string, bool>
	 */
	private array $connues = array();

	/**
	 * @param DatabaseGateway $db Passerelle.
	 */
	public function __construct( DatabaseGateway $db ) {
		// Aucun effet de bord : construire n'interroge pas.
		$this->db = $db;
	}

	/**
	 * Toutes ces capacités sont-elles présentes ?
	 *
	 * @param array<int, string> $requises Capacités exigées.
	 * @return string Chaîne vide si tout est présent, sinon la première manquante.
	 */
	public function premiere_absente( array $requises ): string {
		foreach ( $requises as $capacite ) {
			$capacite = strtolower( trim( (string) $capacite ) );

			if ( '' === $capacite ) {
				continue;
			}

			if ( ! $this->possede( $capacite ) ) {
				return $capacite;
			}
		}

		return '';
	}

	/**
	 * Le moteur possède-t-il cette capacité ?
	 *
	 * Une capacité inconnue est **refusée**. Répondre « oui » à ce qu'on ne
	 * sait pas éprouver reviendrait à valider n'importe quelle exigence future
	 * par simple faute de frappe.
	 *
	 * @param string $capacite Capacité.
	 * @return bool
	 */
	public function possede( string $capacite ): bool {
		$capacite = strtolower( trim( $capacite ) );

		if ( isset( $this->connues[ $capacite ] ) ) {
			return $this->connues[ $capacite ];
		}

		switch ( $capacite ) {
			case self::INNODB:
				$reponse = $this->sonder_innodb();
				break;

			case self::CHECK:
				$reponse = $this->sonder_check();
				break;

			case self::UTF8MB4:
				$reponse = $this->sonder_utf8mb4();
				break;

			default:
				$reponse = false;
		}

		$this->connues[ $capacite ] = $reponse;

		return $reponse;
	}

	/**
	 * InnoDB est-il disponible ?
	 *
	 * @return bool
	 */
	private function sonder_innodb(): bool {
		$support = $this->db->valeur(
			"SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'"
		);

		if ( null === $support ) {
			return false;
		}

		return in_array( strtoupper( $support ), array( 'YES', 'DEFAULT' ), true );
	}

	/**
	 * `utf8mb4` est-il disponible ?
	 *
	 * @return bool
	 */
	private function sonder_utf8mb4(): bool {
		$jeu = $this->db->valeur(
			"SELECT CHARACTER_SET_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = 'utf8mb4'"
		);

		return 'utf8mb4' === strtolower( (string) $jeu );
	}

	/**
	 * Les contraintes `CHECK` sont-elles réellement appliquées ?
	 *
	 * La sonde crée une table **temporaire**, au nom imprévisible, y insère une
	 * ligne conforme — qui doit passer — puis une ligne contraire — qui doit
	 * être refusée. Les deux moitiés comptent : une contrainte qui refuse tout
	 * n'est pas plus utilisable qu'une contrainte qui n'accepte rien.
	 *
	 * La table est supprimée dans un `finally`, donc y compris si une exception
	 * traverse. Étant temporaire, elle disparaîtrait de toute façon avec la
	 * connexion — mais on ne s'en remet pas à cela.
	 *
	 * @return bool
	 */
	private function sonder_check(): bool {
		// Nom imprévisible, et strictement contrôlé avant interpolation : le
		// nom d'une table ne peut pas être passé en paramètre préparé.
		$table = 'urbizen_sonde_' . strtolower( Ulid::generer() );

		if ( 1 !== preg_match( '/^[a-z0-9_]{1,64}$/', $table ) ) {
			return false;
		}

		$verdict = false;

		try {
			$cree = $this->db->executer(
				sprintf(
					'CREATE TEMPORARY TABLE `%s` ( a BIGINT NULL, b BIGINT NULL,
					 CONSTRAINT chk_sonde CHECK ( ( a IS NULL ) <> ( b IS NULL ) ) ) ENGINE=InnoDB',
					$table
				)
			);

			if ( ! $cree ) {
				return false;
			}

			// Moitié 1 : une ligne conforme doit être acceptée.
			$conforme = $this->db->executer(
				sprintf( 'INSERT INTO `%s` ( a, b ) VALUES ( 1, NULL )', $table )
			);

			if ( ! $conforme ) {
				return false;
			}

			// Moitié 2 : une ligne contraire doit être refusée.
			$contraire = $this->db->executer(
				sprintf( 'INSERT INTO `%s` ( a, b ) VALUES ( 1, 2 )', $table )
			);

			$verdict = ( false === $contraire );
		} finally {
			$this->db->executer( sprintf( 'DROP TEMPORARY TABLE IF EXISTS `%s`', $table ) );
		}

		return $verdict;
	}
}
