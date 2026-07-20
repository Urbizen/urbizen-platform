<?php
/**
 * Réception d'une demande de conception.
 *
 * Passe par `admin-post.php` plutôt que par une route REST : la soumission
 * comporte des pièces jointes (`multipart/form-data`) et doit fonctionner
 * **sans JavaScript**. Une route REST imposerait `fetch`, donc une dépendance
 * au navigateur pour un parcours qui doit rester robuste.
 *
 * L'ordre des contrôles n'est pas indifférent. Les refus les moins coûteux
 * viennent d'abord : inutile de charger une définition et de valider quarante
 * champs pour une requête qui n'a même pas de nonce. Les contrôles de sécurité
 * précèdent donc systématiquement le travail métier.
 *
 * En version B1, **aucun courriel n'est envoyé et aucun fichier n'est reçu**.
 * La demande est enregistrée, et c'est tout. Les notifications viendront en
 * PR B3, les pièces jointes en PR B2.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Pricing;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Contrôleur de soumission du formulaire de conception.
 */
final class SubmissionController {

	/**
	 * Action `admin-post`.
	 */
	public const ACTION = 'urbizen_conception';

	/**
	 * Action du nonce.
	 */
	public const NONCE_ACTION = 'urbizen_conception_submit';

	/**
	 * Nom du champ de nonce.
	 */
	public const NONCE_FIELD = 'urbizen_conception_nonce';

	/**
	 * Nom du champ pot de miel.
	 *
	 * Un nom plausible : un robot qui remplit tout ce qui ressemble à un
	 * formulaire d'entreprise le remplira. Une personne ne le verra pas — la
	 * dissimulation visuelle viendra avec l'interface, en PR C.
	 */
	public const HONEYPOT_FIELD = 'company_website';

	/**
	 * Nom du champ portant le jeton anti-robot.
	 */
	public const TOKEN_FIELD = 'urbizen_token';

	/**
	 * Nom du champ portant l'adresse de retour.
	 */
	public const RETURN_FIELD = 'urbizen_return';

	/**
	 * Identifiant du formulaire traité.
	 */
	public const FORM_TYPE = 'conception';

	/**
	 * Accroche les deux points d'entrée.
	 *
	 * `nopriv` sert les visiteurs, l'autre les personnes connectées : un client
	 * qui a un compte doit pouvoir soumettre comme les autres.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Point d'entrée HTTP.
	 *
	 * @return void
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- le nonce est vérifié dans process().
		$post   = wp_unslash( $_POST );
		$files  = isset( $_FILES ) ? (array) $_FILES : array();
		$server = isset( $_SERVER ) ? (array) $_SERVER : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = self::process( is_array( $post ) ? $post : array(), $files, $server );

		wp_safe_redirect( self::redirect_url( $result, is_array( $post ) ? $post : array() ) );
		exit;
	}

	/**
	 * Traite une soumission et renvoie son issue.
	 *
	 * Méthode sans effet de bord HTTP : elle ne redirige pas, ne termine pas le
	 * script, et reçoit ses superglobales en paramètre. C'est ce qui la rend
	 * intégralement testable.
	 *
	 * @param array<string, mixed> $post   Données postées, déjà déséchappées.
	 * @param array<string, mixed> $files  Fichiers reçus.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @param int|null             $now    Horodatage courant (tests).
	 * @return SubmissionResult
	 */
	public static function process( array $post, array $files, array $server, ?int $now = null ): SubmissionResult {
		// La journalisation appartient au traitement, pas au point d'entrée
		// HTTP : un appel direct à process() doit laisser la même trace qu'une
		// vraie requête, sans quoi un refus pourrait passer inaperçu.
		$result = self::evaluate( $post, $files, $server, null === $now ? time() : $now );

		self::log( $result );

		return $result;
	}

	/**
	 * Déroule les quatorze contrôles et renvoie l'issue, sans journaliser.
	 *
	 * @param array<string, mixed> $post   Données postées.
	 * @param array<string, mixed> $files  Fichiers reçus.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @param int                  $now    Horodatage courant.
	 * @return SubmissionResult
	 */
	private static function evaluate( array $post, array $files, array $server, int $now ): SubmissionResult {

		// --- 1 · méthode ---
		$methode = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : '';

		if ( 'POST' !== $methode ) {
			return SubmissionResult::failure( SubmissionResult::INVALID_METHOD );
		}

		// --- 2 · nonce ---
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return SubmissionResult::failure( SubmissionResult::INVALID_NONCE );
		}

		// --- 3 · pot de miel ---
		// Refus silencieux : ni journal détaillé, ni valeur consignée. On ne
		// conserve rien de ce qu'un robot a écrit.
		$miel = isset( $post[ self::HONEYPOT_FIELD ] ) ? trim( (string) $post[ self::HONEYPOT_FIELD ] ) : '';

		if ( '' !== $miel ) {
			return SubmissionResult::failure( SubmissionResult::SPAM_HONEYPOT );
		}

