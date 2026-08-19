# Lot GUIDES — livrable de revue (2ᵉ passage)

**18 août 2026** · branche `fix/forms-validation-ux` · base `45dedb94b850443a10f1a1fd885d40f839e1a4a9`

Rien n'est commité, rien n'est poussé, rien n'est déployé, aucune PR.
`git diff --check` ne signale rien.

Ce passage reprend les cinq points d'ajustement demandés après la première
revue : le CTA, les largeurs, les accroches, le tarif du guide secteur protégé
et les gardes de tests.

---

## 1 · Les deux largeurs

| jeton | valeur | largeur physique mesurée |
|---|---|---|
| `--u-guide-col` — colonne de lecture | **37,5rem** | **600 px** |
| `--u-guide-large` — largeur éditoriale | **65rem** | **1040 px** (754 px à 834 px de fenêtre, 354 px à 390 px) |

### D'où vient 37,5rem

D'une mesure, pas d'un raisonnement. Le corps de trois guides a été rendu dans
Chrome avec IBM Plex Sans réellement chargée, et le nombre de signes de chaque
ligne relevé **caractère par caractère** (déplacement d'un `Range`, saut de
ligne détecté au changement de `top`), lignes finales de paragraphe exclues :

| colonne | largeur | médiane | quartiles |
|---|---:|---:|---:|
| 36rem | 576 px | 74 | 71 – 78 |
| 37rem | 592 px | 77 | 73 – 79 |
| **37,5rem** | **600 px** | **77** | **74 – 80** |
| 38rem | 608 px | 79 | 75 – 82 |
| 39rem | 624 px | 81 | 78 – 84 |
| 41rem | 656 px | 85 | 81 – 88 |
| 42rem | 672 px | 87 | 83 – 90 |
| 46rem *(1ᵉʳ passage)* | 736 px | 96 | 93 – 100 |

37,5rem est la seule largeur dont l'intervalle interquartile tienne
**entièrement** dans les 70 à 80 signes visés.

**Un point à signaler** : vous attendiez 660 à 680 px. La mesure y trouve 84 à
87 signes, au-dessus de la cible. J'ai suivi la cible en signes, qui était le
critère explicite, et non l'intervalle en pixels. Si vous préférez l'inverse,
c'est une valeur à changer — et le banc borne la plage à 34–40rem pour qu'un
retour à 46rem ne repasse pas inaperçu.

### La répartition

**A · ce qui se lit, mêmes axes gauche et droite** — chapô du hero, titre,
corps de l'article, encadrés textuels, sources, appel à l'action, retour à
l'index.

**B · ce qui se regarde, plus large et centré** — visuel d'en-tête, planches et
schémas, tableaux de seuils, grille « À lire aussi ».

L'architecture est celle du `layout: constrained` de Gutenberg : `.guide-corps`
prend B et devient le **cadre**, chaque enfant du contenu prend A **par
défaut**, et seuls les objets nommés ressortent en B. Aucune marge négative,
donc aucun risque de débordement à une largeur intermédiaire — et un bloc
Gutenberg ajouté demain sera lisible sans que personne y pense.

**Écart assumé par rapport à votre liste** : j'ai mis les **tableaux** dans B.
Un tableau de seuils est un objet technique, pas du texte courant ; à 600 px
ceux de ces guides passaient en défilement horizontal, et un tableau qu'il faut
faire glisser pour lire sa colonne de droite ne se compare plus — or comparer
est sa seule fonction. Un mot et je les repasse en A.

---

## 2 · Le CTA exact final

```
┌──────────────────────────────────────────────┐
│  LE SERVICE URBIZEN                          │  cartouche
│  Besoin d'aide pour votre projet ?           │  h2, commun aux 18
│  <texte de la catégorie>                     │
│  — <point 1>                                 │
│  — <point 2>                                 │  3 points, par catégorie
│  — <point 3>                                 │
│  [ Étudier mon projet ] [ Poser mes questions ]  2 boutons, 44 px
│  Tarifs et délais                            │  lien de texte, 44 px
└──────────────────────────────────────────────┘
```

| rôle | libellé | destination |
|---|---|---|
| bouton principal | **Étudier mon projet** | `/#localisation` |
| bouton secondaire | **Poser mes questions** | `/#demander-des-renseignements` |
| lien de texte | **Tarifs et délais** | `/tarifs/` |

