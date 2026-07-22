<?php
/**
 * Émission et consommation des jetons de vérification.
 *
 * Deux invariants portent la sûreté de ce service.
 *
 * **Tout ce qui décide passe sous verrou.** Le contrôle effectué avant
 * l'acquisition n'est qu'un préfiltre : il évite d'acquérir un verrou pour
 * rien, il n'autorise rien. L'état sur lequel repose la mutation finale est
 * **relu après** le verrou, jamais avant — sans quoi deux processus agiraient
 * sur une photographie périmée.
 *
 * **Rien de définitif n'est écrit sur le chemin de l'échec.** Le condensat
 * n'est supprimé qu'après une vérification relue et confirmée ; le quota n'est
 * consommé que lorsque l'appelant confirme son envoi. Un échec laisse donc un
 * jeton légitime encore utilisable, ce qui est le comportement voulu.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Throwable;
use Urbizen\Platform\Domain\Account\AdresseCourriel;
use Urbizen\Platform\Domain\Account\Compte;
use Urbizen\Platform\Schema\DatabaseGateway;
use Urbizen\Platform\Support\Logger;

/**
 * Service de vérification.
 */
final class VerificationService {

	public const META_VERIFIE    = '_urbizen_courriel_verifie';
	public const META_VERIFIE_LE = '_urbizen_courriel_verifie_le';
	public const META_EN_ATTENTE = '_urbizen_courriel_en_attente';

	/**
	 * Valeur, et unique valeur, signifiant « vérifié ».
	 */
	public const VALEUR_VERIFIE = '1';

	/**
	 * @var ComptesGateway
	 */
	private ComptesGateway $comptes;

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * @param ComptesGateway  $comptes Port des comptes.
	 * @param DatabaseGateway $db      Passerelle, pour le verrou.
	 */
	public function __construct( ComptesGateway $comptes, DatabaseGateway $db ) {
		$this->comptes = $comptes;
		$this->db      = $db;
	}

