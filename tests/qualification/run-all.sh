#!/usr/bin/env bash
#
# Bancs du moteur de qualification d'urbanisme.
#
#   ./run-all.sh
#
# Deux moteurs appliquent les mêmes règles : l'un dans le navigateur, l'autre
# dans WordPress. Ils ne partagent aucun code — ils partagent un corpus de cas,
# et ces bancs exigent qu'ils en tirent les mêmes verdicts. Une divergence est
# un échec ici, pas une découverte en production.
#
# Prérequis : PHP 8.1+ et Node 18+.
# Codes de sortie : 0 succès · 1 échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
echecs=0

titre() { printf '\n\033[1m── %s\033[0m\n' "$1"; }
verdict() {
	if [ "$1" -eq 0 ]; then printf '\033[32m✓ %s\033[0m\n' "$2"
	else printf '\033[31m✗ %s (code %s)\033[0m\n' "$2" "$1"; echecs=$((echecs + 1)); fi
}

command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "PHP introuvable (PHP_BIN=$PHP_BIN)."; exit 2; }
command -v "$NODE_BIN" >/dev/null 2>&1 || { echo "Node introuvable (NODE_BIN=$NODE_BIN)."; exit 2; }

titre "1/2 — Moteur serveur"
"$PHP_BIN" test-qualification.php
verdict $? "test-qualification.php"

titre "2/2 — Moteur navigateur, et son équivalence avec le serveur"
"$NODE_BIN" test-qualification.mjs
verdict $? "test-qualification.mjs"

printf '\n'
if [ "$echecs" -eq 0 ]; then printf '\033[32mLes 2 bancs passent.\033[0m\n'; exit 0; fi
printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
