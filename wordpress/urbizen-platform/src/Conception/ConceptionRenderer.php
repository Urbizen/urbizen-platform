<?php
/**
 * Rendu HTML du formulaire de conception, en six étapes.
 *
 * Le `Renderer` historique refusait ce formulaire — il aurait posé les
 * quarante-cinq champs à plat, sans distinguer un bouton radio d'une zone de
 * texte. Cette classe est le rendu qui lui manquait.
 *
 * Trois principes tiennent la structure.
 *
 * **La définition serveur est la seule source.** Étapes, champs, ordre,
 * libellés, obligations et conditions sont lus dans `FormDefinition` ; rien
 * n'est recopié.
 *
 * **Le HTML est utilisable sans JavaScript.** Les six étapes sont présentes
 * dans le document, chacune dans son `fieldset` avec sa `legend`. Sans script,
 * elles s'affichent les unes après les autres, et un message dit clairement
 * que l'envoi exige JavaScript — plutôt qu'une interface qui paraît marcher et
 * ne le peut pas.
 *
 * **Rien n'est transmis par la seule couleur.** Chaque erreur porte un texte,
 * un `aria-invalid` et un lien depuis le résumé.
 *
 * @package Urbizen\Platform\Conception
 */

namespace Urbizen\Platform\Conception;

