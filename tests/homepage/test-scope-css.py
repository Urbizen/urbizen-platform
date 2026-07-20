#!/usr/bin/env python3
"""Test unitaire du scoping CSS — scripts/scope-css.py.

Vérifie la règle qui a manqué la première fois : `:root` et `body` désignent la
racine du document et ne doivent jamais être préfixés, sous peine de produire un
sélecteur impossible à satisfaire — `.urbizen-accueil :root` — et de perdre en
silence les variables qu'ils déclarent.

    python3 tests/homepage/test-scope-css.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

import importlib.util

chemin = Path(__file__).resolve().parents[2] / "scripts" / "scope-css.py"
spec = importlib.util.spec_from_file_location("scope_css", chemin)
scope_css = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scope_css)

PORTEE = ".urbizen-accueil"
echecs = 0


def check(label, obtenu, attendu):
    global echecs
    ok = obtenu == attendu
    if not ok:
        echecs += 1
    print(f"{label:<62} {'OK' if ok else 'ECHEC'}")
    if not ok:
        print(f"    attendu : {attendu!r}")
        print(f"    obtenu  : {obtenu!r}")


def scoper(css):
    return scope_css.scoper(css, PORTEE).strip()


# --- Les trois cas demandés -------------------------------------------------
check("`:root` devient la portée",
      scoper(":root { --x: 1; }"), ".urbizen-accueil { --x: 1; }")
check("`body` devient la portée",
      scoper("body { margin: 0; }"), ".urbizen-accueil { margin: 0; }")
check("un sélecteur normal est préfixé",
      scoper(".foo { display: block; }"), ".urbizen-accueil .foo { display: block; }")

# --- Le cas qui a produit le défaut ------------------------------------------
check("`:root` dans une media query devient la portée",
      scoper("@media (max-width: 420px) { :root { --u-pad: 18px; } }"),
      "@media (max-width: 420px) { .urbizen-accueil { --u-pad: 18px; } }")

# --- Règles voisines ---------------------------------------------------------
check("`html` reste global",
      scoper("html { scroll-behavior: smooth; }"), "html { scroll-behavior: smooth; }")
check("le reset universel est borné à la portée",
      scoper("* { box-sizing: border-box; }"),
      ".urbizen-accueil, .urbizen-accueil * { box-sizing: border-box; }")
check("`body .x` conserve sa descendance",
      scoper("body .x { color: red; }"), ".urbizen-accueil .x { color: red; }")
check("`:root .x` conserve sa descendance",
      scoper(":root .x { color: red; }"), ".urbizen-accueil .x { color: red; }")
check("une liste de sélecteurs est traitée terme à terme",
      scoper("body, .a, .b { color: red; }"),
      ".urbizen-accueil, .urbizen-accueil .a, .urbizen-accueil .b { color: red; }")
check("un sélecteur déjà porté n'est pas préfixé deux fois",
      scoper(".urbizen-accueil .a { color: red; }"), ".urbizen-accueil .a { color: red; }")
check("`@keyframes` est recopié tel quel",
      scoper("@keyframes p { from { opacity: 0; } to { opacity: 1; } }"),
      "@keyframes p { from { opacity: 0; } to { opacity: 1; } }")

# --- Aucun sélecteur mort ne doit pouvoir être produit ------------------------
echantillon = ":root{--a:1}body{margin:0}.c{top:0}@media (max-width:420px){:root{--a:2}body{margin:1px}}"
porte = scoper(echantillon)
check("aucun `.urbizen-accueil :root` produit", ":root" in porte, False)
check("aucun `.urbizen-accueil body` produit", "urbizen-accueil body" in porte, False)

# --- Les valeurs ne bougent jamais -------------------------------------------
source = Path(__file__).resolve().parents[2] / "frontend/homepage/homepage.css"
if source.is_file():
    css = source.read_text()
    resultat = scope_css.scoper(css, PORTEE)
    check("maquette : déclarations identiques après scoping",
          scope_css.declarations(css), scope_css.declarations(resultat))
    check("maquette : aucun !important ajouté",
          resultat.count("!important"), css.count("!important"))
    check("maquette : aucun sélecteur mort",
          ".urbizen-accueil :root" in resultat, False)

print()
print("TOUS LES CONTROLES PASSENT" if echecs == 0 else f"{echecs} CONTROLE(S) EN ECHEC")
sys.exit(0 if echecs == 0 else 1)
