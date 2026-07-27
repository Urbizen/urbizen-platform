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

use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Files\UploadProfileRegistry;
use Urbizen\Platform\Files\UploadManifest;
use Urbizen\Platform\Files\UploadNormalizer;
use Urbizen\Platform\Files\UploadPolicy;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\PricingStrategyRegistry;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;
use Urbizen\Platform\Support\PhpLimits;

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
	 * Identifiant du formulaire traité par l'action historique {@see self::ACTION}.
	 * Conservé pour compatibilité et comme valeur de la table de routage.
	 */
	public const FORM_TYPE = 'conception';

	/**
	 * Configuration serveur des routes : action → { type de formulaire, action de
	 * nonce }. Résolue EXCLUSIVEMENT côté serveur (la clé est l'action du hook) ;
	 * le navigateur ne choisit jamais la route. Un champ POST ne sert qu'à un
	 * contrôle de cohérence, après que la route serveur a déjà été choisie. Une
	 * seule route réelle aujourd'hui ; DP/PC/CERFA ne sont pas anticipés.
	 * Décision : docs/DECISIONS.md D-050 (B).
	 *
	 * @var array<string, array{form_type: string, nonce_action: string}>
	 */
	private const ROUTES = array(
		self::ACTION => array(
			'form_type'    => self::FORM_TYPE,
			'nonce_action' => self::NONCE_ACTION,
		),
	);

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

		// La route est choisie par le HOOK, via une valeur LITTÉRALE liée à
		// l'action enregistrée ({@see self::register()}) — jamais depuis $_POST.
		$result = self::process(
			is_array( $post ) ? $post : array(),
			$files,
			$server,
			null,
			self::route_for_action( self::ACTION )
		);

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
	public static function process( array $post, array $files, array $server, ?int $now = null, ?array $route = null ): SubmissionResult {
		// La ROUTE provient du hook (paramètre) ou, à défaut — appel direct des
		// bancs d'essai —, de l'action historique. Jamais d'une valeur de requête.
		$resolved = null === $route ? self::route_for_action( self::ACTION ) : self::sanitize_route( $route );

		if ( null === $resolved ) {
			$result = SubmissionResult::failure( SubmissionResult::INVALID_FORM );
			self::log( $result, isset( $route['form_type'] ) ? (string) $route['form_type'] : self::FORM_TYPE );

			return $result;
		}

		// La journalisation appartient au traitement, pas au point d'entrée
		// HTTP : un appel direct à process() doit laisser la même trace qu'une
		// vraie requête, sans quoi un refus pourrait passer inaperçu.
		$result = self::evaluate( $post, $files, $server, null === $now ? time() : $now, $resolved );

		self::log( $result, $resolved['form_type'] );

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
	private static function evaluate( array $post, array $files, array $server, int $now, array $route ): SubmissionResult {

		// Type de formulaire : issu de la ROUTE serveur déjà résolue, jamais du POST.
		$type = $route['form_type'];

		// --- 1 · méthode ---
		$methode = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : '';

		if ( 'POST' !== $methode ) {
			return SubmissionResult::failure( SubmissionResult::INVALID_METHOD );
		}

		// --- 1 bis · corps écarté par PHP ---
		// Un corps dépassant `post_max_size` est vidé par PHP avant que ce code
		// ne s'exécute. Sans ce contrôle, la requête se présenterait comme
		// dépourvue de nonce, et le visiteur recevrait un refus de sécurité
		// pour un fichier simplement trop lourd.
		if ( PhpLimits::body_rejected( $post, $files, $server ) ) {
			Logger::info( 'soumission conception refusée : request_too_large' );

			return SubmissionResult::failure( SubmissionResult::REQUEST_TOO_LARGE );
		}

		// --- 2 · nonce ---
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? (string) $post[ self::NONCE_FIELD ] : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $route['nonce_action'] ) ) {
			return SubmissionResult::failure( SubmissionResult::INVALID_NONCE );
		}

		// --- 2 bis · cohérence (la ROUTE, déjà choisie côté serveur, fait foi) ---
		// Un « action » ou « form_type » présent dans le POST ne peut que
		// CONFIRMER la route serveur ; s'il la contredit, la requête est
		// trafiquée → refus AVANT toute réservation ou écriture.
		if ( ( isset( $post['action'] ) && (string) $post['action'] !== $route['action'] )
			|| ( isset( $post['form_type'] ) && (string) $post['form_type'] !== $type ) ) {
			return SubmissionResult::failure( SubmissionResult::INVALID_FORM );
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
		$creneau = RateLimiter::reserve( $type, $server, $now );

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

		// --- 7 · définition ---
		$definition = FormRegistry::get( $type );

		if ( null === $definition || ! $definition->is_valid() ) {
			Logger::error( 'soumission : définition « ' . $type . ' » indisponible ou invalide' );

			return $renoncer( SubmissionResult::INVALID_FORM );
		}

		// --- 8 · validation ---
		$validation = Validator::validate( $definition, self::strip_technical_fields( $post ) );

		if ( ! $validation['valid'] ) {
			return $renoncer( SubmissionResult::VALIDATION_FAILED, $validation['errors'] );
		}

		// --- 9 · prix, recalculé côté serveur ---
		$pricing = $validation['pricing'];

		if ( ! is_array( $pricing ) || ! isset( $pricing['total'], $pricing['base'] ) ) {
			Logger::error( 'soumission : calcul tarifaire indisponible' );

			return $renoncer( SubmissionResult::PRICING_FAILED );
		}

		// La stratégie tarifaire est résolue depuis le TYPE serveur, jamais depuis
		// $_POST. Le prix persisté doit reposer sur le socle de cette stratégie —
		// un type sans stratégie, ou un socle divergent, est refusé.
		$strategie_prix = PricingStrategyRegistry::for_type( $type );

		if ( null === $strategie_prix || (int) $pricing['base'] !== $strategie_prix->base() ) {
			Logger::error( 'soumission : prix de base incohérent avec la stratégie du type' );

			return $renoncer( SubmissionResult::PRICING_FAILED );
		}

		// --- 10 · documents : profil serveur, puis normalisation pilotée par lui ---
		// Le profil d'upload est résolu depuis le TYPE serveur (issu de la route),
		// jamais depuis $_POST/$_FILES : le navigateur transmet des fichiers, jamais
		// le profil qui les juge. Le moteur ne suppose JAMAIS Conception par défaut ;
		// la résolution précède toute décision sur les blocs acceptables.
		$profil = UploadProfileRegistry::for_type( $type );

		if ( null === $profil || ! $profil->uploads_enabled ) {
			// Ce type n'admet aucun document. On ne normalise pas contre une liste
			// Conception : un vrai fichier reçu est un refus contrôlé, sinon la
			// soumission continue sans document. (Aujourd'hui inatteignable — seule
			// la route Conception existe — mais la garde interdit tout repli.)
			if ( self::contient_fichiers( $files ) ) {
				return $renoncer( SubmissionResult::UPLOAD_NOT_ALLOWED );
			}

			$normalisation = array(
				'ok'      => true,
				'code'    => 'success',
				'files'   => array(),
				'ignored' => array(),
			);
		} else {
			$normalisation = UploadNormalizer::normalize( $files, $profil );

			if ( ! $normalisation['ok'] ) {
				return $renoncer( $normalisation['code'] );
			}
		}

		// --- 10 bis · manifeste : détecter une réception partielle ---
		// `max_file_uploads` plafonne à 20 : au-delà, PHP livre une partie des
		// fichiers sans le signaler. Le serveur ne peut pas connaître un
		// fichier qui ne lui est jamais parvenu ; seule la déclaration
		// préalable du navigateur permet de constater l'écart (D-032).
		//
		// Le contrôle vient **après** la normalisation — les tailles comparées
		// sont mesurées sur les fichiers réellement reçus — et **avant** tout
		// dépôt : un refus ne laisse ni staging, ni référence, ni notification.
		// Il vient aussi après les barrières de `UploadPolicy`, pour que le
		// motif rendu reste le plus précis possible ; il ne les remplace pas.
		$verifier_manifeste = static function () use ( $post, $normalisation, $renoncer ) {
			$manifeste = UploadManifest::verify(
				$post[ UploadManifest::FIELD ] ?? null,
				$normalisation['files']
			);

			return $manifeste['ok'] ? null : $renoncer( $manifeste['code'] );
		};

		$lot     = array();
		$staging = null;

		if ( array() === $normalisation['files'] ) {
			// Aucun fichier reçu : c'est précisément le cas où un manifeste
			// annonçant des documents doit être refusé.
			$refus = $verifier_manifeste();

			if ( null !== $refus ) {
				return $refus;
			}
		}

		if ( array() !== $normalisation['files'] ) {
			// $profil est ici garanti non nul et ouvert : un profil absent ou fermé
			// aurait produit une liste de fichiers vide ci-dessus. La validation
			// s'appuie donc sur le profil serveur explicite, jamais sur un défaut.
			$politique = UploadPolicy::validate( $normalisation['files'], $profil );

			if ( ! $politique['ok'] ) {
				return $renoncer( $politique['code'] );
			}

			$refus = $verifier_manifeste();

			if ( null !== $refus ) {
				return $refus;
			}

			// --- 11 · dépôt dans un staging privé ---
			// Les documents quittent le répertoire temporaire de PHP avant que
			// quoi que ce soit ne soit écrit en base : si la suite échoue, il
			// n'y a qu'un répertoire à effacer.
			$staging = Storage::open_staging();

			if ( null === $staging ) {
				return $renoncer( SubmissionResult::STORAGE_UNAVAILABLE );
			}

			foreach ( $politique['files'] as $rang => $doc ) {
				$depose = Storage::stage( $staging, $doc, (int) $rang );

				if ( null === $depose ) {
					Storage::discard_staging( $staging );

					return $renoncer( SubmissionResult::STORAGE_FAILED );
				}

				$lot[] = $depose;
			}
		}

		/**
		 * Abandonne le traitement en rendant tout ce qui a été réservé.
		 *
		 * @param string $code      Code interne.
		 * @param int    $id        Demande partielle, ou 0.
		 * @param string $reference Référence réservée, ou chaîne vide.
		 * @param array  $deposes   Documents déjà finalisés.
		 * @return SubmissionResult
		 */
		$abandonner = static function ( string $code, int $id = 0, string $reference = '', array $deposes = array() ) use ( $renoncer, $staging ): SubmissionResult {
			Storage::rollback( $deposes );
			Storage::discard_staging( $staging );

			if ( $id > 0 || '' !== $reference ) {
				SubmissionRepository::discard( $id, $reference );
			}

			return $renoncer( $code );
		};

		// --- 12 · demande créée, mais pas encore finalisée ---
		// La référence reste réservée, jamais attribuée : tant que les
		// documents ne sont pas en place, la demande n'existe pas vraiment.
		$creation = SubmissionRepository::create(
			$validation['clean'],
			$pricing,
			array(
				'form_type'    => $type,
				'source_path'  => self::source_path( $post, $server ),
				'now'          => $now,
				'files_status' => array() === $lot ? 'none' : 'pending',
				'finalize'     => false,
				// État durable : si le processus est tué après ce point, une
				// requête ultérieure saura retrouver ce qu'il faut nettoyer.
				'transaction'  => Storage::random_id(),
				'staging'      => null === $staging ? '' : $staging,
			)
		);

		if ( empty( $creation['ok'] ) ) {
			return $abandonner( SubmissionResult::PERSISTENCE_FAILED );
		}

		$id        = (int) $creation['id'];
		$reference = (string) $creation['reference'];

		// --- 13 · documents déplacés sous la référence ---
		$metadonnees = array();

		if ( array() !== $lot ) {
			$metadonnees = Storage::finalize( $staging, $reference, $lot, $now );

			if ( null === $metadonnees ) {
				return $abandonner( SubmissionResult::STORAGE_FAILED, $id, $reference );
			}

			if ( ! SubmissionRepository::set_files( $id, $metadonnees ) ) {
				return $abandonner(
					SubmissionResult::FILE_METADATA_FAILED,
					$id,
					$reference,
					array_map( static fn( $m ) => (string) Storage::resolve( (string) $m['relative_path'] ), $metadonnees )
				);
			}
		}

		Storage::discard_staging( $staging );

		// --- 14 · finalisation : la référence est attribuée pour de bon ---
		if ( ! SubmissionRepository::finalize( $id, $reference, array() === $lot ? 'none' : 'stored', $now ) ) {
			return $abandonner(
				SubmissionResult::PERSISTENCE_FAILED,
				$id,
				$reference,
				array_map( static fn( $m ) => (string) Storage::resolve( (string) $m['relative_path'] ), $metadonnees )
			);
		}

		// --- 15 · la demande existe : le jeton et le créneau sont acquis ---
		AntiSpam::consume_token( $jeton, $now );
		RateLimiter::confirm( $creneau, $now );

		if ( array() !== $metadonnees ) {
			// Journal : décomptes et identifiants techniques seulement.
			Logger::info(
				sprintf(
					'demande %s : %d document(s), %d octets',
					$reference,
					count( $metadonnees ),
					array_sum( array_column( $metadonnees, 'size' ) )
				)
			);
		}

		// --- 16 · succès. Aucun courriel : ce sera la PR B3. ---
		return SubmissionResult::success( $reference, $id );
	}

	/**
	 * `$_FILES` contient-il au moins un fichier réellement téléversé ?
	 *
	 * Contrôle **générique**, indépendant de tout profil ou bloc : il ne sert
	 * qu'à distinguer, pour un type sans profil d'upload, une soumission sans
	 * document (admise) d'une tentative de dépôt (refusée). Un champ laissé vide
	 * (`UPLOAD_ERR_NO_FILE`) ne compte pas.
	 *
	 * @param array<string, mixed> $files Superglobale des fichiers.
	 * @return bool
	 */
	private static function contient_fichiers( array $files ): bool {
		foreach ( $files as $entree ) {
			if ( ! is_array( $entree ) || ! isset( $entree['error'] ) ) {
				continue;
			}

			$erreurs = is_array( $entree['error'] ) ? $entree['error'] : array( $entree['error'] );

			foreach ( $erreurs as $erreur ) {
				if ( is_numeric( $erreur ) && UPLOAD_ERR_NO_FILE !== (int) $erreur ) {
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
			$post['form_type'],
			$post['_wp_http_referer']
		);

		return $post;
	}

	/**
	 * Route serveur associée à une action, depuis la table {@see self::ROUTES}.
	 * Jamais construite à partir d'une valeur de requête : l'action passée est
	 * une valeur LITTÉRALE liée au hook.
	 *
	 * @param string $action Nom de l'action `admin-post`.
	 * @return array{action: string, form_type: string, nonce_action: string}|null
	 */
	private static function route_for_action( string $action ): ?array {
		$route = self::ROUTES[ $action ] ?? null;

		return is_array( $route ) ? self::sanitize_route( array( 'action' => $action ) + $route ) : null;
	}

	/**
	 * Valide une route explicite : structure complète (action, type, nonce) et
	 * type présent dans la liste blanche du registre. Sert à la résolution
	 * serveur et aux routes fictives des bancs d'essai. Renvoie une route
	 * normalisée, ou null si elle est incomplète ou non autorisée.
	 *
	 * @param array<string, mixed> $route Route candidate.
	 * @return array{action: string, form_type: string, nonce_action: string}|null
	 */
	private static function sanitize_route( array $route ): ?array {
		$action = isset( $route['action'] ) ? (string) $route['action'] : '';
		$type   = isset( $route['form_type'] ) ? (string) $route['form_type'] : '';
		$nonce  = isset( $route['nonce_action'] ) ? (string) $route['nonce_action'] : '';

		if ( '' === $action || '' === $nonce || ! FormRegistry::has( $type ) ) {
			return null;
		}

		return array(
			'action'       => $action,
			'form_type'    => $type,
			'nonce_action' => $nonce,
		);
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
	 * @param string           $type   Type de formulaire (issu de la route serveur).
	 * @return void
	 */
	private static function log( SubmissionResult $result, string $type ): void {
		if ( $result->is_success() ) {
			Logger::info(
				sprintf(
					'soumission %s : %s (#%d)',
					$type,
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
				$type,
				$result->code(),
				count( $result->errors() )
			)
		);
	}
}
