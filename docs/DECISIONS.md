# Décisions d'architecture

> Chaque décision structurante est consignée ici, avec son contexte et ses
> conséquences. Une décision ne se modifie pas : elle est remplacée par une
> nouvelle, qui indique celle qu'elle remplace.

Format : contexte → décision → conséquences.

---

## D-001 — Abandon de Fluent Forms

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Le site utilisait Fluent Forms pour le formulaire de contact et la
demande de déclaration préalable. Les parcours métier Urbizen (pièces jointes
contrôlées, cadastre, transmission au service Python, statuts de dossier, RGPD)
dépassent largement ce qu'un constructeur généraliste permet de maîtriser.

**Décision.** Tous les formulaires Urbizen sont développés et maintenus dans
l'extension `urbizen-platform`. Fluent Forms n'est utilisé pour aucun nouveau
parcours : ni DP, ni PCMI, ni contact, ni devis, ni espace professionnels.

**Conséquences.**
- Les formulaires de `frontend/formulaires/` deviennent la référence officielle.
- Validation serveur, nonces, contrôle MIME, anti-spam, journalisation et
  rétention sont à notre charge.
- Fluent Forms et Fluent SMTP restent installés jusqu'à migration complète : les
  désactiver avant couperait la réception des demandes.
- Les 4 entrées existantes sont inventoriées et exportées chiffrées avant tout
  retrait.

---

## D-002 — Thème enfant pour le rendu, extension pour le métier

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Le site repose sur `hostinger-ai-theme`, un thème FSE fourni par
l'hébergeur et mis à jour automatiquement. Toute modification directe serait
écrasée. Trois options : thème sur mesure, thème enfant seul, ou thème enfant
combiné à une extension.

**Décision.** Thème enfant `urbizen-child` pour le rendu et les gabarits ;
extension `urbizen-platform` pour toute la logique métier ; service Python séparé
pour la génération documentaire. Dépendances à sens unique.

**Conséquences.**
- Le thème parent n'est jamais modifié et peut être mis à jour.
- La logique métier survit à tout changement de thème.
- Un thème sur mesure aurait imposé de reconstruire 13 pages de blocs sans gain
  immédiat : écarté.
- Le thème enfant s'interdit toute requête SQL, tout appel réseau et tout
  traitement de données personnelles.

---

## D-003 — Tables dédiées plutôt qu'un type de contenu personnalisé

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Les soumissions de formulaires doivent être stockées. Deux
possibilités : un type de contenu personnalisé, ou des tables dédiées.

**Décision.** Tables dédiées `wp_urbizen_submissions`, `_submission_fields`,
`_files` et `_log`.

**Conséquences.**
- Purge RGPD chirurgicale, champ par champ, sans révisions parasites.
- `wp_posts` fait déjà 27,8 Mo avec 629 révisions : on n'y ajoute pas de données
  personnelles.
- Coût : une interface d'administration à écrire, sans bénéficier des listes
  natives de WordPress.

---

## D-004 — Pièces jointes stockées hors racine web

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Les dossiers d'urbanisme contiennent des pièces d'identité, des
plans et des photographies. `wp-content/uploads` est servi publiquement et ses
URL sont devinables.

**Décision.** Stockage dans `${URBIZEN_STORAGE_ROOT}` — répertoire personnel du
compte d'hébergement, voisin de `domains/`, donc hors racine web —
avec contrôle du type MIME réel, plafonds de taille, empreinte SHA-256, et accès
par jeton expirant via une route REST authentifiée.

**Conséquences.**
- Ce répertoire doit être explicitement inclus dans le plan de sauvegarde.
- Aucun accès direct par URL n'est possible.
- Chaque téléchargement est journalisé.

---

## D-005 — Gabarits FSE en fichiers, jamais en base

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** L'activation du thème enfant a fait disparaître le menu et le pied
de page du site. Cause : l'en-tête, le pied de page et les styles globaux étaient
des enregistrements `wp_template_part` et `wp_global_styles` rattachés au terme
`wp_theme` du thème parent. Deux corrections possibles : réaffecter ces
enregistrements au thème enfant en base, ou les exporter en fichiers.

**Décision.** Export en fichiers versionnés dans `wordpress/urbizen-child/parts/`
et report des styles globaux dans `theme.json`. Le bloc de navigation, qui
référençait `wp_navigation` ID 15, est inliné.

**Règle générale qui en découle :** *toute l'architecture, tous les gabarits et
toute la logique applicative doivent être reproductibles depuis Git, sans
personnalisation technique cachée dans la base. Les contenus et données
d'exploitation sont sauvegardés séparément.*

**Conséquences.**
- Aucune écriture durable en base n'est nécessaire pour reconstruire le site.
- Les gabarits deviennent lisibles, comparables et révisables en Pull Request.
- Les modifications faites depuis l'éditeur de site WordPress ne seront plus
  reprises automatiquement : elles devront être réexportées vers le dépôt.
- Le CSS personnalisé est repris tel quel, imperfections comprises, pour garantir
  l'équivalence visuelle. Nettoyage différé à la refonte des pages.
