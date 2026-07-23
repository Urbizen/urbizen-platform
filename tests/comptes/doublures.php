<?php
/**
 * Doublures des deux ports.
 *
 * Elles sont **fidèles sur ce qui compte**, et le projet a payé assez cher
 * pour que la règle soit rappelée : une doublure permissive valide un code qui
 * a perdu sa garantie. Ici, deux fidélités sont décisives —
 *
 *   `PasserelleOptions` n'applique la condition sur l'ancienne valeur que si
 *   le SQL la porte réellement ;
 *   `ComptesDouble` distingue une métadonnée absente d'une métadonnée vide.
 */

declare( strict_types = 1 );

use Urbizen\Platform\Account\ComptesGateway;

/**
 * Journal d'événements partagé par les deux doublures.
 *
 * Il rend l'ORDRE des opérations observable — et c'est la seule façon de
 * prouver qu'aucune lecture ne précède l'acquisition du verrou. Un
 * entrelacement ne le prouverait pas : le code correct ne lisant rien avant le
 * verrou, rien ne peut y devenir périmé, et la course n'a pas lieu.
 */
final class JournalEvenements {

	/**
	 * @var array<int, string>
	 */
	public static array $evenements = array();

	public static function reset(): void {
		self::$evenements = array();
	}

	public static function noter( string $evenement ): void {
		self::$evenements[] = $evenement;
	}

	/**
	 * Rang du premier événement correspondant, ou -1.
	 *
	 * @param string $motif Motif recherché.
	 * @return int
	 */
	public static function premier( string $motif ): int {
		foreach ( self::$evenements as $rang => $evenement ) {
			if ( false !== strpos( $evenement, $motif ) ) {
				return $rang;
			}
		}

		return -1;
	}
}
use Urbizen\Platform\Domain\Account\AdresseCourriel;
use Urbizen\Platform\Domain\Account\Compte;
use Urbizen\Platform\Schema\DatabaseGateway;

/**
 * Table d'options simulée, à sémantique exacte.
 */
final class PasserelleOptions implements DatabaseGateway {

	/**
	 * @var array<string, string>
	 */
	public array $options = array();

	/**
	 * @var array<int, string>
	 */
	public array $instructions = array();

	public function prefixe(): string {
		return 'wp_';
	}

	public function executer( string $sql, array $parametres = array() ): bool {
		return $this->lignes_affectees( $sql, $parametres ) >= 0;
	}

	public function valeur( string $sql, array $parametres = array() ): ?string {
		$this->instructions[] = $sql;

		if ( false !== strpos( $sql, 'option_value' ) && false !== strpos( $sql, 'option_name' ) ) {
			return $this->options[ (string) ( $parametres[0] ?? '' ) ] ?? null;
		}

		return null;
	}

	public function lignes( string $sql, array $parametres = array() ): array {
		$this->instructions[] = $sql;

		return array();
	}

	public function lignes_affectees( string $sql, array $parametres = array() ): int {
		$this->instructions[] = $sql;

		if ( 0 === strpos( $sql, 'INSERT INTO' ) ) {
			JournalEvenements::noter( 'verrou:pose' );
			$nom = (string) ( $parametres[0] ?? '' );

			// L'index unique de `option_name` : une seule insertion passe.
			if ( array_key_exists( $nom, $this->options ) ) {
				return -1;
			}

			$this->options[ $nom ] = (string) ( $parametres[1] ?? '' );

			return 1;
		}

		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			$nouvelle = (string) ( $parametres[0] ?? '' );
			$nom      = (string) ( $parametres[1] ?? '' );
			$attendue = (string) ( $parametres[2] ?? '' );

			if ( ! array_key_exists( $nom, $this->options ) ) {
				return 0;
			}

			if ( $this->porte_condition( $sql ) && $this->options[ $nom ] !== $attendue ) {
				return 0;
			}

			$this->options[ $nom ] = $nouvelle;

			return 1;
		}

		if ( 0 === strpos( $sql, 'DELETE FROM' ) ) {
			$nom      = (string) ( $parametres[0] ?? '' );
			$attendue = (string) ( $parametres[1] ?? '' );

			if ( ! array_key_exists( $nom, $this->options ) ) {
				return 0;
			}

			if ( $this->porte_condition( $sql ) && $this->options[ $nom ] !== $attendue ) {
				return 0;
			}

			unset( $this->options[ $nom ] );

			return 1;
		}

