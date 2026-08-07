# Recette — adresse assistée du déclarant et report sur le terrain

> Trace technique conservée **hors production**, avant suppression définitive des cinq
> demandes de recette. Toutes les données sont fictives : identités inventées, adresses
> postales publiques, courriels en `@example.com` (domaine réservé IANA, jamais remis).

## Ce que cette recette établit

| | |
|---|---|
| Commit validé | `9a72c30` — *fix: preserve address report state and coordinate precision* |
| Commits de la tranche | `1a07277` → `e1f3379` → `9a72c30`, branche `feat/dp-pc-live-forms` |
| Sauvegarde production | `/home/u328261530/backups/dp-adresse-20260807-091836/` (avec `ROLLBACK.sh`) |
| Bancs formulaires | **908 contrôles**, 9 bancs, tous verts |
| Suites exécutables | **10 vertes** — blocks, cadastre, comptes, conception, domaine, files, forms, mail, pricing, submissions |
| Intégration WordPress locale | **NON EXÉCUTÉE** — `URBIZEN_WP_ROOT` indéfini, `run.sh` sort en 0 sans rien lancer |
| Recette réelle production | **réussie** — trois scénarios, rendus et transport vérifiés |
| Charge forgée `URB-2026-0011` | **correctement neutralisée** |

Un « ✓ » sur la suite `integration` serait un saut, pas un succès : elle n'a jamais tourné.

## Les deux défauts corrigés par `9a72c30`

1. **Le report ne s'activait jamais.** `Validator::clean_liste()` rend une liste pour tout
   champ `checkbox`, même à case unique. `reportee()` rejetait les tableaux : la condition
   était toujours fausse, sans erreur ni symptôme. Preuve : `URB-2026-0007`.
2. **Les coordonnées étaient arrondies au centième.** `NombreLocalise` ramenait tout nombre
   à deux décimales — juste pour une surface, faux pour une latitude. La précision était
   déjà dans le contrat (`increment => 0.000001`), elle n'était pas lue. Preuve :
   `URB-2026-0007` contre `URB-2026-0008`.

---

## URB-2026-0007

- **Identifiant WordPress** : `#1180`
- **Créée le** : 2026-08-07 08:44:32 (GMT)
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **Report** : `[]`

### Résultat attendu du scénario

Première recette (commit `e1f3379`) — déclarant automatique, terrain automatique différent.

**PREUVE DU DÉFAUT DE PRÉCISION**, corrigé par `9a72c30`.
Les coordonnées y sont persistées `48.86` / `2.36` au lieu de `48.8555` / `2.36041` :
`NombreLocalise` arrondissait alors tout nombre à deux décimales, soit environ
six cents mètres d'erreur sur une latitude.

Elle porte aussi la trace du **second défaut** : `terrain_meme_adresse_declarant`
y vaut `[]` — une liste vide persistée alors que la case était décochée. C'est ce
tableau fantôme qui a mis sur la voie : le socle rend une LISTE pour tout champ
`checkbox`, et `reportee()` la rejetait, si bien que le report ne se serait jamais
activé.

### Champs d'adresse persistés

| Champ | Valeur |
|---|---|
| `mode_adresse_declarant` | `automatique` |
| `adresse_declarant` | `10 Rue de Rivoli 75004 Paris` |
| `cp_declarant` | `75004` |
| `ville_declarant` | `Paris` |
| `insee_declarant` | `75104` |
| `lat_declarant` | `48.86` |
| `lon_declarant` | `2.36` |
| `terrain_meme_adresse_declarant` | `[]` |
| `mode_adresse` | `automatique` |
| `terrain_adresse` | `5 Avenue Anatole France 75007 Paris` |
| `terrain_cp` | `75007` |
| `terrain_ville` | `Paris` |
| `terrain_insee` | `75107` |
| `terrain_lat` | `48.86` |
| `terrain_lon` | `2.29` |

### Statut des notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 08:44:33` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 08:44:34` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `55c98c7a6980275e16d4ce94c126cde7` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `f94a493b7a59eb4efb8edb3dae00b7a4` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 08:44:33` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 08:44:34` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "changement_destination": "",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Demande de recette technique — A. Aucune suite a donner.",
  "email": "recette-technique-a@example.com",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "intervention": "existant",
  "lat_declarant": "48.86",
  "lon_declarant": "2.36",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "cloture_mur",
  "nom": "RECETTE-TECHNIQUE",
  "pieces_differees": [],
  "prenom": "A",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "remarques": "",
  "telephone": "0600000000",
  "terrain_adresse": "5 Avenue Anatole France 75007 Paris",
  "terrain_cp": "75007",
  "terrain_insee": "75107",
  "terrain_lat": "48.86",
  "terrain_lon": "2.29",
  "terrain_meme_adresse_declarant": [],
  "terrain_superficie": null,
  "terrain_ville": "Paris",
  "ville_declarant": "Paris"
}
```

---

## URB-2026-0008

- **Identifiant WordPress** : `#1181`
- **Créée le** : 2026-08-07 09:22:18 (GMT)
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **Report** : `"(clé absente)"`

