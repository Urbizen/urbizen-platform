<?php
/**
 * Rendu serveur d'un formulaire Urbizen.
 *
 * Produit du HTML entièrement échappé. Les noms, types et attributs des champs
 * viennent **exclusivement** de la définition PHP : aucune valeur reçue du
 * navigateur ne peut créer ou renommer un champ.
 *
 * En version 0.4.0 le formulaire n'est **pas** soumis : il n'y a ni `action`,
 * ni `method`, ni nonce, ni route REST. La validation est locale et le résultat
 * est publié par un événement. Le futur point de soumission devra revalider
 * l'intégralité des champs côté serveur, sans faire la moindre confiance aux
 * champs masqués.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Rendu HTML d'une définition de formulaire.
 */
final class Renderer {

	/**
	 * Compteur d'instances rendues dans la page courante.
	 *
	 * Garantit des identifiants HTML uniques **dès le rendu serveur** : le HTML
	 * est valide avant toute exécution de JavaScript, et un libellé cliqué sans
	 * JavaScript vise bien son propre champ.
	 *
	 * @var int
	 */
	private static int $instance = 0;

	/**
	 * Rend le formulaire complet.
	 *
	 * @param FormDefinition       $def     Définition.
	 * @param array<string,string> $options Options d'instance (clé de stockage, identifiant).
	 * @return string HTML échappé.
	 */
	public static function render( FormDefinition $def, array $options = array() ): string {
		// Un formulaire en étapes exige un rendu que cette classe ne sait pas
		// produire : elle poserait tous les champs à plat, sans distinguer un
		// bouton radio d'un champ texte. Plutôt qu'un formulaire trompeur, on
		// ne rend rien tant que StepRenderer n'existe pas.
		if ( array() !== $def->steps() ) {
			Logger::error(
				sprintf( 'rendu refusé : le formulaire « %s » est en étapes', $def->type() )
			);

			return '';
		}

		$storage_key = isset( $options['storageKey'] ) ? (string) $options['storageKey'] : 'parcel';
		$form_id     = isset( $options['formId'] ) ? (string) $options['formId'] : '';

		$prefix = 'uf-' . ( ++self::$instance );

		$html  = '<div class="urbizen-form"';
		$html .= ' data-urbizen-form="1"';
		// Signale au JavaScript que les identifiants sont déjà uniques : il ne
		// doit pas en ajouter un second préfixe.
		$html .= ' data-uf-instance="' . esc_attr( $prefix ) . '"';
		$html .= ' data-form-type="' . esc_attr( $def->type() ) . '"';
		$html .= ' data-storage-key="' . esc_attr( $storage_key ) . '"';

		if ( '' !== $form_id ) {
			$html .= ' data-form-id="' . esc_attr( $form_id ) . '"';
		}

		$html .= '>';

		if ( '' !== $def->title() ) {
			$html .= '<h3 class="uf-title">' . esc_html( $def->title() ) . '</h3>';
		}

		// Résumé de la localisation reprise. Rempli par le JavaScript, en
		// textContent : jamais d'innerHTML sur une donnée.
		$html .= '<div class="uf-summary" hidden>';
		$html .= '<p class="uf-summary-line uf-summary-address"></p>';
		$html .= '<p class="uf-summary-line uf-summary-parcel"></p>';
		$html .= '<button type="button" class="uf-edit">'
			. esc_html__( 'Modifier l’adresse', 'urbizen-platform' ) . '</button>';
		$html .= '</div>';

		// État technique non bloquant (ex. codes commune divergents).
		$html .= '<p class="uf-notice" role="status" hidden></p>';

		// Aucun `action` ni `method` : rien n'est soumis à cette étape.
		$html .= '<form class="uf-form" novalidate>';
		$html .= self::render_fields( $def, $prefix );
		$html .= '<div class="uf-actions">';
		$html .= '<button type="submit" class="uf-submit">' . esc_html( $def->submit_label() ) . '</button>';
		$html .= '<button type="button" class="uf-clear">'
			. esc_html__( 'Effacer mes données de localisation', 'urbizen-platform' ) . '</button>';
		$html .= '</div>';
		$html .= '<div class="uf-result" role="status" hidden></div>';
		$html .= '</form>';

		$html .= '<noscript><p class="uf-noscript">';
		$html .= esc_html__(
			'Ce formulaire nécessite JavaScript pour reprendre la localisation choisie sur la carte. Activez-le, ou indiquez votre adresse et vos références cadastrales lors de votre prise de contact.',
			'urbizen-platform'
		);
		$html .= '</p></noscript>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Rend les champs, visibles puis masqués.
	 *
	 * @param FormDefinition $def    Définition.
	 * @param string         $prefix Préfixe d'instance.
	 * @return string HTML échappé.
	 */
	private static function render_fields( FormDefinition $def, string $prefix ): string {
		$html = '<div class="uf-fields">';

		foreach ( $def->visible_fields() as $field ) {
			$html .= self::render_visible_field( $field, $prefix );
		}

		$html .= '</div>';

		$hidden = $def->hidden_fields();

		if ( $hidden ) {
			// Rassemblés dans un bloc inspectable : masqués à l'écran, mais
			// lisibles dans l'inspecteur, comme demandé pour les tests.
			$html .= '<div class="uf-technical" hidden data-urbizen-technical="1">';

			foreach ( $hidden as $field ) {
				$html .= sprintf(
					'<input type="hidden" name="%s" data-from="%s" value="" />',
					esc_attr( $field['name'] ),
					esc_attr( (string) ( $field['from'] ?? '' ) )
				);
			}

			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Rend un champ visible avec son étiquette.
	 *
	 * @param array<string, mixed> $field  Champ.
	 * @param string               $prefix Préfixe d'instance.
	 * @return string HTML échappé.
	 */
	private static function render_visible_field( array $field, string $prefix ): string {
		$name     = (string) $field['name'];
		$id       = $prefix . '-' . $name;
		$error_id = $id . '-error';
		$type     = 'number' === $field['type'] ? 'number' : 'text';

		$attrs = array(
			'type'      => $type,
			'id'        => $id,
			'name'      => $name,
			'class'     => 'uf-input',
			'value'     => '',
			'data-from' => (string) ( $field['from'] ?? '' ),
		);

		// Clé de définition => attribut HTML. `increment` porte l'incrément des
		// champs numériques : depuis l'introduction des formulaires en étapes,
		// `step` désigne l'étape d'appartenance et ne peut plus servir ici.
		$passants = array(
			'maxlength'    => 'maxlength',
			'inputmode'    => 'inputmode',
			'min'          => 'min',
			'max'          => 'max',
			'increment'    => 'step',
			'autocomplete' => 'autocomplete',
			'placeholder'  => 'placeholder',
		);

		foreach ( $passants as $key => $attribut ) {
			if ( isset( $field[ $key ] ) ) {
				$attrs[ $attribut ] = (string) $field[ $key ];
			}
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs['required'] = 'required';
		}

		$note_id  = '';
		$described = array();

		if ( ! empty( $field['note'] ) ) {
			$note_id     = $id . '-note';
			$described[] = $note_id;
		}

		// Le message d'erreur est TOUJOURS référencé, même vide : le champ et
		// son message restent liés, et le JavaScript n'a qu'à remplir le texte.
		$described[]               = $error_id;
		$attrs['aria-describedby'] = implode( ' ', $described );

		$html  = '<div class="uf-field uf-field-' . esc_attr( $name ) . '">';
		$html .= '<label class="uf-label" for="' . esc_attr( $id ) . '">' . esc_html( (string) $field['label'] );

		if ( ! empty( $field['required'] ) ) {
			$html .= ' <span class="uf-required" aria-hidden="true">*</span>';
		}

		$html .= '</label>';

		$html .= '<div class="uf-control">';
		$html .= '<input';

		foreach ( $attrs as $key => $value ) {
			$html .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		$html .= ' />';

		if ( ! empty( $field['unit'] ) ) {
			$html .= '<span class="uf-unit">' . esc_html( (string) $field['unit'] ) . '</span>';
		}

		$html .= '</div>';

		if ( '' !== $note_id ) {
			$html .= '<p class="uf-note" id="' . esc_attr( $note_id ) . '">'
				. esc_html( (string) $field['note'] ) . '</p>';
		}

		// aria-live plutôt que role="alert" : le message est annoncé quand il
		// apparaît, sans interrompre la saisie ni se répéter à chaque frappe.
		$html .= '<p class="uf-error" id="' . esc_attr( $error_id ) . '" aria-live="polite" hidden></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Réinitialise le compteur d'instances.
	 *
	 * Réservé aux tests : une requête WordPress rend une page puis s'achève.
	 *
	 * @return void
	 */
	public static function reset_instances(): void {
		self::$instance = 0;
	}
}
