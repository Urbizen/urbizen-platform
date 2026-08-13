# Lot F — images

**Audit, relevé du 14 août 2026, après les lots A à E.**
Aucune modification n'a été faite.

Chaque page a été chargée deux fois — desktop 1400 × 900 à DPR 1, mobile
390 × 844 à DPR 2 — et parcourue de bout en bout. Les gains annoncés ne sont pas
estimés : les variantes ont été **produites et pesées**.

---

## Le constat en une ligne

**Deux pages portent presque toutes les images du site, et l'une des deux est
déjà exemplaire.**

| Page | Images | Poids | État |
|---|---:|---:|---|
| Accueil | 8 | **563,5 Ko** | aucun `srcset`, aucune dimension déclarée |
| Conception | 9 | 714,4 Ko | `srcset`, `sizes`, dimensions, `fetchpriority` — **rien à corriger** |
| Déclaration préalable | 2 | 10,6 Ko | logo seul |
| Permis de construire | 2 | 10,6 Ko | logo seul |
| Tarifs | 2 | 10,6 Ko | logo seul |

Le lot se joue donc presque entièrement sur l'accueil.

---

## 1 · Le LCP n'est pas une image, sauf sur Conception — et il y est bien traité

| Page | LCP desktop | LCP mobile |
|---|---|---|
| Accueil | `H1`, 656 ms | `DIV`, 604 ms |
| Déclaration préalable | `H1`, 588 ms | `P`, 636 ms |
| Permis de construire | `H1`, 592 ms | `P`, 568 ms |
| Tarifs | `H1`, 576 ms | `DIV`, 672 ms |
| **Conception** | **`IMG`, 560 ms** | `DIV`, 596 ms |

Sur quatre pages sur cinq, le plus grand élément peint est du texte : **aucune
image n'est en cause dans le LCP**, et la question du « héros chargé trop tard »
ne se pose pas.

Sur Conception, le LCP **est** une image — et elle est traitée comme il faut :
`fetchpriority="high"`, aucun `loading="lazy"`, `srcset` et `sizes` renseignés.
**Aucun lazy-loading n'est appliqué à tort au LCP, nulle part.**

---

## 2 · Les six illustrations de l'accueil — le seul gros gain du lot

Six fichiers WebP de 960 × 640, affichés à **335 × 223 en desktop** et
**352 × 234 en mobile**. Aucun `srcset`, aucun `sizes`, aucune dimension
déclarée. Toutes sous la ligne de flottaison, toutes en `loading="lazy"` — ce
qui est correct.

Le besoin réel en pixels est de **335 px de large en desktop** (DPR 1) et
**704 px en mobile** (352 CSS × DPR 2). Le fichier servi en fait 960 : **2,9 fois
trop large en desktop**.

### Gain mesuré, fichier par fichier

Variantes produites à la même qualité perceptuelle et pesées.

| Fichier | Servi | 704 px | 352 px | Gain à 704 px |
|---|---:|---:|---:|---:|
| `extension-maison.webp` | 152 Ko | 66 Ko | 17 Ko | **−86 Ko** |
| `plu-terrain.webp` | 150 Ko | 67 Ko | 19 Ko | **−83 Ko** |
| `piscine-garage-carport.webp` | 117 Ko | 51 Ko | 14 Ko | **−65 Ko** |
| `autorisation-dp-permis.webp` | 47 Ko | 23 Ko | 9 Ko | −24 Ko |
| `erreurs-dossier-urbanisme.webp` | 42 Ko | 18 Ko | 5 Ko | −24 Ko |
| `delais-urbanisme.webp` | 40 Ko | 18 Ko | 6 Ko | −22 Ko |
| **Total** | **551 Ko** | **244 Ko** | **73 Ko** | |

Avec un `srcset` à deux largeurs, un navigateur choisit la variante utile :

| | Aujourd'hui | Avec `srcset` | Gain |
|---|---:|---:|---:|
| Accueil, desktop | 563,5 Ko | **86 Ko** | **−478 Ko, −85 %** |
| Accueil, mobile | 563,5 Ko | **255 Ko** | **−308 Ko, −55 %** |

**Classement : P1.** C'est le seul gain à trois chiffres du lot.

### AVIF, en second temps

À qualité comparable, les mêmes images à 704 px pèsent **177 Ko en AVIF** contre
244 en WebP, soit **67 Ko de plus**. Le gain est réel mais secondaire, et il
suppose un `<picture>` avec repli WebP : plus de balisage pour un quart du gain
déjà obtenu par le redimensionnement.

**Classement : P2**, à faire après le `srcset`, pas à sa place.

---

## 3 · Aucune image de l'accueil n'a de dimensions déclarées

Les huit images de l'accueil sont servies sans `width` ni `height`. Le navigateur
ne connaît donc leur encombrement qu'une fois le fichier reçu.

Le CLS mesuré reste bon — **0,001 en desktop** — parce que la mise en page réserve
la place par le CSS. Le risque n'est pas actuel : il est qu'une modification de
feuille le fasse apparaître sans que personne ne relie la cause à l'effet.

Les images de Conception, elles, portent toutes `width` et `height`.

**Classement : P1** — pas pour le gain, pour l'accessibilité et la robustesse. Le
coût est nul et l'attribut est exigé par toutes les recommandations.

---

## 4 · Six images servies ne sont pas dans le dépôt

`wp-content/themes/urbizen-child/assets/images/blog/` contient six fichiers,
**564 Ko au total**, présents en production et **absents du dépôt Git**.

