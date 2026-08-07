# Recette — adresse assistée du permis de construire

> Trace technique conservée **hors production**, avant suppression définitive des quatre
> demandes de recette. Toutes les données sont fictives : identités inventées, adresses postales
> publiques, courriels en `@example.com` (domaine réservé IANA, jamais remis).

## Ce que cette recette établit

| | |
|---|---|
| Commit serveur | `2ac4e21` — *refactor: let the address factory serve the building permit* |
| Commit interface | `ce9ec71` — *feat: assist applicant and land addresses on building permits* |
| **Commit réellement déployé** | **`ce9ec71`** |
| Sauvegarde production | `/home/u328261530/backups/pc-adresse-20260807-172450/` (avec `ROLLBACK.sh`) |
| Bancs formulaires | **1 063 contrôles**, 9 bancs, tous verts |
| Banc JavaScript | `test-adresse.mjs` — **78 contrôles × 2 parcours**, verts |
| Suites exécutables | **10 vertes** |
| Intégration WordPress locale | **NON EXÉCUTÉE** — `URBIZEN_WP_ROOT` indéfini |
| Recette production PC | **réussie** — trois scénarios, charge forgée, rendus, transport |
| Contrôle humain navigateur | **réussi** — autocomplétion Géoplateforme visible et fonctionnelle sur les deux blocs |
| Précision lat/lon | **conservée** — `48.8555` / `2.36041`, jamais `48.86` / `2.36` |
| Charge forgée | **correctement neutralisée** |
| Cadastre pendant le report | **conservé** |
| Non-régression piscine | **vérifiée sur la version déployée** |
| Anciens payloads PC sans mode | **lisibles**, sans provenance inventée ni repère fantôme |

Un « ✓ » sur la suite `integration` serait un saut, pas un succès : elle n'a jamais tourné.

### Ce que mon environnement n'a pas permis de vérifier

L'appel Géoplateforme **depuis un navigateur** n'était pas vérifiable de mon côté : mon Chrome
échoue sur `data.geopf.fr` comme sur le témoin `example.com`, tout en réussissant en même
origine. Ce n'était pas un défaut du site — la CSP de production est `upgrade-insecure-requests`
seule, le module construisait la bonne URL, et le service répondait depuis l'infrastructure en
0,07 s. **Le contrôle humain a levé cette réserve** : l'autocomplétion est visible et
fonctionnelle sur les deux blocs, dans le navigateur réel.

## Ce que la tranche a changé

Le permis portait son adresse en texte libre : trois champs plats pour le déclarant, trois pour
le terrain, sans mode de saisie. Il passe sur la fabrique partagée pour ses deux blocs, avec les
noms canoniques inchangés, et reçoit la case « même adresse » déjà validée en déclaration
préalable. L'obligation quitte le validateur générique pour la couche métier — `Validator::is_active()`
n'accepte qu'une condition par champ et ne saurait pas combiner « mode de saisie » et « même
adresse ». `Validator::is_active()` n'a pas été touché.

---

## URB-2026-0012 — PC-A

- **Identifiant interne** : `#1185`
- **Date** : 2026-08-07 17:31:52 (GMT)
- **Type de parcours** : `permis_construire`
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **État du report** : `"(clé absente)"`

### Scénario, attendu et constaté

**PC-A — deux adresses automatiques différentes**

**Attendu** : deux modes automatiques, deux adresses distinctes, codes commune corrects,
coordonnées conservées avec leur précision, aucun champ manuel concurrent, aucune clé de report.

**Constaté** : conforme. Déclarant `75104`, terrain `75107` — deux communes différentes, donc
aucune confusion possible entre les deux blocs. `voie_declarant`, `complement_declarant`,
`terrain_voie` et `terrain_complement` sont tous absents : le mode inactif n'a rien laissé.
La clé de report n'existe pas — décochée, la case ne persiste rien.

### Données d'adresse persistées

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

Champs d'adresse **absents** (mode inactif ou report) : `voie_declarant`, `complement_declarant`, `terrain_meme_adresse_declarant`, `terrain_voie`, `terrain_complement`

### Données cadastrales

| Champ | Valeur |
|---|---|
| `cad_section` | `(vide)` |
| `cad_numero` | `(vide)` |
| `terrain_superficie` | `(vide)` |
| `terrain_etat` | `(vide)` |
| `informations_cadastrales_differees` | `["non"]` |

### Précision des coordonnées

| | latitude | longitude |
|---|---|---|
| déclarant | `48.8555` | `2.36041` |
| terrain | `48.858819` | `2.294597` |

