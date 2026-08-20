#!/usr/bin/env bash
#
# Lance les bancs des gabarits et contenus de guides.
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucune connexion réseau, aucune base de données.
# Le rendu réel demande WordPress ; il est vérifié à part.
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

printf '\n\033[1m── 1/3 — Gabarits, patterns, feuille et contexte\033[0m\n'
"$PHP_BIN" test-guides.php || echecs=$((echecs + 1))

# Le contenant est figé par le banc ci-dessus ; celui-ci fige le CONTENU
# historique versionné dans content/guides/.
printf '\n\033[1m── 2/3 — Contenu historique, maillage et règles éditoriales\033[0m\n'
"$PHP_BIN" test-contenu-guides.php || echecs=$((echecs + 1))

# Le lot 2 a son propre contrat : vingt slugs explicites, structure Gutenberg,
# sources institutionnelles, liens internes et cohérence du publisher dédié.
printf '\n\033[1m── 3/3 — Lot SEO 2 : 20 guides et publisher\033[0m\n'
"$PHP_BIN" test-guides-lot-2.php || echecs=$((echecs + 1))

printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes trois bancs passent.\033[0m\n'
	exit 0
fi
printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
