#!/usr/bin/env bash
#
# Lance les bancs du domaine et du schéma.
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucun WordPress, aucune base de données, aucun réseau —
# ces bancs éprouvent du code qui n'a pas le droit d'en dépendre.
#
#   PHP_BIN=/chemin/vers/php ./run-all.sh
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
echecs=0

if ! command -v "$PHP_BIN" > /dev/null 2>&1; then
	printf 'PHP introuvable (%s)\n' "$PHP_BIN" >&2
	exit 2
fi

bancs=(
	test-identite.php
	test-autorisation.php
	test-ulid.php
	test-migrations.php
	test-frontiere-domaine.php
)

for banc in "${bancs[@]}"; do
	if [ ! -f "$banc" ]; then
		printf '\033[31m✗ %s : fichier absent\033[0m\n' "$banc"
		echecs=$(( echecs + 1 ))
		continue
	fi

	if sortie=$( "$PHP_BIN" "$banc" 2>&1 ); then
		printf '\033[32m✓ %s\033[0m\n' "$banc"
	else
		printf '\033[31m✗ %s\033[0m\n' "$banc"
		printf '%s\n' "$sortie" | tail -20
		echecs=$(( echecs + 1 ))
	fi
done

echo

if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes %d bancs passent.\033[0m\n' "${#bancs[@]}"
	exit 0
fi

printf '\033[31m%d banc(s) en échec.\033[0m\n' "$echecs"
exit 1
