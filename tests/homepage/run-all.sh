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

titre "1/11 — Fidélité du portage WordPress"
"$PHP_BIN" test-fidelite.php
verdict $? "test-fidelite.php"

titre "2/11 — En-tête et centre de contact"
"$PHP_BIN" test-entete.php
verdict $? "test-entete.php"

titre "3/11 — Menu principal : composition, page courante, lisibilité"
"$PHP_BIN" test-navigation.php
verdict $? "test-navigation.php"

titre "4/11 — Cibles tactiles de l'en-tête mobile (cascade CSS)"
"$PHP_BIN" test-cibles-tactiles.php
verdict $? "test-cibles-tactiles.php"

titre "5/11 — Planche du hero et sa séquence d'animation"
"$PHP_BIN" test-hero.php
verdict $? "test-hero.php"

titre "6/11 — Section « Nos services » : prestations et contenu du dossier"
"$PHP_BIN" test-services.php
verdict $? "test-services.php"

titre "7/11 — Icônes et cartes de type de projet"
"$PHP_BIN" test-icones-projet.php
verdict $? "test-icones-projet.php"

titre "8/11 — Gabarit front-page et sa parité"
"$PHP_BIN" test-front-page.php
verdict $? "test-front-page.php"

titre "9/11 — Contrat du parcours « Écrire à Urbizen »"
"$PHP_BIN" test-contrat-renseignements.php
verdict $? "test-contrat-renseignements.php"

# Le portage CSS a son propre banc unitaire : c'est lui qui garantit que
# `:root` et `body` ne sont jamais préfixés, faute de quoi les variables du
# document seraient perdues en silence.
titre "Portage CSS — scope-css.py"
"$PY_BIN" test-scope-css.py
verdict $? "test-scope-css.py"

# La cascade CSS ne dit pas tout. Une règle peut viser le bon sélecteur dans le
# bon palier et le résultat visuel rester faux, parce que la mise en page dépend
# de hauteurs et de positions qu'aucune lecture de feuille ne donne. C'est ainsi
# qu'un CTA replié est sorti de l'en-tête et est venu recouvrir le lanceur de
# chat, en production, avec 428 contrôles au vert. Ce banc mesure pour de bon.
titre "Géométrie de l'en-tête — mesure dans un moteur de rendu"
"$PY_BIN" test-geometrie-entete.py
code_geometrie=$?
if [ "$code_geometrie" -eq 2 ]; then
	printf '\033[33m⚠ test-geometrie-entete.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_geometrie "test-geometrie-entete.py"
fi

# Un menu peut être irréprochable dans les sources et rester inutilisable : un
# `aria-expanded` qui ment, un panneau qui déborde, un Échap qui laisse le focus
# nulle part. Ce banc l'ouvre et le referme pour de bon, à huit largeurs.
titre "Menu à deux niveaux — rejoué dans un moteur de rendu"
"$PY_BIN" test-navigation.py
code_navigation=$?
if [ "$code_navigation" -eq 2 ]; then
	printf '\033[33m⚠ test-navigation.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_navigation "test-navigation.py"
fi

# Le balisage peut être parfait et le parcours ne mener nulle part : un dialogue
# qui reste ouvert, un focus qui tombe dans un champ et lève le clavier, un
# second clic qui referme ce qu'on venait d'ouvrir. Ce banc rejoue le trajet.
titre "Parcours « Écrire à Urbizen » — rejoué dans un moteur de rendu"
"$PY_BIN" test-parcours-renseignements.py
code_parcours=$?
if [ "$code_parcours" -eq 2 ]; then
	printf '\033[33m⚠ test-parcours-renseignements.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_parcours "test-parcours-renseignements.py"
fi

printf '\n'
if [ "${prerequis_absents:-0}" -eq 1 ] && [ "$echecs" -eq 0 ]; then
	printf '\033[33mBancs passés, mais au moins un banc NON EXÉCUTÉ (prérequis absent).\033[0m\n'
	exit 2
fi
if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes 13 bancs passent.\033[0m\n'
	exit 0
fi

printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
