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

echecs=0

printf '\n\033[1m── 1/2 — Gabarits, patterns, feuille et contexte\033[0m\n'
"$PHP_BIN" test-guides.php || echecs=$((echecs + 1))

# Le contenant est figé par le banc ci-dessus ; celui-ci fige le CONTENU
# versionné dans content/guides/, qui est la source de vérité des articles
# publiés. Sans lui, un lien vers un guide inexistant ou une promesse
# d'obtention d'autorisation passeraient sans être vus.
printf '\n\033[1m── 2/2 — Contenu des guides, maillage et règles éditoriales\033[0m\n'
"$PHP_BIN" test-contenu-guides.php || echecs=$((echecs + 1))

printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes deux bancs passent.\033[0m\n'
	exit 0
fi
printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
