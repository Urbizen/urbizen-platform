# Lot G — stratégie éditoriale et contenu

**Plan, relevé du 14 août 2026, après les lots A à F.**
Aucun article rédigé, rien publié. Ce document est un plan, pas un contenu.

---

## 1 · État des lieux

| | |
|---|---:|
| Articles publiés | **0** |
| Catégories | 1 — « Non classé », vide, en `noindex` |
| Étiquettes | 0 |
| Page d'articles (`page_for_posts`) | **aucune** — `/blog/` répond 404 |
| Structure d'URL | `/%postname%/` |

**Six guides sont déjà annoncés sur l'accueil**, avec titre, catégorie, numéro et
illustration — et **sans lien**, puisque rien n'existe derrière :

| # | Catégorie affichée | Titre annoncé |
|---|---|---|
| 01 | Autorisations | DP ou permis de construire : lequel faut-il pour votre projet ? |
| 02 | Extension de maison | Extension de maison : 5 vérifications avant de dessiner les plans |
| 03 | Piscine, garage & carport | Piscine, garage, carport : les seuils qui changent votre autorisation |
| 04 | Règles d'urbanisme | PLU : ce que vous pouvez vraiment construire sur votre terrain |
| 05 | Conseils pratiques | Dossier d'urbanisme : 7 erreurs qui peuvent retarder l'accord |
| 06 | Délais & démarches | Délais d'urbanisme : quand pouvez-vous vraiment commencer les travaux ? |

Ce ne sont pas des idées : ce sont des **promesses publiées**. Le plan part de là.

Deux autres traces d'intention éditoriale antérieure : les liens morts vers
`/panneaux-solaires/` et `/comment-ca-marche/`, relevés au lot B, et la page
`/autres-projets/` — désormais en `noindex` — qui traite clôtures, abris de
jardin et panneaux solaires.

---

## 2 · Le risque central : les piliers couvrent déjà la liste des sujets

C'est le constat qui commande tout le lot, et il va à l'encontre de l'intuition.

Les deux pages piliers ne sont pas des pages minces à compléter : elles
**traitent déjà**, brièvement, presque tous les sujets de la liste de priorités.

### Ce que `/declarations-prealables/` couvre déjà — 1 724 mots

| Section | Mots | Sujet de la liste déjà touché |
|---|---:|---|
| Quel projet relève d'une déclaration préalable ? | 282 | piscine, extension, abri, clôture, panneaux solaires |
| C'est quoi, une déclaration préalable ? | 199 | — |
| Ce qui se passe après le dépôt | 167 | **délais**, affichage, recours |
| Les conditions qui peuvent changer votre dossier | 153 | **ABF**, taxe, PLU, copropriété |
| Les erreurs qui font rejeter un dossier | 127 | **refus** |
| Les pièces d'une déclaration préalable | 94 | **pièces DP** |
| Des forfaits clairs | 173 | clôture, piscine, garage, abri, extension |
| Vos questions | 226 | refus, délais, affichage, surfaces |

### Ce que `/permis-de-construire/` couvre déjà — 1 918 mots

| Section | Mots | Sujet de la liste déjà touché |
|---|---:|---|
| Quand faut-il un permis de construire ? | 262 | seuils |
| Surface de plancher, emprise au sol | 169 | seuils |
| L'architecte et le seuil des 150 m² | 96 | — |
| Les étapes jusqu'au chantier | 214 | **délais** — 2 à 3 mois |
| Les conditions qui peuvent modifier votre dossier | 167 | **ABF**, RE2020, PLU, taxe |
| Les incohérences qui retardent un permis | 154 | **refus** |
| Les pièces d'un permis de construire | 101 | **pièces** |

### Ce que cela implique

Sur les douze sujets prioritaires, **cinq sont déjà traités sur les piliers** :
ABF, délais, pièces, refus, et la comparaison DP / PC. Et **vingt-deux questions
sont déjà rédigées** en FAQ sur les quatre pages commerciales — dont
« Permis de construire ou déclaration préalable ? », qui est exactement le sujet
du guide 01 annoncé sur l'accueil.

Publier un article sur « les délais d'urbanisme » sans précaution, c'est mettre
en concurrence un article neuf, sans autorité, avec une page pilier de 1 918 mots
qui vend une prestation. **Celle qui perdrait est celle qui rapporte.**

