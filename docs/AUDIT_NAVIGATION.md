# Navigation — regroupement sous « Nos prestations » et lisibilité des intitulés

**14 août 2026.** Branche `feat/nav-nos-prestations`.

---

## 1 · Audit — quel menu est réellement servi

La mise en garde était fondée. Le thème contient **deux menus WordPress** que
personne ne voit :

| Fichier | Contenu | Appelé par |
|---|---|---|
| `parts/header.html` | bloc `wp:navigation`, 4 entrées, bouton « Commander mon dossier » | **aucun gabarit** |
| `parts/superposition-de-navigation.html` | bloc `wp:navigation`, mêmes 4 entrées | **aucun gabarit** |
| `parts/header-urbizen.html` | une ligne : le pattern `urbizen-child/header-accueil` | **les 12 gabarits** |

Vérifié sur la production : `wp-block-navigation` et `hostinger-ai-menu`
n'apparaissent **ni sur l'accueil ni sur `/tarifs/`**. Modifier le menu dans
l'administration WordPress n'aurait donc rien changé à l'écran.

Le menu réel est du HTML écrit à la main dans `patterns/header-accueil.php`,
en double : `.nav-links` au-delà de 1100 px, `#mmenu` sous le burger en deçà.
Et ce pattern doit rester **identique au caractère près** à
`frontend/homepage/index.html` — c'est ce que compare `test-fidelite.php`.
Les deux ont donc bougé ensemble.

Un contrôle du nouveau banc vérifie désormais que le menu hérité n'est branché
sur aucun gabarit : le jour où il le serait, deux menus seraient servis.

---

## 2 · Le menu, avant et après

| Avant | Après |
|---|---|
| Déclaration préalable | Accueil |
| Permis de construire | **Nos prestations** ▾ |
| Conception de plans | · Déclaration préalable |
| Comment ça marche | · Permis de construire |
| Tarifs | · Conception de plans |
| | Tarifs |
| | Espace client *(bientôt)* |
| | Contact |

**Aucune URL de prestation ne change.** Aucune page « Nos prestations » n'a été
créée : le parent est un `<button aria-expanded>` qui n'ouvre que le sous-menu.

### Ce que le menu perd — à signaler

L'entrée **« Comment ça marche » → `#methode` disparaît**. Elle n'était pas dans
la liste des retraits demandés, mais le menu cible a été énoncé de façon
exhaustive et ne la contient pas. Deux faits à connaître avant d'arbitrer :

- c'était **le seul chemin de navigation** vers la section « méthode » de
  l'accueil ; celle-ci reste atteignable en faisant défiler la page, et rien
  d'autre n'y renvoie ;
- l'ancre `#methode` existe toujours dans les trois gabarits d'accueil, et
  `/comment-ca-marche/` reste une URL morte, supprimée au lot B.

La rétablir coûte une ligne dans la maquette et une dans le pattern, au premier
niveau ou en quatrième entrée du sous-menu.

### Espace client

Ni lien ni bouton : un `<span aria-disabled="true">` portant l'étiquette
« bientôt ». Aucune destination n'a été inventée — et l'entrée n'était de toute
façon **pas** un `href="#"` : c'était, et cela reste, l'icône « compte » de la
barre, laissée intacte. L'entrée texte s'y ajoute pour que le menu soit complet.

### Guides — préparé, pas posé

`https://urbizen.fr/guides/` répond **404** aujourd'hui. L'entrée n'est donc pas
créée : elle le sera **en même temps que la page index du lot G**, insérée entre
« Tarifs » et « Espace client ».

Le banc porte un contrôle qui échoue le jour où `/guides/` apparaît dans
l'en-tête sans que la page existe — il force les deux à arriver ensemble.

---

## 3 · Mécanique du sous-menu

**Ouverture au clic et au clavier uniquement, jamais au survol.** Mêler les deux
produit le défaut classique : le clic ferme, le pointeur est encore dessus, le
survol rouvre aussitôt, et le menu paraît increvable.

Quatre fermetures, chacune vérifiée séparément : second clic sur le parent,
`Échap`, clic au dehors, sortie du focus par tabulation. `Échap` **rend le focus
au parent** — sans quoi il retombe sur `<body>` et la tabulation repart du début
du document. `Flèche bas` ouvre et pose le focus sur la première prestation.

Sous 1100 px, `.nav-links` disparaît au profit du burger. Le tiroir mobile
présente « Nos prestations » comme un intitulé de groupe **toujours déplié**,
les trois prestations décalées sous lui : pas de second mécanisme de dépliage à
maintenir, et rien qui puisse rester coincé.

---

