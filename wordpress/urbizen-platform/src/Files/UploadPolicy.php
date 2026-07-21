<?php
/**
 * Politique des documents joints.
 *
 * Source unique de vérité : blocs autorisés, formats, correspondances entre
 * extension et type réel, nombres et tailles. Elle **valide**, elle ne stocke
 * rien — le déplacement physique appartient à `Storage`, les métadonnées au
 * repository, et le téléchargement à son propre contrôleur.
 *
 * Deux principes gouvernent la validation :
 *
 * 1. **Rien de ce que dit le navigateur n'est cru.** Le type MIME transmis
 *    dans `$_FILES` est une déclaration du client ; il est ignoré. Le type
 *    réel est lu dans le contenu du fichier par `finfo`.
 * 2. **L'extension et le contenu doivent concorder.** Un `.jpg` contenant du
 *    PHP est refusé, un PDF renommé en `.jpg` aussi. La cohérence est
 *    vérifiée dans les deux sens.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Règles applicables aux documents d'une demande de conception.
 */
final class UploadPolicy {

	/**
	 * Blocs de dépôt autorisés.
	 *
	 * Liste fermée, alignée sur `definitions/conception.php`. Le navigateur ne
	 * peut pas inventer un bloc : un identifiant hors de cette liste est
	 * écarté, jamais créé.
	 *
	 * @var array<int, string>
	 */
	public const BLOCKS = array( 'croquis_plans', 'plan_terrain', 'photos', 'inspirations_docs', 'urbanisme' );

	/**
	 * Extensions autorisées et type réel attendu pour chacune.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/**
	 * Nombre maximal de documents par bloc.
	 */
	public const MAX_PER_BLOCK = 10;

	/**
	 * Nombre maximal de documents pour l'ensemble de la demande.
	 *
	 * Aligné sur `max_file_uploads` du serveur, mesuré à 20.
	 */
	public const MAX_TOTAL = 20;

	/**
	 * Taille maximale d'un document, en octets. 10 Mio.
	 */
	public const MAX_FILE_SIZE = 10485760;

	/**
	 * Taille maximale cumulée, en octets. 25 Mio.
	 */
	public const MAX_TOTAL_SIZE = 26214400;

	/**
	 * Longueur maximale conservée d'un nom d'origine.
	 */
	public const MAX_NAME_LENGTH = 120;

	/**
	 * Blocs autorisés.
	 *
	 * @return array<int, string>
	 */
	public static function blocks(): array {
		return self::BLOCKS;
	}

	/**
	 * Extensions autorisées.
	 *
	 * @return array<int, string>
	 */
	public static function extensions(): array {
		return array_keys( self::TYPES );
	}

	/**
	 * Type réel attendu pour une extension, ou null si l'extension est refusée.
	 *
	 * @param string $extension Extension, sans point.
	 * @return string|null
	 */
	public static function mime_for( string $extension ): ?string {
		return self::TYPES[ strtolower( $extension ) ] ?? null;
	}

	/**
	 * Un bloc est-il autorisé ?
	 *
	 * @param string $block Identifiant de bloc.
	 * @return bool
	 */
	public static function is_block( string $block ): bool {
		return in_array( $block, self::BLOCKS, true );
	}

	/**
	 * Nombre maximal par bloc, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function max_per_block(): int {
		return max( 0, (int) apply_filters( 'urbizen_files_max_per_block', self::MAX_PER_BLOCK ) );
	}

	/**
	 * Nombre maximal total, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function max_total(): int {
		return max( 0, (int) apply_filters( 'urbizen_files_max_total', self::MAX_TOTAL ) );
	}

	/**
	 * Taille maximale d'un document, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function max_file_size(): int {
		return max( 1, (int) apply_filters( 'urbizen_files_max_file_size', self::MAX_FILE_SIZE ) );
	}

	/**
	 * Taille maximale cumulée, ajustable par filtre.
	 *
	 * @return int
	 */
	public static function max_total_size(): int {
		return max( 1, (int) apply_filters( 'urbizen_files_max_total_size', self::MAX_TOTAL_SIZE ) );
	}

