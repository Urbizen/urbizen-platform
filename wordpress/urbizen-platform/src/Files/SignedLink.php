<?php
/**
 * Liens de téléchargement signés.
 *
 * Les documents étant hors de la racine publique, aucune URL ne mène
 * directement à eux. Un lien signé est donc la seule façon d'en donner
 * l'accès — et il est délibérément limité dans le temps.
 *
 * La signature couvre **tous** les champs : demande, fichier, échéance et
 * version du schéma. Changer l'un d'eux invalide le lien. On ne peut donc pas
 * prolonger une échéance, ni glisser vers le document d'une autre demande en
 * modifiant un chiffre.
 *
 * L'URL ne porte **aucune** information métier : ni chemin, ni nom de fichier,
 * ni nom de personne, ni adresse, ni empreinte. Une URL se retrouve dans
 * l'historique du navigateur, dans les journaux du serveur et dans le
 * `Referer` envoyé au site suivant.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Génération et vérification des liens signés.
 */
final class SignedLink {

	/**
	 * Action `admin-post` du téléchargement.
	 */
	public const ACTION = 'urbizen_file';

	/**
	 * Version du schéma de signature.
	 *
	 * Elle entre dans la signature : faire évoluer le format invalidera
	 * proprement les anciens liens plutôt que de les accepter par mégarde.
	 */
	public const SCHEMA = 1;

	/**
	 * Durée de validité par défaut : 14 jours.
	 */
	public const DEFAULT_TTL = 1209600;

	/**
	 * Durée de validité retenue, en secondes.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return max( 60, (int) apply_filters( 'urbizen_signed_link_ttl', self::DEFAULT_TTL ) );
	}

	/**
	 * Fabrique un lien signé.
	 *
	 * Régénérer un lien ne touche jamais au fichier : seule l'échéance change.
	 *
	 * @param int      $submission Identifiant de la demande.
	 * @param string   $file_id    Identifiant aléatoire du document.
	 * @param int|null $now        Horodatage courant (tests).
	 * @return string URL absolue.
	 */
	public static function url( int $submission, string $file_id, ?int $now = null ): string {
		$now     = null === $now ? time() : $now;
		$expires = $now + self::ttl();

		return add_query_arg(
			array(
				'action'    => self::ACTION,
				'v'         => self::SCHEMA,
				'submission' => $submission,
				'file'      => $file_id,
				'expires'   => $expires,
				'signature' => self::sign( $submission, $file_id, $expires ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Vérifie les paramètres d'un lien.
	 *
	 * @param array<string, mixed> $params Paramètres reçus.
	 * @param int|null             $now    Horodatage courant (tests).
	 * @return array{ok:bool,code:string,submission:int,file:string}
	 */
	public static function verify( array $params, ?int $now = null ): array {
		$now = null === $now ? time() : $now;

		// Aucune coercition PHP ne doit intervenir dans la chaîne signée : un
		// tableau, un flottant ou une notation scientifique se convertiraient
		// en un entier qui ne correspondrait plus à ce qui a été signé. On
		// exige donc des formes strictement canoniques, avant tout calcul.
		foreach ( array( 'v', 'submission', 'file', 'expires', 'signature' ) as $cle ) {
			if ( ! isset( $params[ $cle ] ) || ! is_scalar( $params[ $cle ] ) ) {
				return self::refus();
			}
		}

		$version    = (string) $params['v'];
		$submission = (string) $params['submission'];
		$file       = (string) $params['file'];
		$expires    = (string) $params['expires'];
		$signature  = (string) $params['signature'];

		// Entiers décimaux, sans signe, sans zéro initial superflu, bornés.
		if ( 1 !== preg_match( '/^[1-9]\d{0,9}$/', $submission )
			|| 1 !== preg_match( '/^[1-9]\d{0,11}$/', $expires )
			|| (string) self::SCHEMA !== $version ) {
			return self::refus();
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $file ) ) {
			return self::refus();
		}

		// Longueur exacte d'un HMAC-SHA256 en hexadécimal.
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return self::refus();
		}

		$submission = (int) $submission;
		$expires    = (int) $expires;

		// Comparaison à temps constant : une comparaison naïve laisse fuir la
		// signature attendue, octet par octet, par mesure du temps de réponse.
		if ( ! hash_equals( self::sign( $submission, $file, $expires ), $signature ) ) {
			return self::refus();
		}

		if ( $expires <= 0 || $now > $expires ) {
			return self::refus();
		}

		return array(
			'ok'         => true,
			'code'       => 'success',
			'submission' => $submission,
			'file'       => $file,
		);
	}

	/**
	 * Signature d'un triplet.
	 *
	 * @param int    $submission Demande.
	 * @param string $file_id    Document.
	 * @param int    $expires    Échéance.
	 * @return string
	 */
	private static function sign( int $submission, string $file_id, int $expires ): string {
		$payload = implode( '|', array( self::SCHEMA, $submission, $file_id, $expires ) );

		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	/**
	 * Secret de signature.
	 *
	 * Les sels WordPress vivent dans `wp-config.php`, hors dépôt.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return wp_salt( 'auth' ) . '|urbizen-signed-link';
	}

	/**
	 * Refus.
	 *
	 * Un code unique, volontairement : distinguer « signature fausse » de
	 * « lien expiré » renseignerait qui cherche à en fabriquer un.
	 *
	 * @return array{ok:bool,code:string,submission:int,file:string}
	 */
	private static function refus(): array {
		return array(
			'ok'         => false,
			'code'       => 'invalid_link',
			'submission' => 0,
			'file'       => '',
		);
	}
}