Le répertoire `assets/images/` versionné ne contient que `conception/`.

Ces fichiers disparaîtraient au premier redéploiement propre du thème, et rien
ne les protège aujourd'hui. Ce n'est pas un défaut de performance : c'est une
lacune de versionnement, découverte en cherchant les originaux pour mesurer.

**Classement : P1.** À corriger avant toute optimisation, faute de quoi le lot
optimiserait des fichiers qui n'existent nulle part.

---

## 5 · Le logo — le réflexe « PNG vers WebP » serait une erreur ici

`logo-urbizen.png`, 430 × 120, **5 145 octets**, affiché 129 × 36 en desktop
(×3,3) et 115 × 32 en mobile (×1,9 à DPR 2).

Le surdimensionnement est réel. Les conversions, mesurées, ne le sont pas moins :

| Variante | Poids | Écart |
|---|---:|---:|
| **PNG 430 px, servi aujourd'hui** | **5 145 o** | — |
| WebP 430 px | 16 718 o | **+225 %** |
| PNG 258 px | 19 256 o | **+274 %** |
| WebP 258 px | 11 832 o | **+130 %** |

Un logo est un aplat de couleurs : la palette indexée du PNG y bat la compression
WebP, et le redimensionnement par `sips` perd la palette au passage. **Toute
« optimisation » du logo l'alourdirait.**

**Classement : P3, sans action.** Cinq kilo-octets pour une image présente sur
toutes les pages, c'est déjà très bon. Le ×3,3 ne coûte rien en octets ; il
coûte un peu de mémoire de décodage, ce qui est négligeable à cette taille.

C'est le genre de correction que l'on applique par réflexe et qui dégrade : elle
méritait d'être mesurée avant d'être proposée.

---

## 6 · Une incohérence de chargement sur la page Tarifs

Le logo d'en-tête porte `loading="lazy"` **sur la page Tarifs seulement** — et il
est au-dessus de la ligne de flottaison. Sur l'accueil, la DP, le PC et
Conception, le même logo n'a aucun attribut `loading`.

Retarder volontairement une image visible d'emblée est contraire au but de
l'attribut. L'effet est minime — 5 Ko — mais l'incohérence signale que
l'attribut a été posé sans intention.

**Classement : P2.** Coût nul.

---

## 7 · Textes alternatifs — rien à corriger

Contrôle des dix-neuf images des cinq pages.

| Attendu | Constat |
|---|---|
| Image informative sans `alt` | **aucune** |
| Image décorative avec `alt` inutile | **aucune** |
| `alt` vide ou générique | **aucun** |

Les libellés sont descriptifs et spécifiques — « Extension contemporaine reliée à
une maison en pierre », « Rendu 3D d'une maison contemporaine aux lignes
japonisantes avec jardin zen et piscine ». Aucun n'est un nom de fichier ni un
mot-clé plaqué.

Le logo porte `alt="Urbizen · urbanisme & projets"` en en-tête et `alt="Urbizen"`
en pied. Les deux conviennent : c'est un lien vers l'accueil, pas une décoration.

**Rien à faire. C'est un point fort du site, comme les ancres internes.**

---

## 8 · Doublons et fichiers inutilisés

Le thème contient **13 images versionnées**, **19 en production** — l'écart étant
les six du point 4.

Les douze images de Conception vont par paires : une variante `-card` pour la
grille, une variante pleine pour l'affichage agrandi. **Les deux sont réellement
employées** ; ce n'est pas un doublon mais un jeu de tailles, ce qui est
exactement ce qu'il faut faire.

**Aucun fichier inutilisé, aucun doublon.**

---

## 9 · Récapitulatif

| Rang | Point | Gain mesuré | Effort |
|---|---|---|---|
| **P1** | Six images sans `srcset` sur l'accueil | **−478 Ko desktop, −308 Ko mobile** | production de 12 variantes + balisage |
| **P1** | Aucune dimension déclarée sur l'accueil | 0 Ko — robustesse et accessibilité | 8 attributs |
| **P1** | Six images absentes du dépôt | 0 Ko — versionnement | ajout au dépôt |
| **P2** | AVIF avec repli WebP | −67 Ko supplémentaires | `<picture>` |
| **P2** | `loading="lazy"` sur le logo d'en-tête de Tarifs | négligeable | 1 attribut |
| **P3** | Logo servi ×3,3 trop grand | **négatif — ne rien faire** | — |

### Ordre proposé

1. **Verser les six images au dépôt.** Rien d'autre ne doit précéder : optimiser
   des fichiers non versionnés reviendrait à travailler sur du sable.
2. **`srcset` et dimensions sur l'accueil**, ensemble : les deux touchent au même
   balisage, dans la maquette et les deux gabarits.
3. **Le `loading` du logo de Tarifs**, au passage.
4. **AVIF**, séparément, s'il est jugé utile.

---

## 10 · Ce que cet audit n'a pas fait

- **Aucune comparaison visuelle des variantes.** Les fichiers redimensionnés ont
  été pesés, pas regardés. Un contrôle à l'œil s'impose avant publication : une
  qualité d'encodage se juge, elle ne se mesure pas.
- **Aucune donnée de terrain.** Le CLS et le LCP cités sont des mesures de
  laboratoire sur connexion rapide.
- **Les pages hors index** — `/contact/`, `/autres-projets/`,
  `/commander-un-dossier/` — n'ont pas été auditées : elles ne portent pas
  d'image propre et leur reprise est un chantier distinct.
