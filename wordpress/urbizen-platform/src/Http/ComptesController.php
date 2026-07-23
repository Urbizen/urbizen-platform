<?php
/**
 * Actions `admin-post` du parcours public des comptes.
 *
 * **Trois actions anonymes, deux actions de session.** `admin_post_nopriv_*`
 * n'est déclaré que pour les trois premières : un visiteur déconnecté ne doit
 * pas même atteindre le code des deux autres.
 *
 * | Action                     | Méthode | nopriv | Politique                |
 * |----------------------------|---------|--------|--------------------------|
 * | `urbizen_inscription`      | POST    | oui    | aucune — acte anonyme    |
 * | `urbizen_resultat`         | GET     | oui    | aucune — aucun effet     |
 * | `urbizen_changer_adresse`  | POST    | NON    | `compte.modifier`        |
 * | `urbizen_renvoi_connecte`  | POST    | NON    | `verification.demander`  |
 *
 * **Il n'existe pas d'action de renvoi public.** Le shortcode `[urbizen_renvoi]`
 * poste vers `urbizen_inscription` : `InscriptionService` sait déjà relancer un
 * compte non vérifié et refuser un compte vérifié, et une seconde action ferait
 * une seconde règle à tenir en cohérence.
 *
 * **La politique n'intervient pas sur les actes anonymes.** Une politique
 * répond à « cet acteur peut-il agir sur cette ressource » : à l'inscription il
 * n'y a ni acteur ni ressource. Y brancher `AutorisationComptes` donnerait
 * l'illusion d'un contrôle là où il n'y a rien à contrôler.
 *
 * **Le nonce anonyme n'est pas une protection.** WordPress le calcule depuis
 * l'identifiant de l'utilisateur, qui vaut zéro pour tout visiteur déconnecté :
 * c'est une valeur partagée, obtenue en chargeant la page, et rejouable. La
 * protection tient à l'empilement — méthode, nonce, jeton anti-robot,
 * limitation par origine, réponse uniforme, quota par compte — dont aucun étage
 * ne suffit seul.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Account\AutorisationComptes;
use Urbizen\Platform\Account\EnvoiVerification;
use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpCurrentUser;
use Urbizen\Platform\Adapter\WpdbGateway;
use Urbizen\Platform\Domain\Account\DemandeVerification;
use Urbizen\Platform\Mail\WordPressMailTransport;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Les quatre parcours, et la page de résultat.
 */
final class ComptesController {

	public const ACTION_INSCRIPTION = 'urbizen_inscription';
	public const ACTION_RESULTAT    = 'urbizen_resultat';
	public const ACTION_CHANGEMENT  = 'urbizen_changer_adresse';
	public const ACTION_RENVOI      = 'urbizen_renvoi_connecte';

	/**
	 * Compartiment de limitation par origine.
	 */
	public const BUCKET = 'comptes';

	/**
	 * **Liste fermée** des codes que la page de résultat accepte d'afficher.
	 *
	 * Tout code hors de cette liste rend le message générique. Sans cette
	 * fermeture, l'URL de résultat deviendrait un canal d'affichage arbitraire.
	 */
	public const CODES = array(
		'verifiez',
		'confirme',
		'expire',
		'invalide',
		'indisponible',
		'change',
		'refus',
	);

	/**
	 * Code rendu par l'inscription ET le renvoi public, quoi qu'il advienne.
	 *
	 * Adresse libre, prise non vérifiée, prise vérifiée, quota épuisé : la
	 * réponse est la même. Distinguer offrirait un annuaire de la clientèle.
	 */
	public const CODE_UNIFORME = 'verifiez';

