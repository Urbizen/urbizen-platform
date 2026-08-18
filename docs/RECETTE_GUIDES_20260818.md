# Recette du lot GUIDES — 18 août 2026

Branche `fix/forms-validation-ux`, à partir de `45dedb94`. Aucun formulaire
touché, aucune règle réglementaire modifiée, aucun déploiement.

## Ce qui a été mesuré, et comment

Le rendu réel d'un guide demande WordPress, une base et un article publié. Cette
recette ne l'a pas : elle mesure **la mise en page**, sur un banc statique qui
charge les cinq feuilles du thème enfant dans leur ordre de production
(`urbizen-tokens`, `urbizen-fonts`, `urbizen-homepage`, `urbizen-pages`,
`urbizen-guides`) et reproduit la structure de `templates/single.html`. Le corps
employé est celui de `dp-ou-permis-de-construire`, pris tel quel dans
`content/guides/` : il porte à la fois un schéma vectoriel et un tableau de
seuils, c'est-à-dire les deux objets qui sortent de la colonne de lecture.

Trois précautions, sans lesquelles les chiffres seraient faux :

- **Les polices sont chargées.** `ch` — l'unité de l'ancienne colonne — vaut
  l'avance du glyphe « 0 » de la police effectivement rendue. Sans IBM Plex Sans,
  la colonne mesurait 771 px au lieu de 734,4. Le banc « avant » a reçu une copie
  du répertoire `assets/fonts/` : les deux états sont comparés à polices égales.
- **`body` n'a pas de marge.** WordPress la met à zéro par ses styles globaux ;
  un banc nu garde les 8 px du navigateur, qui décalaient tout sous 1200 px.
- **Les largeurs viennent d'un cadre `<iframe>`, pas de la taille de fenêtre.**
  Chrome en mode headless refuse une fenêtre sous 500 px, et une recette à
  390 px mesurée à 500 ne prouve rien.

## Comment la colonne de lecture a été choisie

Pas au jugé. Le corps de trois guides a été rendu avec IBM Plex Sans réellement
chargée, et **le nombre de signes de chaque ligne relevé caractère par
caractère** : un `Range` avance d'un caractère à la fois, et le passage à la
ligne est détecté au changement de `top`. Les lignes finales de paragraphe sont
exclues du calcul — elles sont partielles par construction et tireraient la
moyenne vers le bas.

| colonne | largeur | lignes mesurées | moyenne | médiane | quartiles |
|---|---:|---:|---:|---:|---:|
| 36rem | 576 px | 259 | 73,7 | 74 | 71 – 78 |
| 36,5rem | 584 px | 253 | 74,6 | 75 | 72 – 78 |
| 37rem | 592 px | 248 | 75,8 | 77 | 73 – 79 |
| **37,5rem** | **600 px** | **244** | **76,7** | **77** | **74 – 80** |
| 38rem | 608 px | 241 | 78,1 | 79 | 75 – 82 |
| 39rem | 624 px | 232 | 80,4 | 81 | 78 – 84 |
| 41rem | 656 px | 222 | 84,0 | 85 | 81 – 88 |
| 42rem | 672 px | 218 | 86,3 | 87 | 83 – 90 |
| 46rem | 736 px | 195 | 95,5 | 96 | 93 – 100 |

**37,5rem est la seule largeur dont l'intervalle interquartile tienne
entièrement dans les 70 à 80 signes visés.** L'ordre de grandeur attendu au
départ — 660 à 680 px — donne 84 à 87 signes : la mesure tranche autrement que
l'intuition, et c'est elle qui a décidé.

`46rem` était la valeur du premier passage de ce lot : 96 signes de médiane.

## Les deux largeurs

| jeton | valeur | employé par |
|---|---|---|
| `--u-guide-col` | 37,5rem · 600 px | chapô du hero, titre, corps de l'article, encadrés textuels, sources, appel à l'action, retour à l'index |
| `--u-guide-large` | 65rem · 1040 px | visuel d'en-tête, planches et schémas, tableaux de seuils, grille « À lire aussi » |

## Mesures aux quatre largeurs

Valeurs en pixels, relevées par `getBoundingClientRect()`.

### 1440 px

