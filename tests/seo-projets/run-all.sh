#!/usr/bin/env bash
#
# Bancs du cocon SEO « projets » — 9 pages et 12 guides.
#
#   ./run-all.sh                          # production par défaut
#   ./run-all.sh https://recette.test      # une autre base
#
# Le volet statique est autonome : il ne lit que le dépôt. Le volet en ligne
# interroge la production par défaut, comme `tests/seo/run-all.sh` — c'est la
# convention du dépôt pour les bancs SEO, et elle a une raison : ces contrôles
# ne valent que sur ce qui est réellement servi. Son échec peut signaler un
# site indisponible autant qu'une régression ; les deux volets restent donc
# affichés séparément.
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec ·
#                   2 prérequis absent (banc NON exécuté, jamais un succès).

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
BASE="${1:-https://urbizen.fr}"
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
	exit 2
}

titre "1/2 — Contenu, maillage, visuels et règles éditoriales"
"$PHP_BIN" test-contenu-projets.php
verdict $? "test-contenu-projets.php"

if [ -n "$BASE" ]; then
	# Le volet en ligne mesure ce qu'aucune lecture de source ne donne : codes
	# HTTP, canonicals servis, données structurées, images réellement chargées.
	if ! command -v "$NODE_BIN" >/dev/null 2>&1; then
		printf '\033[33m⚠ Node introuvable : volet en ligne NON EXÉCUTÉ — ce n'"'"'est pas un succès.\033[0m\n'
		prerequis_absents=1
	elif ! "$NODE_BIN" -e 'import("playwright").then(()=>process.exit(0),()=>process.exit(1))' >/dev/null 2>&1; then
		printf '\033[33m⚠ playwright introuvable : volet en ligne NON EXÉCUTÉ — ce n'"'"'est pas un succès.\033[0m\n'
		printf '  Installer :  npm install --no-save playwright && npx playwright install chromium\n'
		prerequis_absents=1
	else
		titre "2/2 — Volet en ligne — $BASE"
		"$NODE_BIN" test-projets-en-ligne.mjs "$BASE"
		verdict $? "test-projets-en-ligne.mjs"
	fi
fi

printf '\n'
if [ "$echecs" -gt 0 ]; then
	printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
	exit 1
fi
if [ "$prerequis_absents" -eq 1 ]; then
	printf '\033[33mBancs passés, mais au moins un banc NON EXÉCUTÉ (prérequis absent).\033[0m\n'
	exit 2
fi
printf '\033[32mLes bancs du cocon passent.\033[0m\n'
exit 0
