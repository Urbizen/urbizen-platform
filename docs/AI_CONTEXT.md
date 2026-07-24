# Contexte de reprise — à lire en premier

> Ce document permet à une IA (Claude Code, Claude, ChatGPT) ou à un développeur
> de reprendre le projet **sans connaître l'historique des conversations**.
> Il décrit l'état réel, les pièges vérifiés et les règles de travail.

Dernière mise à jour : 24 juillet 2026.

---

## 1. En trois phrases

Urbizen prépare des dossiers d'urbanisme français (déclaration préalable, permis
de construire). Le site public est un WordPress hébergé chez Hostinger, en cours
de reprise en main : la logique métier migre vers une extension maison
`urbizen-platform`, le rendu vers un thème enfant `urbizen-child`, et la
génération documentaire reste dans un service Python séparé. Fluent Forms est
abandonné, mais **pas encore désinstallé**.

Lire ensuite [PROJECT_MASTER_PLAN.md](PROJECT_MASTER_PLAN.md), qui fait foi.

---

## 2. Accès à la production

Les coordonnées exactes du serveur ne sont **pas versionnées** : ce dépôt est
public. Elles sont conservées hors Git, dans la mémoire projet de l'assistant et
dans le gestionnaire de mots de passe. Renseigner ces variables avant usage :

```bash
export SSH_USER=…                                  # compte SSH Hostinger
export SSH_HOST=…                                  # adresse du serveur
export SSH_PORT=65002
export WP_ROOT="domains/urbizen.fr/public_html"    # relatif au répertoire personnel
export URBIZEN_STORAGE_ROOT="urbizen-storage"      # stockage privé, hors racine web

ssh -i ~/.ssh/urbizen_hostinger -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}"
cd "${WP_ROOT}"
```

| Élément | Valeur |
|---|---|
| Racine WordPress | `${WP_ROOT}` |
| Stockage privé | `${URBIZEN_STORAGE_ROOT}` |
| Sauvegardes | `~/backups/` |
| Hébergeur | Hostinger, CloudLinux + CageFS |
| PHP | 8.3.30 · WP-CLI 2.12.0 · WordPress 7.0.2 · base `wp_` |
| Site | <https://urbizen.fr> |

**Ne jamais afficher le contenu de `wp-config.php`** : il contient les
identifiants de base de données et les clés de salage.

---

## 3. État réel de l'installation

**Thème** : `hostinger-ai-theme` 2.0.18, thème **FSE** fourni par l'hébergeur,
mise à jour 2.0.29 disponible, auto-updates actifs via mu-plugin. Le thème enfant
`urbizen-child` 0.1.0 est déployé et **actif** ; le thème Hostinger est devenu son
parent.

**Extensions actives notables** : Fluent Forms 6.2.5 (6 formulaires, 4 entrées),
Fluent SMTP, All in One SEO, LiteSpeed Cache, Kadence Blocks, Google Site Kit,
MonsterInsights, plus les extensions Hostinger. WooCommerce est **inactif** mais
ses 4 pages boutique sont publiées.

**Extension Urbizen** : deux numéros de version à ne pas confondre.

- **`main` : `0.12.0`** — parcours public des comptes (E2.2, D-046), **fusionné
  par la PR #26**. C'est l'état du dépôt, **non déployé**.
- **Dernière production constatée : `0.10.0`** — état observé lors du dernier
  accès. La production **n'a pas été recontactée** : ce numéro n'est pas
  revérifié ici, il est reporté tel qu'il était constaté.

**`0.12.0` n'est pas déployée.** Son déploiement suivra le protocole
[DEPLOY_ACCOUNTS_0_12.md](DEPLOY_ACCOUNTS_0_12.md), sous autorisation distincte.

L'extension ne crée **ni table propre, ni route REST**. En revanche elle emploie
désormais des **options** (limitation de débit, jetons anti-robot) et des
métadonnées utilisateur privées (préfixe `_urbizen_`) pour les comptes :
l'ancienne mention « ni option » ne valait que pour 0.4.0. Les shortcodes du
parcours des comptes existent dans le code de `0.12.0` ; `main` n'étant **pas
déployée**, E2.2 n'est **pas visible** sur le site. La livraison `0.12.0` ne crée
ni ne publie aucune page WordPress ; l'exposition des shortcodes reste un geste
d'exploitation distinct.

**Aucune extension de sécurité applicative** : la protection est assurée par
Imunify360 côté hébergeur. Un seul compte administrateur.

---

## 4. Pièges vérifiés en conditions réelles

