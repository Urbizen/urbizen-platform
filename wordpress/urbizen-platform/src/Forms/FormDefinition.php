<?php
/**
 * Définition d'un formulaire Urbizen.
 *
 * Objet de données immuable : un identifiant, un titre, un intitulé de bouton,
 * une liste d'étapes facultative et une liste de champs.
 *
 * L'extension du 20/07/2026 est **additive** : un formulaire sans `steps` et
 * n'employant que `text`, `number` et `hidden` se charge exactement comme
 * auparavant. C'est le cas de `localisation`, qui ne doit connaître aucune
 * évolution fonctionnelle.
 *
 * Deux principes gouvernent cette classe :
 *
 * 1. **Aucun écran fatal.** Une définition mal écrite ne casse pas la page :
 *    les champs fautifs sont écartés et la raison est consignée dans
 *    `errors()`, que les bancs d'essai et le journal peuvent lire.
 * 2. **Aucun filtrage silencieux.** Tout ce qui est écarté — champ invalide,
 *    clé inconnue, doublon, étape inexistante — laisse une trace nommée.
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
	public const TYPES = array(
		'text',
		'number',
		'hidden',
		'radio',
		'checkbox',
		'select',
		'textarea',
		'file',
		'consent',
	);

	/**
	 * Types dont les valeurs proviennent d'une liste fermée déclarée.
	 */
	public const TYPES_A_OPTIONS = array( 'radio', 'checkbox', 'select' );

	/**
	 * Clés reconnues dans un champ. Toute autre clé est écartée et consignée :
	 * une faute de frappe dans une définition doit se voir, pas se taire.
	 *
	 * `step` désigne l'étape d'appartenance. L'incrément HTML des champs
	 * numériques porte le nom distinct `increment`, afin qu'aucune définition
	 * n'ait à faire porter deux sens au même mot.
	 */
	public const FIELD_KEYS = array(
		// Socle commun.
		'name',
		'type',
		'label',
		'required',
		'help',
		'note',
		// Étape d'appartenance.
		'step',
		// Listes fermées.
		'options',
		'multiple',
		// Bornes.
		'min',
		'max',
		'maxlength',
		'increment',
		// Affichage conditionnel.
		'visible_if',
		// Tarification.
		'price_id',
		'quote_only',
		// Dépôts de fichiers.
		'accept',
		'max_files',
		'max_size',
		// Familles de champs dynamiques (surfaces par pièce).
		'family',
		'keys',
		'total_max',
		// Reprise du contrat cadastre et confort de saisie.
		'from',
		'unit',
		'inputmode',
		'autocomplete',
		'placeholder',
		'rows',
	);

	/**
	 * Clés reconnues dans une étape.
	 */
	public const STEP_KEYS = array( 'id', 'label', 'title', 'description' );

	/**
	 * Forme d'un identifiant : minuscules, chiffres, tirets bas. Sans accent,
	 * sans espace, stable dans une URL comme dans une clé de tableau.
	 */
	public const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

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
	 * Étapes déclarées, dans l'ordre. Vide pour un formulaire d'un seul tenant.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $steps;

	/**
	 * Champs, dans l'ordre de rendu.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Anomalies rencontrées au chargement, en clair.
	 *
	 * @var array<int, string>
	 */
	private array $errors = array();

	/**
	 * Constructeur.
	 *
	 * @param string                           $type         Identifiant.
	 * @param string                           $title        Titre affiché.
	 * @param string                           $submit_label Intitulé du bouton.
	 * @param array<int, array<string, mixed>> $fields       Champs déclarés.
	 * @param array<int, array<string, mixed>> $steps        Étapes déclarées.
	 */
	public function __construct(
		string $type,
		string $title,
		string $submit_label,
		array $fields,
		array $steps = array()
	) {
		$this->type         = $type;
		$this->title        = $title;
		$this->submit_label = $submit_label;
		$this->steps        = $this->normalize_steps( $steps );
		$this->fields       = $this->normalize_fields( $fields );
	}

	/**
	 * Normalise et contrôle les étapes.
	 *
	 * @param array<int, array<string, mixed>> $steps Étapes candidates.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_steps( array $steps ): array {
		$out = array();
		$vus = array();

		foreach ( $steps as $rang => $step ) {
			if ( ! is_array( $step ) || ! isset( $step['id'] ) || ! is_string( $step['id'] ) ) {
				$this->errors[] = sprintf( 'étape #%d : identifiant absent', (int) $rang );
				continue;
			}

			$id = $step['id'];

			if ( ! preg_match( self::ID_PATTERN, $id ) ) {
				$this->errors[] = sprintf( 'étape « %s » : identifiant invalide', $id );
				continue;
			}

			if ( isset( $vus[ $id ] ) ) {
				$this->errors[] = sprintf( 'étape « %s » : identifiant en double', $id );
				continue;
			}

			$inconnues = array_diff( array_keys( $step ), self::STEP_KEYS );

			foreach ( $inconnues as $cle ) {
				$this->errors[] = sprintf( 'étape « %s » : clé inconnue « %s » écartée', $id, (string) $cle );
			}

			$vus[ $id ] = true;
			$propre     = array( 'id' => $id );

			foreach ( array( 'label', 'title', 'description' ) as $cle ) {
				$propre[ $cle ] = isset( $step[ $cle ] ) ? (string) $step[ $cle ] : '';
			}

			$out[] = $propre;
		}

		return $out;
	}

	/**
	 * Normalise et contrôle les champs.
	 *
	 * @param array<int, array<string, mixed>> $fields Champs candidats.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_fields( array $fields ): array {
		$out      = array();
		$vus      = array();
		$etapes   = $this->step_ids();
		$a_etapes = array() !== $etapes;

		foreach ( $fields as $rang => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) || ! is_string( $field['name'] ) ) {
				$this->errors[] = sprintf( 'champ #%d : nom absent', (int) $rang );
				continue;
			}

			$name = $field['name'];

			if ( ! preg_match( self::ID_PATTERN, $name ) ) {
				$this->errors[] = sprintf( 'champ « %s » : identifiant invalide', $name );
				continue;
			}

			if ( isset( $vus[ $name ] ) ) {
				$this->errors[] = sprintf( 'champ « %s » : identifiant en double', $name );
				continue;
			}

			if ( ! isset( $field['type'] ) || ! in_array( $field['type'], self::TYPES, true ) ) {
				$this->errors[] = sprintf(
					'champ « %s » : type « %s » refusé',
					$name,
					is_string( $field['type'] ?? null ) ? $field['type'] : gettype( $field['type'] ?? null )
				);
				continue;
			}

			$type = (string) $field['type'];

			// Clés inconnues : écartées, mais jamais en silence.
			$inconnues = array_diff( array_keys( $field ), self::FIELD_KEYS );

			foreach ( $inconnues as $cle ) {
				$this->errors[] = sprintf( 'champ « %s » : clé inconnue « %s » écartée', $name, (string) $cle );
			}

			$propre = array_intersect_key( $field, array_flip( self::FIELD_KEYS ) );

			// Une liste fermée sans liste n'est pas une liste fermée.
			if ( in_array( $type, self::TYPES_A_OPTIONS, true ) ) {
				$options = $this->normalize_options( $name, $propre['options'] ?? null );

				if ( array() === $options ) {
					$this->errors[] = sprintf( 'champ « %s » : liste fermée vide, champ écarté', $name );
					continue;
				}

				$propre['options'] = $options;
			} elseif ( isset( $propre['options'] ) ) {
				$this->errors[] = sprintf( 'champ « %s » : options ignorées sur un type « %s »', $name, $type );
				unset( $propre['options'] );
			}

			// Appartenance à une étape : contrôlée seulement si des étapes existent.
			if ( $a_etapes ) {
				$step = isset( $propre['step'] ) ? (string) $propre['step'] : '';

				if ( ! in_array( $step, $etapes, true ) ) {
					$this->errors[] = sprintf( 'champ « %s » : étape « %s » inconnue', $name, $step );
					continue;
				}

				$propre['step'] = $step;
			}

			$propre['visible_if'] = $this->normalize_condition( $name, $propre['visible_if'] ?? null );

			if ( null === $propre['visible_if'] ) {
				unset( $propre['visible_if'] );
			}

			$vus[ $name ] = true;
			$out[]        = $propre;
		}

		// Une condition ne peut viser qu'un champ réellement déclaré.
		$noms = array_column( $out, 'name' );

		foreach ( $out as $champ ) {
			if ( isset( $champ['visible_if'] ) && ! in_array( $champ['visible_if']['field'], $noms, true ) ) {
				$this->errors[] = sprintf(
					'champ « %s » : condition sur « %s », champ inexistant',
					$champ['name'],
					$champ['visible_if']['field']
				);
			}
		}

		return $out;
	}

	/**
	 * Normalise une liste fermée.
	 *
	 * Chaque entrée devient `array( 'value' => …, 'label' => …, … )`. Une valeur
	 * qui n'est pas un identifiant stable est refusée : les valeurs transitent
	 * par le navigateur, elles doivent être comparables sans ambiguïté.
	 *
	 * @param string $name    Nom du champ, pour les messages.
	 * @param mixed  $options Options candidates.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_options( string $name, $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$out = array();
		$vus = array();

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['value'] ) || ! is_string( $option['value'] ) ) {
				$this->errors[] = sprintf( 'champ « %s » : option sans valeur', $name );
				continue;
			}

			$value = $option['value'];

			if ( ! preg_match( self::ID_PATTERN, $value ) ) {
				$this->errors[] = sprintf( 'champ « %s » : valeur « %s » invalide', $name, $value );
				continue;
			}

			if ( isset( $vus[ $value ] ) ) {
				$this->errors[] = sprintf( 'champ « %s » : valeur « %s » en double', $name, $value );
				continue;
			}

			$vus[ $value ] = true;
			$propre        = array(
				'value' => $value,
				'label' => isset( $option['label'] ) ? (string) $option['label'] : $value,
			);

			foreach ( array( 'price_id', 'quote_only', 'help' ) as $cle ) {
				if ( isset( $option[ $cle ] ) ) {
					$propre[ $cle ] = $option[ $cle ];
				}
			}

			$out[] = $propre;
		}

		return $out;
	}

	/**
	 * Normalise une condition d'affichage.
	 *
	 * Forme retenue : `array( 'field' => …, 'in' => array( … ) )`. La forme
	 * abrégée `array( 'field' => …, 'value' => … )` est acceptée et convertie.
	 *
	 * @param string $name      Nom du champ, pour les messages.
	 * @param mixed  $condition Condition candidate.
	 * @return array{field:string,in:array<int,string>}|null
	 */
	private function normalize_condition( string $name, $condition ): ?array {
		if ( null === $condition ) {
			return null;
		}

		if ( ! is_array( $condition ) || ! isset( $condition['field'] ) || ! is_string( $condition['field'] ) ) {
			$this->errors[] = sprintf( 'champ « %s » : condition sans champ de référence', $name );
			return null;
		}

		$valeurs = array();

		if ( isset( $condition['in'] ) && is_array( $condition['in'] ) ) {
			$valeurs = $condition['in'];
		} elseif ( array_key_exists( 'value', $condition ) ) {
			$valeurs = array( $condition['value'] );
		}

		$valeurs = array_values( array_unique( array_map( 'strval', $valeurs ) ) );

		if ( array() === $valeurs ) {
			$this->errors[] = sprintf( 'champ « %s » : condition sans valeur attendue', $name );
			return null;
		}

		return array(
			'field' => $condition['field'],
			'in'    => $valeurs,
		);
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
	 * Étapes déclarées, dans l'ordre.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function steps(): array {
		return $this->steps;
	}

	/**
	 * Identifiants des étapes, dans l'ordre.
	 *
	 * @return array<int, string>
	 */
	public function step_ids(): array {
		return array_column( $this->steps, 'id' );
	}

	/**
	 * Champs d'une étape donnée, dans l'ordre.
	 *
	 * @param string $step Identifiant d'étape.
	 * @return array<int, array<string, mixed>>
	 */
	public function fields_for_step( string $step ): array {
		return array_values(
			array_filter( $this->fields, static fn( $f ) => ( $f['step'] ?? '' ) === $step )
		);
	}

	/**
	 * Un champ par son nom.
	 *
	 * @param string $name Nom du champ.
	 * @return array<string, mixed>|null
	 */
	public function field( string $name ): ?array {
		foreach ( $this->fields as $field ) {
			if ( $field['name'] === $name ) {
				return $field;
			}
		}

		return null;
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

	/**
	 * Anomalies relevées au chargement.
	 *
	 * @return array<int, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Vrai si la définition s'est chargée sans aucune anomalie.
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}
}