		// --- 4 · jeton : signature et dates ---
		$jeton   = isset( $post[ self::TOKEN_FIELD ] ) ? (string) $post[ self::TOKEN_FIELD ] : '';
		$verdict = AntiSpam::verify_token( $jeton, $now );

		if ( ! $verdict['ok'] ) {
			return SubmissionResult::failure( $verdict['code'] );
		}

		// --- 5 · réservation atomique du jeton ---
		// Réserver, et non « marquer plus tard » : entre un contrôle et une
		// écriture différée s'ouvre une fenêtre par laquelle deux requêtes
		// concurrentes passent toutes les deux. Ici, une seule peut réussir.
		if ( ! AntiSpam::reserve_token( $jeton, $now ) ) {
			return SubmissionResult::failure( SubmissionResult::DUPLICATE_SUBMISSION );
		}

		// --- 6 · réservation d'un créneau de débit ---
		$creneau = RateLimiter::reserve( self::FORM_TYPE, $server, $now );

		if ( null === $creneau ) {
			// Le quota est atteint : le jeton reste utilisable plus tard.
			AntiSpam::release_token( $jeton );

			return SubmissionResult::failure( SubmissionResult::RATE_LIMITED );
		}

		/**
		 * Abandonne le traitement en rendant ce qui a été réservé.
		 *
		 * Une erreur corrigible ne doit coûter ni le jeton, ni l'un des cinq
		 * créneaux horaires : la personne doit pouvoir rectifier et renvoyer.
		 *
		 * @param string                $code   Code interne.
		 * @param array<string, string> $errors Erreurs de validation.
		 * @return SubmissionResult
		 */
		$renoncer = static function ( string $code, array $errors = array() ) use ( $jeton, $creneau ): SubmissionResult {
			RateLimiter::release( $creneau );
			AntiSpam::release_token( $jeton );

			return SubmissionResult::failure( $code, $errors );
		};

		// --- 7 · fichiers : refusés jusqu'à la PR B2 ---
		if ( self::has_files( $files ) ) {
			return $renoncer( SubmissionResult::FILES_NOT_SUPPORTED_YET );
		}

		// --- 8 · définition ---
		$definition = FormRegistry::get( self::FORM_TYPE );

		if ( null === $definition || ! $definition->is_valid() ) {
			Logger::error( 'soumission : définition « ' . self::FORM_TYPE . ' » indisponible ou invalide' );

			return $renoncer( SubmissionResult::INVALID_FORM );
		}

		// --- 9 · validation ---
		$validation = Validator::validate( $definition, self::strip_technical_fields( $post ) );

		if ( ! $validation['valid'] ) {
			return $renoncer( SubmissionResult::VALIDATION_FAILED, $validation['errors'] );
		}

		// --- 10 · prix, recalculé côté serveur ---
		$pricing = $validation['pricing'];

		if ( ! is_array( $pricing ) || ! isset( $pricing['total'], $pricing['base'] ) ) {
			Logger::error( 'soumission : calcul tarifaire indisponible' );

			return $renoncer( SubmissionResult::PRICING_FAILED );
		}

		if ( (int) $pricing['base'] !== Pricing::BASE ) {
			Logger::error( 'soumission : prix de base incohérent avec le catalogue' );

			return $renoncer( SubmissionResult::PRICING_FAILED );
		}

		// --- 11 et 12 · référence et enregistrement, avant toute action externe ---
		$creation = SubmissionRepository::create(
			$validation['clean'],
			$pricing,
			array(
				'form_type'   => self::FORM_TYPE,
				'source_path' => self::source_path( $post, $server ),
				'now'         => $now,
			)
		);

		if ( empty( $creation['ok'] ) ) {
			return $renoncer( SubmissionResult::PERSISTENCE_FAILED );
		}

		// --- 13 · la demande existe : le jeton et le créneau sont acquis ---
		AntiSpam::consume_token( $jeton, $now );
		RateLimiter::confirm( $creneau, $now );