	/**
	 * Prépare un jeton, sans consommer le quota.
	 *
	 * @param int      $compte     Identifiant.
	 * @param int|null $maintenant Horloge injectable.
	 * @return ResultatEmission
	 */
	public function preparer( int $compte, ?int $maintenant = null ): ResultatEmission {
		$maintenant = null === $maintenant ? time() : $maintenant;
		$verrou     = VerrouCompte::acquerir( $this->db, $compte, $maintenant );

		if ( null === $verrou ) {
			return ResultatEmission::refuse( 'verrou_indisponible' );
		}

		try {
			// Relecture SOUS verrou : le compte a pu changer entre-temps.
			$objet = $this->comptes->trouver_par_id( $compte );

			if ( null === $objet ) {
				return ResultatEmission::refuse( 'compte_absent' );
			}

			$etat  = LimiteEnvois::decoder( $this->comptes->lire_meta( $compte, LimiteEnvois::META ) );
			$motif = LimiteEnvois::motif_de_refus( $etat, $maintenant );

			if ( '' !== $motif ) {
				Logger::info( sprintf( 'emission refusee : %s (compte %d)', $motif, $compte ) );

				return ResultatEmission::refuse( $motif );
			}

			$cible      = $objet->cible_de_verification()->valeur();
			$generation = $this->generation_suivante( $compte );
			$jeton      = JetonVerification::engendrer();
			$expire_le  = $maintenant + JetonVerification::TTL;

			// L'écriture est groupée : une seule valeur manquante rendra le
			// jeton invalide à la consommation, jamais partiellement valide.
			$ecrit = $this->comptes->ecrire_meta(
				$compte,
				JetonVerification::META_CONDENSAT,
				JetonVerification::condensat( $compte, $cible, $generation, $jeton )
			)
			&& $this->comptes->ecrire_meta( $compte, JetonVerification::META_EXPIRE, (string) $expire_le )
			&& $this->comptes->ecrire_meta( $compte, JetonVerification::META_CIBLE, $cible )
			&& $this->comptes->ecrire_meta( $compte, JetonVerification::META_GENERATION, (string) $generation );

			if ( ! $ecrit ) {
				// Écriture partielle : on efface ce qui a pu passer, pour ne
				// pas laisser un état à demi valide derrière soi.
				$this->effacer_jeton( $compte );

				return ResultatEmission::refuse( 'ecriture_incomplete' );
			}

			return ResultatEmission::prepare( $jeton, $cible, $expire_le );
		} catch ( Throwable $e ) {
			$this->effacer_jeton( $compte );

			return ResultatEmission::refuse( 'exception' );
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * Confirme qu'une émission a réellement eu lieu, et consomme le quota.
	 *
	 * @param int      $compte     Identifiant.
	 * @param int|null $maintenant Horloge injectable.
	 * @return bool
	 */
	public function confirmer_emission( int $compte, ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;
		$verrou     = VerrouCompte::acquerir( $this->db, $compte, $maintenant );

		if ( null === $verrou ) {
			return false;
		}

		try {
			$etat = LimiteEnvois::decoder( $this->comptes->lire_meta( $compte, LimiteEnvois::META ) );

			// Un quota illisible est réécrit proprement plutôt que conservé :
			// on repart d'un envoi connu, jamais d'un état qu'on ne sait pas lire.
			$base = empty( $etat['corrompue'] ) ? $etat['horodatages'] : array();

			return $this->comptes->ecrire_meta(
				$compte,
				LimiteEnvois::META,
				LimiteEnvois::encoder( LimiteEnvois::confirmer( $base, $maintenant ) )
			);
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * Annule une émission : le quota reste intact, le jeton reste valide.
	 *
	 * Rien à écrire — c'est précisément le point. La méthode existe pour que
	 * l'appelant exprime son intention, et pour que les bancs l'éprouvent.
	 *
	 * @param int $compte Identifiant.
	 * @return bool
	 */
	public function annuler_emission( int $compte ): bool {
		Logger::info( sprintf( 'emission annulee (compte %d)', $compte ) );

		return true;
	}

	/**
	 * Consomme un jeton.
	 *
	 * @param int      $compte     Identifiant.
	 * @param string   $jeton      Jeton brut présenté.
	 * @param int|null $maintenant Horloge injectable.
	 * @return string Chaîne vide en cas de succès, motif technique sinon.
	 */
	public function consommer( int $compte, string $jeton, ?int $maintenant = null ): string {
		$maintenant = null === $maintenant ? time() : $maintenant;

		// (1) Préfiltre : n'autorise rien, évite seulement un verrou inutile.
		if ( $compte <= 0 || ! JetonVerification::forme_valide( $jeton ) ) {
			return 'jeton_invalide';
		}

		// (2) Verrou.
		$verrou = VerrouCompte::acquerir( $this->db, $compte, $maintenant );

		if ( null === $verrou ) {
			return 'verrou_indisponible';
		}

		try {
			// (3) RELECTURE COMPLÈTE sous verrou. Aucun état lu avant le
			// verrou ne sert à la mutation.
			$objet = $this->comptes->trouver_par_id( $compte );

			if ( null === $objet ) {
				return 'compte_absent';
			}

			$condensat  = (string) $this->comptes->lire_meta( $compte, JetonVerification::META_CONDENSAT );
			$expire     = $this->comptes->lire_meta( $compte, JetonVerification::META_EXPIRE );
			$cible      = (string) $this->comptes->lire_meta( $compte, JetonVerification::META_CIBLE );
			$generation = $this->comptes->lire_meta( $compte, JetonVerification::META_GENERATION );

			// État incomplet : jamais partiellement valide.
			if ( '' === $condensat || null === $expire || '' === $cible || null === $generation ) {
				return 'jeton_absent';
			}

			// (4) Revalidation du condensat.
			if ( ! JetonVerification::correspond( $condensat, $compte, $cible, (int) $generation, $jeton ) ) {
				return 'jeton_invalide';
			}

			// (5) Échéance.
			if ( ! ctype_digit( (string) $expire ) || (int) $expire <= $maintenant ) {
				return 'jeton_expire';
			}

			// (6) La cible est-elle toujours celle que le compte attend ?
			if ( ! hash_equals( $objet->cible_de_verification()->valeur(), $cible ) ) {
				return 'cible_obsolete';
			}

			// (7) Changement d'adresse : est-elle encore libre ?
			if ( $objet->a_un_changement_en_cours() ) {
				if ( ! $this->comptes->adresse_disponible( $cible, $compte ) ) {
					return 'adresse_occupee';
				}

				// (8) Promotion d'abord. Si elle échoue, rien n'est vérifié.
				if ( ! $this->comptes->promouvoir_adresse( $compte, $cible ) ) {
					return 'promotion_echouee';
				}
			}

			// (8 bis) Marquer vérifié, PUIS relire pour le prouver.
			if ( ! $this->comptes->ecrire_meta( $compte, self::META_VERIFIE, self::VALEUR_VERIFIE ) ) {
				return 'ecriture_verifie_echouee';
			}

			if ( self::VALEUR_VERIFIE !== (string) $this->comptes->lire_meta( $compte, self::META_VERIFIE ) ) {
				// La relecture est la seule preuve. Sans elle, on affirmerait
				// une vérification que la base n'a peut-être pas retenue.
				return 'verification_non_relue';
			}

			$this->comptes->ecrire_meta( $compte, self::META_VERIFIE_LE, gmdate( 'Y-m-d H:i:s', $maintenant ) );

			// (9) Le condensat n'est effacé qu'ici, après preuve.
			$this->effacer_jeton( $compte );
			$this->comptes->supprimer_meta( $compte, self::META_EN_ATTENTE );

			Logger::info( sprintf( 'courriel verifie (compte %d)', $compte ) );

			return '';
		} catch ( Throwable $e ) {
			return 'exception';
		} finally {
			// (10) Libération dans tous les cas.
			$verrou->liberer();
		}
	}

	/**
	 * Génération suivante.
	 *
	 * @param int $compte Identifiant.
	 * @return int
	 */
	private function generation_suivante( int $compte ): int {
		$actuelle = $this->comptes->lire_meta( $compte, JetonVerification::META_GENERATION );

		return ( null !== $actuelle && ctype_digit( (string) $actuelle ) ) ? ( (int) $actuelle + 1 ) : 1;
	}

	/**
	 * Efface les quatre métadonnées d'un jeton.
	 *
	 * La génération est conservée : la remettre à zéro rendrait un ancien
	 * condensat calculable à nouveau.
	 *
	 * @param int $compte Identifiant.
	 * @return void
	 */
	private function effacer_jeton( int $compte ): void {
		$this->comptes->supprimer_meta( $compte, JetonVerification::META_CONDENSAT );
		$this->comptes->supprimer_meta( $compte, JetonVerification::META_EXPIRE );
		$this->comptes->supprimer_meta( $compte, JetonVerification::META_CIBLE );
	}
}
