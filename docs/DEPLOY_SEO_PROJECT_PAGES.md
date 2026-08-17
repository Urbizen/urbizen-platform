# Publication du cocon SEO « projets » — journal de déploiement

**16 août 2026.** Branche `feat/pages-seo-projets`, partie de `main` à
`834bf84`. 21 contenus publiés : 9 pages, 12 guides.

---

## 1 · Sauvegarde et retour arrière

Faite **avant toute mutation**, selon la procédure de `docs/AI_CONTEXT.md` § 4.1
(`wp db export` échoue sous CageFS — on passe par `mysqldump`).

```
~/backups/urbizen-seo-projets-20260816-1016/
├── db.sql.gz                 2 169 968 o   intègre (gzip -t)
├── urbizen-child.tar.gz      4 038 683 o   intègre (tar tzf)
├── uploads-2026-08.tar.gz    1 401 866 o   intègre (tar tzf)
├── contenus-avant.csv        inventaire de tous les contenus avant publication
└── page_for_posts-avant.txt  1204
```

### Retour arrière complet

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 u328261530@92.113.28.40
cd /home/u328261530/domains/urbizen.fr/public_html
DEST=~/backups/urbizen-seo-projets-20260816-1016

# 1 · Thème enfant et médias du mois
tar xzf $DEST/urbizen-child.tar.gz -C .
tar xzf $DEST/uploads-2026-08.tar.gz -C .

# 2 · Base — écrase l'état courant, y compris tout contenu saisi depuis
CNF=~/.urbizen-restore.cnf; umask 077
wp eval 'printf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\n", DB_USER, DB_PASSWORD, DB_HOST);' > $CNF
chmod 600 $CNF
gunzip -c $DEST/db.sql.gz | mysql --defaults-extra-file=$CNF "$(wp eval 'echo DB_NAME;')"
rm -f $CNF

# 3 · Caches et règles de réécriture
wp cache flush && wp litespeed-purge all && wp rewrite flush
```

### Retour arrière partiel — dépublier les 21 contenus

Utile si le thème doit rester en place mais que les contenus posent problème.
Pense à retirer aussi la section « projets » du hub, sinon elle pointera vers
des pages absentes.

```bash
for s in declaration-prealable-extension-maison declaration-prealable-piscine \
         declaration-prealable-abri-de-jardin declaration-prealable-pergola-carport \
         declaration-prealable-transformation-garage declaration-prealable-panneaux-solaires \
         declaration-prealable-fenetre-de-toit declaration-prealable-modification-facade \
         declaration-prealable-cloture-portail; do
  wp post update "$(wp post list --post_type=page --name=$s --field=ID)" --post_status=draft
done
for s in pieces-declaration-prealable plan-masse-dp2 insertion-graphique-dp6 \
         plan-facades-toitures-dp4 plan-coupe-dp3 secteur-protege-abf-declaration-travaux \
         emprise-au-sol-surface-de-plancher distance-limite-separative-construction \
         recours-architecte-150-m2 demande-pieces-complementaires-urbanisme \
         refus-declaration-prealable cerfa-declaration-travaux; do
  wp post update "$(wp post list --post_type=post --name=$s --field=ID)" --post_status=draft
