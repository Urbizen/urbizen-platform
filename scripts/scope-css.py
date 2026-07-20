#!/usr/bin/env python3
"""Porte une feuille de style sous une classe de portée.

Sert à charger le CSS de la maquette `frontend/homepage/` dans WordPress sans
qu'il déborde sur le thème parent ni sur Kadence Blocks : chaque sélecteur est
préfixé par une classe racine, sans qu'aucune valeur ne soit modifiée.

    python3 scripts/scope-css.py \\
        frontend/homepage/homepage.css \\
        wordpress/urbizen-child/assets/css/urbizen-homepage.css \\
        .urbizen-accueil

Règles de transformation
------------------------

    :root { … }      →  .urbizen-accueil { … }
    body { … }       →  .urbizen-accueil { … }
    body .x { … }    →  .urbizen-accueil .x { … }
    * { … }          →  .urbizen-accueil, .urbizen-accueil * { … }
    html { … }       →  html { … }              (inchangé)
    .foo { … }       →  .urbizen-accueil .foo { … }

`:root` et `body` désignent la racine du document. Les préfixer produirait
`.urbizen-accueil :root`, un sélecteur qui **ne peut jamais correspondre** —
`:root` est un ancêtre de la portée, jamais un descendant. C'est le défaut qui
a rendu muette la règle `@media (max-width: 420px) { :root { --u-pad: 18px } }`
et décalé de 10 px la rupture de l'en-tête mobile. Les variables destinées au
document sont donc portées par le conteneur de portée, d'où elles cascadent au
sous-arbre tout en restant confinées.

`html` reste global : il porte des comportements de document — `scroll-behavior`
notamment — et non des styles de page.

Le script ne réécrit ni les valeurs, ni les blocs `@media`, ni les commentaires,
ni la mise en forme : seuls les sélecteurs changent. `verifier_declarations()`
compare les déclarations avant et après pour le garantir.
"""

import re
import sys
from pathlib import Path

RACINE_DOCUMENT = (":root", "body")
GLOBAUX = ("html",)


def scoper_selecteur(selecteur: str, portee: str) -> str:
    """Préfixe un sélecteur, en traitant à part les racines du document."""
    parties: list[str] = []

    for brut in selecteur.split(","):
        p = brut.strip()

        if not p:
            continue

        if p == "*":
            # Le reset universel est borné à la portée pour ne pas déborder
            # sur l'administration ni sur les autres pages.
            parties.append(portee)
            parties.append(f"{portee} *")
        elif p in GLOBAUX:
            parties.append(p)
        elif p in RACINE_DOCUMENT:
            parties.append(portee)
        elif p.startswith("body "):
            parties.append(portee + p[len("body"):])
        elif p.startswith(":root "):
            parties.append(portee + p[len(":root"):])
        elif p.startswith(portee):
            parties.append(p)
        else:
            parties.append(f"{portee} {p}")

    return ", ".join(parties)


def scoper(css: str, portee: str) -> str:
    """Applique le scoping à une feuille entière."""
    sortie: list[str] = []
    i, n = 0, len(css)

    while i < n:
        if css.startswith("/*", i):
            j = css.index("*/", i) + 2
            sortie.append(css[i:j])
            i = j
            continue

        # @media / @supports : on entre dans le bloc pour scoper son contenu
        m = re.match(r"\s*@(media|supports)[^{]*\{", css[i:])
        if m:
            sortie.append(css[i:i + m.end()])
            i += m.end()
            continue

        # @keyframes / @font-face / @import : recopiés tels quels
        m = re.match(r"\s*@(keyframes|font-face|import|charset)[^{;]*[{;]", css[i:])
        if m:
            bloc = css[i:i + m.end()]

            if bloc.rstrip().endswith("{"):
                profondeur, j = 1, i + m.end()

                while j < n and profondeur:
                    profondeur += (css[j] == "{") - (css[j] == "}")
                    j += 1

                sortie.append(css[i:j])
                i = j
            else:
                sortie.append(bloc)
                i += m.end()

            continue

        if css[i] == "}":
            sortie.append("}")
            i += 1
            continue

        m = re.match(r"([^{}@]+)\{([^{}]*)\}", css[i:])
        if m:
            brut = m.group(1)
            commentaires = re.findall(r"/\*.*?\*/", brut, flags=re.S)
            selecteur = re.sub(r"/\*.*?\*/", "", brut, flags=re.S)
            blancs = re.match(r"\s*", brut).group(0)
            entete = "\n".join(commentaires) + "\n" if commentaires else ""
            sortie.append(blancs + entete + scoper_selecteur(selecteur, portee) + " {" + m.group(2) + "}")
            i += m.end()
            continue

        sortie.append(css[i])
        i += 1

    return "".join(sortie)


def declarations(css: str) -> list[tuple[str, str]]:
    """Liste triée des couples propriété/valeur, commentaires exclus."""
    sans_commentaires = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    return sorted(re.findall(r"([-a-z]+)\s*:\s*([^;{}]+)[;}]", sans_commentaires))


def verifier_declarations(avant: str, apres: str) -> None:
    """Échoue si une valeur a bougé : seuls les sélecteurs doivent changer."""
    a, b = declarations(avant), declarations(apres)

    if a != b:
        ecarts = [f"{x} -> {y}" for x, y in zip(a, b) if x != y]
        raise SystemExit(
            f"scope-css : {len(a)} déclarations avant, {len(b)} après — "
            f"des valeurs ont changé : {ecarts[:5]}"
        )


ENTETE = """/* ============================================================================
   {nom} — styles de la page d'accueil Urbizen.

   Fichier GÉNÉRÉ par scripts/scope-css.py depuis {source}.
   Ne pas modifier à la main : régénérer.

   Le CSS est porté sous `{portee}` pour cohabiter avec les styles du
   thème parent et de Kadence Blocks. AUCUNE valeur graphique n'est modifiée :
   seuls les sélecteurs changent. Le reset `*` est lui aussi borné à la portée,
   pour ne pas déborder sur l'administration ni sur les autres pages. Seul
   `html {{ scroll-behavior }}` reste global : c'est un comportement du document.

   `:root` et `body` deviennent `{portee}` : les préfixer donnerait un
   sélecteur impossible à satisfaire, et les variables qu'ils déclarent
   seraient perdues.

   Dépend de urbizen-tokens.css et urbizen-fonts.css.
   ========================================================================== */

"""


def main() -> None:
    if len(sys.argv) != 4:
        raise SystemExit("usage : scope-css.py <source.css> <destination.css> <.portee>")

    source, destination, portee = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
    css = source.read_text()
    porte = scoper(css, portee)
    verifier_declarations(css, porte)

    # L'en-tête d'origine est remplacé par celui du fichier généré.
    corps = porte[porte.index("*/") + 2:].lstrip("\n") if porte.lstrip().startswith("/*") else porte
    entete = ENTETE.format(nom=destination.name, source=source, portee=portee)
    destination.write_text(entete + corps)

    print(f"{destination} écrit — {len(declarations(css))} déclarations, inchangées")


if __name__ == "__main__":
    main()
