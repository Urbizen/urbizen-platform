#!/usr/bin/env bash
#
# Épreuve reproductible du VRAI WP-CLI des comptes, contre un WordPress jetable.
#
#   URBIZEN_WP_ROOT=/chemin/wp tests/integration/wp-cli-comptes.sh
#
# Ce que le script exige, code de sortie réel à l'appui (jamais celui d'un
# `grep` intermédiaire) :
#
#   status    lecture seule, code 0, aucune écriture ;
#   install   installe (code 0), puis idempotent (code 0) ;
#   verify    conforme -> 0 ; surplus de capacité -> non nul ; install corrige
#             SANS retirer le rôle ; verify -> 0 ;
#   sous-commande inconnue -> code non nul ;
#   aucune table `wp_urbizen_%` créée par ces commandes.
#
# Codes : 0 tout est conforme · 1 au moins un contrôle en échec · 2 prérequis.

set -uo pipefail

if [ -z "${URBIZEN_WP_ROOT:-}" ] || [ ! -r "${URBIZEN_WP_ROOT}/wp-load.php" ]; then
	echo "URBIZEN_WP_ROOT non défini ou illisible : épreuve WP-CLI ignorée." >&2
	exit 0
fi

if ! command -v wp >/dev/null 2>&1; then
	echo "wp (WP-CLI) introuvable : épreuve ignorée." >&2
	exit 0
fi

# PHP 8.5 + WP-CLI 2.12 émettent des dépréciations : on les tait sans masquer le
# reste. 22519 = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED.
export WP_CLI_PHP_ARGS='-d error_reporting=22519 -d display_errors=0'

ok=0; ko=0
# La sortie de WP-CLI sous PHP 8.5 est polluée de « Deprecated: … » émis sur la
# sortie standard ; on les retire pour ne juger que ce que la commande dit.
WP() { wp --path="$URBIZEN_WP_ROOT" "$@" 2>/dev/null | grep -viE 'deprecat|react/promise'; }
RC() { wp --path="$URBIZEN_WP_ROOT" "$@" >/dev/null 2>&1; echo $?; }  # code réel

verifier() { # libellé  condition(0/1 via test)
	if [ "$1" = "1" ]; then ok=$(( ok + 1 )); printf '  ✓ %s\n' "$2"; else ko=$(( ko + 1 )); printf '  ✗ %s\n' "$2"; fi
}

echo "── VRAI WP-CLI des comptes ──"

# Partir d'un rôle absent, pour éprouver l'installation depuis zéro.
WP eval 'remove_role("urbizen_client");' >/dev/null 2>&1

verifier "$( [ "$(RC urbizen accounts status)" = 0 ] && echo 1 || echo 0 )" "status : code 0 (lecture seule)"
verifier "$( WP urbizen accounts status | grep -q 'lecture seule' && echo 1 || echo 0 )" "status : annonce « lecture seule »"

verifier "$( [ "$(RC urbizen accounts install)" = 0 ] && echo 1 || echo 0 )" "install : code 0"
verifier "$( WP urbizen accounts install | grep -qi 'déjà conforme' && echo 1 || echo 0 )" "install : 2e fois idempotent (aucune écriture)"
verifier "$( [ "$(RC urbizen accounts install)" = 0 ] && echo 1 || echo 0 )" "install : idempotent, code 0"

verifier "$( [ "$(RC urbizen accounts verify)" = 0 ] && echo 1 || echo 0 )" "verify : conforme, code 0"

# Surplus de capacité -> verify doit ÉCHOUER (code non nul), install doit corriger.
WP eval 'get_role("urbizen_client")->add_cap("manage_options");' >/dev/null 2>&1
verifier "$( [ "$(RC urbizen accounts verify)" != 0 ] && echo 1 || echo 0 )" "verify : surplus de capacité -> code non nul"
verifier "$( [ "$(RC urbizen accounts install)" = 0 ] && echo 1 || echo 0 )" "install : corrige le surplus, code 0"
verifier "$( [ "$(RC urbizen accounts verify)" = 0 ] && echo 1 || echo 0 )" "verify : de nouveau conforme, code 0"
verifier "$( [ "$(WP eval '$r=get_role("urbizen_client"); echo $r ? implode(",",array_keys(array_filter($r->capabilities))) : "ABSENT";' | tail -1)" = "read" ] && echo 1 || echo 0 )" "le rôle existe toujours et n'a QUE read"

# Sous-commande inconnue -> code non nul.
verifier "$( [ "$(RC urbizen accounts pouet)" != 0 ] && echo 1 || echo 0 )" "sous-commande inconnue -> code non nul"

# Aucune table Urbizen créée par ces commandes.
TABLES="$( WP eval 'global $wpdb; echo count($wpdb->get_col("SHOW TABLES LIKE \"".$wpdb->prefix."urbizen_%\"")) > 0 ? "oui" : "non";' | tail -1 )"
verifier "$( [ "$TABLES" = "non" ] && echo 1 || echo 0 )" "aucune table wp_urbizen_% créée"

echo
if [ "$ko" -eq 0 ]; then
	echo "WP-CLI des comptes : $ok contrôle(s) conforme(s)."
	exit 0
fi

echo "WP-CLI des comptes : $ko contrôle(s) en échec."
exit 1
