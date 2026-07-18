# Automatisation des dossiers d'urbanisme (DP + PC maison individuelle)

Composants :

- **`dp-formulaire.html`** — formulaire client pour la **déclaration préalable** (Cerfa 16702*02).
- **`pc-formulaire.html`** — formulaire client pour le **permis de construire maison individuelle** (Cerfa 13406*16).
- **`dp-service/`** — microservice Python commun aux deux : il constitue le dossier PDF
  (Cerfa rempli + notice + bordereau + pièces jointes). Le type est porté par le
  champ caché `dossier_type` (`dp` ou `pcmi`) — un seul service, une seule URL.

---

## Mise en place — 3 étapes

### 1. Récupérer les Cerfa officiels et lire leurs champs

Téléchargez sur service-public.gouv.fr et placez dans `dp-service/` :
- `cerfa_16702-02.pdf` (déclaration préalable)
- `cerfa_13406-16.pdf` (permis de construire MI)

```bash
cd dp-service
pip install -r requirements.txt
python cerfa.py inspect cerfa_16702-02.pdf     # -> champs_cerfa_16702-02.txt
python cerfa.py inspect cerfa_13406-16.pdf     # -> champs_cerfa_13406-16.txt
```

### 2. Compléter les mappings

Dans `cerfa.py`, remplacez les `"TODO_..."` par les vrais noms de champs lus à
l'étape 1 : `MAPPING_DP` pour la DP, `MAPPING_PCMI` pour le PC (et les
`CHECKBOXES_*` pour les cases à cocher, en indiquant l'état coché — colonne
`états=` du fichier d'inspection).

> Seule étape manuelle, une fois par formulaire. Un champ resté en `TODO_` est
> ignoré proprement : notice, bordereau et assemblage se génèrent quand même.

### 3. Lancer le service

```bash
python app.py                          # dev
waitress-serve --port=8000 app:app     # prod Windows (comme vos bots)
```

`curl http://127.0.0.1:8000/api/health` → `{"ok": true, "cerfa": {"dp": true, "pcmi": true}}`

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

| Variable | Rôle | Défaut |
|---|---|---|
| `CERFA_DP` / `CERFA_PCMI` | chemins des Cerfa officiels | `cerfa_16702-02.pdf` / `cerfa_13406-16.pdf` |
| `DOSSIER_DIR` | dossier de sortie | `./dossiers` |
| `ALLOW_ORIGIN` | domaine WordPress autorisé (CORS) | `*` |
| `NOTIFY_TO`, `SMTP_HOST/PORT/USER/PASS` | notification e-mail | — |

## Note

`assets/DejaVuSans*.ttf` : police Unicode embarquée pour un rendu correct des
accents et du « m² » dans les PDF générés. Ne pas supprimer.