## 4 · Lisibilité des intitulés — valeurs appliquées

Toutes les couleurs viennent de `urbizen-tokens.css`. Contrastes mesurés sur le
fond de l'en-tête, `#FBFCFD`.

| État | Avant | Après | Contraste |
|---|---|---|---|
| Normal | `--u-ink-soft` `#55617A`, **500** | **`--u-ink` `#14233B`, 600** | 6,05 → **15,33:1** (AAA) |
| Survol | `--u-brand` `#128A5A` | **`--u-brand-dk` `#0E6E48`** + soulignement 1,5 px | 4,25 → **6,12:1** (AA) |
| Page courante | *(n'existait pas)* | **`--u-ink`, 700** + soulignement 2 px en `--u-brand` | **15,33:1** |
| Espace client (inactif) | — | `--u-ink-faint` `#8791A6` | 3,08:1 |

Taille **inchangée à 14 px**. Tiroir mobile aligné : `--u-ink`, 600, 15 px.

### Le survol a dû être corrigé

Le premier jet gardait `--u-brand` au survol. La mesure l'a écarté : à
**4,25:1**, en dessous du seuil AA de 4,5 pour du texte de 14 px, survoler un
libellé désormais à 15,33:1 l'aurait rendu **moins** lisible qu'au repos —
l'inverse de ce qu'un survol doit faire. `--u-brand-dk` est d'ailleurs ce que la
charte désigne explicitement comme la couleur des survols. Le soulignement s'y
ajoute pour que la marque ne dépende pas seulement de la couleur.

### Page courante

Marquée par `aria-current="page"` posé par le pattern : l'état est dans le
balisage, donc annoncé aux lecteurs d'écran, et le CSS ne fait que le rendre
visible. Elle **garde le bleu nuit** au lieu de passer au vert, pour ne pas se
lire comme un lien survolé. Le groupe « Nos prestations » s'allume aussi quand
l'une de ses trois pages est ouverte.

Le soulignement passe par `text-decoration` et non par une bordure : une bordure
aurait ajouté 2 px de hauteur et décalé la ligne du menu dans une barre à
hauteur fixe.

### Ce qui n'a pas bougé

Logo, bouton « Démarrer mon projet », icônes téléphone et compte, burger, et la
taille des libellés. Mesuré après coup : `.nav` fait toujours **70 px**, le haut
du premier libellé est à **23,80 px** — aucun décalage vertical.

---

## 5 · Vérifications

`tests/homepage/test-navigation.php` — 40 contrôles statiques : composition du
menu dans l'ordre, parent non-lien, aucun `href="#"`, aucun lien professionnels,
absence d'entrée Guides tant que la page n'existe pas, valeurs de couleur et de
graisse lues dans la feuille, absence de bordure sur l'état courant. Il rend
aussi le pattern **en position de page interne** — ce que `test-fidelite.php` ne
fait jamais — pour vérifier que `aria-current` atterrit sur la bonne entrée sur
`/tarifs/` et sur `/conception/`.

`tests/homepage/test-navigation.py` — 26 contrôles rejoués dans Chrome, à
**320, 360, 390, 768, 1100, 1101, 1280 et 1440 px**. 1100 et 1101 encadrent la
bascule : un menu qui casse le fait au seuil. Sont mesurés l'ouverture, les
quatre fermetures, le retour du focus après `Échap`, l'entrée au clavier, la
rotation du chevron, le maintien du panneau dans la fenêtre, le décalage réel du
sous-niveau mobile et l'absence de débordement horizontal dans tous les états.

Les deux sont câblés dans `tests/homepage/run-all.sh` : **13 bancs, tous verts.**

Deux défauts venaient du banc lui-même, pas du code : la rotation du chevron
était lue pendant sa transition de 0,18 s, et le décalage du sous-niveau mobile
était mesuré sur la boîte alors qu'il est produit par un `padding-left` — la
boîte occupant toute la largeur, son bord ne bouge pas d'un pixel. Le décalage
se mesure maintenant sur le texte.

Le halo de focus n'est pas mesuré dans le banc Chrome : `:focus-visible` ne se
déclenche pas de façon fiable sur un `focus()` programmatique. La règle est
garantie par le banc statique, et le halo a été constaté au clavier réel.

---

## 6 · Reste ouvert

- **« Comment ça marche »** — retiré selon le menu cible ; à rétablir sur un mot.
- **Guides** — à poser avec la page index du lot G.
- **Espace client** — l'entrée restera inactive tant que la destination n'existe
  pas ; l'icône « compte » de la barre fait aujourd'hui double emploi avec elle,
  et l'une des deux pourra disparaître le jour où l'espace ouvrira.
