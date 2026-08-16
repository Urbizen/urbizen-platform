# Publication des guides 3 à 7 — journal de déploiement

**15–16 août 2026.** Branche `feat/guides-urbizen-complets`.
Cinq articles publiés, un sixième mis à jour, six cartes d'accueil liées.

---

## 1 · Sauvegarde

Faite **avant toute mutation**, selon la procédure de `docs/AI_CONTEXT.md` § 4.1
(`wp db export` échoue sous CageFS, on passe par `mysqldump`).

```
~/backups/urbizen-guides-20260815-2224/
├── db.sql.gz                 2 113 533 o   intégrité vérifiée (gzip -t)
├── urbizen-child.tar.gz      4 256 928 o   intégrité vérifiée (tar tzf)
├── articles-avant.csv        inventaire des articles avant publication
└── page_for_posts-avant.txt  1204
```

**Retour arrière complet** — à n'exécuter qu'en cas de besoin :

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 u328261530@92.113.28.40
cd /home/u328261530/domains/urbizen.fr/public_html
DEST=~/backups/urbizen-guides-20260815-2224

# 1 · Thème enfant
tar xzf $DEST/urbizen-child.tar.gz -C .

# 2 · Base — écrase l'état courant, y compris tout contenu saisi depuis
CNF=~/.urbizen-restore.cnf; umask 077
wp eval 'printf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\n", DB_USER, DB_PASSWORD, DB_HOST);' > $CNF
chmod 600 $CNF
gunzip -c $DEST/db.sql.gz | mysql --defaults-extra-file=$CNF "$(wp eval 'echo DB_NAME;')"
rm -f $CNF

# 3 · Caches et règles de réécriture
wp litespeed-purge all && wp rewrite flush
```

**Retour arrière partiel**, si seuls les articles posent problème — il suffit de
les dépublier, les cartes de l'accueil pointant alors vers des pages inexistantes
(prévoir de redéployer le thème depuis `origin/main` dans la foulée) :

```bash
for s in dp-ou-permis-de-construire extension-maison-verifications-avant-plans \
         lire-le-plu-de-son-terrain erreurs-dossier-urbanisme \
         delais-urbanisme-debut-des-travaux; do
  wp post update "$(wp post list --post_type=post --name=$s --field=ID)" --post_status=draft