Deux boutons, pas trois. La page de prestation ne figure plus dans le bloc : elle
est liée en contexte dans l'introduction et dans le corps de chaque guide.
`urbizen_child_cta_guide()` ne porte plus aucune URL ni aucun titre — seulement
l'éditorial qui varie.

**Vérifié sur le reste du site, et corrigé** : voir la section 4 bis.

---

## 3 · Les textes « Le service Urbizen », à valider

### Catégorie « Autorisations & projets »

> Piscine, extension, abri, clôture, panneaux solaires : Urbizen prépare le
> dossier complet et vous le remet prêt à déposer.

- Le métré repris sur votre projet réel, emprise au sol et surface de plancher
- Les pièces exigées pour votre cas, et pas celles qui ne le sont pas
- Le dossier complet, prêt à déposer en mairie ou sur le guichet en ligne

### Catégorie « Règles d'urbanisme »

> PLU, emprise au sol, surface de plancher, secteur protégé : Urbizen vérifie
> les règles applicables avant de dessiner quoi que ce soit.

- Le règlement de votre zone lu et rapporté à votre parcelle
- Les surfaces calculées, avec les hypothèses écrites noir sur blanc
- Des plans dessinés à partir de ces règles, plutôt que corrigés après coup

### Catégorie « Conseils & démarches »

> Un dossier bien préparé limite les demandes de pièces complémentaires.
> Urbizen rassemble pour vous les éléments nécessaires à votre projet.

- Chaque pièce relue avant le dépôt, sur la liste qui s'applique à vous
- Les plans et visuels nécessaires au dossier, du plan de masse à l'insertion lorsque ces pièces sont requises
- Un accompagnement à distance, du premier échange à la remise du dossier

### Repli (article sans catégorie connue)

> Urbizen prépare votre dossier d'urbanisme à distance, pièce par pièce, et vous
> le remet prêt à déposer en mairie.

- La formalité applicable vérifiée sur votre projet, avant toute pièce
- Les plans et pièces exigés pour votre cas, dessinés et rassemblés
- Un dossier prêt à déposer, en mairie ou sur le guichet en ligne

**Ce que j'ai déjà retouché pour ne rien affirmer de trop** : « Les pièces DP
utiles à votre cas, **et elles seules** » est devenu « Les pièces exigées pour
votre cas, **et pas celles qui ne le sont pas** » — la première formulation
promettait une exhaustivité que rien ne garantit, la seconde décrit la règle de
la notice officielle.

**Les trois formulations que vous avez fait resserrer** :

1. « Un interlocuteur unique, à distance, du premier échange à la remise » →
   **« Un accompagnement à distance, du premier échange à la remise du
   dossier »**. La première promettait une organisation — une seule personne
   physique sur toutes les étapes — invérifiable et sans rapport avec la valeur
   rendue, qui est la continuité.
2. « Le dossier monté de bout en bout, du plan de masse à l'insertion » →
   **« Les plans et visuels nécessaires au dossier, du plan de masse à
   l'insertion lorsque ces pièces sont requises »**. J'ai retenu la variante
   avec exemples plutôt que « selon les pièces exigées » : le point précédent du
   même cartouche porte déjà « sur la liste qui s'applique à vous », et les deux
   auraient fait doublon.

3. « Un dossier complet du premier coup, c'est un mois gagné » →
   **« Un dossier bien préparé limite les demandes de pièces complémentaires.
   Urbizen rassemble pour vous les éléments nécessaires à votre projet. »** La
   phrase cumulait deux promesses : un dossier réputé complet d'emblée, et un
   gain quantifié. Ni l'une ni l'autre ne dépend d'Urbizen seul — une demande de
   pièces peut venir d'une exigence locale, et le délai d'instruction appartient
   à l'administration.

### Contrôle des promesses sur tout le lot

Les 18 guides et les quatre cartouches ont été passés au crible de dix motifs :
délai garanti, autorisation garantie, dossier accepté, « du premier coup »,
zéro pièce complémentaire, gain chiffré, économie chiffrée, « toujours /
jamais / systématiquement », « garanti », « 100 % ». 35 occurrences relevées,
**toutes examinées, aucune problématique** :