Aucune valeur n'est ramenée au centième : le pas déclaré (`increment => 0.000001`) gouverne
la persistance depuis `9a72c30`.

### Notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_form_type` | `permis_construire` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 17:31:53` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 17:31:54` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `7c883cf392b5c5caa1f3e6f9cd13e616` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `485f968b7499e302822b9768c6c15c59` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 17:31:53` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 17:31:54` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_reference` | `URB-2026-0012` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "architecte_nom": "",
  "architecte_ordre": "",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique PC — A. Aucune suite a donner.",
  "dossier_type": "pcmi",
  "email": "recette-pc-a@example.com",
  "emprise_avant": "0",
  "emprise_creee": "0",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "insertion": "",
  "intervention": "existant",
  "lat_declarant": "48.8555",
  "lon_declarant": "2.36041",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "extension",
  "nom": "RECETTE-PC",
  "pieces_differees": [],
  "prenom": "A",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "raccord_assainissement": "",
  "raccord_eau": "",
  "raccord_elec": "",
  "remarques": "",
  "sp_creee": "0",
  "sp_existante": "0",
  "sp_totale": "0",
  "surface_taxable": "0",
  "telephone": "0600000000",
  "terrain_adresse": "5 Avenue Anatole France 75007 Paris",
  "terrain_cp": "75007",
  "terrain_etat": "",
  "terrain_insee": "75107",
  "terrain_lat": "48.858819",
  "terrain_lon": "2.294597",
  "terrain_superficie": null,
  "terrain_ville": "Paris",
  "ville_declarant": "Paris"
}
```

---

## URB-2026-0013 — PC-B

- **Identifiant interne** : `#1186`
- **Date** : 2026-08-07 17:33:39 (GMT)
- **Type de parcours** : `permis_construire`
- **Statut** : `private`
- **Mode déclarant** : `manuel`
- **Mode terrain** : `manuel`
- **État du report** : `"oui"`

### Scénario, attendu et constaté

**PC-B — déclarant manuel + « même adresse », avec données cadastrales**

**Attendu** : terrain reconstruit par le serveur en mode manuel, aucun code commune, aucune
coordonnée, et surtout **cadastre intact** — la case ne concerne que l'adresse.

**Constaté** : conforme. Le terrain porte la voie et le complément du déclarant, son code postal
et sa commune ; ni `terrain_adresse`, ni `terrain_insee`, ni coordonnées — rien que le mode
manuel ne justifie.

**C'est la demande qui prouve l'indépendance du cadastre** : `cad_section`, `cad_numero`,
`terrain_superficie` et `terrain_etat` ont traversé le report sans être ni recopiés depuis le
déclarant, ni purgés avec l'adresse.

### Données d'adresse persistées

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

Champs d'adresse **absents** (mode inactif ou report) : `adresse_declarant`, `insee_declarant`, `lat_declarant`, `lon_declarant`, `terrain_adresse`, `terrain_insee`, `terrain_lat`, `terrain_lon`

### Données cadastrales

| Champ | Valeur |
|---|---|
| `cad_section` | `AB` |
| `cad_numero` | `0142` |
| `terrain_superficie` | `450` |
| `terrain_etat` | `Terrain en pente douce, oriente sud.` |
| `informations_cadastrales_differees` | `["non"]` |

### Précision des coordonnées

Aucune coordonnée — mode manuel des deux côtés, ce qui est le comportement attendu :
le service n'a rien fourni, et rien n'est inventé.

### Notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_form_type` | `permis_construire` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 17:33:39` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 17:33:40` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `4ce416b73b81dc45cc7dcbe31bcc9cff` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `01d8fc6817a1ecc4a9d0ec0a3f16cca5` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 17:33:39` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 17:33:40` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_reference` | `URB-2026-0013` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "architecte_nom": "",
  "architecte_ordre": "",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "0142",
  "cad_section": "AB",
  "complement_declarant": "Bâtiment B",
  "cp_declarant": "20000",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique PC — B. Aucune suite a donner.",
  "dossier_type": "pcmi",
  "email": "recette-pc-b@example.com",
  "emprise_avant": "0",
  "emprise_creee": "0",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insertion": "",
  "intervention": "existant",
  "materiaux": "",
  "mode_adresse": "manuel",
  "mode_adresse_declarant": "manuel",
  "nature": "extension",
  "nom": "RECETTE-PC",
  "pieces_differees": [],
  "prenom": "B",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "raccord_assainissement": "",
  "raccord_eau": "",
  "raccord_elec": "",
  "remarques": "",
  "sp_creee": "0",
  "sp_existante": "0",
  "sp_totale": "0",
  "surface_taxable": "0",
  "telephone": "0600000000",
  "terrain_complement": "Bâtiment B",
  "terrain_cp": "20000",
  "terrain_etat": "Terrain en pente douce, oriente sud.",
  "terrain_meme_adresse_declarant": "oui",
  "terrain_superficie": 450,
  "terrain_ville": "Ajaccio",
  "terrain_voie": "Lieu-dit Les Vignes",
  "ville_declarant": "Ajaccio",
  "voie_declarant": "Lieu-dit Les Vignes"
}
```

---

## URB-2026-0014 — PC-C

- **Identifiant interne** : `#1187`
- **Date** : 2026-08-07 17:34:53 (GMT)
- **Type de parcours** : `permis_construire`
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **État du report** : `"oui"`