done
wp litespeed-purge all
```

---

## 2 · État constaté avant publication

| | |
|---|---|
| Thème actif | `urbizen-child` 0.3.0 → **0.4.0** (voir § 4.1) |
| Articles | 6, les guides du lot précédent |
| Pages | les commerciales et légales existantes |
| Catégories | `autorisations-projets`, `regles-urbanisme`, `conseils-demarches`, `non-classe` |
| PR #97 | **fusionnée** le 16/08/2026 en `b8a4443` — cette branche part donc du `main` qui la contient |

---

## 3 · Ordre des opérations

1. **Audit** — état git, worktrees, stash, PR #97, contenus publiés.
2. **Sauvegarde** base + thème + médias, intégrité vérifiée.
3. **Installation du kit visuel** — `assets/seo-projects/` seulement ; les sept
   planches `assets/dossier/` du kit sont **déjà au dépôt à l'octet près**,
   vérifié par empreinte SHA-256, donc rien n'est écrasé.
4. **Recherche et cadrage** — `docs/SEO_CONTENT_MAP.md`.
5. **Vérification réglementaire** — `docs/VERIFICATION_REGLEMENTAIRE_SEO_PROJETS.md`.
6. **Infrastructure** — gabarit, deux patterns, feuille scopée, `theme.json`.
7. **Rédaction** des 21 contenus.
8. **Banc statique** — 538 contrôles, au vert avant tout déploiement.
9. **Déploiement du thème**, précédé d'un passage à blanc.
10. **Publication** — simulation, puis exécution.
11. **Contrôles publics**, banc en ligne, recette visuelle.
12. **Corrections** et redéploiement.

### Commandes de publication

```bash
rsync -az content/ <hôte>:~/urbizen-publication/content/
rsync -az scripts/publier-pages-seo.php <hôte>:~/urbizen-publication/
cd $WP_ROOT
URBIZEN_CONTENU=~/urbizen-publication/content wp eval-file ~/urbizen-publication/publier-pages-seo.php simulation
URBIZEN_CONTENU=~/urbizen-publication/content wp eval-file ~/urbizen-publication/publier-pages-seo.php
wp litespeed-purge all && wp rewrite flush
```

Le script est **idempotent par slug**. Il a d'ailleurs été rejoué une fois pour
poser les vignettes manquantes : aucune page n'a été dupliquée, et les
attachements ont été réutilisés grâce au marqueur `_urbizen_seo_image`.

---

## 4 · Deux difficultés rencontrées, et ce qu'elles ont appris

### 4.1 · Les patterns d'un thème sont mis en cache, indexés sur sa version

Symptôme : les 21 URLs répondaient 200, la feuille était bien enfilée, les
classes de portée présentes — mais les neuf pages projets n'avaient **ni H1 ni
fil d'ariane**. Le corps s'affichait, l'en-tête non.

Diagnostic : `WP_Block_Patterns_Registry` ne connaissait pas
`urbizen-child/projet-entete` ni `projet-pied`, alors que les fichiers étaient
bien déployés dans `patterns/`. WordPress met en cache la liste des patterns
d'un thème, et la clé de ce cache comprend **la version du thème**. Celle-ci
n'ayant pas bougé, les deux nouveaux fichiers n'étaient jamais lus.

Un bloc `wp:pattern` dont le pattern n'est pas enregistré **ne rend rien, sans
erreur**. C'est ce silence qui rend le défaut difficile à voir.

**Correctif** : version du thème enfant portée de **0.3.0 à 0.4.0** — ce qui se
justifiait de toute façon, le lot ajoutant un gabarit, deux patterns et une
feuille — puis `wp cache flush`.

**À retenir** : ajouter un pattern à ce thème sans bump de version est un
non-événement pour WordPress.

### 4.2 · Le visuel d'en-tête mordait sur les boutons

Le débord négatif de `.projet-visuel` avait été repris des guides. Mais le hero
d'un guide se termine par son chapô, tandis que celui d'une page projet porte
deux boutons : à 1440 px, le visuel remontait de 86 px et recouvrait le bas des
boutons de **7 pixels**.

Invisible à la lecture de la feuille, visible sur une capture. Débord ramené à
`clamp(-64px, -4.5vw, -32px)` : 16 px de dégagement à 1440 px, et l'effet de
liaison conservé. Vérifié par mesure aux quatre largeurs.

---

## 5 · Contrôles

| Contrôle | Résultat |
|---|---|
| Les 21 URLs | **200** |
| Canonical autonome | oui, sur les 21 |
| `index, follow` | oui, sur les 21 |
| Un seul H1 | oui, sur les 21 |
| `title` et `description` uniques | oui, aucun doublon sur les 21 |
| H1 jamais identique au title | vérifié |
| Open Graph title et description | présents sur les 21 |
| Image mise en avant | 17 sur 21 — **4 volontairement sans**, voir § 6 |
| `BreadcrumbList` | présent sur les 21 |
| Balisage d'article sur les guides | présent sur les 12 |
| FAQ visible dans le DOM | ≥ 4 questions sur chaque page projet |
| Liens internes | **aucun mort**, toutes destinations en 200 |
| Plan de site | les 21 URLs présentes, aucune archive de catégorie |
| Images responsives | `srcset` à quatre variantes sur les photographies, `width`/`height` partout |
| Débordement horizontal | **aucun** à 1440, 1280, 834 et 390 px |
| Erreurs JavaScript | **aucune** |
| Cible tactile de la FAQ | ≥ 44 px aux quatre largeurs |
| Non-régression | accueil, hub, Tarifs, Conception, PC et les six guides : **200** |
| HERO validé de l'accueil | **intact** |

### Un faux positif, déjà connu

La recette signale « 9 images cassées » sur l'accueil. Vérifié une fois de plus :
ce sont les planches et pictogrammes en `loading="lazy"` dans les panneaux
**repliés** de l'explorateur de dossier — jamais chargés tant qu'on ne les ouvre
pas. Les fichiers répondent tous **200**. Même constat qu'au lot précédent,
consigné dans `DEPLOY_GUIDES_03-07.md` § 6.

---

## 6 · Quatre guides sans image, et pourquoi

`docs/SEO_VISUALS_HANDOFF.md` le prescrit explicitement, guide par guide :

| Guide | Consigne du handoff |
|---|---|
| secteur protégé | une capture officielle créditée, **ou aucune image**. Ne pas inventer un périmètre ABF. |
| recours à l'architecte | pas de schéma de seuil figé dans une image ; un tableau HTML maintenable. |
| refus | **pas de faux arrêté municipal**. |
| CERFA | pas de reproduction de formulaire dont la réutilisation n'a pas été vérifiée. |

Aucune de ces conditions n'était réunie sans produire un visuel — ce que le lot
s'interdit. Ces quatre guides n'ont donc pas d'image mise en avant, et
n'émettent pas d'`og:image`.

Le banc encode ce contrat **dans les deux sens** : ces quatre-là doivent rester
sans image, les dix-sept autres doivent en avoir une. Écrit autrement, il
laisserait passer l'ajout d'un visuel décoratif, précisément ce que le handoff
refuse.

**Piste hors périmètre**, si les cartes sociales de ces quatre pages importent :
configurer une image OG par défaut au niveau du site dans AIOSEO. C'est une
décision de configuration globale — elle toucherait toutes les pages sans
vignette — et elle ne relève pas de ce lot.

---

## 7 · Ce qui reste à surveiller

- **Indexation** des 21 URLs neuves : quelques jours. Le plan de site est à jour.
- **Positions** du guide « emprise au sol / surface de plancher » face au guide
  piscine/garage/carport : la frontière est posée au § 1 de la carte de contenu,
  elle mérite d'être observée sur trois mois.
- **Versions de CERFA** : les formulaires 16702, 13406 et 13409 ont été réédités
  le 1<sup>er</sup> juillet 2026. Le guide affiche la version et sa date ; un
  contrôle annuel suffit, mais il n'est pas automatisable — le portail ne publie
  pas de flux.
- **La PR n'est pas fusionnée** : elle est ouverte en brouillon, comme demandé.
  La production tourne donc sur du contenu dont la source vit encore sur la
  branche.

---

## 5 · Déploiement du kit visuel v2 — 17 août 2026

Branche `feat/pages-seo-projets`, HEAD `d9d32a2`. **`main` n'a pas été touché**
(`834bf84`), la PR #98 reste en brouillon.

### Sauvegarde

`~/backups/urbizen-kit-v2-20260817-0903`

| Pièce | Taille | Intégrité |
|---|---|---|
| `db.sql.gz` | 2,3 Mo | gzip OK, 90 tables |
| `urbizen-child.tar.gz` | 8,3 Mo | archive OK, 191 entrées |
| `uploads-2026-08.tar.gz` | 8,0 Mo | archive OK, 139 entrées |

`wp db export` reste inutilisable sous CageFS : la base est prise par
`mysqldump --defaults-extra-file=`, le fichier d'identifiants créé en 600 et
détruit dans la foulée.

### Ce qui a été déployé, et rien d'autre

Les cinq fichiers de thème portaient **avant** écrasement l'empreinte SHA-256
exacte de `32175d1`, et **après** celle de `d9d32a2`. Aucun correctif appliqué à
la main en production n'a donc été perdu — la vérification a été faite dans les
deux sens.

| Cible | Contenu |
|---|---|
| `assets/images/seo-guides-v2/` | 72 WebP, 3,5 Mo |
| `assets/css/urbizen-guides.css` | règles `--planche` |
| `functions.php` | `URBIZEN_CHILD_VISUELS_ENTIERS`, `urbizen_child_visuel_entier()` |
| `patterns/guide-entete.php` | classe conditionnelle sur la figure |
| `templates/front-page.html` + `page-accueil-urbizen.html` | six cartes Guides |
| `~/urbizen-publication/` | `content/`, les deux scripts de publication |

Le **flush des règles de réécriture n'a pas été exécuté** : les 21 contenus
existaient déjà, la publication n'a produit que des mises à jour et aucun slug
nouveau. `wp cache flush` et `wp litespeed-purge all` ont suffi.

### Le verrou de vignette, à l'épreuve

La simulation intégrée des scripts sort **avant** la logique de vignette : elle
ne pouvait donc pas dire ce que le verrou allait décider. Un passage à blanc
dédié, en lecture seule, a rejoué la décision pour les dix-huit guides :

```
REMPLACE             17
DEJA BON              0
CONSERVE (admin)      1
FICHIER ABSENT        0
visuels distincts visés : 18 sur 18
```

Après publication : **18 vignettes distinctes, aucune partagée**, contre trois
guides sur une même planche DP2 et deux sur un même montage auparavant.

### La vignette conservée — et pourquoi elle n'a pas été écrasée

`piscine-garage-carport-autorisation` garde l'attachement **1206**
(`piscine-garage-carport-guide.webp`) au lieu de
`guide-piscine-garage-carport.webp`.

L'attachement ne porte **aucun** marqueur de provenance, et le verrou traite
cette absence comme un choix fait dans l'admin : il conserve et signale. C'est
le comportement voulu.

L'inspection montre pourtant que ce n'est **pas** un choix humain :

| Indice | Valeur |
|---|---|
| `post_author` | **0** — aucun utilisateur, donc création par script |
| `post_title` | « Piscine et carport d'une maison individuelle » — mot pour mot le manifeste |
| `_wp_attachment_image_alt` | identique au manifeste |
| Date | 14/08/2026 20:04, pendant la publication du lot 1 |

C'est un artefact d'une exécution antérieure à la convention de marqueur, sous un
nom de fichier différent. Le verrou ne peut pas le distinguer d'un choix humain,
et il a été laissé en place plutôt que forcé.

**Conséquence visible** : la carte de ce guide sur l'accueil sert le visuel v2,
la page du guide sert l'ancien. Pour aligner, poser le marqueur de provenance sur
l'attachement 1206 puis rejouer `publier-guides.php` — décision laissée ouverte,
non prise ici.

### Mots-clés

21/21 posés dans `keyphrases.focus`, conformes à `docs/SEO_CONTENT_MAP.md`.
Aucune ligne `keywords` non vide dans `aioseo_posts` : aucune balise
`meta keywords` n'est émise. Les six guides antérieurs n'en reçoivent pas — la
carte de contenu ne leur en attribue aucun, et en inventer un aurait été pire
qu'utile.

### Recette visuelle

1440 / 834 / 390 px, les dix-huit guides :

```
1440 px : 7 planche(s) · coupées 0 · cassées 0 · sans srcset 0 · débordement 0
 834 px : 7 planche(s) · coupées 0 · cassées 0 · sans srcset 0 · débordement 0
 390 px : 7 planche(s) · coupées 0 · cassées 0 · sans srcset 0 · débordement 0
