<?php
/**
 * Rendu HTML générique d'un formulaire multi-étapes.
 *
 * C'est le moteur de rendu que le `Renderer` à plat historique n'était pas :
 * il pose chaque étape dans son `fieldset` avec sa `legend`, distingue un
 * bouton radio d'une zone de texte, et porte les conditions d'affichage
 * (`visible_if`) sans connaître aucun formulaire particulier.
 *
 * **Aucune connaissance métier.** Cette classe ignore Conception, DP, PC et le
 * Cerfa. Étapes, champs, ordre, libellés, obligations et conditions sont lus
 * dans la `FormDefinition` ; les valeurs techniques (action, nonce, jeton déjà
 * généré, pot de miel, retour, cible du formulaire, `accept` des dépôts) sont
 * reçues dans un `StepFormRenderConfig` construit **côté serveur** par
 * l'appelant. Le renderer ne choisit pas de route, ne lit aucune superglobale,
 * n'accède à aucun contrôleur : il ne fait que produire du HTML.
 *
 * **Le HTML est utilisable sans JavaScript.** Toutes les étapes sont présentes
 * dans le document ; un message `noscript` dit clairement que l'envoi exige
 * JavaScript, plutôt qu'une interface qui paraît marcher et ne le peut pas.
 *
 * **Rien n'est transmis par la seule couleur.** Chaque champ porte un message
 * d'erreur textuel, chaque groupe une `legend`, chaque obligation le mot
 * « (obligatoire) ».
 *
 * Les fragments propres à un formulaire donné (un cartouche d'en-tête, des
 * consignes de dépôt, un consentement de brouillon) sont injectés par la
 * configuration — `prelude`, `header`, `footer`, `step_extras` — sans que le
 * renderer ne les interprète.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Moteur de rendu d'un formulaire multi-étapes, sans dépendance métier.
 */
final class StepFormRenderer {