	/**
	 * Valide un lot normalisé.
	 *
	 * Reçoit la sortie de `UploadNormalizer`, jamais `$_FILES` brut. Ne déplace
	 * aucun fichier : elle décide, elle n'agit pas.
	 *
	 * @param array<int, array<string, mixed>> $lot Documents normalisés.
	 * @return array{ok:bool,code:string,files:array<int,array<string,mixed>>,block:string}
	 */
	public static function validate( array $lot ): array {
		$par_bloc = array();
		$total    = 0;
		$retenus  = array();

		if ( count( $lot ) > self::max_total() ) {
			return self::refus( 'upload_count_exceeded' );
		}

		foreach ( $lot as $doc ) {
			$bloc = isset( $doc['block'] ) ? (string) $doc['block'] : '';

			if ( ! self::is_block( $bloc ) ) {
				return self::refus( 'upload_invalid_structure', $bloc );
			}

			$par_bloc[ $bloc ] = ( $par_bloc[ $bloc ] ?? 0 ) + 1;

			if ( $par_bloc[ $bloc ] > self::max_per_block() ) {
				return self::refus( 'upload_count_exceeded', $bloc );
			}

			$verdict = self::validate_one( $doc );

			if ( ! $verdict['ok'] ) {
				return self::refus( $verdict['code'], $bloc );
			}

			$total += (int) $verdict['file']['size'];

			if ( $total > self::max_total_size() ) {
				return self::refus( 'upload_total_size_exceeded', $bloc );
			}

			$retenus[] = $verdict['file'];
		}

		return array(
			'ok'    => true,
			'code'  => 'success',
			'files' => $retenus,
			'block' => '',
		);
	}

	/**
	 * Valide un document.
	 *
	 * @param array<string, mixed> $doc Document normalisé.
	 * @return array{ok:bool,code:string,file:array<string,mixed>}
	 */
	public static function validate_one( array $doc ): array {
		$erreur = isset( $doc['error'] ) ? (int) $doc['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $erreur ) {
			return self::refus_un( self::code_for_upload_error( $erreur ) );
		}

		$chemin = isset( $doc['tmp_name'] ) ? (string) $doc['tmp_name'] : '';

		if ( '' === $chemin || ! is_file( $chemin ) || ! is_readable( $chemin ) ) {
			return self::refus_un( 'upload_invalid_structure' );
		}

		// La taille réelle du fichier, pas celle annoncée par le navigateur.
		$taille = (int) filesize( $chemin );

		if ( $taille <= 0 ) {
			return self::refus_un( 'upload_empty_file' );
		}

		if ( $taille > self::max_file_size() ) {
			return self::refus_un( 'upload_too_large' );
		}

		$nom       = isset( $doc['name'] ) ? (string) $doc['name'] : '';
		$extension = self::extension_of( $nom );

		if ( null === self::mime_for( $extension ) ) {
			return self::refus_un( 'upload_invalid_extension' );
		}

		$reel = self::detect_mime( $chemin );

		if ( '' === $reel ) {
			return self::refus_un( 'upload_invalid_mime' );
		}

		// La concordance dans les deux sens : l'extension annonce un type, le
		// contenu en révèle un autre — l'un des deux ment, on refuse.
		if ( self::mime_for( $extension ) !== $reel ) {
			return self::refus_un( 'upload_invalid_mime' );
		}

		// Contrôle croisé par WordPress lorsqu'il est disponible : il applique
		// ses propres correspondances et peut corriger une extension trompeuse.
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$wp = wp_check_filetype_and_ext( $chemin, 'fichier.' . $extension, self::wp_mimes() );

			if ( empty( $wp['ext'] ) || empty( $wp['type'] ) ) {
				return self::refus_un( 'upload_invalid_mime' );
			}

			if ( strtolower( (string) $wp['ext'] ) !== $extension || (string) $wp['type'] !== $reel ) {
				return self::refus_un( 'upload_invalid_mime' );
			}
		}

