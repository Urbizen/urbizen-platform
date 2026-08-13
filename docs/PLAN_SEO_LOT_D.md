# Lot D — polices et performance

**Audit, relevé du 13 août 2026, après les lots A, B et C.**
Aucune modification n'a été faite. Le lot E n'est pas abordé.

Mesures dans un Chromium réel, mobile 390 × 844, DPR 2, page parcourue de haut
en bas pour déclencher tous les rendus. La question posée n'est pas « quelles
polices sont déclarées » — il y en a 27 — mais **lesquelles le navigateur
télécharge réellement** : une déclaration jamais employée ne coûte rien.

---

## 1 · Fichiers de polices chargés, par page

Le site se partage en **deux familles de pages** qui ne chargent pas du tout les
mêmes fichiers.

### Pages sous gabarit Urbizen — 4 fichiers, 443,2 Ko

Identique sur l'accueil, la déclaration préalable, le permis de construire, la
conception et les tarifs. Mesuré cinq fois, cinq fois le même résultat.

| Fichier | Poids | Origine |
|---|---:|---|
| `OpenSans-Variable.ttf` | **331,8 Ko** | thème parent Hostinger |
| `IBMPlexMono-Regular.ttf` | **49,8 Ko** | thème parent Hostinger |
| `ibm-plex-sans-latin.woff2` | 39,6 Ko | thème enfant Urbizen |
| `space-grotesk-latin.woff2` | 22,1 Ko | thème enfant Urbizen |

### Pages héritées — 2 fichiers, 397,5 Ko

`/contact/`, `/autres-projets/`, `/commander-un-dossier/`.

| Fichier | Poids | Origine |
|---|---:|---|
| `OpenSans-Variable.ttf` | **331,8 Ko** | thème parent Hostinger |
| `Poppins-Regular.ttf` | **65,7 Ko** | thème parent Hostinger |

Ces pages ne chargent **aucune** police de la charte Urbizen : elles ne sont pas
rendues par ses gabarits.

---

## 2 · Origine de chaque chargement

**Les quatre fichiers du thème parent viennent tous de `theme.json`.** Le thème
Hostinger y déclare **25 familles**, dont Open Sans, IBM Plex Mono et Poppins.
WordPress en émet les `@font-face` dans une balise `<style>` en ligne — 27
règles, 5 693 octets — que le navigateur n'honore qu'au moment où un caractère a
besoin de la fonte.

**Les deux fichiers du thème enfant** viennent de
`assets/css/urbizen-fonts.css`, qui déclare Space Grotesk, IBM Plex Sans et IBM
Plex Mono en `.woff2` sous-découpés par `unicode-range` (latin et latin-ext
séparés), avec `font-display: swap`.

### Ce qui applique Open Sans

Les styles globaux résolus posent, sur le corps du document :

```css
body { font-family: Open Sans, sans-serif; … }
```

Ce n'est **pas** un réglage de l'éditeur de site : le post de styles utilisateur
de `urbizen-child` est vide (`{"version": 3, "isGlobalStylesUserThemeJSON": true}`).
La valeur vient de la chaîne `theme.json` résolue, à l'origine « theme ».

### Ce qui applique Poppins

Le `theme.json` **du thème enfant** contient :

```json
"elements": { "heading": { "typography": { "fontFamily": "Poppins, sans-serif" } } }
```

Sur les pages Urbizen, les feuilles de la charte imposent Space Grotesk aux
titres et Poppins n'est jamais peinte, donc jamais téléchargée. Sur les pages
héritées, rien ne l'écrase : **65,7 Ko partent pour styler leurs titres**.

---

## 3 · Polices réellement appliquées, d'après les styles calculés

Relevé sur tous les éléments visibles, page par page.

### Pages Urbizen

| Famille | Éléments concernés | Rôle |
|---|---:|---|
| IBM Plex Sans | 140 à 269 | texte courant, libellés, bandeau de consentement |
| Space Grotesk | 40 à 94 | titres, boutons, bandeau de consentement |
| IBM Plex Mono | 36 à 74 | sur-titres, fils d'ariane, références techniques |
| **Open Sans** | **2** | `a.skip-link` et `div.wp-site-blocks` |