		return 0;
	}

	public function table_existe( string $nom ): bool {
		return true;
	}

	public function derniere_erreur(): string {
		return '';
	}

	/**
	 * L'instruction porte-t-elle réellement la condition sur l'ancienne valeur ?
	 *
	 * @param string $sql Instruction.
	 * @return bool
	 */
	private function porte_condition( string $sql ): bool {
		return 1 === preg_match( '/option_value\s*=\s*%s/', $sql );
	}
}

/**
 * Port des comptes, en mémoire.
 */
final class ComptesDouble implements ComptesGateway {

	/**
	 * Utilisateurs : id → ['adresse' => …].
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $utilisateurs = array();

	/**
	 * Métadonnées : id → clé → valeur.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $metas = array();

	/**
	 * @var bool
	 */
	public bool $role_conforme = true;

	/**
	 * Prochain identifiant attribué.
	 *
	 * @var int
	 */
	public int $suivant = 100;

	/**
	 * Identifiants déjà employés — pour éprouver les collisions.
	 *
	 * @var array<string, bool>
	 */
	public array $logins = array();

	/**
	 * Nombre de créations à refuser avant d'accepter.
	 *
	 * @var int
	 */
	public int $refuser_creations = 0;

	/**
	 * Clés dont l'écriture doit échouer.
	 *
	 * @var array<int, string>
	 */
	public array $ecritures_refusees = array();

	/**
	 * La promotion doit-elle échouer ?
	 *
	 * @var bool
	 */
	public bool $promotion_echoue = false;

	/**
	 * Piège : rappel exécuté APRÈS la lecture d'une clé donnée, une seule fois.
	 *
	 * Il sert à reproduire un entrelacement réel dans un seul processus : sans
	 * lui, toute lecture suit l'écriture concurrente, et une mutation qui
	 * supprimerait la relecture sous verrou passerait inaperçue.
	 *
	 * @var array{cle: string, rappel: callable}|null
	 */
	public ?array $piege = null;

	public function canoniser( string $brute ): string {
		$valeur = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $brute );

		return strtolower( trim( $valeur ) );
	}

	public function trouver_par_id( int $id ): ?Compte {
		if ( ! isset( $this->utilisateurs[ $id ] ) ) {
			return null;
		}

		$adresse = AdresseCourriel::ou_null( $this->utilisateurs[ $id ]['adresse'] );

		if ( null === $adresse ) {
			return null;
		}

		$verifie = '1' === ( $this->metas[ $id ]['_urbizen_courriel_verifie'] ?? '' );

		$brute      = $this->metas[ $id ]['_urbizen_courriel_en_attente'] ?? '';
		$en_attente = '' === $brute ? null : AdresseCourriel::ou_null( $brute );

		return new Compte( $id, $adresse, $verifie, $en_attente );
	}

	public function trouver_par_adresse( string $canonique ): ?Compte {
		foreach ( $this->utilisateurs as $id => $donnees ) {
			if ( $donnees['adresse'] === $canonique ) {
				return $this->trouver_par_id( $id );
			}
		}

		return null;
	}

	public function adresse_disponible( string $canonique, int $sauf_id = 0 ): bool {
		foreach ( $this->utilisateurs as $id => $donnees ) {
			if ( $donnees['adresse'] === $canonique ) {
				return $id === $sauf_id;
			}
		}

		return true;
	}

	public function creer( string $identifiant, string $canonique, string $mot_de_passe ): int {
		if ( ! $this->role_conforme ) {
			return 0;
		}

		if ( $this->refuser_creations > 0 ) {
			--$this->refuser_creations;
			$this->logins[ $identifiant ] = true;

			return 0;
		}

		if ( isset( $this->logins[ $identifiant ] ) ) {
			return 0;
		}

		$this->logins[ $identifiant ] = true;
		$id                            = $this->suivant++;
		$this->utilisateurs[ $id ]     = array( 'adresse' => $canonique, 'login' => $identifiant );
		$this->metas[ $id ]            = array();

		return $id;
	}

	public function lire_meta( int $id, string $cle ): ?string {
		// Une clé absente rend `null` ; une clé vide rend la chaîne vide. La
		// distinction est ce qui permet de détecter un état partiel.
		JournalEvenements::noter( 'lecture:' . $cle );

		$valeur = array_key_exists( $cle, $this->metas[ $id ] ?? array() )
			? (string) $this->metas[ $id ][ $cle ]
			: null;

		if ( null !== $this->piege && $this->piege['cle'] === $cle ) {
			$rappel      = $this->piege['rappel'];
			$this->piege = null; // Une seule fois.
			$rappel( $this, $id );
		}

		return $valeur;
	}

	public function ecrire_meta( int $id, string $cle, string $valeur ): bool {
		JournalOrdre::noter( 'ecrire:' . $cle );

		if ( in_array( $cle, $this->ecritures_refusees, true ) ) {
			return false;
		}

		$this->metas[ $id ][ $cle ] = $valeur;

		return true;
	}

	public function supprimer_meta( int $id, string $cle ): bool {
		JournalOrdre::noter( 'supprimer:' . $cle );

		unset( $this->metas[ $id ][ $cle ] );

		return true;
	}

	public function promouvoir_adresse( int $id, string $canonique ): bool {
		if ( $this->promotion_echoue || ! isset( $this->utilisateurs[ $id ] ) ) {
			return false;
		}

		$this->utilisateurs[ $id ]['adresse'] = $canonique;

		return true;
	}

	public function role_conforme(): bool {
		return $this->role_conforme;
	}
}

