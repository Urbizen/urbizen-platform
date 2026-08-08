#!/usr/bin/env python3
"""Banc de géométrie de l'en-tête — mesure réelle, dans un vrai moteur de rendu.

Pourquoi ce banc existe
-----------------------

`test-cibles-tactiles.php` vérifie la cascade CSS : quelle déclaration s'applique
à quel sélecteur, dans quel palier. C'est nécessaire, mais insuffisant. Une règle
peut être parfaitement placée et le résultat visuel rester faux, parce que la
mise en page dépend de valeurs qu'aucune lecture de feuille ne donne : la hauteur
qu'un conteneur prend réellement, l'endroit où un élément retombe après un
retour à la ligne, ce qu'il recouvre en tombant.

C'est exactement ce qui s'est produit en recette de production sur la PR #56 :
le CTA passait bien sur une seconde ligne, avec ses 44 px de haut — mais `.nav`
porte une **hauteur fixe**, si bien que la seconde ligne sortait de la boîte de
l'en-tête et venait recouvrir le lanceur de chat. Deux cibles tactiles
superposées, dans une tranche dont c'était précisément le sujet. Les 428
contrôles étaient verts.

Ce banc ferme cette porte : il charge la maquette dans Chrome, lui impose des
largeurs, et relève des rectangles réels.

Invariants vérifiés
-------------------

1. aucune cible de l'en-tête ne descend sous la boîte de l'en-tête ;
2. aucun chevauchement entre les contrôles de l'en-tête ;
3. aucun chevauchement entre un contrôle de l'en-tête et un autre élément
   interactif visible de la page ;
4. le contenu suivant commence sous l'en-tête, sans vide artificiel ;
5. les quatre cibles restent à 44 × 44 en mode mobile ;
6. aucun débordement horizontal ;
7. le desktop garde sa hauteur d'en-tête.

Le lanceur de chat
------------------

Il est injecté après chargement par un service tiers ; il n'existe **pas** dans
le dépôt. Le banc le cherche, et **déclare explicitement son absence** plutôt
que de la passer sous silence — l'invariant 3 le couvrirait s'il était présent,
et l'invariant 1 attrape la cause en amont, chat ou pas. Sa vérification en
conditions réelles reste une étape de la recette de production.

Prérequis : Python 3 et Google Chrome. Sans Chrome, le banc sort en code 2
(prérequis absent) — jamais en succès silencieux.

    python3 test-geometrie-entete.py
"""

import http.server
import json
import os
import re
import shutil
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading

RACINE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

CHROMES = [
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Chromium.app/Contents/MacOS/Chromium",
    "google-chrome",
    "google-chrome-stable",
    "chromium",
    "chromium-browser",
]

# Largeurs où l'en-tête est en mode mobile (le burger y est affiché).
MOBILES = [320, 340, 341, 360, 375, 379, 380, 381, 390, 430, 1100]

echecs = 0


def check(libelle, condition, detail=""):
    global echecs
    if not condition:
        echecs += 1
    print("%-74s %s" % (libelle, "OK" if condition else "ECHEC"))
    if not condition and detail:
        print("    " + detail)


def trouver_chrome():
    for c in CHROMES:
        if os.path.isfile(c):
            return c
        trouve = shutil.which(c)
        if trouve:
            return trouve
    return None