**331,8 Ko pour deux éléments** — dont l'un, le lien d'évitement, n'est visible
qu'au clavier, et l'autre est le conteneur de blocs, qui ne porte aucun texte
propre.

### Pages héritées

Sur `/contact/`, **plus de cent éléments** sont en Open Sans : tout le corps de
la page, y compris les libellés et le cadre du formulaire Fluent Forms. Ce n'est
pas un résidu, c'est la police de ces pages.

---

## 4 · Préchargements

**Aucun.** Ni `preload`, ni `prefetch`, sur aucune des cinq pages. Les polices
sont découvertes à la construction du rendu.

Ce n'est pas un défaut à corriger tel quel : précharger 331,8 Ko d'une police
qui doit disparaître serait exactement le contraire du but. La question du
`preload` se reposera une fois le poids ramené à l'utile.

---

## 5 · Le doublon IBM Plex Mono, expliqué

Le thème enfant et le thème parent déclarent **la même famille**, `IBM Plex
Mono`, à la **même graisse**, 400. Quand deux règles `@font-face` se disputent
une famille pour un même caractère, **la dernière déclarée l'emporte**. Les
positions dans le document servi le disent :

| Déclaration | Position | Fichier |
|---|---:|---|
| thème enfant, `urbizen-fonts.css` | 33 961 | `ibm-plex-mono-latin.woff2` — **9,8 Ko** |
| thème parent, `<style>` en ligne | 42 354 | `IBMPlexMono-Regular.ttf` — **49,8 Ko** |

La déclaration du parent arrive **8 393 octets plus loin** dans le `<head>`.
C'est elle qui gagne. Le `.woff2` de 9,8 Ko du thème enfant est déclaré, chargé
dans la feuille, et **jamais téléchargé** : `document.fonts` le rapporte
`unloaded` sur les cinq pages.

Le site paie donc **40 Ko de plus que nécessaire**, pour la même police, dans un
format non compressé.

---

## 6 · Qui dépend d'Open Sans

Contrôle demandé, et il change la stratégie.

| Composant | Dépend d'Open Sans ? | Vérification |
|---|---|---|
| Bandeau Complianz | **non** | texte en IBM Plex Sans, boutons en Space Grotesk — la feuille du lot « consentement » nomme les familles explicitement |
| Fluent Forms sur les pages Urbizen | **non** | libellés en IBM Plex Sans, champs en `-apple-system` |
| Fluent Forms sur `/contact/` | **oui** | libellés, groupes et conteneurs tous en Open Sans |
| Pages héritées `/contact/`, `/autres-projets/`, `/commander-un-dossier/` | **oui** | plus de cent éléments chacune |
| Lien d'évitement, conteneur de blocs | oui, formellement | 2 éléments, aucun texte propre |

**Conclusion : Open Sans n'est inutile que sur les pages Urbizen.** Sur les trois
pages héritées, c'est la police du contenu. Une suppression globale ne serait pas
un allègement mais un changement d'apparence sur ces pages.

---

## 7 · Stratégies possibles

### A · Neutraliser Open Sans sur les seules pages Urbizen — recommandée

Poser, dans la feuille de la charte, une famille explicite sur le corps et le
conteneur de blocs des gabarits Urbizen, de sorte qu'aucun élément n'y résolve
plus vers Open Sans. La police n'étant plus peinte, elle n'est plus téléchargée.

- **Gain** : 331,8 Ko sur les cinq pages commerciales et les trois pages de
  formulaire.
- **Risque** : nul sur les pages héritées, qui ne sont pas visées.
- **Réversibilité** : une règle CSS, retirée en une ligne.
- **Ce que cela ne fait pas** : les pages héritées continuent de charger 397,5 Ko.

### B · Retirer les familles inutilisées de la chaîne `theme.json`