- Le thème parent écrasant la palette et la police des titres par un filtre en
  priorité 999, le thème enfant les réapplique en priorité 1000 en les relisant
  depuis son propre `theme.json` : la configuration reste dans Git, jamais
  dupliquée dans le PHP ni figée en base.
- Résultat vérifié en production : rendu **pixel-identique** à la référence sur
  ordinateur et sur mobile.

---

## D-006 — Backend Python découplé

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** La génération des Cerfa, notices et bordereaux existe déjà en
Python. La tentation serait de l'appeler directement depuis WordPress.

**Décision.** Le service reste autonome. L'extension le contacte en HTTP, avec
signature HMAC, clé d'idempotence, délai d'attente et tentatives multiples.

**Conséquences.**
- Si le service est indisponible, la demande est **quand même enregistrée et
  notifiée**, avec un statut d'échec de transmission et un rejeu manuel possible.
  Aucune demande client n'est perdue.
- `PayloadMapper` est le seul point de traduction entre les champs WordPress et
  les clés attendues par `app.py`.
- Deux évolutions restent à faire côté Python : authentifier `POST /api/dp` et
  restreindre le CORS au domaine du site.

---

## D-007 — La documentation fait partie du code

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Le projet est mené avec l'assistance d'IA, sur des sessions
successives sans mémoire partagée, et sur une production réelle où chaque piège
coûte cher à redécouvrir.

**Décision.** Cinq documents sont maintenus dans `docs/` :
`PROJECT_MASTER_PLAN.md`, `AI_CONTEXT.md`, `DECISIONS.md`, `CHANGELOG.md`,
`ROADMAP.md`. Aucun développement significatif n'est considéré comme terminé tant
que la documentation n'est pas à jour **dans le même commit**.

**Conséquences.**
- Une nouvelle IA ou un nouveau développeur reprend le projet sans historique de
  conversation.
- Les pièges vérifiés en production sont consignés une fois pour toutes.
- Une Pull Request qui touche l'architecture sans toucher `docs/` est incomplète.

---

## D-008 — Le composant cadastre devient un module de l'extension

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Le composant cadastre — saisie d'adresse, carte IGN, sélection et
confirmation de parcelle — a été prototypé dans `frontend/assets/`, chargé par la
page d'accueil statique avec Leaflet servi depuis `unpkg.com`. Trois obstacles
interdisaient de le porter tel quel dans WordPress : le HTML est construit par
`innerHTML` à partir de données d'API et d'options, les identifiants HTML sont en
dur (`uc-input`, `uc-map`), et le CSS dépend de tokens `--u-*` absents du thème
enfant en production.

**Décision.** Le composant devient un module de `urbizen-platform`, avec une
**source de vérité unique** :

- `assets/js/urbizen-cadastre.js`, `assets/css/urbizen-cadastre.css` et
  `assets/vendor/leaflet/` vivent dans l'extension ;
- les copies de `frontend/assets/` sont supprimées ; le prototype de la page
  d'accueil référence les fichiers de l'extension par chemin relatif ;
- aucune copie générée, aucun script de synchronisation.

Cinq règles encadrent le portage :

1. **Leaflet 1.9.4 est embarqué** dans le dépôt, servi localement, jamais depuis
   un CDN — pas de fuite d'adresse IP des visiteurs vers un tiers.
2. **Aucun `innerHTML` pour une donnée d'API ou d'attribut** : le DOM est
   construit par `createElement`, `textContent` et `setAttribute`. Pas de
   fonction d'échappement maison. L'échappement PHP ne dispense pas de celui du
   JavaScript, les deux sont exigés.
3. **Identifiants uniques par instance** — champ, label, liste de suggestions,
   carte, options ARIA et messages d'état — pour que plusieurs composants
   cohabitent sur une même page sans casser `for`, `aria-controls` ni
   `aria-activedescendant`.
4. **Tokens en repli explicite** : `var(--u-brand, #128A5A)`. Le composant est
   correct sans le thème et hérite de la charte dès qu'elle est déployée.
   L'extension **ne redéclare jamais** les tokens dans `:root` : le rendu
   appartient au thème (voir D-002).
5. **Bloc et shortcode partagent exactement** le même `render_callback` et la
   même logique d'enfilage. Le bloc est **rendu dynamiquement côté PHP** : ni
   adresse, ni parcelle, ni donnée métier n'est enregistrée dans `post_content`.

**Conséquences.**
- Les assets ne sont chargés que sur les pages qui rendent le composant.
- L'activation de l'extension n'écrit toujours rien en base.
- Aucun appel au backend Python : le composant ne parle qu'aux services publics
  IGN, sans clé d'API.
- `sessionStorage` reste utilisé avec une clé préfixée et configurable ; adresse
  et parcelle ne quittent pas l'onglet du navigateur. L'API JavaScript expose
  `clearStored()` pour permettre un effacement explicite.
- Les services IGN sont soumis à quota et le parcellaire n'est mis à jour que
  deux fois par an : la surface cadastrale affichée est **indicative**.
