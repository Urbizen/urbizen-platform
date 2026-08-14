# Guide 1 — métadonnées de publication

**À ne pas publier sans validation.** Ce fichier accompagne
`piscine-garage-carport-autorisation.html`, qui contient le corps de l'article
en balisage Gutenberg, prêt à coller dans l'éditeur de code.

| Champ | Valeur |
|---|---|
| **Titre** (H1) | Piscine, garage, carport : les seuils qui changent votre autorisation |
| **Slug** | `piscine-garage-carport-autorisation` |
| **URL** | `https://urbizen.fr/guides/piscine-garage-carport-autorisation/` |
| **Catégorie** | Autorisations & projets |
| **Image mise en avant** | `assets/images/blog/piscine-garage-carport.webp` (déjà au dépôt) |

## AIOSEO

**Title** — 58 caractères
```
Piscine, garage, carport : quelle autorisation ? | Urbizen
```

**Meta description** — 155 caractères
```
Piscine, garage ou carport : ce qui compte vraiment dans le calcul des surfaces, et comment savoir si votre projet relève d'une déclaration ou d'un permis.
```

`og_*` et `twitter_*` laissés NULL : ils héritent du title et de la description.

## Extrait (post_excerpt)

```
Trois seuils, et surtout trois façons de mesurer. Ce qui entre dans la surface
d'un bassin, ce qu'une dalle sous auvent change, et pourquoi un carport crée de
l'emprise sans créer de surface de plancher.
```

## Ce que l'article contient

| | |
|---|---:|
| Mots (hors légendes et sources) | **1 821** |
| H2 | 7 |
| H3 | 21 |
| Schémas dédiés | 3 |
| Liens internes | 3 |
| Références au code, liées | 8 |

## Liens internes posés

- `/declarations-prealables/` — section « Les pièces à réunir »
- `/permis-de-construire/` — même section, pour les projets au-delà des seuils
- `/conception/` — section « Ce qui fait revenir la mairie »

Aucun lien vers `/tarifs/` dans le corps : cette page porte l'intention prix,
isolée au lot C. Le CTA de fin d'article, servi par la catégorie, y mène si
besoin.

## Liens restant à poser

- **Vers le guide 2** — prévu depuis « Le garage qui fait basculer la maison
  au-delà de 150 m² ». Retiré à la rédaction : l'article n'existe pas encore, et
  un lien mort dans un guide sur la rigueur documentaire serait mal venu. À
  ajouter le jour de la publication du guide 2.
- **Depuis les pages DP et PC vers ce guide** — deux ancres prévues, sur la
  phrase de la dalle sous auvent et sur la ligne « Piscine » du tableau. À poser
  après publication, dans le lot commercial dédié.

## Schémas

Trois SVG dans `wordpress/urbizen-child/assets/images/guides/` :
`schema-piscine.svg`, `schema-garage.svg`, `schema-carport.svg`.

Vectoriels et non bitmap : ils portent du texte fin et des cotes, que le
rééchantillonnage d'un PNG rendrait illisibles sur écran dense. Chacun porte un
`<title>` et un `<desc>` liés par `aria-labelledby`, et l'article répète
l'information en `alt` — un schéma qui ne serait compréhensible qu'en le voyant
laisserait de côté les lecteurs d'écran.

Palette Urbizen : `#14233B`, `#55617A`, `#128A5A`, `#E4F5EC`, `#C9D3DD`,
`#EAEEF2`, `#FBFCFD`. Convention constante : **vert plein = compté**, **gris
tireté = non compté**, **contour bleu nuit = construction distincte ou
existant**.

## Vérification réglementaire

Chaque règle citée est sourcée dans `docs/VERIFICATION_REGLEMENTAIRE_GUIDES_01-02.md`.
Un point a été vérifié en plus au moment de la rédaction : la piscine hors-sol.
L'article R.421-5 dispense les constructions implantées pour trois mois au plus,
**délai ramené à quinze jours dans les périmètres protégés**. C'est la seule
règle de l'article qui ne figurait pas dans la note de vérification initiale.

## Ce qui n'est pas dans l'article, volontairement

- Aucun montant de prestation, aucune promesse d'obtention d'autorisation.
- Aucun auteur affiché, donc aucun risque de faire réapparaître l'archive
  laissée en 404 au lot A.
- Aucune reprise du tableau des seuils des pages DP et PC : l'article y renvoie
  et développe la méthode, il ne duplique pas la synthèse.