### Résultat attendu du scénario

TEST A — déclarant automatique, terrain automatique **différent**.

Deux adresses distinctes conservées, chacune avec son code commune.
Coordonnées à pleine précision des deux côtés : la correction `9a72c30` est ici prouvée.
Aucune clé de report persistée : décochée, la case ne laisse plus rien.

### Champs d'adresse persistés

| Champ | Valeur |
|---|---|
| `mode_adresse_declarant` | `automatique` |
| `adresse_declarant` | `10 Rue de Rivoli 75004 Paris` |
| `cp_declarant` | `75004` |
| `ville_declarant` | `Paris` |
| `insee_declarant` | `75104` |
| `lat_declarant` | `48.8555` |
| `lon_declarant` | `2.36041` |
| `mode_adresse` | `automatique` |
| `terrain_adresse` | `5 Avenue Anatole France 75007 Paris` |
| `terrain_cp` | `75007` |
| `terrain_ville` | `Paris` |
| `terrain_insee` | `75107` |
| `terrain_lat` | `48.858819` |
| `terrain_lon` | `2.294597` |

### Statut des notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 09:22:19` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 09:22:25` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `bdfca393f330b3e00ffa48970b64012c` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `835fa45e1df753def4bd658a8b52ec81` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 09:22:19` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 09:22:25` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "changement_destination": "",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique 2 — A. Aucune suite a donner.",
  "email": "recette2-a@example.com",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "intervention": "existant",
  "lat_declarant": "48.8555",
  "lon_declarant": "2.36041",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "cloture_mur",
  "nom": "RECETTE-2",
  "pieces_differees": [],
  "prenom": "A",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "remarques": "",
  "telephone": "0600000000",
  "terrain_adresse": "5 Avenue Anatole France 75007 Paris",
  "terrain_cp": "75007",
  "terrain_insee": "75107",
  "terrain_lat": "48.858819",
  "terrain_lon": "2.294597",
  "terrain_superficie": null,
  "terrain_ville": "Paris",
  "ville_declarant": "Paris"
}
```

---

## URB-2026-0009

- **Identifiant WordPress** : `#1182`
- **Créée le** : 2026-08-07 09:24:59 (GMT)
- **Statut** : `private`
- **Mode déclarant** : `manuel`
- **Mode terrain** : `manuel`
- **Report** : `"oui"`

### Résultat attendu du scénario

TEST B — déclarant **manuel**, case « même adresse » cochée.

Terrain reconstruit par le serveur dans le mode réel du déclarant : `manuel`.
Voie et complément recopiés ; ni libellé de service, ni code commune, ni coordonnées
— rien que le mode manuel ne justifie.
Case persistée en scalaire `oui`, plus en liste.

### Champs d'adresse persistés

| Champ | Valeur |
|---|---|
| `mode_adresse_declarant` | `manuel` |
| `voie_declarant` | `Lieu-dit Les Vignes` |
| `complement_declarant` | `Bâtiment B` |
| `cp_declarant` | `20000` |
| `ville_declarant` | `Ajaccio` |
| `terrain_meme_adresse_declarant` | `oui` |
| `mode_adresse` | `manuel` |
| `terrain_voie` | `Lieu-dit Les Vignes` |
| `terrain_complement` | `Bâtiment B` |
| `terrain_cp` | `20000` |
| `terrain_ville` | `Ajaccio` |

### Statut des notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 09:25:00` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 09:25:00` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `59f43130a0caa519c0db19af592b5ad2` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `da8b37c1097d2566fcf97a9178e6b0d6` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 09:25:00` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 09:25:00` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "changement_destination": "",
  "complement_declarant": "Bâtiment B",
  "cp_declarant": "20000",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique 2 — B. Aucune suite a donner.",
  "email": "recette2-b@example.com",
  "informations_cadastrales_differees": [
    "non"
  ],
  "intervention": "existant",
  "materiaux": "",
  "mode_adresse": "manuel",
  "mode_adresse_declarant": "manuel",
  "nature": "cloture_mur",
  "nom": "RECETTE-2",
  "pieces_differees": [],
  "prenom": "B",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "remarques": "",
  "telephone": "0600000000",
  "terrain_complement": "Bâtiment B",
  "terrain_cp": "20000",
  "terrain_meme_adresse_declarant": "oui",
  "terrain_superficie": null,
  "terrain_ville": "Ajaccio",
  "terrain_voie": "Lieu-dit Les Vignes",
  "ville_declarant": "Ajaccio",
  "voie_declarant": "Lieu-dit Les Vignes"
}
```

---

## URB-2026-0010

- **Identifiant WordPress** : `#1183`
- **Créée le** : 2026-08-07 09:26:35 (GMT)
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **Report** : `"oui"`

