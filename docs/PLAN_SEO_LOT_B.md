# Lot B — assainissement de l'index

**Plan, relevé du 13 août 2026, après application du lot A.**
Aucune modification n'a été faite : ce document décrit ce qui est proposé et
pourquoi. Le lot C et les polices ne sont pas abordés.

## Point de départ mesuré

| | |
|---|---:|
| URL répondant 200 | **23** |
| dont **indexables** | **20** |
| dont `noindex` | 3 |
| archive d'auteur | 404 depuis le lot A |

L'objectif du lot est de ramener l'index à **9 à 11 URL, toutes voulues**.

Un mot sur ce que ce plan ne peut pas savoir : **aucun outil de netlinking ni
de Search Console n'a été consulté**. Les recommandations reposent sur la valeur
intrinsèque des pages, pas sur leur historique de positions ou leurs liens
entrants externes. Si une de ces pages recevait des liens depuis l'extérieur, la
recommandation de suppression mériterait d'être revue — un contrôle en Search
Console avant exécution lèverait le doute.

---

## 1 · L'îlot hérité

Le fait le plus important de cet audit n'est pas dans la liste des URL, il est
dans leur graphe.

**Sept pages forment un îlot fermé.** Elles se lient les unes aux autres et
**rien, dans le site vivant, ne pointe vers elles** :

```
/autres-projets/  ─┐
/espace-professionnels/ ─┤
/shop/  ───────────┼──→  /contact/  ←─┐
/category/uncategorized/ ─┘           │
                    └──→ /commander-un-dossier/ ─┘
/hello-world/  ←── /category/uncategorized/
```

`/contact/` et `/commander-un-dossier/` affichent 5 liens entrants chacun — mais
**tous proviennent de l'îlot lui-même**. Aucune des onze pages vivantes (accueil,
DP, PC, conception, tarifs, pages légales, formulaires) ne pointe vers eux.

Ces pages portent l'ancien en-tête et l'ancien pied du thème parent ; les pages
refondues portent l'en-tête Urbizen, qui ne les mentionne pas. Le menu WordPress
`menu principal` existe encore, contient `/contact/`, mais **n'est assigné à
aucun emplacement** : il n'est rendu nulle part.

Conséquence SEO : ces pages ne reçoivent aucun signal interne. Elles ne se
positionneront pas, et elles consomment du budget d'exploration en donnant à
Google l'image d'un site en deux morceaux.

---

## 2 · Recommandation par URL

Légende des actions : **supprimer** (corbeille, 404 naturel) · **noindex** ·
**conserver** · **améliorer**.

### Pages WooCommerce — extension inactive

| URL | État | Action | Justification |
|---|---|---|---|
| `/shop/` | 200, **indexable**, 1 mot, 0 entrant | **supprimer** | Aucun produit, aucune boutique, extension inactive. Une page « Shop » d'un mot, indexable, sur un site de services : elle ne peut que nuire à la compréhension du site par Google. |
| `/cart/` | 200, `noindex` | **supprimer** | Même raison. Déjà `noindex`, mais elle répond encore 200 et sera explorée. |
| `/checkout/` | 200, `noindex` | **supprimer** | Idem. |
| `/my-account/` | 200, `noindex` | **supprimer** | Idem. |

**Précaution.** Les options `woocommerce_shop_page_id`, `…_cart_page_id`,
`…_checkout_page_id` et `…_myaccount_page_id` pointent encore sur les pages 22 à
25. Si WooCommerce était réactivé un jour, il recréerait ces pages — ce n'est pas
un obstacle, mais il faut le savoir. La mise à la corbeille est réversible
pendant 30 jours ; la suppression définitive vient ensuite.

**Pourquoi 404 et non 410.** Un 410 signale une disparition définitive et accélère
un peu la désindexation. Il demanderait ici du code ou une extension pour quatre
URL qui, selon toute vraisemblance, ne sont pas indexées et ne reçoivent aucun
trafic. Le 404 natif de WordPress suffit — même raisonnement que pour les
anciens slugs légaux.

### Contenu de démonstration WordPress

| URL | État | Action | Justification |
|---|---|---|---|
| `/hello-world/` | 200, **indexable**, 2 mots | **supprimer** | Article créé par l'installation de WordPress. Deux mots, en anglais, indexable : c'est le signal le plus net qu'un site n'est pas fini. Il porte aussi le commentaire de démonstration « A WordPress Commenter », à supprimer avec lui. |
| `/category/uncategorized/` | 200, **indexable**, 24 mots | **renommer** + `noindex` explicite | WordPress interdit de supprimer la catégorie par défaut : elle est **renommée en français**, slug compris. ⚠️ **Correction apportée à l'exécution** — ce plan annonçait que l'option AIOSEO `noIndexEmptyCat`, active, la désindexerait une fois vide. C'est faux : dans la version 5.0.0.1, l'option n'existe que comme définition et **n'est lue nulle part**. Mesuré : catégorie à zéro article, toujours indexable. Poser le `noindex` sur le terme est impossible en Free (ni modèle ni table pour les termes). Un filtre `aioseo_robots_meta` du thème applique donc la règle à **toute archive de taxonomie vide** — durable pour le blog du lot G, et sans effet dès le premier article publié. |

