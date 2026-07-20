<?php
/**
 * Stockage privé des documents.
 *
 * Les documents d'une demande — croquis, photographies, relevés — sont des
 * données personnelles. Ils ne doivent être atteignables par **aucune URL**.
 *
 * D'où le choix : un répertoire situé **hors de la racine publique**, et non
 * un dossier de `uploads` protégé par un nom difficile à deviner. Un nom
 * imprévisible n'est pas une protection : il fuit par les journaux du serveur,
 * par le `Referer`, par une sauvegarde mal placée. La seule barrière solide est
 * qu'aucun chemin d'URL ne mène au fichier.
 *
 * En l'absence d'emplacement privé sûr, le stockage **refuse**. Il ne se replie
 * jamais silencieusement sur `wp-content/uploads` : mieux vaut une soumission
 * refusée qu'un document exposé.
 *
 * Le traitement est en deux temps. Les fichiers passent d'abord dans un
 * **staging** identifié au hasard, puis sont finalisés sous la référence
 * lorsque la demande existe vraiment. Un échec à n'importe quel moment
 * n'abandonne donc jamais un fichier permanent sans demande.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Files;

use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Écriture, lecture et suppression des documents privés.
 */
final class Storage {

	/**
	 * Sous-répertoire des demandes finalisées.
	 */
	public const DIR_FINAL = 'conception';

	/**
	 * Sous-répertoire des lots en cours de traitement.
	 */
	public const DIR_STAGING = 'staging';

	/**
	 * Durée au-delà de laquelle un staging est considéré abandonné.
	 */
	public const STAGING_TTL = 3600;

	/**
	 * Permissions des répertoires : lecture, écriture et parcours par le seul
	 * propriétaire.
	 */
	public const DIR_MODE = 0700;

	/**
	 * Permissions des fichiers : lecture et écriture par le seul propriétaire.
	 */
	public const FILE_MODE = 0600;

	/**
	 * Racine privée résolue, mise en cache pour la requête.
	 *
	 * @var string|null
	 */
	private static ?string $racine = null;

	/**
	 * Stratégie de déplacement des fichiers reçus.
	 *
	 * @var UploadedFileMover|null
	 */
	private static ?UploadedFileMover $mover = null;

	/**
	 * Stratégie de déplacement en vigueur.
	 *
	 * @return UploadedFileMover
	 */
	public static function mover(): UploadedFileMover {
		if ( null === self::$mover ) {
			self::$mover = new HttpUploadedFileMover();
		}

		return self::$mover;
	}

	/**
	 * Remplace la stratégie de déplacement.
	 *
	 * **Réservé aux bancs d'essai.** Aucun filtre, aucune option et aucun
	 * paramètre de requête ne mène ici : le remplacement n'est possible qu'en
	 * ligne de commande, ou sous une constante définie hors du dépôt. Une
	 * requête HTTP ordinaire ne peut donc pas désactiver le contrôle de
	 * provenance.
	 *
	 * @param UploadedFileMover|null $mover Stratégie, ou null pour rétablir celle de production.
	 * @return bool Vrai si le remplacement a été accepté.
	 */
	public static function set_mover( ?UploadedFileMover $mover ): bool {
		if ( 'cli' !== PHP_SAPI && ! defined( 'URBIZEN_TESTING' ) ) {
			Logger::error( 'tentative de remplacement du déplacement de fichiers hors ligne de commande' );

			return false;
		}

		self::$mover = $mover;

		return true;
	}

