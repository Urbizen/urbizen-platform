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
| Liens internes | 5 |
| Références au code, liées | 8 |

## Liens internes posés

- `/declarations-prealables/` — **introduction, 2ᵉ paragraphe** (le métré comme
  point de départ du dossier), puis section « Les pièces à réunir »
- `/permis-de-construire/` — section « Les pièces à réunir », pour les projets
  au-delà des seuils
- `/conception/` — section « Ce qui fait revenir la mairie »
- `/guides/extension-maison-verifications-avant-plans/` — lien réciproque prévu
  au plan
- `/guides/erreurs-dossier-urbanisme/` — avant dépôt

> Le décompte ci-dessus disait **3** jusqu'au lot GUIDES, alors que l'article en
> portait déjà cinq : les deux renvois vers d'autres guides manquaient à la
> liste. L'écart est antérieur à ce lot ; il est corrigé ici parce qu'il se
> constate en lisant le fichier. Seul l'emplacement en introduction est nouveau.

Aucun lien vers `/tarifs/` dans le corps : cette page porte l'intention prix,
isolée au lot C. Le CTA de fin d'article, servi par la catégorie, y mène si
besoin.

## Liens restant à poser

- **Depuis les pages DP et PC vers ce guide** — deux ancres prévues, sur la
  phrase de la dalle sous auvent et sur la ligne « Piscine » du tableau. À poser
  après publication, dans le lot commercial dédié.

## Mise à jour du 15 août 2026 — lot `feat/guides-urbizen-complets`

Deux modifications, et deux seulement. Le reste de l'article est inchangé.

### 1 · Le lien réciproque vers le guide Extension est posé

Il était prévu au plan depuis « Le garage qui fait basculer la maison au-delà de
150 m² », et avait été retiré à la rédaction parce que l'article cible n'existait
pas encore. `/guides/extension-maison-verifications-avant-plans/` est publié dans
ce lot : le lien est en place, en fin de section, et le guide 4 renvoie ici
depuis sa section sur le métré. Les deux liens se répondent sans se dupliquer.

Un second lien a été ajouté dans « Les pièces à réunir », vers
`/guides/erreurs-dossier-urbanisme/` : c'est là que le lecteur qui vient de lire
la liste des pièces cherche à savoir ce qui fait revenir la mairie.

### 2 · La liste des pièces de la déclaration préalable est corrigée

**Erreur réglementaire objectivement constatée.** L'article écrivait :

> « Une déclaration préalable s'appuie sur un plan de situation, un plan de masse
> coté dans les trois dimensions, un plan de coupe du terrain, et selon le projet
> un plan des façades, un document graphique d'insertion et deux
> photographies. »

Le **plan en coupe** y figurait parmi les pièces systématiques. Il ne l'est pas :
la liste de base de l'article R.431-36 comporte a) le plan de situation, b) le
plan de masse coté dans les trois dimensions, c) la représentation de l'aspect
extérieur, d) le justificatif aviation civile le cas échéant. Le plan en coupe
relève de R.431-10 b), auquel R.431-36 renvoie « s'il y a lieu ».

Deux éléments s'ajoutent, découverts à la vérification du 15 août 2026 :

- R.431-36 est **en vigueur dans une nouvelle version depuis le 1er juillet
  2026**, issue du décret n° 2026-291 du 17 avril 2026. L'article n'en tenait pas
  compte, ayant été rédigé le 14 août sans que ce point ait été contrôlé ;
- cette version exige le **document d'insertion et les photographies** pour les
  projets **visibles depuis l'espace public**, en plus des secteurs protégés — et
  se clôt sur « aucune autre information ou pièce ne peut être exigée par
  l'autorité compétente », garantie utile au lecteur et absente de l'article.

Le paragraphe a été réécrit sur cette base, et R.431-36 ajouté aux sources avec
la mention de la date de mise à jour.

Détail complet dans `docs/VERIFICATION_REGLEMENTAIRE_GUIDES_03-07.md`, § 0.

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
