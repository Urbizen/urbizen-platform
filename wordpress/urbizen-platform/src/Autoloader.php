<?php
/**
 * Chargement automatique des classes (PSR-4, sans dépendance Composer).
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader minimal pour l'espace de noms Urbizen\Platform.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Enregistre l'autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Résout une classe vers un fichier de src/.
	 *
	 * @param string $class_name Nom pleinement qualifié.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = URBIZEN_PLATFORM_DIR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
