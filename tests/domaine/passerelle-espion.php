<?php
/**
 * Doublures de passerelle pour les bancs.
 *
 * L'**espion muet** est l'instrument central d'E1 : il lève à tout appel, quel
 * qu'il soit. Branché sous l'exécuteur avec un catalogue vide, il transforme
 * la promesse « aucune requête » en fait vérifiable — si une seule ligne de
 * l'exécuteur touchait la base, le banc exploserait au lieu de passer.
 *
 * La **passerelle en mémoire** sert aux scénarios qui ont besoin d'un schéma
 * plausible sans base de données.
 */

declare( strict_types = 1 );

use Urbizen\Platform\Schema\DatabaseGateway;
use Urbizen\Platform\Schema\Migration;

/**
 * Passerelle qui refuse d'être utilisée.
 */
final class PasserelleMuette implements DatabaseGateway {

	/**
	 * Appels reçus, pour le rapport d'échec.
	 *
	 * @var array<int, string>
	 */
	public array $appels = array();

	/**
	 * @param string $methode Méthode sollicitée.
	 * @return never
	 *
	 * @throws RuntimeException Toujours.
	 */
	private function interdit( string $methode ) {
		$this->appels[] = $methode;

		throw new RuntimeException( "la passerelle a été sollicitée : $methode" );
	}

	public function prefixe(): string {
		$this->interdit( 'prefixe' );
	}

	public function executer( string $sql, array $parametres = array() ): bool {
		$this->interdit( 'executer' );
	}

	public function valeur( string $sql, array $parametres = array() ): ?string {
		$this->interdit( 'valeur' );
	}

	public function lignes( string $sql, array $parametres = array() ): array {
		$this->interdit( 'lignes' );
	}

	public function table_existe( string $nom ): bool {
		$this->interdit( 'table_existe' );
	}

	public function derniere_erreur(): string {
		$this->interdit( 'derniere_erreur' );
	}
}

/**
 * Passerelle en mémoire : tables et lignes simulées.
 */
final class PasserelleMemoire implements DatabaseGateway {

	/**
	 * Tables existantes.
	 *
	 * @var array<string, bool>
	 */
	public array $tables = array();

	/**
	 * Lignes du registre.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $registre = array();

	/**
	 * Instructions reçues.
	 *
	 * @var array<int, string>
	 */
	public array $instructions = array();

	/**
	 * Capacités annoncées par le faux moteur.
	 *
	 * @var array<string, bool>
	 */
	public array $capacites = array(
		'innodb'  => true,
		'utf8mb4' => true,
		'check'   => true,
	);

	/**
	 * `executer()` doit-elle échouer ?
	 *
	 * @var bool
	 */
	public bool $echouer = false;

	public function prefixe(): string {
		return 'wp_';
	}

	public function executer( string $sql, array $parametres = array() ): bool {
		$this->instructions[] = $sql;

		if ( $this->echouer ) {
			return false;
		}

		// Sonde de contrainte : la ligne contraire doit être refusée.
		if ( false !== strpos( $sql, 'VALUES ( 1, 2 )' ) ) {
			return (bool) ( $this->capacites['check'] ? false : true );
		}

		if ( 0 === strpos( $sql, 'CREATE TABLE' ) || false !== strpos( $sql, 'CREATE TABLE IF NOT EXISTS' ) ) {
			if ( 1 === preg_match( '/`([a-z0-9_]+)`/i', $sql, $m ) ) {
				$this->tables[ $m[1] ] = true;
			}
		}

		if ( 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, 'urbizen_migration' ) ) {
			$this->registre[] = array( 'migration' => (string) ( $parametres[0] ?? '' ) );
		}

		return true;
	}

	public function valeur( string $sql, array $parametres = array() ): ?string {
		$this->instructions[] = $sql;

		if ( false !== strpos( $sql, 'ENGINES' ) ) {
			return $this->capacites['innodb'] ? 'YES' : 'NO';
		}

		if ( false !== strpos( $sql, 'CHARACTER_SETS' ) ) {
			return $this->capacites['utf8mb4'] ? 'utf8mb4' : null;
		}

		return null;
	}

	public function lignes( string $sql, array $parametres = array() ): array {
		$this->instructions[] = $sql;

		return $this->registre;
	}

	public function table_existe( string $nom ): bool {
		return isset( $this->tables[ $nom ] );
	}

	public function derniere_erreur(): string {
		return '';
	}
}

/**
 * Migration d'épreuve, pilotable.
 */
final class MigrationEprouvee implements Migration {

	private string $id;

	/**
	 * @var array<int, string>
	 */
	private array $prerequis;

	private bool $verifie;

	private bool $leve;

	/**
	 * Nombre d'applications reçues, pour éprouver l'idempotence.
	 *
	 * @var int
	 */
	public int $applications = 0;

	/**
	 * @param string             $id        Identifiant.
	 * @param array<int, string> $prerequis Capacités exigées.
	 * @param bool               $verifie   Réponse de `verifier()`.
	 * @param bool               $leve      `appliquer()` doit-elle lever ?
	 */
	public function __construct(
		string $id,
		array $prerequis = array(),
		bool $verifie = true,
		bool $leve = false
	) {
		$this->id        = $id;
		$this->prerequis = $prerequis;
		$this->verifie   = $verifie;
		$this->leve      = $leve;
	}

	public function identifiant(): string {
		return $this->id;
	}

	public function prerequis(): array {
		return $this->prerequis;
	}

	public function appliquer( DatabaseGateway $db ): void {
		++$this->applications;

		if ( $this->leve ) {
			throw new RuntimeException( 'échec voulu' );
		}

		$db->executer( 'CREATE TABLE IF NOT EXISTS `wp_essai_' . $this->id . '` ( id INT )' );
	}

	public function verifier( DatabaseGateway $db ): bool {
		unset( $db );

		return $this->verifie;
	}
}
