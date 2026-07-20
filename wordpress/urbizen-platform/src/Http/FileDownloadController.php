<?php
/**
 * Diffusion d'un document privé.
 *
 * Seul point d'accès aux documents. Il n'existe aucune URL directe : le
 * fichier vit hors de la racine publique, et ce contrôleur est la seule chose
 * qui sait le retrouver.
 *
 * **Toute défaillance produit la même réponse.** Signature fausse, lien
 * expiré, demande inexistante, fichier effacé : un 404 identique. Distinguer
 * les cas révélerait qu'une demande existe — et un identifiant de demande est
 * un entier qu'on essaie en quelques secondes.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Téléchargement authentifié par signature.
 */
final class FileDownloadController {

	/**
	 * Taille des blocs de lecture, en octets.
	 */
	private const BLOC = 262144;

	/**
	 * Accroche les deux points d'entrée.
	 *
	 * Un lien reçu par courriel est ouvert sans session WordPress : la variante
	 * `nopriv` est indispensable. La signature tient lieu d'authentification.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . SignedLink::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . SignedLink::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Point d'entrée HTTP.
	 *
	 * @return void
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- la signature HMAC tient lieu de nonce.
		$params = isset( $_GET ) ? (array) wp_unslash( $_GET ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'GET' !== $methode ) {
			self::introuvable();
		}

		$document = self::locate( $params );

		if ( null === $document ) {
			self::introuvable();
		}

		self::stream( $document );
	}

	/**
	 * Retrouve le document désigné par un lien signé.
	 *
	 * Méthode sans effet HTTP : elle ne diffuse rien et ne termine pas le
	 * script. C'est ce qui la rend testable.
	 *
	 * @param array<string, mixed> $params Paramètres reçus.
	 * @param int|null             $now    Horodatage courant (tests).
	 * @return array<string, mixed>|null Document et chemin réel, ou null.
	 */
	public static function locate( array $params, ?int $now = null ): ?array {
		$verdict = SignedLink::verify( $params, $now );

		if ( ! $verdict['ok'] ) {
			return null;
		}

		$demande = SubmissionRepository::get( $verdict['submission'] );

		if ( null === $demande ) {
			return null;
		}

		$trouve = null;

		foreach ( $demande['files'] as $file ) {
			if ( isset( $file['id'] ) && hash_equals( (string) $file['id'], $verdict['file'] ) ) {
				$trouve = $file;
				break;
			}
		}

		if ( null === $trouve ) {
			return null;
		}

		// Storage refuse toute sortie de la racine privée, tout lien
		// symbolique et tout chemin inexistant.
		$reel = Storage::resolve( (string) ( $trouve['relative_path'] ?? '' ) );

		if ( null === $reel ) {
			return null;
		}

		$trouve['path'] = $reel;

		return $trouve;
	}

	/**
	 * Diffuse un document.
	 *
	 * @param array<string, mixed> $document Document localisé.
	 * @return void
	 */
	private static function stream( array $document ): void {
		$taille = (int) filesize( $document['path'] );

		nocache_headers();

		header( 'Content-Type: ' . self::header_safe( (string) $document['mime'] ) );
		header( 'Content-Length: ' . $taille );
		header( 'Content-Disposition: attachment; filename="' . self::filename( (string) $document['original_name'], (string) $document['extension'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Journal : identifiants techniques seulement. Ni nom, ni chemin.
		Logger::info( sprintf( 'document servi : fichier %s (%d octets)', substr( (string) $document['id'], 0, 8 ), $taille ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$flux = fopen( $document['path'], 'rb' );

		if ( false === $flux ) {
			self::introuvable();
		}

		while ( ! feof( $flux ) ) {
			echo fread( $flux, self::BLOC ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flux binaire.
			flush();
		}

		fclose( $flux );

		// On s'arrête ici : WordPress ne doit pas ajouter une page après le
		// contenu binaire.
		exit;
	}

	/**
	 * Nom proposé au téléchargement.
	 *
	 * Débarrassé de tout chemin, de tout retour chariot et de tout guillemet :
	 * il entre dans un en-tête HTTP, où un saut de ligne permettrait d'en
	 * injecter d'autres.
	 *
	 * @param string $name      Nom d'origine nettoyé.
	 * @param string $extension Extension validée.
	 * @return string
	 */
	public static function filename( string $name, string $extension ): string {
		$name = str_replace( '\\', '/', $name );
		$name = basename( $name );
		$name = (string) preg_replace( '/[\x00-\x1f\x7f]/u', '', $name );
		$name = str_replace( array( '"', "'", ';' ), '', $name );
		$name = trim( $name );

		if ( '' === $name ) {
			$name = 'document';
		}

		if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== strtolower( $extension ) ) {
			$name .= '.' . $extension;
		}

		return substr( $name, 0, 150 );
	}

	/**
	 * Nettoie une valeur destinée à un en-tête.
	 *
	 * @param string $valeur Valeur.
	 * @return string
	 */
	private static function header_safe( string $valeur ): string {
		return (string) preg_replace( '/[^\x20-\x7e]/', '', $valeur );
	}

	/**
	 * Réponse générique.
	 *
	 * @return never
	 */
	private static function introuvable() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		echo esc_html__( 'Ce lien de téléchargement n’est plus valide.', 'urbizen-platform' );

		exit;
	}
}
