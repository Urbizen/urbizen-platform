<?php
/**
 * Validation serveur d'une soumission de formulaire.
 *
 * Ne travaille qu'à partir d'une `FormDefinition` connue. Rien de ce qui
 * arrive du navigateur ne peut créer un champ, élargir une liste fermée,
 * repousser une borne ou introduire une clé dynamique : les noms, les types,
 * les valeurs autorisées et les limites viennent tous de la définition.
 *
 * La validation du navigateur reste utile au confort de saisie. Elle n'a
 * aucune valeur probante : tout est recontrôlé ici, sans exception.
 *
 * Cette PR ne reçoit aucun fichier. Les champs de type `file` sont donc
 * reconnus et laissés de côté ; leur contenu sera contrôlé par UploadPolicy en
 * PR B2, à partir de la même déclaration.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Contrôle et nettoyage d'une soumission.
 */
final class Validator {

	/**
	 * Valeurs acceptées comme un consentement donné.
	 *
	 * @var array<int, string>
	 */
	private const CONSENTEMENT_VRAI = array( '1', 'on', 'true', 'oui', 'yes' );

	/**
	 * Valide une soumission.
	 *
	 * @param FormDefinition       $def   Définition de référence.
	 * @param array<string, mixed> $input Données brutes reçues.
	 * @return array{
	 *     valid:bool,
	 *     errors:array<string,string>,
	 *     clean:array<string,mixed>,
	 *     ignored:array<int,string>,
	 *     notes:array<int,string>,
	 *     pricing:array<string,mixed>|null
	 * }
	 */
	public static function validate( FormDefinition $def, array $input ): array {
		$errors  = array();
		$notes   = array();
		$clean   = array();
		$ignored = array();

		$declares = array_column( $def->fields(), 'name' );

		// Tout ce qui n'est pas déclaré est écarté, et nommé.
		foreach ( array_keys( $input ) as $recu ) {
			if ( ! in_array( (string) $recu, $declares, true ) ) {
				$ignored[] = (string) $recu;
			}
		}

		// --- Passe 1 : nettoyage, sans tenir compte des conditions ---
		foreach ( $def->fields() as $field ) {
			$name = $field['name'];

			if ( 'file' === $field['type'] ) {
				// Les fichiers réels appartiennent à la PR B2.
				continue;
			}

			$brut = $input[ $name ] ?? null;

			$clean[ $name ] = self::clean_field( $field, $brut, $name, $errors );
		}

		// --- Passe 2 : conditions, sur les valeurs nettoyées ---
		$actifs = array();

		foreach ( $def->fields() as $field ) {
			$actifs[ $field['name'] ] = self::is_active( $field, $clean );
		}

		// Une branche inactive n'est pas une erreur : elle est simplement
		// écartée. Un visiteur qui change d'avis ne doit pas être bloqué par
		// des valeurs restées dans le document.
		foreach ( $actifs as $name => $actif ) {
			if ( ! $actif && array_key_exists( $name, $clean ) ) {
				if ( null !== $clean[ $name ] && array() !== $clean[ $name ] ) {
					$notes[] = sprintf( 'champ « %s » écarté : branche inactive', $name );
				}

				unset( $clean[ $name ] );
				unset( $errors[ $name ] );
			}
		}

		// --- Passe 4 : champs requis, sur les seules branches actives ---
		foreach ( $def->fields() as $field ) {
			$name = $field['name'];

			if ( empty( $field['required'] ) || empty( $actifs[ $name ] ) ) {
				continue;
			}

			if ( 'file' === $field['type'] ) {
				continue;
			}

			if ( isset( $errors[ $name ] ) ) {
				continue;
			}

			if ( self::est_vide( $clean[ $name ] ?? null ) ) {
				$errors[ $name ] = 'requis';
			}
		}

		// --- Passe 5 : prix, recalculé à partir des seuls identifiants ---
		$pricing = self::compute_pricing( $def, $clean, $notes );

		return array(
			'valid'   => array() === $errors,
			'errors'  => $errors,
			'clean'   => $clean,
			'ignored' => array_values( array_unique( $ignored ) ),
			'notes'   => $notes,
			'pricing' => $pricing,
		);
	}

