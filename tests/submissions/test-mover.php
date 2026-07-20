<?php
/**
 * Déplacement de fichiers pour les bancs d'essai.
 *
 * Les fixtures ne sont pas des uploads HTTP : `is_uploaded_file()` les refuse,
 * à juste titre. Cet adaptateur permet de les employer **et uniquement elles**.
 *
 * Il n'est jamais atteignable depuis une requête : `Storage::set_mover()` exige
 * la ligne de commande ou la constante `URBIZEN_TESTING`, et aucun filtre ni
 * option WordPress n'y mène.
 */

use Urbizen\Platform\Files\UploadedFileMover;

/**
 * Adaptateur de test : n'accepte que des chemins explicitement enregistrés.
 */
final class FixtureFileMover implements UploadedFileMover {

	/**
	 * Chemins reconnus comme provenant d'un téléversement.
	 *
	 * @var array<string, bool>
	 */
	private array $autorises = array();

	/**
	 * Déclare un chemin comme provenant d'un téléversement.
	 *
	 * @param string $chemin Chemin.
	 * @return string
	 */
	public function autoriser( string $chemin ): string {
		$this->autorises[ $chemin ] = true;

		return $chemin;
	}

	/**
	 * Le chemin est-il déclaré ?
	 *
	 * @param string $tmp_name Chemin.
	 * @return bool
	 */
	public function is_uploaded( string $tmp_name ): bool {
		return isset( $this->autorises[ $tmp_name ] );
	}

	/**
	 * Déplace un chemin déclaré.
	 *
	 * Un chemin non déclaré — `/etc/passwd`, un fichier du dépôt — est refusé
	 * exactement comme `move_uploaded_file()` le refuserait.
	 *
	 * @param string $tmp_name Chemin.
	 * @param string $cible    Destination.
	 * @return bool
	 */
	public function move( string $tmp_name, string $cible ): bool {
		if ( ! $this->is_uploaded( $tmp_name ) ) {
			return false;
		}

		return @rename( $tmp_name, $cible );
	}
}