### Archives de date

| URL | État | Action | Justification |
|---|---|---|---|
| `/2026/` | 200, **indexable** | **noindex** via AIOSEO | Une archive de date ne fait que republier une liste d'articles sous une autre adresse. Sans blog, elle est vide de sens ; avec un blog, elle deviendra un doublon fin de la liste principale. |
| `/2026/05/` | 200, **indexable** | **noindex** via AIOSEO | Idem. |

**Réglage unique** : `searchAppearance.archives.date.show = false`. Vérifié dans
le code d'AIOSEO — c'est le même mécanisme que celui employé au lot A pour les
archives d'auteur : exclusion du plan de site et `noindex`.

**Pourquoi `noindex` et non 404, contrairement aux archives d'auteur.** Le lot A
a supprimé l'archive d'auteur parce qu'elle exposait une **donnée personnelle**,
et que `noindex` n'est qu'une demande faite aux moteurs — un moissonneur ne la
lit pas. Une archive de date n'expose rien : le `noindex` suffit, et supprimer
l'URL demanderait du code pour un gain nul.

### L'îlot hérité — pages de contenu

| URL | État | Action | Justification |
|---|---|---|---|
| `/espace-professionnels/` | 200, **indexable**, 39 mots, **0 H1**, 0 entrant | **supprimer** | 39 mots ne se positionneront jamais. Deux raisons de fond s'ajoutent : la page annonce « Notre équipe » et parle au « nous », là où les mentions légales déclarent un entrepreneur individuel — une incohérence qu'il vaut mieux ne pas laisser indexable ; et l'offre « Espace Pro » qu'elle décrit ne correspond à aucune prestation du site actuel. |
| `/autres-projets/` | 200, **indexable**, 77 mots, **0 H1**, 0 entrant, **1 lien sortant en 404** | **noindex**, contenu conservé | Elle traite des clôtures, abris de jardin et panneaux solaires — c'est-à-dire **trois des huit clusters du lot G**. La supprimer détruirait de la matière ; la laisser indexable à 77 mots sans H1 dilue le site. `noindex` retire le préjudice sans rien perdre, et la décision de fond (refonte ou suppression) se prend au lot G. Son lien mort vers `/panneaux-solaires/` disparaît de fait. |
| `/commander-un-dossier/` | 200, **indexable**, 137 mots, 0 entrant du site vivant | **supprimer** | Doublon fonctionnel : les trois pages `/formulaire-*/` remplissent ce rôle, en mieux. Deux tunnels de commande concurrents, dont un invisible, n'apportent rien. |
| `/contact/` | 200, **indexable**, 51 mots, 5 entrants — tous depuis l'îlot | **conserver et améliorer** | **La seule page de l'îlot à garder.** « urbizen contact » est une requête de marque réelle, et le site vivant n'offre aujourd'hui aucune page de contact — seulement un formulaire sur l'accueil, un `mailto:` et un `tel:` en pied. Elle porte le triplet nom / adresse / téléphone, socle du futur `LocalBusiness` du lot E. Adresse vérifiée cohérente avec les mentions légales : 59 rue de Ponthieu, 75008 Paris. |

**Le travail sur `/contact/` déborde de ce lot.** L'assainissement s'arrête à la
décision de la conserver et à son raccrochage au maillage — un lien depuis le
pied de page vivant suffit. Sa réécriture (51 mots, aucun H2) relève du lot C.

### Pages de formulaire

| URL | État | Action | Justification |
|---|---|---|---|
| `/formulaire-declaration-prealable/` | 200, **indexable**, **0 mot**, **0 H1**, iframe | **noindex** | Coque applicative : tout le contenu est dans une iframe, que Google n'indexe pas au titre de la page. Une page à zéro mot indexable est une porte d'entrée sans contexte — un visiteur qui y arrive depuis une recherche tombe sur un formulaire sans savoir ce qu'il fait là. |
| `/formulaire-permis-de-construire/` | 200, **indexable**, **0 mot**, **0 H1**, iframe | **noindex** | Idem. |
| `/formulaire-conception/` | 200, **indexable**, 621 mots, 1 H1 | **conserver** | Contrairement aux deux autres, elle a un contenu propre et un H1. Elle peut rester indexable. |