La règle du lot en découle : **un satellite ne redit pas une section du pilier,
il descend d'un cran là où le pilier ne peut pas aller** — un type de projet
précis, un seuil chiffré, un cas particulier. Le pilier garde la requête
générique ; le satellite prend la longue traîne et renvoie au pilier.

---

## 3 · Quatre décisions d'architecture, avant la première ligne

Elles ne se rattrapent pas après publication sans migration d'URL — et la
politique du projet est de ne pas créer de redirection.

### 3.1 · Où vivent les articles ?

Avec `/%postname%/`, un article publié aujourd'hui serait à
`https://urbizen.fr/delais-urbanisme/` — **dans le même espace de noms que les
pages**. Trois options :

| Option | Effet |
|---|---|
| **Racine**, tel quel | URL courtes ; risque de collision de slug avec une future page ; aucun signal de rubrique |
| **`/guides/`** comme base | rubrique lisible, pas de collision, et le mot « guide » correspond à ce que l'accueil annonce |
| `/blog/` | convention répandue, mais « blog » vend moins bien qu'« guide » pour du contenu d'expertise |

**Je recommande `/guides/`**, cohérent avec « Prochains guides Urbizen » déjà
écrit sur l'accueil. À trancher avant le premier article.

### 3.2 · Une page d'index est nécessaire

`page_for_posts` vaut 0 : il n'existe aucune page listant les articles.
Sans elle, les guides n'auront pas de point d'entrée, et la grille de l'accueil
ne pourra renvoyer que vers des articles isolés.

### 3.3 · Les catégories

La seule catégorie est « Non classé ». Les six guides annoncés en désignent déjà
six, en clair, sur l'accueil : *Autorisations*, *Extension de maison*,
*Piscine, garage & carport*, *Règles d'urbanisme*, *Conseils pratiques*,
*Délais & démarches*.

Attention : **une catégorie non vide redevient indexable** — le filtre posé au
lot B ne met en `noindex` que les archives vides. Créer six catégories, c'est
créer six pages d'archive à faible contenu qui entreront dans l'index. Deux ou
trois catégories larges valent mieux que six étroites.

### 3.4 · L'auteur

Le lot E a neutralisé le nœud `Person` par anticipation : le premier article ne
citera pas d'archive d'auteur en 404. **Rien à faire**, mais le banc
`tests/seo/test-seo-lot-e-article.php` doit être relancé après la première
publication réelle, pour vérifier sur un article vrai ce qu'il a vérifié sur un
brouillon.

---

## 4 · Clusters, piliers et satellites

Trois clusters, hiérarchisés selon votre liste de priorités. Chaque satellite
porte une intention que le pilier ne peut pas servir seul.

### Cluster 1 — Déclaration préalable · pilier `/declarations-prealables/`

Le pilier garde « déclaration préalable de travaux ». Les satellites prennent les
types de projet, un par un — c'est là que se joue la longue traîne, et c'est ce
que la page pilier énumère sans pouvoir développer.

| # | Article | Intention | Requêtes visées | Le pilier dit déjà | Le satellite ajoute |
|---|---|---|---|---|---|
| G1 | **Piscine : quelle autorisation selon la taille** | informationnelle, décision | déclaration préalable piscine · piscine 10 m² autorisation · piscine sans déclaration | une ligne dans les forfaits | les trois seuils, le cas de l'abri de piscine, la taxe, le local technique |
| G2 | **Extension de maison : quelle démarche selon la surface créée** | informationnelle, décision | extension 20 m² · extension 40 m² déclaration ou permis · agrandissement autorisation | mentionnée dans les forfaits et les seuils | le passage DP → PC, le seuil des 150 m² total, la zone U du PLU |
| G3 | **Abri de jardin, garage, carport : ce qui change l'autorisation** | informationnelle | abri de jardin 5 m² · garage déclaration préalable · carport autorisation | groupés en une ligne tarifaire | le tableau des seuils par type, la hauteur, l'emprise |
| G4 | **Clôture et mur : quand faut-il déclarer** | informationnelle | déclaration préalable clôture · mur de clôture autorisation · hauteur clôture PLU | une ligne | le rôle du PLU, les cas où la commune impose la déclaration, les hauteurs |
| G5 | **Panneaux solaires : autorisation en toiture et au sol** | informationnelle | panneaux solaires déclaration préalable · panneaux photovoltaïques autorisation | une ligne | toiture contre sol, ABF, monuments, aspect extérieur |