### Résultat attendu du scénario

TEST C — déclarant **automatique**, case « même adresse » cochée.

Terrain reconstruit en `automatique` depuis le déclarant validé.
Code commune et coordonnées recopiés sans perte de précision.

### Champs d'adresse persistés

| Champ | Valeur |
|---|---|
| `mode_adresse_declarant` | `automatique` |
| `adresse_declarant` | `10 Rue de Rivoli 75004 Paris` |
| `cp_declarant` | `75004` |
| `ville_declarant` | `Paris` |
| `insee_declarant` | `75104` |
| `lat_declarant` | `48.8555` |
| `lon_declarant` | `2.36041` |
| `terrain_meme_adresse_declarant` | `oui` |
| `mode_adresse` | `automatique` |
| `terrain_adresse` | `10 Rue de Rivoli 75004 Paris` |
| `terrain_cp` | `75004` |
| `terrain_ville` | `Paris` |
| `terrain_insee` | `75104` |
| `terrain_lat` | `48.8555` |
| `terrain_lon` | `2.36041` |

### Statut des notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 09:26:36` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 09:26:36` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `63f499aae29c55c90bfe025fd32b6236` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `79a378a68519f18d2261d546ea108552` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 09:26:36` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 09:26:36` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "changement_destination": "",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique 2 — C. Aucune suite a donner.",
  "email": "recette2-c@example.com",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "intervention": "existant",
  "lat_declarant": "48.8555",
  "lon_declarant": "2.36041",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "cloture_mur",
  "nom": "RECETTE-2",
  "pieces_differees": [],
  "prenom": "C",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "remarques": "",
  "telephone": "0600000000",
  "terrain_adresse": "10 Rue de Rivoli 75004 Paris",
  "terrain_cp": "75004",
  "terrain_insee": "75104",
  "terrain_lat": "48.8555",
  "terrain_lon": "2.36041",
  "terrain_meme_adresse_declarant": "oui",
  "terrain_superficie": null,
  "terrain_ville": "Paris",
  "ville_declarant": "Paris"
}
```

---

## URB-2026-0011

- **Identifiant WordPress** : `#1184`
- **Créée le** : 2026-08-07 09:28:12 (GMT)
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **Report** : `"oui"`

### Résultat attendu du scénario

CHARGE FORGÉE — case cochée **et** adresse terrain concurrente injectée.

Le navigateur a réellement envoyé `99 Rue Forgee 13001 Marseille`, INSEE `13201`,
latitude `43.296500`, en contournant l'interface (contrôles réactivés de force).
**Aucune trace de Marseille dans la charge persistée.**
Le serveur a purgé le terrain reçu avant de le reconstruire depuis le déclarant.

### Champs d'adresse persistés

| Champ | Valeur |
|---|---|
| `mode_adresse_declarant` | `automatique` |
| `adresse_declarant` | `10 Rue de Rivoli 75004 Paris` |
| `cp_declarant` | `75004` |
| `ville_declarant` | `Paris` |
| `insee_declarant` | `75104` |
| `lat_declarant` | `48.8555` |
| `lon_declarant` | `2.36041` |
| `terrain_meme_adresse_declarant` | `oui` |
| `mode_adresse` | `automatique` |
| `terrain_adresse` | `10 Rue de Rivoli 75004 Paris` |
| `terrain_cp` | `75004` |
| `terrain_ville` | `Paris` |
| `terrain_insee` | `75104` |
| `terrain_lat` | `48.8555` |
| `terrain_lon` | `2.36041` |

### Statut des notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 09:28:14` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 09:28:14` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `2906e7a8e6b7c4804be7ffe8c1cc4ceb` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `2aa86037219155918792204c39a79a04` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 09:28:14` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 09:28:14` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "changement_destination": "",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique 2 — charge forgee. Aucune suite a donner.",
  "email": "recette2-forge@example.com",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "intervention": "existant",
  "lat_declarant": "48.8555",
  "lon_declarant": "2.36041",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "cloture_mur",
  "nom": "RECETTE-2",
  "pieces_differees": [],
  "prenom": "FORGE",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "remarques": "",
  "telephone": "0600000000",
  "terrain_adresse": "10 Rue de Rivoli 75004 Paris",
  "terrain_cp": "75004",
  "terrain_insee": "75104",
  "terrain_lat": "48.8555",
  "terrain_lon": "2.36041",
  "terrain_meme_adresse_declarant": "oui",
  "terrain_superficie": null,
  "terrain_ville": "Paris",
  "ville_declarant": "Paris"
}
```

---

## Suppression

Ces cinq demandes ont été **supprimées définitivement** de la production après
établissement de ce fichier. `TrashGuard` interdit la corbeille pour le type
`urbizen_demande` : la suppression ne pouvait qu'être définitive.

`URB-2026-0006` et `URB-2026-0002`, antérieures et étrangères à la recette, n'ont pas été
touchées.
