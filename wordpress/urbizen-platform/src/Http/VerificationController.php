<?php
/**
 * Page de vérification : GET affiche, POST consomme.
 *
 * **Le GET n'agit pas.** Il affiche l'adresse concernée et un bouton, sans
 * toucher au jeton. Un lien qui vérifierait en GET serait consommé par le
 * premier antivirus de messagerie ou préchargeur qui le suit, et le client
 * recevrait un lien mort sans avoir rien fait.
 *
 * **Aucune politique ici, et ce n'est pas un oubli.** L'autorisation EST la
 * validation du condensat par `VerificationService::consommer()`, qui vérifie
 * compte, cible, génération et échéance. Y superposer `AutorisationComptes`
 * donnerait l'illusion d'un contrôle supplémentaire là où le vrai contrôle est
 * déjà cryptographique.
 *
 * **Une vérification réussie NE CONNECTE PAS.** Donner une session à quiconque
 * suit un lien reçu par courriel ferait de l'accès à une boîte un accès au
 * compte. Le lien confirme une adresse ; il n'ouvre rien.
 *
 * **Quatre issues, pas cinq.** Succès, lien expiré, lien invalide ou déjà
 * utilisé, indisponible. Séparer les deux du milieu révélerait qu'un compte
 * donné existe et n'a pas de jeton en cours.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Account\LienVerification;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;
use Urbizen\Platform\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Affichage et consommation du lien de vérification.
 */
final class VerificationController {

	/**
	 * Compartiment de limitation, distinct de celui des autres parcours.
	 */
	public const BUCKET = 'verification';

	/**
	 * Compartiment du GET : plafond large, l'affichage n'a aucun effet.
	 */
	public const BUCKET_GET = 'verification_vue';

	/**
	 * Accroche l'action, en GET comme en POST.
	 *
	 * Une seule action, deux méthodes : c'est `handle()` qui les distingue.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . LienVerification::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . LienVerification::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Aiguille selon la méthode.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$methode = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );

		if ( 'GET' === $methode ) {
			self::afficher();

			return;
		}

		if ( 'POST' === $methode ) {
			self::consommer();

			return;
		}

		// Toute autre méthode est refusée avant tout effet.
		status_header( 405 );
		wp_die( esc_html__( 'Méthode non autorisée.', 'urbizen-platform' ), '', array( 'response' => 405 ) );
	}

	/**
	 * GET : affiche, sans jamais toucher au jeton.
	 *
	 * @return void
	 */
	private static function afficher(): void {
		self::entetes();

		$lu = LienVerification::lire( is_array( $_GET ) ? wp_unslash( $_GET ) : array() );

		if ( null === $lu ) {
			self::rediriger( 'invalide' );
		}

		// Plafond large : afficher ne coûte rien au système, mais un
		// préchargeur emballé ne doit pas pouvoir marteler la page.
		RateLimiter::reserve( self::BUCKET_GET, $_SERVER );

		/*
		 * On lit la cible pour l'afficher. C'est une LECTURE : le condensat
		 * n'est ni comparé, ni effacé, et l'émission n'est pas close. Une
		 * cible absente n'est pas une erreur affichable — la page se rend
		 * quand même, et c'est le POST qui tranchera.
		 */
		$comptes = new WpComptes();
		$cible   = (string) $comptes->lire_meta( $lu['compte'], \Urbizen\Platform\Account\JetonVerification::META_CIBLE );

		$urbizen_compte = $lu['compte'];
		$urbizen_jeton  = $lu['jeton'];
		$urbizen_cible  = $cible;

		require URBIZEN_PLATFORM_DIR . 'templates/comptes/verification.php';

		exit;
	}

	/**
	 * POST : consomme, puis redirige vers une URL NETTOYÉE.
	 *
	 * @return void
	 */
	private static function consommer(): void {
		self::entetes();

		$post = is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$nonce = isset( $post['_urbizen_nonce'] ) ? (string) $post['_urbizen_nonce'] : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, LienVerification::ACTION ) ) {
			self::rediriger( 'invalide' );
		}

		$lu = LienVerification::lire( $post );

		if ( null === $lu ) {
			self::rediriger( 'invalide' );
		}

		$creneau = RateLimiter::reserve( self::BUCKET, $_SERVER );

		if ( null === $creneau ) {
			self::rediriger( 'indisponible' );
		}

		// Le code est CALCULÉ ici : rediriger sous un `finally` empêcherait
		// celui-ci de s'exécuter, et le créneau resterait réservé.
		$code = 'indisponible';

		try {
			$service = new VerificationService( new WpComptes(), new WpdbGateway() );
			$motif   = $service->consommer( $lu['compte'], $lu['jeton'] );

			$code = self::code_public( $motif );
		} catch ( \Throwable $e ) {
			$code = 'indisponible';
		} finally {
			RateLimiter::confirm( $creneau );
		}

		self::rediriger( $code );
	}

	/**
	 * Traduit un motif technique en l'une des QUATRE issues publiques.
	 *
	 * `jeton_invalide` et « aucun jeton en cours » rendent le MÊME code : les
	 * séparer révélerait qu'un compte donné existe et n'a pas de jeton vivant.
	 *
	 * @param string $motif Motif technique.
	 * @return string
	 */
	private static function code_public( string $motif ): string {
		if ( '' === $motif ) {
			return 'confirme';
		}

		if ( 'jeton_expire' === $motif ) {
			return 'expire';
		}

		if ( 'verrou_indisponible' === $motif || 'exception' === $motif ) {
			return 'indisponible';
		}

		return 'invalide';
	}

	/**
	 * En-têtes de protection de la page portant le jeton.
	 *
	 * `Referrer-Policy: no-referrer` n'est pas décoratif : sans lui, le jeton
	 * partirait dans l'en-tête `Referer` de la première ressource externe
	 * rencontrée. La page n'en charge aucune, mais la règle ne doit pas
	 * dépendre de cette discipline.
	 *
	 * @return void
	 */
	private static function entetes(): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Redirige vers la page de résultat, en 303, **sans le jeton**.
	 *
	 * C'est ce nettoyage qui évite que l'historique du navigateur — ou un
	 * partage d'URL — n'emporte le jeton.
	 *
	 * @param string $code Code public.
	 * @return void
	 */
	private static function rediriger( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => ComptesController::ACTION_RESULTAT,
					'code'   => $code,
				),
				admin_url( 'admin-post.php' )
			),
			303
		);

		exit;
	}
}