		return array(
			'ok'   => true,
			'code' => 'success',
			'file' => array(
				'block'         => (string) $doc['block'],
				'tmp_name'      => $chemin,
				'original_name' => self::clean_name( $nom ),
				'extension'     => $extension,
				'mime'          => $reel,
				'size'          => $taille,
			),
		);
	}

	/**
	 * Extension normalisée d'un nom de fichier.
	 *
	 * Seule la **dernière** extension compte : `facture.pdf.php` a pour
	 * extension `php`, et c'est bien ainsi qu'un serveur l'exécuterait.
	 *
	 * @param string $name Nom de fichier.
	 * @return string
	 */
	public static function extension_of( string $name ): string {
		$point = strrpos( $name, '.' );

		if ( false === $point || $point === strlen( $name ) - 1 ) {
			return '';
		}

		return strtolower( substr( $name, $point + 1 ) );
	}

	/**
	 * Type réel d'un fichier, lu dans son contenu.
	 *
	 * @param string $path Chemin.
	 * @return string Type MIME, ou chaîne vide si indéterminable.
	 */
	public static function detect_mime( string $path ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return '';
		}

		$type = finfo_file( $finfo, $path );
		finfo_close( $finfo );

		return is_string( $type ) ? strtolower( $type ) : '';
	}

	/**
	 * Correspondances au format attendu par WordPress.
	 *
	 * @return array<string, string>
	 */
	public static function wp_mimes(): array {
		return array(
			'pdf'      => 'application/pdf',
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
		);
	}

	/**
	 * Nettoie un nom d'origine destiné aux métadonnées.
	 *
	 * Il ne sert qu'à ce qu'Urbizen reconnaisse un document. Il n'apparaît
	 * jamais dans un nom physique, ni dans une URL, ni dans un journal.
	 *
	 * @param string $name Nom reçu.
	 * @return string
	 */
	public static function clean_name( string $name ): string {
		// Aucun chemin ne survit : ni Unix, ni Windows.
		$name = str_replace( '\\', '/', $name );
		$name = basename( $name );
		$name = (string) preg_replace( '/[\x00-\x1f\x7f]/u', '', $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );

		if ( '' === $name ) {
			return 'document';
		}

		if ( function_exists( 'mb_substr' ) && mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME_LENGTH ) {
			return mb_substr( $name, 0, self::MAX_NAME_LENGTH, 'UTF-8' );
		}

		return strlen( $name ) > self::MAX_NAME_LENGTH ? substr( $name, 0, self::MAX_NAME_LENGTH ) : $name;
	}

	/**
	 * Code interne correspondant à une erreur de téléversement PHP.
	 *
	 * @param int $error Constante `UPLOAD_ERR_*`.
	 * @return string
	 */
	public static function code_for_upload_error( int $error ): string {
		switch ( $error ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return 'upload_too_large';

			case UPLOAD_ERR_PARTIAL:
				return 'upload_partial';

			case UPLOAD_ERR_NO_TMP_DIR:
				return 'upload_missing_tmp';

			case UPLOAD_ERR_CANT_WRITE:
				return 'upload_write_failed';

			case UPLOAD_ERR_EXTENSION:
				return 'upload_blocked';

			case UPLOAD_ERR_NO_FILE:
				// Ne devrait jamais parvenir jusqu'ici : le normaliseur écarte
				// les blocs vides. Traité par prudence.
				return 'upload_invalid_structure';

			default:
				return 'upload_invalid_structure';
		}
	}

	/**
	 * Refus d'un lot.
	 *
	 * @param string $code  Code interne.
	 * @param string $block Bloc concerné.
	 * @return array{ok:bool,code:string,files:array<int,mixed>,block:string}
	 */
	private static function refus( string $code, string $block = '' ): array {
		return array(
			'ok'    => false,
			'code'  => $code,
			'files' => array(),
			'block' => $block,
		);
	}

	/**
	 * Refus d'un document.
	 *
	 * @param string $code Code interne.
	 * @return array{ok:bool,code:string,file:array<string,mixed>}
	 */
	private static function refus_un( string $code ): array {
		return array(
			'ok'   => false,
			'code' => $code,
			'file' => array(),
		);
	}
}
