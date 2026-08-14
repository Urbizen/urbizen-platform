#!/usr/bin/env python3
"""Banc du parcours « Écrire à Urbizen » → « Demander des renseignements ».

Deux accès mènent au même formulaire : le canal du panneau « Parlons de votre
projet » et l'entrée du menu mobile. Ce banc vérifie qu'ils empruntent le même
chemin et le mènent jusqu'au bout.

Pourquoi dans un navigateur
---------------------------

Presque rien de ce qui compte ici ne se lit dans le balisage. Qu'un dialogue se
referme, qu'un panneau se déplie, que le focus atterrisse sur un titre et non
dans un champ, que le défilement du document soit rendu après avoir été
verrouillé — ce sont des états d'exécution. Un banc statique dirait que les
attributs sont présents ; il ne dirait pas que le parcours fonctionne.

Ce que le banc refuse de laisser passer
---------------------------------------

1. un déclencheur qui manque, ou qui n'est pas un vrai lien vers l'ancre ;
2. un menu mobile qui reste ouvert derrière le formulaire ;
3. un `aria-expanded` qui ment sur l'état réel ;
4. un dialogue fermé mais laissant le défilement du document verrouillé ;
5. **un second clic qui referme un bloc déjà ouvert** — le piège du basculement ;
6. le bouton natif qui perdrait sa capacité à ouvrir ET refermer ;
7. le focus posé dans un champ de formulaire, qui lèverait le clavier virtuel ;
8. une section qui atterrit sous l'en-tête collant ;
9. un débordement horizontal, ou la moindre erreur JavaScript.

Prérequis : Python 3 et Google Chrome. Sans Chrome, sortie en code 2
(prérequis absent) — jamais un succès silencieux.

    python3 test-parcours-renseignements.py
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

ANCRE = "#demander-des-renseignements"

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
    handler = lambda *a, **k: Silencieux(*a, directory=racine, **k)
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    httpd = socketserver.TCPServer(("127.0.0.1", port), handler)
    httpd.allow_reuse_address = True
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd, port


def mesurer(chrome, url, reduire=False):
    """Chrome écrit le DOM puis ne rend pas toujours la main : on lit et on termine.

    `reduire` force `prefers-reduced-motion: reduce`. Deux raisons : cela teste
    ce chemin pour de bon, et cela rend le défilement instantané — donc
    mesurable. En mouvement normal, Chrome sans interface n'exécute pas
    l'animation de défilement, et la position finale ne veut alors rien dire.
    """
    profil = tempfile.mkdtemp(prefix="urbizen-parcours-")
    proc = subprocess.Popen(
        [
            chrome,
        ] + (["--force-prefers-reduced-motion"] if reduire else []) + [
            "--headless=new",
            "--disable-gpu",
            "--no-first-run",
            "--no-default-browser-check",
            "--disable-extensions",
            "--hide-scrollbars",
            "--user-data-dir=" + profil,
            "--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE 127.0.0.1",
            "--virtual-time-budget=120000",
            "--dump-dom",
            url,
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )
    try:
        dom, _ = proc.communicate(timeout=150)
    except subprocess.TimeoutExpired:
        proc.kill()
        dom, _ = proc.communicate()
    finally:
        shutil.rmtree(profil, ignore_errors=True)

    bloc = re.search(r'<pre id="resultat">(.*?)</pre>', dom or "", re.S)
    if not bloc:
        return None, "aucune mesure produite (Chrome a-t-il rendu la page ?)"

    contenu = bloc.group(1)
    for brut, clair in (("&quot;", '"'), ("&amp;", "&"), ("&lt;", "<"), ("&gt;", ">")):
        contenu = contenu.replace(brut, clair)

    if contenu.startswith("ERREUR:"):
        return None, "la page de mesure a échoué : " + contenu[7:].strip()
    if not contenu.startswith("JSON:"):
        return None, "mesure incomplète — la page en était à « %s »" % contenu.strip()[:40]
    try:
        return json.loads(contenu[5:]), None
    except json.JSONDecodeError as e:
        return None, "mesure illisible : %s" % e


def main():
    chrome = trouver_chrome()
    if not chrome:
        print("Google Chrome introuvable — ce banc rejoue un parcours réel et ne")
        print("peut pas s'exécuter sans moteur de rendu.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    httpd, port = servir(RACINE)
    try:
        url = "http://127.0.0.1:%d/tests/homepage/parcours-renseignements.html" % port
        donnees, erreur = mesurer(chrome, url, reduire=False)
        reduit, erreur_r = mesurer(chrome, url, reduire=True)
    finally:
        httpd.shutdown()

    if donnees is None:
        print("Impossible de mesurer : %s" % erreur)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)
    if reduit is None:
        print("Impossible de mesurer en mouvement réduit : %s" % erreur_r)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    menu = {m["largeur"]: m for m in donnees["menu"]}
    dial = {m["largeur"]: m for m in donnees["dialogue"]}

    check(
        "Les 7 viewports sont mesurés sur les trois scénarios",
        len(menu) == 7 and len(dial) == 7 and len(donnees["hash"]) == 7,
    )
    if len(menu) != 7:
        print("\n1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    mobiles = [w for w in sorted(menu) if menu[w]["burgerVisible"]]

    # ------------------------------------------------- les deux déclencheurs --

    check(
        "Le menu mobile porte une entrée « Écrire à Urbizen »",
        all(menu[w]["lienPresent"] for w in menu),
    )
    check(
        "Le panneau de contact porte le canal « Écrire à Urbizen »",
        all(dial[w]["lienPresent"] for w in dial),
    )
    mauvais = [
        "%dpx %s" % (w, src[w]["href"])
        for src in (menu, dial)
        for w in src
        if src[w]["href"] != ANCRE
    ]
    check("Les deux déclencheurs pointent vers l'ancre", not mauvais, " | ".join(mauvais))

    # ------------------------------------------------- scénario menu mobile ---

    ko = []
    for w in mobiles:
        m = menu[w]
        avant, apres = m["apresOuvertureMenu"], m["apresClic"]
        if not avant["menuOuvert"]:
            ko.append("%dpx : le menu ne s'est pas ouvert" % w)
            continue
        if apres["menuOuvert"]:
            ko.append("%dpx : menu resté ouvert" % w)
        if apres["burgerAria"] != "false":
            ko.append("%dpx : burger aria-expanded=%s" % (w, apres["burgerAria"]))
        if not apres["blocOuvert"]:
            ko.append("%dpx : bloc non ouvert" % w)
        if apres["boutonAria"] != "true":
            ko.append("%dpx : bouton aria-expanded=%s" % (w, apres["boutonAria"]))
        if apres["focalise"] != "h3#titre-renseignements":
            ko.append("%dpx : focus sur %s" % (w, apres["focalise"]))
        if apres["erreurs"]:
            ko.append("%dpx : erreur JS %s" % (w, apres["erreurs"]))
    check("Menu mobile : clic → menu fermé, bloc ouvert, focus sur le titre", not ko, " | ".join(ko))

    check(
        "Menu mobile : le burger est bien masqué en desktop (scénario non applicable)",
        not menu[1400]["burgerVisible"],
    )

    # ------------------------------------------------ scénario panneau contact -

    ko = []
    for w in sorted(dial):
        m = dial[w]
        avant, apres = m["apresOuvertureDialogue"], m["apresClic"]
        if not avant["dialogueOuvert"]:
            ko.append("%dpx : le dialogue ne s'est pas ouvert" % w)
            continue
        if not avant["defilementBloque"]:
            ko.append("%dpx : le dialogue n'a pas verrouillé le défilement" % w)
        if apres["dialogueOuvert"]:
            ko.append("%dpx : dialogue resté ouvert" % w)
        if apres["defilementBloque"]:
            ko.append("%dpx : défilement du document resté verrouillé" % w)
        if any(a != "false" for a in apres["dialogueAria"]):
            ko.append("%dpx : aria-expanded des déclencheurs = %s" % (w, apres["dialogueAria"]))
        if not apres["blocOuvert"]:
            ko.append("%dpx : bloc non ouvert" % w)
        if apres["focalise"] != "h3#titre-renseignements":
            ko.append("%dpx : focus sur %s" % (w, apres["focalise"]))
        if apres["erreurs"]:
            ko.append("%dpx : erreur JS %s" % (w, apres["erreurs"]))
    check("Panneau de contact : clic → dialogue fermé, défilement rendu, bloc ouvert", not ko, " | ".join(ko))

    # ------------------------------------------------------------ idempotence -

    ko = [
        "%dpx : bloc=%s bouton=%s" % (w, menu[w]["apresSecondClic"]["blocOuvert"], menu[w]["apresSecondClic"]["boutonAria"])
        for w in mobiles
        if not menu[w]["apresSecondClic"]["blocOuvert"] or menu[w]["apresSecondClic"]["boutonAria"] != "true"
    ]
    check("Second clic sur un bloc déjà ouvert : il reste ouvert", not ko, " | ".join(ko))

    ko = [
        "%dpx : libellé « %s »" % (w, menu[w]["apresSecondClic"]["libelle"])
        for w in mobiles
        if menu[w]["apresSecondClic"]["libelle"] != "Fermer le formulaire"
    ]
    check("Le libellé du bouton reste cohérent après un second clic", not ko, " | ".join(ko))

    # -------------------------------------------- le bouton natif bascule encore

    ko = []
    for w in sorted(dial):
        f, o = dial[w].get("apresBoutonFerme"), dial[w].get("apresBoutonOuvre")
        if not f or not o:
            ko.append("%dpx : non mesuré" % w)
            continue
        if f["blocOuvert"] or f["boutonAria"] != "false" or f["libelle"] != "Demander des renseignements":
            ko.append("%dpx : fermeture KO (%s / %s)" % (w, f["boutonAria"], f["libelle"]))
        if not o["blocOuvert"] or o["boutonAria"] != "true" or o["libelle"] != "Fermer le formulaire":
            ko.append("%dpx : réouverture KO (%s / %s)" % (w, o["boutonAria"], o["libelle"]))
    check("Le bouton natif ouvre ET referme toujours, libellé compris", not ko, " | ".join(ko))

    # ------------------------------------------- la section sous l'en-tête ----

    # Le défilement ne s'observe que sur la passe en mouvement réduit : ailleurs,
    # Chrome sans interface n'anime pas et la page ne bouge pas.
    dial_r = {m["largeur"]: m for m in reduit["dialogue"]}
    menu_r = {m["largeur"]: m for m in reduit["menu"]}

    ko = []
    for w in sorted(dial_r):
        apres = dial_r[w]["apresClic"]
        if not apres:
            continue
        if apres["scrollY"] <= 0:
            ko.append("%dpx : la page n'a pas défilé (scrollY=%s)" % (w, apres["scrollY"]))
    check("Mouvement réduit : le clic fait réellement défiler la page", not ko, " | ".join(ko))

    ko = []
    for w in sorted(dial_r):
        apres = dial_r[w]["apresClic"]
        if not apres or apres["scrollY"] <= 0:
            continue
        ecart = apres["section"]["y"] - apres["entete"]["bas"]
        if ecart < -0.5:
            ko.append("%dpx : section %.1fpx SOUS l'en-tête" % (w, -ecart))
        elif ecart > 40:
            ko.append("%dpx : section %.1fpx trop bas" % (w, ecart))
    check(
        "La section se pose juste sous l'en-tête collant, jamais dessous",
        not ko,
        " | ".join(ko),
    )

    ko = []
    for src, nom_src in ((menu_r, "menu"), (dial_r, "panneau")):
        for w in sorted(src):
            a = src[w].get("apresClic")
            if not a:
                continue
            if not a["blocOuvert"] or a["focalise"] != "h3#titre-renseignements":
                ko.append("%dpx (%s) : bloc=%s focus=%s" % (w, nom_src, a["blocOuvert"], a["focalise"]))
    check("Mouvement réduit : le parcours reste complet et le focus correct", not ko, " | ".join(ko))

    ko = [
        "%dpx : %s/%s" % (w, s["scrollWidth"], s["clientWidth"])
        for src in (menu, dial)
        for w in src
        for s in [src[w].get("apresClic")]
        if s and s["scrollWidth"] > s["clientWidth"]
    ]
    check("Aucun débordement horizontal pendant le parcours", not ko, " | ".join(ko))

    # ------------------------------------------------- l'arrivée par l'ancre ---
    # Depuis une page interne, le clic ne peut pas être intercepté : la page
    # change. C'est le manque trouvé en recette de production sur e114d69 — la
    # section était atteinte, mais le formulaire restait fermé.

    hach = {m["largeur"]: m for m in donnees["hash"]}
    hach_r = {m["largeur"]: m for m in reduit["hash"]}

    ko = []
    for w in sorted(hach):
        a = hach[w]["arrivee"]
        if not a["blocOuvert"]:
            ko.append("%dpx : bloc fermé à l'arrivée" % w)
        if a["boutonAria"] != "true":
            ko.append("%dpx : aria-expanded=%s" % (w, a["boutonAria"]))
        if a["libelle"] != "Fermer le formulaire":
            ko.append("%dpx : libellé « %s »" % (w, a["libelle"]))
        if a["erreurs"]:
            ko.append("%dpx : erreur JS %s" % (w, a["erreurs"]))
    check("Arrivée sur l'ancre : le formulaire est déjà ouvert", not ko, " | ".join(ko))

    ko = [
        "%dpx" % w for w in sorted(hach) if hach[w]["arrivee"]["champFocalise"]
    ]
    check("Arrivée par l'ancre : aucun champ du formulaire n'est focalisé", not ko, " | ".join(ko))

    # Un hash étranger ne doit rien déclencher.
    ko = [
        "%dpx : bloc ouvert par #tarifs" % w
        for w in sorted(hach)
        if hach[w]["autreHash"]["blocOuvert"]
    ]
    check("Un hash sans rapport n'ouvre pas le formulaire", not ko, " | ".join(ko))

    # hashchange : ouvre sur l'ancre, et ne referme jamais en la quittant.
    ko = []
    for w in sorted(hach):
        h = hach[w]
        if h["avantHashchange"]["blocOuvert"]:
            ko.append("%dpx : bloc déjà ouvert avant tout hash" % w)
        if not h["apresHashchange"]["blocOuvert"]:
            ko.append("%dpx : hashchange n'a pas ouvert" % w)
        if not h["apresAutreHash"]["blocOuvert"]:
            ko.append("%dpx : quitter l'ancre a REFERMÉ le bloc" % w)
        if not h["apresRetourHash"]["blocOuvert"] or h["apresRetourHash"]["boutonAria"] != "true":
            ko.append("%dpx : retour sur l'ancre — état %s" % (w, h["apresRetourHash"]["boutonAria"]))
    check("hashchange : ouvre sur l'ancre, ne referme jamais en la quittant", not ko, " | ".join(ko))

    # La position n'est vérifiable que là où le défilement s'exécute.
    ko = []
    for w in sorted(hach_r):
        a = hach_r[w]["arrivee"]
        if a["scrollY"] <= 0:
            ko.append("%dpx : aucun défilement (scrollY=%s)" % (w, a["scrollY"]))
            continue
        ecart = a["section"]["y"] - a["entete"]["bas"]
        if ecart < -0.5 or ecart > 40:
            ko.append("%dpx : écart %.1fpx" % (w, ecart))
    check("Arrivée par l'ancre : la section se pose sous l'en-tête", not ko, " | ".join(ko))

    # ------------------------------------------------------------- le rapport -

    print("")
    print("position finale (passe en mouvement réduit, défilement réellement exécuté) :")
    for w in sorted(dial_r):
        a = dial_r[w]["apresClic"]
        if not a:
            continue
        print(
            "  %4dpx  en-tête %5.1f  scrollY %6d  section y=%6.1f  écart %5.1f  focus %s"
            % (w, a["entete"]["h"], a["scrollY"], a["section"]["y"], a["section"]["y"] - a["entete"]["bas"], a["focalise"])
        )

    print("")
    print("TOUS LES CONTROLES PASSENT" if echecs == 0 else "%d CONTROLE(S) EN ECHEC" % echecs)
    sys.exit(0 if echecs == 0 else 1)


if __name__ == "__main__":
    main()
