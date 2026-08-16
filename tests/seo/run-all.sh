#!/usr/bin/env bash
# Bancs SEO — volet statique (dépôt) puis volet en ligne (site servi).
#
# Le volet en ligne interroge la production par défaut. Il n'écrit rien, mais
# il dépend du réseau : son échec peut signaler un site indisponible autant
# qu'une régression. Le volet statique, lui, est autonome.
set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
BASE="${1:-https://urbizen.fr}"
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

command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "PHP introuvable (PHP_BIN=$PHP_BIN)."; exit 2; }

prerequis_absents=0

titre "Contrôles statiques"
"$PHP_BIN" test-seo-p0.php
verdict $? "test-seo-p0.php"

if command -v "$NODE_BIN" >/dev/null 2>&1; then
	titre "Contrôles en ligne — $BASE"
	"$NODE_BIN" test-seo-p0.mjs "$BASE"
	verdict $? "test-seo-p0.mjs"
	"$NODE_BIN" test-seo-lot-b.mjs "$BASE"
	verdict $? "test-seo-lot-b.mjs"
	"$NODE_BIN" test-seo-lot-c.mjs "$BASE"
	verdict $? "test-seo-lot-c.mjs"
	"$NODE_BIN" test-seo-lot-e.mjs "$BASE"
	verdict $? "test-seo-lot-e.mjs"

	# LES LOTS D ET F DEMANDENT UN MOTEUR DE RENDU
	#
	# Ils importent `playwright`. Sans lui, node sortait en
	# `ERR_MODULE_NOT_FOUND` et ce lanceur comptait cela comme un échec
	# ordinaire : les deux bancs ont pu paraître rouges pendant des semaines
	# sans avoir jamais été exécutés une seule fois. Un prérequis absent n'est
	# ni un succès ni un échec — c'est une mesure qui n'a pas eu lieu, et le
	# lanceur doit le dire.
	#
	# Même convention que `tests/homepage/run-all.sh` : code 2.
	if "$NODE_BIN" -e 'import("playwright").then(()=>process.exit(0),()=>process.exit(1))' >/dev/null 2>&1; then
		titre "Contrôles en ligne avec moteur de rendu — $BASE"
		"$NODE_BIN" test-seo-lot-d.mjs "$BASE"
		verdict $? "test-seo-lot-d.mjs"
		"$NODE_BIN" test-seo-lot-f.mjs "$BASE"
		verdict $? "test-seo-lot-f.mjs"
	else
		printf '\033[33m⚠ test-seo-lot-d.mjs et test-seo-lot-f.mjs NON EXÉCUTÉS\033[0m\n'
		printf '\033[33m  playwright introuvable — ce n\x27est PAS un succès.\033[0m\n'
		printf '  Installer :  npm install --no-save playwright && npx playwright install chromium\n'
		prerequis_absents=1
	fi
else
	printf '\033[33m⚠ Node introuvable : volet en ligne NON EXÉCUTÉ — ce n\x27est pas un succès.\033[0m\n'
	prerequis_absents=1
fi

printf '\n\033[33m⚠ Le schéma d\x27un futur article se contrôle sur le serveur :\n   wp eval-file tests/seo/test-seo-lot-e-article.php\033[0m\n'

echo
if [ "$echecs" -gt 0 ]; then
	printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
	exit 1
fi
if [ "$prerequis_absents" -eq 1 ]; then
	printf '\033[33mBancs passés, mais au moins un banc NON EXÉCUTÉ (prérequis absent).\033[0m\n'
	exit 2
fi
printf '\033[32mLes bancs SEO passent.\033[0m\n'
exit 0