	/**
	 * Racine privée, créée si nécessaire.
	 *
	 * Renvoie null si aucun emplacement sûr n'est disponible — auquel cas
	 * l'appelant doit refuser la soumission, pas chercher un plan B.
	 *
	 * @return string|null Chemin absolu réel, sans barre finale.
	 */
	public static function root(): ?string {
		if ( null !== self::$racine ) {
			return '' === self::$racine ? null : self::$racine;
		}

		$candidat = self::default_root();

		/**
		 * Filtre le répertoire de stockage privé.
		 *
		 * Le chemin doit se situer **hors** de la racine publique. Un chemin
		 * situé sous `ABSPATH` est refusé, quel que soit le filtre.
		 *
		 * @param string $candidat Chemin absolu.
		 */
		$candidat = (string) apply_filters( 'urbizen_private_storage_dir', $candidat );
		$candidat = rtrim( $candidat, '/' );

		if ( '' === $candidat || '/' !== substr( $candidat, 0, 1 ) ) {
			return self::indisponible( 'chemin non absolu' );
		}

		// Le contrôle décisif, **avant toute création** : refuser un chemin
		// public après l'avoir créé laisserait un répertoire dans l'arbre servi
		// par le serveur web — exactement ce qu'on cherche à éviter.
		if ( self::is_inside( $candidat, self::public_root() ) ) {
			return self::indisponible( 'chemin situé dans la racine publique' );
		}

		if ( ! is_dir( $candidat ) && ! self::mkdir( $candidat ) ) {
			return self::indisponible( 'création impossible' );
		}

		$reel = realpath( $candidat );

		if ( false === $reel ) {
			return self::indisponible( 'chemin irrésolu' );
		}

		// Second contrôle après résolution : un lien symbolique pourrait
		// ramener un chemin d'apparence privée dans la racine publique.
		if ( self::is_inside( $reel, self::public_root() ) ) {
			return self::indisponible( 'chemin résolu dans la racine publique' );
		}

		if ( ! is_writable( $reel ) ) {
			return self::indisponible( 'répertoire non inscriptible' );
		}

		self::harden( $reel );

		self::$racine = $reel;

		return $reel;
	}

	/**
	 * Emplacement privé par défaut.
	 *
	 * Déduit de l'installation : le parent de la racine publique. Aucun compte
	 * d'hébergement n'est inscrit en dur.
	 *
	 * @return string
	 */
	public static function default_root(): string {
		if ( defined( 'URBIZEN_PRIVATE_STORAGE_DIR' ) && is_string( URBIZEN_PRIVATE_STORAGE_DIR ) ) {
			return rtrim( URBIZEN_PRIVATE_STORAGE_DIR, '/' );
		}

		return dirname( self::public_root() ) . '/private/urbizen-conception';
	}

	/**
	 * Racine publique réelle.
	 *
	 * @return string
	 */
	public static function public_root(): string {
		$abs  = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$reel = realpath( $abs );

		return false === $reel ? $abs : $reel;
	}

	/**
	 * Un chemin est-il situé dans un autre ?
	 *
	 * Comparaison par préfixe **de segment** : `/a/bc` n'est pas dans `/a/b`.
	 *
	 * @param string $chemin Chemin candidat.
	 * @param string $parent Répertoire parent.
	 * @return bool
	 */
	public static function is_inside( string $chemin, string $parent ): bool {
		$chemin = rtrim( str_replace( '\\', '/', $chemin ), '/' );
		$parent = rtrim( str_replace( '\\', '/', $parent ), '/' );

		if ( '' === $parent ) {
			return false;
		}

		return $chemin === $parent || str_starts_with( $chemin . '/', $parent . '/' );
	}

	/**
	 * Ouvre un staging pour un lot.
	 *
	 * @return string|null Chemin du staging, ou null si le stockage est indisponible.
	 */
	public static function open_staging(): ?string {
		$racine = self::root();

		if ( null === $racine ) {
			return null;
		}

		$chemin = $racine . '/' . self::DIR_STAGING . '/' . self::random_id();

		return self::mkdir( $chemin ) ? $chemin : null;
	}

	/**
	 * Dépose un fichier téléversé dans le staging.
	 *
	 * @param string               $staging Répertoire de staging.
	 * @param array<string, mixed> $file    Document validé par UploadPolicy.
	 * @param int                  $rang    Rang dans le lot.
	 * @return array<string, mixed>|null Document enrichi, ou null si l'écriture échoue.
	 */
	public static function stage( string $staging, array $file, int $rang ): ?array {
		$id   = self::random_id();
		$cible = $staging . '/' . $rang . '-' . $id . '.' . $file['extension'];

		if ( ! self::move_uploaded( (string) $file['tmp_name'], $cible ) ) {
			return null;
		}

		$sha = hash_file( 'sha256', $cible );

		if ( false === $sha ) {
			self::unlink( $cible );
			return null;
		}

		$file['id']       = $id;
		$file['staged']   = $cible;
		$file['sha256']   = $sha;
		$file['size']     = (int) filesize( $cible );

		unset( $file['tmp_name'] );

		return $file;
	}

