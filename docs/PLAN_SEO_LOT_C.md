# Lot C — métadonnées et ciblage des pages commerciales

**Plan, relevé du 13 août 2026, après application des lots A et B.**
Aucune modification n'a été faite. Les lots D à G ne sont pas abordés.

## Ce que ce plan ne peut pas trancher

Les intentions et requêtes ci-dessous sont **déduites du contenu des pages et de
la structure de l'offre**, pas d'un outil de mots-clés ni de la Search Console.
Aucun volume de recherche, aucune position, aucune donnée de clic n'a été
consulté — ils ne sont pas accessibles. Les propositions sont donc des
hypothèses argumentées, pas des certitudes chiffrées : elles gagneraient à être
confrontées à un outil de volumes avant d'être figées.

---

## Le problème transversal : trois pages visent les deux mêmes requêtes

C'est le constat qui commande tout le lot.

| Page | Ce que son `title` annonce aujourd'hui |
|---|---|
| **Accueil** | `Déclaration préalable – Permis de construire - UrbiZen` |
| **Déclaration préalable** | `Déclaration préalable de travaux à distance \| Urbizen` |
| **Permis de construire** | `Permis De Construire - Urbizen` |
| **Tarifs** | `tarifs pour une déclaration de travaux et permis de construire` |

**L'accueil et la page Tarifs annoncent tous deux « déclaration préalable » et
« permis de construire »** — exactement les requêtes que les deux pages dédiées
travaillent sur 1 724 et 1 918 mots. Quatre pages se disputent deux requêtes, et
les deux qui ont le contenu le plus faible sur le sujet occupent les
métadonnées les plus visibles.

C'est la cannibalisation la plus nette du site, et elle ne se corrige pas par
le contenu : elle se corrige en donnant à chaque page une promesse distincte.

**Principe proposé pour le lot :**

| Page | Ce qu'elle doit porter |
|---|---|
| Accueil | la requête générique — « dossier d'urbanisme », le service dans son ensemble |
| Déclaration préalable | « déclaration préalable de travaux » |
| Permis de construire | « permis de construire » |
| Conception | « plans » — la prestation graphique |
| Tarifs | « prix / tarif » d'un dossier d'urbanisme, sans nommer les deux démarches |

---

## La casse de la marque

`UrbiZen`, avec un Z majuscule, apparaît **4 fois** — toutes sur l'accueil, et
toutes issues du **même champ** : le `title` AIOSEO de la page 4, recopié
automatiquement dans `og:title`, `twitter:title` et le `name` du JSON-LD.

Le nom de site vaut désormais `Urbizen` (lot B), et les 33 à 37 autres
occurrences sur chaque page l'écrivent ainsi. **Une seule valeur à corriger**
règle les quatre.

`URBIZEN` en capitales apparaît sur `/contact/` et `/autres-projets/`, dans le
corps de texte hérité : hors périmètre du lot C, qui ne touche qu'aux
métadonnées.

---

## 1 · Accueil — `/`

| | |
|---|---|
| **Intention principale** | générique et navigationnelle : « qui prépare mon dossier d'urbanisme », « faire faire sa déclaration de travaux » |
| **Requêtes secondaires plausibles** | dossier d'urbanisme à distance · aide déclaration de travaux · faire sa demande d'autorisation d'urbanisme · préparation dossier mairie |
| **Title actuel** | `Déclaration préalable – Permis de construire - UrbiZen` — 54 c. |
| **H1 actuel** | « Vos démarches d'urbanisme, simplement. » |
| **Meta actuelle** | 168 c. — dépasse la longueur utile ; « Gagnez du temps avec Urbizen: dossiers d'urbanisme complets, prêts à déposer, préparés à distance pour projets variés comme panneaux solaires, garage, abri ou piscines. » |
| **Casse** | `UrbiZen` ×4, tous depuis le `title` |
| **Contenu manquant** | aucune mention de la zone d'intervention dans le premier écran ; aucun élément de réassurance chiffré (délai de préparation, nombre de dossiers) ; le H1 ne porte aucun mot-clé |

**Défauts de forme du title** : il enchaîne un tiret demi-cadratin (`–`) entre
les deux démarches puis un tiret simple (`-`) avant la marque, ce qui donne deux
séparateurs de nature différente dans une même chaîne. Et la casse est fausse.

### Propositions

- **Title** — `Dossiers d'urbanisme préparés à distance | Urbizen` *(49 c.)*
- **H1** — « Vos dossiers d'urbanisme, préparés et prêts à déposer. »
  *(garde le ton de la version actuelle en y plaçant le terme générique)*
- **Meta** — « Urbizen prépare vos dossiers d'urbanisme à distance, partout en
  France : déclaration préalable, permis de construire, plans et pièces
  graphiques prêts à déposer. » *(158 c.)*

**Cannibalisation** : forte aujourd'hui, nulle après. L'accueil cesse d'annoncer
les deux démarches et prend la requête parapluie que personne d'autre ne vise.

