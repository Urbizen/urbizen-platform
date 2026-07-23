<?php
/**
 * Émission de courriel en attente, portée par **une seule** métadonnée.
 *
 * Le problème qu'elle résout est une course que la confirmation ne pouvait pas
 * fermer, parce qu'elle arrive trop tard :
 *
 *     P1 prépare A · P1 libère le verrou · P2 prépare B et remplace A
 *     P1 envoie A · P2 envoie B → deux courriels partent, A est déjà invalide
 *
 * Le destinataire reçoit alors deux liens dont l'un ne fonctionne pas, sans
 * pouvoir distinguer lequel. Confirmer après l'envoi ne l'empêche pas : entre la
 * préparation de P1 et son envoi, aucun état ne dit que P1 est en cours.
 *
 * **Cette classe est cet état.** Tant qu'une émission non expirée existe, aucune
 * autre préparation n'est possible pour ce compte. L'appelant confirme ou annule
 * explicitement, en présentant l'identifiant qu'il a reçu — ce qui interdit à un
 * ancien appelant de clore une émission plus récente que la sienne.
 *
 * Format de la valeur, JSON :
 *
 *     {"id":"01J…","generation":3,"cible":"a@b.fr",
 *      "cree_le":1785000000,"expire_le":1785000300,"statut":"prepare"}
 *
 * **Le jeton brut n'y figure jamais** — ni en clair, ni sous forme de condensat.
 * La valeur contient en revanche l'adresse visée : c'est une donnée personnelle,
 * elle ne doit ni être journalisée ni ressortir par WP-CLI.
 *
 * Cette classe est pure : elle encode, décode et juge des tableaux. La sûreté
 * vient de son appelant, qui ne la manipule que sous `VerrouCompte`.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

/**
 * État d'une émission préparée mais pas encore close.
 */
final class EmissionEnAttente {

	/**
	 * Clé de métadonnée.
	 */
	public const META = '_urbizen_verif_emission_en_attente';

	/**
	 * Durée de vie, en secondes.
	 *
	 * Volontairement courte, et sans rapport avec celle du jeton : elle ne borne
	 * que l'intervalle entre la préparation et l'envoi. Un processus mort au
	 * milieu ne doit pas bloquer le compte vingt-quatre heures.
	 */
	public const TTL = 300;

	/**
	 * Unique statut existant en E2.1.
	 */
	public const STATUT = 'prepare';

	/**
	 * Encode une émission.
	 *
	 * @param string $id         Identifiant d'émission.
	 * @param int    $generation Génération du jeton associé.
	 * @param string $cible      Adresse visée.
	 * @param int    $cree_le    Horodatage de création.
	 * @return string
	 */
	public static function encoder( string $id, int $generation, string $cible, int $cree_le ): string {
		return (string) json_encode(
			array(
				'id'         => $id,
				'generation' => $generation,
				'cible'      => $cible,
				'cree_le'    => $cree_le,
				'expire_le'  => $cree_le + self::TTL,
				'statut'     => self::STATUT,
			)
		);
	}

	/**
	 * Décode la métadonnée.
	 *
	 * **Une valeur illisible est rendue comme présente et corrompue**, jamais
	 * comme absente. La traiter comme absente autoriserait une seconde émission
	 * exactement là où l'on ne comprend plus l'état — c'est-à-dire à l'endroit où
	 * la garantie doit être la plus ferme.
	 *
	 * Une émission corrompue n'est en revanche confirmable ni annulable par
	 * personne : aucun identifiant valide ne peut lui correspondre. Elle est donc
	 * déclarée **expirée**, ce qui la rend nettoyable par la préparation
	 * suivante — laquelle invalide au passage le jeton associé. Le compte n'est
	 * jamais condamné, et deux jetons vivants restent impossibles.
	 *
	 * @param string|null $brut Valeur stockée.
	 * @return array{id: string, generation: int, cible: string, cree_le: int, expire_le: int, corrompue: bool}|null
	 */
	public static function decoder( ?string $brut ): ?array {
		if ( null === $brut || '' === $brut ) {
			return null;
		}

		$vide = array(
			'id'         => '',
			'generation' => 0,
			'cible'      => '',
			'cree_le'    => 0,
			'expire_le'  => 0,
			'corrompue'  => true,
		);

		$decode = json_decode( $brut, true );

		if ( ! is_array( $decode ) || array_is_list( $decode ) ) {
			return $vide;
		}

		foreach ( array( 'id', 'generation', 'cible', 'cree_le', 'expire_le', 'statut' ) as $attendu ) {
			if ( ! array_key_exists( $attendu, $decode ) ) {
				return $vide;
			}
		}

		if ( self::STATUT !== $decode['statut'] ) {
			return $vide;
		}

		if ( ! is_string( $decode['id'] ) || 1 !== preg_match( '/^[0-9A-Za-z]{26}$/', $decode['id'] ) ) {
			return $vide;
		}

		if ( ! is_string( $decode['cible'] ) || '' === $decode['cible'] ) {
			return $vide;
		}

		foreach ( array( 'generation', 'cree_le', 'expire_le' ) as $entier ) {
			if ( ! is_int( $decode[ $entier ] ) || $decode[ $entier ] < 0 ) {
				return $vide;
			}
		}

		return array(
			'id'         => $decode['id'],
			'generation' => $decode['generation'],
			'cible'      => $decode['cible'],
			'cree_le'    => $decode['cree_le'],
			'expire_le'  => $decode['expire_le'],
			'corrompue'  => false,
		);
	}

	/**
	 * L'émission est-elle expirée — donc nettoyable ?
	 *
	 * Une émission corrompue l'est toujours : voir `decoder()`.
	 *
	 * @param array{expire_le: int, corrompue: bool} $emission   Émission décodée.
	 * @param int                                    $maintenant Horloge.
	 * @return bool
	 */
	public static function est_expiree( array $emission, int $maintenant ): bool {
		if ( ! empty( $emission['corrompue'] ) ) {
			return true;
		}

		return (int) $emission['expire_le'] <= $maintenant;
	}

	/**
	 * L'identifiant présenté est-il celui de cette émission ?
	 *
	 * Comparaison en temps constant, et refus systématique d'une émission
	 * corrompue : son identifiant est vide, et une chaîne vide ne doit pas se
	 * laisser égaler.
	 *
	 * @param array{id: string, corrompue: bool} $emission Émission décodée.
	 * @param string                             $presente Identifiant présenté.
	 * @return bool
	 */
	public static function correspond( array $emission, string $presente ): bool {
		if ( ! empty( $emission['corrompue'] ) || '' === $presente || '' === (string) $emission['id'] ) {
			return false;
		}

		return hash_equals( (string) $emission['id'], $presente );
	}
}
