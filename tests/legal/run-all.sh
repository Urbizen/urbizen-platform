#!/usr/bin/env bash
#
# Lance les bancs TECHNIQUES des trois documents légaux.
#
#   ./run-all.sh
#
# Ce lanceur ne contient PAS `test-legal-readiness.php`, et c'est délibéré.
# Ce dernier est fait pour être rouge tant qu'une donnée juridique manque — il
# l'a été jusqu'au 11 août 2026 pour le médiateur et le régime de TVA, et le
# redeviendra si l'attestation d'assurance vient à échoir. L'inclure ici
# exposerait la suite technique à un rouge d'origine juridique, et une suite
# qu'on n'ose plus lancer ne sert plus. On le lance donc à part, avant tout
# déploiement :
#
#   php tests/legal/test-legal-readiness.php
#
# Prérequis : PHP 8.1+, Python 3 et Google Chrome. Sans Chrome, le banc de
# géométrie sort en code 2 (prérequis absent) — jamais en succès silencieux.
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

command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "PHP introuvable (PHP_BIN=$PHP_BIN)."; exit 2; }
command -v "$PY_BIN"  >/dev/null 2>&1 || { echo "Python 3 introuvable (PY_BIN=$PY_BIN)."; exit 2; }

titre "1/2 — Structure, contenu juridique et charte"
"$PHP_BIN" test-pages-legales.php
verdict $? "test-pages-legales.php"

titre "2/2 — Géométrie, de 1440 à 320 px"
"$PY_BIN" test-geometrie-legal.py
code_geo=$?
if [ "$code_geo" -eq 2 ]; then
	printf '\033[33m⚠ test-geometrie-legal.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_geo "test-geometrie-legal.py"
fi

printf '\n'
if [ "$prerequis_absents" -eq 1 ] && [ "$echecs" -eq 0 ]; then
	printf '\033[33mBancs passés, mais au moins un banc NON EXÉCUTÉ (prérequis absent).\033[0m\n'
	exit 2
fi
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 2 bancs techniques passent.\033[0m\n'
	printf '\033[33mRappel : lancer « php test-legal-readiness.php » avant tout déploiement.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
