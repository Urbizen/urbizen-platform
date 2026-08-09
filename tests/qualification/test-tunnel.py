#!/usr/bin/env python3
"""Banc du tunnel de qualification — rejoué dans un moteur de rendu.

Le moteur de qualification est couvert hors navigateur, et son équivalence avec
le serveur prouvée sur 100 cas. Ce qui ne l'était pas, c'est l'orchestration :
l'enchaînement réel des questions, et surtout l'absence de redirection avant
conclusion.

C'est exactement le type de lacune qui a produit le défaut de la PR #56 — des
règles justes, une orchestration non mesurée. Un moteur parfait derrière un
tunnel qui redirige au clic ne vaut rien.

Ce que le banc refuse de laisser passer :

1. une carte de projet qui conclut sans avoir posé ses questions ;
2. un bouton actif alors qu'une question attend une réponse ;
3. un état hors des cinq ;
4. « Autre » qui conclurait autrement qu'en « à confirmer » ;
5. un verdict `none` ou `confirm` qui mènerait vers un dossier payant ;
6. des réponses recueillies puis perdues avant le formulaire ;
7. la moindre erreur JavaScript.

Prérequis : Python 3 et Google Chrome. Sans Chrome, sortie en code 2.

    python3 test-tunnel.py
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
    profil = tempfile.mkdtemp(prefix="urbizen-tunnel-")
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



ETATS = ("dp", "pcmi", "none", "confirm", "conception")

# Ce que chaque scénario doit conclure. Les seuils viennent des textes ; ici on
# vérifie que le tunnel les fait vivre, pas qu'ils sont justes — c'est le rôle
# du corpus partagé.
ATTENDUS = {
    "extension DP certaine": "dp",
    "extension PC certaine": "pcmi",
    "extension zone U inconnue": "confirm",
    "garage accolé DP": "dp",
    "garage accolé PC prudent": "pcmi",
    "garage indépendant sans formalité": "none",
    "garage indépendant DP": "dp",
    "garage indépendant PC": "pcmi",
    "abri secteur inconnu": "dp",
    "abri accolé DP": "dp",
    "abri accolé PC prudent": "pcmi",
    "abri sans formalité": "none",
    "abri PC": "pcmi",
    "piscine sans formalité": "none",
    "piscine DP": "dp",
    "piscine PC": "pcmi",
    "pergola autonome": "dp",
    "pergola adossée DP": "dp",
    "pergola adossée PC prudent": "pcmi",
    "transformation garage en pièce": "dp",
    "autre projet": "confirm",
}


def main():
    chrome = trouver_chrome()
    if not chrome:
        print("Google Chrome introuvable — ce banc rejoue un tunnel réel et ne")
        print("peut pas s'exécuter sans moteur de rendu.")
        print("PREREQUIS ABSENT — BANC NON EXECUTE")
        sys.exit(2)

    httpd, port = servir(RACINE)
    try:
        url = "http://127.0.0.1:%d/tests/qualification/tunnel.html" % port
        donnees, erreur = mesurer(chrome, url)
    finally:
        httpd.shutdown()

    if donnees is None:
        print("Impossible de mesurer : %s" % erreur)
        print("1 CONTROLE(S) EN ECHEC")
        sys.exit(1)

    scenarios = {s["nom"]: s for s in donnees["scenarios"]}

    check("Les 21 scénarios ont été rejoués", len(scenarios) == 21, str(sorted(scenarios)))
    check(
        "La carte « Transformer un espace existant » existe dans le tunnel",
        "transformation" in donnees["cartes"],
        "cartes : %s" % ", ".join(donnees["cartes"]),
    )
    check("Les onze cartes de projet sont présentes", len(donnees["cartes"]) == 11)

    # ------------------------------------------------ le verdict de chacun ---

    ko = [
        "%s → %s (attendu %s)" % (nom, scenarios[nom]["statut"], attendu)
        for nom, attendu in ATTENDUS.items()
        if nom in scenarios and scenarios[nom]["statut"] != attendu
    ]
    check("Chaque scénario conclut ce qu'il doit conclure", not ko, " | ".join(ko))

    hors = [s["nom"] for s in scenarios.values() if s["statut"] not in ETATS]
    check("Aucun état hors des cinq", not hors, " | ".join(hors))

    # ------------------------------- aucune conclusion sans avoir demandé ----

    # Une carte qui conclurait sans poser de question serait le défaut d'origine
    # sous une autre forme : décider avant de savoir.
    muets = [
        s["nom"]
        for s in scenarios.values()
        if s["projet"] in ("extension", "garage", "abri", "piscine", "pergola", "transformation")
        and not s.get("posees")
    ]
    check("Aucun projet ne conclut sans avoir posé de question", not muets, " | ".join(muets))

    # « Autre » demande légitimement une description : c'est ce qui lui manque.
    # Ce qu'il ne doit jamais faire, c'est conclure « dp ».
    autre = scenarios["autre projet"]
    check(
        "« Autre » conclut « à confirmer » et demande une description",
        autre["statut"] == "confirm"
        and any("écrivez" in q.lower() or "décrivez" in q.lower() for q in (autre.get("posees") or [])),
        "%s · %s" % (autre["statut"], autre.get("posees")),
    )

    # Une réponse donnée ne doit jamais être redemandée : « je ne sais pas » est
    # une réponse, et le tunnel doit conclure « à confirmer » plutôt que de
    # reposer la même question sans fin.
    boucles = [
        s["nom"] for s in scenarios.values()
        if s["statut"] == "confirm" and s["questionVisible"]
    ]
    check("Aucune question ne revient après avoir reçu sa réponse", not boucles, " | ".join(boucles))

    # Le garage commence par l'implantation : c'est la question qui change
    # d'article, et la poser en second n'aurait pas de sens.
    premiere = (scenarios["garage accolé DP"].get("posees") or [""])[0]
    check(
        "Le garage commence par accolé ou indépendant",
        "accolée" in premiere.lower() or "indépendante" in premiere.lower(),
        premiere,
    )

    piscine = scenarios["piscine sans formalité"]
    check(
        "La piscine demande explicitement si elle sera couverte",
        any("couverte" in q.lower() for q in (piscine.get("posees") or [])),
        str(piscine.get("posees")),
    )
    check(
        "Toutes les réponses du scénario piscine sont consommées",
        len(piscine.get("posees") or []) == 3,
        str(piscine.get("posees")),
    )

    premiere_t = (scenarios["transformation garage en pièce"].get("posees") or [""])[0]
    check(
        "La transformation ne pose jamais les questions du garage neuf",
        "accolée" not in premiere_t.lower(),
        premiere_t,
    )

    # ------------------------------- pas de dossier payant sans conclusion ---

    # `none` et `confirm` n'ont pas de formulaire de régime : proposer un dossier
    # payant sans avoir conclu serait le défaut que cette tranche corrige.
    fautes = []
    for s in scenarios.values():
        if s["statut"] in ("none", "confirm"):
            if "déclaration préalable" in s["bouton"] or "permis de construire" in s["bouton"]:
                fautes.append("%s : bouton « %s »" % (s["nom"], s["bouton"]))
            if "déclaration préalable" in s["message"] or "permis de construire" in s["message"]:
                fautes.append("%s : message « %s »" % (s["nom"], s["message"][:60]))
    check(
        "Ni « aucune formalité » ni « à confirmer » n'annoncent un dossier DP ou PC",
        not fautes,
        " | ".join(fautes),
    )

    anciens_libelles = [
        "%s : %s" % (s["nom"], s["bouton"])
        for s in scenarios.values()
        if "qualifier mon projet" in s["bouton"].lower()
        or "faire vérifier mon projet" in s["bouton"].lower()
    ]
    check(
        "Aucun bouton ne propose de faire qualifier le projet",
        not anciens_libelles,
        " | ".join(anciens_libelles),
    )

    sans_suivi = [
        s["nom"] for s in scenarios.values()
        if s["statut"] in ("dp", "pcmi")
        and ("après l'envoi" not in s["message"].lower() or "24 h ouvrées" not in s["message"].lower())
    ]
    check(
        "Chaque démarche DP ou PC annonce le contrôle post-envoi sous 24 h",
        not sans_suivi,
        " | ".join(sans_suivi),
    )

    # Le bouton reste inactif tant qu'une question attend.
    actifs = [s["nom"] for s in scenarios.values() if s["questionVisible"] and not s["desactive"]]
    check("Le bouton reste inactif tant qu'une question attend", not actifs, " | ".join(actifs))

    # Aucune navigation ne doit partir pendant la qualification.
    partis = [s["nom"] for s in scenarios.values() if s.get("navigations")]
    check("Aucune redirection avant conclusion", not partis, " | ".join(partis))

    # ------------------------------------------- transmission au formulaire --

    transmis = donnees.get("transmis") or {}
    donnees_t = transmis.get("donnees") or {}
    check(
        "Les réponses de qualification sont conservées pour le formulaire",
        bool(donnees_t.get("projet")) and len(donnees_t) > 1,
        "conservé : %s" % ", ".join(sorted(donnees_t)),
    )
    check(
        "Le verdict est conservé avec ses données",
        (transmis.get("verdict") or {}).get("status") in ETATS,
    )

    # ------------------------------------------------------------ erreurs ----

    # ------------------- contexte transmis au formulaire de renseignements ---

    # `none` et `confirm` mènent au formulaire de renseignements. Sans contexte,
    # Urbizen reçoit une demande sans savoir ce que la personne venait de
    # qualifier — et lui redemande tout.
    ctx = donnees.get("contexteRenseignements") or {}
    check("Le scénario de contexte conclut bien « aucune formalité »", ctx.get("statut") == "none", str(ctx.get("statut")))

    message = ctx.get("message") or ""
    check(
        "Le message de renseignements reçoit un contexte lisible",
        "Projet :" in message and "Suite proposée :" in message,
        repr(message[:80]),
    )
    check(
        "Le contexte nomme le projet et ses réponses, en clair",
        "Garage" in message and "indépendant" in message and "Emprise" in message,
        repr(message[:120]),
    )
    check(
        "Le contexte n'expose aucune chaîne technique au client",
        "{" not in message and "status" not in message and "sp_creee" not in message,
        repr(message[:120]),
    )
    check(
        "Le verdict y figure comme contexte, pas comme décision",
        "aucune formalité nationale identifiée" in message,
        repr(message[-80:]),
    )
    check(
        "Un message déjà écrit par le client n'est jamais réécrit",
        (ctx.get("messageClient") or "") == "Bonjour, j'ai une question précise.",
        repr(ctx.get("messageClient")),
    )

    # ------------------------------ contamination entre projets successifs ---

    # Une extension de 60 m² conclut « permis ». Si le garage héritait de cette
    # surface, il conclurait sans avoir posé sa première question — et sur une
    # mesure qui n'est pas la sienne.
    cont = donnees.get("contamination") or {}
    check(
        "L'extension à 60 m² conclut bien « permis » avant le changement",
        (cont.get("apresExtension") or {}).get("statut") == "pcmi",
        str((cont.get("apresExtension") or {}).get("statut")),
    )
    apres_garage = cont.get("apresGarage") or {}
    check(
        "Changer de projet repart de zéro : le garage redemande son implantation",
        apres_garage.get("statut") == "confirm" and apres_garage.get("questionVisible"),
        "statut=%s · question=%r" % (apres_garage.get("statut"), apres_garage.get("question")),
    )
    check(
        "Aucune réponse du projet précédent ne contamine le suivant",
        "accolée" in (apres_garage.get("question") or "").lower()
        or "indépendante" in (apres_garage.get("question") or "").lower(),
        apres_garage.get("question"),
    )

    # ------------------------------------------------------ retour arrière ---

    ret = donnees.get("retour") or {}
    check("Le retour arrière n'a produit aucune exception", not ret.get("erreur"), str(ret.get("erreur")))
    apres_retour = ret.get("apres") or {}
    check(
        "Après un retour arrière, le tunnel revient sur l'accueil",
        "index.html" in (ret.get("url") or "") or (ret.get("url") or "").endswith("/"),
        ret.get("url"),
    )
    check(
        "Après un retour arrière, aucun état incohérent n'est affiché",
        apres_retour.get("statut") in (None, "dp", "pcmi", "none", "confirm", "conception"),
        str(apres_retour.get("statut")),
    )
    check(
        "Après un retour arrière, aucune erreur JavaScript",
        not apres_retour.get("erreurs"),
        str(apres_retour.get("erreurs")),
    )

    err = [s["nom"] for s in scenarios.values() if s.get("erreurs")]
    check("Aucune erreur JavaScript pendant le tunnel", not err, " | ".join(err))

    print("")
    for nom, attendu in ATTENDUS.items():
        s = scenarios.get(nom)
        if not s:
            continue
        print("  %-38s %-8s %d question(s) · « %s »" % (nom, s["statut"], len(s.get("posees") or []), s["bouton"]))

    print("")
    print("TOUS LES CONTROLES PASSENT" if echecs == 0 else "%d CONTROLE(S) EN ECHEC" % echecs)
    sys.exit(0 if echecs == 0 else 1)


if __name__ == "__main__":
    main()