- les « garantie » sont celles de l'**article R.431-36** — aucune autre pièce ne
  peut être exigée — c'est-à-dire un fait réglementaire ;
- les « toujours / jamais » décrivent la règle, et plusieurs **nient** justement
  un absolu : « un délai d'instruction qui ne commence pas toujours au dépôt »,
  « elle n'est jamais générale », « il serait faux d'écrire que le silence vaut
  toujours accord » ;
- `refus-declaration-prealable` écrit noir sur blanc : « Il n'existe pas de
  garantie — et personne ne peut sérieusement en promettre une » ;
- « du premier coup » n'apparaît que dans un tableau **annoncé comme fictif**,
  pour comparer deux calendriers ;
- les « 100 % » sont un niveau de zoom PDF.

**Aucune autre promesse absolue ou chiffrée trouvée.** Un banc interdit désormais
ces formes dans les textes du CTA — et là seulement : appliqué au corps des
guides, il aurait rougi sur du contenu juste, et aurait fini désactivé.

**Le même énoncé universel figurait aussi dans le TEXTE du cartouche**, et pas
seulement dans le point : « Urbizen le monte pour vous, du plan de masse à
l'insertion ». Votre règle le couvrait ; il devient « avec les pièces qu'exige
votre projet ». Corriger le point seul aurait laissé la promesse intacte deux
lignes plus haut.

Aucun des textes ne contient de montant ni de promesse d'obtention ; deux bancs
le vérifient sur le corps de la fonction.

---

## 4 · Les 18 introductions

Condition posée : mention naturelle, spécifique au sujet, idéalement dans les
150 premiers mots. **Tenue partout — le plus tardif est au 95ᵉ mot.**

| slug | première phrase où Urbizen est nommé | position | prestation liée | lien contextuel |
|---|---|---:|---|---|
| `cerfa-declaration-travaux` | Urbizen part toujours de là : la préparation d'une déclaration préalable commence par le formulaire en vigueur et… | 56ᵉ mot | Déclaration préalable | « préparation d'une déclaration préalable » |
| `delais-urbanisme-debut-des-travaux` | C'est celle qu'Urbizen prend en charge — la préparation d'une déclaration préalable vise un dossier complet dès le… | 88ᵉ mot | Déclaration préalable | « préparation d'une déclaration préalable » |
| `demande-pieces-complementaires-urbanisme` | Urbizen monte des dossiers dont l'objet est précisément d'éviter cette lettre : la déclaration préalable est… | 61ᵉ mot | Déclaration préalable | « déclaration préalable » |
| `distance-limite-separative-construction` | Relever ce règlement, puis dessiner un projet qui s'y tient, c'est le travail qu'Urbizen fait en conception de plans… | 78ᵉ mot | Conception de plans | « conception de plans » |
| `dp-ou-permis-de-construire` | C'est la première question qu'Urbizen tranche sur chaque dossier, avant toute pièce : la déclaration préalable et le… | 78ᵉ mot | Déclaration préalable + Permis de construire | « déclaration préalable » + « permis de construire » |
| `emprise-au-sol-surface-de-plancher` | Ces deux calculs ouvrent chaque dossier chez Urbizen : le métré est repris sur le projet réel avant que la… | 62ᵉ mot | Conception de plans | « conception des plans » |
| `erreurs-dossier-urbanisme` | Ces sept points sont ceux qu'Urbizen vérifie avant de remettre un dossier : c'est le dernier geste de la préparation… | 46ᵉ mot | Déclaration préalable | « préparation d'une déclaration préalable » |
| `extension-maison-verifications-avant-plans` | Urbizen fait ces vérifications avant de dessiner : en conception de plans , le règlement de zone, les surfaces et… | 48ᵉ mot | Conception de plans | « conception de plans » |
| `insertion-graphique-dp6` | C'est une pièce qu'Urbizen produit en conception de plans , à partir de vos photos et du projet dessiné : le DP6 se… | 55ᵉ mot | Conception de plans | « conception de plans » |
| `lire-le-plu-de-son-terrain` | Cette lecture est le premier livrable d'Urbizen en conception de plans : les règles applicables à votre parcelle… | 77ᵉ mot | Conception de plans | « conception de plans » |
| `pieces-declaration-prealable` | Établir cette liste pour un projet donné, puis produire les pièces qu'elle contient, c'est l'objet de la préparation… | 95ᵉ mot | Déclaration préalable | « préparation de déclaration préalable » |
| `piscine-garage-carport-autorisation` | Ce métré est le point de départ de chaque dossier chez Urbizen : la préparation d'une déclaration préalable commence… | 81ᵉ mot | Déclaration préalable | « préparation d'une déclaration préalable » |
| `plan-coupe-dp3` | Le DP3 fait partie des planches qu'Urbizen dessine en conception de plans , à partir du terrain existant et du… | 61ᵉ mot | Conception de plans | « conception de plans » |
| `plan-facades-toitures-dp4` | Urbizen dessine ce couple existant/projeté en conception de plans : deux planches comparables, à la même échelle et… | 56ᵉ mot | Conception de plans | « conception de plans » |
| `plan-masse-dp2` | C'est aussi la première planche qu'Urbizen dessine en conception de plans , et celle qui porte le plus de cotes. | 60ᵉ mot | Conception de plans | « conception de plans » |
| `recours-architecte-150-m2` | Ce calcul décide de la suite : sous le seuil, Urbizen peut préparer votre permis de construire ; au-dessus, le… | 40ᵉ mot | Permis de construire | « permis de construire » |
| `refus-declaration-prealable` | Urbizen intervient sur la première — une nouvelle déclaration préalable , reprise sur les motifs invoqués, pièce par… | 65ᵉ mot | Déclaration préalable | « déclaration préalable » |
| `secteur-protege-abf-declaration-travaux` | Cette vérification est la première qu'Urbizen fait sur une adresse, parce qu'elle change à la fois le contenu du… | 72ᵉ mot | Déclaration préalable | « déclaration préalable » |