	/**
	 * Finalise un lot : déplace le staging vers le répertoire de la référence.
	 *
	 * @param string                           $staging   Répertoire de staging.
	 * @param string                           $reference Référence attribuée.
	 * @param array<int, array<string, mixed>> $files     Documents déposés.
	 * @param int                              $now       Horodatage.
	 * @return array<int, array<string, mixed>>|null Métadonnées, ou null si un déplacement échoue.
	 */
	public static function finalize( string $staging, string $reference, array $files, int $now ): ?array {
		$racine = self::root();

		if ( null === $racine || ! self::is_reference( $reference ) ) {
			return null;
		}

		$metadonnees = array();
		$deposes     = array();

		foreach ( $files as $file ) {
			$bloc = (string) $file['block'];

			if ( ! UploadPolicy::is_block( $bloc ) ) {
				self::rollback( $deposes );
				return null;
			}

			$relatif = self::DIR_FINAL . '/' . $reference . '/' . $bloc;
			$dossier = $racine . '/' . $relatif;

			if ( ! self::mkdir( $dossier ) ) {
				self::rollback( $deposes );
				return null;
			}

			$nom    = $reference . '-' . $bloc . '-' . $file['id'] . '.' . $file['extension'];
			$final  = $dossier . '/' . $nom;

			if ( ! @rename( $file['staged'], $final ) ) {
				self::rollback( $deposes );
				return null;
			}

			@chmod( $final, self::FILE_MODE );
			$deposes[] = $final;

			$metadonnees[] = array(
				'id'            => (string) $file['id'],
				'block'         => $bloc,
				'original_name' => (string) $file['original_name'],
				'stored_name'   => $nom,
				'relative_path' => $relatif . '/' . $nom,
				'extension'     => (string) $file['extension'],
				'mime'          => (string) $file['mime'],
				'size'          => (int) $file['size'],
				'sha256'        => (string) $file['sha256'],
				'stored_at_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
			);
		}

		return $metadonnees;
	}

	/**
	 * Chemin absolu d'un document, vérifié.
	 *
	 * Seul point de reconstruction d'un chemin. Il refuse toute sortie de la
	 * racine privée — `../`, chemin absolu injecté, lien symbolique.
	 *
	 * @param string $relative Chemin relatif issu des métadonnées.
	 * @return string|null Chemin absolu réel, ou null s'il est refusé.
	 */
	public static function resolve( string $relative ): ?string {
		$racine = self::root();

		if ( null === $racine || '' === $relative ) {
			return null;
		}

		// Un chemin relatif ne commence jamais par une barre et ne contient
		// jamais de remontée. Refuser plutôt que normaliser.
		if ( str_starts_with( $relative, '/' ) || str_contains( $relative, '\\' ) ) {
			return null;
		}

		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		$chemin = $racine . '/' . $relative;
		$reel   = realpath( $chemin );

		if ( false === $reel || ! self::is_inside( $reel, $racine ) ) {
			return null;
		}

		// `realpath()` suit les liens ; ce contrôle rejette le lien lui-même,
		// y compris lorsqu'il pointe à l'intérieur de la racine.
		if ( is_link( $chemin ) ) {
			return null;
		}

		return is_file( $reel ) ? $reel : null;
	}

	/**
	 * Supprime les documents d'une demande.
	 *
	 * Idempotente : un second appel ne produit ni erreur, ni effet.
	 *
	 * @param string                           $reference Référence.
	 * @param array<int, array<string, mixed>> $files     Métadonnées connues.
	 * @return int Nombre de fichiers effacés.
	 */
	public static function delete_files( string $reference, array $files ): int {
		$racine = self::root();

		if ( null === $racine || ! self::is_reference( $reference ) ) {
			return 0;
		}

		$effaces = 0;

		// Seuls les fichiers **déclarés** sont supprimés. On ne balaie jamais
		// un répertoire pour effacer ce qu'on y trouve.
		foreach ( $files as $file ) {
			$relatif = isset( $file['relative_path'] ) ? (string) $file['relative_path'] : '';

			if ( '' === $relatif ) {
				continue;
			}

			$reel = self::resolve( $relatif );

			if ( null === $reel ) {
				continue;
			}

			if ( self::unlink( $reel ) ) {
				++$effaces;
			}
		}

		self::remove_empty_dirs( $racine . '/' . self::DIR_FINAL . '/' . $reference );

		return $effaces;
	}