**Réserve à trancher.** Ces trois pages sont des étapes de tunnel : on peut
soutenir qu'aucune ne devrait être indexable, par cohérence. Je recommande de
n'appliquer le `noindex` qu'aux deux pages vides — celles où le préjudice est
certain — et de traiter la troisième au lot C, quand les métadonnées et les H1
des parcours seront arbitrés ensemble.

Le **H1 manquant** des deux coques est un défaut d'accessibilité qui subsiste
après le `noindex` : il relève du lot C.

---

## 3 · Les quatre pages sans H1

| URL | Sort proposé | Le H1 reste-t-il à traiter ? |
|---|---|---|
| `/espace-professionnels/` | supprimer | non — la page disparaît |
| `/autres-projets/` | `noindex` | à traiter au lot G si la page est refondue |
| `/formulaire-declaration-prealable/` | `noindex` | **oui — lot C**, pour l'accessibilité |
| `/formulaire-permis-de-construire/` | `noindex` | **oui — lot C**, pour l'accessibilité |

---

## 4 · Les deux liens internes en 404

| Depuis | Vers | Sort |
|---|---|---|
| `/autres-projets/` | `/panneaux-solaires/` | disparaît avec le `noindex` de la page source ; le lien reste cassé pour un visiteur, à traiter si la page est refondue au lot G |
| `/espace-professionnels/` | `/comment-ca-marche/` (×2) | disparaît avec la suppression de la page source |

Aucun des deux ne demande de correction propre : leurs pages sources sont
traitées. **Il n'y aura plus aucun lien mort dans le site indexable.**

---

## 5 · Le titre de site vide

Ce point n'est pas une URL, mais il appartient à l'assainissement : `blogname`
est une chaîne vide, ce qui produit **13 titles terminés par « - »** et fait que
`Organization.name` vaut le slogan.

| Réglage | Avant | Après proposé |
|---|---|---|
| `blogname` | *(vide)* | `Urbizen` |

Effet immédiat sur toutes les pages sans title AIOSEO explicite, et sur les
données structurées. C'est la correction au meilleur rapport effort / effet du
lot.

**Attention à un effet de bord** : les pages qui ont déjà un title explicite se
terminant par `| Urbizen` ne changent pas — elles n'utilisent aucune balise
dynamique. En revanche, `/permis-de-construire/` (`Permis De Construire -`) et
`/conception/` (`Conception -`) deviendraient `Permis De Construire - Urbizen` et
`Conception - Urbizen` : mieux, mais toujours faibles. Leur rédaction est au
lot C, et les deux lots se complètent sans se gêner.

---

## 6 · Récapitulatif

| Action | URL concernées |
|---:|---|
| **Supprimer** (6) | `/shop/`, `/cart/`, `/checkout/`, `/my-account/`, `/hello-world/`, `/espace-professionnels/`, `/commander-un-dossier/` |
| **`noindex`** (5) | `/autres-projets/`, `/2026/`, `/2026/05/`, `/formulaire-declaration-prealable/`, `/formulaire-permis-de-construire/` |
| **Renommer** (1) | catégorie `Uncategorized` |
| **Conserver et raccrocher** (1) | `/contact/` |
| **Réglage** (1) | `blogname` |

*(La ligne « supprimer » compte 7 URL ; les 4 pages WooCommerce forment un seul
geste.)*

### Index attendu après le lot

| | Avant | Après |
|---|---:|---:|
| URL répondant 200 | 23 | **16** |
| dont indexables | **20** | **9** |

Les neuf : accueil, tarifs, déclaration préalable, permis de construire,
conception, formulaire conception, contact, et les trois documents légaux —
soit **dix**. Le compte exact dépendra de l'arbitrage sur
`/formulaire-conception/`.

---

## 7 · Ce que ce lot ne fait pas

- Il ne réécrit aucun contenu ni aucune métadonnée — c'est le lot C.
- Il ne touche pas aux polices — c'est le lot D.
- Il ne corrige pas le `Person.url` du JSON-LD, qui pointe vers l'archive
  d'auteur désormais en 404 : anomalie identifiée au lot A, réservée au lot E.
- Il ne crée aucune redirection, conformément aux décisions précédentes.
- Il ne désinstalle pas WooCommerce ni les autres extensions inutilisées :
  c'est un sujet de maintenance, pas d'indexation.

## 8 · Avant d'exécuter

Trois points appellent une décision de votre part :

1. **`/autres-projets/`** — `noindex` avec conservation du contenu, comme
   proposé, ou suppression franche ?
2. **`/formulaire-conception/`** — indexable, ou `noindex` par cohérence avec
   les deux autres pages de tunnel ?
3. **Search Console** — un coup d'œil aux pages réellement indexées et à leurs
   éventuels liens entrants avant de supprimer sept URL. Sans cet accès, la
   recommandation reste fondée sur la valeur intrinsèque des pages, ce qui est
   solide mais pas complet.
