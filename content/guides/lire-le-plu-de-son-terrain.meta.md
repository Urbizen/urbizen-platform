# Guide 5 — métadonnées de publication

Accompagne `lire-le-plu-de-son-terrain.html`.

| Champ | Valeur |
|---|---|
| **Titre** (H1) | PLU : ce que vous pouvez vraiment construire sur votre terrain |
| **Slug** | `lire-le-plu-de-son-terrain` |
| **URL** | `https://urbizen.fr/guides/lire-le-plu-de-son-terrain/` |
| **Catégorie** | Règles d'urbanisme |
| **Image mise en avant** | `assets/images/blog/plu-terrain.webp` (déjà au dépôt, carte 04 de l'accueil) |

## Pourquoi ce slug

`lire-le-plu-de-son-terrain` porte le **geste** que l'article enseigne, et non
la promesse du H1. C'est ce que le lecteur cherche à faire — « comment lire un
PLU », « trouver la zone de son terrain » — et c'est durable : le PLU restera le
PLU, tandis qu'une formulation comme « ce que vous pouvez construire » aurait
donné une URL longue et vague. Le H1 conserve la promesse de la carte 04, à
l'identique.

## Intention de recherche

**Informationnelle, exploratoire.** Le lecteur veut savoir où regarder et
comment lire ce qu'il trouvera. C'est l'intention la plus large des cinq guides,
et la plus en amont.

Requêtes secondaires visées : *comment lire un PLU · trouver la zone de son
terrain · zone U AU A N urbanisme · règlement de zone emprise au sol hauteur ·
géoportail de l'urbanisme parcelle · servitudes annexes PLU · terrain
constructible ou non*.

## AIOSEO

**Title** — 55 caractères
```
Lire le PLU de son terrain : le guide pratique | Urbizen
```

**Meta description** — 154 caractères
```
Retrouver la zone d'une parcelle, ouvrir le bon chapitre du règlement, lire emprise, hauteur, reculs et servitudes — et distinguer faisabilité et autorisation.
```

`og_*` et `twitter_*` laissés NULL.

## Extrait (post_excerpt)

```
Un PLU tient en cinq pièces, dont deux seulement s'imposent à votre projet.
Retrouver sa zone, ouvrir le bon chapitre, lire les six familles de règles, et
savoir ce que les annexes cachent de décisif.
```

## Ce que l'article contient

| | |
|---|---:|
| Mots (hors légendes, alt et sources) | **2 325** |
| H2 | 7 |
| H3 | 9 |
| Schéma dédié | 1 |
| Tableaux | 3 |
| Liens internes | 6 |
| Références au code, liées | 13 |

## L'apport central : conformité ≠ compatibilité

C'est l'angle qui distingue ce guide de tout ce qui s'écrit sur le sujet.
L'article L.152-1 impose la **conformité** au règlement et à ses documents
graphiques, et la **compatibilité** avec les orientations d'aménagement et de
programmation. Il précise en outre que **seuls** la partie écrite et les
documents graphiques du règlement sont opposables au titre de la conformité.

Conséquence, énoncée explicitement dans l'article : le rapport de présentation
et le PADD **ne fondent pas un refus**. Un lecteur à qui l'on oppose une phrase
du rapport de présentation sait désormais quoi répondre.

## L'exemple fictif

Commune de « Villeneuve-sur-Exemple », parcelle `AB 214`, zone UC, six valeurs
réglementaires inventées. **L'avertissement est posé en bloc `guide-hypotheses`
avant l'exemple**, en gras, et répété dans la légende du tableau — pas seulement
en note de bas de page.

L'exemple est construit pour que la règle qui bloque ne soit **pas** celle qu'on
attendait : l'emprise passe largement, et ce sont la pente de toiture et les
espaces verts qui redessinent le projet. C'est la leçon de la section.

## Liens internes posés

| Cible | Emplacement |
|---|---|
| `/guides/dp-ou-permis-de-construire/` | « Les quatre familles de zones », sur le fait que zone AU ≠ zone U |
| `/guides/extension-maison-verifications-avant-plans/` | « Une fois le règlement lu » |
| `/guides/erreurs-dossier-urbanisme/` | même section |
| `/conception/` | même section |
| `/declarations-prealables/` | même section |
| `/permis-de-construire/` | même section |

Aucun lien vers `/tarifs/` dans le corps. Le CTA de fin d'article, servi par la
catégorie « Règles d'urbanisme », mène à `/conception/` — ce qui est cohérent :
un lecteur qui vient de découvrir la complexité de son règlement cherche
quelqu'un pour vérifier avant de dessiner.

## Schéma

`assets/images/guides/schema-lecture-plu.svg` — coupe d'une parcelle fictive,
de la voie publique à la limite séparative, avec les six familles de règles
numérotées : recul, emprise au sol, hauteur, aspect extérieur, retrait, espaces
verts. Un bandeau rappelle qu'aucune commune ne les emploie de la même façon, et
que le point depuis lequel se mesure la hauteur est fixé par le règlement
lui-même.

## Faisabilité réglementaire ≠ autorisation administrative

Un tableau de quatre lignes y est consacré, en fin d'article, comme demandé. Il
dit qui établit quoi, sur quel fondement, et ce que cela vaut. La phrase qui le
suit est la plus importante du guide : **personne ne peut promettre une
autorisation avant qu'elle soit rendue.**

## Vérification réglementaire

`docs/VERIFICATION_REGLEMENTAIRE_GUIDES_03-07.md`, section 3.

Note sur les liens : `atlas.patrimoines.culture.fr` ne répond **qu'en HTTP**
(vérifié le 15/08/2026). L'article ne pose donc aucun lien cliquable vers ce
domaine — il nomme l'Atlas et renvoie à la page de présentation du ministère,
servie en HTTPS, ainsi qu'à la Plateforme ouverte du patrimoine.

## Ce qui n'est pas dans l'article, volontairement

- Aucun montant de prestation, aucune promesse d'obtention.
- Aucune commune réelle, aucune parcelle réelle, aucune valeur réglementaire
  réelle dans l'exemple.
- Aucune affirmation du type « votre terrain est constructible » : l'article
  explique comment le lecteur établit lui-même sa faisabilité.