use Urbizen\Platform\Files\UploadPolicy;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Structure HTML du parcours de conception.
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
	 * Libellés des blocs de dépôt.
	 *
	 * @var array<string, string>
	 */
	private const BLOCS = array(
		'croquis_plans'     => 'Croquis et plans existants',
		'plan_terrain'      => 'Plan du terrain',
		'photos'            => 'Photographies',
		'inspirations_docs' => 'Inspirations et documents',
		'urbanisme'         => 'Pièces d’urbanisme',
	);

	/**
	 * Rend le formulaire, ou une chaîne vide.
	 *
	 * Le contrôle d'accès est **le premier geste** : un visiteur sans droit
	 * n'obtient ni balise, ni schéma, ni nonce, ni jeton.
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

		$html   = array();
		$html[] = sprintf( '<div class="%s" id="%s">', esc_attr( self::RACINE ), esc_attr( $id ) );

		if ( ! ConceptionAvailability::is_public() ) {
			$html[] = sprintf(
				'<p class="%s__apercu" role="status">%s</p>',
				esc_attr( self::RACINE ),
				esc_html__( 'Aperçu réservé à l’administration : ce formulaire n’est pas encore public.', 'urbizen-platform' )
			);
		}

		$html[] = self::sans_script();
		$html[] = self::entete( $def, $etapes );
		$html[] = self::progression( $etapes, $id );
		$html[] = sprintf(
			'<form class="%1$s__form" id="%2$s-form" method="post" enctype="multipart/form-data" action="%3$s" novalidate>',
			esc_attr( self::RACINE ),
			esc_attr( $id ),
			esc_url( admin_url( 'admin-post.php' ) )
		);

		$html[] = self::champs_techniques();
		$html[] = self::resume_erreurs( $id );

		$dernier = count( $etapes ) - 1;

		foreach ( array_values( $etapes ) as $rang => $etape ) {
			$html[] = self::etape( $def, $etape, $rang, $id, $rang === $dernier );
		}

		$html[] = self::brouillon( $id );
		$html[] = self::navigation( $id );
		$html[] = '</form>';
		$html[] = sprintf(
			'<div class="%s__annonce" role="status" aria-live="polite" id="%s-annonce"></div>',
			esc_attr( self::RACINE ),
			esc_attr( $id )
		);
		$html[] = '</div>';

		return implode( "\n", $html );
	}

	/**
	 * Message affiché lorsque JavaScript est absent.
	 *
	 * Il ne masque rien : les champs restent lisibles et renseignables. Il dit
	 * seulement, sans détour, que l'envoi ne fonctionnera pas.
	 *
	 * @return string
	 */
	private static function sans_script(): string {
		return sprintf(
			'<noscript><p class="%s__noscript">%s</p></noscript>',
			esc_attr( self::RACINE ),
			esc_html__(
				'Ce formulaire nécessite JavaScript pour être envoyé. Les questions restent consultables ci-dessous, mais l’envoi ne fonctionnera pas tant que JavaScript est désactivé.',
				'urbizen-platform'
			)
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
	 * Liste de progression.
	 *
	 * @param array<int, mixed> $etapes Étapes.
	 * @param string            $id     Identifiant d'instance.
	 * @return string
	 */
	private static function progression( array $etapes, string $id ): string {
		$html   = array();
		$html[] = sprintf(
			'<nav class="%s__progression" aria-label="%s"><ol>',
			esc_attr( self::RACINE ),
			esc_attr__( 'Progression du formulaire', 'urbizen-platform' )
		);

		foreach ( array_values( $etapes ) as $rang => $etape ) {
			$eid     = self::etape_id( $etape );
			$libelle = self::etape_libelle( $etape );

			$html[] = sprintf(
				'<li class="%1$s__progression-item" data-step="%2$s"%3$s><span class="%1$s__progression-rang">%4$d</span> <span class="%1$s__progression-label">%5$s</span></li>',
				esc_attr( self::RACINE ),
				esc_attr( $eid ),
				0 === $rang ? ' aria-current="step"' : '',
				$rang + 1,
				esc_html( $libelle )
			);
		}

		$html[] = '</ol></nav>';

		return implode( '', $html );
	}

	/**
	 * Champs techniques : nonce, jeton, pot de miel, retour.
	 *
	 * Le pot de miel est masqué visuellement **et** retiré de l'ordre de
	 * tabulation : un utilisateur au clavier ne doit jamais tomber dedans.
	 *
	 * @return string
	 */
	private static function champs_techniques(): string {
		$html   = array();
		$html[] = sprintf( '<input type="hidden" name="action" value="%s">', esc_attr( SubmissionController::ACTION ) );
		$html[] = wp_nonce_field( SubmissionController::NONCE_ACTION, SubmissionController::NONCE_FIELD, true, false );
		$html[] = sprintf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( SubmissionController::TOKEN_FIELD ),
			esc_attr( AntiSpam::issue_token() )
		);
		$html[] = sprintf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( SubmissionController::RETURN_FIELD ),
			esc_url( self::retour() )
		);
		$html[] = sprintf(
			'<div class="%1$s__miel" aria-hidden="true"><label for="%2$s">%3$s</label><input type="text" id="%2$s" name="%2$s" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( self::RACINE ),
			esc_attr( SubmissionController::HONEYPOT_FIELD ),
			esc_html__( 'Ne remplissez pas ce champ', 'urbizen-platform' )
		);

		return implode( "\n", $html );
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
	 * Résumé des erreurs, avec liens vers les champs concernés.
	 *
	 * @param string $id Identifiant d'instance.
	 * @return string
	 */
	private static function resume_erreurs( string $id ): string {
		return sprintf(
			'<div class="%1$s__erreurs" id="%2$s-erreurs" role="alert" aria-live="assertive" tabindex="-1" hidden><h2 class="%1$s__erreurs-titre">%3$s</h2><ul class="%1$s__erreurs-liste"></ul></div>',
			esc_attr( self::RACINE ),
			esc_attr( $id ),
			esc_html__( 'Corrigez les points suivants', 'urbizen-platform' )
		);
	}

	/**
	 * Rend une étape complète.
	 *
	 * @param FormDefinition $def     Définition.
	 * @param mixed          $etape   Étape.
	 * @param int            $rang    Rang, à partir de 0.
	 * @param string         $id      Identifiant d'instance.
	 * @param bool           $dernier Dernière étape.
	 * @return string
	 */
	private static function etape( FormDefinition $def, $etape, int $rang, string $id, bool $dernier ): string {
		$eid    = self::etape_id( $etape );
		$champs = $def->fields_for_step( $eid );
		$total  = count( $def->steps() );

		// Le titre long est celui de la définition. Le libellé court reste au
		// rail : il y sert de repère, il ne remplace pas le vrai titre ici.
		$intitule    = self::etape_titre( $etape );
		$description = self::etape_description( $etape );
		$id_desc     = $id . '-desc-' . $eid;

		$html   = array();
		$html[] = sprintf(
			'<fieldset class="%1$s__etape" id="%2$s-etape-%3$s" data-step="%3$s" data-rang="%4$d"%5$s%6$s>',
			esc_attr( self::RACINE ),
			esc_attr( $id ),
			esc_attr( $eid ),
			$rang,
			$dernier ? ' data-derniere="1"' : '',
			'' === $description ? '' : sprintf( ' aria-describedby="%s"', esc_attr( $id_desc ) )
		);

		/*
		 * Le rang est dans la légende, donc dans le nom accessible du groupe :
		 * « Étape 1 sur 6, Votre projet ». Il n'est annoncé qu'une fois — le
		 * rail porte `aria-current`, pas un second décompte parlé. Le total
		 * est compté sur la définition, jamais écrit en dur.
		 */
		$html[] = sprintf(
			'<legend class="%1$s__etape-titre" id="%2$s-titre-%3$s" tabindex="-1">'
				// L'espace entre les deux `span` n'est pas décoratif : sans lui,
				// le nom accessible du groupe est « Étape 1 sur 6Votre projet ».
				. '<span class="%1$s__etape-rang">%4$s</span> '
				. '<span class="%1$s__etape-intitule">%5$s</span>'
				. '</legend>',
			esc_attr( self::RACINE ),
			esc_attr( $id ),
			esc_attr( $eid ),
			esc_html(
				sprintf(
					/* translators: 1 : rang de l'étape. 2 : nombre total d'étapes. */
					__( 'Étape %1$d sur %2$d', 'urbizen-platform' ),
					$rang + 1,
					$total
				)
			),
			esc_html( $intitule )
		);

		if ( '' !== $description ) {
			$html[] = sprintf(
				'<p class="%1$s__etape-description" id="%2$s">%3$s</p>',
				esc_attr( self::RACINE ),
				esc_attr( $id_desc ),
				esc_html( $description )
			);
		}

		if ( 'documents' === $eid ) {
			$html[] = self::consignes_documents();
		}

		foreach ( $champs as $champ ) {
			$html[] = self::champ( (array) $champ, $id );
		}

		$html[] = '</fieldset>';

		return implode( "\n", $html );
	}

	/**
	 * Consignes de dépôt, lues depuis la politique serveur.
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
	 * Rend un champ, selon son type.
	 *
	 * @param array<string, mixed> $champ Champ.
	 * @param string               $id    Identifiant d'instance.
	 * @return string
	 */
	private static function champ( array $champ, string $id ): string {
		$nom = (string) ( $champ['name'] ?? '' );

		if ( '' === $nom ) {
			return '';
		}

		$type      = (string) ( $champ['type'] ?? 'text' );
		$libelle   = (string) ( $champ['label'] ?? $nom );
		$requis    = ! empty( $champ['required'] );
		$champ_id  = $id . '-' . $nom;
		$aide_id   = $champ_id . '-aide';
		$condition = isset( $champ['visible_if'] ) && is_array( $champ['visible_if'] ) ? $champ['visible_if'] : null;

		$attributs = sprintf(
			' class="%1$s__champ %1$s__champ--%2$s" data-field="%3$s"',
			esc_attr( self::RACINE ),
			esc_attr( $type ),
			esc_attr( $nom )
		);

		if ( null !== $condition ) {
			$attributs .= sprintf(
				' data-visible-if="%s" data-visible-in="%s"',
				esc_attr( (string) ( $condition['field'] ?? '' ) ),
				esc_attr( implode( '|', array_map( 'strval', (array) ( $condition['in'] ?? array() ) ) ) )
			);
		}

		$html   = array();
		$html[] = sprintf( '<div%s>', $attributs );

		// Un groupe de choix porte son libellé dans une légende, pas dans un
		// `label` : un `label` unique ne peut pas décrire plusieurs contrôles.
		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) && isset( $champ['options'] ) ) {
			$html[] = sprintf(
				'<fieldset class="%1$s__groupe"><legend class="%1$s__label">%2$s%3$s</legend>',
				esc_attr( self::RACINE ),
				esc_html( $libelle ),
				$requis ? self::marque_requis() : ''
			);
			$html[] = self::choix( $champ, $champ_id, $type );
			$html[] = '</fieldset>';
		} else {
			$html[] = sprintf(
				'<label class="%1$s__label" for="%2$s">%3$s%4$s</label>',
				esc_attr( self::RACINE ),
				esc_attr( $champ_id ),
				esc_html( $libelle ),
				$requis ? self::marque_requis() : ''
			);
			$html[] = self::controle( $champ, $champ_id, $aide_id );
		}

		$html[] = sprintf(
			'<p class="%1$s__erreur" id="%2$s" hidden></p>',
			esc_attr( self::RACINE ),
			esc_attr( $aide_id )
		);
		$html[] = '</div>';

		return implode( '', $html );
	}

	/**
	 * Marque textuelle d'un champ obligatoire.
	 *
	 * Un astérisque seul ne dit rien à qui ne le voit pas : le mot suit.
	 *
	 * @return string
	 */
	private static function marque_requis(): string {
		return sprintf(
			' <span class="%s__requis">%s</span>',
			esc_attr( self::RACINE ),
			esc_html__( '(obligatoire)', 'urbizen-platform' )
		);
	}

	/**
	 * Groupe de boutons radio ou de cases à cocher.
	 *
	 * @param array<string, mixed> $champ    Champ.
	 * @param string               $champ_id Identifiant.
	 * @param string               $type     Type.
	 * @return string
	 */
	private static function choix( array $champ, string $champ_id, string $type ): string {
		$nom  = (string) $champ['name'];
		$html = array();

		foreach ( (array) ( $champ['options'] ?? array() ) as $rang => $option ) {
			$valeur  = (string) ( is_array( $option ) ? ( $option['value'] ?? '' ) : $option );
			$libelle = (string) ( is_array( $option ) ? ( $option['label'] ?? $valeur ) : $option );
			$oid     = $champ_id . '-' . $rang;

			$html[] = sprintf(
				'<div class="%1$s__choix"><input type="%2$s" id="%3$s" name="%4$s%5$s" value="%6$s"><label for="%3$s">%7$s</label></div>',
				esc_attr( self::RACINE ),
				esc_attr( $type ),
				esc_attr( $oid ),
				esc_attr( $nom ),
				'checkbox' === $type ? '[]' : '',
				esc_attr( $valeur ),
				esc_html( $libelle )
			);
		}

		return implode( '', $html );
	}

	/**
	 * Contrôle simple : texte, nombre, liste, zone, consentement, dépôt.
	 *
	 * @param array<string, mixed> $champ    Champ.
	 * @param string               $champ_id Identifiant.
	 * @param string               $aide_id  Identifiant du message d'erreur.
	 * @return string
	 */
	private static function controle( array $champ, string $champ_id, string $aide_id ): string {
		$nom  = (string) $champ['name'];
		$type = (string) ( $champ['type'] ?? 'text' );

		$commun = sprintf(
			' id="%s" name="%s" aria-describedby="%s"',
			esc_attr( $champ_id ),
			esc_attr( 'file' === $type ? $nom . '[]' : $nom ),
			esc_attr( $aide_id )
		);

		foreach ( array( 'maxlength', 'min', 'max', 'step' ) as $cle ) {
			if ( isset( $champ[ $cle ] ) && is_scalar( $champ[ $cle ] ) ) {
				$commun .= sprintf( ' %s="%s"', esc_attr( $cle ), esc_attr( (string) $champ[ $cle ] ) );
			}
		}

		switch ( $type ) {
			case 'textarea':
				return sprintf( '<textarea%s rows="4"></textarea>', $commun );

			case 'select':
				$html = sprintf( '<select%s>', $commun );
				$html .= sprintf( '<option value="">%s</option>', esc_html__( 'Choisissez…', 'urbizen-platform' ) );

				foreach ( (array) ( $champ['options'] ?? array() ) as $option ) {
					$valeur  = (string) ( is_array( $option ) ? ( $option['value'] ?? '' ) : $option );
					$libelle = (string) ( is_array( $option ) ? ( $option['label'] ?? $valeur ) : $option );
					$html   .= sprintf( '<option value="%s">%s</option>', esc_attr( $valeur ), esc_html( $libelle ) );
				}

				return $html . '</select>';

			case 'consent':
				return sprintf( '<input type="checkbox" value="1"%s>', $commun );

			case 'file':
				return sprintf(
					'<input type="file" multiple accept="%s"%s><ul class="%s__fichiers" data-bloc="%s"></ul>',
					esc_attr( '.' . implode( ',.', array_keys( UploadPolicy::TYPES ) ) ),
					$commun,
					esc_attr( self::RACINE ),
					esc_attr( $nom )
				);

			case 'number':
				return sprintf( '<input type="number" inputmode="numeric"%s>', $commun );

			default:
				return sprintf( '<input type="text"%s>', $commun );
		}
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
	 * Boutons de navigation.
	 *
	 * `button` pour Précédent et Suivant : ils ne soumettent rien. Le seul
	 * `submit` du document apparaît à la dernière étape.
	 *
	 * @param string $id Identifiant d'instance.
	 * @return string
	 */
	private static function navigation( string $id ): string {
		return sprintf(
			// La navigation est masquée par le serveur et révélée par le
			// script. Sans JavaScript, aucun bouton n'apparaît : le message
			// `noscript` dit que l'envoi ne fonctionnera pas, il ne doit pas
			// être démenti par un bouton « Envoyer » bien visible.
			'<div class="%1$s__navigation" hidden>'
				. '<button type="button" class="%1$s__bouton %1$s__bouton--precedent" data-action="precedent">%2$s</button>'
				. '<div class="%1$s__estimation" id="%3$s-estimation" aria-live="polite"></div>'
				. '<button type="button" class="%1$s__bouton %1$s__bouton--suivant" data-action="suivant">%4$s</button>'
				. '<button type="submit" class="%1$s__bouton %1$s__bouton--envoyer" data-action="envoyer">%5$s</button>'
				. '</div>',
			esc_attr( self::RACINE ),
			esc_html__( 'Précédent', 'urbizen-platform' ),
			esc_attr( $id ),
			esc_html__( 'Suivant', 'urbizen-platform' ),
			esc_html__( 'Envoyer ma demande', 'urbizen-platform' )
		);
	}

	/**
	 * Identifiant d'une étape.
	 *
	 * @param mixed $etape Étape.
	 * @return string
	 */
	private static function etape_id( $etape ): string {
		return is_array( $etape ) ? (string) ( $etape['id'] ?? '' ) : (string) $etape;
	}

	/**
	 * Libellé d'une étape.
	 *
	 * @param mixed $etape Étape.
	 * @return string
	 */
	/**
	 * Titre long de l'étape, tel que déclaré dans la définition.
	 *
	 * `title` d'abord — c'est le vrai titre. `label` n'est qu'un repli : il est
	 * fait pour le rail, où la place manque, et ne dit pas la même chose.
	 * Aucun texte n'est écrit ici : le renderer ne fait que lire.
	 *
	 * @param array<string, mixed>|string $etape Étape déclarée.
	 * @return string
	 */
	private static function etape_titre( $etape ): string {
		if ( ! is_array( $etape ) ) {
			return (string) $etape;
		}

		return (string) ( $etape['title'] ?? $etape['label'] ?? $etape['id'] ?? '' );
	}

	/**
	 * Phrase d'explication de l'étape, si la définition en porte une.
	 *
	 * @param array<string, mixed>|string $etape Étape déclarée.
	 * @return string
	 */
	private static function etape_description( $etape ): string {
		return is_array( $etape ) ? trim( (string) ( $etape['description'] ?? '' ) ) : '';
	}

	private static function etape_libelle( $etape ): string {
		if ( ! is_array( $etape ) ) {
			return (string) $etape;
		}

		return (string) ( $etape['label'] ?? $etape['title'] ?? $etape['id'] ?? '' );
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
