# Contexte de reprise — à lire en premier

> Ce document permet à une IA (Claude Code, Claude, ChatGPT) ou à un développeur
> de reprendre le projet **sans connaître l'historique des conversations**.
> Il décrit l'état réel, les pièges vérifiés et les règles de travail.

Dernière mise à jour : 19 juillet 2026.

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

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 u328261530@92.113.28.40
```

| Élément | Valeur |
|---|---|
| Racine WordPress | `/home/u328261530/domains/urbizen.fr/public_html` |
| Sauvegardes | `~/backups/` |
| Serveur | `fr-int-web1589.main-hosting.eu`, CloudLinux + CageFS |
| PHP | 8.3.30 · WP-CLI 2.12.0 · WordPress 7.0.2 · base `wp_` |
| Site | <https://urbizen.fr> |

**Ne jamais afficher le contenu de `wp-config.php`** : il contient les
identifiants de base de données et les clés de salage.

---

## 3. État réel de l'installation

**Thème** : `hostinger-ai-theme` 2.0.18, thème **FSE** fourni par l'hébergeur,
mise à jour 2.0.29 disponible, auto-updates actifs via mu-plugin. Le thème enfant
`urbizen-child` est déployé.

**Extensions actives notables** : Fluent Forms 6.2.5 (6 formulaires, 4 entrées),
Fluent SMTP, All in One SEO, LiteSpeed Cache, Kadence Blocks, Google Site Kit,
MonsterInsights, plus les extensions Hostinger. WooCommerce est **inactif** mais
ses 4 pages boutique sont publiées.

**Extension Urbizen** : `urbizen-platform` 0.1.0, **active**, sans aucun module
chargé — ni table, ni option, ni route REST, ni formulaire.

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

## 5. Règles de travail non négociables

1. **Sauvegarder avant d'agir** : base et fichiers, intégrité vérifiée.
2. **Vérifier après chaque action** : voir le protocole du plan directeur, §7.
3. **Retour arrière immédiat** au moindre écart, puis rapport.
4. Ne jamais modifier le thème parent `hostinger-ai-theme`.
5. Ne jamais toucher aux données Fluent Forms sans plan de migration.
6. Ne jamais placer de donnée personnelle ni de secret dans Git.
7. Ne jamais pousser directement sur `main`.
8. Documentation mise à jour **dans le même commit** que le code.

---

## 6. Boucle de vérification après déploiement

```bash
# 1. Lint PHP — pas de PHP en local, on lint via le serveur
for f in $(find wordpress -name '*.php'); do
  ssh -i ~/.ssh/urbizen_hostinger -p 65002 u328261530@92.113.28.40 'php -l' < "$f"
done

# 2. Déploiement
rsync -az --delete -e "ssh -i ~/.ssh/urbizen_hostinger -p 65002" \
  wordpress/urbizen-child/ \
  u328261530@92.113.28.40:domains/urbizen.fr/public_html/wp-content/themes/urbizen-child/

# 3. Activation puis purge du cache
wp theme activate urbizen-child && wp litespeed-purge all

# 4. Contrôles : HTTP 200, texte visible, assets sans 404, captures écran
#    ordinateur et mobile, en-tête, menu mobile, pied de page, débordements

# 5. Retour arrière si écart
wp theme activate hostinger-ai-theme && wp litespeed-purge all
```

---

## 7. Cartographie du dépôt

```
backend/dp-service/      service Python : Cerfa, notice, bordereau, assemblage PDF
frontend/homepage/       maquette HTML/CSS/JS de l'accueil (référence de refonte)
frontend/formulaires/    formulaires DP et PCMI de référence (à porter dans l'extension)
frontend/assets/         urbizen-tokens.css, composant cadastre (CSS + JS)
wordpress/urbizen-child/     thème enfant : rendu et gabarits
wordpress/urbizen-platform/  extension : toute la logique métier
docs/                    documentation du projet
tests/ scripts/          à créer
```

---

## 8. Points ouverts connus

- Les mappings Cerfa de `backend/dp-service/cerfa.py` sont tous en `TODO_` :
  aucun champ n'est réellement rempli aujourd'hui.
- `POST /api/dp` du service Python n'est pas authentifié et son CORS vaut `*`.
- `requirements.txt` et `.env.example` sont absents du dépôt.
- Les CGV sont publiées sur le slug `refund_returns` : à corriger avec une
  redirection 301, jamais sans.
- Les 4 entrées Fluent Forms sont des données personnelles réelles : à exporter
  chiffrées, hors dépôt, avant toute désactivation.
