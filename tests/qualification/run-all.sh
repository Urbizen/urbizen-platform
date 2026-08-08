#!/usr/bin/env bash
#
# Bancs du moteur de qualification d'urbanisme.
#
#   ./run-all.sh
#
# Deux moteurs appliquent les mêmes règles : l'un dans le navigateur, l'autre
# dans WordPress. Ils ne partagent aucun code — ils partagent un corpus de cas,
# et ces bancs exigent qu'ils en tirent les mêmes verdicts. Une divergence est
# un échec ici, pas une découverte en production.
#
# Prérequis : PHP 8.1+ et Node 18+.
# Codes de sortie : 0 succès · 1 échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
PY_BIN="${PY_BIN:-python3}"
NODE_BIN="${NODE_BIN:-node}"
echecs=0

titre() { printf '\n\033[1m── %s\033[0m\n' "$1"; }
verdict() {
	if [ "$1" -eq 0 ]; then printf '\033[32m✓ %s\033[0m\n' "$2"
	else printf '\033[31m✗ %s (code %s)\033[0m\n' "$2" "$1"; echecs=$((echecs + 1)); fi
}

command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "PHP introuvable (PHP_BIN=$PHP_BIN)."; exit 2; }
command -v "$NODE_BIN" >/dev/null 2>&1 || { echo "Node introuvable (NODE_BIN=$NODE_BIN)."; exit 2; }

titre "1/5 — Moteur serveur"
"$PHP_BIN" test-qualification.php
verdict $? "test-qualification.php"

titre "2/5 — Moteur navigateur, et son équivalence avec le serveur"
"$NODE_BIN" test-qualification.mjs
verdict $? "test-qualification.mjs"

# Le moteur peut être juste et le tunnel décider quand même trop tôt : c'est
# exactement la lacune qui a produit le défaut de la PR #56. Ce banc rejoue
# l'enchaînement réel des questions dans un moteur de rendu.
titre "3/5 — Tunnel de qualification, rejoué dans un navigateur"
"$PY_BIN" test-tunnel.py
code_tunnel=$?
if [ "$code_tunnel" -eq 2 ]; then
	printf '\033[33m⚠ test-tunnel.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_tunnel "test-tunnel.py"
fi

# Le tunnel peut poser les bonnes questions et le formulaire les redemander
# toutes : la qualification serait alors un questionnaire jeté.
# Le tunnel transmet ses réponses et son verdict par la session, puis par un
# champ caché : c'est-à-dire par des données que n'importe qui peut réécrire.
titre "4/5 — Falsification : le serveur croit-il le navigateur ?"
"$PHP_BIN" test-falsification.php
verdict $? "test-falsification.php"

titre "5/5 — Report des réponses vers les formulaires DP et PC"
"$PY_BIN" test-report.py
code_report=$?
if [ "$code_report" -eq 2 ]; then
	printf '\033[33m⚠ test-report.py NON EXÉCUTÉ (Chrome absent) — ce n'"'"'est pas un succès\033[0m\n'
	prerequis_absents=1
else
	verdict $code_report "test-report.py"
fi

printf '\n'
if [ "${prerequis_absents:-0}" -eq 1 ] && [ "$echecs" -eq 0 ]; then
	printf '\033[33mbancs passés, au moins un NON EXÉCUTÉ (prérequis absent).\033[0m\n'; exit 2
fi
if [ "$echecs" -eq 0 ]; then printf '\033[32mLes 5 bancs passent.\033[0m\n'; exit 0; fi
printf '\033[31m%s banc(s) en échec.\033[0m\n' "$echecs"
exit 1