### Scénario, attendu et constaté

**PC-C — déclarant automatique + « même adresse »**

**Attendu** : terrain reconstruit en automatique, adresse, code postal, commune et code commune
identiques, latitude et longitude identiques et sans perte, aucun champ manuel.

**Constaté** : conforme. Les deux blocs portent la même adresse au caractère près, et les
coordonnées sont recopiées sans qu'un chiffre se perde au passage.

### Données d'adresse persistées

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

Champs d'adresse **absents** (mode inactif ou report) : `voie_declarant`, `complement_declarant`, `terrain_voie`, `terrain_complement`

### Données cadastrales

| Champ | Valeur |
|---|---|
| `cad_section` | `(vide)` |
| `cad_numero` | `(vide)` |
| `terrain_superficie` | `(vide)` |
| `terrain_etat` | `(vide)` |
| `informations_cadastrales_differees` | `["non"]` |

### Précision des coordonnées

| | latitude | longitude |
|---|---|---|
| déclarant | `48.8555` | `2.36041` |
| terrain | `48.8555` | `2.36041` |

Aucune valeur n'est ramenée au centième : le pas déclaré (`increment => 0.000001`) gouverne
la persistance depuis `9a72c30`.

### Notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_form_type` | `permis_construire` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 17:34:54` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 17:34:54` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `08e027d549f05df2153a03329af04e27` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `71fe16232199b9bcca5705a6e781d7e0` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 17:34:54` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 17:34:54` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_reference` | `URB-2026-0014` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "architecte_nom": "",
  "architecte_ordre": "",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "",
  "cad_section": "",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique PC — C. Aucune suite a donner.",
  "dossier_type": "pcmi",
  "email": "recette-pc-c@example.com",
  "emprise_avant": "0",
  "emprise_creee": "0",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "insertion": "",
  "intervention": "existant",
  "lat_declarant": "48.8555",
  "lon_declarant": "2.36041",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "extension",
  "nom": "RECETTE-PC",
  "pieces_differees": [],
  "prenom": "C",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "raccord_assainissement": "",
  "raccord_eau": "",
  "raccord_elec": "",
  "remarques": "",
  "sp_creee": "0",
  "sp_existante": "0",
  "sp_totale": "0",
  "surface_taxable": "0",
  "telephone": "0600000000",
  "terrain_adresse": "10 Rue de Rivoli 75004 Paris",
  "terrain_cp": "75004",
  "terrain_etat": "",
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

## URB-2026-0015 — CHARGE FORGÉE

- **Identifiant interne** : `#1188`
- **Date** : 2026-08-07 17:36:08 (GMT)
- **Type de parcours** : `permis_construire`
- **Statut** : `private`
- **Mode déclarant** : `automatique`
- **Mode terrain** : `automatique`
- **État du report** : `"oui"`

### Scénario, attendu et constaté

**CHARGE FORGÉE — case cochée **et** adresse terrain concurrente injectée**

Le navigateur a réellement envoyé `99 Rue Forgee 13001 Marseille`, code commune `13201`,
latitude `43.296500`, en contournant l'interface : les contrôles du bloc terrain ont été
réactivés de force avant l'envoi.

**Constaté** : **aucune trace de Marseille dans la charge persistée.** Le serveur a purgé
l'intégralité du terrain reçu avant de le reconstruire depuis le déclarant validé.

Et le cadastre a survécu à cette purge : `CD` / `0999` / `777` sont intacts. La purge ne connaît
que les neuf rôles d'adresse — c'est ce qui distingue une adresse d'une parcelle.

### Données d'adresse persistées

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

Champs d'adresse **absents** (mode inactif ou report) : `voie_declarant`, `complement_declarant`, `terrain_voie`, `terrain_complement`

