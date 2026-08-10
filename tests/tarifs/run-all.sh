#!/usr/bin/env bash
#
# Lance les bancs d'essai de la page Tarifs.
#
#   ./run-all.sh
#
# Trois bancs, du plus structurel au plus fin :
#
#   1. la source tarifaire — les montants affichés sont-ils ceux que le
#      formulaire facture ? C'est le contrôle qui compte le plus : une page
#      de tarifs qui ment sur un prix est pire qu'une page absente ;
#   2. le gabarit — enregistrement, titres, composants réutilisés, liens ;
#   3. la géométrie — ce que tout cela devient à l'écran, mesuré dans un vrai
#      moteur de rendu, de 1440 à 320 px.
#
# Prérequis : PHP 8.1+, Python 3 et Google Chrome. Sans Chrome, le banc de
# géométrie sort en code 2 (prérequis absent) — jamais en succès silencieux.
#
#   PHP_BIN=/chemin/vers/php ./run-all.sh
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
PY_BIN="${PY_BIN:-python3}"
echecs=0
prerequis_absents=0

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
command -v "$PY_BIN" >/dev/null 2>&1 || {
	echo "Python 3 introuvable (PY_BIN=$PY_BIN)."
	echo "Le banc de géométrie ne peut pas s'exécuter sans lui."
	exit 2
}

titre "1/3 — Cohérence de la source tarifaire"
"$PHP_BIN" test-tarifs-source.php
verdict $? "test-tarifs-source.php"

titre "2/3 — Gabarit, composants et référencement"
"$PHP_BIN" test-page-tarifs.php
verdict $? "test-page-tarifs.php"

titre "3/3 — Géométrie, de 1440 à 320 px"
"$PY_BIN" test-geometrie-tarifs.py
code_geometrie=$?
if [ "$code_geometrie" -eq 2 ]; then
	printf '\033[33m⚠ test-geometrie-tarifs.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_geometrie "test-geometrie-tarifs.py"
fi

printf '\n'
if [ "$prerequis_absents" -eq 1 ] && [ "$echecs" -eq 0 ]; then
	printf '\033[33mBancs passés, mais au moins un banc NON EXÉCUTÉ (prérequis absent).\033[0m\n'
	exit 2
fi
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 3 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
