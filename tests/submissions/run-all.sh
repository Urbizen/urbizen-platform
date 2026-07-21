#!/usr/bin/env bash
#
# Lance les onze bancs d'essai de la réception des demandes.
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

titre "1/11 — Défenses : jeton, pot de miel, limitation de débit"
"$PHP_BIN" test-security.php
verdict $? "test-security.php"

titre "2/11 — Conservation : type de contenu privé et repository"
"$PHP_BIN" test-storage.php
verdict $? "test-storage.php"

titre "3/11 — Contrôleur de soumission"
"$PHP_BIN" test-controller.php
verdict $? "test-controller.php"

titre "4/11 — Rétention à 365 jours"
"$PHP_BIN" test-retention.php
verdict $? "test-retention.php"

titre "5/11 — Concurrence, atomicité et planification"
"$PHP_BIN" test-concurrence.php
verdict $? "test-concurrence.php"

titre "6/11 — Registre des références et verrou de programmation"
"$PHP_BIN" test-registre.php
verdict $? "test-registre.php"

titre "7/11 — Politique, normalisation et stockage des documents"
"$PHP_BIN" test-documents.php
verdict $? "test-documents.php"

titre "8/11 — Transaction, liens signés et rétention des documents"
"$PHP_BIN" test-transaction.php
verdict $? "test-transaction.php"

titre "9/11 — Interruptions brutales et récupération"
"$PHP_BIN" test-interruption.php
verdict $? "test-interruption.php"

titre "10/11 — Cycle de Corbeille WordPress"
"$PHP_BIN" test-corbeille.php
verdict $? "test-corbeille.php"

titre "11/11 — Compatibilité et absence d'effet public"
"$PHP_BIN" test-compat.php
verdict $? "test-compat.php"

titre "Mutations : les contrôles mordent-ils ?"
"$PHP_BIN" test-mutation.php
verdict $? "test-mutation.php"

printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 12 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
