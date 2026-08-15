# Guide 4 — métadonnées de publication

Accompagne `extension-maison-verifications-avant-plans.html`. Plan validé le
14 août 2026 dans `docs/PLAN_GUIDES_01-02.md`, suivi sans écart.

| Champ | Valeur |
|---|---|
| **Titre** (H1) | Extension de maison : 5 vérifications avant de dessiner les plans |
| **Slug** | `extension-maison-verifications-avant-plans` |
| **URL** | `https://urbizen.fr/guides/extension-maison-verifications-avant-plans/` |
| **Catégorie** | Autorisations & projets |
| **Image mise en avant** | `assets/images/blog/extension-maison.webp` (déjà au dépôt, carte 02 de l'accueil) |

Slug arrêté le 14 août 2026 : le chiffre « 5 » reste dans le H1, où il porte la
promesse, mais **pas dans l'URL** — une URL ne doit pas être à refaire le jour
où une sixième vérification s'impose.

## Intention de recherche

**Informationnelle, en amont du projet.** Le lecteur envisage un agrandissement
et n'a encore rien dessiné. Intention plus précoce que celle des autres guides,
et c'est ce qui fait sa valeur : il capte le lecteur avant qu'il ait choisi un
prestataire.

Requêtes secondaires visées : *extension 20 m² 40 m² · agrandissement maison
autorisation · extension 150 m² architecte · PLU extension maison · emprise au
sol extension · recul limite séparative extension · agrandir sa maison
démarches*.

## AIOSEO

**Title** — 62 caractères
```
Extension de maison : 5 vérifications avant les plans | Urbizen
```

**Meta description** — 155 caractères
```
Avant de dessiner une extension : ce que le PLU autorise, comment se calcule le total après travaux, et cinq points à vérifier pour ne pas refaire les plans.
```

`og_*` et `twitter_*` laissés NULL.

## Extrait (post_excerpt)

```
Ce que le règlement de zone autorise, la surface que vous aurez au total après
travaux, le seuil qui fait basculer vers le permis, l'architecte, le contexte du
terrain — et une check-list à reprendre avant le premier trait.
```

## Ce que l'article contient

| | |
|---|---:|
| Mots (hors légendes, alt et sources) | **2 982** |
| H2 | 7 |
| H3 | 18 |
| Schéma dédié | 1 |
| Tableaux | 1 |
| Check-list | 14 points |
| Liens internes | 8 |
| Références au code, liées | 16 |

## Conformité au plan validé

Les cinq H2 numérotés du plan sont repris à l'identique et dans l'ordre :
1. ce que le PLU autorise ; 2. surface créée et total après travaux ; 3. seuil
DP/PC ; 4. architecte et seuil de 150 m² ; 5. contexte du terrain. Suivis du
**récapitulatif en une page** prévu au plan, puis de « Une fois les
vérifications faites ».

Deux ajouts par rapport au plan, tous deux issus de la vérification
réglementaire :

1. la **formulation complète de R.431-2** — « soit la surface de plancher, soit
   l'emprise au sol de l'ensemble » —, que la note du 14 août signalait comme
   manquante sur la page commerciale ;
2. la **réserve de L.442-9** sur les rapports entre colotis, que le plan
   signalait comme « une phrase générale serait fausse ».

## Liens internes posés

| Cible | Emplacement |
|---|---|
| `/guides/lire-le-plu-de-son-terrain/` | vérification 1, pour retrouver la zone et son chapitre de règlement |
| `/guides/piscine-garage-carport-autorisation/` | vérification 2, sur le métré — **c'est le lien réciproque prévu au plan** |
| `/guides/dp-ou-permis-de-construire/` | vérification 3, sur l'articulation 40 / 150 m² |
| `/guides/delais-urbanisme-debut-des-travaux/` | « Les délais à prévoir » |
| `/guides/erreurs-dossier-urbanisme/` | même section, avant dépôt |
| `/declarations-prealables/` | « Ce que le dossier demandera » |
| `/permis-de-construire/` | même section |
| `/conception/` | même section |

Aucun lien vers `/tarifs/` dans le corps, conformément au lot C.

## La réciprocité avec le guide 1

Le plan prévoyait deux liens qui se répondent sans se dupliquer :

- **guide 1 → guide 4**, depuis la section sur le seuil des 150 m². Ce lien
  avait été retiré à la rédaction du guide 1 parce que l'article n'existait pas
  encore. **Il est posé dans ce lot**, dans
  `piscine-garage-carport-autorisation.html`.
- **guide 4 → guide 1**, depuis « Deux compteurs, appliqués à une extension ».

## Schéma

`assets/images/guides/schema-total-apres-travaux.svg` — trois projets fictifs
comparés sur une même échelle de surface de plancher, avec le seuil de 150 m²
matérialisé. Le troisième cas montre l'effet de la déduction du stationnement :
même volume bâti, total inchangé.

Palette Urbizen, avec le token `--u-error` (`#C0392B`) pour le seuil franchi —
c'est la seule teinte d'alerte de la charte, et elle n'est pas introduite ici :
elle existe déjà dans `urbizen-tokens.css`.

## Ce qui reste propre au guide

Rien n'est retiré de `/permis-de-construire/`, qui reste la page la mieux
fournie sur ce sujet. Le guide apporte ce qu'une page commerciale ne peut pas
porter : **l'ordre des vérifications**, le décompte exact du total après travaux
pièce par pièce, la distinction surface habitable / surface de plancher, la
mesure du recul depuis le point le plus avancé, et la check-list.

## Vérification réglementaire

`docs/VERIFICATION_REGLEMENTAIRE_GUIDES_03-07.md`, et pour les seuils
`docs/VERIFICATION_REGLEMENTAIRE_GUIDES_01-02.md`, dont aucune conclusion n'a
changé au 15 août 2026.

## Ce qui n'est pas dans l'article, volontairement

- Aucun montant de prestation, aucune promesse d'obtention.
- L'exemple d'emprise au sol est **fictif et annoncé comme tel**.
- Le guide dit explicitement qu'au-delà du seuil de 150 m², l'intervention d'un
  architecte est requise par la loi et qu'Urbizen ne s'y substitue pas.