### 4.1 `wp db export` est cassé sur cet hébergement

Erreur fatale PHP silencieuse, code de sortie 255 — les fonctions d'exécution
sont bridées par CageFS. Utiliser `mysqldump` avec un fichier d'identifiants
temporaire, jamais affiché et détruit après usage :

```bash
CNF=~/.urbizen-dump.cnf; umask 077
wp eval 'printf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\n", DB_USER, DB_PASSWORD, DB_HOST);' > $CNF
chmod 600 $CNF
mysqldump --defaults-extra-file=$CNF --single-transaction --quick --add-drop-table \
  --default-character-set=utf8mb4 "$(wp eval 'echo DB_NAME;')" > ~/backups/urbizen-db-$(date +%Y%m%d-%H%M).sql
rm -f $CNF && gzip ~/backups/urbizen-db-*.sql
```

### 4.2 Le thème parent résout ses chemins avec `get_stylesheet_directory()`

`includes/Assets.php` du parent enregistre `style.min.css` et
`front-scripts.min.js` avec `get_stylesheet_directory_uri()`, qui pointe vers le
thème **enfant** dès que celui-ci est actif : les assets partiraient en 404.

Corrigé dans `wordpress/urbizen-child/functions.php` par deux mécanismes :
- pré-définition de `HOSTINGER_AI_WEBSITES_THEME_PATH` et
  `HOSTINGER_AI_WEBSITES_ASSETS_URL` vers le répertoire du parent — possible car
  WordPress charge le `functions.php` de l'enfant **avant** celui du parent, et
  le parent protège ses définitions par `if ( ! defined() )` ;
- filtres `style_loader_src` / `script_loader_src` qui rebasculent vers le thème
  parent toute URL d'asset absente du thème enfant.

**Ne pas retirer ces correctifs.**

### 4.3 Les gabarits FSE personnalisés étaient rattachés au thème parent

En-tête, pied de page et styles globaux du site n'étaient pas des fichiers mais
des enregistrements `wp_template_part` et `wp_global_styles`, liés au terme
`wp_theme` valant `hostinger-ai-theme`. Activer le thème enfant les orphelinait :
le menu et tout le pied de page disparaissaient au profit des gabarits par défaut
(`trans-menu`, `email@email.com`), soit 489 caractères de texte visible perdus
sur chacune des 11 pages.

Résolu par export en fichiers dans `wordpress/urbizen-child/parts/` :
`header.html`, `footer.html`, `footer-landing.html`,
`superposition-de-navigation.html`, plus le report des styles globaux dans
`theme.json`. Le bloc de navigation, qui pointait vers `wp_navigation` **ID 15**,
a été **inliné** : plus aucune dépendance à la base.

Le bloc `wp:site-logo` reste dépendant de l'option `site_logo` : c'est de
l'identité de site, donc une donnée d'exploitation, pas de la configuration
technique.

### 4.4 Le thème parent écrase la palette et la police des titres

`includes/Builder/WebsiteBuilder.php` accroche `update_theme_json` au filtre
`wp_theme_json_data_theme` en **priorité 999** et y remplace :

- `settings.color`, par une palette lue dans l'option `hostinger_ai_colors` ;
- `styles.elements.heading.typography.fontFamily`, recalculé par
  `Fonts::get_main_font()`, qui sous thème enfant ne retrouve pas les familles
  de polices et retombe sur `system-ui`.

Sous le thème parent, les styles globaux **utilisateur** en base reprenaient la
main sur les couleurs. Rattachés au thème parent, ils ne suivent pas le thème
enfant. Sans correctif, le site repassait sur la palette sombre de Hostinger
(`color2` valant `#2C2A29`) : fonds noirs, textes illisibles, boutons verts sur
vert — constaté en production, puis annulé en moins d'une minute.

Corrigé par `urbizen_child_restore_theme_json()` dans le `functions.php` de
l'enfant, accroché au même filtre en **priorité 1000**. Il relit la palette et
la police des titres depuis le `theme.json` de l'enfant : source unique de
vérité, aucune valeur dupliquée dans le PHP.

Deux pièges annexes vérifiés :
- dans `theme.json`, `settings.color.palette` doit être un **tableau**. La forme
  `{"theme": [...], "custom": [...]}` provient des styles globaux utilisateur et
  est silencieusement ignorée ;
- WordPress normalise les identifiants de palette en kebab-case : le slug
  `color1` devient la variable `--wp--preset--color--color-1`.

**Ne pas retirer ce correctif.**

### 4.5 Le CSS personnalisé repris est imparfait

