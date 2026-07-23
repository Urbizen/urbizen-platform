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

		/*
		 * LE LIEN EST AUTHENTIFIÉ AVANT QUE QUOI QUE CE SOIT NE S'AFFICHE.
		 *
		 * Se contenter de la forme du jeton, puis lire la cible avec
		 * l'identifiant fourni, suffisait à obtenir l'adresse de tout compte
		 * ayant une vérification en cours : il n'y avait qu'à essayer des
		 * identifiants numériques avec 64 caractères hexadécimaux quelconques.
		 * C'est l'annuaire que tout le reste du parcours refuse de fournir.
		 *
		 * `inspecter()` ne consomme rien : aucune écriture, aucun verrou
		 * écrivant, aucune clôture, aucun quota. Le GET reste sans effet.
		 */
		$service    = new VerificationService( new WpComptes(), new WpdbGateway() );
		$inspection = $service->inspecter( $lu['compte'], $lu['jeton'] );

		if ( '' !== $inspection['motif'] ) {
			self::rediriger( self::code_public( $inspection['motif'] ) );
		}

		$urbizen_compte = $lu['compte'];
		$urbizen_jeton  = $lu['jeton'];
		$urbizen_cible  = $inspection['cible'];

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

		/*
		 * Les échecs de PERSISTANCE ne sont pas des liens invalides. Dire
		 * « invalide » à quelqu'un dont le lien est bon mais dont l'écriture a
		 * échoué l'enverrait en redemander un, alors que le sien fonctionne
		 * encore : il faut lui dire de réessayer.
		 */
		$indisponibles = array(
			'verrou_indisponible',
			'exception',
			'quota_non_clos',
			'promotion_echouee',
			'ecriture_verifie_echouee',
			'verification_non_relue',
			'adresse_occupee',
		);

		if ( in_array( $motif, $indisponibles, true ) ) {
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
