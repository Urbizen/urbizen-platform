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
use Urbizen\Platform\Domain\Support\Texte;
use Urbizen\Platform\Domain\Support\Ulid;
use Urbizen\Platform\Schema\ConnexionPerdue;
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
	 * Attente maximale, en secondes, pour obtenir le verrou d'adresse.
	 *
	 * Le verrou est un `GET_LOCK()` : il **attend** de lui-même jusqu'à cette
	 * borne que le gagnant relâche, sans boucle applicative. Le concurrent qui
	 * perd la course patiente donc dans la base, puis retrouve le compte créé.
	 */
	public const VERROU_ATTENTE = VerrouAdresse::ATTENTE;

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
		$verrou = VerrouAdresse::acquerir( $this->db, $adresse->valeur(), self::VERROU_ATTENTE );

		if ( null === $verrou ) {
			Logger::error( 'inscription refusee : verrou_adresse_indisponible' );

			return $this->echec( 'verrou_adresse_indisponible' );
		}

		$compte_id  = 0;
		$cree       = false;
		$echec_code = null;
		$liberation = false;

		// Section NON reconnectable. Le verrou GET_LOCK est lié à la connexion :
		// si celle-ci meurt, il se libère. Or `wpdb` reconnecte et **rejoue**
		// l'écriture sur une connexion neuve, qui ne tient plus le verrou —
		// c'est la brèche du fencing. On interdit donc la reconnexion pendant
		// toute la section : une perte de connexion fait échouer l'écriture (et
		// lève ConnexionPerdue) au lieu de la rejouer sans exclusion.
		$this->db->interdire_reconnexion();

		try {
			// Combien de comptes portent déjà cette adresse ? La question passe
			// AVANT toute décision. Zéro : on créera. Un : il existe, on le
			// récupère. Plus d'un : un doublon historique — on ne tranche pas
			// lequel est légitime, on refuse de façon restrictive. Prendre
			// simplement le premier résultat de `trouver_par_adresse()`
			// masquerait ce doublon.
			$deja = $this->comptes->compter_par_adresse( $adresse->valeur() );

			if ( $deja > 1 ) {
				Logger::error( sprintf( 'inscription refusee : unicite non prouvee (%d comptes pour l adresse)', $deja ) );
				$echec_code = 'unicite_non_prouvee';
			} else {
				$existant = $this->comptes->trouver_par_adresse( $adresse->valeur() );

				if ( null !== $existant ) {
					// Adresse déjà employée. On ne le dit pas, et l'on ne relance
					// un lien que pour un compte encore non vérifié — jamais de
					// courriel répété vers un compte vérifié. Le mot de passe
					// n'est PAS exigé ici : le renvoi public emprunte cette même
					// action, sans seconde règle. Aucun compte n'est modifié.
					if ( $existant->est_verifie() ) {
						$echec_code = 'adresse_prise_verifiee';
					} else {
						$compte_id = $existant->id();
					}
				} elseif ( ! $this->longueur_mdp_conforme( $mot_de_passe ) ) {
					// L'adresse est libre : inscription complète. Le mot de passe
					// n'est contrôlé qu'ICI, une fois établi qu'on créerait un
					// compte.
					$echec_code = 'inscription_incomplete';
				} else {
					$id = $this->creer_avec_identifiant_unique( $adresse->valeur(), $mot_de_passe );

					if ( 0 === $id ) {
						$echec_code = 'creation_echouee';
					} else {
						// Preuve d'unicité APRÈS création, toujours sous verrou :
						// un seul compte doit porter l'adresse, et le relire doit
						// rendre celui que l'on vient de créer. Un décompte ou un
						// identifiant qui diffère trahirait une course non
						// couverte : on n'émet alors AUCUN jeton, on ne tient PAS
						// le compte pour créé, et l'on ne supprime rien — on ne
						// saurait dire quel utilisateur est légitime. Le journal
						// ne porte qu'un code et des identifiants, jamais
						// l'adresse.
						$apres = $this->comptes->compter_par_adresse( $adresse->valeur() );
						$relu  = $this->comptes->trouver_par_adresse( $adresse->valeur() );

						if ( 1 !== $apres || null === $relu || $relu->id() !== $id ) {
							Logger::error(
								sprintf(
									'inscription refusee : unicite non prouvee apres creation (compte %d, decompte %d, relu %d)',
									$id,
									$apres,
									null === $relu ? 0 : $relu->id()
								)
							);
							$echec_code = 'unicite_non_prouvee';
						} else {
							$compte_id = $id;
							$cree      = true;
						}
					}
				}
			}

			// Libération SOUS la même connexion, toujours en section non
			// reconnectable : son résultat fait partie de la preuve.
			$liberation = $verrou->liberer();
		} catch ( ConnexionPerdue $e ) {
			// Une écriture a échoué faute de reconnexion : rien n'a pu aboutir
			// sur une connexion sans verrou. La connexion morte a déjà relâché
			// le verrou. On échoue de façon restrictive ; un compte
			// éventuellement déjà créé demeure, non vérifié et récupérable.
			Logger::error( sprintf( 'inscription refusee : connexion perdue en section critique (compte %d)', $compte_id ) );

			return $this->echec( 'connexion_perdue' );
		} finally {
			$this->db->autoriser_reconnexion();
			// Filet idempotent : si le corps a levé avant la libération primaire,
			// on relâche tout de même (sans échéance, GET_LOCK tomberait de toute
			// façon à la fin de la connexion).
			$verrou->liberer();
		}

		if ( null !== $echec_code ) {
			return $this->echec( $echec_code );
		}

		if ( true !== $liberation ) {
			// Libération non prouvée (RELEASE_LOCK a rendu 0 ou NULL) : on n'émet
			// AUCUN jeton. Un compte éventuellement créé demeure, non vérifié et
			// récupérable par une nouvelle demande. Journal : un code et un
			// identifiant, jamais l'adresse.
			Logger::error( sprintf( 'inscription refusee : liberation du verrou non prouvee (compte %d)', $compte_id ) );

			return $this->echec( 'liberation_non_prouvee' );
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
	 * Le mot de passe atteint-il la longueur minimale, en CARACTÈRES ?
	 *
	 * La règle est douze **caractères** (points de code Unicode), pas douze
	 * octets : « garçon12345 » fait onze caractères mais douze octets, et
	 * `strlen()` l'accepterait par erreur en le comptant en octets. Un UTF-8
	 * invalide est refusé — on ne compte pas des caractères sur des octets qui
	 * n'en forment pas. La mesure passe par {@see Texte}, en PCRE, sans dépendre
	 * de `mbstring`, qui n'est pas garanti partout. La valeur n'est ni modifiée
	 * ni journalisée.
	 *
	 * @param string $mot_de_passe Mot de passe en clair.
	 * @return bool
	 */
	private function longueur_mdp_conforme( string $mot_de_passe ): bool {
		return Texte::au_moins( $mot_de_passe, self::MDP_MINIMUM );
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
