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
use Urbizen\Platform\Forms\StepFormRenderState;
use Urbizen\Platform\Forms\ValidationMessages;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResultNotice;
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

		// **Rendu opérationnel** (public) : la page porte un **nonce** et un **jeton
		// anti-robot à usage unique**, PROPRES à ce rendu. Servie depuis un cache
		// public partagé, elle donnerait le MÊME jeton à plusieurs visiteurs — le
		// premier le réserverait, les suivants seraient rejetés. La réponse est donc
		// marquée **non cacheable AVANT** toute génération d'identifiant technique.
		// L'aperçu inerte (admin, sans nonce ni jeton réels — C3) reste hors de cette
		// règle : son contrat de sécurité est inchangé.
		if ( ConceptionAvailability::is_public() ) {
			self::non_cacheable();
		}

		++self::$instance;
		$id = self::RACINE . '-' . self::$instance;

		ConceptionAssets::enqueue( $def, $id );

		return StepFormRenderer::render( $def, self::config( $def, $etapes, $id ), self::etat_reprise() );
	}

	/**
	 * État de reprise à réafficher, ou état neutre.
	 *
	 * En **aperçu**, aucune reprise n'est lue ni consommée. En **opérationnel**,
	 * la reprise associée au feedback signé est **consommée une seule fois**
	 * (usage unique du store) ; les codes d'erreur y sont traduits en messages
	 * publics. La réponse est déjà marquée **non cacheable** par {@see self::render()}
	 * pour tout rendu opérationnel (nonce/jeton propres à chaque visiteur) ; a
	 * fortiori lorsqu'elle porte des valeurs saisies reprises.
	 *
	 * @return StepFormRenderState
	 */
	private static function etat_reprise(): StepFormRenderState {
		// L'aperçu ne consomme jamais de reprise (aucun feedback n'y est lu).
		if ( ! ConceptionAvailability::is_public() ) {
			return StepFormRenderState::vide();
		}

		$recovery = SubmissionResultNotice::consume_recovery();

		if ( null === $recovery ) {
			return StepFormRenderState::vide();
		}

		$erreurs = array();

		foreach ( $recovery->errors as $cle => $code ) {
			$erreurs[ (string) $cle ] = ValidationMessages::message( (string) $code );
		}

		return StepFormRenderState::reprise(
			$recovery->values,
			$erreurs,
			ValidationMessages::globale( $recovery->global_error )
		);
	}

	/**
	 * Marque la réponse courante comme **privée et non stockable** par un cache
	 * partagé (LiteSpeed compris) : un visiteur ne doit jamais recevoir l'HTML —
	 * nonce, jeton anti-robot ou valeurs reprises — destiné à un autre.
	 *
	 * Trois garanties **génériques WordPress**, sans dépendance obligatoire à un
	 * plugin de cache : `DONOTCACHEPAGE` (respecté par LiteSpeed, WP Super Cache,
	 * W3TC…), `nocache_headers()` (en-têtes du cœur), et un `Cache-Control` explicite
	 * renforcé (`no-store, private`) pour garantir la directive quelle que soit la
	 * version de WordPress. Idempotente : appelée une seule fois par requête a le
	 * même effet que plusieurs.
	 *
	 * @return void
	 */
	private static function non_cacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		// Renfort explicite : garantit `no-store, private` même sur une version de
		// WordPress dont `nocache_headers()` ne les émet pas. Sans effet si l'entête
		// est déjà parti (le rendu se fait dans un tampon de sortie ; DONOTCACHEPAGE
		// suffit alors à exclure LiteSpeed).
		if ( function_exists( 'headers_sent' ) && ! headers_sent() ) {
			header( 'Cache-Control: no-cache, no-store, private, must-revalidate, max-age=0' );
		}
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
		// Le mode est décidé **uniquement côté serveur** : opérationnel si le
		// formulaire est public, aperçu inerte sinon (administration, avant
		// publication). Aucune valeur du navigateur — GET, POST, attribut de bloc,
		// cookie — n'entre dans ce choix et ne peut désactiver les défenses.
		return ConceptionAvailability::is_public()
			? self::config_operationnel( $def, $etapes, $id )
			: self::config_apercu( $def, $etapes, $id );
	}

	/**
	 * Configuration **opérationnelle** : défenses réelles, formulaire soumissible.
	 * Strictement inchangée — action, nonce, jeton anti-robot, retour historiques.
	 *
	 * @param FormDefinition    $def    Définition serveur.
	 * @param array<int, mixed> $etapes Étapes déclarées.
	 * @param string            $id     Identifiant d'instance.
	 * @return StepFormRenderConfig
	 */
	private static function config_operationnel( FormDefinition $def, array $etapes, string $id ): StepFormRenderConfig {
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
			trusted_prelude_html: self::prelude(),
			trusted_header_html: self::entete( $def, $etapes ),
			trusted_footer_html: self::brouillon( $id ),
			trusted_step_extras_html: array( 'documents' => self::consignes_documents() ),
		);
	}

	/**
	 * Configuration d'**aperçu inerte** : visuellement représentative, mais **non
	 * soumissible**. Aucun jeton anti-robot n'est émis (`AntiSpam::issue_token()`
	 * n'est pas appelé), aucune cible réelle, aucun nonce, aucun retour. Le
	 * feedback C1 n'y est **pas** affiché (aucune lecture d'URL en aperçu) : seule
	 * la notice d'aperçu figure en tête. Le renderer neutralise en plus les champs
	 * techniques et désactive le bouton d'envoi (`render_mode: 'preview'`).
	 *
	 * @param FormDefinition    $def    Définition serveur.
	 * @param array<int, mixed> $etapes Étapes déclarées.
	 * @param string            $id     Identifiant d'instance.
	 * @return StepFormRenderConfig
	 */
	private static function config_apercu( FormDefinition $def, array $etapes, string $id ): StepFormRenderConfig {
		return new StepFormRenderConfig(
			root: self::RACINE,
			instance_id: $id,
			form_action_url: '',
			action: '',
			nonce_action: SubmissionController::NONCE_ACTION,
			nonce_field: SubmissionController::NONCE_FIELD,
			token_field: SubmissionController::TOKEN_FIELD,
			token: '',
			honeypot_field: SubmissionController::HONEYPOT_FIELD,
			return_field: SubmissionController::RETURN_FIELD,
			return_url: '',
			file_accept: '.' . implode( ',.', array_keys( UploadPolicy::TYPES ) ),
			trusted_prelude_html: self::apercu(),
			trusted_header_html: self::entete( $def, $etapes ),
			trusted_footer_html: self::brouillon( $id ),
			trusted_step_extras_html: array( 'documents' => self::consignes_documents() ),
			render_mode: 'preview',
		);
	}

	/**
	 * Fragment de tête inséré avant le formulaire.
	 *
	 * Il réunit, dans l'ordre d'affichage, le **message de résultat** d'une
	 * soumission précédente (confirmation ou erreur, produit et vérifié côté
	 * serveur) puis le bandeau d'aperçu administrateur. Le message vient en
	 * premier : c'est ce qu'attend la personne qui revient d'un envoi.
	 *
	 * Le message est du HTML **déjà échappé**, produit par
	 * {@see SubmissionResultNotice} — seul endroit où l'URL est lue. En l'absence
	 * de retour valide, il vaut la chaîne vide : le rendu du formulaire est alors
	 * identique, au caractère près, à ce qu'il était sans ce fragment.
	 *
	 * @return string
	 */
	private static function prelude(): string {
		return SubmissionResultNotice::html_courante( self::RACINE );
	}

	/**
	 * Notice d'aperçu : indique clairement, de façon accessible, qu'il s'agit d'un
	 * aperçu administrateur **non soumissible**. Rendue uniquement dans la
	 * configuration d'aperçu ({@see self::config_apercu()}), jamais en opérationnel.
	 *
	 * @return string
	 */
	private static function apercu(): string {
		return sprintf(
			'<p class="%s__apercu" role="status">%s</p>',
			esc_attr( self::RACINE ),
			esc_html__( 'Aperçu réservé à l’administration : ce formulaire n’est pas encore public et ne peut pas être envoyé.', 'urbizen-platform' )
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