**Maillage recommandé** : la page lie 3 fois DP, 3 fois PC, 3 fois Conception
mais **une seule fois Tarifs**. Le prix étant la deuxième question de tout
visiteur, un second lien vers Tarifs depuis le bloc d'offres est justifié.

---

## 2 · Déclaration préalable — `/declarations-prealables/`

| | |
|---|---|
| **Intention principale** | informationnelle puis transactionnelle : « c'est quoi », « pour quels travaux », « comment faire » |
| **Requêtes secondaires plausibles** | délai d'instruction déclaration préalable · pièces DP1 à DP8 · déclaration préalable clôture / abri de jardin / piscine / panneaux solaires · seuil de surface · refus de déclaration préalable |
| **Title actuel** | `Déclaration préalable de travaux à distance \| Urbizen` — 53 c. **corrigé au lot A** |
| **H1 actuel** | « La déclaration préalable de travaux, sans prise de tête. » |
| **Meta actuelle** | 139 c. **corrigée au lot A** |
| **Casse** | conforme |
| **Contenu manquant** | aucune déclinaison par type de projet, alors que la page les évoque en liste ; c'est précisément la matière des clusters du lot G |

### Propositions

**Aucun changement de métadonnées.** Title, H1 et description ont été refaits au
lot A et tiennent leur rôle. Y revenir ferait perdre le bénéfice d'un
changement déjà indexé.

**Un point de forme, non traité ici** : le slug est au pluriel
(`/declarations-prealables/`) alors que la requête est au singulier. Le corriger
supposerait une nouvelle migration d'URL sans redirection — coût réel, gain
incertain. À trancher séparément, pas dans ce lot.

**Cannibalisation** : disparaît dès que l'accueil et Tarifs cessent de viser la
même requête. C'est cette page qui doit gagner : elle a le contenu.

**Maillage recommandé** : conserver tel quel. Elle reçoit 16 liens des pages
commerciales et en envoie 6 vers son formulaire — l'équilibre est bon.

---

## 3 · Permis de construire — `/permis-de-construire/`

| | |
|---|---|
| **Intention principale** | informationnelle puis transactionnelle, symétrique de la page DP |
| **Requêtes secondaires plausibles** | seuil des 150 m² et architecte obligatoire · surface de plancher / emprise au sol · délai d'instruction permis · pièces PCMI · permis de construire maison individuelle · extension |
| **Title actuel** | `Permis De Construire - Urbizen` — 30 c. |
| **H1 actuel** | « Votre permis de construire, préparé de A à Z. » |
| **Meta actuelle** | **382 c., générée automatiquement** — reprend les premiers mots de la page : « DOSSIERS D'URBANISME À DISTANCE Permis de construire Votre dossier… » |
| **Casse** | conforme, mais le title est en casse de titre anglaise : « Permis **De** Construire » |
| **Contenu manquant** | rien de majeur : c'est la page la plus riche du site avec 1 918 mots et 11 H2 |

**C'est le déséquilibre le plus coûteux du site** : la page la mieux écrite porte
les métadonnées les moins travaillées. Le title n'est que le titre WordPress
brut, et la description sera tronquée dans les résultats après environ 160
caractères — soit en plein milieu de « Votre dossier de permis de constr… ».

### Propositions

- **Title** — `Permis de construire préparé à distance | Urbizen` *(48 c.)*
- **H1** — inchangé. Il porte le mot-clé et le ton du site.
- **Meta** — « Urbizen prépare votre dossier de permis de construire à distance :
  CERFA, plans PCMI, notice descriptive et insertion paysagère, prêts à
  déposer en mairie. » *(157 c.)*

**Cannibalisation** : même mécanisme que pour la page DP, réglé par le
repositionnement de l'accueil et de Tarifs.

**Maillage recommandé** : conserver. 16 liens entrants, 6 vers son formulaire.

---

## 4 · Conception — `/conception/`

| | |
|---|---|
| **Intention principale** | transactionnelle : « faire réaliser des plans » |
| **Requêtes secondaires plausibles** | plan de maison sur mesure · plan pour permis de construire · dessinateur projeteur · plan de masse et plan de coupe · rendu 3D de projet |
| **Title actuel** | `Conception - Urbizen` — **20 c., le plus faible du site** |
| **H1 actuel** | « Donnez forme à votre projet avec des plans pensés pour vous » |
| **Meta actuelle** | **absente** |
| **Casse** | conforme |
| **Contenu manquant** | 703 mots — la page la plus courte des cinq, alors qu'elle porte la prestation la plus chère (449 €). Manquent : ce que comprend exactement un jeu de plans, les formats livrés, le nombre d'allers-retours inclus, et le lien explicite avec les deux démarches qui en ont besoin |

« Conception » seul ne correspond à aucune requête d'urbanisme : le mot est trop
générique et appartient à d'autres domaines. La page ne vise rien aujourd'hui.

