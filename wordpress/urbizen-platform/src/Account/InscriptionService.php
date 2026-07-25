<?php
/**
 * Création d'un compte particulier.
 *
 * Trois précautions distinguent ce service d'un simple appel à
 * `wp_insert_user()`.
 *
 * **Le rôle est vérifié avant toute création.** S'il manque ou diverge, on
 * n'écrit rien : sans ce contrôle, WordPress attribuerait silencieusement
 * `default_role` — aujourd'hui `subscriber` — et l'on découvrirait des comptes
 * mal dotés bien plus tard.
 *
 * **L'identifiant de connexion est opaque.** `user_login` est immuable dans
 * WordPress ; y placer l'adresse la figerait, alors qu'elle doit pouvoir
 * changer. L'utilisateur se connecte tout de même avec son adresse, WordPress
 * l'acceptant nativement depuis la version 4.5.
 *
 * **Un échec après création ne détruit pas le compte.** Si l'émission du jeton
 * échoue, le compte demeure, non vérifié et récupérable par un renvoi. Le
 * supprimer effacerait un mot de passe déjà choisi par quelqu'un.
 *
 * @package Urbizen\Platform\Account;
 */

namespace Urbizen\Platform\Account;

use Urbizen\Platform\Domain\Account\AdresseCourriel;
use Urbizen\Platform\Domain\Support\Ulid;
use Urbizen\Platform\Schema\DatabaseGateway;
use Urbizen\Platform\Support\Logger;

/**
 * Service d'inscription.
 */
final class InscriptionService {

	/**
	 * Préfixe de l'identifiant technique.
	 */
	public const PREFIXE_LOGIN = 'urb_';

	/**
	 * Longueur minimale du mot de passe.
	 */
	public const MDP_MINIMUM = 12;

	/**
	 * Tentatives de génération d'identifiant avant abandon.
	 */
	public const TENTATIVES_LOGIN = 3;

	/**
	 * Tentatives d'acquisition du verrou d'adresse, et attente entre deux.
	 *
	 * Un concurrent qui perd la course doit attendre la libération du gagnant
	 * pour retrouver le compte qu'il a créé, jamais échouer. 40 × 50 ms ≈ 2 s
	 * couvrent largement une inscription, qui dure des millisecondes.
	 */
	public const VERROU_TENTATIVES = 40;
	public const VERROU_ATTENTE_US = 50000;

	/**
	 * @var ComptesGateway
	 */
	private ComptesGateway $comptes;

	/**
	 * @var VerificationService
	 */
	private VerificationService $verification;

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * @param ComptesGateway      $comptes      Port des comptes.
	 * @param VerificationService $verification Service de vérification.
	 * @param DatabaseGateway     $db           Passerelle, pour le verrou d'adresse.
	 */
	public function __construct( ComptesGateway $comptes, VerificationService $verification, DatabaseGateway $db ) {
		$this->comptes      = $comptes;
		$this->verification = $verification;
		$this->db           = $db;
	}

