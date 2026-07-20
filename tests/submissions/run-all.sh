#!/usr/bin/env bash
#
# Lance les six bancs d'essai de la réception des demandes.
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucun WordPress, aucune base de données, aucun réseau :
# les fonctions WordPress employées sont doublées dans wp-double.php, avec une
# horloge pilotable qui permet d'éprouver expirations et fenêtres sans attendre.
#
#   PHP_BIN=/chemin/vers/php ./run-all.sh
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
echecs=0

titre() { printf '\n\033[1m── %s\033[0m\n' "$1"; }
verdict() {
	if [ "$1" -eq 0 ]; then
		printf '\033[32m✓ %s\033[0m\n' "$2"
	else
		printf '\033[31m✗ %s (code %s)\033[0m\n' "$2" "$1"
		echecs=$((echecs + 1))
	fi
}

command -v "$PHP_BIN" >/dev/null 2>&1 || {
	echo "PHP introuvable (PHP_BIN=$PHP_BIN)."
	exit 2
}

titre "1/6 — Défenses : jeton, pot de miel, limitation de débit"
"$PHP_BIN" test-security.php
verdict $? "test-security.php"

titre "2/6 — Conservation : type de contenu privé et repository"
"$PHP_BIN" test-storage.php
verdict $? "test-storage.php"

titre "3/6 — Contrôleur de soumission"
"$PHP_BIN" test-controller.php
verdict $? "test-controller.php"

titre "4/6 — Rétention à 365 jours"
"$PHP_BIN" test-retention.php
verdict $? "test-retention.php"

titre "5/6 — Concurrence, atomicité et planification"
"$PHP_BIN" test-concurrence.php
verdict $? "test-concurrence.php"

titre "6/6 — Compatibilité et absence d'effet public"
"$PHP_BIN" test-compat.php
verdict $? "test-compat.php"

titre "Mutations : les contrôles mordent-ils ?"
"$PHP_BIN" test-mutation.php
verdict $? "test-mutation.php"

printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 7 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