	/**
	 * Nettoie et contrôle un champ selon son type.
	 *
	 * @param array<string, mixed>  $field  Déclaration du champ.
	 * @param mixed                 $brut   Valeur reçue.
	 * @param string                $name   Nom du champ.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return mixed
	 */
	private static function clean_field( array $field, $brut, string $name, array &$errors ) {
		switch ( $field['type'] ) {

			case 'consent':
				return self::est_consenti( $brut );

			case 'number':
				return self::clean_number( $field, $brut, $name, $errors );

			case 'checkbox':
				return self::clean_liste( $field, $brut, $name, $errors );

			case 'radio':
			case 'select':
				return self::clean_choix( $field, $brut, $name, $errors );

			case 'textarea':
				return self::clean_texte( $field, $brut, $name, $errors, true );

			case 'text':
			case 'hidden':
			default:
				return self::clean_texte( $field, $brut, $name, $errors, false );
		}
	}

	/**
	 * Nettoie un texte : caractères de contrôle retirés, longueur bornée.
	 *
	 * Les champs susceptibles d'alimenter un en-tête de courriel — nom, adresse
	 * électronique, téléphone — sont débarrassés de tout retour chariot : c'est
	 * la parade à l'injection d'en-tête, et elle s'applique ici, à la source.
	 *
	 * @param array<string, mixed>  $field    Déclaration.
	 * @param mixed                 $brut     Valeur reçue.
	 * @param string                $name     Nom du champ.
	 * @param array<string, string> $errors   Erreurs, modifiées sur place.
	 * @param bool                  $multiligne Autoriser les sauts de ligne.
	 * @return string
	 */
	private static function clean_texte( array $field, $brut, string $name, array &$errors, bool $multiligne ): string {
		if ( ! is_scalar( $brut ) ) {
			return '';
		}

		$valeur = (string) $brut;

		// Caractères de contrôle : supprimés dans tous les cas. Le saut de
		// ligne n'est préservé que dans un champ explicitement multiligne.
		$valeur = $multiligne
			? preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $valeur )
			: preg_replace( '/[\x00-\x1f\x7f]/u', ' ', $valeur );

		$valeur = (string) $valeur;

		if ( $multiligne ) {
			$valeur = preg_replace( "/\r\n?/", "\n", $valeur );
			$valeur = preg_replace( "/\n{3,}/", "\n\n", (string) $valeur );
		} else {
			$valeur = preg_replace( '/\s{2,}/u', ' ', $valeur );
		}

		$valeur = trim( (string) $valeur );

		if ( 'email' === $name ) {
			$valeur = self::clean_email( $valeur, $errors );
		}

		$maxlength = isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 0;

		if ( $maxlength > 0 && self::longueur( $valeur ) > $maxlength ) {
			$errors[ $name ] = 'trop_long';
		}