### Propositions

- **Title** — `Plans sur mesure pour votre dossier d'urbanisme | Urbizen` *(56 c.)*
- **H1** — « Des plans sur mesure pour votre projet et votre dossier »
  *(place « plans » et « dossier » là où le H1 actuel ne dit que « plans »)*
- **Meta** — « Urbizen dessine les plans de votre projet : plan de masse, plan de
  coupe, façades et insertion paysagère, réalisés sur mesure et prêts pour votre
  dossier. » *(160 c.)*

**Cannibalisation** : nulle. Cette page ne concurrence personne — c'est son
problème, pas sa qualité.

**Maillage recommandé** : c'est la page commerciale **la moins liée** — 13 liens
entrants contre 16 pour DP et PC. Les pages DP et PC devraient renvoyer vers
elle depuis leur section « pièces du dossier », là où les plans sont évoqués :
c'est le moment où le besoin naît.

---

## 5 · Tarifs — `/tarifs/`

| | |
|---|---|
| **Intention principale** | commerciale comparative : « combien coûte une déclaration préalable », « prix permis de construire » |
| **Requêtes secondaires plausibles** | prix dossier d'urbanisme · tarif dessinateur permis de construire · coût plan de masse · ce qui est inclus dans un dossier |
| **Title actuel** | `tarifs pour une déclaration de travaux et permis de construire` — 62 c., **initiale en minuscule**, **sans marque** |
| **H1 actuel** | « Des tarifs clairs pour votre projet d'urbanisme » |
| **Meta actuelle** | 142 c. — « Tarifs Urbizen pour déclaration préalable, permis de construire et plans sur mesure : **dès 189 €**, dossier préparé à distance partout en France. » |
| **Casse** | conforme |
| **Contenu manquant** | rien de structurel : 1 278 mots, grille lisible, 6 questions fréquentes |

**Deux défauts.** Le title vise les deux démarches, qui appartiennent aux pages
dédiées, et il commence par une minuscule sans porter la marque.

Surtout : **la description contient un prix.** C'est exactement le mécanisme qui
a produit le problème P0 du lot A, où la page DP annonçait « à partir de 149 € »
dans Google des mois après le changement de grille. Le montant est juste
aujourd'hui ; il ne le restera pas indéfiniment, et une métadonnée ne se relit
jamais.

### Propositions

- **Title** — `Tarifs d'un dossier d'urbanisme | Urbizen` *(40 c.)*
- **H1** — inchangé.
- **Meta** — « Le prix de votre dossier d'urbanisme selon la nature du projet :
  ce qui est inclus, les options, et le devis avant toute commande. Préparation
  à distance partout en France. » *(159 c.)*

**Cannibalisation** : forte aujourd'hui sur les deux requêtes principales,
nulle après. La page garde toute sa force sur « prix » et « tarif », qu'aucune
autre page ne vise.

**Maillage recommandé** : elle reçoit 12 liens, le minimum des cinq, alors
qu'elle est la deuxième étape naturelle de toute visite. Voir la recommandation
d'un second lien depuis l'accueil.

---

## Récapitulatif des changements proposés

| Page | Title | H1 | Meta |
|---|:---:|:---:|:---:|
| Accueil | à changer | à changer | à raccourcir |
| Déclaration préalable | — | — | — |
| Permis de construire | à changer | — | **à écrire** |
| Conception | à changer | à changer | **à écrire** |
| Tarifs | à changer | — | à réécrire, sans prix |

**Quatre titles, deux H1, quatre descriptions.** Aucune réécriture de contenu :
le lot C ne touche qu'aux métadonnées et au maillage.

### Deux règles à retenir du lot

1. **Aucun prix dans une métadonnée.** Leçon du P0 du lot A, appliquée
   préventivement à la page Tarifs.
2. **Une requête, une page.** L'accueil et Tarifs cessent d'annoncer les deux
   démarches ; DP et PC les gardent pour eux.

### Maillage — trois ajouts

| Depuis | Vers | Où |
|---|---|---|
| Accueil | `/tarifs/` | dans le bloc d'offres, en plus du lien de navigation |
| `/declarations-prealables/` | `/conception/` | section « les pièces d'une déclaration préalable » |
| `/permis-de-construire/` | `/conception/` | section « les pièces d'un permis de construire » |

---

## Hors périmètre du lot C

- La casse `URBIZEN` en capitales dans le corps de `/contact/` et
  `/autres-projets/` : contenu, pas métadonnée.
- Le H1 manquant des deux coques de formulaire — un défaut d'accessibilité qui
  subsiste malgré leur `noindex`.
- Le slug pluriel de la page Déclaration préalable.
- Le contenu de la page Conception, trop courte pour sa valeur commerciale : à
  étoffer, mais c'est de la rédaction, donc un autre chantier.
- Les polices (lot D), les données structurées (lot E), l'éditorial (lot G).
