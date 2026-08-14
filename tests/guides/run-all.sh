#!/usr/bin/env bash
#
# Lance les bancs des gabarits de guides.
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucune connexion réseau, aucune base de données.
# Le rendu réel demande WordPress ; il est vérifié à part, sur une installation
# locale, et consigné dans docs/PLAN_GUIDES_INFRASTRUCTURE.md.
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
command -v "$PHP_BIN" >/dev/null 2>&1 || {
	echo "PHP introuvable (PHP_BIN=$PHP_BIN)."
	exit 2
}

printf '\n\033[1m── 1/1 — Gabarits, patterns, feuille et contexte\033[0m\n'
"$PHP_BIN" test-guides.php
code=$?

printf '\n'
if [ "$code" -eq 0 ]; then
	printf '\033[32mLe banc passe.\033[0m\n'
	exit 0
fi
printf '\033[31mBanc en échec.\033[0m\n'
exit 1
