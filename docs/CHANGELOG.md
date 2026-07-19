# Journal des évolutions

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Ce fichier est mis à jour **dans le même commit** que le code qu'il décrit.

---

## [0.3.0] — 19 juillet 2026

Composant cadastre porté dans l'extension. **Rien n'est déployé en production**
et la page d'accueil publiée n'est pas modifiée.

### Ajouté
- `src/Blocks/CadastreBlock.php` : bloc Gutenberg `urbizen/cadastre` et
  shortcode `[urbizen_cadastre]`, partageant **exactement** le même rendu et le
  même enfilage. Rendu dynamique côté PHP ; `post_content` ne contient jamais
  d'adresse ni de parcelle.
- `assets/vendor/leaflet/` : Leaflet 1.9.4 embarqué (JS, CSS, images). Plus
  aucun appel à `unpkg.com`.
- `UrbizenCadastre.autoMount()` : montage automatique des conteneurs
  `[data-urbizen-cadastre]`, idempotent, options lues sur des `data-*`.
- `UrbizenCadastre.clearStored()` : effacement explicite des données de
  localisation conservées dans l'onglet.
- Repli `<noscript>` : le composant ne laisse jamais un conteneur muet.
- `tests/cadastre/` : deux bancs d'essai — comportement JavaScript sous jsdom
  (16 contrôles) et rendu PHP avec doublures WordPress (15 contrôles). Le
  répertoire `tests/` n'était pas utilisé jusqu'ici.
- `docs/AI_CONTEXT.md` : section sur les services publics externes, leurs points
  d'entrée et leurs limites.

### Modifié
- **Source de vérité unique** (D-008) : `urbizen-cadastre.js` et
  `urbizen-cadastre.css` vivent désormais dans l'extension. Les copies de
  `frontend/assets/` sont supprimées et le prototype de la page d'accueil
  référence les fichiers canoniques par chemin relatif.
- Le CSS porte une valeur de repli sur chacun de ses 64 `var(--u-*)` : le
  composant reste correct sans les tokens du thème, sans jamais les redéclarer
  dans `:root`.
- Messages d'erreur rendus plus explicites : « vérifiez votre connexion »,
  « vérifiez l'orthographe ou précisez la commune ».

### Sécurité
- **Suppression de tout `innerHTML`** sur des données d'API ou d'attributs : le
  DOM est construit par `createElement`, `textContent` et `setAttribute`. Une
  suggestion piégée de la Géoplateforme ne peut plus injecter de balise.
- **Identifiants HTML uniques par instance** : plusieurs composants cohabitent
  sur une page sans casser `for`, `aria-controls` ni `aria-activedescendant`.
- Attributs assainis et échappés côté PHP, hauteur de carte validée par
  expression régulière. L'échappement PHP ne remplace pas celui du JavaScript :
  les deux sont en place.

---

## [0.2.2] — 19 juillet 2026

Reproductibilité du backend Python. **Aucune logique métier modifiée.**

### Ajouté
- `backend/dp-service/requirements.txt` : les cinq dépendances réellement
  importées par le code — Flask, Flask-Cors, pypdf, reportlab, pillow — plus
  waitress pour la production. Majeures figées, correctifs acceptés.
- `.env.example` à la racine : les neuf variables lues par le service, avec
  leurs valeurs par défaut et des exemples fictifs. Aucune valeur sensible.

