#!/usr/bin/env bash
#
# Lance les bancs des comptes (E2.1).
#
#   ./run-all.sh
#
# Prérequis : PHP 8.1+. Aucun WordPress, aucune base de données, aucun réseau —
# le domaine n'a pas le droit d'en dépendre, et les services passent par des
# ports dont les doublures suffisent.
#
#   PHP_BIN=/chemin/vers/php ./run-all.sh
#
# Codes de sortie : 0 succès · 1 au moins un banc en échec · 2 prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
echecs=0

if ! command -v "$PHP_BIN" > /dev/null 2>&1; then
	printf 'PHP introuvable (%s)\n' "$PHP_BIN" >&2
	exit 2
fi

bancs=(
	test-adresse.php
	test-politiques.php
	test-verrou.php
	test-limite.php
	test-jeton.php
	test-emission.php
	test-verification.php
	test-idempotence.php
	test-changement-adresse.php
	test-courriel-verification.php
	test-envoi.php
	test-comptes-controller.php
	test-inscription.php
)

for banc in "${bancs[@]}"; do
	if [ ! -f "$banc" ]; then
		printf '\033[31m✗ %s : fichier absent\033[0m\n' "$banc"
		echecs=$(( echecs + 1 ))
		continue
	fi

	if ! sortie=$( "$PHP_BIN" "$banc" 2>&1 ); then
		printf '\033[31m✗ %s\033[0m\n' "$banc"
		printf '%s\n' "$sortie" | tail -20
		echecs=$(( echecs + 1 ))
		continue
	fi

	# Un code de sortie nul ne suffit PAS. La garde `defined( 'ABSPATH' ) || exit;`
	# que portent les fichiers d'extension termine le processus sans rien dire,
	# code 0 : un banc muet passerait alors pour un banc vert. On exige donc le
	# verdict, pas seulement l'absence d'erreur.
	if ! printf '%s' "$sortie" | grep -q 'TOUS LES CONTROLES PASSENT'; then
		printf '\033[31m✗ %s : aucun verdict rendu (banc muet ?)\033[0m\n' "$banc"
		printf '%s\n' "$sortie" | tail -20
		echecs=$(( echecs + 1 ))
		continue
	fi

	printf '\033[32m✓ %s\033[0m\n' "$banc"
done

echo

if [ "$echecs" -eq 0 ]; then
	printf '\033[32mLes %d bancs passent.\033[0m\n' "${#bancs[@]}"
	exit 0
fi

printf '\033[31m%d banc(s) en échec.\033[0m\n' "$echecs"
exit 1