	/**
	 * Supprime le répertoire complet d'une référence.
	 *
	 * Réservé à la récupération d'une transaction abandonnée : la référence
	 * doit être encore `reserved`, jamais attribuée. Bornée au sous-répertoire
	 * des demandes finalisées.
	 *
	 * @param string $reference Référence.
	 * @return bool
	 */
	public static function delete_reference_dir( string $reference ): bool {
		$racine = self::root();

		if ( null === $racine || ! self::is_reference( $reference ) ) {
			return false;
		}

		$base = $racine . '/' . self::DIR_FINAL;

		return self::rmtree( $base . '/' . $reference, $base );
	}

	/**
	 * Un répertoire existe-t-il pour cette référence ?
	 *
	 * @param string $reference Référence.
	 * @return bool
	 */
	public static function has_reference_dir( string $reference ): bool {
		$racine = self::root();

		return null !== $racine && self::is_reference( $reference )
			&& is_dir( $racine . '/' . self::DIR_FINAL . '/' . $reference );
	}

	/**
	 * Supprime les stagings abandonnés.
	 *
	 * Ne touche **que** le répertoire de staging. Un document final n'est
	 * jamais supprimé au motif qu'une métadonnée semble manquante : la sécurité
	 * prime sur la récupération de quelques octets.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de stagings supprimés.
	 */
	public static function cleanup_staging( ?int $now = null ): int {
		$now    = null === $now ? time() : $now;
		$racine = self::root();

		if ( null === $racine ) {
			return 0;
		}

		$base = $racine . '/' . self::DIR_STAGING;

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		$supprimes = 0;

		foreach ( (array) scandir( $base ) as $entree ) {
			if ( '.' === $entree || '..' === $entree ) {
				continue;
			}

			$chemin = $base . '/' . $entree;

			if ( ! is_dir( $chemin ) || is_link( $chemin ) ) {
				continue;
			}

			$age = $now - (int) filemtime( $chemin );

			if ( $age < self::STAGING_TTL ) {
				continue;
			}

			if ( self::rmtree( $chemin, $base ) ) {
				++$supprimes;
			}
		}

		return $supprimes;
	}

	/**
	 * Abandonne un staging.
	 *
	 * @param string|null $staging Répertoire de staging.
	 * @return void
	 */
	public static function discard_staging( ?string $staging ): void {
		if ( null === $staging || '' === $staging ) {
			return;
		}

		$racine = self::root();

		if ( null === $racine || ! self::is_inside( $staging, $racine . '/' . self::DIR_STAGING ) ) {
			return;
		}

		self::rmtree( $staging, $racine . '/' . self::DIR_STAGING );
	}

	/**
	 * Annule des dépôts déjà finalisés.
	 *
	 * @param array<int, string> $chemins Chemins absolus.
	 * @return void
	 */
	public static function rollback( array $chemins ): void {
		foreach ( $chemins as $chemin ) {
			self::unlink( $chemin );
		}
	}

	/**
	 * Identifiant aléatoire, non séquentiel.
	 *
	 * @return string
	 */
	public static function random_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Une chaîne a-t-elle la forme d'une référence Urbizen ?
	 *
	 * @param string $reference Référence.
	 * @return bool
	 */
	public static function is_reference( string $reference ): bool {
		return 1 === preg_match( '/^URB-\d{4}-\d{4}$/', $reference );
	}

	/**
	 * Crée un répertoire avec des permissions restrictives.
	 *
	 * @param string $chemin Chemin absolu.
	 * @return bool
	 */
	private static function mkdir( string $chemin ): bool {
		if ( is_dir( $chemin ) ) {
			return true;
		}

		if ( ! @mkdir( $chemin, self::DIR_MODE, true ) && ! is_dir( $chemin ) ) {
			return false;
		}

		@chmod( $chemin, self::DIR_MODE );

		return is_dir( $chemin );
	}

