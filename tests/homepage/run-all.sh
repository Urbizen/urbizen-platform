#!/usr/bin/env bash
#
# Lance les bancs d'essai de la page d'accueil.
#
#   ./run-all.sh
#
# Ces bancs existaient depuis longtemps mais n'avaient **aucun lanceur** : ils
# ne figuraient donc dans aucune vérification de routine, et personne ne les
# exécutait. C'est ainsi qu'une divergence a pu s'installer entre l'accueil
# servi en production et les sources du dépôt, sans qu'aucun contrôle ne la
# signale. Ce fichier ferme cette porte.
#
# Prérequis : PHP 8.1+ et Python 3 (pour le banc du portage CSS). Aucune
# connexion réseau, aucune base de données, aucun WordPress installé : les
# rares fonctions WordPress employées sont doublées dans chaque banc.
#
#   PHP_BIN=/chemin/vers/php ./run-all.sh
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
PY_BIN="${PY_BIN:-python3}"
echecs=0

titre() { printf '\n\033[1m── %s\033[0m\n' "$1"; }
verdict() {
	if [ "$1" -eq 0 ]; then
		printf '\033[32m✓ %s\033[0m\n' "$2"
	else
		printf '\033[31m✗ %s (code %s)\033[0m\n' "$1" "$2"
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
	echo "Le banc du portage CSS ne peut pas s'exécuter sans lui."
	exit 2
}

# L'ordre va du plus structurel au plus fin : la fidélité du portage d'abord,
# puis chaque section. Un échec de fidélité explique souvent les suivants.

titre "1/7 — Fidélité du portage WordPress"
"$PHP_BIN" test-fidelite.php
verdict $? "test-fidelite.php"

titre "2/7 — En-tête et centre de contact"
"$PHP_BIN" test-entete.php
verdict $? "test-entete.php"

titre "3/7 — Cibles tactiles de l'en-tête mobile"
"$PHP_BIN" test-cibles-tactiles.php
verdict $? "test-cibles-tactiles.php"

titre "4/7 — Planche du hero et sa séquence d'animation"
"$PHP_BIN" test-hero.php
verdict $? "test-hero.php"

titre "5/7 — Section « Nos services » : prestations et contenu du dossier"
"$PHP_BIN" test-services.php
verdict $? "test-services.php"

titre "6/7 — Icônes et cartes de type de projet"
"$PHP_BIN" test-icones-projet.php
verdict $? "test-icones-projet.php"

titre "7/7 — Gabarit front-page et sa parité"
"$PHP_BIN" test-front-page.php
verdict $? "test-front-page.php"

# Le portage CSS a son propre banc unitaire : c'est lui qui garantit que
# `:root` et `body` ne sont jamais préfixés, faute de quoi les variables du
# document seraient perdues en silence.
titre "Portage CSS — scope-css.py"
"$PY_BIN" test-scope-css.py
verdict $? "test-scope-css.py"

printf '\n'
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 8 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
