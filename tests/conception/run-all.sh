#!/usr/bin/env bash
#
# Lance les quatre bancs d'essai du socle « Conception de plans sur mesure ».
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucune connexion réseau, aucune base de données, aucun
# WordPress installé : les rares fonctions WordPress employées sont doublées
# dans bootstrap.php.
#
# Le binaire PHP peut être désigné par PHP_BIN si `php` n'est pas dans le PATH :
#
#   PHP_BIN=/opt/homebrew/bin/php ./run-all.sh
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
	echo "Installez PHP 8.1+, ou désignez-le : PHP_BIN=/chemin/vers/php ./run-all.sh"
	exit 2
}

titre "1/4 — Définition, étapes et champs"
"$PHP_BIN" test-definition.php
verdict $? "test-definition.php"

titre "2/4 — Catalogue tarifaire"
"$PHP_BIN" test-pricing.php
verdict $? "test-pricing.php"

titre "3/4 — Validation serveur"
"$PHP_BIN" test-validator.php
verdict $? "test-validator.php"

titre "4/4 — Mutations : les contrôles mordent-ils ?"
"$PHP_BIN" test-mutation.php
verdict $? "test-mutation.php"

printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 4 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
