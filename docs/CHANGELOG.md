# Journal des évolutions

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).
Ce fichier est mis à jour **dans le même commit** que le code qu'il décrit.

---

## [0.6.0] — 20 juillet 2026

Réception, protection et conservation des demandes de conception.

> **Aucun effet public.** Aucun formulaire n'est rendu, aucune page n'est
> créée, aucun courriel n'est envoyé, aucun fichier n'est reçu. L'action
> `admin-post` existe, mais aucune page publique n'en produit le nonce ni le
> jeton. Le site est strictement inchangé.

### Ajouté
- `src/Http/SubmissionController.php` : réception par `admin-post.php`, en
  `multipart/form-data`, **sans dépendance à JavaScript**. Quatorze contrôles
  dans un ordre imposé — les refus les moins coûteux d'abord.
- `src/Http/SubmissionResult.php` : objet de valeur immuable, quatorze codes
  internes, jamais montrés au prospect.
- `src/Security/AntiSpam.php` : jeton signé HMAC, délai minimal de 3 secondes
  mesuré **par le serveur**, validité de 24 heures, usage unique. Le jeton
  consommé n'est mémorisé que par un condensat non réversible (D-015).
- `src/Security/RateLimiter.php` : 5 soumissions par heure et par origine.
  **Aucune adresse IP n'est conservée** — ni en base, ni en transient, ni dans
  un journal. Les en-têtes de proxy ne sont jamais crus sur parole.
- `src/Submissions/SubmissionPostType.php` : type de contenu `urbizen_demande`,
  privé, hors recherche, hors REST, sans permalien. Toutes les capacités sont
  ramenées à `manage_options`, sans héritage de celles des articles.
- `src/Submissions/SubmissionRepository.php` : seule couche autorisée à écrire
  une demande. Douze métadonnées, retour arrière si l'une échoue (D-014).
- `src/Privacy/Retention.php` : purge quotidienne à 365 jours, **jamais** un
  dossier client, hook `urbizen_before_submission_delete` pour la PR B2 (D-016).
- `src/Admin/SubmissionsAdmin.php` : liste réservée aux administrateurs —
  référence, formulaire, statut, date. **Aucune donnée personnelle.**
- `tests/submissions/` : six bancs, **361 contrôles**, dont **50 mutations**.
  Doublure WordPress complète à horloge pilotable.

### Modifié
- `Plugin.php` enregistre le type de contenu, le contrôleur et la rétention ;
  la liste d'administration n'est chargée qu'en administration.
- `Activator.php` programme la purge quotidienne. **Aucune table SQL créée.**
- `Deactivator.php` référence `Retention::HOOK` plutôt qu'une chaîne : renommer
  la tâche sans renommer ici laisserait un événement orphelin.
- Version 0.6.0, alignée dans les deux `block.json`. Aucune autre clé ne change.

### Volontairement absent
- Aucun envoi de courriel : un banc le vérifie sur l'ensemble du plugin.
- Aucune réception de fichier : toute pièce jointe est refusée explicitement
  avec le code `files_not_supported_yet`, jamais ignorée en silence.
- Aucun rendu public : le garde-fou du `Renderer` reste actif.

### Inchangé
- `src/Forms/` n'est pas touché : `localisation` rend le même HTML, la
  définition `conception` conserve ses 6 étapes et ses 45 champs, `Pricing` et
  `Validator` sont fonctionnellement identiques.
- Les 260 contrôles de la PR A, les 175 du formulaire et du cadastre, et les
  367 de la page d'accueil passent **sans le moindre assouplissement**.

### À venir
- **PR B2** — fichiers : politique de dépôt, stockage hors racine web, liens
  signés de 14 jours régénérables, branchement sur
  `urbizen_before_submission_delete`.
- **PR B3** — courriels : notification à `contact@urbizen.fr`, confirmation au
  client, `Reply-To` sur l'adresse validée.
- **PR C** — interface publique en six étapes, génération du nonce et du jeton,
  page en brouillon.

---

## [0.5.0] — 20 juillet 2026

Socle métier du troisième service Urbizen : **Conception de plans sur mesure**.

