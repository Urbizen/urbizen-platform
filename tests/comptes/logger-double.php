<?php
/**
 * Doublure du journal.
 *
 * Elle **conserve** tout ce qui est écrit : c'est ainsi qu'un banc peut
 * prouver qu'aucune adresse, aucun jeton et aucun mot de passe n'y figure.
 * Un journal muet ne le prouverait pas.
 */

declare( strict_types = 1 );

namespace Urbizen\Platform\Support;

/**
 * Journal en mémoire.
 */
final class Logger {

	/**
	 * @var array<int, string>
	 */
	public static array $lignes = array();

	public static function info( string $message ): void {
		self::$lignes[] = 'INFO ' . $message;
	}

	public static function error( string $message ): void {
		self::$lignes[] = 'ERROR ' . $message;
	}

	public static function debug( string $message ): void {
		self::$lignes[] = 'DEBUG ' . $message;
	}

	public static function reset(): void {
		self::$lignes = array();
	}

	/**
	 * Tout le journal, concaténé.
	 *
	 * @return string
	 */
	public static function tout(): string {
		return implode( "\n", self::$lignes );
	}
}
