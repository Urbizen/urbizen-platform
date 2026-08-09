#!/usr/bin/env python3
"""Banc du report tunnel → formulaires DP et PC.

Le tunnel dépose des réponses ; ce banc vérifie que le destinataire les
ramasse. Sans lui, la qualification serait un questionnaire jeté : le client
répondrait deux fois aux mêmes questions, et l'orientation ne servirait qu'à
choisir une page.

Il fait le trajet complet dans une même iframe — qualification, puis navigation
vers le formulaire — parce que `sessionStorage` est partagé par l'origine,
exactement comme pour un visiteur.

Ce qu'il refuse de laisser passer :

1. une surface saisie dans le tunnel et redemandée par le formulaire ;
2. un permis de construire présenté comme une maison neuve alors que le projet
   qualifié était une extension ;
3. une transformation de garage devenue « garage neuf » ;
4. un contexte de qualification perdu entre les deux pages ;
5. un verdict client traité comme autoritaire.

Prérequis : Python 3 et Google Chrome. Sans Chrome, sortie en code 2.
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
    profil = tempfile.mkdtemp(prefix="urbizen-report-")
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
        print("Google Chrome introuvable — ce banc fait un trajet réel entre deux")
        print("pages et ne peut pas s'exécuter sans moteur de rendu.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    httpd, port = servir(RACINE)
    try:
        donnees, erreur = mesurer(chrome, "http://127.0.0.1:%d/tests/qualification/report.html" % port)
    finally:
        httpd.shutdown()

    if donnees is None:
        print("Impossible de mesurer : %s" % erreur)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    cas = {c["nom"]: c for c in donnees["cas"]}
    check("Les 9 trajets ont été rejoués", len(cas) == 9, str(sorted(cas)))

    # --- A · les surfaces saisies dans le tunnel ne sont pas redemandées ---
    # Les champs naissent vides : un zéro explicite reste désormais une vraie
    # réponse et ne doit plus être confondu avec une valeur par défaut.
    SURFACES = {
        "extension DP": ("15", "15"), "extension PC": ("60", ""),
        "garage surfaces distinctes": ("4", "15"),
        "garage indépendant DP": ("15", "15"), "abri DP": ("12", "12"),
        "abri PC": ("25", ""), "transformation garage habitable": ("18", ""),
        "pergola DP": ("12", "12"),
    }
    ko = []
    for n, attendues in SURFACES.items():
        c = cas.get(n)
        if not c:
            continue
        obtenues = ((c["sp_creee"] or "").strip(), (c["emprise_creee"] or "").strip())
        juste = obtenues == attendues
        if not juste:
            ko.append("%s : %r (attendu %r)" % (n, obtenues, attendues))
    check("Les deux surfaces saisies dans le tunnel ne sont pas redemandées", not ko, " | ".join(ko))

    # --- B · le type de projet survit au trajet ---
    attendus = {
        "extension DP": "extension",
        "extension PC": "extension",
        "garage surfaces distinctes": "garage",
        "garage indépendant DP": "garage",
        "abri DP": "abri_annexe",
        "abri PC": "annexe_garage",
        "piscine DP": "piscine",
        "pergola DP": "autre",
    }
    ko = [
        "%s → %r (attendu %r)" % (n, cas[n]["nature"], a)
        for n, a in attendus.items()
        if n in cas and cas[n]["nature"] != a
    ]
    check("Le type de projet est préservé jusqu'au formulaire", not ko, " | ".join(ko))

    # Le piège nommé : un permis n'est pas une maison neuve.
    check(
        "Une extension en permis n'est jamais présentée comme une maison neuve",
        cas["extension PC"]["nature"] == "extension" and cas["extension PC"]["form"] == "pc",
        "nature = %r" % cas["extension PC"]["nature"],
    )

    # --- C · la transformation ne devient ni un garage neuf ni « autre » ---
    t = cas["transformation garage habitable"]
    check(
        "Une transformation de garage ne devient pas un garage neuf",
        t["nature"] not in ("garage", "annexe_garage"),
        "nature = %r" % t["nature"],
    )
    check(
        "La transformation reste identifiable dans le contexte transmis",
        bool(t["contexte"]) and t["contexte"].get("projet") == "transformation"
        and "garage" in (t["contexte"].get("resume") or ""),
        "contexte = %r" % (t["contexte"] or {}).get("resume"),
    )

    # --- D · le contexte suit, y compris ce qui n'a pas de champ ---
    sans_contexte = [n for n, c in cas.items() if not c["contexte"]]
    check("Chaque trajet transmet son contexte de qualification", not sans_contexte, " | ".join(sans_contexte))

    g = cas["garage indépendant DP"]
    check(
        "Ce qui n'a pas de champ voyage en métadonnée lisible",
        "indépendant" in (g["contexte"].get("resume") or ""),
        (g["contexte"] or {}).get("resume"),
    )

    # --- E · le verdict voyage, sans autorité ---
    check(
        "Le verdict est transmis pour information",
        (cas["extension DP"]["contexte"].get("verdict") or {}).get("status") == "dp",
    )

    # --- F · une session ancienne ou contradictoire ne préremplit rien ---
    securite = donnees.get("securiteSession") or {}
    for cle, libelle in (
        ("apresChangement", "un autre projet commencé"),
        ("expiree", "une qualification expirée"),
        ("mauvaisRegime", "un verdict destiné à l’autre formulaire"),
        ("mauvaisParcours", "un identifiant de parcours différent"),
    ):
        valeurs = securite.get(cle) or {}
        check(
            "La session est ignorée après " + libelle,
            not valeurs.get("nature") and not valeurs.get("sp_creee") and not valeurs.get("contexte"),
            repr(valeurs),
        )

    # --- G · une adresse cadastrale n'est reprise que juste après confirmation ---
    cadastre = donnees.get("securiteCadastre") or {}
    recent = cadastre.get("recent") or {}
    check(
        "Une confirmation cadastrale récente préremplit encore l’adresse",
        recent == {
            "adresse": "12 rue du Test", "codePostal": "33000", "ville": "Bordeaux",
            "section": "AB", "numero": "42",
        },
        repr(recent),
    )
    for cle, libelle in (("expire", "expirée"), ("futur", "datée dans le futur")):
        valeurs = cadastre.get(cle) or {}
        check(
            "Une confirmation cadastrale " + libelle + " est ignorée",
            not any(valeurs.values()),
            repr(valeurs),
        )

    print("")
    for n, c in cas.items():
        print("  %-34s %-4s nature=%-22s sp=%-6s « %s »" % (
            n, c["form"], c["nature"], c["sp_creee"], (c["contexte"] or {}).get("resume", "")[:44]))

    print("")
    print("TOUS LES CONTROLES PASSENT" if echecs == 0 else "%d CONTROLE(S) EN ECHEC" % echecs)
    sys.exit(0 if echecs == 0 else 1)


if __name__ == "__main__":
    main()
