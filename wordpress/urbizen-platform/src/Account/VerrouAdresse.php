<?php
/**
 * Verrou **d'exclusion** par adresse canonique, pour sérialiser l'inscription.
 *
 * `trouver_par_adresse()` puis `creer()` n'est pas atomique : WordPress ne pose
 * aucune contrainte SQL `UNIQUE` sur `user_email`, donc deux inscriptions
 * simultanées peuvent toutes deux constater l'absence avant d'insérer, et créer
 * deux utilisateurs pour la même boîte. Ce verrou ferme cette course.
 *
 * **`GET_LOCK()` seul ne suffit PAS ; la garantie repose sur un ensemble.** Un
 * verrou consultatif est lié à la connexion : si celle-ci meurt, il se libère.
 * Mais `wpdb` reconnecte et **rejoue** silencieusement l'écriture sur une
 * connexion neuve, qui ne tient plus le verrou — deux inscriptions pourraient
 * encore aboutir. L'exclusion n'est réelle que par la conjonction de quatre
 * conditions, dont aucune n'est facultative :
 *
 * - **`GET_LOCK()` lié à la connexion** — pas d'échéance, donc pas de fenêtre à
 *   deux propriétaires par expiration d'un bail comme avec un TTL ;
 * - **une seule connexion** pour l'acquisition, la recherche, la création et la
 *   libération — `$wpdb` n'ouvre qu'une connexion par requête, et
 *   `wp_insert_user()` comme cette passerelle passent par elle ;
 * - **désactivation temporaire de la reconnexion et du rejeu de `wpdb`**
 *   pendant la section critique ({@see DatabaseGateway::interdire_reconnexion()})
 *   — sans quoi une écriture serait rejouée sur une connexion sans verrou ;
 * - **échec restrictif si la connexion est perdue** — la tentative lève
 *   {@see \Urbizen\Platform\Schema\ConnexionPerdue}, aucun jeton n'est émis, et
 *   la demande reste récupérable.
 *
 * Le nom du verrou, lui, ne révèle pas l'adresse : HMAC de l'adresse canonique
 * avec le secret du site, tronqué sous la limite du moteur (64 caractères). Le
 * secret sert aussi de cloison entre deux sites d'un même serveur MySQL, où les
 * noms de `GET_LOCK()` sont **globaux au serveur**. L'attente à l'acquisition est
 * bornée, jamais infinie.
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