> **Aucun effet public.** Cette version ne rend aucun formulaire, ne crée aucune
> page, n'ajoute aucune route et n'envoie aucun courriel. Elle pose la structure
> déclarative, la validation serveur et le catalogue tarifaire dont dépendront
> les étapes suivantes. Le site public est strictement inchangé.

### Ajouté
- **Six types de champs** dans `FormDefinition` : `radio`, `checkbox`, `select`,
  `textarea`, `file`, `consent`, en plus de `text`, `number` et `hidden`.
- **Étapes déclaratives** : clé `steps[]`, chaque champ portant son `step`.
- **Affichage conditionnel** déclaratif par `visible_if`, réévalué côté serveur.
- **Liste blanche des clés de champ** : une clé inconnue est écartée et nommée.
- **Anomalies contrôlables** : `FormDefinition::errors()` et `is_valid()`. Une
  définition fautive ne provoque aucun écran fatal ; le registre journalise.
- `src/Forms/definitions/conception.php` : six étapes — programme, pièces,
  terrain, style et options, documents, contact — 40 champs, 39 clés de surface.
- `src/Forms/Validator.php` : validation serveur intégrale — requis, listes
  fermées, bornes, longueurs, normalisation, neutralisation des retours chariot
  dans les champs d'en-tête de courriel, branches conditionnelles, liste blanche
  des surfaces dynamiques, erreurs structurées par identifiant de champ.
- `src/Forms/Pricing.php` : catalogue tarifaire serveur, exclusivité du pack,
  prestations sur devis tenues hors du calcul (D-012).
- `tests/conception/` : quatre bancs, **260 contrôles**, dont **39 mutations**
  prouvant que chaque règle cassée fait bien tomber son contrôle.

### Modifié
- `FormRegistry::KNOWN` accueille `conception`. La liste blanche en dur est
  conservée : aucune valeur du navigateur ne peut désigner un fichier arbitraire.
- `Renderer` **refuse** de rendre un formulaire déclarant des étapes, tant que
  `StepRenderer` n'existe pas. Garde-fou temporaire, retiré en PR C.
- Le mot `step` désigne désormais l'étape d'appartenance ; l'incrément HTML des
  champs numériques prend le nom `increment` (D-011). Seule conséquence sur
  `localisation.php` et `Renderer.php` — le HTML rendu est **identique au bit
  près**, vérifié par comparaison d'empreintes SHA-256 avec la version
  précédente.

### Inchangé
- `localisation` : 14 champs, 6 visibles, 8 techniques, mêmes types, mêmes
  contraintes, même rendu.
- `assets/js/urbizen-form.js`, le composant cadastre, le thème enfant, la page
  d'accueil, les pages Déclaration préalable et Permis de construire, les menus,
  le pied de page, Fluent Forms.
- Les 175 contrôles du formulaire et du cadastre, et les 367 contrôles de la
  page d'accueil, passent **sans le moindre assouplissement**.

### À venir
- **PR B1** — soumission : `admin-post`, nonce, pot de miel, limitation de
  débit, type de contenu privé `urbizen_demande`.
- **PR B2** — fichiers : politique de dépôt, stockage hors racine web, liens
  signés de 14 jours régénérables.
- **PR B3** — courriels : notification à `contact@urbizen.fr`, confirmation au
  client, `Reply-To` sur l'adresse validée.
- **PR C** — interface publique en six étapes, page en brouillon.

---

## [0.4.0] — 19 juillet 2026

Connexion du composant cadastre au premier formulaire Urbizen. Aucune requête
réseau n'est émise par cette version — la validation est entièrement locale.