	/**
	 * Rend le formulaire complet.
	 *
	 * @param FormDefinition          $def   Définition serveur (seule source des étapes et champs).
	 * @param StepFormRenderConfig    $cfg   Configuration technique du rendu.
	 * @param StepFormRenderState|null $state Valeurs et erreurs à réafficher (reprise), ou aucune.
	 * @return string
	 */
	public static function render( FormDefinition $def, StepFormRenderConfig $cfg, ?StepFormRenderState $state = null ): string {
		// Sans reprise, l'état neutre garantit un rendu STRICTEMENT inchangé.
		$state  = $state ?? StepFormRenderState::vide();
		$etapes = $def->steps();

		$html   = array();
		$html[] = sprintf(
			// Le marqueur de mode n'apparaît qu'en aperçu (liste blanche serveur,
			// jamais issu de l'URL) ; en opérationnel l'ouverture est inchangée.
			'<div class="%s" id="%s"%s>',
			esc_attr( $cfg->root ),
			esc_attr( $cfg->instance_id ),
			$cfg->est_apercu() ? ' data-urbizen-render-mode="preview"' : ''
		);

		if ( '' !== $cfg->trusted_prelude_html ) {
			$html[] = $cfg->trusted_prelude_html;
		}

		$html[] = self::sans_script( $cfg->root );
		$html[] = $cfg->trusted_header_html;
		$html[] = self::progression( $etapes, $cfg );
		$html[] = sprintf(
			// `data-urbizen-form-instance` : identifiant d'instance **produit par le
			// serveur**, déterministe et borné (`instance_id`), présent seulement en
			// opérationnel. Il sert **uniquement** à ce que le JavaScript relie deux
			// rendus du MÊME formulaire (soumission → réponse) quand plusieurs
			// instances coexistent ; aucune confiance de sécurité ne lui est accordée
			// (nonce et jeton restent la seule frontière).
			'<form class="%1$s__form" id="%2$s-form"%4$s method="post" enctype="multipart/form-data" action="%3$s" novalidate>',
			esc_attr( $cfg->root ),
			esc_attr( $cfg->instance_id ),
			esc_url( $cfg->form_action_url ),
			$cfg->est_apercu() ? '' : sprintf( ' data-urbizen-form-instance="%s"', esc_attr( $cfg->instance_id ) )
		);

		$html[] = self::champs_techniques( $cfg );
		$html[] = self::resume_erreurs( $cfg, $def, $state );

		$dernier = count( $etapes ) - 1;

		foreach ( array_values( $etapes ) as $rang => $etape ) {
			$html[] = self::etape( $def, $etape, $rang, $cfg, $rang === $dernier, $state );
		}

		$html[] = $cfg->trusted_footer_html;
		$html[] = self::navigation( $cfg );
		$html[] = '</form>';
		$html[] = sprintf(
			'<div class="%s__annonce" role="status" aria-live="polite" id="%s-annonce"></div>',
			esc_attr( $cfg->root ),
			esc_attr( $cfg->instance_id )
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
	 * @param string $root Classe racine.
	 * @return string
	 */
	private static function sans_script( string $root ): string {
		return sprintf(
			'<noscript><p class="%s__noscript">%s</p></noscript>',
			esc_attr( $root ),
			esc_html__(
				'Ce formulaire nécessite JavaScript pour être envoyé. Les questions restent consultables ci-dessous, mais l’envoi ne fonctionnera pas tant que JavaScript est désactivé.',
				'urbizen-platform'
			)
		);
	}

	/**
	 * Liste de progression.
	 *
	 * @param array<int, mixed>    $etapes Étapes.
	 * @param StepFormRenderConfig $cfg    Configuration.
	 * @return string
	 */
	private static function progression( array $etapes, StepFormRenderConfig $cfg ): string {
		$html   = array();
		$html[] = sprintf(
			'<nav class="%s__progression" aria-label="%s"><ol>',
			esc_attr( $cfg->root ),
			esc_attr__( 'Progression du formulaire', 'urbizen-platform' )
		);

		foreach ( array_values( $etapes ) as $rang => $etape ) {
			$eid     = self::etape_id( $etape );
			$libelle = self::etape_libelle( $etape );

			$html[] = sprintf(
				'<li class="%1$s__progression-item" data-step="%2$s"%3$s><span class="%1$s__progression-rang">%4$d</span> <span class="%1$s__progression-label">%5$s</span></li>',
				esc_attr( $cfg->root ),
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
	 * Champs techniques : action, nonce, jeton, retour, pot de miel.
	 *
	 * Toutes les valeurs viennent de la configuration serveur ; aucune n'est
	 * lue dans une requête. Le pot de miel est masqué visuellement **et** retiré
	 * de l'ordre de tabulation : un utilisateur au clavier ne doit jamais tomber
	 * dedans.
	 *
	 * @param StepFormRenderConfig $cfg Configuration.
	 * @return string
	 */
	private static function champs_techniques( StepFormRenderConfig $cfg ): string {
		// En aperçu : AUCUN champ technique opérationnel. Ni action réelle, ni
		// nonce, ni jeton anti-robot, ni pot de miel exploitable — rien qui puisse
		// être posté à la route réelle. Le formulaire n'est structurellement pas
		// soumissible ; les défenses restent entières côté route serveur.
		if ( $cfg->est_apercu() ) {
			return '';
		}

		$html   = array();
		$html[] = sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $cfg->action_field ), esc_attr( $cfg->action ) );
		$html[] = wp_nonce_field( $cfg->nonce_action, $cfg->nonce_field, true, false );
		$html[] = sprintf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( $cfg->token_field ),
			esc_attr( $cfg->token )
		);
		$html[] = sprintf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( $cfg->return_field ),
			esc_url( $cfg->return_url )
		);
		$html[] = sprintf(
			'<div class="%1$s__miel" aria-hidden="true"><label for="%2$s">%3$s</label><input type="text" id="%2$s" name="%2$s" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( $cfg->root ),
			esc_attr( $cfg->honeypot_field ),
			esc_html__( 'Ne remplissez pas ce champ', 'urbizen-platform' )
		);

		return implode( "\n", $html );
	}

	/**
	 * Résumé des erreurs, avec liens vers les champs concernés.
	 *
	 * @param StepFormRenderConfig $cfg Configuration.
	 * @return string
	 */
	private static function resume_erreurs( StepFormRenderConfig $cfg, FormDefinition $def, StepFormRenderState $state ): string {
		$root = $cfg->root;
		$id   = $cfg->instance_id;

		// Sans erreurs, le résumé reste masqué et vide — sortie inchangée.
		if ( ! $state->a_des_erreurs() ) {
			return sprintf(
				'<div class="%1$s__erreurs" id="%2$s-erreurs" role="alert" aria-live="assertive" tabindex="-1" hidden><h2 class="%1$s__erreurs-titre">%3$s</h2><ul class="%1$s__erreurs-liste"></ul></div>',
				esc_attr( $root ),
				esc_attr( $id ),
				esc_html__( 'Corrigez les points suivants', 'urbizen-platform' )
			);
		}

		$items = array();

		// Erreur globale d'abord, sans lien (elle ne cible aucun champ précis).
		if ( '' !== $state->global_error ) {
			$items[] = sprintf( '<li class="%1$s__erreurs-item">%2$s</li>', esc_attr( $root ), esc_html( $state->global_error ) );
		}

		// Puis les erreurs par champ, dans l'ordre de la DÉFINITION (jamais celui
		// du POST). Les clés de famille « champ[clé] » sont regroupées sous le
		// contrôle de base. Une clé sans champ déclaré n'est jamais liée.
		foreach ( $def->fields() as $champ ) {
			$nom = isset( $champ['name'] ) ? (string) $champ['name'] : '';

			if ( '' === $nom ) {
				continue;
			}

			$libelle = (string) ( $champ['label'] ?? $nom );
			$message = $state->message( $nom );

			if ( '' === $message ) {
				continue;
			}

			$items[] = sprintf(
				'<li class="%1$s__erreurs-item"><a href="#%2$s-%3$s">%4$s</a></li>',
				esc_attr( $root ),
				esc_attr( $id ),
				esc_attr( $nom ),
				esc_html( $libelle . ' : ' . $message )
			);
		}

		return sprintf(
			'<div class="%1$s__erreurs" id="%2$s-erreurs" role="alert" aria-live="assertive" tabindex="-1" data-urbizen-error-summary="1"><h2 class="%1$s__erreurs-titre">%3$s</h2><ul class="%1$s__erreurs-liste">%4$s</ul></div>',
			esc_attr( $root ),
			esc_attr( $id ),
			esc_html__( 'Corrigez les points suivants', 'urbizen-platform' ),
			implode( '', $items )
		);
	}

	/**
	 * Rend une étape complète.
	 *
	 * @param FormDefinition       $def     Définition.
	 * @param mixed                $etape   Étape.
	 * @param int                  $rang    Rang, à partir de 0.
	 * @param StepFormRenderConfig $cfg     Configuration.
	 * @param bool                 $dernier Dernière étape.
	 * @return string
	 */
	private static function etape( FormDefinition $def, $etape, int $rang, StepFormRenderConfig $cfg, bool $dernier, StepFormRenderState $state ): string {
		$eid    = self::etape_id( $etape );
		$champs = $def->fields_for_step( $eid );
		$total  = count( $def->steps() );
		$id     = $cfg->instance_id;
		$root   = $cfg->root;

		// Le titre long est celui de la définition. Le libellé court reste au
		// rail : il y sert de repère, il ne remplace pas le vrai titre ici.
		$intitule    = self::etape_titre( $etape );
		$description = self::etape_description( $etape );
		$id_desc     = $id . '-desc-' . $eid;

		$html   = array();
		$html[] = sprintf(
			'<fieldset class="%1$s__etape" id="%2$s-etape-%3$s" data-step="%3$s" data-rang="%4$d"%5$s%6$s>',
			esc_attr( $root ),
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
			esc_attr( $root ),
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
				esc_attr( $root ),
				esc_attr( $id_desc ),
				esc_html( $description )
			);
		}

		// Fragment de confiance propre au formulaire (par ex. des consignes de
		// dépôt), inséré tel quel après la description et avant les champs.
		if ( isset( $cfg->trusted_step_extras_html[ $eid ] ) ) {
			$html[] = $cfg->trusted_step_extras_html[ $eid ];
		}

		foreach ( $champs as $champ ) {
			$html[] = self::champ( (array) $champ, $cfg, $state );
		}

		$html[] = '</fieldset>';

		return implode( "\n", $html );
	}