Les 11 accroches SEO jugées correctes restent mot pour mot ; ce sont les
7 autres, qui annonçaient déjà « ce guide montre… » et faisaient doublon avec le
paragraphe de service, qui ont été réécrites.

---

## 4 bis · Le libellé abandonné sur /tarifs/

J'avais classé cette occurrence « hors lot ». C'était une erreur de méthode :
une occurrence n'est pas hors périmètre parce qu'elle est sur une autre page,
elle l'est si elle n'est pas servie. J'ai donc vérifié.

**Elle est bien servie.** Trois éléments concordent :

1. `page-tarifs` est déclaré dans `customTemplates` de `theme.json` — le gabarit
   est assignable depuis l'administration ;
2. son nom suit aussi la hiérarchie `page-{slug}`, donc WordPress le retient
   d'office pour une page de slug `tarifs` ;
3. les deux occurrences sont du markup rendu, pas des commentaires : le bouton
   principal du hero (ligne 20) et celui du bandeau de fin (ligne 219), tous
   deux vers `/#localisation`.

C'est exactement le cas que vise la règle — un appel à l'action dont la seule
fonction est d'ouvrir le tunnel. **Les deux libellés passent à « Étudier mon
projet ».** Le diff de ce fichier fait deux lignes : aucun prix, aucune
structure, aucun autre contenu n'a bougé.

Le banc `tests/tarifs/test-page-tarifs.php` épinglait le libellé dans l'intitulé
d'un contrôle qui, lui, ne comptait que les destinations — il aurait donc laissé
passer la régression. Il gagne deux gardes : le libellé harmonisé doit être
présent au moins deux fois, et l'ancien nulle part. Vérifiés par mutation.

`tests/tarifs/apercu-tarifs.html` n'a pas été touché : c'est un artefact
régénéré par `apercu-tarifs.php` et ignoré par Git.

### Le troisième bouton, corrigé lui aussi

La même page portait, ligne 104, un **troisième** bouton vers `/#localisation`
dans le bloc « Vous hésitez » : « Faire étudier mon projet ». Même destination,
même fonction, troisième libellé. Il passe à « Étudier mon projet ».

Le diff de `page-tarifs.html` fait **trois lignes**, toutes de même nature.
Aucun prix, aucun texte commercial, aucune structure, aucun lien.