	/**
	 * Déplace un fichier téléversé.
	 *
	 * Aucun repli sur `rename()` : un `tmp_name` forgé — `/etc/passwd`, un
	 * fichier du dépôt, une sauvegarde — doit **échouer**, pas être traité
	 * autrement. C'est la stratégie injectée qui atteste de la provenance.
	 *
	 * @param string $source Chemin temporaire.
	 * @param string $cible  Destination.
	 * @return bool
	 */
	private static function move_uploaded( string $source, string $cible ): bool {
		if ( ! self::mover()->move( $source, $cible ) ) {
			return false;
		}

		@chmod( $cible, self::FILE_MODE );

		return is_file( $cible );
	}

	/**
	 * Supprime un fichier, sans suivre de lien.
	 *
	 * @param string $chemin Chemin absolu.
	 * @return bool
	 */
	private static function unlink( string $chemin ): bool {
		if ( ! file_exists( $chemin ) && ! is_link( $chemin ) ) {
			return false;
		}

		return @unlink( $chemin );
	}

	/**
	 * Supprime récursivement un répertoire, borné à un parent.
	 *
	 * @param string $chemin Répertoire à supprimer.
	 * @param string $borne  Parent au-delà duquel on ne remonte jamais.
	 * @return bool
	 */
	private static function rmtree( string $chemin, string $borne ): bool {
		$reel = realpath( $chemin );

		// Double barrière : le chemin doit être sous la borne, et la borne
		// elle-même n'est jamais supprimée.
		if ( false === $reel || ! self::is_inside( $reel, $borne ) || $reel === rtrim( $borne, '/' ) ) {
			return false;
		}

		foreach ( (array) scandir( $reel ) as $entree ) {
			if ( '.' === $entree || '..' === $entree ) {
				continue;
			}

			$enfant = $reel . '/' . $entree;

			if ( is_link( $enfant ) || is_file( $enfant ) ) {
				@unlink( $enfant );
				continue;
			}

			if ( is_dir( $enfant ) ) {
				self::rmtree( $enfant, $borne );
			}
		}

		return @rmdir( $reel );
	}

	/**
	 * Supprime les répertoires devenus vides sous une référence.
	 *
	 * @param string $chemin Répertoire de la référence.
	 * @return void
	 */
	private static function remove_empty_dirs( string $chemin ): void {
		$racine = self::root();

		if ( null === $racine ) {
			return;
		}

		$base = $racine . '/' . self::DIR_FINAL;
		$reel = realpath( $chemin );

		if ( false === $reel || ! self::is_inside( $reel, $base ) || $reel === rtrim( $base, '/' ) ) {
			return;
		}

		foreach ( (array) scandir( $reel ) as $entree ) {
			if ( '.' === $entree || '..' === $entree ) {
				continue;
			}

			$enfant = $reel . '/' . $entree;

			if ( is_dir( $enfant ) && ! is_link( $enfant ) ) {
				$restant = array_diff( (array) scandir( $enfant ), array( '.', '..' ) );

				if ( array() === $restant ) {
					@rmdir( $enfant );
				}
			}
		}

		$restant = array_diff( (array) scandir( $reel ), array( '.', '..' ) );

		if ( array() === $restant ) {
			@rmdir( $reel );
		}
	}

	/**
	 * Défenses complémentaires posées dans la racine privée.
	 *
	 * Elles ne sont **pas** la protection principale — celle-ci est l'absence
	 * de chemin d'URL menant au répertoire. Elles limitent les dégâts d'une
	 * reconfiguration malheureuse du serveur.
	 *
	 * @param string $racine Racine privée.
	 * @return void
	 */
	private static function harden( string $racine ): void {
		$index = $racine . '/index.php';

		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence.\n" );
			@chmod( $index, self::FILE_MODE );
		}

		$htaccess = $racine . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			@chmod( $htaccess, self::FILE_MODE );
		}
	}

	/**
	 * Consigne l'indisponibilité du stockage et la mémorise.
	 *
	 * @param string $raison Raison technique, sans chemin.
	 * @return null
	 */
	private static function indisponible( string $raison ) {
		self::$racine = '';
		Logger::error( 'stockage privé indisponible : ' . $raison );

		return null;
	}

	/**
	 * Oublie la racine mémorisée.
	 *
	 * Réservé aux bancs d'essai : une requête WordPress ne change pas de
	 * racine en cours de route.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$racine = null;
	}
}
