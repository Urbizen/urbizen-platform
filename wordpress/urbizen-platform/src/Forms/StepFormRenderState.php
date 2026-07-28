<?php
/**
 * État de reprise d'un formulaire multi-étapes (Lot 2, C2B).
 *
 * Objet-valeur **immuable et limité** transmis au {@see StepFormRenderer} pour
 * réafficher, après un rejet de validation corrigeable, les **valeurs déjà
 * nettoyées** et les **messages d'erreur publics** — rien d'autre.
 *
 * Il ne contient **aucune** donnée technique, aucun POST brut, aucun code
 * interne, aucune donnée de fichier. Les messages qu'il porte sont **déjà
 * présentés** (publics, sûrs, échappables) : le renderer générique ne connaît ni
 * catalogue de codes, ni règle de validation. Les valeurs sont **déjà
 * nettoyées** par le serveur.
 *
 * En l'absence de reprise ({@see self::vide()}), le renderer produit exactement
 * la même sortie qu'auparavant : aucune valeur, aucune erreur, résumé masqué.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs nettoyées et messages d'erreur publics à réafficher.
 */
final class StepFormRenderState {

	/**
	 * @param bool                  $has_recovery Une reprise de validation est-elle active ?
	 * @param array<string, mixed>  $values       Valeurs nettoyées, par nom de champ.
	 * @param array<string, string> $errors       Messages publics, par clé de champ (ou « champ[clé] »).
	 * @param string                $global_error Message public global, ou chaîne vide.
	 */
	private function __construct(
		public readonly bool $has_recovery,
		public readonly array $values,
		public readonly array $errors,
		public readonly string $global_error,
	) {
	}

	/**
	 * État neutre : aucun réaffichage (rendu strictement inchangé).
	 *
	 * @return self
	 */
	public static function vide(): self {
		return new self( false, array(), array(), '' );
	}

	/**
	 * État de reprise après un rejet de validation.
	 *
	 * @param array<string, mixed>  $values       Valeurs nettoyées.
	 * @param array<string, string> $errors       Messages publics par clé.
	 * @param string                $global_error Message public global, ou chaîne vide.
	 * @return self
	 */
	public static function reprise( array $values, array $errors, string $global_error = '' ): self {
		return new self( true, $values, $errors, $global_error );
	}

	/**
	 * Valeur nettoyée d'un champ, ou null.
	 *
	 * @param string $nom Nom de champ.
	 * @return mixed
	 */
	public function valeur( string $nom ) {
		return $this->values[ $nom ] ?? null;
	}

	/**
	 * Une valeur est-elle disponible pour ce champ ?
	 *
	 * @param string $nom Nom de champ.
	 * @return bool
	 */
	public function a_valeur( string $nom ): bool {
		return array_key_exists( $nom, $this->values );
	}

	/**
	 * Message d'erreur public d'une clé, ou chaîne vide.
	 *
	 * @param string $cle Clé de champ (ou « champ[clé] »).
	 * @return string
	 */
	public function message( string $cle ): string {
		return isset( $this->errors[ $cle ] ) && is_string( $this->errors[ $cle ] ) ? $this->errors[ $cle ] : '';
	}

	/**
	 * Existe-t-il au moins une erreur (par champ ou globale) à afficher ?
	 *
	 * @return bool
	 */
	public function a_des_erreurs(): bool {
		return $this->has_recovery && ( array() !== $this->errors || '' !== $this->global_error );
	}
}
