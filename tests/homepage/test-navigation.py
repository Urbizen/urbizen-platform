#!/usr/bin/env python3
"""Banc du menu principal à deux niveaux — « Nos prestations ».

Pourquoi dans un navigateur
---------------------------

Le balisage dirait que `aria-expanded` est présent ; il ne dirait pas qu'il
suit l'état réel du sous-menu. Il dirait que le chevron a une classe ; pas
qu'il pivote. Il dirait que le panneau existe ; pas qu'il tient dans la
fenêtre. Et il ne dirait rien du seul comportement que le clavier exige
vraiment : qu'Échap rende le focus au parent, faute de quoi il retombe sur
`<body>` et la tabulation repart du début de la page.

Ce que le banc refuse de laisser passer
---------------------------------------

1. un parent qui serait un lien — il n'existe aucune page « Nos prestations » ;
2. un `aria-expanded` qui ment sur l'état réel, dans un sens ou dans l'autre ;
3. un sous-menu qui ne se referme pas : second clic, Échap, clic au dehors,
   sortie du focus — les quatre chemins sont vérifiés séparément ;
4. Échap qui ne rend pas le focus au parent ;
5. la flèche bas qui n'entre pas dans le sous-menu ;
6. un panneau qui déborde de la fenêtre à une largeur de bureau ;
7. les deux menus offerts en même temps, ou aucun des deux ;
8. sous le burger : un groupe replié dont les enfants ne seraient pas
   atteignables, ou un sous-niveau sans décalage visible ;
9. un « Espace client » devenu un lien — la destination n'existe pas encore ;
10. le moindre débordement horizontal, menu ouvert ou fermé.

Le halo de focus n'est pas mesuré ici : `:focus-visible` ne se déclenche pas de
façon fiable sur un `focus()` programmatique. C'est `test-navigation.php` qui
garantit la règle, et une vérification au clavier réel qui l'a constatée.

Prérequis : Python 3 et Google Chrome. Sans Chrome, sortie en code 2
(prérequis absent) — jamais un succès silencieux.

    python3 test-navigation.py
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

# Le menu attendu, dans l'ordre. C'est la seule description de référence :
# le banc ne devine pas, il compare.
PREMIER_NIVEAU = ["Accueil", "Nos prestations", "Tarifs", "Espace client", "Contact"]
PRESTATIONS = ["Déclaration préalable", "Permis de construire", "Conception de plans"]

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


def mesurer(chrome, url):
    """Chrome écrit le DOM puis ne rend pas toujours la main : on lit et on termine."""
    profil = tempfile.mkdtemp(prefix="urbizen-navigation-")
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
        print("Google Chrome introuvable — ce banc rejoue un menu réel et ne")
        print("peut pas s'exécuter sans moteur de rendu.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    httpd, port = servir(RACINE)
    try:
        url = "http://127.0.0.1:%d/tests/homepage/navigation.html" % port
        donnees, erreur = mesurer(chrome, url)
    finally:
        httpd.shutdown()

    if donnees is None:
        print("Impossible de mesurer : %s" % erreur)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    m = {x["largeur"]: x for x in donnees["mesures"]}
    check("Les 8 largeurs sont mesurées", len(m) == 8, "obtenu : %s" % sorted(m))
    if len(m) != 8:
        print("\n1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    bureau = [w for w in sorted(m) if m[w]["desktopVisible"]]
    mobile = [w for w in sorted(m) if m[w]["burgerVisible"]]

    # ----------------------------------------------------- la bascule ---------

    faux = ["%dpx" % w for w in sorted(m) if m[w]["desktopVisible"] == m[w]["burgerVisible"]]
    check("Un seul des deux menus est offert, à chaque largeur", not faux, " | ".join(faux))
    check(
        "La bascule tombe bien entre 1100 et 1101 px",
        m[1100]["burgerVisible"] and m[1101]["desktopVisible"],
        "1100 burger=%s | 1101 desktop=%s" % (m[1100]["burgerVisible"], m[1101]["desktopVisible"]),
    )

    # ----------------------------------------------------- aucun faux lien ----

    fautifs = ["%dpx : %d" % (w, m[w]["faussesAncres"]) for w in sorted(m) if m[w]["faussesAncres"]]
    check("Aucun href=\"#\" dans l'en-tête", not fautifs, " | ".join(fautifs))
    pro = ["%dpx" % w for w in sorted(m) if m[w]["liensPro"]]
    check("Aucun lien vers un espace professionnels", not pro, " | ".join(pro))

    deb = ["%dpx → %d" % (w, m[w]["debordement"]) for w in sorted(m) if m[w]["debordement"] > 0]
    check("Aucun débordement horizontal, menu fermé", not deb, " | ".join(deb))

    # ----------------------------------------------------- menu de bureau -----

    check(
        "Le parent « Nos prestations » est un bouton, pas un lien",
        all(m[w]["parentBalise"] == "BUTTON" and m[w]["parentHref"] is None for w in bureau),
        " | ".join("%dpx %s" % (w, m[w]["parentBalise"]) for w in bureau),
    )
    # L'étiquette « bientôt » est écrite en minuscules et mise en capitales par
    # le CSS : `textContent` restitue la source, pas ce que l'œil lit.
    libelles = lambda w: [
        re.sub(r"\s*bientôt$", "", e["texte"], flags=re.I) for e in m[w]["entrees"]
    ]
    check(
        "Le premier niveau est exactement le menu attendu, dans l'ordre",
        all(libelles(w) == PREMIER_NIVEAU for w in bureau),
        " | ".join("%dpx %s" % (w, libelles(w)) for w in bureau),
    )
    check(
        "Au chargement : sous-menu fermé et aria-expanded=false",
        all(not m[w]["initial"]["ouvert"] and m[w]["initial"]["aria"] == "false" for w in bureau),
    )
    check(
        "Au clic : sous-menu ouvert, aria-expanded=true, 3 prestations",
        all(m[w]["ouvert"]["ouvert"] and m[w]["ouvert"]["aria"] == "true"
            and m[w]["ouvert"]["liens"] == len(PRESTATIONS) for w in bureau),
        " | ".join("%dpx %s" % (w, m[w]["ouvert"]) for w in bureau),
    )
    # rotate(180deg) donne matrix(-1, 0, 0, -1, 0, 0). Une valeur identité
    # signifierait que la règle n'a jamais mordu.
    plats = ["%dpx %s" % (w, m[w]["ouvert"]["chevron"]) for w in bureau
             if "-1" not in m[w]["ouvert"]["chevron"]]
    check("Le chevron pivote à l'ouverture", not plats, " | ".join(plats))
    hors = ["%dpx [%s, %s]" % (w, m[w]["ouvert"]["gauche"], m[w]["ouvert"]["droite"])
            for w in bureau if m[w]["ouvert"]["gauche"] < 0 or m[w]["ouvert"]["droite"] > w]
    check("Le panneau tient entièrement dans la fenêtre", not hors, " | ".join(hors))
    deb2 = ["%dpx → %d" % (w, m[w]["ouvert"]["debordement"]) for w in bureau
            if m[w]["ouvert"]["debordement"] > 0]
    check("Aucun débordement horizontal, sous-menu ouvert", not deb2, " | ".join(deb2))

    # ----------------------------------------------------- les 4 fermetures ---

    for cle, libelle in (
        ("secondClic", "Second clic sur le parent"),
        ("echap", "Touche Échap"),
        ("sortieFocus", "Sortie du focus par tabulation"),
        ("clicDehors", "Clic au dehors"),
    ):
        restes = ["%dpx" % w for w in bureau if m[w][cle]["ouvert"]]
        check("%s : le sous-menu se referme" % libelle, not restes, " | ".join(restes))

    check(
        "Second clic : aria-expanded repasse à false",
        all(m[w]["secondClic"]["aria"] == "false" for w in bureau),
    )
    mauvais = ["%dpx → %s" % (w, m[w]["echap"]["focus"]) for w in bureau
               if m[w]["echap"]["focus"] != "button.nav-parent"]
    check("Échap : le focus revient au parent", not mauvais, " | ".join(mauvais))

    # ----------------------------------------------------- entrée au clavier --

    check(
        "Flèche bas : ouvre le sous-menu et y pose le focus",
        all(m[w]["flecheBas"]["ouvert"] and m[w]["flecheBas"]["premier"] for w in bureau),
        " | ".join("%dpx %s" % (w, m[w]["flecheBas"]) for w in bureau),
    )

    # ----------------------------------------------------- tiroir mobile ------

    check(
        "Le burger ouvre le tiroir et l'annonce (aria-expanded=true)",
        all(m[w]["tiroir"]["ouvert"] and m[w]["tiroir"]["aria"] == "true" for w in mobile),
    )
    check(
        "L'intitulé « Nos prestations » y est visible et n'est pas un lien",
        all(m[w]["tiroir"]["groupeVisible"] and m[w]["tiroir"]["groupeEstUnLien"] is False
            for w in mobile),
    )
    check(
        "Les 3 prestations sont atteignables sans second dépliage",
        all(m[w]["tiroir"]["enfants"] == len(PRESTATIONS)
            and m[w]["tiroir"]["enfantsVisibles"] == len(PRESTATIONS) for w in mobile),
        " | ".join("%dpx %s/%s" % (w, m[w]["tiroir"]["enfantsVisibles"], m[w]["tiroir"]["enfants"])
                   for w in mobile),
    )
    # Sans décalage, rien ne distingue un sous-niveau d'une entrée de premier
    # niveau : la hiérarchie serait dans le balisage et nulle part à l'écran.
    plat = ["%dpx → %s" % (w, m[w]["tiroir"]["decalage"]) for w in mobile
            if not m[w]["tiroir"]["decalage"] or m[w]["tiroir"]["decalage"] < 8]
    check("Le sous-niveau est décalé d'au moins 8 px", not plat, " | ".join(plat))
    check(
        "Espace client : aria-disabled, et aucune destination inventée",
        all(m[w]["tiroir"]["bientotAria"] == "true" and m[w]["tiroir"]["bientotHref"] is None
            for w in mobile),
    )
    deb3 = ["%dpx → %d" % (w, m[w]["tiroir"]["debordement"]) for w in mobile
            if m[w]["tiroir"]["debordement"] > 0]
    check("Aucun débordement horizontal, tiroir ouvert", not deb3, " | ".join(deb3))

    print("")
    if echecs:
        print("%d CONTROLE(S) EN ECHEC" % echecs)
        sys.exit(1)
    print("TOUS LES CONTROLES PASSENT")


if __name__ == "__main__":
    main()
