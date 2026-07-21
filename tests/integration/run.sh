#!/usr/bin/env bash
#
# Banc d'intégration contre un WordPress réel et jetable.
#
# Il ne touche jamais à la production. Deux usages :
#
#   URBIZEN_WP_ROOT=/chemin/vers/wordpress tests/integration/run.sh
#   tests/integration/run.sh --provisionner   (télécharge WordPress dans un
#                                              répertoire temporaire, exige un
#                                              MySQL joignable)
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

"$PHP_BIN" "$ICI/test-coeur-reel.php" || exit 1
"$PHP_BIN" "$ICI/test-concurrence-reelle.php"
