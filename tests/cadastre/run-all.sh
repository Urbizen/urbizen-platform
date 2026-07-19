#!/usr/bin/env bash
#
# Lance les quatre bancs d'essai Urbizen.
#
#   ./run-all.sh
#
# Prérequis : Node 18+, jsdom (npm install dans ce répertoire), PHP 8.1+.
# Aucune connexion réseau n'est nécessaire, aucun WordPress installé non plus :
# les fonctions WordPress sont doublées dans les bancs PHP et les services IGN
# sont simulés dans les bancs JavaScript.
#
# Le binaire PHP peut être désigné par PHP_BIN si `php` n'est pas dans le PATH
# (macOS n'en fournit plus depuis Monterey) :
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

# --- Prérequis ---
command -v node >/dev/null 2>&1 || { echo "Node introuvable. Installez Node 18+."; exit 2; }
command -v "$PHP_BIN" >/dev/null 2>&1 || {
	echo "PHP introuvable (PHP_BIN=$PHP_BIN)."
	echo "Installez PHP 8.1+, ou désignez-le : PHP_BIN=/chemin/vers/php ./run-all.sh"
	exit 2
}
[ -d node_modules/jsdom ] || { echo "jsdom absent. Lancez : npm install"; exit 2; }

# --- Fixture : le HTML réel de Renderer.php, régénéré à chaque exécution ---
titre "Fixture — rendu réel de Renderer.php"
if "$PHP_BIN" make-fixture.php > fixture.html; then
	printf '✓ fixture.html régénérée (%s octets)\n' "$(wc -c < fixture.html | tr -d ' ')"
else
	printf '\033[31m✗ génération de la fixture impossible\033[0m\n'
	exit 1
fi

# --- Les quatre bancs ---
titre "1/4 — Cadastre, comportement JavaScript"
node test-cadastre.mjs
verdict $? "test-cadastre.mjs"

titre "2/4 — Formulaire, comportement JavaScript (sur le HTML réel)"
node test-form.mjs
verdict $? "test-form.mjs"

titre "3/4 — Cadastre, rendu PHP"
"$PHP_BIN" test-render.php
verdict $? "test-render.php"

titre "4/4 — Formulaire, rendu PHP"
"$PHP_BIN" test-form-render.php
verdict $? "test-form-render.php"

# --- Bilan ---
printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 4 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
