<?php
/**
 * Stockage serveur, court et à usage unique, d'une {@see SubmissionRecovery}.
 *
 * **Pourquoi.** Après un rejet corrigeable, les valeurs nettoyées et les erreurs
 * publiques doivent survivre à la redirection sans jamais transiter par l'URL.
 * Elles sont donc déposées côté serveur, derrière un **identifiant opaque**
 * aléatoire, puis retrouvées **une seule fois**.
 *
 * **Identifiant.** 32 hexadécimaux issus de `random_bytes(16)` :
 * cryptographiquement fort, imprévisible, sans aucune donnée intrinsèque. Il ne
 * permet de retrouver **que** la reprise exacte qui lui est associée, jamais une
 * autre donnée.
 *
 * **Clé de stockage.** Dérivée d'un HMAC de l'identifiant (secret serveur, hors
 * dépôt) : l'identifiant lisible n'apparaît **jamais** dans le nom de la clé, et
 * aucune donnée personnelle n'y figure — ni référence, ni courriel, ni IP.
 *
 * **Durée.** Courte ({@see self::TTL}), alignée sur le feedback C1.
 *
 * **Usage unique.** `consume()` **supprime** la reprise avant de la renvoyer :
 * une reprise consommée n'est plus accessible. Une reprise non consommée expire
 * d'elle-même.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Support\Logger;
use Urbizen\Platform\Support\OptionMutex;
use Urbizen\Platform\Support\OptionsScan;

defined( 'ABSPATH' ) || exit;

/**
 * Dépôt et consommation, à usage unique, des reprises serveur.
 */
final class SubmissionRecoveryStore {

	/**
	 * Durée de validité, en secondes. Alignée sur le feedback C1.
	 */
	public const TTL = 600;

	/**
	 * Préfixe des clés de transient.
	 */
	private const PREFIXE = 'urbizen_rec_';

	/**
	 * Préfixe des clés d'option servant de **verrou de consommation exclusif**.
	 * Distinct du transient de charge : un verrou est une option (unicité de
	 * `option_name`), la charge est un transient.
	 */
	private const PREFIXE_VERROU = 'urbizen_recl_';

	/**
	 * Durée de vie d'un verrou de consommation, en secondes. **Alignée sur la
	 * durée de vie maximale de la reprise** ({@see self::TTL}) — jamais plus
	 * courte. Un verrou n'est **jamais recyclé ni volé** pendant qu'il court : tant
	 * qu'il existe, la reprise est réputée en cours de consommation (ou son
	 * ancien propriétaire peut encore reprendre), et toute autre tentative échoue
	 * fermé. Puisque le verrou est posé au plus tôt à la première consommation
	 * (donc jamais avant le dépôt de la charge), sa péremption survient **après**
	 * celle du transient : aucune fenêtre où un verrou expire alors que la charge
	 * reste lisible. Le nettoyage différé n'intervient donc qu'une fois la reprise
	 * devenue inconsommable.
	 */
	private const TTL_VERROU = self::TTL;

	/**
	 * Forme d'un identifiant opaque valide.
	 */
	private const REGLE_ID = '/^[0-9a-f]{32}$/';

	/**
	 * Dépose une reprise et renvoie son identifiant opaque, ou une chaîne vide
	 * en cas d'échec (encodage ou stockage).
	 *
	 * @param SubmissionRecovery $recovery Reprise à conserver.
	 * @return string Identifiant opaque, ou chaîne vide.
	 */
	public static function store( SubmissionRecovery $recovery ): string {
		$json = wp_json_encode( $recovery->to_payload() );

		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$id = bin2hex( random_bytes( 16 ) );

		if ( ! set_transient( self::cle( $id ), $json, self::TTL ) ) {
			return '';
		}

		return $id;
	}

