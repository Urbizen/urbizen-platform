<?php
/**
 * Verrou **d'exclusion** par adresse canonique, pour sérialiser l'inscription.
 *
 * `trouver_par_adresse()` puis `creer()` n'est pas atomique : WordPress ne pose
 * aucune contrainte SQL `UNIQUE` sur `user_email`, donc deux inscriptions
 * simultanées peuvent toutes deux constater l'absence avant d'insérer, et créer
 * deux utilisateurs pour la même boîte. Ce verrou ferme cette course.
 *
 * **Pourquoi un verrou consultatif de la base, et non un bail à échéance.** Une
 * option horodatée avec un TTL est un *bail sans fencing* : si le propriétaire
 * est suspendu au-delà de l'échéance, un second processus reprend le verrou
 * expiré, constate l'adresse libre, et **les deux** peuvent créer un compte —
 * la reprise par compare-et-échange protège le verrou, pas la section critique
 * de l'ancien propriétaire. `GET_LOCK()` n'a pas d'échéance : il tient tant que
 * la **connexion** qui l'a pris vit, et se libère **de lui-même** dès qu'elle
 * meurt. Il n'existe donc aucune fenêtre à deux propriétaires : ou bien le
 * premier vit et tient (le second attend, puis échoue), ou bien il est mort et
 * ne peut plus rien écrire sur une connexion fermée.
 *
 * Trois conditions rendent ce fencing réel :
 *
 * - **une seule connexion** pour l'acquisition, la recherche, la création et la
 *   libération — c'est le cas : `$wpdb` n'ouvre qu'une connexion par requête, et
 *   `wp_insert_user()` comme cette passerelle passent par elle ;
 * - **un nom qui ne révèle pas l'adresse** — HMAC de l'adresse canonique avec le
 *   secret du site, tronqué sous la limite du moteur (64 caractères). Le secret
 *   sert aussi de cloison : deux sites sur le même serveur MySQL, où les noms de
 *   `GET_LOCK()` sont **globaux au serveur**, ne se marchent pas dessus ;
 * - **une attente bornée** à l'acquisition, jamais infinie.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Urbizen\Platform\Schema\DatabaseGateway;

/**
 * Verrou d'inscription dérivé de l'adresse, sur `GET_LOCK()`.
 */
final class VerrouAdresse {

	/**
	 * Préfixe du nom de verrou. Court : le nom complet doit tenir sous la
	 * limite de 64 caractères qu'impose MySQL 8 aux noms de `GET_LOCK()`.
	 */
	public const PREFIXE = 'urbz_adr_';

	/**
	 * Attente maximale, en secondes, à l'acquisition. Bornée : une inscription
	 * dure des millisecondes, et le concurrent qui patiente doit obtenir la
	 * main dès que le gagnant relâche, sans jamais bloquer indéfiniment.
	 */
	public const ATTENTE = 10;

	/**
	 * @var DatabaseGateway
	 */
	private DatabaseGateway $db;

	/**
	 * @var string
	 */
	private string $nom;

	/**
	 * Le verrou est-il encore tenu par cet objet ? Passe à `false` dès la
	 * première libération, pour qu'un second appel ne prétende pas libérer.
	 *
	 * @var bool
	 */
	private bool $tenu = true;

	/**
	 * @param DatabaseGateway $db  Passerelle — la **même** connexion que la
	 *                             recherche et la création qui suivront.
	 * @param string          $nom Nom du verrou, déjà dérivé.
	 */
	private function __construct( DatabaseGateway $db, string $nom ) {
		$this->db  = $db;
		$this->nom = $nom;
	}

	/**
	 * Secret du site, servant de clé au HMAC du nom de verrou.
	 *
	 * `wp_salt()` est le secret de WordPress. Le repli n'est atteint qu'en
	 * l'absence de WordPress — les bancs sans WP — et n'affaiblit pas la
	 * production, qui dispose toujours de `wp_salt()`.
	 *
	 * @return string
	 */
	private static function secret(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return (string) wp_salt( 'auth' );
		}

		return 'urbizen-adresse-lock-secret-hors-wordpress';
	}

	/**
	 * Nom de verrou pour une adresse canonique.
	 *
	 * HMAC-SHA-256 de l'adresse avec le secret du site, tronqué à 48 caractères
	 * hexadécimaux : avec le préfixe, 57 caractères, sous la limite de 64. Le
	 * nom ne révèle pas l'adresse et ne peut être forgé sans le secret.
	 *
	 * @param string $adresse Adresse canonique.
	 * @return string
	 */
	public static function nom_pour( string $adresse ): string {
		return self::PREFIXE . substr( hash_hmac( 'sha256', $adresse, self::secret() ), 0, 48 );
	}

	/**
	 * Tente d'acquérir le verrou d'une adresse, avec une attente bornée.
	 *
	 * `GET_LOCK()` rend `1` si le verrou est obtenu, `0` si l'attente expire
	 * sans l'obtenir, `NULL` sur erreur. On n'accepte que `1` : tout le reste
	 * est un refus, jamais une présomption d'exclusivité.
	 *
	 * @param DatabaseGateway $db      Passerelle.
	 * @param string          $adresse Adresse canonique, non vide.
	 * @param int             $attente Attente maximale en secondes.
	 * @return self|null `null` si le verrou n'a pas été obtenu.
	 */
	public static function acquerir( DatabaseGateway $db, string $adresse, int $attente = self::ATTENTE ): ?self {
		if ( '' === $adresse ) {
			return null;
		}

		$nom    = self::nom_pour( $adresse );
		$obtenu = $db->valeur( 'SELECT GET_LOCK(%s, %d)', array( $nom, $attente ) );

		if ( '1' !== $obtenu ) {
			return null;
		}

		return new self( $db, $nom );
	}

	/**
	 * Libère le verrou, **une seule fois**, et dit si la libération a bien porté.
	 *
	 * `RELEASE_LOCK()` rend `1` si cette connexion tenait le verrou et l'a
	 * relâché, `0` s'il était tenu par une autre connexion, `NULL` s'il n'était
	 * pas pris. On exige `1`.
	 *
	 * @return bool
	 */
	public function liberer(): bool {
		if ( ! $this->tenu ) {
			return false;
		}

		$this->tenu = false;

		$libere = $this->db->valeur( 'SELECT RELEASE_LOCK(%s)', array( $this->nom ) );

		return '1' === $libere;
	}

	/**
	 * Nom du verrou tenu — pour les bancs, qui vérifient qu'il ne porte pas
	 * l'adresse en clair.
	 *
	 * @return string
	 */
	public function nom(): string {
		return $this->nom;
	}

	/**
	 * Le verrou est-il encore tenu par cet objet ?
	 *
	 * @return bool
	 */
	public function est_tenu(): bool {
		return $this->tenu;
	}
}
