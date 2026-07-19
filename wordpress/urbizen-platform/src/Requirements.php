<?php
/**
 * Vérification de l'environnement d'exécution.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

defined( 'ABSPATH' ) || exit;

/**
 * Contrôle des versions minimales de PHP et de WordPress.
 */
final class Requirements {

	/**
	 * Indique si l'environnement est compatible.
	 */
	public static function are_met(): bool {
		return empty( self::failures() );
	}

	/**
	 * Liste les incompatibilités détectées.
	 *
	 * @return string[]
	 */
	public static function failures(): array {
		$failures = array();

		if ( version_compare( PHP_VERSION, URBIZEN_PLATFORM_MIN_PHP, '<' ) ) {
			$failures[] = sprintf(
				/* translators: 1: version requise, 2: version détectée */
				__( 'PHP %1$s minimum est requis (version détectée : %2$s).', 'urbizen-platform' ),
				URBIZEN_PLATFORM_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), URBIZEN_PLATFORM_MIN_WP, '<' ) ) {
			$failures[] = sprintf(
				/* translators: 1: version requise, 2: version détectée */
				__( 'WordPress %1$s minimum est requis (version détectée : %2$s).', 'urbizen-platform' ),
				URBIZEN_PLATFORM_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		return $failures;
	}

	/**
	 * Affiche un avertissement dans l'administration.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Urbizen Platform ne peut pas démarrer :', 'urbizen-platform' ),
			esc_html( implode( ' ', self::failures() ) )
		);
	}
}