> **Fusionnée par la PR [#7](https://github.com/Urbizen/urbizen-platform/pull/7)**
> — merge `90191f0` — et **déployée en production** le 20 juillet 2026, après
> validation en conditions réelles sur la page brouillon 1157. Le contenu de la
> production est identique à `main`, fichier par fichier.

### Ajouté
- **Contrat de données canonique 1.0** (D-009) : structure imbriquée et
  versionnée `{ schemaVersion, source, confirmedAt, address, location, parcel }`,
  produite par une fabrique unique et publiée à la fois par l'événement
  `urbizen:parcel-confirmed` et par `sessionStorage`.
- `src/Forms/FormDefinition.php`, `FormRegistry.php`, `Renderer.php` et la
  définition `definitions/localisation.php` : formulaire déclaratif, 6 champs
  visibles et 8 champs techniques masqués mais inspectables.
- `src/Blocks/FormBlock.php` : bloc `urbizen/formulaire` et shortcode
  `[urbizen_formulaire]`, rendu dynamique commun, attributs `formType`,
  `storageKey` et `formId`.
- `assets/js/urbizen-form.js` : pont cadastre → formulaire, **sans dépendance**
  au script du cadastre. Reprise par événement **et** par `sessionStorage`, donc
  indifférente à l'ordre de montage.
- `assets/css/urbizen-form.css`, `blocks/formulaire/{block.json,editor.js,editor.css}`.
- Événement `urbizen:location-form-validated` : le contrat validé est publié
  dans `event.detail`, à charge de l'hôte d'en faire quelque chose.
- Événement `urbizen:cadastre-edit-requested` : le bouton « Modifier l'adresse »
  redonne la main au cadastre **par événement**, sans appeler de méthode privée
  ni dépendre d'un identifiant HTML fixe.
- `UrbizenCadastre.getContract()` et `Cadastre.requestEdit()`.
- `tests/cadastre/test-form.mjs` (**126 contrôles**), `test-form-render.php`
  (**49 contrôles**), `make-fixture.php` et `run-all.sh`. Suite complète du
  dépôt : **243 contrôles**, tous verts.

### Modifié
- Le composant cadastre capte désormais `street`, `houseNumber` et le préfixe
  cadastral (`com_abs`), que les API fournissaient déjà et qu'il ignorait.
- `confirmedAt` horodate la **confirmation par la personne**, non la réponse de
  l'API — l'ancien `retrievedAt` mélangeait les deux.
- Version du plugin **0.3.0 → 0.4.0**, handles et `block.json` alignés.

### Sécurité et vie privée
- **La géométrie est exclue du contrat 1.0** : ni publiée, ni stockée, ni
  transmise, ni portée par un champ caché. Elle reste en interne pour le seul
  tracé sur la carte.
- **Les deux codes commune restent séparés** : celui du géocodeur et celui de la
  parcelle. Une divergence est signalée sans être corrigée, et aucune valeur
  n'est inventée.
- Refus de la pollution de prototype : `__proto__`, `constructor` et `prototype`
  ne sont jamais traversés. Seuls 17 chemins connus sont lus — le payload ne
  choisit jamais ce qui est écrit.
- Toutes les chaînes sont bornées, tous les nombres validés, les coordonnées
  hors bornes terrestres rejetées.
- **Aucune donnée personnelle en console**, y compris en cas d'erreur : les
  diagnostics n'exposent que des codes.
- Aucun `fetch`, `XMLHttpRequest`, `sendBeacon` ni soumission HTML : vérifié par
  test, sur le comportement **et** sur le code source.
- Conservation documentée (D-010) : `sessionStorage` seul, portée de l'onglet,
  aucune durée inventée, effacement explicite, et **ni la reprise ni la
  validation n'effacent** quoi que ce soit.

### Corrigé après revue de la PR #7
- **Identifiants HTML uniques dès le rendu serveur** : `Renderer.php` préfixe
  chaque instance (`uf-1-…`). Le HTML est valide sans JavaScript, et un libellé
  cliqué vise bien son propre champ. Le script ne pose plus de second préfixe
  lorsqu'il en trouve déjà un.
- **Messages d'erreur accessibles** : chaque champ visible porte un conteneur
  d'erreur identifié, référencé par `aria-describedby` dès le rendu, annoncé par
  `aria-live="polite"`. Les descriptions d'aide existantes sont conservées.
  `aria-invalid` est posé à l'erreur et **retiré dès la correction**.
- **Fin de la troncature silencieuse.** Les codes, section, numéro et
  identifiant cadastral sont validés par expression régulière ; une valeur non
  conforme est refusée et signalée, jamais raccourcie. Le cas corse (`2B033`)
  est pris en charge : la règle « 5 chiffres » aurait rejeté toute la Corse.
- **Surface strictement positive** lorsqu'elle est renseignée : 0 m² est refusé.
- **Un payload devenu inexploitable est signalé** au lieu d'être ignoré en
  silence — défaut trouvé par un test pendant la correction.
- **Zone d'état ne s'écrase plus elle-même** : les messages techniques et la
  divergence des codes commune sont rassemblés puis rendus une seule fois.
  Autre défaut trouvé par un test.
- **Provenance honnête** : `source` vaut `urbizen-form` sur une saisie
  entièrement manuelle, `urbizen-cadastre` dès qu'une confirmation cadastre est
  à l'origine, même corrigée ensuite.
- **Commande unique** `npm test` (`run-all.sh`) : régénère la fixture puis
  enchaîne les quatre bancs, s'arrête avec un code non nul au premier échec.
- **Le banc JavaScript consomme le HTML réel** produit par `Renderer.php` :
  plus aucune copie manuelle de la structure, donc plus de faux positif
  possible si le rendu change.

### Ancien format 0.3.0
Le payload plat de la 0.3.0 encore présent dans un onglet est **ignoré** : ni
interprété, ni transmis. La personne devra confirmer à nouveau sa parcelle.
Aucune donnée ancienne n'est effacée automatiquement, aucune migration n'est
prévue.

### Limites assumées
Aucune soumission serveur, aucune route REST, aucune table, aucun nonce, aucun
envoi au service Python. Aucun champ demandeur, aucune pièce jointe. Les
formulaires DP et PCMI complets restent à l'étape suivante.

---

## [0.3.0] — 19 juillet 2026

Composant cadastre porté dans l'extension, **version du plugin portée à 0.3.0**,
**déployée en production** le 19 juillet 2026 après validation. La page
d'accueil publiée n'est pas modifiée et ne charge aucun asset du composant.

> **Statut du bloc Gutenberg : validé en conditions réelles** le 19 juillet 2026.
> Extension déployée en production, bloc inséré et réglé dans l'éditeur sur une
> page en brouillon non indexée, enregistré et rechargé sans erreur. Détail des
> 13 contrôles en fin d'entrée.

### Ajouté
- `src/Blocks/CadastreBlock.php` : bloc Gutenberg `urbizen/cadastre` et
  shortcode `[urbizen_cadastre]`, partageant **exactement** le même rendu et le
  même enfilage. Rendu dynamique côté PHP ; `post_content` ne contient jamais
  d'adresse ni de parcelle.
- `blocks/cadastre/block.json` : **déclaration unique** des cinq attributs.
  PHP les relit depuis ce fichier, l'éditeur les reçoit de WordPress : aucune
  valeur par défaut n'est dupliquée.
- `blocks/cadastre/editor.js` : `registerBlockType` avec `InspectorControls`
  pour le libellé, le texte d'aide, le bouton Continuer, la clé de stockage et
  la hauteur de carte, plus un **aperçu statique**. Aucun appel IGN, aucune
  carte Leaflet dans l'éditeur. `save()` renvoie `null`.
- `blocks/cadastre/editor.css` : styles de l'aperçu, éditeur uniquement.
- `assets/vendor/leaflet/LICENSE` : licence **BSD 2-Clause** officielle de
  Leaflet 1.9.4, et `README.md` précisant version, source, empreintes SHA-256
  et règle de mise à jour.
- `Cadastre.prototype.destroy()` : démontage propre — carte Leaflet, écouteur
  document, DOM et marqueur de montage —, permettant un remontage.
- `assets/vendor/leaflet/` : Leaflet 1.9.4 embarqué (JS, CSS, images). Plus
  aucun appel à `unpkg.com`.
- `UrbizenCadastre.autoMount()` : montage automatique des conteneurs
  `[data-urbizen-cadastre]`, idempotent, options lues sur des `data-*`.
- `UrbizenCadastre.clearStored()` : effacement explicite des données de
  localisation conservées dans l'onglet.
- Repli `<noscript>` : le composant ne laisse jamais un conteneur muet.
- `tests/cadastre/` : deux bancs d'essai, comportement JavaScript sous jsdom et
  rendu PHP avec doublures WordPress. Le répertoire `tests/` n'était pas
  utilisé jusqu'ici.
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
- Version du plugin **0.1.0 → 0.3.0**, en-tête et constante alignées. Les
  handles CSS et JavaScript portent cette version : la montée de version casse
  le cache navigateur et LiteSpeed, les anciens assets ne peuvent pas rester
  servis. Leaflet garde sa propre version, 1.9.4.
- `aria-activedescendant` désormais **retiré** dès qu'aucune suggestion n'est
  active — à la fermeture de la liste, quand elle est vide et à chaque
  reconstruction. Il annonçait auparavant une option disparue.
- `scrollIntoView` appelé seulement s'il existe : son absence ne casse plus la
  navigation clavier.
- Panne d'une couche IGN : message de statut explicite, affiché une seule fois,
  sans masquer ce qui s'affiche déjà.

### Corrigé
- **Le bloc n'était pas utilisable dans Gutenberg** : `register_block_type()`
  côté PHP ne suffit pas, il faut un enregistrement côté éditeur. Sans
  `block.json` ni script d'éditeur, le bloc n'apparaissait pas dans l'outil
  d'insertion. C'est l'objet de cette correction.
- Écouteur `click` posé sur `document` à chaque montage sans jamais être
  retiré : fuite corrigée par `destroy()`.

### Sécurité
- **Suppression de tout `innerHTML`** sur des données d'API ou d'attributs : le
  DOM est construit par `createElement`, `textContent` et `setAttribute`. Une
  suggestion piégée de la Géoplateforme ne peut plus injecter de balise.
- **Identifiants HTML uniques par instance** : plusieurs composants cohabitent
  sur une page sans casser `for`, `aria-controls` ni `aria-activedescendant`.
- Attributs assainis et échappés côté PHP, hauteur de carte validée par
  expression régulière **des deux côtés** — l'éditeur avertit, le serveur
  tranche. L'échappement PHP ne remplace pas celui du JavaScript : les deux
  sont en place.

### Tests
- **32 contrôles JavaScript** sous jsdom et **36 contrôles de rendu PHP** avec
  doublures WordPress, tous verts. Couvrent désormais l'enregistrement de
  l'éditeur, la cohérence des attributs, les versions de handles, les
  dépendances, l'absence de double enfilage et la licence Leaflet.

### Validation en conditions réelles — 19 juillet 2026
Extension déployée sur la production (0.1.0 → 0.3.0), page de test créée en
**brouillon non indexé**, 13 contrôles passés :

**Éditeur** — « Cadastre Urbizen » présent dans l'outil d'insertion ; bloc
inséré ; les cinq réglages modifiables par la barre latérale ; une hauteur
invalide déclenche bien l'avertissement et n'est pas retenue ; enregistrement
sans erreur ; après rechargement, **3 blocs sur 3 valides**, aucun message de
contenu inattendu, attributs conservés à l'identique.

**`post_content`** — uniquement le commentaire de bloc et ses attributs de
présentation. Aucune coordonnée, aucun code postal, aucune référence
cadastrale, aucune géométrie.

**Site public** — 3 composants montés, identifiants uniques (`uc-1`, `uc-2`,
`uc-3`), **aucune ressource en échec**, chaque asset chargé **une seule fois**
malgré deux blocs et un shortcode sur la même page. Aucune requête vers un CDN
pour le cadastre.

**Parcours** — autocomplétion IGN sur une adresse publique (3 suggestions) ;
carte, orthophoto et parcellaire affichés ; parcelle détectée et confirmée,
cartouche cohérent avec le cadastre ; `sessionStorage` alimenté sous la clé
préfixée du bloc, 13 champs ; `clearStored()` efface ; clés normalisées avec
ou sans préfixe.

**Erreurs** — « Aucune adresse trouvée… » sur requête sans résultat,
« Recherche indisponible… » sur panne réseau **et** sur erreur HTTP 500. La
page reste fonctionnelle dans les trois cas.

**Mobile** — aucun débordement horizontal, carte et champs à la largeur de
l'écran, contrôles utilisables.

**Isolation** — la page d'accueil publiée ne charge **aucun** asset cadastre :
ni Leaflet, ni CSS, ni JavaScript.

**Journal PHP** — aucune entrée liée à `urbizen-platform` ni au cadastre.

Observation sans gravité : une hauteur de carte explicite l'emporte sur la
règle responsive `@media (max-width: 520px)`. C'est le comportement attendu
d'un réglage posé par la rédactrice ou le rédacteur, mais il faut le savoir.

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