	/**
	 * Accroche les actions et les shortcodes.
	 *
	 * D-046 prévoit cinq classes de production, et pas une sixième pour porter
	 * trois shortcodes : ils sont enregistrés ici.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Trois actions anonymes.
		add_action( 'admin_post_nopriv_' . self::ACTION_INSCRIPTION, array( self::class, 'handle_inscription' ) );
		add_action( 'admin_post_' . self::ACTION_INSCRIPTION, array( self::class, 'handle_inscription' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_RESULTAT, array( self::class, 'handle_resultat' ) );
		add_action( 'admin_post_' . self::ACTION_RESULTAT, array( self::class, 'handle_resultat' ) );

		// Deux actions de session — AUCUN `nopriv`.
		add_action( 'admin_post_' . self::ACTION_CHANGEMENT, array( self::class, 'handle_changement' ) );
		add_action( 'admin_post_' . self::ACTION_RENVOI, array( self::class, 'handle_renvoi' ) );

		add_shortcode( 'urbizen_inscription', array( self::class, 'rendre_formulaire_inscription' ) );
		add_shortcode( 'urbizen_renvoi', array( self::class, 'rendre_formulaire_renvoi' ) );
		add_shortcode( 'urbizen_changer_adresse', array( self::class, 'rendre_formulaire_changement' ) );
	}

	// ==================================================================
	// ACTIONS ANONYMES
	// ==================================================================

	/**
	 * Inscription d'un particulier — et renvoi public, par le même chemin.
	 *
	 * @return void
	 */
	public static function handle_inscription(): void {
		if ( ! self::est_post() ) {
			self::refuser_methode();
		}

		$post = self::post();

		// (2) Nonce. Il écarte le rejeu grossier, rien de plus.
		if ( ! self::nonce_valide( $post, self::ACTION_INSCRIPTION ) ) {
			self::rediriger( self::CODE_UNIFORME );
		}

		$jeton_robot = isset( $post['urbizen_token'] ) ? (string) $post['urbizen_token'] : '';

		/*
		 * (3) Jeton anti-robot : VÉRIFIER, PUIS réserver.
		 *
		 * `reserve_token()` seule ne contrôle rien : elle accepterait un jeton
		 * fabriqué de toutes pièces. La signature, l'âge minimal et l'échéance
		 * ne sont éprouvés que par `verify_token()`.
		 */
		$verdict = AntiSpam::verify_token( $jeton_robot );

		if ( empty( $verdict['ok'] ) ) {
			self::rediriger( self::CODE_UNIFORME );
		}

		if ( ! AntiSpam::reserve_token( $jeton_robot ) ) {
			self::rediriger( self::CODE_UNIFORME );
		}

		// (4) Limitation par origine. Tout échec ici LIBÈRE le jeton : la
		// tentative n'a pas franchi la frontière, elle ne doit rien consommer.
		$creneau = null;

		try {
			$creneau = RateLimiter::reserve( self::BUCKET, $_SERVER );
		} catch ( \Throwable $e ) {
			AntiSpam::release_token( $jeton_robot );

			self::rediriger( self::CODE_UNIFORME );
		}

		if ( null === $creneau ) {
			AntiSpam::release_token( $jeton_robot );

			self::rediriger( self::CODE_UNIFORME );
		}

		/*
		 * ══ FRONTIÈRE DE COÛT ══
		 *
		 * La tentative est structurellement valide. Elle coûte un créneau,
		 * QUELLE QUE SOIT l'issue métier : adresse libre, inconnue, déjà
		 * vérifiée, quota par compte épuisé, exception interne. Libérer sur
		 * certaines issues laisserait un attaquant sonder gratuitement en
		 * visant précisément celles-là.
		 *
		 * Au-delà d'ici : ni `release_token()`, ni `release()`, ni `exit`.
		 * Un `exit` — c'est-à-dire une redirection — empêcherait le `finally`
		 * de s'exécuter et laisserait le créneau réservé jusqu'à la fin de la
		 * fenêtre. Le code de résultat est donc CALCULÉ ici et la redirection
		 * n'a lieu qu'APRÈS.
		 */
		try {
			$adresse    = isset( $post['adresse'] ) ? (string) $post['adresse'] : '';
			$motdepasse = isset( $post['motdepasse'] ) ? (string) $post['motdepasse'] : '';

			$inscription = self::inscription_service()->inscrire( $adresse, $motdepasse );

			// L'émission est DÉJÀ préparée par le service : la repasser à
			// `emettre()` la ferait préparer une seconde fois, refusée, et
			// aucun courriel ne partirait.
			if ( null !== $inscription['emission'] && $inscription['emission']->est_prepare() ) {
				self::envoi()->emettre_prepare( $inscription['compte'], $inscription['emission'] );
			}
		} catch ( \Throwable $e ) {
			// Absorbée : aucun détail ne franchit la frontière publique, et la
			// réponse reste celle de tous les autres cas.
			Logger::error( 'inscription : exception interne' );
		} finally {
			/*
			 * Imbriqué : un échec de `consume_token()` ne doit pas empêcher la
			 * confirmation du créneau, sans quoi le coût ne serait pas perçu.
			 *
			 * Et l'ensemble est absorbé : une panne de stockage pendant la
			 * finalisation ne doit pas remonter à la place de la redirection.
			 * La confirmation a été TENTÉE avant que l'exception ne soit
			 * attrapée — l'ordre des blocs le garantit.
			 */
			try {
				try {
					AntiSpam::consume_token( $jeton_robot );
				} finally {
					RateLimiter::confirm( $creneau );
				}
			} catch ( \Throwable $e ) {
				Logger::error( 'inscription : finalisation incomplete' );
			}
		}

		self::rediriger( self::CODE_UNIFORME );
	}