**Ces cinq articles sont le cœur du lot.** Ils correspondent exactement aux
priorités 2 à 6 de votre liste, ils ne concurrencent pas le pilier — ils lui
apportent des visiteurs qualifiés — et ils reprennent les sujets de
`/autres-projets/`, dont le contenu peut servir de matière.

### Cluster 2 — Permis de construire · pilier `/permis-de-construire/`

| # | Article | Intention | Requêtes visées | Précaution |
|---|---|---|---|---|
| G6 | **DP ou permis de construire : comment trancher** | comparative | déclaration préalable ou permis de construire · différence DP permis | **guide 01 annoncé.** Chevauche la FAQ des deux piliers. Doit être un arbre de décision par type de projet, pas une redite des définitions |
| G7 | **Maison individuelle : le dossier de permis, pièce par pièce** | informationnelle | permis de construire maison individuelle · PCMI liste | complète les 101 mots du pilier sur les pièces |
| G8 | **Le seuil des 150 m² et l'architecte** | informationnelle | architecte obligatoire 150 m² · permis sans architecte | le pilier y consacre 96 mots ; l'article doit apporter les exceptions et le cas de l'extension |

### Cluster 3 — Comprendre son dossier · pas de pilier dédié

C'est ici que le risque de cannibalisation est le plus fort : les cinq sujets
sont déjà traités par les deux piliers. Ces articles doivent être **franchement
plus profonds**, sinon ils ne valent pas la peine d'être écrits.

| # | Article | Intention | Le pilier dit déjà | Le satellite doit |
|---|---|---|---|---|
| G9 | **PLU : lire ce que vous pouvez construire** | informationnelle | 153 + 167 mots, en conditions | **guide 04 annoncé.** Expliquer comment lire un règlement de zone — le pilier ne le fait nulle part |
| G10 | **Délais réels : dépôt, instruction, recours, chantier** | informationnelle | 167 + 214 mots, chronologies | **guide 06 annoncé.** Traiter les majorations de délai, le silence de l'administration, la prorogation |
| G11 | **Les pièces DP1, DP2, DP4, DP6 expliquées** | informationnelle | 94 mots, liste | Dire à quoi sert chaque pièce et ce qui la fait refuser. **Risque élevé** : à n'écrire qu'après G1–G5 |
| G12 | **Refus, sursis, modification : que faire ensuite** | informationnelle, problème | « erreurs qui font rejeter », 127 mots | Le recours gracieux, les délais, le dossier modificatif |
| G13 | **Secteur ABF : ce que cela change** | informationnelle | quatre lignes dans les conditions | Le périmètre, l'avis, les matériaux, le délai supplémentaire |
| G14 | **Pièces complémentaires : quand la mairie en demande** | informationnelle, problème | rien, sinon la complétude | La demande de pièces, l'effet sur le délai, ce qu'il ne faut pas envoyer |

**Le guide 05 annoncé — « 7 erreurs qui retardent l'accord » — n'a pas de ligne
propre** : il recouvre G11, G12 et G14. Je propose de l'écrire **en dernier**,
comme une synthèse qui renvoie aux trois, plutôt qu'en premier comme une liste
qui les videra de leur substance.

---

## 5 · Cannibalisation : la carte des risques

| Sujet | Concurrence avec | Niveau | Parade |
|---|---|---|---|
| G6 · DP ou PC | les deux piliers **et** leurs FAQ | **élevé** | arbre de décision par projet ; ne redéfinit ni DP ni PC, renvoie aux piliers pour cela |
| G11 · pièces DP | section « Les pièces » des deux piliers | **élevé** | une pièce = une explication ; le pilier garde la liste, l'article explique |
| G10 · délais | « Après le dépôt » + « Les étapes » | **élevé** | le pilier donne le délai nominal, l'article les cas où il change |
| G13 · ABF | « conditions » des deux piliers | moyen | l'article prend « secteur ABF » comme requête propre |
| G12 · refus | « erreurs qui font rejeter » | moyen | le pilier prévient, l'article traite l'après |
| G9 · PLU | « conditions » | moyen | l'article prend la lecture du règlement, pas la mention |
| G1–G5 · types de projet | une ligne tarifaire par type | **faible** | c'est la longue traîne, le pilier ne la vise pas |

**Un principe applicable à tous** : aucun satellite ne doit porter un `title` qui
commence par « déclaration préalable » ou « permis de construire » seuls. Ces
deux formulations appartiennent aux piliers depuis le lot C, et les leur
reprendre annulerait ce que ce lot a construit.