### Modifié
- `backend/dp-service/README.md` : mise en place en 4 étapes, environnement
  virtuel, chargement de la configuration, chemins corrigés (le document
  décrivait des fichiers comme adjacents alors qu'ils vivent dans `frontend/`),
  lancement Linux documenté, tableau exhaustif des variables d'environnement
  avec le fichier qui les lit.

---

## [0.2.1] — 19 juillet 2026

### Sécurité
- Coordonnées du serveur de production retirées de la documentation : le dépôt
  GitHub est **public**. `docs/AI_CONTEXT.md` et `docs/DECISIONS.md` utilisent
  désormais les variables `SSH_USER`, `SSH_HOST`, `SSH_PORT`, `WP_ROOT` et
  `URBIZEN_STORAGE_ROOT`. Les valeurs réelles vivent hors Git.

### Ajouté
- `docs/SESSION_HANDOFF.md` : passation de session — branche et PR courantes,
  état réel de la production, travaux terminés, contrôles, points de vigilance,
  prochaine étape et interdictions.

### Corrigé
- `docs/AI_CONTEXT.md` : le thème enfant n'est pas seulement déployé, il est
  **actif** en production.

---

## [0.2.0] — 19 juillet 2026

### Corrigé
- Nouveau correctif de compatibilité `urbizen_child_restore_theme_json()` :
  le thème parent écrasait, en priorité 999, la palette de couleurs et la police
  des titres. Le thème enfant les réapplique en priorité 1000, en les relisant
  depuis son propre `theme.json`.

### Ajouté
- Gabarits FSE du site exportés en fichiers versionnés dans
  `wordpress/urbizen-child/parts/` : `header.html`, `footer.html`,
  `footer-landing.html`, `superposition-de-navigation.html`.
- Styles globaux du site reportés dans `wordpress/urbizen-child/theme.json` :
  palette de 6 couleurs, styles de base et 5 502 caractères de CSS personnalisé.
- Documentation permanente du projet : `docs/PROJECT_MASTER_PLAN.md`,
  `docs/AI_CONTEXT.md`, `docs/DECISIONS.md`, `docs/CHANGELOG.md`, et réécriture
  de `docs/ROADMAP.md`.

### Modifié
- Le bloc de navigation de l'en-tête, qui référençait `wp_navigation` ID 15, est
  désormais **inliné** : le thème enfant ne dépend plus de la base pour son menu.
- `docs/ROADMAP.md` réaligné sur l'architecture réelle et l'avancement constaté.

### Vérifié en production
- Thème enfant **activé**, les 11 pages publiques en HTTP 200 et texte visible
  identique à la référence.
- Captures ordinateur (1440 px) et mobile (390 px) de l'accueil et de la page
  Contact : **pixel-identiques** à la référence, empreintes SHA-256 égales.
- 56 ressources contrôlées (CSS, JavaScript, images, polices) : **aucun 404**.
- En-tête, navigation, menu mobile et pied de page conservés, aucun gabarit par
  défaut du parent réapparu.
- Aucune erreur PHP, aucune erreur JavaScript de page.
- Options Hostinger intactes, aucune table créée, aucune écriture durable en
  base de données.

### Notes
- Le CSS personnalisé est repris **tel quel**, règles dupliquées et caractère
  parasite compris, afin de garantir l'équivalence visuelle. Nettoyage prévu lors
  de la refonte des pages.
- Le débordement horizontal de l'accueil sur mobile est **préexistant** : il est
  présent à l'identique avant et après activation, et sera traité à la refonte.
- Aucune page, option ou donnée Fluent Forms modifiée.

---

## [0.1.0] — 19 juillet 2026

### Ajouté
- Thème enfant `urbizen-child` : `style.css` sans règle, `theme.json` vide,
  structure `templates/`, `parts/`, `patterns/`, `assets/`, `languages/`.
- Extension `urbizen-platform` : chargement automatique PSR-4 sans Composer,
  contrôle d'environnement PHP 8.1 / WordPress 6.5, activation et désactivation
  sans effet de bord, désinstallation protégée par `URBIZEN_ALLOW_DATA_DELETION`.
- Bases `Support` : journal sans donnée personnelle, réglages centralisés,
  références de dossier `URB-AAAA-NNNN`.
- Arborescence des modules à venir : formulaires, HTTP, fichiers, soumissions,
  backend, RGPD, courriel, administration, shortcodes, blocs.

### Corrigé
- Compatibilité avec le thème parent `hostinger-ai-theme`, qui résout ses chemins
  avec `get_stylesheet_directory()` : pré-définition de ses constantes vers le
  répertoire parent, et repli automatique des URL d'assets absents du thème
  enfant. Sans ce correctif, l'activation du thème enfant produirait des 404 sur
  `style.min.css` et `front-scripts.min.js`.

### Vérifié en production
- Sauvegardes préalables : base 1,9 Mo pour 86 tables, fichiers 553 Mo pour
  35 421 fichiers, intégrité contrôlée.
- Extension activée sans aucune erreur PHP, sans créer de table ni d'option.
- Les 11 pages publiques répondent en HTTP 200, contenu identique.

### Connu
- L'activation du thème enfant a été testée puis **annulée** : les gabarits FSE
  étaient rattachés au thème parent en base. Corrigé en 0.2.0.