Le banc ne compte plus les occurrences du bon libellé : il extrait **toutes** les
ancres vers `/#localisation` et exige que chacune le porte. Un quatrième bouton
ajouté demain sous un quatrième nom échouerait — ce qu'un comptage n'aurait pas
vu.

Résultat : **toutes** les ancres `/#localisation` du site portent « Étudier mon
projet ». Ni « Démarrer mon projet » ni « Faire étudier mon projet » ne
subsistent dans ce qui est rendu.

### Recherche globale

21 occurrences des deux libellés abandonnés subsistent dans le dépôt, **aucune
servie** : 9 dans les bancs (ce sont les chaînes cherchées), 5 en commentaires
CSS, 1 en commentaire PHP, 3 en documentation, 1 dans `apercu-tarifs.html`
— artefact ignoré par Git, depuis régénéré, qui n'en porte plus aucune — et 2
dans le présent document. Le classement est fait par un script qui distingue
l'artefact (via `git check-ignore`), le commentaire et le markup rendu.

## 5 · Tarif du guide secteur protégé

« un supplément de 80 € s'applique à nos forfaits » est retiré, et le lien
`/tarifs/` du corps avec lui. **Je n'ai pas ajouté la phrase de renvoi que vous
proposiez** : elle n'aurait rien appris que le lecteur ne trouve déjà. Le guide
garde deux chemins — la prestation liée dans le paragraphe même
(`/declarations-prealables/`), et « Tarifs et délais » dans le CTA de fin
d'article. Le paragraphe dit maintenant ce qui est vrai et utile : en périmètre
protégé, le dossier demande des pièces supplémentaires, une insertion qui doit
convaincre, et un délai allongé par l'avis.

---

## 6 · Métadonnées du guide piscine

Seule la métadonnée a bougé : le décompte passe de 3 à 5, et les deux renvois
vers d'autres guides qui manquaient à la liste y ont été ajoutés. **Aucun
contenu n'a été modifié pour faire correspondre le compteur** — les cinq liens
étaient déjà dans l'article avant ce lot.

---

## 7 · Tests

Gardes demandées, toutes en place :

| garde | où |
|---|---|
| aucun « Démarrer mon projet » rendu | pattern, 3 gabarits, 18 corps de guide |
| libellé harmonisé sur /tarifs/ | toutes les ancres `/#localisation`, pas un comptage |
| « insertion » jamais inconditionnelle | corps de `urbizen_child_cta_guide()` |
| aucune promesse chiffrée ni absolue | idem, dix motifs |
| aucune promesse d'organisation | idem |
| « Étudier mon projet » → `/#localisation` | pattern + appel du pattern par `single.html` |
| « Poser mes questions » → `/#demander-des-renseignements` | idem |
| « Tarifs et délais » → `/tarifs/` | idem |
| deux boutons maximum | comptage dans le bloc `<aside class="guide-cta">` |
| colonne de lecture commune | 5 blocs A + le défaut sur tout enfant du contenu |
| planches et tableaux autorisés à la largeur large | sélecteur nommé + centrage |
| signal service précoce et lien contextualisé | 18/18, contrôle structurel |

**Pourquoi le CTA est vérifié sur le pattern et non sur 18 fichiers** : le bloc
n'est pas recopié dans les guides, il est rendu une fois par `guide-pied.php`,
appelé par `single.html`, gabarit de tout article. Vérifier le pattern **et son
appel** prouve les dix-huit d'un coup, et le prouve encore pour le dix-neuvième.
Le banc contrôle explicitement que `single.html` appelle bien le pattern.

Aucun contrôle ne porte sur un nombre exact de mots. La borne de la colonne est
une plage (34–40rem), pas une égalité.

### Suites exécutées

| suites | verdict | durée |
|---|---|---|
| `guides` · `seo` · `seo-projets` · `homepage` · `tarifs` | **5/5 vertes** | 15 min 48 s |

Ce tour a été lancé **après** les trois ajustements finaux.

Un avertissement subsiste, inchangé et attendu : le schéma d'un futur article se
contrôle sur le serveur (`wp eval-file tests/seo/test-seo-lot-e-article.php`).
Le banc le signale en jaune plutôt que de le compter en succès.