	/**
	 * Lit et **supprime** la reprise associée à un identifiant (usage unique),
	 * derrière une **réservation exclusive atomique**.
	 *
	 * La consommation n'est PAS un simple `get` puis `delete` : de deux requêtes
	 * concurrentes portant le même identifiant, une **seule** acquiert le verrou
	 * (unicité de `option_name`, cf. {@see self::reserver()}) et devient le
	 * consommateur ; l'autre reçoit `null` **sans jamais lire la charge**. Aucune
	 * donnée n'est retournée avant acquisition exclusive.
	 *
	 * Renvoie null si l'identifiant est absent, vide, mal formé ; si le verrou ne
	 * peut être acquis (autre consommateur en cours, ou verrou abandonné encore
	 * vivant) ; si la charge est absente, expirée, inconnue ou corrompue ; ou si
	 * **la suppression de la charge n'est pas confirmée**. Dans ce dernier cas, le
	 * verrou est **conservé** pour bloquer toute autre consommation jusqu'à la
	 * péremption : **jamais** de restitution non suivie d'une suppression garantie.
	 *
	 * @param mixed    $id  Identifiant opaque (contenu potentiellement libre).
	 * @param int|null $now Horodatage courant (tests).
	 * @return SubmissionRecovery|null
	 */
	public static function consume( $id, ?int $now = null ): ?SubmissionRecovery {
		if ( ! is_string( $id ) || 1 !== preg_match( self::REGLE_ID, $id ) ) {
			return null;
		}

		// Acquisition exclusive AVANT toute lecture : le perdant repart sans charge.
		// On retient le JETON de propriétaire : la libération y sera conditionnée.
		$jeton = self::reserver( $id, $now );

		if ( null === $jeton ) {
			return null;
		}

		$cle  = self::cle( $id );
		$brut = get_transient( $cle );

		if ( ! is_string( $brut ) || '' === $brut ) {
			// Aucune charge (absente, expirée, déjà consommée) : rien à restituer.
			// On nettoie et on LIBÈRE NOTRE verrou (aucune donnée n'est en jeu).
			delete_transient( $cle );
			self::liberer( $id, $jeton );

			return null;
		}

		// Charge présente : sa suppression DOIT être confirmée pour garantir l'usage
		// unique. Si elle échoue, on NE libère PAS le verrou (il continue de bloquer
		// toute consommation jusqu'à sa péremption, alignée sur celle de la charge)
		// et on ne restitue RIEN : jamais de seconde restitution possible.
		if ( ! delete_transient( $cle ) ) {
			return null;
		}

		self::liberer( $id, $jeton );

		$charge = json_decode( $brut, true );

		return SubmissionRecovery::from_payload( $charge );
	}

	/**
	 * Réserve, de façon **atomique et non ambiguë**, le droit exclusif de consommer
	 * une reprise, et renvoie le **jeton de propriétaire** (ou null).
	 *
	 * L'acquisition passe par {@see OptionMutex::claim()} (un `INSERT IGNORE` : une
	 * seule insertion réussit, jamais d'écrasement, un contrat indépendant de
	 * `ON DUPLICATE KEY UPDATE` et de `CLIENT_FOUND_ROWS` — contrairement au retour
	 * de `add_option()`). La valeur stockée encode un **propriétaire aléatoire**
	 * (`random_bytes`) et l'expiration : elle sert de preuve d'appartenance à la
	 * libération conditionnelle, de sorte qu'un processus ne puisse jamais retirer
	 * le verrou d'un autre.
	 *
	 * **Aucun recyclage, aucun vol.** Si un verrou existe déjà, `claim()` échoue et
	 * l'on renvoie null sans y toucher. Le verrou n'est retiré que par son
	 * propriétaire (fin de traitement) ou, une fois la reprise inconsommable, par le
	 * ménage différé. Un processus qui meurt rend la reprise **définitivement
	 * indisponible** (fail-closed, préférable à une double restitution).
	 *
	 * @param string   $id  Identifiant opaque (déjà validé par l'appelant public).
	 * @param int|null $now Horodatage courant (tests).
	 * @return string|null Jeton de propriétaire (à repasser à {@see self::liberer()}), ou null.
	 */
	public static function reserver( string $id, ?int $now = null ): ?string {
		if ( 1 !== preg_match( self::REGLE_ID, $id ) ) {
			return null;
		}

		$now    = null === $now ? time() : $now;
		$jeton  = bin2hex( random_bytes( 16 ) ) . ':' . ( $now + self::TTL_VERROU );

		return OptionMutex::claim( self::verrou( $id ), $jeton ) ? $jeton : null;
	}

