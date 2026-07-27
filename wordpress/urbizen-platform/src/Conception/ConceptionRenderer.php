<?php
/**
 * Rendu HTML du formulaire de conception, en six étapes.
 *
 * **Façade de compatibilité.** Depuis l'incrément 3 du socle multi-formulaire,
 * la mécanique de rendu multi-étapes vit dans
 * {@see \Urbizen\Platform\Forms\StepFormRenderer}, générique et sans
 * connaissance métier. Cette classe n'en est plus qu'une façade : elle garde le
 * contrôle d'accès propre à Conception, construit la configuration technique
 * (action historique, nonce, jeton, retour) et les fragments qui lui sont
 * propres (cartouche d'en-tête, consignes de dépôt, consentement de brouillon),
 * puis délègue. Elle ne contient plus de seconde implémentation du renderer.
 *
 * Son API publique — `render()` et `reset()` — et sa sortie HTML sont
 * inchangées : les appelants (bloc, fixtures, bancs d'essai) ne voient aucune
 * différence. La façade sera supprimée le jour où tous les appelants
 * construiront eux-mêmes leur `StepFormRenderConfig` ; d'ici là, elle reste le
 * point d'entrée du parcours de conception.
 *
 * Trois principes tiennent la structure.
 *
 * **La définition serveur est la seule source.** Étapes, champs, ordre,
 * libellés, obligations et conditions sont lus dans `FormDefinition` ; rien
 * n'est recopié.
 *
 * **Le HTML est utilisable sans JavaScript.** Les six étapes sont présentes
 * dans le document, chacune dans son `fieldset` avec sa `legend`.
 *
 * **Rien n'est transmis par la seule couleur.** Chaque erreur porte un texte,
 * un `aria-invalid` et un lien depuis le résumé.
 *
 * @package Urbizen\Platform\Conception
 */

namespace Urbizen\Platform\Conception;

use Urbizen\Platform\Files\UploadPolicy;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\StepFormRenderConfig;
use Urbizen\Platform\Forms\StepFormRenderer;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Point d'entrée du parcours de conception : configuration et délégation.
 */
final class ConceptionRenderer {

	/**
	 * Classe racine, qui isole entièrement les styles.
	 */
	public const RACINE = 'urbizen-conception';

	/**
	 * Compteur d'instances, pour des identifiants uniques dès le serveur.
	 */
	private static int $instance = 0;

	/**
	 * Rend le formulaire, ou une chaîne vide.
	 *
	 * Le contrôle d'accès est **le premier geste** : un visiteur sans droit
	 * n'obtient ni balise, ni schéma, ni nonce, ni jeton. Le rendu lui-même est
	 * délégué au renderer générique, avec une configuration Conception.
	 *
	 * @param FormDefinition $def Définition serveur.
	 * @return string
	 */
	public static function render( FormDefinition $def ): string {
		$motif = ConceptionAvailability::blocker();

		if ( '' !== $motif ) {
			Logger::info( sprintf( 'formulaire conception non rendu : %s', $motif ) );

			return '';
		}

		$etapes = $def->steps();

		if ( array() === $etapes ) {
			Logger::error( 'formulaire conception : aucune étape définie' );

			return '';
		}

		++self::$instance;
		$id = self::RACINE . '-' . self::$instance;

		ConceptionAssets::enqueue( $def, $id );

		return StepFormRenderer::render( $def, self::config( $def, $etapes, $id ) );
	}

	/**
	 * Construit la configuration technique historique du parcours de conception.
	 *
	 * Les valeurs techniques (action, nonce, jeton, retour) restent adossées aux
	 * constantes de {@see SubmissionController}, seule source canonique : la
	 * façade n'invente aucune valeur parallèle. Les fragments propres à
	 * Conception — aperçu, cartouche, consignes de dépôt, brouillon — sont
	 * rendus ici et transmis au renderer comme du HTML déjà fait.
	 *
	 * @param FormDefinition    $def    Définition serveur.
	 * @param array<int, mixed> $etapes Étapes déclarées.
	 * @param string            $id     Identifiant d'instance.
	 * @return StepFormRenderConfig
	 */
	private static function config( FormDefinition $def, array $etapes, string $id ): StepFormRenderConfig {
		return new StepFormRenderConfig(
			root: self::RACINE,
			instance_id: $id,
			form_action_url: admin_url( 'admin-post.php' ),
			action: SubmissionController::ACTION,
			nonce_action: SubmissionController::NONCE_ACTION,
			nonce_field: SubmissionController::NONCE_FIELD,
			token_field: SubmissionController::TOKEN_FIELD,
			token: AntiSpam::issue_token(),
			honeypot_field: SubmissionController::HONEYPOT_FIELD,
			return_field: SubmissionController::RETURN_FIELD,
			return_url: self::retour(),
			file_accept: '.' . implode( ',.', array_keys( UploadPolicy::TYPES ) ),
			trusted_prelude_html: self::apercu(),
			trusted_header_html: self::entete( $def, $etapes ),
			trusted_footer_html: self::brouillon( $id ),
			trusted_step_extras_html: array( 'documents' => self::consignes_documents() ),
		);
	}

	/**
	 * Bandeau d'aperçu réservé à l'administration, tant que le formulaire n'est
	 * pas public. Vide sinon.
	 *
	 * @return string
	 */
	private static function apercu(): string {
		if ( ConceptionAvailability::is_public() ) {
			return '';
		}

		return sprintf(
			'<p class="%s__apercu" role="status">%s</p>',
			esc_attr( self::RACINE ),
			esc_html__( 'Aperçu réservé à l’administration : ce formulaire n’est pas encore public.', 'urbizen-platform' )
		);
	}

