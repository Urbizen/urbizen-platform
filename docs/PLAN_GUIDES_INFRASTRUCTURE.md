# Infrastructure des guides — gabarits d'articles du thème enfant

**14 août 2026.** Branche `feat/guides-templates`.
**Aucune mutation WordPress en production.** Ni permaliens, ni page `/guides/`,
ni catégories, ni entrée de menu.

---

## 1 · Le problème que ce lot résout

Le thème enfant n'avait **aucun gabarit d'article**. `templates/` contenait
`front-page.html` et onze `page-*.html` — pas de `home`, pas de `single`, pas
d'`archive`. Tout cela retombait sur le thème parent Hostinger, dont
l'`index.html` tient en quatre lignes :

- `template-part {"slug":"header"}` — le menu mort à quatre entrées ;
- un `<h1>` écrit en dur : **« Latest posts »**, en anglais ;
- le pattern `hostinger-ai/query` ;
- `template-part {"slug":"footer"}` — le pied de page Hostinger.

Son `single.html` est bâti de même. Un guide publié aujourd'hui serait donc
servi avec l'apparence d'un autre site.

---

## 2 · Ce qui a été ajouté

| Fichier | Rôle |
|---|---|
| `templates/home.html` | index des guides |
| `templates/single.html` | article |
| `templates/archive.html` | archive de catégorie |
| `patterns/guides-grille.php` | grille de cartes + pagination |
| `patterns/guide-entete.php` | fil d'ariane, catégorie, date, titre, visuel |
| `patterns/guide-pied.php` | appel à l'action, guides voisins, retour |
| `patterns/guides-archive-entete.php` | fil d'ariane et titre d'archive |
| `assets/css/urbizen-guides.css` | ~90 règles, toutes scopées `.urbizen-guides` |
| `tests/guides/test-guides.php` | 70 contrôles |

Plus, dans `functions.php` : la détection du contexte, l'enfilement de la
feuille, les deux helpers partagés, la fonction d'orientation de l'appel à
l'action, et le `noindex` des trois catégories.

---

## 3 · Cinq décisions, et pourquoi

### 3.1 · Les cartes ne sont pas réécrites, elles sont réutilisées

Les gabarits portent `urbizen-accueil urbizen-page urbizen-guides`. Les deux
premières classes sont les portées de `urbizen-homepage.css` et
`urbizen-pages.css` : les cartes `.blog-preview-*` de l'accueil, le hero de
page, le fil d'ariane, les boutons et `.wrap` **fonctionnent déjà**.

La feuille des guides n'écrit donc que ce qui manque : l'extrait et le lien de
carte (l'accueil annonce sans lier), la typographie du corps, la pagination, le
pied d'article. Réécrire une grille de cartes aurait créé un second composant,
qui aurait dérivé du premier à la première retouche.

### 3.2 · La grille est un pattern, pas un bloc « Requête »

`core/query` rend sa propre structure (`<ul class="wp-block-post-…">`) qu'il
aurait fallu re-styler à l'identique. Le pattern émet exactement le markup de
l'accueil. Il lit la **boucle principale** et ne crée aucune `WP_Query` : la
pagination, le filtrage par catégorie et le nombre d'articles par page sont
ceux que WordPress a déjà calculés. En refaire une les aurait ignorés.

### 3.3 · Le H1 de l'index est écrit dans le gabarit

C'était le point signalé, et il est fondé. Sur `home.html`, WordPress interroge
la **liste des articles** ; l'objet interrogé n'est pas la page assignée à
`page_for_posts`. Un `core/post-title` y rendrait le titre du **premier article
de la liste** — et un titre différent sur la page 2. Un gabarit `.html`
n'exécute par ailleurs aucun PHP pour aller chercher le titre de la page.

Le libellé « Guides d'urbanisme » est donc en dur. La page WordPress sert à
porter le slug `/guides/`, les métadonnées AIOSEO et le maillon du fil
d'ariane ; le titre affiché ne dépend d'aucun mécanisme incertain.

### 3.4 · Aucun auteur affiché

`/author/` répond 404 depuis le lot A et le lot E a nettoyé le graphe JSON-LD.
Les gabarits n'affichent **pas d'auteur du tout** : un nom sans lien inviterait
quelqu'un à le rendre cliquable, et l'archive reviendrait par la bande. Un
contrôle vérifie que la chaîne « author » n'apparaît nulle part dans les
gabarits — y compris en commentaire HTML, qui part chez le visiteur.

### 3.5 · L'appel à l'action suit le sujet

Un guide sur la piscine mène à la déclaration préalable, pas à une page
générique. La correspondance se fait par slug de catégorie, avec `/tarifs/` en
repli. Les libellés parlent de **préparer et déposer un dossier** : jamais de
délivrer ou d'obtenir une autorisation, règle posée au lot C.

---

## 4 · Trois pièges rencontrés

**Le `wp:html` non refermé.** Le markup d'un pattern doit être dans un bloc
`wp:html`, sinon `wpautop` sème des `<p>` au milieu de la grille. Ma première
version sortait tôt quand la liste était vide — le `return` sautait la fermeture
du bloc et laissait le gabarit avec un `wp:html` jamais refermé. Corrigé en
`if/else`, et un contrôle compte désormais les délimiteurs.

**Les fonctions déclarées dans un pattern.** Un fichier de pattern est inclus à
**chaque** rendu. `guide-pied` utilise deux helpers définis dans
`guides-grille`, absent d'une page d'article : erreur fatale sur chaque guide.
Et un second rendu dans la même page les aurait redéclarés. Les trois fonctions
sont dans `functions.php` ; un contrôle interdit toute déclaration dans un
pattern — c'est lui qui a trouvé la troisième, que j'avais laissée passer.

**Le contexte n'est pas une page.** `urbizen_child_est_page_urbizen()` exigeait
`is_singular()` **et** un slug de gabarit de page. `is_home()`, `is_single()` et
`is_category()` échouaient tous les trois : les guides seraient sortis sans
polices, sans charte et sans feuille. D'où `urbizen_child_est_page_guides()`.
L'archive d'auteur en est volontairement absente.

---

## 5 · Vérifié sur un rendu réel

Installation WordPress locale, thème copié sous `urbizen-child-apercu`, 7
articles factices, 3 catégories, `page_for_posts` posée, permaliens en
`/guides/%postname%/`, `posts_per_page = 6` pour forcer la pagination.

| Contrôle | Résultat |
|---|---|
| `/guides/` | 200 · 6 cartes, 6 extraits, 6 images, pagination `1 · 2 · Suivant` |
| `/guides/<slug>/` | 200 · fil d'ariane à 3 maillons, catégorie, date, visuel, 2 H2, tableau, citation, colonne de 771 px |
| `/guides/category/<slug>/` | 200 · titre du terme, cartes filtrées |
| `/guides/page/2/` | 200 |
| En-tête et pied | **Urbizen partout**, aucune trace de `hostinger-ai-menu` |
| Appel à l'action | pointe sur `/declarations-prealables/` depuis un guide « Autorisations » |
| Guides voisins | 2 cartes de la même catégorie |
| Chaîne `author` | **0 occurrence** |
| Erreurs PHP | **0** sur les quatre URL |
| Débordement horizontal | **0** à 1440, 1240, 768, 390 et 360 px |

Deux corrections faites après lecture des captures : le visuel d'article passait
à 692 px de haut et remplissait l'écran — il est cadré à 420 px (260 en
mobile) ; et le corps de l'article se lisait sur la trame technique — il est
posé sur une surface unie, comme les `.sec-blanc` des pages internes.

