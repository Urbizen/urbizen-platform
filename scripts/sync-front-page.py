#!/usr/bin/env python3
"""Synchronise templates/front-page.html sur le gabarit « Accueil Urbizen ».

Pourquoi deux fichiers
----------------------

Pour la page définie comme page d'accueil du site (`show_on_front = page`),
la hiérarchie de gabarits de WordPress consulte `front-page` **avant** le
gabarit personnalisé de la page. Le thème parent fournissant son propre
`templates/front-page.html`, affecter « Accueil Urbizen » à la page 4 restait
sans effet : le gabarit personnalisé n'était jamais atteint.

Le thème enfant fournit donc son propre `front-page.html`, qui supplante
naturellement celui du parent — mécanisme natif de WordPress, sans filtre PHP
sur `frontpage_template_hierarchy` et sans modification du thème parent.

`page-accueil-urbizen.html` est conservé : c'est lui qui sert la page brouillon
1162 et les prévisualisations, et il reste le gabarit assignable depuis
l'éditeur à toute autre page.

Pourquoi une copie stricte
--------------------------

Deux fichiers, c'est deux occasions de diverger. Trois garde-fous :

1. ce script régénère `front-page.html` depuis la source, en une commande ;
2. `--verifier` échoue si les deux fichiers diffèrent, ne serait-ce que d'un
   octet — ce mode est appelé par tests/homepage/test-front-page.php ;
3. la copie est *strictement* identique : aucun en-tête « fichier généré »
   n'est inséré, afin que l'égalité binaire reste l'invariant, le plus simple
   et le plus solide à contrôler.

La source de vérité reste `page-accueil-urbizen.html`. Toute modification s'y
fait, puis :

    python3 scripts/sync-front-page.py

Usage
-----

    python3 scripts/sync-front-page.py            # régénère front-page.html
    python3 scripts/sync-front-page.py --verifier  # ne modifie rien, code 1 si écart
"""

import hashlib
import sys
from pathlib import Path

THEME = Path(__file__).resolve().parents[1] / "wordpress" / "urbizen-child" / "templates"
SOURCE = THEME / "page-accueil-urbizen.html"
CIBLE = THEME / "front-page.html"


def empreinte(chemin: Path) -> str:
    return hashlib.sha256(chemin.read_bytes()).hexdigest()


def main() -> None:
    verifier = "--verifier" in sys.argv[1:]

    if not SOURCE.is_file():
        raise SystemExit(f"sync-front-page : source introuvable — {SOURCE}")

    source = SOURCE.read_bytes()

    if verifier:
        if not CIBLE.is_file():
            raise SystemExit(f"sync-front-page : {CIBLE.name} est absent — lancer le script sans --verifier")

        if CIBLE.read_bytes() != source:
            raise SystemExit(
                f"sync-front-page : {CIBLE.name} a divergé de {SOURCE.name}\n"
                f"    {SOURCE.name} : {empreinte(SOURCE)}\n"
                f"    {CIBLE.name} : {empreinte(CIBLE)}\n"
                "    corriger la source puis relancer : python3 scripts/sync-front-page.py"
            )

        print(f"{CIBLE.name} identique à {SOURCE.name} — {len(source)} octets, {empreinte(SOURCE)[:16]}…")
        return

    CIBLE.write_bytes(source)
    print(f"{CIBLE} écrit — {len(source)} octets, copie stricte de {SOURCE.name}")


if __name__ == "__main__":
    main()
