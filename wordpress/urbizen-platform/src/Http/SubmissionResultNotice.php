<?php
/**
 * Message de résultat affiché après la redirection d'une soumission.
 *
 * Ce composant relie les trois responsabilités du retour post-soumission, en
 * les gardant séparées : lire le jeton dans la requête (couche HTTP), le faire
 * **vérifier** ({@see SubmissionFeedbackToken}), puis traduire le retour vérifié
 * en un **message public accessible**.
 *
 * **La lecture de l'URL est confinée ici.** Le renderer générique
 * ({@see \Urbizen\Platform\Forms\StepFormRenderer}) ne lit aucune superglobale,
 * ne vérifie aucun jeton et ne choisit aucun message : il reçoit du HTML déjà
 * fait. La façade Conception se contente d'insérer, comme fragment de confiance,
 * le HTML **déjà échappé** que produit ce composant.
 *
 * **Aucune confiance dans les paramètres bruts.** Un `?urbizen_submission=success`
 * ou un `?reference=URB-...` forgés ne produisent rien : seul un jeton signé et
 * valide affiche une confirmation. La référence n'est montrée qu'au sein d'un
 * retour vérifié.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture, traduction et rendu accessible du retour de soumission.
 */
final class SubmissionResultNotice {

	/**
	 * Nom du paramètre d'URL portant le jeton de retour.
	 */
	public const CHAMP = 'urbizen_feedback';

	/**
	 * Rend le message correspondant au jeton présent dans la requête, ou une
	 * chaîne vide si aucun retour valide n'est présent.
	 *
	 * Unique point où l'URL est lue : le jeton en est extrait, puis vérifié. En
	 * l'absence de jeton valide — paramètre absent, forgé, altéré ou expiré —,
	 * rien n'est affiché et le formulaire reste inchangé.
	 *
	 * @param string   $racine Classe racine CSS de l'hôte (isolation des styles).
	 * @param int|null $now    Horodatage courant (tests).
	 * @return string HTML déjà échappé, ou chaîne vide.
	 */
	public static function html_courante( string $racine, ?int $now = null ): string {
		$feedback = SubmissionFeedbackToken::verify( self::jeton_de_requete(), $now );

		return null === $feedback ? '' : self::render( $feedback, $racine );
	}

	/**
	 * Consomme, s'il existe, la reprise serveur associée au feedback courant.
	 *
	 * **API préparatoire (C2A).** Ce point vérifie le feedback signé, en extrait
	 * l'identifiant opaque de reprise (présent uniquement pour une erreur de
	 * validation corrigeable) et **consomme** la reprise (lecture + suppression, à
	 * usage unique). Il renvoie l'objet serveur — valeurs nettoyées et erreurs
	 * publiques — que **C2B** réaffichera. En C2A, ce point n'est **pas** branché
	 * au rendu : rien n'est encore injecté dans les champs ni affiché.
	 *
	 * Renvoie null en l'absence de reprise valide : succès, erreur de sécurité,
	 * jeton absent/forgé, ou identifiant expiré/déjà consommé.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return SubmissionRecovery|null
	 */
	public static function consume_recovery( ?int $now = null ): ?SubmissionRecovery {
		$feedback = SubmissionFeedbackToken::verify( self::jeton_de_requete(), $now );

		if ( null === $feedback || $feedback->est_succes() || null === $feedback->recovery_id ) {
			return null;
		}

		return SubmissionRecoveryStore::consume( $feedback->recovery_id );
	}

	/**
	 * Extrait le jeton brut de la requête, sans lui accorder aucune confiance.
	 *
	 * @return mixed Valeur brute (chaîne, tableau ou null) — jamais interprétée ici.
	 */
	private static function jeton_de_requete() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture d'affichage : la fiabilité vient de la signature du jeton, pas d'un nonce.
		$get = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();

		if ( ! is_array( $get ) ) {
			return null;
		}

		return $get[ self::CHAMP ] ?? null;
	}

	/**
	 * Traduit un retour vérifié en HTML accessible.
	 *
	 * Séparé de la vérification : il ne reçoit qu'un retour déjà validé et se
	 * borne à produire le message. Toute valeur variable — la référence — est
	 * échappée sans exception.
	 *
	 * Le conteneur porte un **marqueur serveur** `data-urbizen-feedback-status`
	 * (`success`/`error`), issu du retour vérifié et **jamais** d'un paramètre
	 * d'URL. C'est la **seule** source à laquelle le script client se fie pour
	 * décider d'un comportement post-succès : le marqueur n'existe que si le jeton
	 * signé a été vérifié côté serveur. Il ne porte ni référence ni donnée
	 * personnelle.
	 *
	 * @param SubmissionFeedback $feedback Retour vérifié.
	 * @param string             $racine   Classe racine CSS.
	 * @return string
	 */
	public static function render( SubmissionFeedback $feedback, string $racine ): string {
		// Liste blanche par construction : le retour vérifié n'a que ces deux états.
		$statut = $feedback->est_succes() ? 'success' : 'error';

		if ( $feedback->est_succes() ) {
			return sprintf(
				'<div class="%1$s__resultat %1$s__resultat--succes" role="status" data-urbizen-feedback-status="%2$s">'
					. '<h2 class="%1$s__resultat-titre">%3$s</h2>'
					. '<p class="%1$s__resultat-texte">%4$s <span class="%1$s__resultat-reference">%5$s</span></p>'
					. '<p class="%1$s__resultat-note">%6$s</p>'
					. '</div>',
				esc_attr( $racine ),
				esc_attr( $statut ),
				esc_html__( 'Votre demande a bien été enregistrée', 'urbizen-platform' ),
				esc_html__( 'Référence de votre demande :', 'urbizen-platform' ),
				esc_html( (string) $feedback->reference ),
				esc_html__( 'Conservez cette référence : elle identifie votre demande.', 'urbizen-platform' )
			);
		}

		return sprintf(
			'<div class="%1$s__resultat %1$s__resultat--erreur" role="alert" data-urbizen-feedback-status="%2$s">'
				. '<h2 class="%1$s__resultat-titre">%3$s</h2>'
				. '<p class="%1$s__resultat-texte">%4$s</p>'
				. '</div>',
			esc_attr( $racine ),
			esc_attr( $statut ),
			esc_html__( 'Votre demande n’a pas pu être envoyée', 'urbizen-platform' ),
			esc_html( self::message_erreur( (string) $feedback->categorie_erreur ) )
		);
	}

	/**
	 * Message public d'une catégorie d'erreur.
	 *
	 * Aucune trace, exception, classe, fichier, code interne ni détail des
	 * défenses n'y figure : seulement une phrase compréhensible, adaptée à la
	 * catégorie.
	 *
	 * @param string $categorie Catégorie publique en liste blanche.
	 * @return string
	 */
	private static function message_erreur( string $categorie ): string {
		switch ( $categorie ) {
			case 'validation':
				return __( 'Certaines informations n’ont pas pu être validées. Vérifiez votre saisie, puis renvoyez votre demande.', 'urbizen-platform' );

			case 'rate_limited':
				return __( 'Trop de demandes ont été envoyées depuis cet appareil. Réessayez un peu plus tard.', 'urbizen-platform' );

			case 'unavailable':
				return __( 'Le formulaire n’est pas disponible pour le moment. Réessayez plus tard.', 'urbizen-platform' );

			case 'technical':
			default:
				return __( 'Une erreur technique est survenue et votre demande n’a pas été enregistrée. Réessayez dans un instant.', 'urbizen-platform' );
		}
	}
}