---

## 6 · Maillage interne

### Depuis les satellites — obligatoire

Chaque article renvoie **une fois** vers son pilier, avec une ancre descriptive,
dans le corps du texte et non en pied. Jamais « cliquez ici » : le site n'a
aucune ancre générique aujourd'hui, c'est un point fort à préserver.

### Depuis les piliers — avec parcimonie

Un pilier ne doit pas se transformer en annuaire de liens. Deux emplacements
suffisent :

- section « Quel projet relève d'une déclaration préalable ? » → G1 à G5, sur le
  type de projet correspondant ;
- section « Les pièces » → G11, quand il existera.

### Depuis l'accueil — déjà prévu, à connecter

Les six cartes de la grille attendent leurs liens. **Aucune ne doit pointer vers
un article inexistant** : chaque lien s'ajoute au moment de la publication, et
pas avant.

### Entre satellites

G1 à G5 se citent entre eux quand le projet du lecteur est à la frontière — une
piscine avec abri renvoie vers G3, une extension avec clôture vers G4.

### Ce qu'il ne faut pas faire

Lier depuis les pages légales, ou depuis `/tarifs/` vers les articles. La page
Tarifs porte l'intention prix ; l'y diluer défait le lot C.

---

## 7 · Ordre de publication recommandé

L'ordre suit deux règles : **le risque de cannibalisation croît** au fil de la
liste, et **l'autorité se construit** avant d'attaquer les sujets disputés.

| Vague | Articles | Pourquoi cet ordre |
|---|---|---|
| **1** | G1 piscine · G2 extension | Priorités 2 et 3 de votre liste, risque le plus faible, et deux des plus fortes demandes saisonnières. Ils valident le format avant d'engager la série |
| **2** | G3 abri/garage · G4 clôture · G5 panneaux solaires | Complètent le cluster DP. À ce stade, cinq articles pointent vers le pilier : son autorité thématique est établie |
| **3** | G9 PLU · G13 ABF | Sujets transverses, risque moyen. G9 est le guide 04 annoncé |
| **4** | G6 DP ou PC | **Sciemment tardif.** C'est le guide 01 annoncé, mais aussi le plus risqué : il ne doit sortir qu'une fois les cinq articles de type de projet en place, pour pouvoir renvoyer vers eux au lieu de tout redire |
| **5** | G10 délais · G12 refus · G14 pièces complémentaires | Le parcours après dépôt, cohérent entre les trois |
| **6** | G7 maison individuelle · G8 seuil 150 m² | Cluster PC, à traiter quand le cluster DP tourne |
| **7** | G11 pièces DP · **guide 05** « 7 erreurs » | En dernier, en synthèse renvoyant aux précédents |

**Sur le guide 01** : il est annoncé en position 01 sur l'accueil mais je le
place en vague 4. La numérotation affichée n'oblige pas à l'ordre de rédaction —
les cartes peuvent recevoir leurs liens dans n'importe quel ordre. Si vous
préférez respecter la numérotation, il faut l'accepter comme un choix
commercial assumé contre le risque SEO, et je le signalerai à nouveau à ce
moment-là.

---

## 8 · Ce que ce plan ne peut pas trancher

- **Aucun volume de recherche.** Les requêtes citées sont déduites de la
  structure de l'offre et du vocabulaire du site, pas d'un outil. Elles sont
  plausibles, pas mesurées. Un passage par un outil de volumes avant la vague 1
  changerait peut-être l'ordre — pas les clusters.
- **Aucune analyse de concurrence.** Les sujets de l'urbanisme sont tenus par
  des acteurs installés, service-public.fr en tête. Le plan ne dit pas sur quelles
  requêtes une page neuve peut réellement se positionner.
- **Aucune estimation de trafic.** Promettre des visites serait inventer.
- **La capacité de rédaction n'est pas connue.** Quatorze articles de fond, c'est
  un chantier de plusieurs mois. L'ordre proposé permet de s'arrêter après
  n'importe quelle vague sans laisser un cluster incohérent.

---

## 9 · À valider avant la première ligne

1. **Base d'URL des articles** — `/guides/`, la racine, ou `/blog/` ?
2. **Page d'index** — à créer, avec quel titre et quel slug ?
3. **Catégories** — deux ou trois larges, ou les six annoncées sur l'accueil ?
4. **Longueur cible** par article, et rythme de publication.
5. **Ordre** — le mien, ou la numérotation affichée sur l'accueil ?
