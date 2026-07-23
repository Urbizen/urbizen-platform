<?php
/**
 * Émission et consommation des jetons de vérification.
 *
 * Trois invariants portent la sûreté de ce service.
 *
 * **Aucune lecture de stockage ne précède le verrou.** `consommer()` commence
 * par un contrôle d'arguments — identifiant positif, jeton de forme plausible —
 * qui n'interroge ni la base ni les métadonnées : c'est une validation de
 * paramètres, pas une lecture d'état. Tout ce qui décide est lu **après**
 * l'acquisition. C'est vérifié par l'ordre des opérations, pas par une course :
 * le code correct ne lisant rien avant le verrou, rien ne peut y devenir
 * périmé.
 *
 * **Une seule émission peut être en vol à la fois.** La confirmation après
 * envoi ne suffisait pas : entre la préparation de P1 et son envoi, rien ne
 * disait que P1 existait, et P2 pouvait préparer un second jeton qui invalidait
 * le premier avant même son départ. `EmissionEnAttente` est cet état manquant ;
 * tant qu'il est posé et non expiré, aucune autre préparation ne passe.
 *
 * **Rien de définitif n'est écrit sur le chemin de l'échec.** Le condensat n'est
 * supprimé qu'après une vérification relue et confirmée ; le quota n'est
 * consommé qu'à la confirmation d'un envoi réel.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Throwable;
use Urbizen\Platform\Domain\Account\AdresseCourriel;
use Urbizen\Platform\Domain\Account\Compte;
use Urbizen\Platform\Domain\Support\Ulid;
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
	 * Sous verrou, dans cet ordre :
	 *
	 *   1. relire et nettoyer une émission en attente expirée — avec le jeton
	 *      qu'elle portait ;
	 *   2. refuser si une émission non expirée existe déjà ;
	 *   3. purger les horodatages sortis de la fenêtre ;
	 *   4. contrôler quota et délai minimal ;
	 *   5. engendrer le jeton et sa génération ;
	 *   6. écrire condensat, échéance, cible, génération ;
	 *   7. écrire l'émission en attente — **en dernier**.
	 *
	 * L'ordre 6-puis-7 n'est pas indifférent. Si le processus meurt entre les
	 * deux, on laisse un jeton sans émission : la préparation suivante n'est pas
	 * bloquée, et elle écrase ce jeton en incrémentant la génération. L'ordre
	 * inverse laisserait une émission sans jeton, qui bloquerait le compte
	 * jusqu'à son expiration pour rien.
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

			// (1-2) Une émission est-elle déjà en vol ?
			$attente = EmissionEnAttente::decoder(
				$this->comptes->lire_meta( $compte, EmissionEnAttente::META )
			);

			if ( null !== $attente ) {
				if ( ! EmissionEnAttente::est_expiree( $attente, $maintenant ) ) {
					Logger::info( sprintf( 'emission refusee : emission_en_attente (compte %d)', $compte ) );

					return ResultatEmission::refuse( 'emission_en_attente' );
				}

				// Expirée : le processus qui l'avait ouverte n'a ni confirmé ni
				// annulé. Son jeton part avec elle — sinon un lien préparé, non
				// envoyé et jamais clos resterait consommable un jour entier.
				$this->effacer_jeton( $compte );

				if ( ! $this->comptes->supprimer_meta( $compte, EmissionEnAttente::META ) ) {
					return ResultatEmission::refuse( 'nettoyage_impossible' );
				}
			}

			// (3-4) Quota et délai.
			$etat  = LimiteEnvois::decoder( $this->comptes->lire_meta( $compte, LimiteEnvois::META ) );
			$motif = LimiteEnvois::motif_de_refus( $etat, $maintenant );

			if ( '' !== $motif ) {
				Logger::info( sprintf( 'emission refusee : %s (compte %d)', $motif, $compte ) );

				return ResultatEmission::refuse( $motif );
			}

			// (5) Jeton.
			$cible      = $objet->cible_de_verification()->valeur();
			$generation = $this->generation_suivante( $compte );
			$jeton      = JetonVerification::engendrer();
			$expire_le  = $maintenant + JetonVerification::TTL;

			// (6) L'écriture est groupée : une seule valeur manquante rendra le
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

			// (7) L'émission en attente ferme le compte aux autres préparations.
			$emission_id = Ulid::generer();

			if ( ! $this->comptes->ecrire_meta(
				$compte,
				EmissionEnAttente::META,
				EmissionEnAttente::encoder( $emission_id, $generation, $cible, $maintenant )
			) ) {
				$this->effacer_jeton( $compte );
				$this->comptes->supprimer_meta( $compte, EmissionEnAttente::META );

				return ResultatEmission::refuse( 'ecriture_incomplete' );
			}

			return ResultatEmission::prepare( $jeton, $cible, $expire_le, $emission_id, $generation );
		} catch ( Throwable $e ) {
			$this->effacer_jeton( $compte );
			$this->comptes->supprimer_meta( $compte, EmissionEnAttente::META );

			return ResultatEmission::refuse( 'exception' );
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * Confirme qu'une émission a réellement eu lieu, et consomme le quota.
	 *
	 * L'identifiant présenté doit être celui de l'émission encore en attente :
	 * un appelant lent ne doit pas pouvoir clore une émission plus récente que
	 * la sienne. Génération et cible sont recontrôlées contre le jeton stocké —
	 * si le jeton a été remplacé, l'émission que l'on croit confirmer n'existe
	 * plus.
	 *
	 * **Le quota est écrit avant l'effacement de l'émission.** L'ordre inverse
	 * serait plus élégant mais penche du mauvais côté : si l'effacement passait
	 * et l'écriture du quota échouait, un envoi réel ne serait pas décompté. Ici,
	 * une suppression manquée coûte au pire un créneau de trop — jamais un
	 * créneau de moins.
	 *
	 * @param int      $compte      Identifiant.
	 * @param string   $emission_id Identifiant d'émission.
	 * @param int|null $maintenant  Horloge injectable.
	 * @return bool
	 */
	public function confirmer_emission( int $compte, string $emission_id, ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;
		$verrou     = VerrouCompte::acquerir( $this->db, $compte, $maintenant );

		if ( null === $verrou ) {
			return false;
		}

		try {
			$attente = EmissionEnAttente::decoder(
				$this->comptes->lire_meta( $compte, EmissionEnAttente::META )
			);

			if ( null === $attente || ! EmissionEnAttente::correspond( $attente, $emission_id ) ) {
				return false;
			}

			if ( ! $this->emission_porte_le_jeton( $compte, $attente ) ) {
				return false;
			}

			if ( ! $this->consommer_quota( $compte, $maintenant ) ) {
				return false;
			}

			// L'émission est close ; le jeton, lui, reste actif jusqu'à sa
			// consommation ou son échéance.
			return $this->comptes->supprimer_meta( $compte, EmissionEnAttente::META );
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * Annule une émission : le quota reste intact, le jeton est détruit.
	 *
	 * Détruire le jeton peut surprendre — mais le conserver ne servirait à rien.
	 * Le jeton brut n'est pas stocké : il n'existait que dans la réponse rendue à
	 * l'appelant, et disparaît avec la requête. Un jeton « conservé » serait donc
	 * un condensat que plus personne ne peut satisfaire, occupant la place du
	 * suivant. L'appelant doit repréparer, et il le peut immédiatement puisque le
	 * quota n'a pas bougé.
	 *
	 * @param int      $compte      Identifiant.
	 * @param string   $emission_id Identifiant d'émission.
	 * @param int|null $maintenant  Horloge injectable.
	 * @return bool
	 */
	public function annuler_emission( int $compte, string $emission_id, ?int $maintenant = null ): bool {
		$maintenant = null === $maintenant ? time() : $maintenant;
		$verrou     = VerrouCompte::acquerir( $this->db, $compte, $maintenant );

		if ( null === $verrou ) {
			return false;
		}

		try {
			$attente = EmissionEnAttente::decoder(
				$this->comptes->lire_meta( $compte, EmissionEnAttente::META )
			);

			if ( null === $attente || ! EmissionEnAttente::correspond( $attente, $emission_id ) ) {
				return false;
			}

			// Le jeton n'est effacé que s'il est bien celui de CETTE émission :
			// une génération plus récente appartient à quelqu'un d'autre.
			if ( $this->emission_porte_le_jeton( $compte, $attente ) ) {
				$this->effacer_jeton( $compte );
			}

			if ( ! $this->comptes->supprimer_meta( $compte, EmissionEnAttente::META ) ) {
				return false;
			}

			Logger::info( sprintf( 'emission annulee : quota intact (compte %d)', $compte ) );

			return true;
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * Le jeton actuellement stocké est-il bien celui de cette émission ?
	 *
	 * @param int                                        $compte  Identifiant.
	 * @param array{generation: int, cible: string}      $attente Émission décodée.
	 * @return bool
	 */
	private function emission_porte_le_jeton( int $compte, array $attente ): bool {
		$generation = $this->comptes->lire_meta( $compte, JetonVerification::META_GENERATION );
		$cible      = $this->comptes->lire_meta( $compte, JetonVerification::META_CIBLE );

		if ( null === $generation || null === $cible ) {
			return false;
		}

		if ( ! ctype_digit( (string) $generation ) || (int) $generation !== (int) $attente['generation'] ) {
			return false;
		}

		return hash_equals( (string) $cible, (string) $attente['cible'] );
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

		// (1) Contrôle d'ARGUMENTS, pas de lecture d'état : ni base, ni
		// métadonnée n'est interrogée ici. Il n'autorise rien et n'écarte que
		// des valeurs qui ne peuvent pas être un jeton, quel que soit l'état du
		// stockage. La règle « aucune lecture avant le verrou » est donc
		// entière, et c'est bien l'ordre des opérations qui l'établit.
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

			/*
			 * (9) Une émission de ce jeton était-elle encore en attente ?
			 *
			 * Alors le courriel est bel et bien parti — le destinataire vient
			 * d'en suivre le lien. C'est la preuve d'envoi la plus forte qui
			 * soit, et le créneau doit être décompté ici : sans cela, cliquer
			 * plus vite que l'appelant ne confirme rendrait le créneau gratuit,
			 * et l'opération répétée viderait le quota de sa fonction.
			 */
			$attente = EmissionEnAttente::decoder(
				$this->comptes->lire_meta( $compte, EmissionEnAttente::META )
			);

			if ( null !== $attente
				&& empty( $attente['corrompue'] )
				&& (int) $generation === (int) $attente['generation']
				&& hash_equals( (string) $attente['cible'], $cible )
			) {
				$this->consommer_quota( $compte, $maintenant );
			}

			$this->comptes->supprimer_meta( $compte, EmissionEnAttente::META );

			// (10) Le condensat n'est effacé qu'ici, après preuve.
			$this->effacer_jeton( $compte );
			$this->comptes->supprimer_meta( $compte, self::META_EN_ATTENTE );

			Logger::info( sprintf( 'courriel verifie (compte %d)', $compte ) );

			return '';
		} catch ( Throwable $e ) {
			return 'exception';
		} finally {
			// (11) Libération dans tous les cas.
			$verrou->liberer();
		}
	}

	/**
	 * Ajoute un créneau confirmé au quota. Verrou déjà tenu.
	 *
	 * @param int $compte     Identifiant.
	 * @param int $maintenant Horloge.
	 * @return bool
	 */
	private function consommer_quota( int $compte, int $maintenant ): bool {
		$etat = LimiteEnvois::decoder( $this->comptes->lire_meta( $compte, LimiteEnvois::META ) );

		// Un quota illisible est réécrit proprement plutôt que conservé : on
		// repart d'un envoi connu, jamais d'un état qu'on ne sait pas lire.
		$base = empty( $etat['corrompue'] ) ? $etat['horodatages'] : array();

		return $this->comptes->ecrire_meta(
			$compte,
			LimiteEnvois::META,
			LimiteEnvois::encoder( LimiteEnvois::confirmer( $base, $maintenant ) )
		);
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
