<?php
/**
 * Limites de téléversement du serveur.
 *
 * Un corps de requête dépassant `post_max_size` est **écarté par PHP avant que
 * le code ne s'exécute** : `$_POST` et `$_FILES` arrivent vides. Sans détection
 * précoce, la soumission se présente alors comme une requête sans nonce, et le
 * visiteur reçoit un refus de sécurité pour un fichier simplement trop lourd —
 * message trompeur, et incompréhensible pour lui.
 *
 * Cette classe lit la configuration réelle et reconnaît cette situation.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture et interprétation des limites PHP.
 */
final class PhpLimits {

	/**
	 * Convertit une valeur de configuration PHP en octets.
	 *
	 * Accepte `32M`, `1G`, `512K`, `1048576`, et `-1` pour « sans limite ».
	 *
	 * @param string|false $valeur Valeur brute.
	 * @return int Octets, ou -1 si aucune limite.
	 */
	public static function bytes( $valeur ): int {
		if ( false === $valeur || null === $valeur ) {
			return -1;
		}

		$valeur = trim( (string) $valeur );

		if ( '' === $valeur ) {
			return -1;
		}

		if ( '-1' === $valeur ) {
			return -1;
		}

		if ( 1 !== preg_match( '/^(\d+(?:\.\d+)?)\s*([kmgt]?)b?$/i', $valeur, $m ) ) {
			return -1;
		}

		$nombre = (float) $m[1];

		switch ( strtolower( $m[2] ) ) {
			case 'k':
				$nombre *= 1024;
				break;
			case 'm':
				$nombre *= 1024 ** 2;
				break;
			case 'g':
				$nombre *= 1024 ** 3;
				break;
			case 't':
				$nombre *= 1024 ** 4;
				break;
		}

		return (int) $nombre;
	}

	/**
	 * Taille maximale d'un corps de requête, en octets.
	 *
	 * @return int -1 si aucune limite.
	 */
	public static function post_max_size(): int {
		return self::bytes( ini_get( 'post_max_size' ) );
	}

	/**
	 * Taille maximale d'un fichier téléversé, en octets.
	 *
	 * @return int -1 si aucune limite.
	 */
	public static function upload_max_filesize(): int {
		return self::bytes( ini_get( 'upload_max_filesize' ) );
	}

	/**
	 * Nombre maximal de fichiers par requête.
	 *
	 * @return int
	 */
	public static function max_file_uploads(): int {
		$valeur = ini_get( 'max_file_uploads' );

		return false === $valeur || '' === $valeur ? 20 : (int) $valeur;
	}

	/**
	 * Le téléversement est-il activé ?
	 *
	 * @return bool
	 */
	public static function uploads_enabled(): bool {
		return (bool) ini_get( 'file_uploads' );
	}

	/**
	 * Répertoire temporaire réellement employé.
	 *
	 * @return string
	 */
	public static function tmp_dir(): string {
		$dir = (string) ini_get( 'upload_tmp_dir' );

		return '' !== $dir ? $dir : sys_get_temp_dir();
	}

	/**
	 * Le corps de la requête a-t-il été écarté par PHP ?
	 *
	 * Signature reconnaissable : une requête POST annonçant un corps, mais dont
	 * ni les champs ni les fichiers ne sont parvenus. PHP a vidé les deux
	 * superglobales sans rien signaler d'autre.
	 *
	 * @param array<string, mixed> $post   Données postées.
	 * @param array<string, mixed> $files  Fichiers reçus.
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @return bool
	 */
	public static function body_rejected( array $post, array $files, array $server ): bool {
		if ( array() !== $post || array() !== $files ) {
			return false;
		}

		$annonce = isset( $server['CONTENT_LENGTH'] ) && is_numeric( $server['CONTENT_LENGTH'] )
			? (int) $server['CONTENT_LENGTH']
			: 0;

		if ( $annonce <= 0 ) {
			return false;
		}

		$limite = self::post_max_size();

		return $limite > 0 && $annonce > $limite;
	}

	/**
	 * La configuration permet-elle d'appliquer la politique Urbizen ?
	 *
	 * @param int $taille_fichier Taille maximale par document, en octets.
	 * @param int $taille_totale  Taille maximale cumulée, en octets.
	 * @param int $nombre         Nombre maximal de documents.
	 * @return array<int, string> Codes des insuffisances relevées.
	 */
	public static function shortcomings( int $taille_fichier, int $taille_totale, int $nombre ): array {
		$manques = array();

		if ( ! self::uploads_enabled() ) {
			$manques[] = 'file_uploads_disabled';
		}

		$upload = self::upload_max_filesize();

		if ( $upload > 0 && $upload < $taille_fichier ) {
			$manques[] = 'upload_max_filesize_too_low';
		}

		if ( self::max_file_uploads() < $nombre ) {
			$manques[] = 'max_file_uploads_too_low';
		}

		$post = self::post_max_size();

		// Le corps ne contient pas que les fichiers : il porte aussi les champs
		// du formulaire et l'enveloppe multipart. Une marge est indispensable —
		// `post_max_size` exactement égal au volume autorisé ne suffit pas.
		if ( $post > 0 && $post <= $taille_totale ) {
			$manques[] = 'post_max_size_too_low';
		}

		$tmp = self::tmp_dir();

		if ( ! is_dir( $tmp ) || ! is_writable( $tmp ) ) {
			$manques[] = 'upload_tmp_dir_unavailable';
		}

		return $manques;
	}
}
