<?php
/**
 * Journalisation technique.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Journal applicatif.
 *
 * Règle RGPD absolue : aucune donnée personnelle ne doit transiter par ce
 * journal — ni nom, ni adresse, ni e-mail, ni contenu de formulaire. On y
 * consigne des références de dossier, des étapes et des codes de résultat.
 */
final class Logger {

	/**
	 * Message de diagnostic, uniquement si WP_DEBUG est actif.
	 *
	 * @param string $message Message sans donnée personnelle.
	 * @return void
	 */
	public static function debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::write( 'DEBUG', $message );
		}
	}

	/**
	 * Événement notable (transmission, purge, rejet).
	 *
	 * @param string $message Message sans donnée personnelle.
	 * @return void
	 */
	public static function info( string $message ): void {
		self::write( 'INFO', $message );
	}

	/**
	 * Erreur nécessitant une intervention.
	 *
	 * @param string $message Message sans donnée personnelle.
	 * @return void
	 */
	public static function error( string $message ): void {
		self::write( 'ERROR', $message );
	}

	/**
	 * Écrit dans le journal PHP.
	 *
	 * Une table dédiée wp_urbizen_log prendra le relais à l'étape « moteur de
	 * formulaires », afin de rendre le journal consultable dans l'admin.
	 *
	 * @param string $level   Niveau.
	 * @param string $message Message.
	 * @return void
	 */
	private static function write( string $level, string $message ): void {
		error_log( sprintf( '[urbizen][%s] %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