	/**
	 * Rend un champ, selon son type.
	 *
	 * @param array<string, mixed> $champ Champ.
	 * @param StepFormRenderConfig $cfg   Configuration.
	 * @return string
	 */
	private static function champ( array $champ, StepFormRenderConfig $cfg, StepFormRenderState $state ): string {
		$nom = (string) ( $champ['name'] ?? '' );

		if ( '' === $nom ) {
			return '';
		}

		// Message d'erreur public à réafficher pour ce champ (vide s'il n'y en a pas).
		$message = $state->message( $nom );

		$root      = $cfg->root;
		$id        = $cfg->instance_id;
		$type      = (string) ( $champ['type'] ?? 'text' );
		$libelle   = (string) ( $champ['label'] ?? $nom );
		$requis    = ! empty( $champ['required'] );
		$champ_id  = $id . '-' . $nom;
		$aide_id   = $champ_id . '-aide';
		$condition = isset( $champ['visible_if'] ) && is_array( $champ['visible_if'] ) ? $champ['visible_if'] : null;

		$attributs = sprintf(
			' class="%1$s__champ %1$s__champ--%2$s" data-field="%3$s"',
			esc_attr( $root ),
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
				esc_attr( $root ),
				esc_html( $libelle ),
				$requis ? self::marque_requis( $root ) : ''
			);
			$html[] = self::choix( $champ, $champ_id, $type, $root, $state, '' !== $message );
			$html[] = '</fieldset>';
		} else {
			$html[] = sprintf(
				'<label class="%1$s__label" for="%2$s">%3$s%4$s</label>',
				esc_attr( $root ),
				esc_attr( $champ_id ),
				esc_html( $libelle ),
				$requis ? self::marque_requis( $root ) : ''
			);
			$html[] = self::controle( $champ, $champ_id, $aide_id, $root, $cfg->file_accept, $state, '' !== $message );
		}