---

## 6 · Ce que la bascule des permaliens changera

Vérifié sur l'installation locale, avec la structure `/guides/%postname%/` :

| | |
|---|---|
| **Pages** (`/tarifs/`, `/declarations-prealables/`, …) | **inchangées** — les pages n'ont jamais suivi cette structure. Une page témoin a répondu 200. |
| **Articles** | `/guides/<slug>/` |
| **Catégories** | **`/guides/category/<slug>/`** — WordPress fait hériter la base de catégorie du préfixe. Peut se changer par `category_base` ; sans objet tant qu'elles sont en `noindex`. |
| Règles verbeuses | activées : les pages sont testées avant les articles. Aucun effet fonctionnel. |

### Le greffon ne dépend pas de cette structure

Audit de `urbizen-platform` : un seul type de contenu déclaré,
`'rewrite' => false`, `'public' => false`, `'query_var' => false`. **Aucun**
`add_rewrite_rule`, `add_rewrite_endpoint` ni `add_rewrite_tag`. La seule
occurrence de `get_permalink()` porte sur la page courante, pas sur un article.
Rien à reprendre.

---

## 7 · Les catégories restent hors de l'index

La règle du lot B ne désindexe que les archives **vides** : elle s'efface au
premier article publié. Une seconde règle, nominative, tient les trois
catégories en `noindex` **même remplies**, comme demandé.

Elle porte les **slugs** et non les identifiants — les catégories n'existent pas
encore. Le jour où l'indexation sera rouverte, vider
`URBIZEN_CHILD_CATEGORIES_GUIDES` suffit ; la règle des archives vides reprend
alors la main. `follow` reste acquis dans les deux cas.

---

## 8 · Les cartes de l'accueil

Six guides y sont annoncés, sans lien. Trois options, et une recommandation.

| Option | Ce qu'elle vaut |
|---|---|
| Lier chaque carte à la main, à mesure des publications | simple, mais deux fichiers à éditer par article (maquette + gabarit), et la numérotation 01–06 fige un ordre que le plan éditorial ne suit pas |
| **Remplacer la section par un pattern dynamique** : les 3 derniers guides + un bouton « Tous les guides » | l'accueil se tient à jour seul, le markup des cartes est déjà le bon, et le lien vers `/guides/` amorce le maillage |
| Ne rien changer, ajouter seulement un lien vers `/guides/` | le moins de travail, mais laisse six promesses sans lien |

**Recommandation : l'option 2, dans un petit lot dédié, une fois trois guides
publiés.** Avant ce seuil, la section afficherait une grille creuse. D'ici là,
les six cartes restent ce qu'elles sont : une annonce.

---

## 9 · Ce qui reste à faire, dans l'ordre

1. **Page index** — « Guides d'urbanisme », slug `guides`, posée en
   `page_for_posts`, avec ses métadonnées AIOSEO.
2. **Permaliens** — `/%postname%/` → `/guides/%postname%/`, puis vidage des
   règles de réécriture.
3. **Catégories** — les trois, aux slugs attendus par l'appel à l'action.
4. **Entrée « Guides »** au menu, entre « Tarifs » et « Espace client ».
   Le banc de navigation **remesurera la largeur intrinsèque du menu** : à six
   entrées il réclame déjà 671 px pour 712 disponibles à 1240 px, et une
   septième peut faire remonter le seuil du burger. Le contrôle est automatique
   à toutes les largeurs de bureau — il échouera plutôt que de laisser passer un
   menu qui recouvre le logo.
5. **Rédaction** — plan H2/H3, slugs, métadonnées et maillage présentés avant
   écriture, conformément au lot G.