		// --- 14 · succès. Aucun courriel : ce sera la PR B3. ---
		return SubmissionResult::success( (string) $creation['reference'], (int) $creation['id'] );
	}

	/**
	 * La requête porte-t-elle au moins un fichier réellement transmis ?
	 *
	 * Un champ de dépôt laissé vide produit une entrée `$_FILES` avec le code
	 * `UPLOAD_ERR_NO_FILE` : ce n'est pas un fichier, et le refuser
	 * empêcherait toute soumission depuis un formulaire qui en déclare.
	 *
	 * @param array<string, mixed> $files Superglobale des fichiers.
	 * @return bool
	 */
	public static function has_files( array $files ): bool {
		foreach ( $files as $champ ) {
			if ( ! is_array( $champ ) || ! isset( $champ['error'] ) ) {
				continue;
			}

			foreach ( (array) $champ['error'] as $erreur ) {
				if ( UPLOAD_ERR_NO_FILE !== (int) $erreur ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Retire les champs techniques avant validation.
	 *
	 * Le nonce, le pot de miel, le jeton et l'adresse de retour ne sont pas des
	 * données de dossier. Le validateur les écarterait de toute façon comme
	 * champs inconnus, mais les retirer ici évite qu'ils figurent un jour dans
	 * une liste de champs ignorés, donc dans un journal.
	 *
	 * @param array<string, mixed> $post Données postées.
	 * @return array<string, mixed>
	 */
	private static function strip_technical_fields( array $post ): array {
		unset(
			$post[ self::NONCE_FIELD ],
			$post[ self::HONEYPOT_FIELD ],
			$post[ self::TOKEN_FIELD ],
			$post[ self::RETURN_FIELD ],
			$post['action'],
			$post['_wp_http_referer']
		);

		return $post;
	}

	/**
	 * Chemin local d'origine de la demande.
	 *
	 * Seul le chemin est conservé : ni domaine, ni paramètres, ni fragment, ni
	 * marqueur de campagne. Un `Referer` complet est une donnée de navigation,
	 * pas une donnée de dossier.
	 *
	 * @param array<string, mixed> $post   Données postées.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @return string
	 */
	public static function source_path( array $post, array $server ): string {
		$candidats = array();

		if ( isset( $post[ self::RETURN_FIELD ] ) && is_string( $post[ self::RETURN_FIELD ] ) ) {
			$candidats[] = $post[ self::RETURN_FIELD ];
		}

		if ( isset( $server['HTTP_REFERER'] ) && is_string( $server['HTTP_REFERER'] ) ) {
			$candidats[] = $server['HTTP_REFERER'];
		}

		foreach ( $candidats as $candidat ) {
			if ( ! self::is_same_site( $candidat ) ) {
				continue;
			}

			$chemin = (string) wp_parse_url( $candidat, PHP_URL_PATH );

			if ( '' === $chemin ) {
				continue;
			}

			// Longueur bornée : une métadonnée n'a pas à porter un chemin
			// arbitrairement long fabriqué par un tiers.
			return substr( $chemin, 0, 200 );
		}

		return '';
	}

	/**
	 * Une adresse appartient-elle au site ?
	 *
	 * @param string $url Adresse candidate.
	 * @return bool
	 */
	public static function is_same_site( string $url ): bool {
		$url = trim( $url );

		if ( '' === $url ) {
			return false;
		}

		// Une adresse relative commençant par « / » — mais pas « // », qui est
		// un raccourci de protocole vers un domaine étranger.
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}

		$hote = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $hote ) {
			return false;
		}

		return strtolower( (string) $hote ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Destination de redirection.
	 *
	 * L'adresse ne porte que l'issue et, en cas de succès, la référence. Ni
	 * nom, ni adresse électronique, ni téléphone, ni message, ni nature du
	 * projet, ni prix, ni détail d'erreur : une adresse se retrouve dans
	 * l'historique du navigateur, dans les journaux du serveur et dans le
	 * `Referer` envoyé au site suivant.
	 *
	 * @param SubmissionResult     $result Issue.
	 * @param array<string, mixed> $post   Données postées.
	 * @return string
	 */
	public static function redirect_url( SubmissionResult $result, array $post ): string {
		$base = '';

		if ( isset( $post[ self::RETURN_FIELD ] ) && is_string( $post[ self::RETURN_FIELD ] )
			&& self::is_same_site( $post[ self::RETURN_FIELD ] ) ) {
			$base = $post[ self::RETURN_FIELD ];
		}

		if ( '' === $base ) {
			$referer = wp_get_referer();

			if ( is_string( $referer ) && '' !== $referer && self::is_same_site( $referer ) ) {
				$base = $referer;
			}
		}

		if ( '' === $base ) {
			$base = home_url( '/' );
		}

		$args = array( 'urbizen_submission' => $result->is_success() ? 'success' : 'error' );

		if ( $result->is_success() ) {
			$args['reference'] = $result->reference();
		}

		return add_query_arg( $args, remove_query_arg( array( 'urbizen_submission', 'reference' ), $base ) );
	}

	/**
	 * Journalise une issue, sans aucune donnée personnelle.
	 *
	 * @param SubmissionResult $result Issue.
	 * @return void
	 */
	private static function log( SubmissionResult $result ): void {
		if ( $result->is_success() ) {
			Logger::info(
				sprintf(
					'soumission %s : %s (#%d)',
					self::FORM_TYPE,
					$result->reference(),
					$result->id()
				)
			);

			return;
		}

		// Seuls le type de formulaire, le code interne et le **nombre** de
		// champs fautifs sont consignés. Jamais leur nom, jamais leur valeur.
		Logger::info(
			sprintf(
				'soumission %s refusée : %s (%d champ(s) en erreur)',
				self::FORM_TYPE,
				$result->code(),
				count( $result->errors() )
			)
		);
	}
}
