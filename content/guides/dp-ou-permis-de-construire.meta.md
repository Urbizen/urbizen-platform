# Guide 3 — métadonnées de publication

Accompagne `dp-ou-permis-de-construire.html`, qui contient le corps de l'article
en balisage Gutenberg, prêt à coller dans l'éditeur de code.

| Champ | Valeur |
|---|---|
| **Titre** (H1) | DP ou permis de construire : lequel faut-il pour votre projet ? |
| **Slug** | `dp-ou-permis-de-construire` |
| **URL** | `https://urbizen.fr/guides/dp-ou-permis-de-construire/` |
| **Catégorie** | Autorisations & projets |
| **Image mise en avant** | `assets/images/blog/autorisation-dp-permis.webp` (déjà au dépôt, carte 01 de l'accueil) |

## Pourquoi ce slug

`dp-ou-permis-de-construire` reprend la formulation que le lecteur tape
réellement — « dp ou permis de construire » — sans le verbe interrogatif ni la
date. Court, durable, et sans collision avec `/permis-de-construire/`, la page
commerciale, puisque le préfixe `/guides/` les sépare et que le slug commence
par « dp ».

## Intention de recherche

**Informationnelle, à décision immédiate.** Le lecteur a un projet et hésite
entre deux formalités. Il ne cherche pas à comprendre le droit de l'urbanisme :
il cherche à trancher, et à savoir pourquoi.

Requêtes secondaires visées : *déclaration préalable ou permis de construire ·
quelle autorisation pour mon projet · seuil 20 m² 40 m² · extension déclaration
ou permis · zone U seuil 40 m² · surface totale après travaux 150 m²*.

## AIOSEO

**Title** — 55 caractères
```
DP ou permis de construire : comment trancher | Urbizen
```

Ne commence ni par « déclaration préalable » ni par « permis de construire » :
ces deux formulations sont attribuées aux pages commerciales depuis le lot C.
« DP » est l'abréviation d'usage et ne rentre pas en concurrence avec elles.

**Meta description** — 152 caractères
```
Quatre questions, dans l'ordre, pour savoir si votre projet relève d'une déclaration préalable ou d'un permis — et ce qui fait basculer un cas limite.
```

`og_*` et `twitter_*` laissés NULL : ils héritent du title et de la description.

## Extrait (post_excerpt)

```
Construction nouvelle ou travaux sur l'existant, emprise ou surface de plancher,
zone U ou non, total après travaux : quatre questions qui se répondent dans
l'ordre, et trois cas limites tranchés pas à pas.
```

## Ce que l'article contient

| | |
|---|---:|
| Mots (hors légendes, alt et sources) | **2 841** |
| H2 | 9 |
| H3 | 18 |
| Schéma dédié | 1 |
| Tableaux | 1 |
| Liens internes | 7 |
| Références au code, liées | 13 |

## Liens internes posés

| Cible | Emplacement |
|---|---|
| `/declarations-prealables/` | introduction, 2ᵉ paragraphe — la première question tranchée sur chaque dossier |
| `/permis-de-construire/` | introduction, 2ᵉ paragraphe — l’autre branche de la même question |
| `/guides/piscine-garage-carport-autorisation/` | « La règle du "et" et la règle du "ou" » — renvoi vers le métré détaillé |
| `/guides/lire-le-plu-de-son-terrain/` | Question 3, pour retrouver la zone d'une parcelle |
| `/guides/extension-maison-verifications-avant-plans/` | Question 4, sur le total après travaux |
| `/guides/delais-urbanisme-debut-des-travaux/` | Troisième cas limite, sur la majoration en périmètre protégé |
| `/declarations-prealables/` | « Une fois la formalité identifiée » |
| `/permis-de-construire/` | même section |
| `/conception/` | même section, si les plans restent à produire |

> Les cibles ci-dessus étaient déjà liées ailleurs dans l'article : le décompte
> « Liens internes », qui porte sur les cibles distinctes, ne change pas. Ce que
> le lot GUIDES ajoute est un **emplacement** — l'introduction — et non une cible.


Aucun lien vers `/tarifs/` dans le corps : cette page porte l'intention prix,
isolée au lot C. Le CTA de fin d'article, servi par la catégorie
« Autorisations & projets », mène à `/declarations-prealables/`.

## Schéma

`assets/images/guides/schema-arbre-dp-pc.svg` — arbre de décision à deux
branches. Vectoriel et non bitmap : il porte des libellés de 11 à 15 px qu'un
rééchantillonnage rendrait illisibles. `<title>` et `<desc>` liés par
`aria-labelledby`, et l'article répète l'information complète en `alt`.

Palette Urbizen uniquement : `#14233B`, `#55617A`, `#128A5A`, `#0E6E48`,
`#E4F5EC`, `#C9D3DD`, `#EAEEF2`, `#9FADBC`, `#FBFCFD`. Convention du schéma,
annoncée par sa légende : **gris = aucune formalité**, **vert = déclaration
préalable**, **contour bleu nuit = permis de construire**.

## Ce qui distingue ce guide des pages commerciales

Les pages DP et PC portent le tableau « projet → formalité », et il y reste.
Ce guide ne le reprend pas : son tableau croise les **deux branches de la
première question** avec les seuils, et signale la seule case dont la réponse
dépend d'un chiffre absent de la ligne — le total après travaux. C'est un outil
de décision, pas un récapitulatif.

## Vérification réglementaire

`docs/VERIFICATION_REGLEMENTAIRE_GUIDES_03-07.md`. Points vérifiés
spécifiquement pour cet article : la borne d'égalité à 5 m² (l'égalité
appartient à la dispense), le fait que le relèvement à 40 m² ne vaut **que**
pour les travaux sur l'existant, et que zone AU ≠ zone U.

## Ce qui n'est pas dans l'article, volontairement

- Aucun montant de prestation, aucune promesse d'obtention d'autorisation.
- Aucun auteur affiché.
- Aucune statistique.
- Les trois cas limites sont **fictifs et annoncés comme tels** dans le corps du
  texte, avec leurs hypothèses explicites — un même métré donnant deux
  formalités selon les hypothèses, les taire aurait rendu les exemples faux.
