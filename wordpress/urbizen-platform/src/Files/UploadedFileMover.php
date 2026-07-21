<?php
/**
 * Déplacement des fichiers téléversés.
 *
 * `move_uploaded_file()` n'est pas un `rename()` avec un autre nom : il refuse
 * tout chemin que PHP n'a pas lui-même reçu par HTTP. C'est cette garantie qui
 * empêche un `tmp_name` forgé — `/etc/passwd`, un fichier du dépôt, un chemin
 * de sauvegarde — d'être déplacé dans le stockage privé puis servi par un lien
 * signé.
 *
 * L'abstraction existe pour que les bancs d'essai puissent employer des
 * fixtures locales, qui ne sont évidemment pas des uploads HTTP. Elle n'est
 * **jamais** sélectionnable depuis une requête : ni filtre, ni option, ni
 * paramètre. Le remplacement n'est possible qu'en ligne de commande, ou sous
 * une constante définie hors du dépôt.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Contrat de déplacement d'un fichier reçu.
 */
interface UploadedFileMover {

	/**
	 * Le chemin correspond-il à un fichier réellement téléversé par HTTP ?
	 *
	 * @param string $tmp_name Chemin temporaire.
	 * @return bool
	 */
	public function is_uploaded( string $tmp_name ): bool;

	/**
	 * Déplace un fichier téléversé.
	 *
	 * @param string $tmp_name Chemin temporaire.
	 * @param string $cible    Destination.
	 * @return bool
	 */
	public function move( string $tmp_name, string $cible ): bool;
}
