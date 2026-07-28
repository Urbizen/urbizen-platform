<?php
/**
 * Reprise serveur d'un rejet corrigeable (Lot 2, C2A).
 *
 * Objet-valeur **immuable** conservant, le temps d'une correction, **uniquement**
 * ce qui permet à une personne de reprendre sa saisie après un rejet de
 * validation métier :
 *
 * - les **valeurs nettoyées** des champs, telles que produites par le
 *   {@see \Urbizen\Platform\Forms\Validator} — jamais le POST brut ;
 * - les **erreurs publiques par champ** (codes stables, jamais la valeur
 *   fautive, jamais un message construit avec une donnée) ;
 * - une éventuelle **erreur globale publique** (code générique).
 *
 * Ce que la reprise **ne contient jamais** : POST/REQUEST brut, nonce, jeton
 * anti-robot, pot de miel, action, URL de retour, `form_type` client, prix ou
 * stratégie, profil, **aucune donnée de fichier** (ni nom, ni chemin, ni
 * contenu, ni manifeste), aucune référence de demande, aucune trace ni
 * exception, aucun code interne sensible. Le **consentement n'est pas conservé**
 * (décision C2A : il est re-confirmé à chaque soumission).
 *
 * La reprise ne porte **jamais plus de champs que la définition serveur** :
 * valeurs et erreurs sont filtrées contre les champs réellement déclarés.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Forms\FormDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs nettoyées et erreurs publiques d'un rejet corrigeable.
 */
final class SubmissionRecovery {

	/**
	 * Version du format de charge utile.
	 */
	public const VERSION = 1;

	/**
	 * Types de champs dont la VALEUR n'est jamais conservée.
	 *
	 * `file` : aucune donnée de fichier ne survit. `consent` : le consentement
	 * est re-confirmé à chaque soumission, jamais pré-rempli depuis une reprise.
	 *
	 * @var array<int, string>
	 */
	private const TYPES_EXCLUS = array( 'file', 'consent' );

	/**
	 * @param string               $form_type    Type serveur (« conception »).
	 * @param array<string, mixed> $values       Valeurs nettoyées, par nom de champ déclaré.
	 * @param array<string, string> $errors      Codes d'erreur publics, par clé de champ/famille connue.
	 * @param string               $global_error Code d'erreur globale publique, ou chaîne vide.
	 */
	private function __construct(
		public readonly string $form_type,
		public readonly array $values,
		public readonly array $errors,
		public readonly string $global_error,
	) {
	}

	/**
	 * Construit une reprise à partir du résultat de validation, **filtrée** à la
	 * définition serveur (aucun champ hors définition, ni fichier, ni consentement).
	 *
	 * @param string               $form_type Type serveur.
	 * @param FormDefinition       $def       Définition serveur.
	 * @param array<string, mixed> $clean     Valeurs nettoyées du Validator.
	 * @param array<string, string> $errors   Erreurs par champ du Validator (codes publics).
	 * @return self
	 */
	public static function from_validation( string $form_type, FormDefinition $def, array $clean, array $errors ): self {
		$types = array();

		foreach ( $def->fields() as $champ ) {
			$nom = isset( $champ['name'] ) ? (string) $champ['name'] : '';

			if ( '' !== $nom ) {
				$types[ $nom ] = isset( $champ['type'] ) ? (string) $champ['type'] : 'text';
			}
		}

		// Valeurs : uniquement des champs DÉCLARÉS, hors fichier et hors consentement.
		$values = array();

		foreach ( $clean as $nom => $valeur ) {
			$nom = (string) $nom;

			if ( ! isset( $types[ $nom ] ) || in_array( $types[ $nom ], self::TYPES_EXCLUS, true ) ) {
				continue;
			}

			$values[ $nom ] = self::valeur_sure( $valeur );
		}

		// Erreurs : clés de champs DÉCLARÉS seulement. Une clé inconnue est écartée
		// et bascule en erreur globale générique, jamais conservée telle quelle.
		$errs   = array();
		$global = '';

		foreach ( $errors as $cle => $code ) {
			$cle = (string) $cle;

			if ( isset( $types[ $cle ] ) ) {
				$errs[ $cle ] = self::code_sur( (string) $code );
			} else {
				$global = 'champs';
			}
		}

		return new self( $form_type, $values, $errs, $global );
	}

	/**
	 * Reconstruit une reprise depuis une charge stockée, en la **revalidant**.
	 * Renvoie null si la structure est corrompue ou d'une version inconnue.
	 *
	 * @param mixed $charge Charge décodée.
	 * @return self|null
	 */
	public static function from_payload( $charge ): ?self {
		if ( ! is_array( $charge ) ) {
			return null;
		}

		if ( ( $charge['v'] ?? null ) !== self::VERSION ) {
			return null;
		}

		$type = $charge['t'] ?? null;

		if ( 'conception' !== $type ) {
			return null;
		}

		$values = $charge['values'] ?? null;
		$errors = $charge['errors'] ?? null;
		$global = $charge['global'] ?? '';

		if ( ! is_array( $values ) || ! is_array( $errors ) || ! is_string( $global ) ) {
			return null;
		}

		// Revalidation défensive : valeurs sûres, erreurs = chaîne → code public.
		$values_srs = array();

		foreach ( $values as $nom => $valeur ) {
			$values_srs[ (string) $nom ] = self::valeur_sure( $valeur );
		}

		$errors_srs = array();

		foreach ( $errors as $cle => $code ) {
			if ( is_string( $code ) ) {
				$errors_srs[ (string) $cle ] = self::code_sur( $code );
			}
		}

		return new self( (string) $type, $values_srs, $errors_srs, self::code_sur( $global ) );
	}

	/**
	 * Charge utile sérialisable (pour le stockage).
	 *
	 * @return array<string, mixed>
	 */
	public function to_payload(): array {
		return array(
			'v'      => self::VERSION,
			't'      => $this->form_type,
			'values' => $this->values,
			'errors' => $this->errors,
			'global' => $this->global_error,
		);
	}

	/**
	 * Réduit une valeur à des scalaires ou des tableaux de scalaires : aucun
	 * objet, aucune ressource, aucune structure profonde inattendue ne subsiste.
	 *
	 * @param mixed $valeur Valeur candidate.
	 * @return mixed
	 */
	private static function valeur_sure( $valeur ) {
		if ( is_array( $valeur ) ) {
			$out = array();

			foreach ( $valeur as $cle => $sous ) {
				if ( is_scalar( $sous ) ) {
					$out[ (string) $cle ] = is_bool( $sous ) ? $sous : (string) $sous;
				}
			}

			return $out;
		}

		if ( is_bool( $valeur ) ) {
			return $valeur;
		}

		return is_scalar( $valeur ) ? (string) $valeur : '';
	}

	/**
	 * Normalise un code public : minuscules ASCII, chiffres et souligné ;
	 * toute autre forme est ramenée à un code générique. Aucune valeur fautive,
	 * aucun message, aucune donnée ne peut s'y glisser.
	 *
	 * @param string $code Code candidat.
	 * @return string
	 */
	private static function code_sur( string $code ): string {
		return 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $code ) ? $code : 'invalide';
	}
}
