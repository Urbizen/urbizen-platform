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
	 * @var ComptesGateway
	 */
	private ComptesGateway $comptes;

	/**
	 * @var VerificationService
	 */
	private VerificationService $verification;

	/**
	 * @param ComptesGateway      $comptes      Port des comptes.
	 * @param VerificationService $verification Service de vérification.
	 */
	public function __construct( ComptesGateway $comptes, VerificationService $verification ) {
		$this->comptes      = $comptes;
		$this->verification = $verification;
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

		$existant = $this->comptes->trouver_par_adresse( $adresse->valeur() );

		if ( null !== $existant ) {
			// Adresse déjà employée. On ne le dit pas, et l'on ne relance un
			// lien que pour un compte encore non vérifié — jamais de courriel
			// répété vers un compte vérifié, quel que soit le nombre d'essais.
			//
			// Le mot de passe n'est PAS exigé ici : c'est ce qui permet au
			// renvoi public d'emprunter cette même action, sans seconde règle
			// à tenir en cohérence. Aucun compte n'est modifié sur ce chemin,
			// et le lien part toujours à l'adresse déjà enregistrée : savoir
			// l'écrire ne donne donc aucun pouvoir sur le compte.
			if ( $existant->est_verifie() ) {
				return $this->echec( 'adresse_prise_verifiee' );
			}

			$emission = $this->verification->preparer( $existant->id(), $maintenant );

			return array(
				'cree'     => false,
				'compte'   => $existant->id(),
				'motif'    => 'adresse_prise_non_verifiee',
				'emission' => $emission,
			);
		}

		// L'adresse est libre : il faut alors une inscription complète. Le mot
		// de passe n'est contrôlé qu'ICI, une fois établi qu'on créerait un
		// compte. Le contrôler d'entrée — ce que faisait la version
		// précédente — rendait tout renvoi impossible sans mot de passe.
		if ( strlen( $mot_de_passe ) < self::MDP_MINIMUM ) {
			return $this->echec( 'inscription_incomplete' );
		}

		$id = $this->creer_avec_identifiant_unique( $adresse->valeur(), $mot_de_passe );

		if ( 0 === $id ) {
			return $this->echec( 'creation_echouee' );
		}

		$emission = $this->verification->preparer( $id, $maintenant );

		if ( ! $emission->est_prepare() ) {
			// Le compte demeure, non vérifié et récupérable. On journalise un
			// code et un identifiant, jamais l'adresse.
			Logger::error(
				sprintf( 'compte cree mais emission echouee : %s (compte %d)', $emission->motif(), $id )
			);
		}

		return array(
			'cree'     => true,
			'compte'   => $id,
			'motif'    => $emission->est_prepare() ? '' : 'emission_echouee',
			'emission' => $emission,
		);
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
