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
use Urbizen\Platform\Forms\PricingStrategyContextuelle;
use Urbizen\Platform\Forms\AdresseTerrain;
use Urbizen\Platform\Forms\MatriceChamps;
use Urbizen\Platform\Forms\ValidationMetierRegistry;
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
	 * Action `admin-post` de la déclaration préalable.
	 */
	public const ACTION_DP = 'urbizen_declaration_prealable';

	/**
	 * Action du nonce de la déclaration préalable.
	 */
	public const NONCE_ACTION_DP = 'urbizen_declaration_prealable_submit';

	/**
	 * Identifiant serveur du formulaire de déclaration préalable.
	 */
	public const FORM_TYPE_DP = 'declaration_prealable';

	/**
	 * Action `admin-post` du permis de construire.
	 */
	public const ACTION_PC = 'urbizen_permis_construire';

	/**
	 * Action du nonce du permis de construire.
	 *
	 * **Distincte de celle de la DP**, et pas par symétrie : un nonce est lié à
	 * son action. Les partager laisserait un nonce émis pour une DP autoriser
	 * l'envoi d'un permis de construire — deux parcours, deux barèmes, deux
	 * profils de dépôt.
	 */
	public const NONCE_ACTION_PC = 'urbizen_permis_construire_submit';

	/**
	 * Identifiant serveur du formulaire de permis de construire.
	 */
	public const FORM_TYPE_PC = 'permis_construire';

	/**
	 * Configuration serveur des routes : action → { type de formulaire, action de
	 * nonce }. Résolue EXCLUSIVEMENT côté serveur (la clé est l'action du hook) ;
	 * le navigateur ne choisit jamais la route. Un champ POST ne sert qu'à un
	 * contrôle de cohérence, après que la route serveur a déjà été choisie. Une
	 * route par parcours livré — Conception, déclaration préalable, permis de
	 * construire. Le CERFA n'est pas anticipé.
	 * Décision : docs/DECISIONS.md D-050 (B).
	 *
	 * @var array<string, array{form_type: string, nonce_action: string}>
	 */
	private const ROUTES = array(
		self::ACTION    => array(
			'form_type'    => self::FORM_TYPE,
			'nonce_action' => self::NONCE_ACTION,
		),
		self::ACTION_DP => array(
			'form_type'    => self::FORM_TYPE_DP,
			'nonce_action' => self::NONCE_ACTION_DP,
		),
		self::ACTION_PC => array(
			'form_type'    => self::FORM_TYPE_PC,
			'nonce_action' => self::NONCE_ACTION_PC,
		),
	);

	/**
	 * Accroche les points d'entrée de chaque route.
	 *
	 * `nopriv` sert les visiteurs, l'autre les personnes connectées : un client
	 * qui a un compte doit pouvoir soumettre comme les autres.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( array_keys( self::ROUTES ) as $action ) {
			// Le gestionnaire est lié à l'action : c'est le HOOK qui portera la
			// route, jamais une valeur de requête. Une closure par action évite
			// de relire $_POST pour savoir quel formulaire répond.
			$gestionnaire = static function () use ( $action ): void {
				self::handle( (string) $action );
			};

			add_action( 'admin_post_nopriv_' . $action, $gestionnaire );
			add_action( 'admin_post_' . $action, $gestionnaire );
		}
	}

	/**
	 * Point d'entrée HTTP.
	 *
	 * @return void
	 */
	public static function handle( string $action = self::ACTION ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- le nonce est vérifié dans process().
		$post   = wp_unslash( $_POST );
		$files  = isset( $_FILES ) ? (array) $_FILES : array();
		$server = isset( $_SERVER ) ? (array) $_SERVER : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// La route est choisie par le HOOK, via une valeur LITTÉRALE liée à
		// l'action enregistrée ({@see self::register()}) — jamais depuis $_POST.
		$route  = self::route_for_action( $action );
		$result = self::process(
			is_array( $post ) ? $post : array(),
			$files,
			$server,
			null,
			$route
		);

		// Le type porté par le retour provient de la ROUTE serveur, jamais du POST.
		$type = null === $route ? self::FORM_TYPE : $route['form_type'];

		// Négociation de contenu : le MÊME traitement vient de s'exécuter, avec
		// les mêmes contrôles. Seule la forme de la réponse change. L'en-tête ne
		// prouve rien et n'autorise rien — un client qui le forge obtient du JSON,
		// pas un passe-droit.
		if ( AcceptNegotiation::veut_json( $server ) ) {
			self::repondre_json( $result );

			return;
		}

		wp_safe_redirect( self::redirect_url( $result, is_array( $post ) ? $post : array(), $type ) );
		exit;
	}

	/**
	 * Émet la réponse JSON d'une soumission déjà traitée.
	 *
	 * Ne décide de rien : elle met en forme une issue produite par le pipeline.
	 *
	 * @param SubmissionResult $result Issue du traitement.
	 * @return void
	 */
	private static function repondre_json( SubmissionResult $result ): void {
		if ( $result->is_success() ) {
			wp_send_json( SubmissionJsonResponse::succes( $result ), 201 );

			return;
		}

		$categorie = self::categorie_publique( $result->code() );

		wp_send_json(
			SubmissionJsonResponse::echec( $categorie, $result->errors() ),
			SubmissionJsonResponse::statut_http( $categorie )
		);
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
			// Rejet CORRIGEABLE : la route, le nonce, l'anti-robot, la limitation de
			// débit et la définition ont tous passé, et AUCUNE demande n'est
			// persistée. On conserve une reprise serveur (valeurs nettoyées + erreurs
			// publiques, jamais de fichier ni de POST brut) pour que la personne
			// retrouve sa saisie après correction. Le dépôt est court, opaque et à
			// usage unique ; s'il échoue, l'identifiant est vide et aucune reprise
			// n'est proposée — le rejet reste un rejet.
			$reprise    = SubmissionRecovery::from_validation( $type, $definition, $validation['clean'], $validation['errors'] );
			$reprise_id = SubmissionRecoveryStore::store( $reprise );

			return $renoncer( SubmissionResult::VALIDATION_FAILED, $validation['errors'] )->with_recovery( $reprise_id );
		}

		// --- 8 bis · les adresses, dans l'ordre où elles se décident ---
		// L'ordre n'est pas indifférent. Le mode du déclarant tranche AVANT tout
		// le reste : une charge portant à la fois ses champs automatiques et ses
		// champs manuels ne doit jamais laisser le mode inactif alimenter la
		// copie faite plus bas.
		$ecartes = array();

		$declarant = AdresseTerrain::pour( AdresseTerrain::DECLARANT );
		$terrain   = AdresseTerrain::pour( AdresseTerrain::TERRAIN );

		$validation['clean'] = $declarant->filtrer( $validation['clean'], $ecartes );

		// L'adresse du déclarant est jugée seule, et son échec s'arrête ici. En
		// laissant la suite se dérouler, le terrain — pas encore reconstruit —
		// signalerait les mêmes manques sous d'autres noms : la personne lirait
		// deux fois la même erreur sans savoir laquelle corriger.
		//
		// C'est la définition, et non la charge, qui dit si le parcours pose une
		// adresse de déclarant : une charge forgée peut retirer le mode, pas
		// changer ce que le formulaire déclare.
		$erreurs_adresse = $declarant->verifier(
			$validation['clean'],
			null !== $definition->field( $declarant->nom( 'mode' ) )
		);

		if ( array() !== $erreurs_adresse ) {
			$reprise    = SubmissionRecovery::from_validation( $type, $definition, $validation['clean'], $erreurs_adresse );
			$reprise_id = SubmissionRecoveryStore::store( $reprise );

			return $renoncer( SubmissionResult::VALIDATION_FAILED, $erreurs_adresse )->with_recovery( $reprise_id );
		}

		// Case cochée : le terrain reçu est intégralement écarté, puis
		// reconstruit depuis le déclarant qui vient d'être validé. Purger
		// d'abord et recopier ensuite est ce qui garantit qu'aucune valeur
		// forgée ne se mêle à la copie — le navigateur n'envoie rien qui
		// survive à cette étape.
		// La décision est d'abord mise au clair : une case décochée laisse une
		// liste vide dans la charge, qui ne dit rien et qu'un futur lecteur
		// devrait réinterpréter. Après ceci, la clé porte « oui » ou n'existe
		// pas.
		$validation['clean'] = AdresseTerrain::normaliser_report( $validation['clean'] );

		if ( AdresseTerrain::reportee( $validation['clean'] ) ) {
			$reporte             = $declarant->exporter( $validation['clean'] );
			$validation['clean'] = $terrain->purger( $validation['clean'], $ecartes );
			$validation['clean'] = $terrain->importer( $validation['clean'], $reporte );
		}

		// --- 8 ter · cohérence métier ---
		// La définition a jugé chaque champ isolément ; elle ne peut rien dire de
		// ce qui les lie. Un doublon de projet, un supplément identique au projet
		// principal ou une liste forgée passeraient la validation de forme, et le
		// catalogue tarifaire se contenterait de ne pas les facturer — la demande
		// serait acceptée avec un contenu incohérent. Le refus intervient donc ici,
		// AVANT tout calcul et toute écriture, et reste corrigeable.
		$metier = ValidationMetierRegistry::for_type( $type );

		if ( null !== $metier ) {
			$erreurs_metier = $metier->valider( $validation['clean'] );

			if ( array() !== $erreurs_metier ) {
				$reprise    = SubmissionRecovery::from_validation( $type, $definition, $validation['clean'], $erreurs_metier );
				$reprise_id = SubmissionRecoveryStore::store( $reprise );

				return $renoncer( SubmissionResult::VALIDATION_FAILED, $erreurs_metier )->with_recovery( $reprise_id );
			}
		}

		// --- 8 quater · champs que la nature ne justifie pas ---
		// La définition a jugé chaque champ, la cohérence métier les a jugés
		// ensemble ; reste ce qu'aucune des deux ne voit — un champ parfaitement
		// valide mais sans objet pour ce projet. Une surface de plancher sur une
		// piscine n'est pas une donnée inutile, c'est une donnée FAUSSE : elle
		// finirait dans le CERFA.
		//
		// L'écart est retiré, pas refusé. C'est le plus souvent le reliquat d'une
		// nature changée en cours de saisie, et faire échouer la demande pour
		// cela serait disproportionné. Le masquage côté navigateur reste une
		// politesse ; ce filtrage-ci est la règle.
		$validation['clean'] = MatriceChamps::filtrer( $type, $validation['clean'], $ecartes );

		// --- 8 quinquies · l'adresse du mode inactif ---
		// Une demande ne porte qu'une adresse par rôle. Le navigateur désactive
		// les contrôles du mode abandonné, donc ils ne partent pas ; mais une
		// charge forgée les enverrait tous, et deux adresses contradictoires
		// arriveraient dans le même dossier. Le mode retenu tranche, ici, avant
		// toute persistance. Le déclarant a déjà été filtré plus haut, avant de
		// servir de source à la recopie.
		$validation['clean'] = $terrain->filtrer( $validation['clean'], $ecartes );

		if ( array() !== $ecartes ) {
			Logger::info(
				sprintf(
					'soumission %s : %d champ(s) sans objet pour la nature ou le mode déclaré, écarté(s) : %s',
					$type,
					count( $ecartes ),
					implode( ', ', $ecartes )
				)
			);
		}

		// --- 9 · prix, recalculé côté serveur ---
		$pricing = $validation['pricing'];

		// `array_key_exists` et non `isset` : un socle et un total volontairement
		// non chiffrés valent `null`, et doivent se distinguer d'un calcul qui
		// n'a rien produit. Confondre les deux refuserait tout dossier sur étude.
		if ( ! is_array( $pricing ) || ! array_key_exists( 'total', $pricing ) || ! array_key_exists( 'base', $pricing ) ) {
			Logger::error( 'soumission : calcul tarifaire indisponible' );

			return $renoncer( SubmissionResult::PRICING_FAILED );
		}

		// La stratégie tarifaire est résolue depuis le TYPE serveur, jamais depuis
		// $_POST. Le prix persisté doit reposer sur le socle de cette stratégie —
		// un type sans stratégie, ou un socle divergent, est refusé.
		$strategie_prix = PricingStrategyRegistry::for_type( $type );

		// Un socle unique ne vaut que pour les stratégies à tarif fixe. Celles
		// dont le socle dépend des réponses répondent elles-mêmes de la valeur
		// calculée : la garde reste entière, elle change d'interlocuteur.
		// `null` est transmis tel quel à la stratégie : c'est un socle sur étude,
		// et seule une stratégie qui en produit réellement doit l'accepter. Le
		// convertir en `0` par un transtypage ferait passer un tarif absent pour
		// un tarif nul, que le catalogue Conception accepterait à tort.
		$socle = null === $pricing['base'] ? null : (int) $pricing['base'];

		$socle_incoherent = null === $strategie_prix
			|| ( $strategie_prix instanceof PricingStrategyContextuelle
				? ! $strategie_prix->accepts_base( $socle )
				: null === $socle || $socle !== $strategie_prix->base() );

		if ( $socle_incoherent ) {
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
	 * L'adresse ne porte aucune donnée personnelle : ni nom, ni adresse
	 * électronique, ni téléphone, ni message, ni nature du projet, ni prix, ni
	 * erreur par champ — une adresse se retrouve dans l'historique du navigateur,
	 * dans les journaux du serveur et dans le `Referer` envoyé au site suivant.
	 *
	 * **Une seule source de résultat, signée.** L'adresse ne porte que
	 * `urbizen_feedback` : un **jeton signé par le serveur**
	 * ({@see SubmissionFeedbackToken}), seule source d'une confirmation fiable
	 * qu'une URL forgée ne peut pas produire. Les paramètres historiques
	 * falsifiables — `urbizen_submission`, `reference`, `error` — ne sont **plus
	 * émis**, et sont **retirés** de l'adresse cible s'ils y traînaient : aucune
	 * ancienne valeur ne survit à une redirection, et le navigateur n'a plus de
	 * seconde source de vérité fondée sur l'URL. La **référence** n'existe que
	 * dans le jeton signé.
	 *
	 * @param SubmissionResult     $result    Issue.
	 * @param array<string, mixed> $post      Données postées.
	 * @param string               $form_type Type serveur de la route (jamais du POST).
	 * @return string
	 */
	public static function redirect_url( SubmissionResult $result, array $post, string $form_type = self::FORM_TYPE ): string {
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

		$reprise = self::recovery_pour( $result );

		$feedback = $result->is_success()
			? SubmissionFeedback::succes( $form_type, $result->reference() )
			: SubmissionFeedback::erreur( $form_type, self::categorie_publique( $result->code() ), $reprise );

		$jeton = SubmissionFeedbackToken::issue( $feedback );

		// Toujours purger les paramètres historiques falsifiables de la cible : ni
		// `urbizen_submission`, ni `reference`, ni `error` ne survivent, même
		// présents dans l'URL de retour.
		$base = remove_query_arg( array( 'urbizen_submission', 'reference', 'error', SubmissionResultNotice::CHAMP ), $base );

		// Émission au mieux : si l'encodage du jeton échoue, on redirige SANS
		// jeton (aucune confirmation affichée), jamais avec un jeton malformé. Une
		// demande déjà persistée n'est jamais transformée en faux échec pour autant.
		if ( '' === $jeton ) {
			// Sans jeton signé, l'identifiant de reprise ne pourrait plus être
			// transporté : on supprime le dépôt pour ne laisser aucun orphelin
			// évitable. L'identifiant brut n'est jamais placé hors du jeton signé.
			if ( null !== $reprise ) {
				SubmissionRecoveryStore::delete( $reprise );
			}

			return $base;
		}

		return add_query_arg( array( SubmissionResultNotice::CHAMP => $jeton ), $base );
	}

	/**
	 * Identifiant de reprise à transporter, ou null.
	 *
	 * Une reprise n'existe que pour un rejet de **validation corrigeable** : pour
	 * toute autre issue (succès, erreur de sécurité, erreur interne), aucune
	 * reprise n'est jamais proposée.
	 *
	 * @param SubmissionResult $result Issue.
	 * @return string|null
	 */
	private static function recovery_pour( SubmissionResult $result ): ?string {
		if ( SubmissionResult::VALIDATION_FAILED !== $result->code() ) {
			return null;
		}

		$id = $result->recovery_id();

		return '' === $id ? null : $id;
	}

	/**
	 * Traduit un code interne en **catégorie publique** d'erreur.
	 *
	 * Trois catégories seulement sortent d'ici ; aucune ne révèle le pipeline ni
	 * le fonctionnement des défenses. Un problème de saisie ou de contenu soumis
	 * est corrigible par la personne (`validation`) ; la limitation de débit a sa
	 * catégorie propre (`rate_limited`) ; tout le reste — défenses anti-robot,
	 * formulaire, méthode, prix, stockage, persistance, interne — reste opaque
	 * (`technical`).
	 *
	 * @param string $code Code interne du résultat.
	 * @return string Catégorie publique en liste blanche.
	 */
	private static function categorie_publique( string $code ): string {
		if ( SubmissionResult::RATE_LIMITED === $code ) {
			return 'rate_limited';
		}

		if ( SubmissionResult::VALIDATION_FAILED === $code
			|| SubmissionResult::REQUEST_TOO_LARGE === $code
			|| SubmissionResult::SERVER_UPLOAD_LIMIT === $code
			|| str_starts_with( $code, 'upload_' ) ) {
			return 'validation';
		}

		return 'technical';
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
