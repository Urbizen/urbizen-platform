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
			return $this->preparer_sous_verrou( $compte, $maintenant );
		} finally {
			$verrou->liberer();
		}
	}

	/**
	 * Prépare une émission, le verrou du compte étant **déjà détenu**.
	 *
	 * Extraite de `preparer()` pour qu'un appelant qui tient déjà le verrou
	 * puisse préparer sans le relâcher. C'est ce qui rend
	 * `demander_changement_adresse()` atomique : enregistrer la cible puis
	 * préparer l'émission en deux acquisitions successives ouvrirait une
	 * fenêtre où une demande concurrente remplacerait la cible entre les deux,
	 * et le jeton confirmerait alors une adresse que personne n'a demandée.
	 *
	 * N'acquiert ni ne libère aucun verrou : c'est la responsabilité de
	 * l'appelant, et elle n'est pas partageable.
	 *
	 * @param int $compte     Identifiant.
	 * @param int $maintenant Horloge.
	 * @return ResultatEmission
	 */
	private function preparer_sous_verrou( int $compte, int $maintenant ): ResultatEmission {
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
			$etat  = LimiteEnvois::etat_depuis_source( $this->lire_source_quota( $compte ) );
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
		}
	}

	/**
	 * Authentifie un lien SANS RIEN CONSOMMER, ni rien écrire.
	 *
	 * Le GET de la page de vérification doit prouver que le lien est le bon
	 * AVANT d'afficher l'adresse qu'il confirme. Sans cette preuve, il suffirait
	 * d'essayer des identifiants numériques avec un jeton bien formé pour se
	 * faire afficher l'adresse de tout compte ayant une vérification en cours —
	 * exactement l'annuaire que le reste du parcours refuse de fournir.
	 *
	 * **Aucune écriture, aucun verrou écrivant, aucune clôture, aucun quota.**
	 * La méthode lit et compare, rien de plus. Elle ne prend pas non plus
	 * `VerrouCompte` : le verrou sert à sérialiser des ÉCRITURES, et une lecture
	 * qui le prendrait bloquerait une émission légitime pour rien.
	 *
	 * Les motifs rendus sont ceux de `consommer()`, de sorte que compte absent
	 * et jeton invalide restent indiscernables du dehors.
	 *
	 * @param int      $compte     Identifiant présenté.
	 * @param string   $jeton      Jeton présenté.
	 * @param int|null $maintenant Horloge injectable.
	 * @return array{motif: string, cible: string}
	 */
	public function inspecter( int $compte, string $jeton, ?int $maintenant = null ): array {
		$maintenant = null === $maintenant ? time() : $maintenant;

		if ( $compte <= 0 || ! JetonVerification::forme_valide( $jeton ) ) {
			return array( 'motif' => 'jeton_invalide', 'cible' => '' );
		}

		$objet = $this->comptes->trouver_par_id( $compte );

		if ( null === $objet ) {
			// MÊME motif qu'un jeton invalide : distinguer révélerait quels
			// identifiants correspondent à un compte.
			return array( 'motif' => 'jeton_invalide', 'cible' => '' );
		}

		$condensat  = (string) $this->comptes->lire_meta( $compte, JetonVerification::META_CONDENSAT );
		$expire     = $this->comptes->lire_meta( $compte, JetonVerification::META_EXPIRE );
		$cible      = (string) $this->comptes->lire_meta( $compte, JetonVerification::META_CIBLE );
		$generation = $this->comptes->lire_meta( $compte, JetonVerification::META_GENERATION );

		if ( '' === $condensat || null === $expire || '' === $cible || null === $generation ) {
			return array( 'motif' => 'jeton_absent', 'cible' => '' );
		}

		if ( ! JetonVerification::correspond( $condensat, $compte, $cible, (int) $generation, $jeton ) ) {
			return array( 'motif' => 'jeton_invalide', 'cible' => '' );
		}

		if ( ! ctype_digit( (string) $expire ) || (int) $expire <= $maintenant ) {
			return array( 'motif' => 'jeton_expire', 'cible' => '' );
		}

		if ( ! hash_equals( $objet->cible_de_verification()->valeur(), $cible ) ) {
			return array( 'motif' => 'cible_obsolete', 'cible' => '' );
		}

		// La cible n'est rendue QU'ICI, une fois le condensat prouvé.
		return array( 'motif' => '', 'cible' => $cible );
	}

	/**
	 * Enregistre une demande de changement d'adresse et prépare son émission.
	 *
	 * **Tout se fait sous UNE seule acquisition du verrou.** Écrire la cible
	 * puis appeler `preparer()` — qui reverrouillerait — ouvrirait une fenêtre
	 * entre les deux : une demande concurrente y remplacerait la cible, et le
	 * jeton confirmerait alors une adresse que personne n'a demandée. C'est la
	 * raison d'être de `preparer_sous_verrou()`.
	 *
	 * **L'adresse du compte ne bouge pas ici.** Seule `consommer()` promeut
	 * l'adresse en attente, et `WpComptes` protège cette promotion de sa garde.
	 * L'ancienne adresse reste donc celle du compte jusqu'à ce que le
	 * destinataire de la nouvelle ait suivi son lien.
	 *
	 * **Si la préparation échoue, la cible est restaurée.** Laisser une adresse
	 * en attente sans jeton ferait viser cette adresse jamais confirmée par le
	 * renvoi suivant. Le tout aboutit, ou rien ne bouge.
	 *
	 * @param int      $compte         Identifiant.
	 * @param string   $nouvelle_brute Adresse demandée, telle que saisie.
	 * @param int|null $maintenant     Horloge injectable.
	 * @return array{motif: string, ancienne: string, emission: ResultatEmission|null}
	 */
	public function demander_changement_adresse( int $compte, string $nouvelle_brute, ?int $maintenant = null ): array {
		$maintenant = null === $maintenant ? time() : $maintenant;
		$verrou     = VerrouCompte::acquerir( $this->db, $compte, $maintenant );

		if ( null === $verrou ) {
			return self::changement_refuse( 'verrou_indisponible' );
		}

		try {
			// Relecture SOUS verrou : tout ce qui suit décide sur cet état-là.
			$objet = $this->comptes->trouver_par_id( $compte );

			if ( null === $objet ) {
				return self::changement_refuse( 'compte_absent' );
			}

			$ancienne = $objet->adresse()->valeur();

			// Ce qu'il faudra remettre si la préparation échoue : la valeur
			// exacte d'avant, ou son absence.
			$precedente = $this->comptes->lire_meta( $compte, self::META_EN_ATTENTE );

			$canonique = $this->comptes->canoniser( $nouvelle_brute );
			$adresse   = AdresseCourriel::ou_null( $canonique );

			if ( null === $adresse ) {
				return self::changement_refuse( 'adresse_invalide', $ancienne );
			}

			if ( hash_equals( $ancienne, $adresse->valeur() ) ) {
				return self::changement_refuse( 'adresse_inchangee', $ancienne );
			}

			// Le compte lui-même est écarté du contrôle : sa propre adresse ne
			// doit pas se rendre indisponible à lui-même.
			if ( ! $this->comptes->adresse_disponible( $adresse->valeur(), $compte ) ) {
				// Le motif part au journal, jamais à l'utilisateur : dire
				// « cette adresse est prise » offrirait un annuaire.
				return self::changement_refuse( 'adresse_indisponible', $ancienne );
			}

			if ( ! $this->comptes->ecrire_meta( $compte, self::META_EN_ATTENTE, $adresse->valeur() ) ) {
				return self::changement_refuse( 'ecriture_incomplete', $ancienne );
			}

			// Le verrou est TOUJOURS détenu : la cible qu'on vient d'écrire est
			// celle que cette préparation lira.
			$emission = $this->preparer_sous_verrou( $compte, $maintenant );

			if ( ! $emission->est_prepare() ) {
				$this->restaurer_en_attente( $compte, $precedente );

				return self::changement_refuse( $emission->motif(), $ancienne );
			}

			Logger::info( sprintf( 'changement d adresse demande (compte %d)', $compte ) );

			return array(
				'motif'    => '',
				'ancienne' => $ancienne,
				'emission' => $emission,
			);
		} catch ( Throwable $e ) {
			return self::changement_refuse( 'exception' );
		} finally {
			// Libéré AVANT tout rendu et tout envoi : un verrou de 60 secondes
			// ne survivrait pas à un envoi SMTP.
			$verrou->liberer();
		}
	}

	/**
	 * Remet l'adresse en attente dans l'état où elle était.
	 *
	 * @param int         $compte     Identifiant.
	 * @param string|null $precedente Valeur d'avant, ou null si absente.
	 * @return void
	 */
	private function restaurer_en_attente( int $compte, ?string $precedente ): void {
		if ( null === $precedente || '' === $precedente ) {
			$this->comptes->supprimer_meta( $compte, self::META_EN_ATTENTE );

			return;
		}

		$this->comptes->ecrire_meta( $compte, self::META_EN_ATTENTE, $precedente );
	}

	/**
	 * @param string $motif    Motif technique.
	 * @param string $ancienne Adresse actuelle, si connue.
	 * @return array{motif: string, ancienne: string, emission: ResultatEmission|null}
	 */
	private static function changement_refuse( string $motif, string $ancienne = '' ): array {
		return array(
			'motif'    => $motif,
			'ancienne' => $ancienne,
			'emission' => null,
		);
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

			// (2-4) Source, puis miroir. La source reconnaît l'identifiant si
			// c'est un rejeu et n'ajoute alors aucun créneau.
			if ( ! $this->consommer_quota( $compte, $maintenant, $emission_id ) ) {
				return false;
			}

			// (5) L'émission n'est effacée QUE si source et miroir ont été
			// écrits. C'est elle qui permet le rejeu : la supprimer sur un
			// miroir en échec fermerait la seule porte de reprise.
			//
			// (6) Le jeton, lui, reste actif jusqu'à sa consommation ou son
			// échéance.
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

			/*
			 * (6 bis) LE QUOTA D'ABORD, avant toute mutation irréversible.
			 *
			 * Le destinataire vient de suivre le lien : c'est la preuve d'envoi
			 * la plus forte qui soit, et le créneau doit être décompté. Le faire
			 * APRÈS la promotion et le marquage — comme auparavant — laissait un
			 * chemin où le compte se vérifiait, le jeton disparaissait, et le
			 * décompte se perdait si la source ou le miroir échouait. D-046 exige
			 * un quota exact : on refuse plutôt que de perdre un créneau.
			 *
			 * Rien n'est encore altéré ici. Un échec rend `indisponible` et
			 * laisse le jeton, l'émission, l'adresse et l'état non vérifié
			 * exactement où ils étaient : le clic est rejouable.
			 */
			$attente = EmissionEnAttente::decoder(
				$this->comptes->lire_meta( $compte, EmissionEnAttente::META )
			);

			$emission_du_jeton = null !== $attente
				&& empty( $attente['corrompue'] )
				&& (int) $generation === (int) $attente['generation']
				&& hash_equals( (string) $attente['cible'], $cible );

			if ( $emission_du_jeton
				&& ! $this->consommer_quota( $compte, $maintenant, (string) $attente['id'] ) ) {
				// Un rejeu du même clic reconnaîtra l'identifiant dans la
				// source, réparera le miroir sans second créneau, et ira
				// jusqu'au bout.
				return 'quota_non_clos';
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

			// (9) Le créneau est déjà décompté (6 bis). L'émission peut être
			// close : une confirmation tardive de l'émetteur reconnaîtra son
			// identifiant dans la source et n'ajoutera aucun second créneau.
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
	private function consommer_quota( int $compte, int $maintenant, string $emission_id ): bool {
		$source = $this->lire_source_quota( $compte );

		// Une source illisible ferme : on n'écrit pas par-dessus un état qu'on
		// ne sait pas lire, et l'on ne repart pas d'un tableau vide — ce serait
		// offrir une fenêtre entière à qui aurait corrompu la valeur.
		if ( ! empty( $source['corrompue'] ) ) {
			Logger::error( sprintf( 'confirmation refusee : quota_illisible (compte %d)', $compte ) );

			return false;
		}

		$entrees = $source['entrees'];

		// (2) L'identifiant y figure déjà : c'est un rejeu. On n'ajoute pas un
		// second créneau, mais on poursuit — le miroir et l'effacement de
		// l'émission restent peut-être à faire.
		if ( ! LimiteEnvois::contient_emission( $entrees, $emission_id ) ) {
			$entrees = LimiteEnvois::ajouter_emission( $entrees, $maintenant, $emission_id );
		}

		// (3) La source d'abord, toujours. Le miroir ne s'écrit jamais seul :
		// il ne doit pas pouvoir devancer ce dont il n'est que la trace.
		if ( ! $this->comptes->ecrire_meta( $compte, LimiteEnvois::META_SOURCE, LimiteEnvois::encoder_source( $entrees ) ) ) {
			return false;
		}

		// (4) Le miroir est réécrit EN ENTIER depuis la source. Il n'est pas
		// rattrapé par incréments : la première écriture qui aboutit le remet
		// exactement à jour, quel que soit le nombre d'échecs précédents.
		return $this->comptes->ecrire_meta(
			$compte,
			LimiteEnvois::META,
			LimiteEnvois::encoder( LimiteEnvois::horodatages_de( $entrees ) )
		);
	}

	/**
	 * Lit la source du quota, amorcée depuis le miroir si elle est absente.
	 *
	 * **Absente n'est pas corrompue.** Absente veut dire « jamais migrée » :
	 * on amorce depuis le miroir hérité, chaque horodatage devenant `{a, e:''}`.
	 * Corrompue veut dire « état incompris » : elle se propage, et l'appelant
	 * refuse.
	 *
	 * L'amorçage reste **en mémoire** : une lecture n'écrit pas. La source
	 * amorcée sera persistée par la première confirmation qui aboutit, avec le
	 * créneau qu'elle ajoute.
	 *
	 * Le miroir n'est lu **que** dans ce cas d'amorçage, jamais en recours sur
	 * une source corrompue : ce serait transformer un état incompris en
	 * autorisation.
	 *
	 * @param int $compte Identifiant.
	 * @return array{entrees: array<int, array{a: int, e: string}>, corrompue: bool, absente: bool}
	 */
	private function lire_source_quota( int $compte ): array {
		$source = LimiteEnvois::decoder_source(
			$this->comptes->lire_meta( $compte, LimiteEnvois::META_SOURCE )
		);

		if ( empty( $source['absente'] ) ) {
			return $source;
		}

		$miroir = LimiteEnvois::decoder( $this->comptes->lire_meta( $compte, LimiteEnvois::META ) );

		// Un miroir hérité illisible n'autorise pas davantage : on le traite
		// comme plein, dans le sens restrictif.
		if ( ! empty( $miroir['corrompue'] ) ) {
			return array( 'entrees' => array(), 'corrompue' => true, 'absente' => false );
		}

		return array(
			'entrees'   => LimiteEnvois::amorcer_depuis_miroir( $miroir['horodatages'] ),
			'corrompue' => false,
			'absente'   => false,
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
