#!/usr/bin/env bash
#
# Mutations du verrou et de la preuve d'unicité — chacune doit FAIRE TOMBER un
# contrôle nommé du banc d'inscription concurrente.
#
# Un test qui reste vert quand on casse le code qu'il prétend protéger ne prouve
# rien. Ce script applique, une à une, quatre mutations sur une COPIE du greffon,
# bascule le lien symbolique de l'installation jetable vers la copie mutée,
# relance le banc concurrent, et vérifie que le contrôle attendu passe au rouge.
# L'original n'est jamais modifié, et le lien est toujours rétabli.
#
#   URBIZEN_WP_ROOT=/chemin/wp tests/integration/test-inscription-mutations.sh
#
# Codes : 0 toutes les mutations sont bien détectées · 1 une mutation est passée
# inaperçue (le banc est aveugle) · 2 prérequis absent.

set -uo pipefail

ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN="$(cd "$ICI/../../wordpress/urbizen-platform" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
PHP_FLAGS=(-d error_reporting=22519 -d display_errors=0)

if [ -z "${URBIZEN_WP_ROOT:-}" ] || [ ! -r "${URBIZEN_WP_ROOT}/wp-load.php" ]; then
	echo "URBIZEN_WP_ROOT non défini ou illisible : mutations ignorées." >&2
	exit 0
fi

LIEN="${URBIZEN_WP_ROOT}/wp-content/plugins/urbizen-platform"
ORIGINE="$(readlink "$LIEN" || true)"
MUT="$(mktemp -d "${TMPDIR:-/tmp}/urbizen-mutant-XXXXXX")"

restaurer() {
	ln -sfn "$PLUGIN" "$LIEN"
	rm -rf "$MUT"
}
trap restaurer EXIT

echecs=0

# Applique une transformation Python à un fichier de la copie, puis relance le
# banc et exige que le contrôle nommé $2 tombe (ECHEC).
#
# Les mutations qui ouvrent une COURSE (M1, M2) sont détectées de façon
# probabiliste : la fenêtre de doublon peut se refermer sur une exécution
# chanceuse. On réessaie donc jusqu'à `$5` fois — un défaut réel finit par se
# manifester ; un banc réellement aveugle échoue les N fois. Les mutations
# DÉTERMINISTES (M3, M4, scénario D) sont détectées du premier coup ($5 = 1).
#
#   verifier_mutation "libellé" "fragment du contrôle" fichier 'py' tentatives
verifier_mutation() {
	local libelle="$1" attendu="$2" fichier="$3" py="$4" tentatives="${5:-1}"

	rm -rf "$MUT"; mkdir -p "$MUT"
	cp -RL "$PLUGIN"/. "$MUT/"

	if ! "$PHP_BIN" -r "\$f='$MUT/$fichier'; \$s=file_get_contents(\$f); $py file_put_contents(\$f,\$s);"; then
		echo "✗ $libelle : mutation non appliquée (motif introuvable ?)" >&2
		echecs=$(( echecs + 1 ))
		return
	fi

	if ! "$PHP_BIN" -l "$MUT/$fichier" >/dev/null 2>&1; then
		echo "✗ $libelle : la copie mutée ne compile pas" >&2
		echecs=$(( echecs + 1 ))
		return
	fi

	ln -sfn "$MUT" "$LIEN"

	local detecte=0 essai
	for (( essai = 1; essai <= tentatives; essai++ )); do
		local sortie
		sortie="$( URBIZEN_WP_ROOT="$URBIZEN_WP_ROOT" "$PHP_BIN" "${PHP_FLAGS[@]}" \
			"$ICI/test-inscription-concurrente.php" 2>/dev/null )"

		if printf '%s\n' "$sortie" | grep -F "$attendu" | grep -q 'ECHEC'; then
			detecte=1
			break
		fi
	done

	ln -sfn "$PLUGIN" "$LIEN"

	if [ "$detecte" = 1 ]; then
		echo "✓ $libelle → « $attendu » tombe bien (essai $essai/$tentatives)"
	else
		echo "✗ $libelle → « $attendu » N'A PAS été détecté en échec sur $tentatives essais (banc aveugle)"
		echecs=$(( echecs + 1 ))
	fi
}

echo "── Mutations du verrou et de la preuve d'unicité ──"

# M1 · retirer l'acquisition de l'exclusion : GET_LOCK toujours « accordé » sans
#      verrouiller réellement. La course A doit produire des doublons.
verifier_mutation \
	"M1 · acquisition du verrou retirée" \
	"A · exactement UN compte est créé en base" \
	"src/Account/VerrouAdresse.php" \
	'$s=preg_replace("/\\\$obtenu = \\\$db->valeur\\([^;]*\\);/", "\$obtenu = \"1\"; // MUTANT: verrou neutralisé", $s, 1, $c); if($c!==1){exit(1);}' \
	4

# M2 · libérer l'exclusion AVANT wp_insert_user : la section critique n'est plus
#      protégée pendant la création. Doublons dans la course A.
verifier_mutation \
	"M2 · libération avant création" \
	"A · exactement UN compte est créé en base" \
	"src/Account/InscriptionService.php" \
	'$s=preg_replace("/(\\\$id = \\\$this->creer_avec_identifiant_unique\\()/", "\$verrou->liberer(); // MUTANT: liberation prematuree\n\t\t\t\t\$1", $s, 1, $c); if($c!==1){exit(1);}' \
	6

# M3 · ignorer compter !== 1 : la preuve d'unicité APRÈS création ne bloque plus.
#      Avec deux comptes préexistants, D ne refuse plus.
verifier_mutation \
	"M3 · preuve d'unicité ignorée" \
	"D · l’inscription échoue de façon restrictive" \
	"src/Account/InscriptionService.php" \
	'$s=preg_replace("/if \\( \\\$deja > 1 \\)/", "if ( false )", $s, 1, $c1); $s=preg_replace("/if \\( 1 !== \\\$apres \\|\\| null === \\\$relu \\|\\| \\\$relu->id\\(\\) !== \\\$id \\)/", "if ( false )", $s, 1, $c2); if($c1!==1 || $c2!==1){exit(1);}'

# M4 · prendre le premier compte quand plusieurs existent : le garde-fou
#      « plus d'un » saute, on retombe sur trouver_par_adresse() (premier). D tombe.
verifier_mutation \
	"M4 · premier compte pris malgré le doublon" \
	"D · l’inscription échoue de façon restrictive" \
	"src/Account/InscriptionService.php" \
	'$s=preg_replace("/if \\( \\\$deja > 1 \\)/", "if ( false )", $s, 1, $c); if($c!==1){exit(1);}'

echo
if [ "$echecs" -eq 0 ]; then
	echo "Les 4 mutations sont détectées : le banc n'est pas aveugle."
	exit 0
fi

echo "$echecs mutation(s) passée(s) inaperçue(s)."
exit 1
