<?php
/**
 * Définition d'un formulaire Urbizen.
 *
 * Objet de données immuable : un identifiant, un titre, un intitulé de bouton
 * et une liste de champs. Volontairement dépourvu de moteur générique — un
 * seul formulaire existe à ce jour (localisation). L'abstraction ne sera
 * étendue que lorsqu'un deuxième cas concret l'exigera.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Description déclarative d'un formulaire.
 */
final class FormDefinition {

	/**
	 * Types de champs acceptés. Tout autre type est refusé au chargement :
	 * le rendu ne doit jamais dépendre d'une valeur inattendue.
	 */
	public const TYPES = array( 'text', 'number', 'hidden' );

	/**
	 * Identifiant du formulaire (ex. « localisation »).
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Titre affiché.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Intitulé du bouton de validation.
	 *
	 * @var string
	 */
	private string $submit_label;

	/**
	 * Champs, dans l'ordre de rendu.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Constructeur.
	 *
	 * @param string                            $type         Identifiant.
	 * @param string                            $title        Titre affiché.
	 * @param string                            $submit_label Intitulé du bouton.
	 * @param array<int, array<string, mixed>>  $fields       Champs déclarés.
	 */
	public function __construct( string $type, string $title, string $submit_label, array $fields ) {
		$this->type         = $type;
		$this->title        = $title;
		$this->submit_label = $submit_label;
		$this->fields       = array_values( array_filter( $fields, array( self::class, 'is_valid_field' ) ) );
	}

	/**
	 * Un champ est retenu s'il est complet et d'un type connu.
	 *
	 * @param mixed $field Champ candidat.
	 * @return bool
	 */
	private static function is_valid_field( $field ): bool {
		return is_array( $field )
			&& isset( $field['name'], $field['type'] )
			&& is_string( $field['name'] )
			&& in_array( $field['type'], self::TYPES, true );
	}

	/**
	 * Identifiant du formulaire.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Titre affiché.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Intitulé du bouton de validation.
	 */
	public function submit_label(): string {
		return $this->submit_label;
	}

	/**
	 * Tous les champs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Champs visibles, dans l'ordre.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function visible_fields(): array {
		return array_values(
			array_filter( $this->fields, static fn( $f ) => 'hidden' !== $f['type'] )
		);
	}

	/**
	 * Champs techniques masqués.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function hidden_fields(): array {
		return array_values(
			array_filter( $this->fields, static fn( $f ) => 'hidden' === $f['type'] )
		);
	}
}