	/**
	 * Inscrit un particulier.
	 *
	 * Le résultat ne dit **jamais** si l'adresse existait déjà : cette
	 * information permettrait d'énumérer les comptes. Le motif technique rendu
	 * est destiné au journal, pas à l'utilisateur.
	 *
	 * @param string   $adresse_brute Adresse telle que saisie.
	 * @param string   $mot_de_passe  Mot de passe en clair.
	 * @param int|null $maintenant    Horloge injectable.
	 * @return array{cree: bool, compte: int, motif: string, emission: ResultatEmission|null}
	 */
	public function inscrire( string $adresse_brute, string $mot_de_passe, ?int $maintenant = null ): array {
		$maintenant = null === $maintenant ? time() : $maintenant;

		$canonique = $this->comptes->canoniser( $adresse_brute );
		$adresse   = AdresseCourriel::ou_null( $canonique );

		if ( null === $adresse ) {
			return $this->echec( 'adresse_invalide' );
		}

		// Le rôle d'abord : rien n'est créé si l'installation n'est pas faite.
		if ( ! $this->comptes->role_conforme() ) {
			Logger::error( 'inscription refusee : role_non_conforme' );

			return $this->echec( 'role_non_conforme' );
		}

		// ── Section critique : trouver-ou-créer sous verrou d'adresse ──
		// WordPress ne garantit pas l'unicité de `user_email` en base ; sans ce
		// verrou, deux inscriptions simultanées créeraient deux comptes pour la
		// même boîte. Le verrou est relâché AVANT la préparation du jeton, pour
		// ne pas imbriquer durablement ce verrou et VerrouCompte.
		$verrou = $this->acquerir_verrou_adresse( $adresse->valeur(), $maintenant );

		if ( null === $verrou ) {
			Logger::error( 'inscription refusee : verrou_adresse_indisponible' );

			return $this->echec( 'verrou_adresse_indisponible' );
		}

		$compte_id = 0;
		$cree      = false;

		try {
			$existant = $this->comptes->trouver_par_adresse( $adresse->valeur() );

			if ( null !== $existant ) {
				// Adresse déjà employée. On ne le dit pas, et l'on ne relance un
				// lien que pour un compte encore non vérifié — jamais de courriel
				// répété vers un compte vérifié. Le mot de passe n'est PAS exigé
				// ici : le renvoi public emprunte cette même action, sans seconde
				// règle. Aucun compte n'est modifié, et le lien part toujours à
				// l'adresse déjà enregistrée.
				if ( $existant->est_verifie() ) {
					return $this->echec( 'adresse_prise_verifiee' );
				}

				$compte_id = $existant->id();
			} else {
				// L'adresse est libre : inscription complète. Le mot de passe
				// n'est contrôlé qu'ICI, une fois établi qu'on créerait un compte.
				if ( ! $this->longueur_mdp_conforme( $mot_de_passe ) ) {
					return $this->echec( 'inscription_incomplete' );
				}

				$id = $this->creer_avec_identifiant_unique( $adresse->valeur(), $mot_de_passe );

				if ( 0 === $id ) {
					return $this->echec( 'creation_echouee' );
				}

				// Preuve d'unicité : sous verrou, un seul compte doit porter
				// l'adresse. Un décompte différent trahirait une course non
				// couverte ; on le journalise sans jamais écrire l'adresse.
				if ( 1 !== $this->comptes->compter_par_adresse( $adresse->valeur() ) ) {
					Logger::error( sprintf( 'inscription : unicite d adresse non prouvee (compte %d)', $id ) );
				}

				$compte_id = $id;
				$cree      = true;
			}
		} finally {
			$verrou->liberer();
		}

		// ── Hors verrou d'adresse : préparation du jeton (qui prend VerrouCompte). ──
		$emission = $this->verification->preparer( $compte_id, $maintenant );

		if ( $cree && ! $emission->est_prepare() ) {
			// Le compte demeure, non vérifié et récupérable. On journalise un
			// code et un identifiant, jamais l'adresse.
			Logger::error(
				sprintf( 'compte cree mais emission echouee : %s (compte %d)', $emission->motif(), $compte_id )
			);
		}

		return array(
			'cree'     => $cree,
			'compte'   => $compte_id,
			'motif'    => $cree ? ( $emission->est_prepare() ? '' : 'emission_echouee' ) : 'adresse_prise_non_verifiee',
			'emission' => $emission,
		);
	}

	/**
	 * Acquiert le verrou d'une adresse, avec une brève attente en cas de course.
	 *
	 * Le concurrent qui perd retente : quand le gagnant relâche son verrou,
	 * l'insertion redevient possible, et le perdant retrouvera le compte créé.
	 *
	 * @param string $canonique  Adresse canonique.
	 * @param int    $maintenant Horloge.
	 * @return VerrouAdresse|null
	 */
	private function acquerir_verrou_adresse( string $canonique, int $maintenant ): ?VerrouAdresse {
		for ( $essai = 0; $essai < self::VERROU_TENTATIVES; $essai++ ) {
			$verrou = VerrouAdresse::acquerir( $this->db, $canonique, $maintenant );

			if ( null !== $verrou ) {
				return $verrou;
			}

			usleep( self::VERROU_ATTENTE_US );
		}

		return null;
	}

	/**
	 * Le mot de passe atteint-il la longueur minimale, en CARACTÈRES ?
	 *
	 * La règle est douze **caractères** (points de code Unicode), pas douze
	 * octets : « garçon12345 » fait douze caractères mais treize octets, et
	 * `strlen()` l'accepterait par erreur en le comptant en octets. Un UTF-8
	 * invalide est refusé — on ne compte pas des caractères sur des octets qui
	 * n'en forment pas. La valeur n'est ni modifiée ni journalisée.
	 *
	 * @param string $mot_de_passe Mot de passe en clair.
	 * @return bool
	 */
	private function longueur_mdp_conforme( string $mot_de_passe ): bool {
		if ( ! mb_check_encoding( $mot_de_passe, 'UTF-8' ) ) {
			return false;
		}

		return mb_strlen( $mot_de_passe, 'UTF-8' ) >= self::MDP_MINIMUM;
	}

	/**
	 * Engendre un identifiant technique et crée l'utilisateur.
	 *
	 * @param string $canonique    Adresse canonique.
	 * @param string $mot_de_passe Mot de passe.
	 * @return int Identifiant, ou `0`.
	 */
	private function creer_avec_identifiant_unique( string $canonique, string $mot_de_passe ): int {
		for ( $essai = 0; $essai < self::TENTATIVES_LOGIN; $essai++ ) {
			$identifiant = self::PREFIXE_LOGIN . strtolower( Ulid::generer() );
			$id          = $this->comptes->creer( $identifiant, $canonique, $mot_de_passe );

			if ( $id > 0 ) {
				return $id;
			}
		}

		Logger::error( 'creation refusee apres ' . self::TENTATIVES_LOGIN . ' tentatives d identifiant' );

		return 0;
	}

	/**
	 * @param string $motif Motif technique.
	 * @return array{cree: bool, compte: int, motif: string, emission: ResultatEmission|null}
	 */
	private function echec( string $motif ): array {
		return array(
			'cree'     => false,
			'compte'   => 0,
			'motif'    => $motif,
			'emission' => null,
		);
	}
}
