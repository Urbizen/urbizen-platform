#!/usr/bin/env python3
"""Banc de géométrie des pages légales — mesure réelle, dans un vrai moteur.

Pourquoi ce banc existe
-----------------------

`test-page-tarifs.php` vérifie ce que le gabarit contient : les montants, les
titres, les liens. C'est nécessaire, mais aveugle à ce qui compte le plus sur
une page de tarifs — est-ce que ça tient à l'écran. Une carte peut porter le
bon prix et sortir de la fenêtre ; une grille peut déclarer trois colonnes et
n'en replier aucune à 390 px ; un bouton peut être conforme à la charte et
s'étaler sur toute la largeur.

Ce banc charge l'aperçu réel dans Chrome, lui impose dix largeurs, et relève
des rectangles.

Invariants vérifiés
-------------------

1. aucun débordement horizontal, à aucune largeur ;
2. rien ne dépasse la fenêtre, et la pièce fautive est nommée ;
3. les grilles se replient : trois colonnes en desktop, une seule en mobile ;
4. les commandes autonomes gardent 44 × 44 px ;
5. aucun bouton ne s'étale sur toute la largeur disponible ;
6. l'en-tête et le pied de page communs sont présents, et un seul `<h1>`.

Prérequis : Python 3, PHP (pour régénérer l'aperçu) et Google Chrome. Sans
Chrome, le banc sort en code 2 (prérequis absent) — jamais en succès
silencieux.

    python3 test-geometrie-legal.py
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
GENERATEUR = os.path.join(RACINE, "tests", "legal", "apercu-legal.php")

# Un gabarit par document : les trois aperçus sont régénérés avant mesure.
# Seul le plus long — les CGV — est ensuite mesuré : c'est lui qui porte les
# tableaux, le formulaire de rétractation et le sommaire le plus fourni, donc
# tout ce qui risque de déborder. Mesurer les trois coûterait trois lancements
# de Chrome pour la même mise en page.
PAGES = {
    "page-mentions-legales": "apercu-mentions-legales.html",
    "page-cgv": "apercu-cgv.html",
    "page-confidentialite": "apercu-confidentialite.html",
}

CHROMES = [
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Chromium.app/Contents/MacOS/Chromium",
    "google-chrome",
    "google-chrome-stable",
    "chromium",
    "chromium-browser",
]

# Largeurs où les grilles doivent être repliées sur une seule colonne.
MOBILES = [620, 480, 390, 360, 320]

echecs = 0


def check(libelle, condition, detail=""):
    global echecs
    if not condition:
        echecs += 1
    print("%-72s %s" % (libelle, "OK" if condition else "ECHEC"))
    if not condition and detail:
        print("    " + detail)


def trouver(binaires):
    for c in binaires:
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


def regenerer():
    """L'aperçu est un artefact : on le refait avant de mesurer.

    Mesurer un aperçu périmé donnerait un banc vert sur une page qui n'existe
    plus. On le régénère donc systématiquement, et on échoue si PHP manque.
    """
    php = trouver([os.environ.get("PHP_BIN", "php")])
    if not php:
        print("PHP introuvable — impossible de régénérer l'aperçu.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    for gabarit, fichier in PAGES.items():
        cible = os.path.join(RACINE, "tests", "legal", fichier)
        with open(cible, "w", encoding="utf-8") as sortie:
            code = subprocess.call([php, GENERATEUR, gabarit], stdout=sortie)
        if code != 0 or not os.path.getsize(cible):
            print("Génération de l'aperçu %s en échec (code %s)." % (gabarit, code))
            sys.exit(1)


def mesurer(chrome, url):
    profil = tempfile.mkdtemp(prefix="urbizen-legal-")
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
            "--virtual-time-budget=10000",
            "--dump-dom",
            url,
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
    )

    try:
        dom, _ = proc.communicate(timeout=120)
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
        return None, "mesure incomplète — la page en était encore à « %s »" % contenu.strip()[:40]

    try:
        return json.loads(contenu[5:]), None
    except json.JSONDecodeError as e:
        return None, "mesure illisible : %s" % e


def main():
    chrome = trouver(CHROMES)
    if not chrome:
        print("Google Chrome introuvable — ce banc mesure une mise en page réelle")
        print("et ne peut pas s'exécuter sans moteur de rendu.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    regenerer()

    httpd, port = servir(RACINE)
    try:
        url = "http://127.0.0.1:%d/tests/legal/geometrie-legal.html" % port
        donnees, erreur = mesurer(chrome, url)
    finally:
        httpd.shutdown()

    if donnees is None:
        print("Impossible de mesurer : %s" % erreur)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    for largeur_txt, d in sorted(donnees.items(), key=lambda kv: -int(kv[0])):
        largeur = int(largeur_txt)
        deb = d["debordement"]

        # 1 · aucun débordement horizontal
        check(
            "%4d px — aucun débordement horizontal" % largeur,
            deb["scrollWidth"] <= deb["clientWidth"] + 1,
            "scrollWidth=%s clientWidth=%s (body=%s)"
            % (deb["scrollWidth"], deb["clientWidth"], deb["bodyScrollWidth"]),
        )

        # 2 · rien ne sort de la fenêtre
        check(
            "%4d px — aucun élément hors fenêtre" % largeur,
            not d["coupables"],
            "; ".join("%s (x=%s → %s, w=%s)" % (c["nom"], c["x"], c["droite"], c["w"])
                      for c in d["coupables"][:4]),
        )

        # 4 · cibles tactiles
        check(
            "%4d px — commandes à 44 × 44 px" % largeur,
            not d["petites"],
            "; ".join("%s %s×%s" % (p["nom"], p["w"], p["h"]) for p in d["petites"][:4]),
        )

        # 5 · pas de bouton pleine largeur
        check(
            "%4d px — aucun bouton pleine largeur" % largeur,
            not d["pleineLargeur"],
            "; ".join("%s w=%s / dispo=%s" % (b["nom"], b["w"], b["dispo"])
                      for b in d["pleineLargeur"][:4]),
        )

        # 6 · structure commune
        check("%4d px — un seul <h1>" % largeur, 1 == d["h1"], "trouvés : %s" % d["h1"])
        check("%4d px — en-tête et pied de page communs" % largeur,
              bool(d["entete"]) and d["pied"])

        # 3 · repliement des grilles
        cols = d["colonnes"]
        if largeur in MOBILES:
            for sel in (".tarif-offers-3", ".tar-compris-grid", ".tar-pourquoi-grid"):
                if sel in cols:
                    check("%4d px — %s replié sur une colonne" % (largeur, sel),
                          1 == cols[sel], "colonnes = %s" % cols[sel])
        if largeur >= 1280:
            for sel, attendu in ((".tarif-offers-3", 3), (".tar-compris-grid", 4),
                                 (".tar-pourquoi-grid", 3)):
                if sel in cols:
                    check("%4d px — %s sur %d colonnes" % (largeur, sel, attendu),
                          attendu == cols[sel], "colonnes = %s" % cols[sel])

    print()
    if echecs:
        print("%s CONTROLE(S) EN ECHEC" % echecs)
        sys.exit(1)
    print("TOUS LES CONTROLES PASSENT")
    sys.exit(0)


main()
