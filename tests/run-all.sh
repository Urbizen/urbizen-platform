#!/usr/bin/env bash
#
# Lanceur global — exécute TOUTES les suites `tests/*/run-all.sh`.
#
#   ./tests/run-all.sh                 # toutes les suites
#   ./tests/run-all.sh --arret         # s'arrête à la première en échec
#   ./tests/run-all.sh seo homepage    # seulement celles-là
#
# POURQUOI CE FICHIER EXISTE
#
# Il n'y en avait pas. Chaque suite avait son lanceur, et le tour complet se
# faisait à la main — une boucle shell écrite au fil de l'eau, dont le code de
# sortie était celui de la boucle et non celui des suites. Résultat : un tour
# complet pouvait afficher une suite rouge et rendre 0. C'est exactement ce qui
# permet à une régression de passer.
#
# Trois règles, et elles ne se négocient pas :
#
#   1. UNE SUITE ROUGE REND LE TOUR ROUGE. Le code de sortie est non nul dès
#      qu'une suite échoue, quel que soit le nombre de suites vertes.
#   2. UN PRÉREQUIS ABSENT N'EST PAS UN SUCCÈS. Les suites signalent ce cas par
#      le code 2 ; il est compté à part, affiché à part, et rend lui aussi le
#      tour non nul. Une mesure qui n'a pas eu lieu ne prouve rien.
#   3. AUCUNE SUITE N'EST OUBLIÉE. La liste est découverte sur le disque, pas
#      écrite en dur : une suite ajoutée demain entre d'elle-même dans le tour.
#
# Codes de sortie : 0 tout vert · 1 au moins une suite en échec ·
#                   2 tout vert mais au moins un prérequis absent.

set -uo pipefail
cd "$(dirname "$0")"

ARRET=0
DEMANDEES=()
for arg in "$@"; do
	case "$arg" in
		--arret|--stop|-x) ARRET=1 ;;
		-*) echo "Option inconnue : $arg"; exit 64 ;;
		*)  DEMANDEES+=("$arg") ;;
	esac
done

# Découverte sur le disque, triée : le tour est reproductible d'une fois sur
# l'autre, et une suite nouvelle n'a rien à déclarer pour être exécutée.
SUITES=()
for f in */run-all.sh; do
	nom="$(dirname "$f")"
	if [ "${#DEMANDEES[@]}" -gt 0 ]; then
		for d in "${DEMANDEES[@]}"; do [ "$d" = "$nom" ] && SUITES+=("$nom"); done
	else
		SUITES+=("$nom")
	fi
done

if [ "${#SUITES[@]}" -eq 0 ]; then
	echo "Aucune suite à exécuter."
	exit 64
fi

total="${#SUITES[@]}"
echecs=()
absents=()
verts=()
i=0
debut=$(date +%s)

for nom in "${SUITES[@]}"; do
	i=$((i + 1))
	printf '\n\033[1m═══ %s/%s — %s ═══\033[0m\n' "$i" "$total" "$nom"

	# Le code de sortie de la SUITE, pas celui d'un tube ni d'un `tee`.
	bash "$nom/run-all.sh"
	code=$?

	case "$code" in
		0) verts+=("$nom");   printf '\033[32m✓ %s\033[0m\n' "$nom" ;;
		2) absents+=("$nom"); printf '\033[33m⚠ %s — prérequis absent, banc NON EXÉCUTÉ\033[0m\n' "$nom" ;;
		*) echecs+=("$nom");  printf '\033[31m✗ %s (code %s)\033[0m\n' "$nom" "$code"
		   if [ "$ARRET" -eq 1 ]; then
			   printf '\n\033[31mArrêt demandé à la première suite en échec.\033[0m\n'
			   break
		   fi ;;
	esac
done

duree=$(( $(date +%s) - debut ))

printf '\n\033[1m═══ BILAN ═══\033[0m\n'
printf '%s suite(s) exécutée(s) en %s min %s s\n' "$i" "$((duree / 60))" "$((duree % 60))"
printf '\033[32m%s verte(s)\033[0m' "${#verts[@]}"
[ "${#absents[@]}" -gt 0 ] && printf ' · \033[33m%s non exécutée(s)\033[0m' "${#absents[@]}"
[ "${#echecs[@]}"  -gt 0 ] && printf ' · \033[31m%s en échec\033[0m' "${#echecs[@]}"
printf '\n'

if [ "${#absents[@]}" -gt 0 ]; then
	printf '\033[33mNon exécutée(s) : %s\033[0m\n' "${absents[*]}"
fi
if [ "${#echecs[@]}" -gt 0 ]; then
	printf '\033[31mEn échec : %s\033[0m\n' "${echecs[*]}"
	exit 1
fi
if [ "${#absents[@]}" -gt 0 ]; then
	printf '\033[33mAucun échec, mais le tour est INCOMPLET.\033[0m\n'
	exit 2
fi

printf '\033[32m%s/%s — tout est vert.\033[0m\n' "${#verts[@]}" "$total"
exit 0