		// Message d'erreur par champ : conteneur masqué et vide sans erreur (rendu
		// inchangé) ; peuplé et signalé au script lorsqu'une erreur est reprise.
		$html[] = '' === $message
			? sprintf( '<p class="%1$s__erreur" id="%2$s" hidden></p>', esc_attr( $root ), esc_attr( $aide_id ) )
			: sprintf( '<p class="%1$s__erreur" id="%2$s" data-urbizen-field-error="%3$s">%4$s</p>', esc_attr( $root ), esc_attr( $aide_id ), esc_attr( $nom ), esc_html( $message ) );
		$html[] = '</div>';

		return implode( '', $html );
	}

	/**
	 * Marque textuelle d'un champ obligatoire.
	 *
	 * Un astérisque seul ne dit rien à qui ne le voit pas : le mot suit.
	 *
	 * @param string $root Classe racine.
	 * @return string
	 */
	private static function marque_requis( string $root ): string {
		return sprintf(
			' <span class="%s__requis">%s</span>',
			esc_attr( $root ),
			esc_html__( '(obligatoire)', 'urbizen-platform' )
		);
	}

	/**
	 * Groupe de boutons radio ou de cases à cocher.
	 *
	 * @param array<string, mixed> $champ    Champ.
	 * @param string               $champ_id Identifiant.
	 * @param string               $type     Type.
	 * @param string               $root     Classe racine.
	 * @return string
	 */
	private static function choix( array $champ, string $champ_id, string $type, string $root, StepFormRenderState $state, bool $invalide ): string {
		$nom     = (string) $champ['name'];
		$reprise = $state->valeur( $nom );
		$aria    = $invalide ? ' aria-invalid="true"' : '';
		$html    = array();

		foreach ( (array) ( $champ['options'] ?? array() ) as $rang => $option ) {
			$valeur  = (string) ( is_array( $option ) ? ( $option['value'] ?? '' ) : $option );
			$libelle = (string) ( is_array( $option ) ? ( $option['label'] ?? $valeur ) : $option );
			$oid     = $champ_id . '-' . $rang;
			$coche   = self::est_choisi( $type, $reprise, $valeur ) ? ' checked' : '';

			$html[] = sprintf(
				'<div class="%1$s__choix"><input type="%2$s" id="%3$s" name="%4$s%5$s" value="%6$s"%7$s%8$s><label for="%3$s">%9$s</label></div>',
				esc_attr( $root ),
				esc_attr( $type ),
				esc_attr( $oid ),
				esc_attr( $nom ),
				'checkbox' === $type ? '[]' : '',
				esc_attr( $valeur ),
				$coche,
				$aria,
				esc_html( $libelle )
			);
		}

		return implode( '', $html );
	}

	/**
	 * Une option de choix doit-elle être cochée d'après la valeur reprise ?
	 *
	 * @param string $type    « radio » ou « checkbox ».
	 * @param mixed  $reprise Valeur nettoyée reprise (scalaire ou tableau).
	 * @param string $valeur  Valeur de l'option.
	 * @return bool
	 */
	private static function est_choisi( string $type, $reprise, string $valeur ): bool {
		if ( 'checkbox' === $type ) {
			return is_array( $reprise ) && in_array( $valeur, array_map( 'strval', $reprise ), true );
		}

		return is_scalar( $reprise ) && ! is_bool( $reprise ) && (string) $reprise === $valeur;
	}

	/**
	 * Contrôle simple : texte, nombre, liste, zone, consentement, dépôt.
	 *
	 * @param array<string, mixed> $champ       Champ.
	 * @param string               $champ_id    Identifiant.
	 * @param string               $aide_id     Identifiant du message d'erreur.
	 * @param string               $root        Classe racine.
	 * @param string               $file_accept Valeur brute de l'attribut `accept` des dépôts.
	 * @param StepFormRenderState  $state       Valeurs et erreurs à réafficher.
	 * @param bool                 $invalide    Le champ porte-t-il une erreur ?
	 * @return string
	 */
	private static function controle( array $champ, string $champ_id, string $aide_id, string $root, string $file_accept, StepFormRenderState $state, bool $invalide ): string {
		$nom     = (string) $champ['name'];
		$type    = (string) ( $champ['type'] ?? 'text' );
		$reprise = $state->valeur( $nom );

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

		// aria-invalid n'est posé qu'en présence d'une erreur reprise (sinon, sortie
		// inchangée). Il reste hors du switch pour valoir pour tous les types.
		if ( $invalide ) {
			$commun .= ' aria-invalid="true"';
		}

		switch ( $type ) {
			case 'textarea':
				return sprintf( '<textarea%s rows="4">%s</textarea>', $commun, esc_html( self::scalaire( $reprise ) ) );

			case 'select':
				$html  = sprintf( '<select%s>', $commun );
				$html .= sprintf( '<option value="">%s</option>', esc_html__( 'Choisissez…', 'urbizen-platform' ) );

				foreach ( (array) ( $champ['options'] ?? array() ) as $option ) {
					$valeur  = (string) ( is_array( $option ) ? ( $option['value'] ?? '' ) : $option );
					$libelle = (string) ( is_array( $option ) ? ( $option['label'] ?? $valeur ) : $option );
					$sel     = ( is_scalar( $reprise ) && ! is_bool( $reprise ) && (string) $reprise === $valeur ) ? ' selected' : '';
					$html   .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $valeur ), $sel, esc_html( $libelle ) );
				}

				return $html . '</select>';

			case 'consent':
				// JAMAIS pré-coché : le consentement est re-confirmé à chaque envoi.
				return sprintf( '<input type="checkbox" value="1"%s>', $commun );

			case 'file':
				// JAMAIS de valeur ni de chemin : un fichier ne se restaure pas.
				return sprintf(
					'<input type="file" multiple accept="%s"%s><ul class="%s__fichiers" data-bloc="%s"></ul>',
					esc_attr( $file_accept ),
					$commun,
					esc_attr( $root ),
					esc_attr( $nom )
				);

			case 'number':
				return sprintf( '<input type="number" inputmode="numeric"%s%s>', $commun, self::attr_valeur( $reprise ) );

			default:
				return sprintf( '<input type="text"%s%s>', $commun, self::attr_valeur( $reprise ) );
		}
	}

	/**
	 * Valeur scalaire nettoyée, sous forme de chaîne — vide pour tout tableau,
	 * booléen ou valeur absente. Sert au contenu d'un `textarea`.
	 *
	 * @param mixed $reprise Valeur reprise.
	 * @return string
	 */
	private static function scalaire( $reprise ): string {
		return ( is_scalar( $reprise ) && ! is_bool( $reprise ) ) ? (string) $reprise : '';
	}

	/**
	 * Attribut `value="…"` échappé pour une valeur scalaire reprise, ou chaîne
	 * vide (aucune valeur, un booléen ou un tableau ne produisent aucun attribut).
	 *
	 * @param mixed $reprise Valeur reprise.
	 * @return string
	 */
	private static function attr_valeur( $reprise ): string {
		$scalaire = self::scalaire( $reprise );

		return '' === $scalaire ? '' : sprintf( ' value="%s"', esc_attr( $scalaire ) );
	}

	/**
	 * Boutons de navigation.
	 *
	 * `button` pour Précédent et Suivant : ils ne soumettent rien. Le seul
	 * `submit` du document apparaît à la dernière étape.
	 *
	 * @param StepFormRenderConfig $cfg Configuration.
	 * @return string
	 */
	private static function navigation( StepFormRenderConfig $cfg ): string {
		return sprintf(
			// La navigation est masquée par le serveur et révélée par le
			// script. Sans JavaScript, aucun bouton n'apparaît : le message
			// `noscript` dit que l'envoi ne fonctionnera pas, il ne doit pas
			// être démenti par un bouton « Envoyer » bien visible.
			// En aperçu, le bouton d'envoi est **désactivé** (`%6$s`) : rien à
			// soumettre. En opérationnel, `%6$s` est vide → sortie inchangée.
			'<div class="%1$s__navigation" hidden>'
				. '<button type="button" class="%1$s__bouton %1$s__bouton--precedent" data-action="precedent">%2$s</button>'
				. '<div class="%1$s__estimation" id="%3$s-estimation" aria-live="polite"></div>'
				. '<button type="button" class="%1$s__bouton %1$s__bouton--suivant" data-action="suivant">%4$s</button>'
				. '<button type="submit" class="%1$s__bouton %1$s__bouton--envoyer" data-action="envoyer"%6$s>%5$s</button>'
				. '</div>',
			esc_attr( $cfg->root ),
			esc_html__( 'Précédent', 'urbizen-platform' ),
			esc_attr( $cfg->instance_id ),
			esc_html__( 'Suivant', 'urbizen-platform' ),
			esc_html__( 'Envoyer ma demande', 'urbizen-platform' ),
			$cfg->est_apercu() ? ' disabled aria-disabled="true"' : ''
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

	/**
	 * Libellé court de l'étape, pour le rail de progression.
	 *
	 * @param array<string, mixed>|string $etape Étape déclarée.
	 * @return string
	 */
	private static function etape_libelle( $etape ): string {
		if ( ! is_array( $etape ) ) {
			return (string) $etape;
		}

		return (string) ( $etape['label'] ?? $etape['title'] ?? $etape['id'] ?? '' );
	}
}
