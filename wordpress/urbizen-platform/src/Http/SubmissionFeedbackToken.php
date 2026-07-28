<?php
/**
 * Jeton signé transportant un {@see SubmissionFeedback} dans l'URL de retour.
 *
 * **Pourquoi un jeton signé.** Le résultat public d'une soumission ne doit
 * jamais être déterminé par un paramètre libre de l'URL : `?statut=success` ou
 * `?reference=URB-...` forgés à la main ne doivent produire **aucune**
 * confirmation fiable. Le serveur émet donc ce jeton, le signe avec un secret
 * qui ne quitte jamais le serveur (sel WordPress, hors dépôt), et le revérifie
 * au rendu. Une charge modifiée — état, type, référence, expiration — invalide
 * la signature.
 *
 * **Finalité distincte du jeton anti-robot.** Le contexte HMAC est propre à ce
 * mécanisme (`urbizen-feedback`). Un jeton de retour ne vaut jamais comme jeton
 * anti-spam, et réciproquement : les finalités ne se confondent pas.
 *
 * **Ce n'est pas une authentification.** Le jeton ne donne accès à aucune donnée
 * persistée : il ne sert qu'à réafficher, de façon fiable, un résultat déjà
 * connu de la personne qui vient de soumettre. Il est **borné dans le temps**
 * ({@see self::TTL}) et ne contient **aucune donnée personnelle**.
 *
 * Format : `base64url(json).base64url(hmac_sha256)`. La signature couvre le
 * premier champ ; la comparaison est faite à temps constant (`hash_equals`).
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Support\Reference;

defined( 'ABSPATH' ) || exit;

/**
 * Émission et vérification des jetons de retour de soumission.
 */
final class SubmissionFeedbackToken {

	/**
	 * Durée de validité, en secondes. Volontairement **courte** : le jeton ne
	 * sert qu'à afficher la confirmation sur la page d'arrivée, juste après la
	 * redirection.
	 */
	public const TTL = 600;

	/**
	 * Contexte de signature, propre à cette finalité.
	 */
	private const CONTEXTE = 'urbizen-feedback-v1';

	/**
	 * Longueur maximale acceptée d'un jeton. La charge utile réelle est très
	 * courte ; au-delà, l'entrée est fabriquée et rejetée sans être analysée.
	 */
	private const LONGUEUR_MAX = 512;

	/**
	 * Sépare la charge de la signature.
	 */
	private const SEP = '.';

	/**
	 * Émet un jeton signé pour un retour donné.
	 *
	 * Renvoie une **chaîne vide** si l'encodage échoue : l'appelant redirige
	 * alors sans jeton (aucune confirmation affichée), plutôt que d'émettre un
	 * jeton malformé. Un succès déjà persisté n'est jamais transformé en échec.
	 *
	 * @param SubmissionFeedback $feedback Retour à transporter.
	 * @param int|null           $now      Horodatage d'émission (tests).
	 * @return string Jeton, ou chaîne vide en cas d'échec d'encodage.
	 */
	public static function issue( SubmissionFeedback $feedback, ?int $now = null ): string {
		$now = null === $now ? time() : $now;

		$charge = array(
			'v' => SubmissionFeedback::VERSION,
			't' => $feedback->form_type,
			's' => $feedback->statut,
			'x' => $now + self::TTL,
		);

		if ( $feedback->est_succes() ) {
			$charge['r'] = (string) $feedback->reference;
		} else {
			$charge['e'] = (string) $feedback->categorie_erreur;

			// Identifiant OPAQUE de reprise (C2A), couvert par la signature. Présent
			// uniquement pour une erreur corrigeable ; l'objet Feedback l'a déjà
			// restreint à la catégorie « validation ».
			if ( is_string( $feedback->recovery_id ) && '' !== $feedback->recovery_id ) {
				$charge['k'] = $feedback->recovery_id;
			}
		}

		$json = wp_json_encode( $charge );

		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$b64 = self::b64url_encode( $json );

		return $b64 . self::SEP . self::signer( $b64 );
	}

