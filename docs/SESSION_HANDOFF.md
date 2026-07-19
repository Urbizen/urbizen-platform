# Passation de session

Photographie de l'état du projet à la fin d'une session de travail, pour qu'une
reprise — humaine ou assistée — parte du réel et non d'une supposition.
Ce fichier est **réécrit à chaque fin de session**, jamais complété par empilement.

Contexte durable et règles de travail : [AI_CONTEXT.md](AI_CONTEXT.md).
Architecture et cap du projet : [PROJECT_MASTER_PLAN.md](PROJECT_MASTER_PLAN.md).

---

## Session du 19 juillet 2026

### Branche et Pull Request

| Élément | Valeur |
|---|---|
| Branche courante | `feature/form-cadastre-integration` |
| Pull Request courante | **à ouvrir** vers `main` |
| Socle WordPress | PR [#4](https://github.com/Urbizen/urbizen-platform/pull/4) fusionnée — merge `5989ba9` |
| Reproductibilité backend | PR [#5](https://github.com/Urbizen/urbizen-platform/pull/5) fusionnée — merge `8214ae6` |
| Composant cadastre | PR [#6](https://github.com/Urbizen/urbizen-platform/pull/6) fusionnée — merge `639e131` |
| Dépôt | `Urbizen/urbizen-platform` — **public** |

### Où en est le projet

L'étape 1 de l'intégration WordPress est **terminée, fusionnée dans `main` et en
production**. Le socle est posé — thème enfant et extension — mais il ne porte
encore **aucune logique métier** : ni formulaire, ni cadastre, ni route REST.

Le composant cadastre est **fusionné dans `main` et déployé en production en
0.3.0**, validé en conditions réelles le 19/07/2026.

La branche en cours prépare la **0.4.0** : contrat de données canonique 1.0 et
premier formulaire Urbizen, qui reprend la localisation confirmée. **Rien n'est
déployé** — la production reste en 0.3.0 — et **aucune donnée ne quitte le
navigateur** : la validation est locale.

### Ce qui a été fait

- Amorçage du thème enfant `urbizen-child` et de l'extension `urbizen-platform`.
- Export des gabarits FSE de production en fichiers versionnés (`parts/`), et
  report des styles globaux dans `theme.json` : le rendu ne dépend plus de la base.
- Correctif `urbizen_child_restore_theme_json()` — sans lui, l'activation du thème
  enfant fait repasser le site sur la palette sombre de Hostinger.
- Documentation permanente du projet ouverte (plan directeur, contexte, décisions,
  changelog, feuille de route, ce fichier).
- Audit complet du WordPress de production, en lecture seule.
- Anonymisation des coordonnées serveur dans la documentation : **le dépôt GitHub
  est public**, compte et adresse ne doivent jamais y figurer.
- Reproductibilité du backend soldée : `requirements.txt`, `.env.example` et
  documentation de lancement local corrigée.
- Composant cadastre porté dans l'extension : bloc `urbizen/cadastre` et
  shortcode `[urbizen_cadastre]` au rendu commun, Leaflet 1.9.4 embarqué avec
  sa licence BSD 2-Clause, `innerHTML` supprimés, identifiants uniques par
  instance, `clearStored()` et `destroy()`.
- Interface d'édition Gutenberg ajoutée après revue : `block.json` comme
  déclaration unique des attributs, `editor.js`, `editor.css`, aperçu statique
  sans appel IGN ni carte Leaflet.
- Version du plugin portée à **0.3.0**, handles versionnés pour casser les
  caches navigateur et LiteSpeed.
- Premiers tests automatiques du projet : `tests/cadastre/`.

### État vérifié de la production

| Élément | Valeur |
|---|---|
| WordPress · PHP · WP-CLI | 7.0.2 fr_FR · 8.3.30 · 2.12.0 |
| Thème actif | `urbizen-child` 0.1.0 (parent `hostinger-ai-theme` 2.0.18) — inchangé |
| Extension Urbizen | `urbizen-platform` **0.3.0**, active, module cadastre chargé (la 0.4.0 n'est **pas** déployée) |
| Page de test | ID 1157, **brouillon**, non indexée, à supprimer après revue |
| Rendu | validé identique — captures 1440 px et 390 px, empreintes SHA-256 égales |
| Réponse HTTP | 200 |
| Effets de bord | aucun : ni table, ni option, ni fichier créé |

Sauvegardes disponibles dans `~/backups/` : base et fichiers du 19/07/2026.

### Contrôles effectués

- Les 5 commits attendus présents sur `origin`, branche sans conflit avec `main`.
- Aucun secret, identifiant, fichier local ni sauvegarde dans le dépôt.
- `php -l` conforme sur les **11 fichiers PHP**, `theme.json` valide (version 3).
- Aucun effet de bord non documenté : ni `add_option`, ni `dbDelta`, ni
  `CREATE TABLE`, ni écriture de fichier, ni appel réseau.
- Rendu comparé avant/après activation : identique.
- Backend : installation des dépendances validée dans un venv neuf (Python 3.13),
  imports `documents`, `cerfa` et `app` opérationnels, `GET /api/health` → 200,
  `POST /api/dp` sans champ → 400 avec la liste des champs manquants.
- Aucun secret dans les fichiers ajoutés : `.env.example` ne contient que des
  noms de variables et des exemples fictifs.
- Cadastre : 12 fichiers PHP au lint sans erreur ; syntaxe JS et `block.json`
  validés ; **32 contrôles JavaScript** sous jsdom et **36 contrôles de rendu
  PHP** avec doublures, tous verts ; aucune référence CDN ; images de
  `leaflet.css` toutes présentes.
- **0.4.0 (branche en cours)** : 74 contrôles JavaScript et 39 contrôles de
  rendu PHP pour le formulaire, plus 32 + 36 pour le cadastre en
  non-régression — **181 au total, tous verts**. Ces contrôles sont **simulés** : le formulaire n'a pas encore été
  ouvert dans un vrai éditeur ni essayé sur le site.
- **Test WordPress réel du 19/07/2026** : 13 contrôles passés dans l'éditeur et
  sur le site public — insertion, réglages, enregistrement, rechargement sans
  erreur de validation, rendu, absence de 404, autocomplétion IGN, parcelle
  confirmée, `sessionStorage`, mobile, isolation des assets. Aucune erreur PHP
  ni JavaScript imputable au composant. Détail dans `CHANGELOG.md` 0.3.0.

### Prochaine étape

**Revue de la 0.4.0**, puis validation réelle du formulaire sur la page de test
1157, conservée à cet effet : insertion du bloc formulaire à côté du bloc
cadastre, reprise de la localisation, correction d'adresse, effacement.

Ensuite, la **validation serveur** : c'est le vrai chantier suivant. La 0.4.0
valide uniquement côté navigateur ; toute soumission devra revalider
l'intégralité des champs, sans faire confiance aux champs masqués.

Restent ouverts : l'auto-hébergement des polices (Google Fonts est encore
appelé par le thème) et la reprise du composant dans les pages publiques —
aucune page publiée ne l'utilise à ce jour.

### Interdictions

1. **Ne pas publier la page de test 1157** : elle reste en brouillon, puis sera
   supprimée. Aucune page publiée n'utilise encore le composant.
2. Ne pas fusionner de branche sans revue ni sauvegarde préalable.
3. Ne jamais pousser directement sur `main`.
4. Ne jamais versionner de coordonnée serveur, de secret, de donnée personnelle
   ni de sauvegarde : le dépôt est public.
5. Ne jamais afficher le contenu de `wp-config.php`.
6. Ne rien modifier en production via l'éditeur de fichiers de WordPress.

### Points de vigilance pour la reprise

1. **Palette et filtre du parent** — le thème parent écrase palette et police des
   titres via `wp_theme_json_data_theme` en priorité 999 ; l'enfant les réapplique
   en priorité 1000. Recontrôler le rendu après toute mise à jour du parent
   (2.0.29 disponible).
2. **Gabarits en base** — `wp_template_part` et `wp_global_styles` restent
   rattachés au terme `wp_theme` du **parent**. Ne pas s'appuyer dessus : la
   source de vérité est le dossier `parts/` du thème enfant.
3. **Dépôt public** — aucune coordonnée serveur, aucune donnée personnelle,
   aucune sauvegarde dans Git.
4. **Données réelles** — les 4 entrées Fluent Forms sont des données personnelles :
   les exporter chiffrées, hors dépôt, avant toute désactivation du plugin.
5. **`wp db export` échoue sous CageFS** — passer par `mysqldump` avec un fichier
   d'identifiants temporaire en mode 600, détruit par `shred -u` après usage.