	/**
	 * Libère le verrou **si et seulement si** le jeton correspond exactement au
	 * propriétaire : jamais le verrou d'un autre (libération conditionnelle SQL).
	 *
	 * @param string      $id    Identifiant opaque.
	 * @param string|null $jeton Jeton de propriétaire obtenu de {@see self::reserver()}.
	 * @return void
	 */
	public static function liberer( string $id, ?string $jeton ): void {
		if ( is_string( $jeton ) && '' !== $jeton && 1 === preg_match( self::REGLE_ID, $id ) ) {
			OptionMutex::release( self::verrou( $id ), $jeton );
		}
	}

	/**
	 * Supprime une reprise sans la lire (nettoyage d'un dépôt devenu inutile,
	 * par exemple si l'émission du feedback signé échoue après le stockage — avant
	 * qu'aucun verrou n'existe). Ne touche PAS au verrou : à ce stade il n'y en a
	 * pas, et une purge inconditionnelle ne doit jamais viser un verrou vivant.
	 *
	 * @param mixed $id Identifiant opaque.
	 * @return void
	 */
	public static function delete( $id ): void {
		if ( is_string( $id ) && 1 === preg_match( self::REGLE_ID, $id ) ) {
			delete_transient( self::cle( $id ) );
		}
	}

	/**
	 * Supprime les verrous de consommation **périmés** restés dans `wp_options`
	 * (processus interrompu avant libération). Lecture **directe** (jamais le cache
	 * d'options) ; borné par préfixe et par LIMIT via {@see OptionsScan}. Un verrou
	 * dont la valeur est **corrompue** (illisible, non datable) n'est PAS supprimé :
	 * il est mis en **quarantaine** (conservé, compté à part, journalisé) plutôt que
	 * traité comme expiré — on ne libère jamais un verrou dont on ne peut prouver la
	 * péremption. Idempotent ; aucun identifiant lisible n'est journalisé.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de verrous supprimés.
	 */
	public static function cleanup_expired_locks( ?int $now = null ): int {
		$now        = null === $now ? time() : $now;
		$supprimes  = 0;
		$quarant5   = 0;

		foreach ( OptionsScan::names( self::PREFIXE_VERROU ) as $verrou ) {
			$brut   = OptionMutex::read( $verrou );
			$expire = self::expire_du_jeton( $brut );

			if ( null === $expire ) {
				// Valeur corrompue : quarantaine (conservée). On ne peut pas prouver
				// qu'elle est périmée, donc on ne la retire pas comme si elle l'était.
				++$quarant5;
				continue;
			}

			if ( $now >= $expire ) {
				OptionMutex::forget( $verrou );
				++$supprimes;
			}
		}

		if ( $quarant5 > 0 ) {
			Logger::info( sprintf( 'reprise : %d verrou(x) en quarantaine (valeur illisible)', $quarant5 ) );
		}

		return $supprimes;
	}

	/**
	 * Extrait l'expiration entière d'un jeton `propriétaire:expiration`, ou null si
	 * la forme est invalide (propriétaire non hexadécimal, expiration non entière).
	 *
	 * @param string|null $jeton Valeur brute du verrou.
	 * @return int|null
	 */
	private static function expire_du_jeton( ?string $jeton ): ?int {
		if ( ! is_string( $jeton ) || 1 !== preg_match( '/^[0-9a-f]{32}:(\d{1,15})$/', $jeton, $m ) ) {
			return null;
		}

		return (int) $m[1];
	}

	/**
	 * Clé de transient d'un identifiant : condensat HMAC non réversible, sans
	 * donnée personnelle et sans l'identifiant lisible lui-même.
	 *
	 * @param string $id Identifiant opaque.
	 * @return string
	 */
	private static function cle( string $id ): string {
		return self::PREFIXE . substr( hash_hmac( 'sha256', $id, self::secret() ), 0, 40 );
	}

	/**
	 * Nom d'option du verrou d'un identifiant : condensat HMAC dans un **domaine
	 * distinct** de la clé de charge, sans donnée personnelle ni identifiant
	 * lisible.
	 *
	 * @param string $id Identifiant opaque.
	 * @return string
	 */
	private static function verrou( string $id ): string {
		return self::PREFIXE_VERROU . substr( hash_hmac( 'sha256', 'verrou:' . $id, self::secret() ), 0, 40 );
	}

	/**
	 * Secret de dérivation de clé, adossé au sel WordPress (hors dépôt), avec un
	 * contexte propre à cette finalité.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return wp_salt( 'auth' ) . '|urbizen-recovery';
	}
}
