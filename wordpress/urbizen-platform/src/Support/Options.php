<?php
/**
 * Accès centralisé aux réglages de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture des réglages Urbizen.
 *
 * Une seule option WordPress est utilisée (tableau), afin de ne pas disperser
 * la configuration. Aucun secret n'y est stocké : les identifiants SMTP et la
 * clé de signature du backend Python sont définis comme constantes dans
 * wp-config.php, hors du dépôt Git.
 *
 * Étape 1 : lecture seule, aucune écriture, aucune option créée.
 */
final class Options {

	public const OPTION_KEY = 'urbizen_platform_settings';

	/**
	 * Valeurs par défaut.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'backend_endpoint'     => '',
			'backend_timeout'      => 60,
			'retention_months'     => 12,
			'max_file_size'        => 12 * 1024 * 1024,
			'max_files'            => 15,
			'allowed_extensions'   => array( 'pdf', 'jpg', 'jpeg', 'png', 'heic' ),
			'antispam_min_seconds' => 4,
			'notify_to'            => '',
		);
	}

	/**
	 * Retourne un réglage.
	 *
	 * @param string $key     Clé.
	 * @param mixed  $default Valeur de repli.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = get_option( self::OPTION_KEY, array() );
		$defaults = self::defaults();

		if ( is_array( $settings ) && array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		if ( array_key_exists( $key, $defaults ) ) {
			return $defaults[ $key ];
		}

		return $default;
	}

	/**
	 * Retourne l'URL du service Python, en privilégiant la constante.
	 */
	public static function backend_endpoint(): string {
		if ( defined( 'URBIZEN_BACKEND_ENDPOINT' ) && is_string( URBIZEN_BACKEND_ENDPOINT ) ) {
			return URBIZEN_BACKEND_ENDPOINT;
		}

		return (string) self::get( 'backend_endpoint', '' );
	}
}
