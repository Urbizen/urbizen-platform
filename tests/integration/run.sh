#!/usr/bin/env bash
#
# Banc d'intégration contre un WordPress réel et jetable.
#
# Il ne touche jamais à la production. Usage :
#
#   URBIZEN_WP_ROOT=/chemin/vers/wordpress tests/integration/run.sh
#
# Le banc HTTP du parcours des comptes ne s'exécute que si URBIZEN_HTTP_BASE
# pointe sur un `admin-post.php` servi localement.
#
# Sans installation disponible, le banc s'abstient et le signale, sans échouer :
# la suite complète reste exécutable sur une machine sans base de données.

set -u

PHP_BIN="${PHP_BIN:-php}"
ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
	printf 'PHP introuvable (PHP_BIN=%s).\n' "$PHP_BIN" >&2
	exit 1
fi

if [ -z "${URBIZEN_WP_ROOT:-}" ]; then
	printf 'URBIZEN_WP_ROOT non défini : banc réel ignoré.\n' >&2
	exit 0
fi

echecs=0

# Cœur métier des demandes, puis concurrence sur les mêmes.
"$PHP_BIN" "$ICI/test-coeur-reel.php" || echecs=$(( echecs + 1 ))
"$PHP_BIN" "$ICI/test-concurrence-reelle.php" || echecs=$(( echecs + 1 ))

# Socle des comptes (E2.1) — annoncé dans le journal, désormais réellement
# exécuté : le raccordement manquait.
"$PHP_BIN" "$ICI/test-comptes-reel.php" || echecs=$(( echecs + 1 ))

# Parcours public des comptes (E2.2) en HTTP réel — anti-énumération comprise.
# Il s'abstient de lui-même si URBIZEN_HTTP_BASE n'est pas fourni.
"$PHP_BIN" "$ICI/test-comptes-http-reel.php" || echecs=$(( echecs + 1 ))

if [ "$echecs" -ne 0 ]; then
	printf '\n%d banc(s) d’intégration en échec.\n' "$echecs" >&2
	exit 1
fi

printf '\nTous les bancs d’intégration raccordés passent.\n'
