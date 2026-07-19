# Automatisation des dossiers d'urbanisme (DP + PC maison individuelle)

Composants :

- **`frontend/formulaires/dp-formulaire.html`** — formulaire client pour la **déclaration préalable** (Cerfa 16702*02).
- **`frontend/formulaires/pc-formulaire.html`** — formulaire client pour le **permis de construire maison individuelle** (Cerfa 13406*16).
- **`backend/dp-service/`** — microservice Python commun aux deux : il constitue le dossier PDF
  (Cerfa rempli + notice + bordereau + pièces jointes). Le type est porté par le
  champ caché `dossier_type` (`dp` ou `pcmi`) — un seul service, une seule URL.

---

## Mise en place — 4 étapes

Toutes les commandes ci-dessous se lancent depuis `backend/dp-service/`.

### 1. Environnement Python et dépendances

Python **3.11 à 3.13**. Un environnement isolé évite tout conflit avec les
paquets du système :

```bash
cd backend/dp-service
python3 -m venv .venv
source .venv/bin/activate        # Windows : .venv\Scripts\activate
pip install -r requirements.txt
```

Sous Linux, décommentez `gunicorn` dans `requirements.txt` avant d'installer.

### 2. Configuration

Les réglages passent par des variables d'environnement, toutes documentées dans
`.env.example` à la racine du dépôt :

```bash
cp ../../.env.example ../../.env    # puis renseigner les valeurs
```

Le service lit l'environnement du processus : chargez le `.env` avec l'outil de
votre choix (`set -a; . ../../.env; set +a` sous bash), ou exportez les variables
à la main. Aucune valeur n'est obligatoire pour démarrer : les défauts sont ceux
du tableau en fin de document.

### 3. Récupérer les Cerfa officiels et lire leurs champs

Téléchargez sur service-public.gouv.fr et placez dans `backend/dp-service/` :
- `cerfa_16702-02.pdf` (déclaration préalable)
- `cerfa_13406-16.pdf` (permis de construire MI)

Ces PDF ne sont pas versionnés.

```bash
python cerfa.py inspect cerfa_16702-02.pdf     # -> champs_cerfa_16702-02.txt
python cerfa.py inspect cerfa_13406-16.pdf     # -> champs_cerfa_13406-16.txt
```

### 4. Compléter les mappings

Dans `cerfa.py`, remplacez les `"TODO_..."` par les vrais noms de champs lus à
l'étape 1 : `MAPPING_DP` pour la DP, `MAPPING_PCMI` pour le PC (et les
`CHECKBOXES_*` pour les cases à cocher, en indiquant l'état coché — colonne
`états=` du fichier d'inspection).

> Seule étape manuelle, une fois par formulaire. Un champ resté en `TODO_` est
> ignoré proprement : notice, bordereau et assemblage se génèrent quand même.

---

## Lancer le service

Environnement activé, depuis `backend/dp-service/` :

```bash
python app.py                            # développement — 127.0.0.1:8000, debug actif
waitress-serve --port=8000 app:app       # production Windows
gunicorn -w 2 -b 127.0.0.1:8000 app:app  # production Linux
```

Contrôle de bon fonctionnement :

```bash
curl http://127.0.0.1:8000/api/health
# {"ok": true, "cerfa": {"dp": true, "pcmi": true}}
```

`cerfa.dp` et `cerfa.pcmi` valent `false` tant que les PDF officiels ne sont pas
en place : le service démarre quand même, mais ne remplit alors aucun Cerfa.

> Le mode développement active le rechargement automatique et le débogueur
> Flask : ne jamais l'exposer publiquement.

---

## Intégration WordPress

Deux pages (ou une page avec onglets) : collez le contenu de `dp-formulaire.html`
et de `pc-formulaire.html` dans des blocs **HTML personnalisé**. Dans chacun,
renseignez en haut du `<script>` :

```js
var ENDPOINT = "https://api.votresite.fr/api/dp";  // vide = mode démo
```

Les deux formulaires pointent vers le **même** endpoint ; le service distingue DP
et PC via `dossier_type`. Réglez `ALLOW_ORIGIN=https://votresite.fr` pour le CORS.

---

## Différences DP / PC gérées automatiquement

| | DP (16702*02) | PC (13406*16) |
|---|---|---|
| Rubriques | 7 | 8 (+ réseaux, fiscalité, architecte) |
| Notice | descriptive | PCMI4 (terrain + projet + insertion) |
| Pièces | DP1–DP8 | PCMI1–PCMI8 + RE2020 + étude de sol |
| Architecte | — | signalé si SP > 150 m² ou personne morale |
| Fiscalité | surface taxable | surface taxable + stationnement + piscine |

## À votre main (volontairement)

- Le **dépôt** sur le guichet dématérialisé (obligatoire depuis le 01/01/2026) :
  vous vérifiez, signez et envoyez.
- Les **plans de conception** (DP1–DP4 / PCMI1–PCMI5) restent votre prestation.
- La **bascule DP↔PC** selon les seuils : le formulaire PC signale le seuil des
  150 m², mais le choix du bon régime relève de votre étude.

## Variables d'environnement

Liste exhaustive de ce que lit le code. Modèle complet : `.env.example`, à la
racine du dépôt. Aucune valeur sensible ne doit être versionnée.

| Variable | Rôle | Défaut | Lue par |
|---|---|---|---|
| `CERFA_DP` | chemin du Cerfa déclaration préalable | `cerfa_16702-02.pdf` | `app.py` |
| `CERFA_PCMI` | chemin du Cerfa permis de construire MI | `cerfa_13406-16.pdf` | `app.py` |
| `DOSSIER_DIR` | répertoire de sortie des dossiers PDF | `dossiers` | `app.py` |
| `ALLOW_ORIGIN` | origine autorisée pour le CORS | `*` | `app.py` |
| `NOTIFY_TO` | destinataire des notifications ; vide = envoi désactivé | — | `app.py` |
| `SMTP_HOST` | serveur d'envoi | `localhost` | `app.py` |
| `SMTP_PORT` | port SMTP | `587` | `app.py` |
| `SMTP_USER` | identifiant SMTP, sert aussi d'expéditeur | — | `app.py` |
| `SMTP_PASS` | mot de passe SMTP | — | `app.py` |

Deux mises en garde :

- `ALLOW_ORIGIN` vaut `*` par défaut : à restreindre au domaine réel en production.
- `SMTP_USER` et `SMTP_PASS` sont lus sans valeur de repli. Renseigner `NOTIFY_TO`
  sans eux fait échouer l'envoi — l'erreur est journalisée, le dossier reste produit.

## Note

`assets/DejaVuSans*.ttf` : police Unicode embarquée pour un rendu correct des
accents et du « m² » dans les PDF générés. Ne pas supprimer.
