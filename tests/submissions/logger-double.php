<?php
/**
 * Capture des écritures du Logger.
 *
 * `error_log()` est une fonction native : elle ne peut pas être redéfinie dans
 * l'espace global. Mais PHP résout un appel non qualifié d'abord dans l'espace
 * de noms courant. En déclarant celle-ci dans l'espace du Logger, c'est elle
 * qui est appelée à sa place.
 *
 * Les bancs peuvent ainsi vérifier ce qui entre dans le journal — et surtout
 * ce qui n'y entre jamais : aucun nom, aucune adresse, aucun jeton, aucune IP.
 *
 * Une déclaration `namespace` doit être la première instruction d'un fichier :
 * d'où ce fichier séparé.
 */

namespace Urbizen\Platform\Support;

/**
 * Doublure de journalisation.
 *
 * @param string $message     Message.
 * @param int    $type        Ignoré.
 * @param string $destination Ignoré.
 * @param string $entetes     Ignoré.
 * @return bool
 */
function error_log( $message, $type = 0, $destination = null, $entetes = null ) {
	$GLOBALS['wpd_logs'][] = (string) $message;
	return true;
}