	/**
	 * Vérifie un jeton et renvoie le retour qu'il porte, ou `null`.
	 *
	 * Rejette silencieusement (par `null`, jamais par une erreur fatale) toute
	 * entrée absente, vide, non-chaîne, trop longue, mal formée, de signature
	 * invalide, de charge altérée, de version inconnue, d'un autre type, de
	 * statut ou de catégorie non reconnus, de référence mal formée, ou expirée.
	 *
	 * @param mixed    $token Jeton reçu (contenu libre de l'URL : peut être un tableau).
	 * @param int|null $now   Horodatage courant (tests).
	 * @return SubmissionFeedback|null
	 */
	public static function verify( $token, ?int $now = null ): ?SubmissionFeedback {
		$now = null === $now ? time() : $now;

		if ( ! is_string( $token ) ) {
			return null;
		}

		$longueur = strlen( $token );

		if ( $longueur < 1 || $longueur > self::LONGUEUR_MAX ) {
			return null;
		}

		$parts = explode( self::SEP, $token );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		list( $b64, $signature ) = $parts;

		// Alphabet base64url strict : ni « / », ni « + », ni « = », ni « . »,
		// ni traversée, ni Unicode ne franchissent ce filtre.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $b64 ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $signature ) ) {
			return null;
		}

		// Comparaison à temps constant : une comparaison naïve laisserait fuir la
		// signature attendue, octet par octet, par mesure du temps de réponse.
		if ( ! hash_equals( self::signer( $b64 ), $signature ) ) {
			return null;
		}

		$json = self::b64url_decode( $b64 );

		if ( '' === $json ) {
			return null;
		}

		$charge = json_decode( $json, true );

		if ( ! is_array( $charge ) ) {
			return null;
		}

		// Version, type et expiration : la charge, même signée, doit rester
		// structurellement conforme et vivante.
		if ( ( $charge['v'] ?? null ) !== SubmissionFeedback::VERSION ) {
			return null;
		}

		$type = $charge['t'] ?? null;

		// Seul « conception » est reconnu dans cet incrément : le retour d'un
		// autre formulaire n'affiche rien.
		if ( 'conception' !== $type ) {
			return null;
		}

		$expire = $charge['x'] ?? null;

		if ( ! is_int( $expire ) || $expire <= 0 || $now > $expire ) {
			return null;
		}

		$statut = $charge['s'] ?? null;

		if ( SubmissionFeedback::STATUT_SUCCES === $statut ) {
			$reference = $charge['r'] ?? null;

			// La référence est validée contre le format réellement produit par le
			// dépôt : une charge signée mais portant une référence non conforme
			// est rejetée.
			if ( ! is_string( $reference ) || ! Reference::is_valid( $reference ) ) {
				return null;
			}

			// Un succès ne porte JAMAIS d'identifiant de reprise.
			if ( array_key_exists( 'k', $charge ) ) {
				return null;
			}

			return SubmissionFeedback::succes( $type, $reference );
		}

		if ( SubmissionFeedback::STATUT_ERREUR === $statut ) {
			$categorie = $charge['e'] ?? null;

			if ( ! is_string( $categorie ) || ! SubmissionFeedback::categorie_valide( $categorie ) ) {
				return null;
			}

			// Reprise optionnelle : l'identifiant opaque n'est accepté que pour une
			// erreur « validation » et sous une forme stricte. Mal placé ou mal
			// formé, il invalide tout le jeton — jamais d'interprétation permissive.
			$reprise = null;

			if ( array_key_exists( 'k', $charge ) ) {
				$k = $charge['k'];

				if ( 'validation' !== $categorie || ! is_string( $k ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $k ) ) {
					return null;
				}

				$reprise = $k;
			}

			return SubmissionFeedback::erreur( $type, $categorie, $reprise );
		}

		return null;
	}

	/**
	 * Signature base64url d'une charge encodée.
	 *
	 * @param string $b64 Charge base64url.
	 * @return string
	 */
	private static function signer( string $b64 ): string {
		return self::b64url_encode( hash_hmac( 'sha256', self::CONTEXTE . '|' . $b64, self::secret(), true ) );
	}

	/**
	 * Secret de signature.
	 *
	 * Adossé au sel WordPress (hors dépôt), avec un suffixe de contexte propre à
	 * cette finalité : aucun secret n'est écrit ici, et un jeton de retour ne
	 * partage jamais sa clé avec le jeton anti-robot.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return wp_salt( 'auth' ) . '|urbizen-feedback';
	}

	/**
	 * Encodage base64url sans remplissage.
	 *
	 * @param string $binaire Données brutes.
	 * @return string
	 */
	private static function b64url_encode( string $binaire ): string {
		return rtrim( strtr( base64_encode( $binaire ), '+/', '-_' ), '=' );
	}

	/**
	 * Décodage base64url strict. Renvoie une chaîne vide si l'entrée n'est pas
	 * un base64url valide.
	 *
	 * @param string $texte Chaîne base64url.
	 * @return string
	 */
	private static function b64url_decode( string $texte ): string {
		$decode = base64_decode( strtr( $texte, '-_', '+/' ), true );

		return is_string( $decode ) ? $decode : '';
	}
}
