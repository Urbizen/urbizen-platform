<?php
/**
 * Déplacement réel d'un fichier reçu par HTTP.
 *
 * Implémentation de production. Elle n'accepte **que** des fichiers dont PHP
 * atteste lui-même l'origine : `is_uploaded_file()` avant toute opération,
 * puis `move_uploaded_file()` pour le déplacement.
 *
 * Aucun repli sur `rename()` ou `copy()` : un chemin forgé doit échouer, pas
 * être traité autrement.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Déplacement fondé sur les garanties de PHP.
 */
final class HttpUploadedFileMover implements UploadedFileMover {

	/**
	 * Le chemin correspond-il à un fichier réellement téléversé par HTTP ?
	 *
	 * @param string $tmp_name Chemin temporaire.
	 * @return bool
	 */
	public function is_uploaded( string $tmp_name ): bool {
		return '' !== $tmp_name && is_uploaded_file( $tmp_name );
	}

	/**
	 * Déplace un fichier téléversé.
	 *
	 * Le contrôle de provenance est refait ici : l'appelant a pu changer entre
	 * la vérification et le déplacement, et cette méthode doit être sûre seule.
	 *
	 * @param string $tmp_name Chemin temporaire.
	 * @param string $cible    Destination.
	 * @return bool
	 */
	public function move( string $tmp_name, string $cible ): bool {
		if ( ! $this->is_uploaded( $tmp_name ) ) {
			return false;
		}

		return @move_uploaded_file( $tmp_name, $cible );
	}
}
