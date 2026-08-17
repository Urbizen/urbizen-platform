#!/usr/bin/env bash
#
# Lance les bancs des formulaires DP et PC.
#
#   ./run-all.sh
#
# POURQUOI CE FICHIER N'EXISTAIT PAS, ET POURQUOI C'ÉTAIT UN PROBLÈME
#
# Ce répertoire portait neuf bancs depuis plusieurs lots — contrats DP et PC,
# matrice des champs, adresse du terrain, validation métier, parité des pages —
# mais aucun lanceur. Or `tests/run-all.sh` découvre les suites en cherchant un
# `run-all.sh` sur le disque : sans lui, ce répertoire était invisible, et ces
# neuf bancs n'ont jamais été exécutés par un seul des tours « 17/17 ».
#
# Ils couvrent précisément DP et PC. Les laisser hors du tour revenait à tenir
# pour vert ce qui n'avait pas été mesuré.
#
# Prérequis : PHP 8.1+ pour les bancs PHP, et Node avec JSDOM pour le banc
# d'erreurs multi-étapes. JSDOM est installé dans `tests/cadastre/` ; le banc le
# retrouve seul et rend 2 s'il ne le trouve pas — un prérequis absent n'est
# jamais un succès.
#
#   PHP_BIN=/chemin/vers/php ./run-all.sh
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
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

# --------------------------------------------------------------------------
# Les contrats servis au navigateur
# --------------------------------------------------------------------------

titre "1/10 — Contrat du formulaire de déclaration préalable"
"$PHP_BIN" test-contrat-dp.php
verdict "$?" test-contrat-dp.php

titre "2/10 — Contrat du formulaire de permis de construire"
"$PHP_BIN" test-contrat-pc.php
verdict "$?" test-contrat-pc.php

titre "3/10 — Parité des pages de formulaire et de leurs maquettes"
"$PHP_BIN" test-pages-formulaires.php
verdict "$?" test-pages-formulaires.php

# --------------------------------------------------------------------------
# Les règles métier
# --------------------------------------------------------------------------

titre "4/10 — Matrice des champs conditionnels"
"$PHP_BIN" test-matrice-champs.php
verdict "$?" test-matrice-champs.php

titre "5/10 — Validation métier de la déclaration préalable"
"$PHP_BIN" test-validation-metier-dp.php
verdict "$?" test-validation-metier-dp.php

titre "6/10 — Piscine : seuils et bascules"
"$PHP_BIN" test-piscine.php
verdict "$?" test-piscine.php

titre "7/10 — Nombres localisés (virgule et point)"
"$PHP_BIN" test-nombre-localise.php
verdict "$?" test-nombre-localise.php

# --------------------------------------------------------------------------
# L'adresse du terrain
# --------------------------------------------------------------------------

titre "8/10 — Adresse du terrain"
"$PHP_BIN" test-adresse-terrain.php
verdict "$?" test-adresse-terrain.php

titre "9/10 — Report de l'adresse du déclarant sur le terrain"
"$PHP_BIN" test-report-adresse.php
verdict "$?" test-report-adresse.php

# --------------------------------------------------------------------------
# Le parcours d'erreur, rejoué dans un vrai document
#
# `display:none` ne se simule pas : c'est cette propriété même qui rendait
# `focus()` inopérant sur un champ d'une rubrique fermée. Un banc qui se
# contenterait de vérifier l'appel serait passé au vert sur le défaut.
# --------------------------------------------------------------------------

titre "10/10 — Erreurs multi-étapes DP / PC (DOM simulé)"
"$NODE_BIN" test-erreurs-etapes.mjs
code_erreurs=$?

if [ "$code_erreurs" -eq 2 ]; then
	printf '\033[33m⚠ test-erreurs-etapes.mjs NON EXÉCUTÉ (JSDOM absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict "$code_erreurs" test-erreurs-etapes.mjs
fi

# --------------------------------------------------------------------------

printf '\n'

if [ "$echecs" -gt 0 ]; then
	printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
	exit 1
fi

if [ "$prerequis_absents" -eq 1 ]; then
	printf '\033[33mLes bancs exécutés passent, mais un prérequis manquait.\033[0m\n'
	exit 2
fi

printf '\033[32mLes 10 bancs passent.\033[0m\n'
exit 0
