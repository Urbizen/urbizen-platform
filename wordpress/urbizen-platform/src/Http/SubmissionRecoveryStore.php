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
	 * Lit et **supprime** la reprise associée à un identifiant (usage unique).
	 *
	 * Renvoie null si l'identifiant est absent, vide, mal formé, expiré, inconnu,
	 * ou si la charge est corrompue. La suppression a lieu **avant** le renvoi :
	 * une seconde consommation est toujours vide.
	 *
	 * @param mixed $id Identifiant opaque (contenu potentiellement libre).
	 * @return SubmissionRecovery|null
	 */
	public static function consume( $id ): ?SubmissionRecovery {
		if ( ! is_string( $id ) || 1 !== preg_match( self::REGLE_ID, $id ) ) {
			return null;
		}

		$cle  = self::cle( $id );
		$brut = get_transient( $cle );

		// Usage unique : on supprime dans tous les cas, avant même de décoder.
		delete_transient( $cle );

		if ( ! is_string( $brut ) || '' === $brut ) {
			return null;
		}

		$charge = json_decode( $brut, true );

		return SubmissionRecovery::from_payload( $charge );
	}

	/**
	 * Supprime une reprise sans la lire (nettoyage d'un dépôt devenu inutile,
	 * par exemple si l'émission du feedback signé échoue après le stockage).
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
	 * Secret de dérivation de clé, adossé au sel WordPress (hors dépôt), avec un
	 * contexte propre à cette finalité.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return wp_salt( 'auth' ) . '|urbizen-recovery';
	}
}