```

**Le premier passage a annoncé 23 échecs, tous faux.** Le message se contredisait
— « photographie en cover — object-fit: contain » — et c'est ce qui a mis sur la
piste : le site appliquait bien `contain`, c'est la recette qui classait les
planches en photographies. En production, WordPress sert ses propres
déclinaisons (`guide-plan-masse-dp2-768x543.webp`) ; l'extraction du radical ne
retirait que les suffixes du kit. **L'instrument a été corrigé, pas le seuil.**

Confirmation indépendante dans le HTML servi : `guide-visuel guide-visuel--planche`
sur un guide-planche, `guide-visuel` seul sur un guide-photo.

### Accueil

Les six cartes servent le kit v2, les 24 fichiers répondent 200. La seule
référence restante à `/images/blog/` est l'illustration de l'étape 1, **laissée
volontairement** : elle partage un fichier avec une carte mais n'illustre pas un
guide.

Poids mesuré en production, contre les plafonds inchangés du lot F : 54,0 Ko pour
les six cartes en 352 px, contre 68,8 Ko auparavant.

### Résultat du tour complet

`bash tests/run-all.sh`, relancé de zéro après synchronisation, sans qu'aucun
fichier ne soit modifié pendant son exécution :

```
17 suite(s) exécutée(s) en 26 min 2 s
17 verte(s)
17/17 — tout est vert.
CODE_GLOBAL=0
```

Aucun seuil n'a été abaissé, aucun contrôle désactivé, aucun échec transformé en
`skip`. Le seul banc modifié au cours de ce lot est `test-seo-lot-f.mjs`, dont le
filtre passe du chemin au conteneur : ses quatre plafonds de poids sont
inchangés, et les cartes sont plus légères qu'avant.
