<?php
/**
 * Profil d'upload d'un formulaire.
 *
 * Objet-valeur **immuable** décrivant *ce qui est autorisé* pour un type de
 * formulaire donné : ses blocs de dépôt, les extensions et types réels admis,
 * les quantités et tailles limites, et si les dépôts sont ouverts. Il ne valide
 * rien lui-même — {@see UploadPolicy} reste le moteur de sécurité générique ;
 * le profil ne fait que lui dire *contre quelles bornes* valider.
 *
 * Un profil est toujours construit par du **code serveur** (à partir des
 * constantes de `UploadPolicy` pour le profil par défaut, ou d'un registre pour
 * les autres) et résolu depuis le **type de formulaire de la route serveur**.
 * Aucun profil ne se construit à partir de `$_POST`, `$_FILES`, d'un attribut de
 * bloc ou d'une URL : le navigateur transmet des fichiers, jamais le profil qui
 * les juge.
 *
 * Les règles **génériques** de sécurité (MIME réel lu par `finfo`, concordance
 * extension/contenu, neutralisation du nom, dernière extension, contrôle croisé
 * WordPress) ne dépendent pas du profil : elles s'appliquent à tout dépôt.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Bornes métier d'un dépôt, sélectionnées côté serveur.
 */
final class UploadProfile {

	/**
	 * @param string                $id              Identifiant du profil (= type de formulaire).
	 * @param array<int, string>    $blocks          Blocs de dépôt autorisés (liste fermée).
	 * @param array<string, string> $types           Extensions autorisées → type réel attendu.
	 * @param int                   $max_per_block   Nombre maximal de documents par bloc.
	 * @param int                   $max_total       Nombre maximal de documents au total.
	 * @param int                   $max_file_size   Taille maximale d'un document, en octets.
	 * @param int                   $max_total_size  Taille maximale cumulée, en octets.
	 * @param bool                  $uploads_enabled Les dépôts sont-ils ouverts pour ce profil ?
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $blocks,
		public readonly array $types,
		public readonly int $max_per_block,
		public readonly int $max_total,
		public readonly int $max_file_size,
		public readonly int $max_total_size,
		public readonly bool $uploads_enabled,
	) {
	}

	/**
	 * Ce bloc est-il autorisé par le profil ?
	 *
	 * @param string $block Identifiant de bloc.
	 * @return bool
	 */
	public function has_block( string $block ): bool {
		return in_array( $block, $this->blocks, true );
	}

	/**
	 * Type réel attendu pour une extension, ou null si l'extension est refusée.
	 *
	 * @param string $extension Extension, sans point.
	 * @return string|null
	 */
	public function mime_for( string $extension ): ?string {
		return $this->types[ strtolower( $extension ) ] ?? null;
	}
}