### Données cadastrales

| Champ | Valeur |
|---|---|
| `cad_section` | `CD` |
| `cad_numero` | `0999` |
| `terrain_superficie` | `777` |
| `terrain_etat` | `Cadastre qui doit survivre.` |
| `informations_cadastrales_differees` | `["non"]` |

### Précision des coordonnées

| | latitude | longitude |
|---|---|---|
| déclarant | `48.8555` | `2.36041` |
| terrain | `48.8555` | `2.36041` |

Aucune valeur n'est ramenée au centième : le pas déclaré (`increment => 0.000001`) gouverne
la persistance depuis `9a72c30`.

### Notifications

| Métadonnée | Valeur |
|---|---|
| `_urbizen_files` | `[]` |
| `_urbizen_files_status` | `none` |
| `_urbizen_form_type` | `permis_construire` |
| `_urbizen_mail_attempts` | `1` |
| `_urbizen_mail_attempts__customer_acknowledgement` | `1` |
| `_urbizen_mail_last_attempt_gmt` | `2026-08-07 17:36:08` |
| `_urbizen_mail_last_attempt_gmt__customer_acknowledgement` | `2026-08-07 17:36:08` |
| `_urbizen_mail_last_error_code` | `(vide)` |
| `_urbizen_mail_last_error_code__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt` | `(vide)` |
| `_urbizen_mail_next_attempt_gmt__customer_acknowledgement` | `(vide)` |
| `_urbizen_mail_notification_id` | `d7ca5bfd46562a296abffb33bb283059` |
| `_urbizen_mail_notification_id__customer_acknowledgement` | `81d9c7d736b6ecb7faa41b93456cf2a5` |
| `_urbizen_mail_sent_at_gmt` | `2026-08-07 17:36:08` |
| `_urbizen_mail_sent_at_gmt__customer_acknowledgement` | `2026-08-07 17:36:08` |
| `_urbizen_mail_status` | `sent` |
| `_urbizen_mail_status__customer_acknowledgement` | `sent` |
| `_urbizen_reference` | `URB-2026-0015` |
| `_urbizen_status` | `received` |

### Charge persistée complète

```json
{
  "abf": "non",
  "adresse_declarant": "10 Rue de Rivoli 75004 Paris",
  "architecte_nom": "",
  "architecte_ordre": "",
  "attest_exact": true,
  "attest_rgpd": true,
  "cad_numero": "0999",
  "cad_section": "CD",
  "cp_declarant": "75004",
  "declarant_type": "particulier",
  "demolition": "non",
  "depot_guichet": [
    "non"
  ],
  "description": "Recette technique PC — charge forgee. Aucune suite a donner.",
  "dossier_type": "pcmi",
  "email": "recette-pc-forge@example.com",
  "emprise_avant": "0",
  "emprise_creee": "0",
  "informations_cadastrales_differees": [
    "non"
  ],
  "insee_declarant": "75104",
  "insertion": "",
  "intervention": "existant",
  "lat_declarant": "48.8555",
  "lon_declarant": "2.36041",
  "materiaux": "",
  "mode_adresse": "automatique",
  "mode_adresse_declarant": "automatique",
  "nature": "extension",
  "nom": "RECETTE-PC",
  "pieces_differees": [],
  "prenom": "FORGE",
  "projets_supplementaires": [],
  "qualite": "proprietaire",
  "raccord_assainissement": "",
  "raccord_eau": "",
  "raccord_elec": "",
  "remarques": "",
  "sp_creee": "0",
  "sp_existante": "0",
  "sp_totale": "0",
  "surface_taxable": "0",
  "telephone": "0600000000",
  "terrain_adresse": "10 Rue de Rivoli 75004 Paris",
  "terrain_cp": "75004",
  "terrain_etat": "Cadastre qui doit survivre.",
  "terrain_insee": "75104",
  "terrain_lat": "48.8555",
  "terrain_lon": "2.36041",
  "terrain_meme_adresse_declarant": "oui",
  "terrain_superficie": 777,
  "terrain_ville": "Paris",
  "ville_declarant": "Paris"
}
```

---

## Suppression

Ces quatre demandes ont été **supprimées définitivement** de la production après établissement
de ce fichier. `TrashGuard` interdit la corbeille pour le type `urbizen_demande` : la suppression
ne pouvait qu'être définitive. Chaque identifiant a été confronté à sa référence **et** à son type
avant retrait ; une discordance aurait tout arrêté.

`URB-2026-0006` et `URB-2026-0002`, antérieures et étrangères à la recette, n'ont pas été touchées.