class Silencieux(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def servir(racine):
    """Sert le dépôt sur un port libre, dans un fil séparé."""
    handler = lambda *a, **k: Silencieux(*a, directory=racine, **k)
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    httpd = socketserver.TCPServer(("127.0.0.1", port), handler)
    httpd.allow_reuse_address = True
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd, port


def mesurer(chrome, url):
    """Lance Chrome sans interface et récupère le JSON écrit dans le DOM.

    Deux précautions, apprises à l'usage :

    - `--host-resolver-rules` coupe le réseau hors boucle locale. Sans cela, les
      polices distantes de la maquette laissent des requêtes en attente et le
      budget de temps virtuel n'expire jamais ;
    - Chrome écrit le DOM puis ne rend pas toujours la main. On lit donc ce qu'il
      a produit, et on le termine sans attendre sa bonne volonté.
    """
    profil = tempfile.mkdtemp(prefix="urbizen-banc-")
    proc = subprocess.Popen(
        [
            chrome,
            "--headless=new",
            "--disable-gpu",
            "--no-first-run",
            "--no-default-browser-check",
            "--disable-extensions",
            "--hide-scrollbars",
            "--user-data-dir=" + profil,
            "--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE 127.0.0.1",
            "--virtual-time-budget=8000",
            "--dump-dom",
            url,
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )

    try:
        dom, _ = proc.communicate(timeout=90)
    except subprocess.TimeoutExpired:
        proc.kill()
        dom, _ = proc.communicate()
    finally:
        shutil.rmtree(profil, ignore_errors=True)

    # Le verdict se lit dans `#resultat`, jamais ailleurs : le source du script
    # de mesure contient lui aussi les mots « JSON: » et « ERREUR: ».
    bloc = re.search(r'<pre id="resultat">(.*?)</pre>', dom or "", re.S)
    if not bloc:
        return None, "aucune mesure produite (Chrome a-t-il rendu la page ?)"

    contenu = bloc.group(1)
    contenu = (
        contenu.replace("&quot;", '"').replace("&amp;", "&").replace("&lt;", "<").replace("&gt;", ">")
    )

    if contenu.startswith("ERREUR:"):
        return None, "la page de mesure a échoué : " + contenu[7:].strip()

    if not contenu.startswith("JSON:"):
        return None, "mesure incomplète — la page en était encore à « %s »" % contenu.strip()[:40]

    try:
        return json.loads(contenu[5:]), None
    except json.JSONDecodeError as e:
        return None, "mesure illisible : %s" % e


def main():
    chrome = trouver_chrome()
    if not chrome:
        print("Google Chrome introuvable — ce banc mesure une mise en page réelle")
        print("et ne peut pas s'exécuter sans moteur de rendu.")
        print("Installez Chrome, ou lancez ce banc sur une machine qui en dispose.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    httpd, port = servir(RACINE)
    try:
        url = "http://127.0.0.1:%d/tests/homepage/geometrie-entete.html" % port
        donnees, erreur = mesurer(chrome, url)
    finally:
        httpd.shutdown()

    if donnees is None:
        print("Impossible de mesurer : %s" % erreur)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    # Le lanceur de chat est injecté par un tiers : constaté, jamais supposé.
    if donnees["source"].get("chat"):
        print("Lanceur de chat détecté dans la maquette : %s" % donnees["source"]["chat"])
    else:
        print("Lanceur de chat : ABSENT de l'environnement de test (injecté en")
        print("  production par un service tiers). L'invariant « aucune cible hors")
        print("  de l'en-tête » couvre la cause en amont ; l'intersection réelle")
        print("  avec le chat reste une étape de la recette de production.")

    # La feuille source et l'artefact porté doivent rendre à l'identique :
    # `scope-css.py` ne fait que préfixer. Un écart signale une régénération
    # oubliée — c'est le fichier porté que la production sert.
    check(
        "Feuille source et artefact porté rendent à l'identique",
        donnees["source"]["viewports"] == donnees["portee"]["viewports"],
        "les deux mesures divergent : le CSS porté a-t-il été régénéré ?",
    )

    for etiquette, bloc in (("source", donnees["source"]), ("porté", donnees["portee"])):
        verifier(etiquette, bloc)

    par_largeur = {v["largeur"]: v for v in donnees["portee"]["viewports"]}

    print("")
    print("hauteurs relevées sur l'artefact porté (header.site / .nav) :")
    for largeur, v in sorted(par_largeur.items()):
        print(
            "  %4dpx  header %5.1f  nav %5.1f  CTA y=%.0f→%.0f  contenu y=%.0f"
            % (
                largeur,
                v["entete"]["h"],
                v["nav"]["h"],
                v["cibles"]["CTA"]["y"],
                v["cibles"]["CTA"]["bas"],
                v["suivant"]["y"] if v["suivant"] else -1,
            )
        )

    print("")
    print("TOUS LES CONTROLES PASSENT" if echecs == 0 else "%d CONTROLE(S) EN ECHEC" % echecs)
    sys.exit(0 if echecs == 0 else 1)


def verifier(etiquette, bloc):
    """Applique tous les invariants à un jeu de mesures."""
    par_largeur = {v["largeur"]: v for v in bloc["viewports"]}
    p = "[%s] " % etiquette

    check(p + "la mesure couvre les 13 viewports attendus", len(par_largeur) == 13)
    if len(par_largeur) != 13:
        return

    # ------------------------------------------------ 1. rien ne sort de l'en-tête

    debordements = []
    for largeur, v in sorted(par_largeur.items()):
        for h in v["horsEntete"]:
            debordements.append("%dpx : %s dépasse de %spx" % (largeur, h["cible"], h["depasse"]))

    check(
        p + "Aucune cible ne descend sous la boîte de l'en-tête",
        not debordements,
        " | ".join(debordements),
    )

    # `.nav` ne doit jamais déborder de son propre parent : c'est la cause amont.
    nav_hors = [
        "%dpx : .nav dépasse header.site de %spx" % (l, v["enteteDepasseParNav"])
        for l, v in sorted(par_largeur.items())
        if v["enteteDepasseParNav"] > 0.5
    ]
    check(p + "La barre .nav reste contenue dans header.site", not nav_hors, " | ".join(nav_hors))

    # ------------------------------------------------------- 2 & 3. chevauchements

    entre = [
        "%dpx : %s" % (l, ", ".join(v["chevauchementsCibles"]))
        for l, v in sorted(par_largeur.items())
        if v["chevauchementsCibles"]
    ]
    check(p + "Aucun chevauchement entre les contrôles de l'en-tête", not entre, " | ".join(entre))

    page = [
        "%dpx : %s" % (l, ", ".join(v["chevauchementsPage"]))
        for l, v in sorted(par_largeur.items())
        if v["chevauchementsPage"]
    ]
    check(
        p + "Aucun chevauchement entre l'en-tête et un autre élément interactif",
        not page,
        " | ".join(page),
    )

    # ------------------------------------------- 4. le contenu suivant suit bien

    # Le hero doit commencer au bas de l'en-tête. L'en-tête est `sticky` : dans le
    # flux, le contenu vient juste après. Un écart négatif signifie que le contenu
    # passe dessous ; un écart important, un vide artificiel.
    suite = []
    for largeur, v in sorted(par_largeur.items()):
        if not v["suivant"]:
            continue
        ecart = round(v["suivant"]["y"] - v["entete"]["bas"], 1)
        if ecart < -0.5:
            suite.append("%dpx : le contenu remonte de %spx sous l'en-tête" % (largeur, -ecart))
        elif ecart > 2:
            suite.append("%dpx : vide de %spx entre l'en-tête et le contenu" % (largeur, ecart))

    check(p + "Le contenu suivant commence au bas réel de l'en-tête", not suite, " | ".join(suite))

    # ---------------------------------------------- 5. les cibles restent à 44 px

    petites = []
    for largeur in MOBILES:
        v = par_largeur[largeur]
        for cible, m in v["cibles"].items():
            if not m["visible"]:
                continue
            if cible == "CTA":
                if m["h"] < 44:
                    petites.append("%dpx : CTA haut de %s" % (largeur, m["h"]))
            elif m["w"] < 44 or m["h"] < 44:
                petites.append("%dpx : %s %s×%s" % (largeur, cible, m["w"], m["h"]))

    check(p + "Les quatre cibles restent ≥ 44 en mode mobile", not petites, " | ".join(petites))

    # ------------------------------------------------ 6. aucun débordement latéral

    larges = [
        "%dpx : scroll %s" % (l, v["scrollWidth"])
        for l, v in sorted(par_largeur.items())
        if v["scrollWidth"] > v["clientWidth"]
    ]
    check(p + "Aucun débordement horizontal", not larges, " | ".join(larges))

    # --------------------------------------------------- 7. le repli et le desktop

    # Le repli se lit sur la position du CTA par rapport au burger — encore
    # faut-il que le burger soit affiché : masqué, son rectangle est nul et
    # n'importe quel CTA paraîtrait « en dessous ».
    replies = [
        l
        for l, v in sorted(par_largeur.items())
        if v["cibles"]["burger"]["visible"] and v["cibles"]["CTA"]["y"] > v["cibles"]["burger"]["y"] + 10
    ]
    check(
        p + "Le repli couvre exactement 320 → 380 px",
        replies == [320, 340, 341, 360, 375, 379, 380],
        "trouvé : %s" % replies,
    )

    # Au-delà du palier mobile, la hauteur d'en-tête ne bouge pas.
    check(
        p + "Desktop : l'en-tête garde sa hauteur historique (70 px)",
        par_largeur[1101]["nav"]["h"] == 70 and par_largeur[1280]["nav"]["h"] == 70,
        "1101 → %s / 1280 → %s" % (par_largeur[1101]["nav"]["h"], par_largeur[1280]["nav"]["h"]),
    )

    check(
        p + "Le burger disparaît au-delà de 1100 px",
        not par_largeur[1101]["cibles"]["burger"]["visible"]
        and par_largeur[1100]["cibles"]["burger"]["visible"],
    )


if __name__ == "__main__":
    main()
