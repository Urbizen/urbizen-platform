#!/usr/bin/env bash
#
# Lance les bancs d'essai des profils d'upload par formulaire (Lot 1 incr. 5).
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucune connexion réseau, aucune base, aucun WordPress :
# les fonctions employées sont doublées par le harnais de tests/submissions,
# réutilisé ici (finfo réel, fixtures fx_*).
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
	echo "Installez PHP 8.1+, ou désignez-le : PHP_BIN=/chemin/vers/php ./run-all.sh"
	exit 2
}

titre "1/2 — Profils d'upload résolus par type serveur"
"$PHP_BIN" test-profils.php
verdict "$?" test-profils.php

titre "2/2 — Pipeline entièrement piloté par le profil (bout en bout)"
"$PHP_BIN" test-pipeline-profil.php
verdict "$?" test-pipeline-profil.php

echo
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLe banc passe.\033[0m\n'
	exit 0
fi
printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