		return $valeur;
	}

	/**
	 * Normalise et contrôle une adresse électronique.
	 *
	 * @param string                $valeur Adresse candidate.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return string
	 */
	private static function clean_email( string $valeur, array &$errors ): string {
		if ( '' === $valeur ) {
			return '';
		}

		// Aucun retour chariot ne peut subsister : cette valeur devient un
		// Reply-To. Le nettoyage textuel les a déjà retirés ; ce contrôle est
		// la seconde barrière, volontairement redondante.
		$valeur = str_replace( array( "\r", "\n" ), '', $valeur );
		$valeur = strtolower( $valeur );

		// `is_email()` est la référence de WordPress : elle refuse des adresses
		// que `filter_var` accepte (TLD absent, point final, label vide). On
		// l'utilise dès qu'elle est chargée, et on retombe sur le filtre PHP
		// dans les bancs, qui s'exécutent sans WordPress.
		$valide = function_exists( 'is_email' )
			? (bool) is_email( $valeur )
			: (bool) filter_var( $valeur, FILTER_VALIDATE_EMAIL );

		if ( ! $valide ) {
			$errors['email'] = 'email_invalide';
		}

		return $valeur;
	}

	/**
	 * Contrôle un nombre entier et ses bornes.
	 *
	 * @param array<string, mixed>  $field  Déclaration.
	 * @param mixed                 $brut   Valeur reçue.
	 * @param string                $name   Nom du champ.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return int|null
	 */
	private static function clean_number( array $field, $brut, string $name, array &$errors ) {
		// Un champ dont le pas est fractionnaire mesure quelque chose : une
		// longueur, une surface. Les autres comptent : des logements, des
		// niveaux, des panneaux. Les deux ne se valident pas de la même façon,
		// et confondre les deux est ce qui faisait rejeter « 8.5 » — le contrôle
		// historique exigeait que la valeur soit égale à sa troncature entière.
		$pas      = isset( $field['increment'] ) ? (float) $field['increment'] : 1.0;
		$decimale = $pas > 0.0 && $pas < 1.0;

		if ( ! $decimale ) {
			return self::clean_entier( $field, $brut, $name, $errors );
		}

		return self::clean_decimal( $field, $brut, $name, $errors );
	}

	/**
	 * Mesure décimale, telle qu'une personne l'écrit.
	 *
	 * Tout passe par {@see NombreLocalise} : c'est le seul chemin, et il rend un
	 * verdict explicite plutôt qu'un nombre ou `false`. Un transtypage PHP
	 * transformerait « 8,5 » en 8 sans rien signaler, et cette valeur-là finit
	 * dans un CERFA.
	 *
	 * La valeur est persistée sous sa **forme canonique**, en chaîne : point
	 * décimal, deux décimales au plus, sans zéro inutile. `8,50` devient `8.5`
	 * et `34,00` devient `34`. Une chaîne et non un flottant, pour que ce qui
	 * est relu soit exactement ce qui a été écrit — un flottant réintroduirait
	 * les surprises de représentation que l'arrondi vient d'écarter.
	 *
	 * @param array<string, mixed>  $field  Déclaration.
	 * @param mixed                 $brut   Valeur reçue.
	 * @param string                $name   Nom du champ.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return string|null
	 */
	/**
	 * Décimales que le champ déclare, par son pas.
	 *
	 * **La précision est déjà dans le contrat.** Un champ qui annonce
	 * `increment => 0.000001` dit qu'il compte au millionième ; en persister
	 * deux décimales trahit sa propre déclaration. C'est ce qui plaçait une
	 * latitude de 48,8555 à 48,86, soit six cents mètres plus loin.
	 *
	 * Aucun attribut nouveau n'est donc introduit : le pas suffisait, il n'était
	 * pas lu. Les vingt-deux champs au centième gardent exactement leurs deux
	 * décimales, et les champs au pas entier ne passent jamais par ici — ils
	 * sont comptés, pas mesurés.
	 *
	 * @param array<string, mixed> $field Déclaration du champ.
	 * @return int|null Null si le champ ne déclare rien d'exploitable.
	 */
	private static function decimales_declarees( array $field ): ?int {
		if ( ! isset( $field['increment'] ) ) {
			return null;
		}

		$pas = (float) $field['increment'];

		// Un pas nul, négatif ou supérieur à l'unité ne décrit aucune précision
		// fractionnaire : on s'en remet au défaut des mesures.
		if ( $pas <= 0.0 || $pas >= 1.0 ) {
			return null;
		}

		return (int) ceil( -log10( $pas ) );
	}

	private static function clean_decimal( array $field, $brut, string $name, array &$errors ): ?string {
		$min = isset( $field['min'] ) ? (float) $field['min'] : null;
		$max = isset( $field['max'] ) ? (float) $field['max'] : null;

		// `strict_positif` : une mesure renseignée vaut forcément plus que zéro.
		// Un bassin de 0 m de long n'est pas une mesure, c'est une case remplie
		// par habitude — et la distinguer d'un champ laissé vide compte, parce
		// que le second veut dire « je ne sais pas ».
		$strict = ! empty( $field['strict_positif'] );

		$decimales = self::decimales_declarees( $field );
		$issue     = NombreLocalise::decimal( $brut, $min, $max, $strict, $decimales );

		switch ( $issue['etat'] ) {
			case NombreLocalise::ABSENT:
				return null;

			case NombreLocalise::VALIDE:
				return NombreLocalise::canonique( (float) $issue['valeur'], $decimales );

			case NombreLocalise::BORNE:
				$errors[ $name ] = 'mesure_nulle' === $issue['raison'] ? 'mesure_nulle' : 'hors_bornes';
				return null;

			default:
				$errors[ $name ] = 'nombre_invalide';
				return null;
		}
	}

	/**
	 * Comptage entier.
	 *
	 * `3,5` panneaux n'est pas une valeur à arrondir : c'est une saisie qui n'a
	 * pas de sens, et l'arrondir inventerait une réponse.
	 *
	 * @param array<string, mixed>  $field  Déclaration.
	 * @param mixed                 $brut   Valeur reçue.
	 * @param string                $name   Nom du champ.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return int|null
	 */
	private static function clean_entier( array $field, $brut, string $name, array &$errors ): ?int {
		$min = isset( $field['min'] ) ? (int) $field['min'] : null;
		$max = isset( $field['max'] ) ? (int) $field['max'] : null;

		$issue = NombreLocalise::entier( $brut, $min, $max );

		switch ( $issue['etat'] ) {
			case NombreLocalise::ABSENT:
				return null;

			case NombreLocalise::VALIDE:
				return (int) $issue['valeur'];

			case NombreLocalise::BORNE:
				$errors[ $name ] = 'sous_borne' === $issue['raison'] ? 'sous_le_minimum' : 'au_dela_du_maximum';
				return null;

			default:
				$errors[ $name ] = 'nombre_invalide';
				return null;
		}
	}

	/**
	 * Contrôle une valeur unique appartenant à une liste fermée.
	 *
	 * @param array<string, mixed>  $field  Déclaration.
	 * @param mixed                 $brut   Valeur reçue.
	 * @param string                $name   Nom du champ.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return string
	 */
	private static function clean_choix( array $field, $brut, string $name, array &$errors ): string {
		if ( null === $brut || '' === $brut || ! is_scalar( $brut ) ) {
			return '';
		}

		$valeur   = (string) $brut;
		$permises = array_column( $field['options'] ?? array(), 'value' );

		if ( ! in_array( $valeur, $permises, true ) ) {
			$errors[ $name ] = 'hors_liste';
			return '';
		}

		return $valeur;
	}

	/**
	 * Contrôle une liste de valeurs appartenant à une liste fermée.
	 *
	 * @param array<string, mixed>  $field  Déclaration.
	 * @param mixed                 $brut   Valeurs reçues.
	 * @param string                $name   Nom du champ.
	 * @param array<string, string> $errors Erreurs, modifiées sur place.
	 * @return array<int, string>
	 */
	private static function clean_liste( array $field, $brut, string $name, array &$errors ): array {
		if ( null === $brut || '' === $brut ) {
			return array();
		}

		$recues   = is_array( $brut ) ? $brut : array( $brut );
		$permises = array_column( $field['options'] ?? array(), 'value' );
		$retenues = array();

		foreach ( $recues as $valeur ) {
			if ( ! is_scalar( $valeur ) || ! in_array( (string) $valeur, $permises, true ) ) {
				$errors[ $name ] = 'hors_liste';
				return array();
			}

			$retenues[ (string) $valeur ] = true;
		}

		// Ordre du catalogue, pas ordre de réception.
		return array_values( array_filter( $permises, static fn( $v ) => isset( $retenues[ $v ] ) ) );
	}

	/**
	 * Recalcule le prix à partir des options retenues.
	 *
	 * @param FormDefinition       $def   Définition.
	 * @param array<string, mixed> $clean Valeurs nettoyées.
	 * @param array<int, string>   $notes Notes, modifiées sur place.
	 * @return array<string, mixed>|null
	 */
	private static function compute_pricing( FormDefinition $def, array $clean, array &$notes ): ?array {
		$selection = array();
		$trouve    = false;

		foreach ( $def->fields() as $field ) {
			$options = $field['options'] ?? null;

			if ( ! is_array( $options ) || array() === $options ) {
				continue;
			}

			$porte_prix = false;

			foreach ( $options as $option ) {
				if ( isset( $option['price_id'] ) ) {
					$porte_prix = true;
					break;
				}
			}

			if ( ! $porte_prix ) {
				continue;
			}

			$trouve = true;

			foreach ( (array) ( $clean[ $field['name'] ] ?? array() ) as $valeur ) {
				foreach ( $options as $option ) {
					if ( $option['value'] === $valeur && isset( $option['price_id'] ) ) {
						$selection[] = (string) $option['price_id'];
					}
				}
			}
		}

		if ( ! $trouve ) {
			return null;
		}

		// La stratégie est résolue depuis le TYPE serveur de la définition (issue
		// de la liste blanche), jamais depuis une valeur cliente. Un formulaire
		// déclarant des options tarifées mais sans stratégie serveur ne se voit
		// inventer aucun prix et ne retombe pas sur Conception : le calcul renvoie
		// null, et le contrôleur rejette (prix indisponible) avant tout effet.
		$strategie = PricingStrategyRegistry::for_type( $def->type() );

		if ( null === $strategie ) {
			$notes[] = 'pricing_strategy_absente:' . $def->type();

			return null;
		}

		// Une stratégie dont le socle dépend des réponses reçoit celles-ci —
		// déjà nettoyées et bornées par les passes précédentes. Les autres
		// gardent le contrat historique, inchangé.
		if ( $strategie instanceof PricingStrategyContextuelle ) {
			return $strategie->calculate_with_context( $selection, $clean );
		}

		$pricing = $strategie->calculate( $selection );

		foreach ( $pricing['ignores'] as $ignore ) {
			$notes[] = 'option_inconnue:' . $ignore;
		}

		return $pricing;
	}

	/**
	 * Un champ est actif si sa condition d'affichage est satisfaite.
	 *
	 * @param array<string, mixed> $field Déclaration.
	 * @param array<string, mixed> $clean Valeurs nettoyées.
	 * @return bool
	 */
	private static function is_active( array $field, array $clean ): bool {
		if ( ! isset( $field['visible_if'] ) ) {
			return true;
		}

		$condition = $field['visible_if'];
		$reference = $clean[ $condition['field'] ] ?? null;

		if ( is_array( $reference ) ) {
			foreach ( $reference as $valeur ) {
				if ( in_array( (string) $valeur, $condition['in'], true ) ) {
					return true;
				}
			}

			return false;
		}

		return in_array( (string) $reference, $condition['in'], true );
	}

	/**
	 * Interprète une valeur de consentement.
	 *
	 * @param mixed $brut Valeur reçue.
	 * @return bool
	 */
	private static function est_consenti( $brut ): bool {
		if ( is_bool( $brut ) ) {
			return $brut;
		}

		if ( ! is_scalar( $brut ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $brut ) ), self::CONSENTEMENT_VRAI, true );
	}

	/**
	 * Vrai si une valeur nettoyée doit être considérée comme absente.
	 *
	 * @param mixed $valeur Valeur.
	 * @return bool
	 */
	private static function est_vide( $valeur ): bool {
		if ( is_bool( $valeur ) ) {
			return ! $valeur;
		}

		if ( is_array( $valeur ) ) {
			return array() === $valeur;
		}

		return null === $valeur || '' === $valeur;
	}

	/**
	 * Longueur en caractères, indépendante de l'encodage des accents.
	 *
	 * @param string $valeur Chaîne.
	 * @return int
	 */
	private static function longueur( string $valeur ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $valeur, 'UTF-8' ) : strlen( $valeur );
	}
}