Quinze mutations ont été passées pour vérifier que ces gardes savent rougir :
libellé abandonné réintroduit, troisième bouton, colonne remise à 46rem, jeton
réécrit en `ch`, largeur en dur, planches privées de la largeur large, prix de
prestation réintroduit, lien d'introduction retiré, phrase de service dupliquée,
libellé abandonné remis sur `/tarifs/`, quatrième libellé inventé, insertion
redevenue inconditionnelle, interlocuteur unique réintroduit, promesse chiffrée
rétablie, colonne hors plage. Chacune fait échouer le contrôle attendu, et lui
seul.

---

## 8 · Recette responsive

`docs/RECETTE_GUIDES_20260818.md`.

| | 1440 | 1280 | 834 | 390 |
|---|---|---|---|---|
| colonne texte | 600 px | 600 px | 600 px | 354 px |
| signes par ligne (médiane) | 77 | 77 | 77 | 44 |
| signes par ligne (quartiles) | 74–80 | 74–80 | 74–80 | 42–46 |
| planche technique | 1040 px | 1040 px | 754 px | 354 px |
| appel à l'action | 600 px | 600 px | 600 px | 354 px |
| bords texte = bords CTA | alignés | alignés | alignés | alignés |
| débordement horizontal | non | non | non | non |
| recouvrement entre blocs | non | non | non | non |
| bouton ou lien sous 44 px | aucun | aucun | aucun | aucun |

À 390 px, schéma et tableau sont plus larges que la fenêtre **dans leur propre
conteneur à défilement** : c'est voulu et documenté, le document ne défile pas.

---

## 9 · État du dépôt

```
$ git diff --check
(rien)

$ git diff --stat
 32 files changed, 933 insertions(+), 81 deletions(-)

$ git status --short
 M content/guides/cerfa-declaration-travaux.html
 M content/guides/delais-urbanisme-debut-des-travaux.html
 M content/guides/delais-urbanisme-debut-des-travaux.meta.md
 M content/guides/demande-pieces-complementaires-urbanisme.html
 M content/guides/distance-limite-separative-construction.html
 M content/guides/dp-ou-permis-de-construire.html
 M content/guides/dp-ou-permis-de-construire.meta.md
 M content/guides/emprise-au-sol-surface-de-plancher.html
 M content/guides/erreurs-dossier-urbanisme.html
 M content/guides/erreurs-dossier-urbanisme.meta.md
 M content/guides/extension-maison-verifications-avant-plans.html
 M content/guides/extension-maison-verifications-avant-plans.meta.md
 M content/guides/insertion-graphique-dp6.html
 M content/guides/lire-le-plu-de-son-terrain.html
 M content/guides/lire-le-plu-de-son-terrain.meta.md
 M content/guides/pieces-declaration-prealable.html
 M content/guides/piscine-garage-carport-autorisation.html
 M content/guides/piscine-garage-carport-autorisation.meta.md
 M content/guides/plan-coupe-dp3.html
 M content/guides/plan-facades-toitures-dp4.html
 M content/guides/plan-masse-dp2.html
 M content/guides/recours-architecte-150-m2.html
 M content/guides/refus-declaration-prealable.html
 M content/guides/secteur-protege-abf-declaration-travaux.html
 M tests/guides/test-contenu-guides.php
 M tests/guides/test-guides.php
 M tests/tarifs/test-page-tarifs.php
 M wordpress/urbizen-child/assets/css/urbizen-guides.css
 M wordpress/urbizen-child/functions.php
 M wordpress/urbizen-child/patterns/guide-entete.php
 M wordpress/urbizen-child/patterns/guide-pied.php
 M wordpress/urbizen-child/templates/page-tarifs.html
?? docs/RECETTE_GUIDES_20260818.md
?? docs/REVUE_LOT_GUIDES_20260818.md
```

| repère | valeur |
|---|---|
| HEAD | `45dedb94b850443a10f1a1fd885d40f839e1a4a9` — inchangé |
| branche | `fix/forms-validation-ux` |
| `main` | `834bf840…`, identique à `origin/main` |
| commits ajoutés | **0** |

Deux fichiers nouveaux : `docs/RECETTE_GUIDES_20260818.md` et le présent
document.

## Ce qui reste à faire, dans l'ordre convenu

1. votre validation ;
2. commit + push ;
3. sweep global 18/18 sur l'état versionné et propre.