/**
 * Fournisseur d'identité pilotable.
 */
final class IdentiteDouble implements Urbizen\Platform\Domain\Identity\CurrentUserProvider {

	private Urbizen\Platform\Domain\Identity\ActeurCourant $acteur;

	public function __construct( Urbizen\Platform\Domain\Identity\ActeurCourant $acteur ) {
		$this->acteur = $acteur;
	}

	public function acteur(): Urbizen\Platform\Domain\Identity\ActeurCourant {
		return $this->acteur;
	}
}

/**
 * Transport de courriel pilotable, avec journal d'ordre.
 *
 * Il n'appelle **jamais** la fonction d'envoi de WordPress : c'est une
 * doublure fidèle du contrat `MailTransport`, rien de plus. Chaque envoi est
 * enregistré, et l'ordre des appels — envoi, confirmation, annulation — est
 * noté dans un journal partagé. C'est ce journal qui prouve qu'aucun appel
 * applicatif ne s'intercale entre le retour de l'envoi et la clôture.
 */
final class TransportDouble implements Urbizen\Platform\Mail\MailTransport {

	/**
	 * Réponses à rendre, dans l'ordre. Une valeur `Throwable` est lancée.
	 *
	 * @var array<int, array{ok: bool, code: string}|Throwable>
	 */
	public array $reponses = array();

	/**
	 * Réponse par défaut, une fois `reponses` épuisée.
	 *
	 * @var array{ok: bool, code: string}
	 */
	public array $defaut = array( 'ok' => true, 'code' => 'accepted' );

	/**
	 * Messages remis, dans l'ordre.
	 *
	 * @var array<int, array{destinataire: string, sujet: string, corps: string, entetes: array<int, string>}>
	 */
	public array $messages = array();

	/**
	 * Rappel exécuté pendant l'envoi, avant qu'il ne rende.
	 *
	 * @var callable|null
	 */
	public $pendant = null;

	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		$this->messages[] = array(
			'destinataire' => $destinataire,
			'sujet'        => $sujet,
			'corps'        => $corps,
			'entetes'      => $entetes,
		);

		JournalOrdre::noter( 'send' );

		if ( null !== $this->pendant ) {
			( $this->pendant )( $this );
		}

		$reponse = array_shift( $this->reponses );

		if ( null === $reponse ) {
			return $this->defaut;
		}

		if ( $reponse instanceof Throwable ) {
			throw $reponse;
		}

		return $reponse;
	}
}

/**
 * Journal d'ordre : la suite exacte des appels observés.
 */
final class JournalOrdre {

	/**
	 * @var array<int, string>
	 */
	public static array $suite = array();

	/**
	 * Le journal n'enregistre que lorsqu'il est armé : les autres bancs ne
	 * doivent pas payer le coût d'une instrumentation qu'ils n'utilisent pas.
	 *
	 * @var bool
	 */
	public static bool $actif = false;

	public static function armer(): void {
		self::$suite = array();
		self::$actif = true;
	}

	public static function reset(): void {
		self::$suite  = array();
		self::$actif  = false;
	}

	public static function noter( string $appel ): void {
		if ( ! self::$actif ) {
			return;
		}

		self::$suite[] = $appel;
	}

	/**
	 * @return string
	 */
	public static function rendu(): string {
		return implode( ' → ', self::$suite );
	}
}