Filtrer les données `theme.json` du parent pour n'y garder que les familles
réellement employées, ce qui supprimerait 25 déclarations `@font-face` de la
balise en ligne. Le doublon IBM Plex Mono disparaîtrait de lui-même, et le
`.woff2` de l'enfant reprendrait la main.

- **Gain** : 331,8 + 40 Ko sur les pages Urbizen, et 397,5 Ko sur les pages
  héritées — qui perdraient alors leur police.
- **Risque** : **réel**. Les pages héritées et leur formulaire changeraient
  d'apparence sans avoir été retravaillées.
- À réserver au moment où `/contact/` sera repris.

### C · Renommer la famille mono du thème enfant

Donner à la déclaration de l'enfant un nom propre — `IBM Plex Mono Urbizen` —
pour qu'elle cesse d'être en concurrence avec celle du parent.

- **Gain** : 40 Ko sur les pages Urbizen.
- **Risque** : faible, mais le nom doit être repris dans `urbizen-tokens.css` et
  partout où le jeton `--u-font-mono` est employé.
- **Alternative plus propre** : combinée à A, la stratégie B rendrait ce
  renommage inutile.

### Ce que je propose

**A pour Open Sans, C pour le doublon mono**, dans le même lot. Les deux sont des
règles de feuille de style, réversibles, sans effet sur les pages héritées ni sur
aucun composant tiers. B reste la bonne solution de fond, mais elle appartient au
chantier de reprise de `/contact/`, pas à un lot de performance.

---

## 8 · Gain estimé

### Pages Urbizen — les cinq pages commerciales et les trois formulaires

| | Avant | Après | Écart |
|---|---:|---:|---:|
| Fichiers de polices | 4 | **3** | −1 requête |
| Poids des polices | 443,2 Ko | **71,5 Ko** | **−371,7 Ko** |

Détail de l'après : `ibm-plex-sans-latin.woff2` 39,6 Ko + `space-grotesk-latin.woff2`
22,1 Ko + `ibm-plex-mono-latin.woff2` 9,8 Ko.

**Soit une réduction de 84 % du poids des polices.**

### Effet sur le poids total de la page

| Page | Poids total avant | Après, estimé | Part gagnée |
|---|---:|---:|---:|
| `/tarifs/` | 820 Ko | ~448 Ko | **−45 %** |
| `/declarations-prealables/` | 820 Ko | ~448 Ko | −45 % |
| `/permis-de-construire/` | 820 Ko | ~448 Ko | −45 % |
| `/conception/` | 1 264 Ko | ~892 Ko | −29 % |
| `/` | 1 453 Ko | ~1 081 Ko | −26 % |

### Pages héritées — inchangées

`/contact/`, `/autres-projets/` et `/commander-un-dossier/` continueraient de
charger leurs 397,5 Ko. Elles sont hors index sauf `/contact/`, et leur reprise
est un chantier distinct.

### Ce que ce gain ne produira pas

Les Core Web Vitals sont **déjà tenus** : LCP de 404 à 816 ms, CLS de 0 à 0,028,
mesures de laboratoire sur connexion rapide. Les polices sont en
`font-display: swap` et ne bloquent donc pas le premier rendu.

Le gain est réel sur le **volume transféré**, sensible sur une connexion mobile
lente ou un forfait limité, mais il ne fera pas passer un indicateur du rouge au
vert : aucun n'est rouge. Il faut l'attendre sur la sobriété, pas sur le score.

---

## 9 · Ce que cet audit n'a pas mesuré

- Aucune donnée de terrain. Pas de CrUX, le trafic est trop faible : tout ce qui
  précède est du laboratoire.
- Le poids des polices **dans l'iframe** des formulaires DP et PC n'a été relevé
  que globalement ; le document embarqué charge ses propres fichiers.
- Les autres postes de performance — 15 feuilles de style bloquantes, jQuery en
  tête de document, images servies 2 à 3,7 fois trop grandes — sont hors de ce
  lot, qui ne traite que les polices.