| élément | gauche | droite | largeur |
|---|---:|---:|---:|
| chapô du hero | 420 | 1020 | **600** |
| titre H1 | 420 | 1020 | **600** |
| visuel d'en-tête | 200 | 1240 | 1040 |
| cadre du corps | 200 | 1240 | 1040 |
| paragraphe de texte | 420 | 1020 | **600** |
| encadré « résultat » | 420 | 1020 | **600** |
| encadré de sources | 420 | 1020 | **600** |
| planche technique | 200 | 1240 | 1040 |
| tableau de seuils | 200 | 1240 | 1040 |
| appel à l'action | 420 | 1020 | **600** |
| grille « À lire aussi » | 200 | 1240 | 1040 |
| retour à l'index | 420 | 1020 | **600** |

### 1280 px

Même figure, décalée : texte, encadrés, sources, CTA et retour à 600 px
(bords 340 / 940) ; visuel, planche, tableau et grille à 1040 px (bords
120 / 1160).

### 834 px

Texte et CTA à 600 px (bords 117 / 717). Les objets larges prennent la bande
disponible, 754 px (bords 40 / 794) : le `.wrap` est alors la contrainte
active, pas le jeton.

### 390 px

Tout tombe à 354 px, texte comme planches : `--u-pad` passe à 18 px sous 420 px
et aucun des deux jetons n'est plus la contrainte.

## Contrôles transversaux

| Contrôle | 1440 | 1280 | 834 | 390 |
|---|---|---|---|---|
| Colonne de lecture | 600 | 600 | 600 | 354 |
| Planche technique | 1040 | 1040 | 754 | 354 |
| Tableau de seuils | 1040 | 1040 | 754 | 354 |
| Appel à l'action | 600 | 600 | 600 | 354 |
| Signes par ligne, médiane (texte courant) | 77 | 77 | 77 | 44 |
| Signes par ligne, quartiles | 74–80 | 74–80 | 74–80 | 42–46 |
| Bords gauche et droite : texte = CTA | alignés | alignés | alignés | alignés |
| Débordement horizontal du document | non | non | non | non |
| Élément plus large que la fenêtre hors conteneur à défilement | aucun | aucun | aucun | aucun |
| Recouvrement entre blocs du pied d'article | non | non | non | non |
| Bouton ou lien du CTA sous 44 px | aucun | aucun | aucun | aucun |

À 390 px, le schéma et le tableau sont **plus larges que la fenêtre à
l'intérieur de leur propre conteneur à défilement** — c'est voulu, et documenté
dans la feuille : un schéma ramené à 354 px porte des libellés de cinq pixels.
Le document, lui, ne défile pas horizontalement.

Le point corrigé en cours de recette : le lien de texte de l'appel à l'action
faisait **20 px de haut de 834 à 1440 px**, à côté de deux boutons de 44. Il
passe en `inline-flex` avec `min-height: 44px`.

## Contre-mesure avec le cartouche le plus long

Les textes du cartouche « Conseils & démarches » ont été resserrés en fin de lot
(« interlocuteur unique » et « du plan de masse à l'insertion »), ce qui allonge
deux de ses trois lignes. Le banc a été rejoué avec ce cartouche-là, le plus
long des quatre, aux quatre largeurs : **aucune valeur ne bouge** — colonne 600,
planche et tableau 1040 (754 à 834 px, 354 à 390 px), CTA 600, axes alignés,
aucun débordement, aucun recouvrement, aucune cible sous 44 px.

C'était le résultat attendu — les largeurs viennent des jetons, pas de la
longueur des textes — mais il valait mieux le mesurer que le supposer.

## Ce que cette recette ne prouve pas

- **Le rendu WordPress.** Les patterns PHP ne sont pas exécutés ; leur sortie a
  été reproduite à la main. Les deux bancs de `tests/guides/` couvrent l'autre
  moitié : ils lisent les sources PHP.
- **Les planches entières.** Le banc emploie un `.guide-visuel` sans la classe
  `--planche` ; les sept guides qui en portent une sont cadrés autrement
  (`object-fit: contain`, rapport 16/9), non revérifiés ici.
- **Le rendu typographique fin.** Césure, veuves et orphelines des nouvelles
  introductions demandent un œil, pas une mesure.