done
wp litespeed-purge all
```

---

## 2 · État constaté avant publication

| | |
|---|---|
| Thème actif | `urbizen-child` 0.3.0, parent `hostinger-ai-theme` 2.0.18 |
| Articles | 1 — `piscine-garage-carport-autorisation` (ID 1205) |
| Catégories | `autorisations-projets` (26), `regles-urbanisme` (27), `conseils-demarches` (28), `non-classe` (1) |
| `page_for_posts` | 1204 |
| Permaliens | `/guides/%postname%/` |
| URL contrôlées | `/`, `/guides/`, guide 1, `/declarations-prealables/`, `/permis-de-construire/`, `/conception/`, `/tarifs/`, `/sitemap.xml` — **toutes 200** |

---

## 3 · Ordre des opérations

1. **Sauvegarde** base + thème, intégrité vérifiée.
2. **Déploiement du thème** — `rsync -az --delete`, précédé d'un passage à blanc :
   aucune suppression, cinq ajouts (les schémas SVG).
3. **Publication** — `wp eval-file ~/urbizen-publication/publier-guides.php`,
   après un passage en `simulation` qui a confirmé cinq créations et une mise à
   jour.
4. **Purge** `wp litespeed-purge all` et `wp rewrite flush`.
5. **Contrôle** des douze URL publiques, du balisage, des images et des liens.
6. **Cartes de l'accueil** — modifiées **seulement après** que les cinq URL ont
   répondu 200, comme prévu. Trois fichiers tenus ensemble : maquette, gabarit,
   et `front-page.html` régénéré par `scripts/sync-front-page.py`.
7. **Correctifs** relevés au rendu — plan de site, puis quatre défauts de mise en
   page des schémas —, redéployés et repurgés.

### Le script de publication

`scripts/publier-guides.php`, versionné. Idempotent **par slug** : rejoué deux
fois, il laisse la base dans le même état. La recherche passe par
`get_page_by_path()`, qui voit aussi brouillons et corbeille — sans quoi un slug
déjà pris ressortirait suffixé « -2 ».

```bash
rsync -az content/guides/ <hôte>:~/urbizen-publication/guides/
rsync -az scripts/publier-guides.php <hôte>:~/urbizen-publication/
cd $WP_ROOT
URBIZEN_CONTENU=~/urbizen-publication/guides wp eval-file ~/urbizen-publication/publier-guides.php simulation
URBIZEN_CONTENU=~/urbizen-publication/guides wp eval-file ~/urbizen-publication/publier-guides.php
```

`content/guides/` reste la source de vérité : une correction faite dans
l'éditeur WordPress et non reportée au dépôt sera écrasée à la republication.

---

## 4 · Résultat

| Slug | ID | Vignette | Catégorie |
|---|---:|---:|---|
| `dp-ou-permis-de-construire` | 1210 | 1211 | Autorisations & projets |
| `extension-maison-verifications-avant-plans` | 1212 | 1213 | Autorisations & projets |
| `lire-le-plu-de-son-terrain` | 1214 | 1215 | Règles d'urbanisme |
| `erreurs-dossier-urbanisme` | 1216 | 1217 | Conseils & démarches |
| `delais-urbanisme-debut-des-travaux` | 1218 | 1219 | Conseils & démarches |
| `piscine-garage-carport-autorisation` | 1205 | 1206 *(inchangée)* | Autorisations & projets |

Aucun doublon créé : la médiathèque comptait un visuel de guide avant, elle en
compte six après.

---

## 5 · Deux corrections décidées pendant le déploiement

### 5.1 · Le plan de site annonçait des adresses `noindex`

Constaté aussitôt après publication : `sitemap.xml` annonçait
`category-sitemap.xml`, qui listait les trois rubriques — toutes servies en
`noindex`. Contradiction : un plan de site est une invitation à explorer.

**Première tentative, écartée.** `aioseo_sitemap_exclude_terms` : le greffon s'en
sert aussi pour écarter les **articles** rattachés aux termes exclus.
`post-sitemap.xml` est tombé de sept entrées à une. Revenu en arrière avant
d'aller plus loin.

**Retenu.** `aioseo_sitemap_indexes` seul : l'index n'annonce plus le plan de
site des catégories. Les articles y restent tous. Le retrait est conditionnel —
une rubrique future, étrangère aux guides, y reviendrait d'elle-même.

Vérifié après déploiement : index à deux plans (`post`, `page`),
`post-sitemap.xml` à sept entrées — `/guides/` et les six articles.

### 5.2 · Quatre défauts de mise en page des schémas

Invisibles à la lecture du code, tous vus sur un rendu : une réserve de légende
coupée en plein mot, un cadre trop étroit de deux pixels, un total traversé par
le trait du seuil, un repère chevauchant la ligne voisine. Corrigés, redéployés,
et trois contrôles ajoutés à la recette — dont un que les autres n'auraient pas
trouvé, le texte restant dans le viewBox tout en sortant de sa boîte.

---

## 6 · Contrôles publics

| Contrôle | Résultat |
|---|---|
| Douze URL publiques | **200** (accueil, index, six guides, quatre pages commerciales) |
| `title`, `description` | uniques, 150 à 159 caractères |
| `canonical` | autonome sur chaque guide |
| `<h1>` | **un seul** par page, aux cinq largeurs |
| `BlogPosting` · `BreadcrumbList` | présents sur les six guides |
| Images et liens internes | **17 URL distinctes, toutes 200** |
| Plan de site des articles | sept entrées, aucune catégorie |
| Archives de catégories | `noindex` — et hors du plan de site |
| Débordement horizontal | **aucun**, de 1440 à 360 px |
| Erreurs JavaScript | **aucune** sur les douze pages, aux cinq largeurs |
| Cartes de l'accueil | six liées, atteignables au clavier, nom accessible porté par le titre |
| Sans JavaScript | accueil, index et un guide servis, navigation et H1 intacts |
| Cache CSS/JS LiteSpeed | **sans objet** — minification, combinaison et UCSS sont désactivées sur ce site (`optm-css_min`, `optm-js_min`, `optm-css_comb`, `optm-js_comb`, `optm-ucss` tous à 0), il n'existe donc aucun cache CSS/JS généré à purger ; `wp litespeed-purge all` suffit |

### Deux constats qui ne sont pas des défauts

Relevés par la recette, vérifiés un par un, et laissés tels quels :

- **« images cassées » sur l'accueil.** Onze images ont `naturalWidth === 0`.
  Elles sont en `loading="lazy"` dans les panneaux repliés de l'explorateur de
  dossier, et ne sont donc jamais chargées tant qu'on ne les ouvre pas.
  **Les onze répondent 200** en HTTP.
- **« éléments hors cadre » dans les guides et sur l'accueil.** Schémas, tableaux
  et bandeau d'étapes dépassent la fenêtre — c'est voulu : ils défilent dans
  leur propre conteneur en `overflow-x: auto`, et ce conteneur reste dans la
  page. Mesuré : `scrollWidth === clientWidth` à 390 comme à 360 px.

---

## 7 · Ce qui reste à surveiller

- **Indexation.** Les cinq URL sont neuves ; leur prise en compte demandera
  quelques jours. Le plan de site est à jour et propre.
- **Liens descendants DP/PC → guides.** Prévus depuis le plan du 14 août, ils
  restent à poser dans le lot commercial dédié — ils ne font pas partie de
  celui-ci.
- **Deux ajouts utiles aux pages commerciales**, signalés le 14 août et toujours
  ouverts : le carport absent du tableau de la page DP, et le local technique de
  piscine.