	/**
	 * Page de résultat : un GET sans le moindre effet.
	 *
	 * @return void
	 */
	public static function handle_resultat(): void {
		// GET exclusivement. Une page sans effet n'a aucune raison d'accepter
		// une méthode qui en suppose un, et le refus précède tout rendu.
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			self::refuser_methode();
		}

		$brut = isset( $_GET['code'] ) ? (string) wp_unslash( $_GET['code'] ) : '';
		$code = in_array( $brut, self::CODES, true ) ? $brut : '';

		self::servir_resultat( $code );
	}

	// ==================================================================
	// ACTIONS DE SESSION
	// ==================================================================

	/**
	 * Changement d'adresse depuis une session ouverte.
	 *
	 * @return void
	 */
	public static function handle_changement(): void {
		if ( ! self::est_post() ) {
			self::refuser_methode();
		}

		$post = self::post();

		if ( ! self::nonce_valide( $post, self::ACTION_CHANGEMENT ) ) {
			self::rediriger( 'refus' );
		}

		// La session est exigée AVANT toute autre chose : un visiteur
		// déconnecté ne doit pas atteindre la suite.
		if ( ! is_user_logged_in() ) {
			self::rediriger( 'refus' );
		}

		$compte = (int) get_current_user_id();

		// Le créneau est réservé AVANT la politique : un titulaire qui sonde
		// d'autres comptes brûle ses propres créneaux.
		$creneau = RateLimiter::reserve( self::BUCKET, $_SERVER );

		if ( null === $creneau ) {
			self::rediriger( 'refus' );
		}

		/*
		 * Le code est CALCULÉ ici, jamais rendu : `rediriger()` exécute `exit`,
		 * et PHP n'exécute pas le `finally` lors d'un `exit`. Rediriger depuis
		 * ce bloc laisserait le créneau réservé jusqu'à la fin de la fenêtre.
		 */
		$code = 'refus';

		try {
			$objet = self::comptes()->trouver_par_id( $compte );

			if ( null !== $objet && self::autorisation()->peut( 'compte.modifier', $objet ) ) {
				$nouvelle = isset( $post['adresse'] ) ? (string) $post['adresse'] : '';

				self::envoi()->emettre_changement_adresse( $compte, $nouvelle );

				$code = 'change';
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'changement d adresse : exception interne' );

			$code = 'refus';
		} finally {
			RateLimiter::confirm( $creneau );
		}

		self::rediriger( $code );
	}

	/**
	 * Renvoi demandé depuis l'espace client.
	 *
	 * @return void
	 */
	public static function handle_renvoi(): void {
		if ( ! self::est_post() ) {
			self::refuser_methode();
		}

		$post = self::post();

		if ( ! self::nonce_valide( $post, self::ACTION_RENVOI ) ) {
			self::rediriger( 'refus' );
		}

		if ( ! is_user_logged_in() ) {
			self::rediriger( 'refus' );
		}

		$compte  = (int) get_current_user_id();
		$creneau = RateLimiter::reserve( self::BUCKET, $_SERVER );

		if ( null === $creneau ) {
			self::rediriger( 'refus' );
		}

		// Même règle : aucun `exit` sous un `finally`.
		$code = 'refus';

		try {
			$objet = self::comptes()->trouver_par_id( $compte );

			if ( null !== $objet
				&& self::autorisation()->peut( 'verification.demander', new DemandeVerification( $objet ) ) ) {
				self::envoi()->emettre( $compte );

				$code = self::CODE_UNIFORME;
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'renvoi connecte : exception interne' );

			$code = 'refus';
		} finally {
			RateLimiter::confirm( $creneau );
		}

		self::rediriger( $code );
	}

	// ==================================================================
	// SHORTCODES
	// ==================================================================

	/**
	 * @return string
	 */
	public static function rendre_formulaire_inscription(): string {
		return self::formulaire(
			self::ACTION_INSCRIPTION,
			__( 'Créer mon compte', 'urbizen-platform' ),
			true
		);
	}

	/**
	 * Renvoi public : **même action**, sans mot de passe.
	 *
	 * @return string
	 */
	public static function rendre_formulaire_renvoi(): string {
		return self::formulaire(
			self::ACTION_INSCRIPTION,
			__( 'Recevoir un nouveau lien', 'urbizen-platform' ),
			false
		);
	}

	/**
	 * Changement d'adresse — **ne rend rien** hors session.
	 *
	 * @return string
	 */
	public static function rendre_formulaire_changement(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		return self::formulaire(
			self::ACTION_CHANGEMENT,
			__( 'Changer mon adresse', 'urbizen-platform' ),
			false
		);
	}

	/**
	 * Formulaire HTML ordinaire, POST, fonctionnel sans JavaScript.
	 *
	 * @param string $action        Action `admin-post`.
	 * @param string $libelle       Libellé du bouton.
	 * @param bool   $mot_de_passe  Le champ de mot de passe est-il rendu ?
	 * @return string
	 */
	private static function formulaire( string $action, string $libelle, bool $mot_de_passe ): string {
		$html = '<form class="urbizen-comptes" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= wp_nonce_field( $action, '_urbizen_nonce', true, false );
		$html .= '<input type="hidden" name="urbizen_token" value="' . esc_attr( AntiSpam::issue_token() ) . '">';

		$html .= '<p><label for="urbizen-adresse">'
			. esc_html__( 'Adresse de courriel', 'urbizen-platform' )
			. '</label><br><input type="email" id="urbizen-adresse" name="adresse" required></p>';

		if ( $mot_de_passe ) {
			$html .= '<p><label for="urbizen-motdepasse">'
				. esc_html__( 'Mot de passe (12 caractères minimum)', 'urbizen-platform' )
				. '</label><br><input type="password" id="urbizen-motdepasse" name="motdepasse" required minlength="12"></p>';
		}

		$html .= '<p><button type="submit">' . esc_html( $libelle ) . '</button></p>';
		$html .= '</form>';

		return $html;
	}

	// ==================================================================
	// OUTILS
	// ==================================================================

	/**
	 * @return bool
	 */
	private static function est_post(): bool {
		return 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
	}

	/**
	 * Refuse la méthode AVANT tout effet.
	 *
	 * @return void
	 */
	private static function refuser_methode(): void {
		status_header( 405 );
		wp_die(
			esc_html__( 'Méthode non autorisée.', 'urbizen-platform' ),
			'',
			array( 'response' => 405 )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function post(): array {
		return is_array( $_POST ) ? wp_unslash( $_POST ) : array();
	}

	/**
	 * @param array<string, mixed> $post   Données postées.
	 * @param string               $action Action.
	 * @return bool
	 */
	private static function nonce_valide( array $post, string $action ): bool {
		$nonce = isset( $post['_urbizen_nonce'] ) ? (string) $post['_urbizen_nonce'] : '';

		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Redirige en 303 vers la page de résultat.
	 *
	 * L'URL ne porte **ni adresse, ni jeton, ni motif technique** — un code
	 * court, et rien d'autre.
	 *
	 * @param string $code Code public.
	 * @return void
	 */
	private static function rediriger( string $code ): void {
		$code = in_array( $code, self::CODES, true ) ? $code : 'refus';

		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => self::ACTION_RESULTAT,
					'code'   => $code,
				),
				admin_url( 'admin-post.php' )
			),
			303
		);

		exit;
	}

	/**
	 * Sert la page de résultat.
	 *
	 * @param string $code Code public, déjà filtré.
	 * @return void
	 */
	private static function servir_resultat( string $code ): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$urbizen_code = $code;

		require URBIZEN_PLATFORM_DIR . 'templates/comptes/resultat.php';

		exit;
	}

	/**
	 * @return \Urbizen\Platform\Adapter\WpComptes
	 */
	private static function comptes(): WpComptes {
		return new WpComptes();
	}

	/**
	 * @return VerificationService
	 */
	private static function verification(): VerificationService {
		return new VerificationService( self::comptes(), new WpdbGateway() );
	}

	/**
	 * @return InscriptionService
	 */
	private static function inscription_service(): InscriptionService {
		return new InscriptionService( self::comptes(), self::verification() );
	}

	/**
	 * Orchestrateur d'émission.
	 *
	 * **Le seul endroit du domaine des comptes où un transport est construit.**
	 * Aucun contrôleur n'appelle `MailTransport::send()`.
	 *
	 * @return EnvoiVerification
	 */
	private static function envoi(): EnvoiVerification {
		return new EnvoiVerification(
			self::verification(),
			new WordPressMailTransport(),
			admin_url( 'admin-post.php' ),
			(string) get_bloginfo( 'name' )
		);
	}

	/**
	 * @return \Urbizen\Platform\Domain\Authorization\Authorization
	 */
	private static function autorisation() {
		return AutorisationComptes::porte( new WpCurrentUser() );
	}
}
