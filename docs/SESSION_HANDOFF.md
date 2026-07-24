# Passation de session

Photographie de l'état du projet à la fin d'une session de travail, pour qu'une
reprise — humaine ou assistée — parte du réel et non d'une supposition.
Ce fichier est **réécrit à chaque fin de session**, jamais complété par empilement.

Contexte durable et règles de travail : [AI_CONTEXT.md](AI_CONTEXT.md).
Architecture et cap du projet : [PROJECT_MASTER_PLAN.md](PROJECT_MASTER_PLAN.md).

---

## Session du 24 juillet 2026

### Point de reprise

| Élément | Valeur |
|---|---|
| Branche stable | **`main`** |
| Commit courant | **`8f716420d4fd0a885c940669c420808d65e89e87`** |
| Dernière PR fusionnée | [#30](https://github.com/Urbizen/urbizen-platform/pull/30) — **MERGED** |
| PR ouverte | [#31](https://github.com/Urbizen/urbizen-platform/pull/31) — contenu de la page DP, **en revue par Anaïs**, non déployée |
| Version dans `main` | `urbizen-platform` **0.12.0** (comptes E2.2), **non déployée** |
| Production — plugin | **0.10.0**, dernière constatée (non revérifiée cette session) |
| Production — thème | page **Déclaration préalable en ligne** (version initiale des PR #29/#30) |
| Déploiement 0.12.0 | **bloqué** — voir l'issue [#28](https://github.com/Urbizen/urbizen-platform/issues/28) |
| Dépôt | `Urbizen/urbizen-platform` — **public** |

Historique récent : PR [#23](https://github.com/Urbizen/urbizen-platform/pull/23)
E2.1 socle des comptes · [#24](https://github.com/Urbizen/urbizen-platform/pull/24)
D-046 · [#25](https://github.com/Urbizen/urbizen-platform/pull/25) D-047 ·
[#26](https://github.com/Urbizen/urbizen-platform/pull/26) E2.2 (0.12.0) ·
[#27](https://github.com/Urbizen/urbizen-platform/pull/27) protocole de déploiement ·
[#29](https://github.com/Urbizen/urbizen-platform/pull/29) gabarit DP ·
[#30](https://github.com/Urbizen/urbizen-platform/pull/30) déclaration du gabarit
dans `theme.json`.

### Où en est le projet

Deux fils avancent en parallèle.

1. **Comptes E2.2 (plugin 0.12.0)** — parcours public des comptes (inscription,
   vérification, renvoi, changement d'adresse), **fusionné dans `main`**, éprouvé
   (bancs réels + campagne de mutations), **mais pas déployé**. Le déploiement suit
   le protocole `docs/DEPLOY_ACCOUNTS_0_12.md`.
2. **Refonte des pages (Étape 6)** — la page **Déclaration préalable** est **en
   ligne** (thème enfant, sans CSS nouvelle, sur la charte de l'accueil). La PR #31
   en **différencie le contenu** de l'accueil (seuils, procédure, cas particuliers,
   erreurs) ; elle attend la relecture et le feu vert d'Anaïs avant déploiement.

### Ce qui a été fait cette session

- Fusion de **#29** (gabarit DP) et **#30** (déclaration `theme.json` — un gabarit
  de thème à blocs doit y figurer pour être assignable et rendu ; le brief l'avait
  omis, corrigé avant tout déploiement).
- **Déploiement de la page DP** en production, indépendamment du plugin 0.12.0
  (thème vs plugin, aucune dépendance) : sauvegarde du thème vérifiée, contrôle des
  empreintes, lint PHP 8.3, remplacement atomique des fichiers, purge LiteSpeed,
  puis assignation du gabarit à `/declarations-prealables/` en WP-CLI. Accueil
  vérifié intact.
- **PR #31** — contenu de la page DP différencié de l'accueil, texte réglementaire
  **fourni et validé par Anaïs**, repris mot pour mot.
- **D-048** consignée : le script `urbizen-homepage.js` du thème est une copie
  manuelle de `frontend/homepage/homepage.js`, avec deux écarts documentés et sans
  synchronisation automatique — dette assumée.
- Tentative de reprise du **déploiement 0.12.0** (phases 0-1 en lecture seule) :
  connectivité SSH OK, mais **arrêt** faute d'identifier avec certitude le journal
  PHP web (voir ci-dessous).

### Déploiement 0.12.0 — pourquoi c'est bloqué

Le protocole `DEPLOY_ACCOUNTS_0_12.md` exige, pour surveiller les erreurs après
bascule, le **vrai journal PHP web (PHP-FPM/LSAPI)**. Sur cet hébergement
LiteSpeed, aucune directive `error_log` explicite n'est lisible côté docroot
(`.user.ini`/`php.ini` absents), et l'activation de `log_errors` en hPanel n'a pas
fait apparaître de fichier identifiable **avec certitude** en lecture seule.
Provoquer une erreur pour le localiser est interdit. **Reprise : fournir le chemin
exact du journal comme `URBIZEN_PHP_WEB_LOG`** dans le fichier d'environnement
opérateur, puis exécuter strictement les phases 0-1, arrêt avant la phase 2.
Détail dans l'issue #28.

### Chantiers ouverts / prochaine étape

- [ ] **PR #31** : relecture Anaïs → si OK, déploiement de la page DP mise à jour
      (même motif : sauvegarde → sync du seul gabarit → purge → vérification).
- [ ] **Déploiement 0.12.0** : débloquer via `URBIZEN_PHP_WEB_LOG`, puis phases 0-1.
- [ ] **Page « Permis de construire »** : même risque de doublon que la DP ;
      refonte de contenu équivalente à prévoir, en héritant des ajustements de charte
      éventuels issus de la relecture de la DP.
- [ ] **Correctifs hors-code (Anaïs, côté wp-admin)** : balises SEO Site Kit
      (`title`, `og:type`, `og:site_name`, image OG) ; retrait des liens morts
      « Se connecter » / « Espace client (bientôt) ». **Ne pas traiter côté code
      sans demande explicite.**

### Interdictions

1. Ne jamais pousser directement sur `main` ; toute évolution passe par une PR.
2. Ne rien déployer sans autorisation explicite ; **la page DP mise à jour (#31)
   ne se déploie qu'après le feu vert d'Anaïs**.
3. Ne pas exécuter la phase 2 (ni suivantes) du déploiement 0.12.0 sans autorisation
   distincte : pas de sauvegarde-remplacement de plugin, pas de maintenance, pas
   d'installation de rôle.
4. Ne jamais versionner de coordonnée serveur, secret, donnée personnelle ni
   sauvegarde : le dépôt est public. Ne jamais afficher `wp-config.php`.
5. Ne pas modifier `frontend/homepage/`, l'accueil, `urbizen-homepage.css` ni le
   plugin pour un besoin propre à une page interne (voir D-048).

### Points de vigilance pour la reprise

1. **Gabarit de thème à blocs = deux endroits.** Une nouvelle page interne exige
   son fichier `templates/…html` **et** sa déclaration dans `theme.json →
   customTemplates`, sinon elle n'est ni assignable ni rendue (leçon de #30).
2. **Script d'accueil partagé (D-048).** `urbizen-homepage.js` est une copie
   manuelle de la source `frontend/homepage/homepage.js`, avec deux écarts
   documentés dans son en-tête. Une recopie naïve les effacerait.
3. **Journal PHP web** — sur LiteSpeed/CageFS, ni le SAPI CLI ni un `.user.ini`
   absent ne renseignent le vrai journal. Il faut le chemin fourni par hPanel.
4. **`wp db export` échoue sous CageFS** — passer par `mysqldump` avec un fichier
   d'options temporaire en mode 600, détruit après usage (le protocole 0.12.0 le
   fait déjà).
5. **Cas corse** — les codes INSEE de Corse ne font pas cinq chiffres (`2B033`) ;
   toute règle sur un code commune doit les accepter.
6. **Palette et police du parent** — le thème parent Hostinger écrase palette et
   police des titres via `wp_theme_json_data_theme` (priorité 999) ; l'enfant les
   réapplique en priorité 1000. Recontrôler après toute mise à jour du parent.