Les 5 502 caractères de `styles.css` du `theme.json` contiennent des règles
dupliquées et un caractère parasite, hérités de l'éditeur. Ils ont été repris
**tels quels** pour garantir l'équivalence visuelle. À nettoyer lors de la
refonte des pages, pas avant.

---

## 5. Services publics externes utilisés

Le composant cadastre est le seul module à appeler des services tiers. Tous sont
**publics, gratuits et sans clé d'API**. Aucun secret n'est donc requis, mais
chacun a ses limites.

| Service | Point d'entrée | Usage | Limites connues |
|---|---|---|---|
| Géocodage IGN — complétion | `data.geopf.fr/geocodage/completion` | autocomplétion d'adresse | quota d'usage raisonnable, non contractuel ; 7 réponses demandées, saisie ≥ 3 caractères, requêtes espacées de 260 ms |
| Géocodage IGN — recherche | `data.geopf.fr/geocodage/search` | coordonnées canoniques et code INSEE | même quota ; en cas d'échec, repli sur les coordonnées de l'autocomplétion |
| Tuiles WMTS IGN | `data.geopf.fr/wmts` | photo aérienne, plan IGN v2, parcellaire express | zoom maximal 20 ; couverture et fraîcheur variables selon les communes |
| API Carto — cadastre | `apicarto.ign.fr/api/cadastre/parcelle` | géométrie et références de parcelle (source PCI) | **parcellaire mis à jour environ 2 fois par an** ; la contenance est une **surface cadastrale indicative**, jamais une surface arpentée |

Trois conséquences à retenir :

1. **Aucune donnée n'est envoyée à un serveur Urbizen** par ce composant. Les
   requêtes partent du navigateur du visiteur vers l'IGN. L'adresse saisie et la
   parcelle confirmée restent dans l'onglet (`sessionStorage`, clé préfixée
   `urbizen:`), effaçables par `UrbizenCadastre.clearStored()`.
   *Ceci vaut tant que D-047 n'est pas mise en œuvre : son lot 1 fait récupérer la
   géométrie par le serveur, à partir du seul identifiant de parcelle, et son lot 3
   ajoute Panoramax à la liste des services externes. Ce constat devra alors être
   réécrit.*
2. **Toute panne se voit** : délai maximal de 8 s par requête, puis message
   explicite — recherche indisponible, aucune adresse trouvée, carte
   momentanément indisponible, lecture de parcelle impossible.
3. `api-adresse.data.gouv.fr` n'est **pas** utilisé : consigne de projet, la
   Géoplateforme IGN fait foi.

Leaflet 1.9.4 est **embarqué dans le dépôt** (`assets/vendor/leaflet/`), jamais
chargé depuis un CDN : aucune adresse IP de visiteur ne part chez un tiers.

---

## 6. Contrat de données cadastre → formulaire

Le composant cadastre publie un **contrat canonique 1.0**, imbriqué et
versionné (D-009). C'est la seule structure publiée : événement
`urbizen:parcel-confirmed` et `sessionStorage` portent le même objet, produit
par une fabrique unique.

```
schemaVersion  "1.0"
source         "urbizen-cadastre"        décrit Urbizen, pas le fournisseur
confirmedAt    ISO 8601                  confirmation par la personne
address        label, houseNumber, street, postcode, city, cityCode
location       latitude, longitude       nombres finis, ou null
parcel         communeCode, prefix, section, number, id, surfaceM2
```

Cinq points à ne pas oublier :

1. **Pas de géométrie.** Exclue du contrat 1.0, faute d'usage en aval. Elle
   reste en interne pour le tracé sur la carte. D-047 ne rouvre pas ce contrat :
   la géométrie sera refaite côté serveur depuis l'identifiant, jamais transmise
   par le navigateur.
2. **Deux codes commune distincts** : `address.cityCode` vient du géocodeur,
   `parcel.communeCode` de la parcelle. Jamais fusionnés, jamais substitués.
   Une divergence est signalée, pas corrigée.
3. **`surfaceM2` est indicative** : surface cadastrale, pas surface de terrain.
   Un projet peut couvrir plusieurs parcelles.
4. **Aucune valeur fabriquée** : chaîne vide ou `null`, jamais un repli inventé.
   Les codes, section, numéro et identifiant sont validés par expression
   régulière — **jamais tronqués**. Attention au cas corse : le code INSEE de
   Bastia est `2B033`, une règle « 5 chiffres » rejetterait toute la Corse.
5. **Aucune propriété plate** n'est conservée : vérifié, aucun consommateur ne
   lisait l'ancien format.