	/**
	 * Cartouche d'en-tête, repris de la structure commune aux deux maquettes
	 * `frontend/formulaires/dp-formulaire.html` et `pc-formulaire.html`.
	 *
	 * Les deux ouvrent sur le même bloc : un sur-titre en capitales espacées,
	 * un titre, puis une phrase d'accroche annonçant le nombre de rubriques.
	 * C'est cette structure qui est reprise, pas son contenu — les maquettes
	 * annoncent un Cerfa, ce que la conception de plans n'est pas.
	 *
	 * **Ajout purement présentationnel.** Aucune étape, aucun champ, aucun nom
	 * de champ, aucun prix, aucune donnée soumise ne dépend de ce bloc.
	 *
	 * Trois choix méritent d'être explicités :
	 *
	 * - le titre est un `h2`, pas un `h1` comme dans les maquettes. Celles-ci
	 *   sont des pages entières ; ici le formulaire est inséré dans une page
	 *   WordPress qui porte déjà son `h1`. Un second `h1` casserait le plan du
	 *   document ;
	 * - le nombre de rubriques est **compté**, jamais écrit en dur : il ne peut
	 *   pas mentir si une étape est ajoutée ou retirée ;
	 * - le logo et la rose des vents des maquettes ne sont pas repris. Ils y
	 *   tiennent lieu d'en-tête de site ; dans une page WordPress, l'en-tête du
	 *   thème les porte déjà. Le filet rayé de l'en-tête, lui, est conservé —
	 *   il est en CSS, donc muet pour les technologies d'assistance.
	 *
	 * @param FormDefinition       $def    Définition serveur.
	 * @param array<int, mixed>    $etapes Étapes déclarées.
	 * @return string
	 */
	private static function entete( FormDefinition $def, array $etapes ): string {
		$titre = $def->title();

		if ( '' === $titre ) {
			return '';
		}

		return sprintf(
			'<header class="%1$s__entete">'
				. '<p class="%1$s__surtitre">%2$s</p>'
				. '<h2 class="%1$s__titre">%3$s</h2>'
				. '<p class="%1$s__sous-titre">%4$s</p>'
				. '</header>',
			esc_attr( self::RACINE ),
			esc_html__( 'Plans et pièces graphiques · Étude sur mesure', 'urbizen-platform' ),
			esc_html( $titre ),
			esc_html(
				sprintf(
					/* translators: %d : nombre de rubriques du formulaire. */
					_n(
						'Répondez à la rubrique ci-dessous. Urbizen vous adresse ensuite une proposition chiffrée, puis réalise vos plans et pièces graphiques.',
						'Répondez aux %d rubriques ci-dessous. Urbizen vous adresse ensuite une proposition chiffrée, puis réalise vos plans et pièces graphiques.',
						count( $etapes ),
						'urbizen-platform'
					),
					count( $etapes )
				)
			)
		);
	}

	/**
	 * Consignes de dépôt, lues depuis la politique serveur. Insérées dans
	 * l'étape « documents » via la configuration de rendu.
	 *
	 * @return string
	 */
	private static function consignes_documents(): string {
		return sprintf(
			'<p class="%1$s__consignes">%2$s</p>',
			esc_attr( self::RACINE ),
			esc_html(
				sprintf(
					/* translators: 1: extensions, 2: max par bloc, 3: max total, 4: taille par document, 5: taille totale. */
					__( 'Formats acceptés : %1$s. %2$d documents au maximum par rubrique, %3$d au total. %4$s par document, %5$s au total.', 'urbizen-platform' ),
					strtoupper( implode( ', ', array_keys( UploadPolicy::TYPES ) ) ),
					UploadPolicy::MAX_PER_BLOCK,
					UploadPolicy::MAX_TOTAL,
					size_format( UploadPolicy::MAX_FILE_SIZE ),
					size_format( UploadPolicy::MAX_TOTAL_SIZE )
				)
			)
		);
	}

	/**
	 * Consentement et information de brouillon.
	 *
	 * Le consentement à la sauvegarde sur l'appareil est **distinct** du
	 * consentement contractuel du formulaire, et décoché par défaut : rien
	 * n'est écrit durablement tant qu'il n'est pas donné.
	 *
	 * @param string $id Identifiant d'instance.
	 * @return string
	 */
	private static function brouillon( string $id ): string {
		return sprintf(
			'<div class="%1$s__brouillon">'
				. '<div class="%1$s__choix">'
				. '<input type="checkbox" id="%2$s-brouillon" data-role="consentement-brouillon">'
				. '<label for="%2$s-brouillon">%3$s</label>'
				. '</div>'
				. '<p class="%1$s__brouillon-note">%4$s</p>'
				. '<button type="button" class="%1$s__lien" data-action="effacer-brouillon">%5$s</button>'
				. '<p class="%1$s__brouillon-info" data-role="info-brouillon" role="status" aria-live="polite"></p>'
				. '</div>',
			esc_attr( self::RACINE ),
			esc_attr( $id ),
			esc_html__( 'Conserver mes réponses sur cet appareil pendant 7 jours', 'urbizen-platform' ),
			esc_html__(
				'Vos réponses restent sur votre appareil : elles ne sont pas envoyées à Urbizen tant que vous n’avez pas validé le formulaire. À éviter sur un ordinateur partagé. Les documents joints ne sont jamais conservés.',
				'urbizen-platform'
			),
			esc_html__( 'Supprimer le brouillon', 'urbizen-platform' )
		);
	}

	/**
	 * URL de retour, toujours du même site.
	 *
	 * @return string
	 */
	private static function retour(): string {
		$permalien = get_permalink();

		return is_string( $permalien ) && '' !== $permalien ? $permalien : home_url( '/' );
	}

	/**
	 * Remet le compteur d'instances à zéro.
	 *
	 * Réservé aux bancs d'essai.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = 0;
	}
}
