# Adresse assistée — audit et architecture

> Passe en cours sur `feat/dp-pc-live-forms`. Rien n'est déployé.

## 1 · Ce qui existe déjà

### Un service, et un seul

| Point d'accès | Usage | Employé par |
|---|---|---|
| `data.geopf.fr/geocodage/completion` | autocomplétion pendant la frappe | `urbizen-cadastre.js` |
| `data.geopf.fr/geocodage/search` | géocodage canonique — code INSEE, coordonnées | `urbizen-cadastre.js`, documents DP et PC |
| `apicarto.ign.fr/api/cadastre/parcelle` | parcelle sous un point | `urbizen-cadastre.js`, documents DP et PC |

Géoplateforme IGN, base BAN, sans clé. **`api-adresse.data.gouv.fr` n'est pas employé** — consigne de
projet inscrite dans `AI_CONTEXT.md` et rappelée en tête de `urbizen-cadastre.js`. Rien à choisir
donc, et rien à documenter comme nouveau service : celui-ci est en production depuis le lot
cadastre.

**Limites connues** : quota d'usage des services publics, parcellaire rafraîchi environ deux fois
par an, surface cadastrale indicative. En panne, le composant existant affiche un message et laisse
la personne continuer — c'est déjà la règle, elle est reprise telle quelle.

### Un composant d'autocomplétion, accessible, déjà éprouvé

`wordpress/urbizen-platform/assets/js/urbizen-cadastre.js` porte une combobox complète :
`role="listbox"`, `aria-expanded`, `aria-activedescendant`, flèches, `Entrée`, `Échap`, fermeture au
clic extérieur, `AbortController` par frappe, anti-rebond à 260 ms, délai maximal à 8 s, et un rendu
des propositions qui n'emploie **jamais** `innerHTML` sur une valeur venue du service.

### Un vocabulaire de contrat, déjà partagé

`buildContract()` publie une forme canonique que `urbizen-form.js` relit par `data-from` :

```
address.label · address.houseNumber · address.street · address.postcode
address.city  · address.cityCode
location.latitude · location.longitude
parcel.communeCode · parcel.prefix · parcel.section · parcel.number · parcel.id · parcel.surfaceM2
```

C'est le vocabulaire à réemployer. En inventer un second condamnerait l'administration à savoir lire
deux formes de la même adresse.

### Les champs d'adresse persistés aujourd'hui

| Parcours | Terrain | Déclarant |
|---|---|---|
| Déclaration préalable | `terrain_adresse`*, `terrain_cp`*, `terrain_ville`*, `cad_section`, `cad_numero`, `terrain_superficie` | `adresse_declarant`*, `cp_declarant`*, `ville_declarant`* |
| Permis de construire | idem + `terrain_etat` | idem |
| Conception | `terrain_adresse`, `terrain_cp`, `terrain_ville`, `cad_section`, `cad_numero`, `terrain_surface` — **tous facultatifs**, sous `a_terrain` | — |

`*` obligatoire côté serveur.

**Trois différences de structure à respecter.**

1. La conception n'exige aucune adresse : elle demande d'abord `a_terrain` (le client a-t-il un
   terrain ?). L'adresse assistée y est donc une aide, jamais une exigence.
2. DP et PC sont des **documents statiques** servis en cadre ; la conception est **rendue par le
   serveur** (`StepFormRenderer`). Le même module doit donc pouvoir être chargé des deux façons.
3. DP et PC portent déjà, dans le document, un géocodage « Localiser ma parcelle » distinct de
   l'autocomplétion. Il reste : il répond à une autre question — où est la parcelle — et s'appuie
   sur `/search`, pas sur `/completion`.

## 2 · Architecture retenue

**Un module, trois consommateurs, aucun second système.**

`wordpress/urbizen-child/assets/js/urbizen-form-adresse.js` — même famille que
`urbizen-form-nombres.js`, donc même contrat de version et même mode de chargement. Il porte :

- l'appel au service, avec les conventions déjà en place (anti-rebond, annulation, délai maximal) ;
- la combobox accessible ;
- la bascule manuelle ;
- l'écriture des champs structurés, **dans le vocabulaire du contrat existant**.

### Pourquoi pas dans `urbizen-cadastre.js`

Ce serait le bon endroit à terme, et c'est ce que prévoit la branche cadastrale : en extraire la
combobox pour que les deux s'en servent. Mais ce fichier est un actif du greffon monté par le bloc
cadastre de **l'accueil**, et l'accueil est hors périmètre de cette branche. Le module est donc créé
là où il servira aux trois parcours, dans **un seul exemplaire**, et la réconciliation consistera à
supprimer la copie du cadastre — pas à départager deux implémentations rivales.

### Ce que le serveur reçoit

| Champ | Mode | Rôle |
|---|---|---|
| `mode_adresse` | les deux | `automatique` ou `manuel` |
| `terrain_adresse` | les deux | adresse complète, lisible |
| `terrain_voie` | manuel | numéro et voie |
| `terrain_complement` | manuel | facultatif |
| `terrain_cp`, `terrain_ville` | les deux | code postal, commune |
| `terrain_libelle_service` | automatique | libellé rendu par le service, tel quel |
| `terrain_insee` | automatique | code commune, s'il est fourni |
| `terrain_lat`, `terrain_lon` | automatique | coordonnées, **seulement si le service les fournit** |

Le mode déclaré par le navigateur n'est jamais cru sur parole : le serveur applique ses règles
d'après `mode_adresse`, **et** écarte tout champ qui n'appartient pas au mode retenu.