Le formulaire lit **la seule clé de stockage qu'on lui a désignée**. Parcourir
les clés `urbizen:*` pour choisir une parcelle au hasard est interdit.

Le payload plat de la 0.3.0 encore présent dans un onglet est **ignoré**, sans
migration ni effacement automatique : il faut confirmer à nouveau la parcelle.

En version 0.4.0, **aucune donnée ne quitte le navigateur** : la validation est
locale et publie `urbizen:location-form-validated`. Le futur point de
soumission serveur devra tout revalider — les champs masqués viennent du
navigateur.

---

## 7. Règles de travail non négociables

1. **Sauvegarder avant d'agir** : base et fichiers, intégrité vérifiée.
2. **Vérifier après chaque action** : voir le protocole du plan directeur, §7.
3. **Retour arrière immédiat** au moindre écart, puis rapport.
4. Ne jamais modifier le thème parent `hostinger-ai-theme`.
5. Ne jamais toucher aux données Fluent Forms sans plan de migration.
6. Ne jamais placer de donnée personnelle ni de secret dans Git.
7. Ne jamais pousser directement sur `main`.
8. Documentation mise à jour **dans le même commit** que le code.

---

## 8. Boucle de vérification après déploiement

```bash
# 1. Lint PHP — pas de PHP en local, on lint via le serveur
for f in $(find wordpress -name '*.php'); do
  ssh -i ~/.ssh/urbizen_hostinger -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}" 'php -l' < "$f"
done

# 2. Déploiement
rsync -az --delete -e "ssh -i ~/.ssh/urbizen_hostinger -p ${SSH_PORT}" \
  wordpress/urbizen-child/ \
  "${SSH_USER}@${SSH_HOST}:${WP_ROOT}/wp-content/themes/urbizen-child/"

# 3. Activation puis purge du cache
wp theme activate urbizen-child && wp litespeed-purge all

# 4. Contrôles : HTTP 200, texte visible, assets sans 404, captures écran
#    ordinateur et mobile, en-tête, menu mobile, pied de page, débordements

# 5. Retour arrière si écart
wp theme activate hostinger-ai-theme && wp litespeed-purge all
```

---

## 9. Cartographie du dépôt

```
backend/dp-service/      service Python : Cerfa, notice, bordereau, assemblage PDF
frontend/homepage/       maquette HTML/CSS/JS de l'accueil (référence de refonte)
frontend/formulaires/    formulaires DP et PCMI de référence (à porter dans l'extension)
frontend/assets/         urbizen-tokens.css (charte, à porter dans le thème)
wordpress/urbizen-child/     thème enfant : rendu et gabarits
wordpress/urbizen-platform/  extension : toute la logique métier
  assets/js|css/             composant cadastre — SOURCE DE VÉRITÉ UNIQUE (D-008)
  assets/vendor/leaflet/     Leaflet 1.9.4 embarqué, jamais de CDN
  src/Blocks/                blocs Gutenberg et shortcodes (cadastre, formulaire)
  src/Forms/                 définitions déclaratives et rendu des formulaires
docs/                    documentation du projet
tests/cadastre/          bancs d'essai : cadastre, formulaire, rendu PHP
scripts/                 à créer
```

---

## 10. Points ouverts connus

- Les mappings Cerfa de `backend/dp-service/cerfa.py` sont tous en `TODO_` :
  aucun champ n'est réellement rempli aujourd'hui.
- `POST /api/dp` du service Python n'est pas authentifié et son CORS vaut `*`.
- `requirements.txt` et `.env.example` sont absents du dépôt.
- Les CGV sont publiées sur le slug `refund_returns` : à corriger avec une
  redirection 301, jamais sans.
- Les 4 entrées Fluent Forms sont des données personnelles réelles : à exporter
  chiffrées, hors dépôt, avant toute désactivation.
- Aucune pièce graphique (DP1–DP8, PCMI1–PCMI8) n'est produite à ce jour, et
  l'extension n'appelle jamais `backend/dp-service/` : `src/Backend/` est vide.
- La nomenclature DP6/DP7/DP8 de `frontend/formulaires/dp-formulaire.html` est
  décalée d'un rang par rapport à `backend/dp-service/documents.py`, qui fait foi ;
  `_image_to_pdf()` détruit par ailleurs l'EXIF utile (orientation et données GPS),
  et le HEIC n'est pas pris en charge.
- D-047 documente l'architecture cible de la préparation assistée des pièces ;
  aucun de ses lots n'est implémenté à ce jour.
