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

---

## D-009 — Contrat de données entre le cadastre et les formulaires

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** Le composant cadastre publiait un objet plat de treize clés, non
versionné, mêlant adresse, coordonnées, références cadastrales et géométrie. Le
formulaire allait devenir son premier consommateur réel : figer un contrat
explicite avant qu'un second module ne s'y accroche évitait de le renégocier
plus tard, sur du code en production.

**Décision.** Un contrat canonique **1.0**, imbriqué et versionné, est la seule
structure publiée — par l'événement `urbizen:parcel-confirmed` comme par
`sessionStorage` :

```json
{
  "schemaVersion": "1.0",
  "source": "urbizen-cadastre",
  "confirmedAt": "",
  "address":  { "label": "", "houseNumber": "", "street": "", "postcode": "", "city": "", "cityCode": "" },
  "location": { "latitude": null, "longitude": null },
  "parcel":   { "communeCode": "", "prefix": "", "section": "", "number": "", "id": "", "surfaceM2": null }
}
```

Six règles l'encadrent :

1. **Aucune valeur fabriquée.** Chaîne absente = chaîne vide ; nombre absent ou
   invalide = `null`. Une donnée que les services ne fournissent pas reste vide.
2. **`surfaceM2` est une surface cadastrale indicative**, jamais une surface de
   terrain arpentée. Le champ correspondant reste modifiable et porte une
   mention visible : un projet peut couvrir plusieurs parcelles, ou une partie
   seulement de l'une d'elles.
3. **`confirmedAt` horodate la confirmation par la personne**, pas la réponse de
   l'API. C'est l'acte de validation qui fait foi.
4. **`source` décrit Urbizen** (`urbizen-cadastre`), pas le fournisseur externe :
   le contrat est le nôtre, quelles que soient les API sous-jacentes.
5. **Une fabrique unique** — `buildContract()` — produit tout ce qui est publié.
   Aucune structure parallèle n'est maintenue à côté.
6. **Toutes les chaînes sont bornées** et les nombres validés, des deux côtés du
   pont.

**La géométrie est exclue du contrat 1.0.** Elle reste dans l'état interne du
composant, où elle sert au seul tracé de la parcelle sur la carte, mais elle
n'est ni publiée, ni stockée, ni transmise au formulaire, et aucun champ caché
ne la porte. Motif : aucun usage identifié en aval — ni le formulaire, ni le
service Python ne l'exploitent — pour un volume de plusieurs kilo-octets de
données de localisation précises. La réintroduire exigera un usage démontré et
une version 1.1 du contrat.

**Les deux codes commune sont conservés séparément.** `address.cityCode` vient
du géocodeur, `parcel.communeCode` de la parcelle cadastrale. Ils ne sont ni
fusionnés, ni substitués l'un à l'autre. En cas de divergence, le formulaire
reste utilisable, aucune valeur n'est inventée, et un état technique non
bloquant est exposé dans le DOM — jamais dans un journal.

**Aucune propriété plate de compatibilité n'est conservée.** Vérification faite
avant la bascule : le seul consommateur existant, `frontend/homepage/homepage.js`,
écoute l'événement pour faire défiler la page et **ne lit aucune propriété** du
payload. Maintenir des champs plats pour un lecteur inexistant aurait créé une
seconde source de vérité sans bénéfice. Il n'y a donc rien à déprécier, et
aucune date de suppression à prévoir.

**Règles de format, sans troncature silencieuse.** Une valeur non conforme est
**refusée et signalée**, jamais raccourcie en douce :

| Champ | Règle | Justification |
|---|---|---|
| `address.postcode` | `^[0-9]{5}$` | code postal français |
| `address.cityCode`, `parcel.communeCode` | `^(?:[0-9]{5}\|2[AB][0-9]{3})$` | **cas réel vérifié** : le code INSEE de Bastia est `2B033`. La règle « exactement 5 chiffres » aurait rejeté toute la Corse |
| `parcel.prefix` | `^[0-9]{3}$` | `com_abs`, vaut `000` hors communes fusionnées |
| `parcel.section` | `^[0-9A-Z]{1,3}$` | sections observées à 2 caractères (`KE`, `KI`), la marge couvre les sections préfixées |
| `parcel.number` | `^[0-9]{1,4}$` | numéros observés sur 4 chiffres (`0112`) |
| `parcel.id` | `^[0-9A-Z]{14}$` | `idu` = INSEE (5) + préfixe (3) + section (2) + numéro (4) |
| `location.latitude` / `longitude` | nombre fini, ±90 / ±180 | bornes terrestres |
| `parcel.surfaceM2` | nombre fini, **strictement positif**, ≤ 10 000 000 | une parcelle de 0 m² n'existe pas |

Deux transformations restent autorisées parce qu'elles sont **explicites et
prévisibles** : `trim` sur toutes les chaînes, et passage en majuscules de la
section, de l'identifiant et des codes commune. Toute autre valeur non conforme
produit un message compréhensible — sur le champ s'il est visible, dans la zone
d'état s'il est technique. Un payload dont plus rien n'est exploitable est
**signalé**, jamais ignoré en silence.

**L'ancien format plat 0.3.0 n'est pas migré.** Une personne dont l'onglet
contient encore un payload produit par la 0.3.0 verra le formulaire vide : le
format inconnu est **ignoré**, ni interprété ni transmis, et il lui faudra
confirmer à nouveau sa parcelle. Rien n'est effacé automatiquement — la clé
ancienne reste en place jusqu'à la fermeture de l'onglet. Une migration serait
du code à écrire, à tester et à retirer plus tard pour un cas qui se résout de
lui-même en une confirmation.

**Conséquences.**
- `street`, `houseNumber` et le préfixe cadastral (`com_abs`), jusque-là
  ignorés alors que les API les fournissent, sont désormais captés : le Cerfa
  distingue numéro et voie.
- Le formulaire reconstruit le contrat **à partir des champs** au moment de la
  validation : ce que la personne a corrigé fait foi, pas le contrat d'origine.
- **La provenance est honnête** : `source` vaut `urbizen-cadastre` si la
  localisation vient d'une confirmation sur la carte — même corrigée ensuite —
  et `urbizen-form` si tout a été saisi à la main. `confirmedAt` reste vide dans
  ce second cas : la validation locale ne crée aucun horodatage, elle n'est pas
  une confirmation cadastrale.
- Un futur point de soumission serveur devra **tout revalider**. Les champs
  masqués viennent du navigateur : ils ne sont pas dignes de confiance.

---

## D-010 — Conservation des données de localisation

**Date** : 19 juillet 2026 · **État** : actée

**Contexte.** L'adresse et la parcelle sont des données personnelles. Elles
transitent aujourd'hui uniquement par le navigateur, mais la question de leur
conservation devait être tranchée avant toute soumission serveur.

**Décision.** `sessionStorage` uniquement, **jamais `localStorage`**.

- La portée est **l'onglet courant** : les données disparaissent normalement à
  sa fermeture. Aucune durée en heures ou en jours n'est annoncée — ce serait
  inventer une garantie que le mécanisme ne donne pas.
- La clé est **préfixée `urbizen:`** et configurable par bloc. Un formulaire
  lit **la seule clé qu'on lui a désignée** ; parcourir l'ensemble des clés
  pour choisir une parcelle au hasard est interdit.
- **Une action explicite d'effacement** est offerte à la personne, dans le
  formulaire comme par l'API `UrbizenCadastre.clearStored()`.
- **Ni le préremplissage, ni la validation locale n'effacent quoi que ce soit** :
  la personne doit pouvoir revenir en arrière et corriger son adresse.
- Un effacement automatique ne sera envisagé qu'**après une soumission serveur
  confirmée comme réussie**.

**Conséquences.**
- Aucune donnée personnelle ne quitte le navigateur en version 0.4.0.
- Aucune donnée personnelle n'est écrite dans un journal, ni côté navigateur,
  ni côté serveur : les diagnostics n'exposent que des codes d'erreur.
- La politique de confidentialité devra mentionner ce stockage local dès que le
  composant sera publié sur une page réelle.

---

## D-011 — Extension additive de FormDefinition

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Le moteur de formulaires connaissait trois types de champs —
`text`, `number`, `hidden` — un seul formulaire d'un seul tenant, et aucune
notion d'étape, de condition ni de liste fermée. Le service « Conception de
plans sur mesure » exige six étapes, neuf types de champs, des branches
conditionnelles, une famille de champs dynamiques et un calcul tarifaire.

Deux voies s'offraient : écrire un second moteur à côté du premier, ou étendre
celui qui existe.

**Décision.** Le moteur unique est étendu, et **toute extension est additive**.

- Un formulaire qui ne déclare pas `steps` se charge exactement comme avant.
  `localisation` n'a subi aucune modification fonctionnelle : son HTML rendu est
  **identique au bit près** à celui de la version précédente, ce que le banc
  d'essai vérifie par comparaison d'empreintes.
- Les six types ajoutés — `radio`, `checkbox`, `select`, `textarea`, `file`,
  `consent` — ne retirent rien aux trois existants.
- Les clés de champ forment une **liste blanche**. Une clé inconnue est écartée
  et **nommée** : une faute de frappe dans une définition doit se voir.
- Une définition fautive ne provoque **jamais d'écran fatal**. Les champs
  invalides sont écartés, la raison est consignée dans `errors()` et journalisée
  par le registre. Les bancs d'essai lisent la même liste.

**Le mot `step` ne peut plus porter deux sens.** Il désignait l'incrément HTML
d'un champ numérique ; il désigne désormais l'étape d'appartenance. L'incrément
prend le nom distinct `increment`. C'est la seule modification apportée à
`localisation.php` et à `Renderer.php`, et elle est neutre : le HTML produit est
inchangé.

**Conséquences.**
- `Renderer` refuse de rendre un formulaire déclarant des étapes : il poserait
  tous les champs à plat, sans distinguer un bouton radio d'un champ texte. Ce
  garde-fou disparaîtra quand `StepRenderer` existera (PR C).
- Le registre reste une **liste blanche en dur**. Aucune valeur reçue du
  navigateur ne peut désigner un fichier de définition arbitraire.
- Les 175 contrôles existants du formulaire et du cadastre passent inchangés,
  sans le moindre assouplissement.

---

## D-012 — Le prix est une décision serveur

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Le formulaire de conception affiche un prix indicatif qui varie
selon les options cochées. Un total calculé dans le navigateur est une donnée
que le visiteur contrôle entièrement.

**Décision.** `src/Forms/Pricing.php` est la **source unique** des montants.

- Le navigateur peut afficher un total pour informer ; il n'en est jamais la
  source. Un montant reçu d'un formulaire est **ignoré sans exception** : seuls
  les identifiants d'options sont lus, et le total est recalculé côté serveur.
- Un identifiant inconnu est écarté et journalisé, jamais interprété.
- `pack_ftc` **remplace** `facades`, `toiture` et `coupe`. La suppression a lieu
  **avant** le calcul : le total ne peut pas les cumuler.
- Les prestations sur devis ne sont **jamais** additionnées. Elles lèvent un
  indicateur et sortent du calcul.
- **La remise de 200 € sur un futur permis n'existe pas dans le calcul.** Ce
  n'est pas une réduction du prix de la conception mais un avantage sur une
  prestation ultérieure. Aucune fonction ne la soustrait, aucun montant n'en est
  dérivé, et le banc de mutation le prouve en montrant qu'une soustraction
  ajoutée fait tomber les contrôles.
- `modifs_sup` figure au catalogue mais **n'est pas exposée** dans la définition
  initiale : la série supplémentaire se propose à la livraison, quand le besoin
  est constaté, et non au moment de la commande.

**Conséquences.**
- Le catalogue serveur : base 449 €, façades 149 €, toiture 99 €, coupe 99 €,
  pack 299 €, plan de masse 149 €, 3D simple 149 €, série supplémentaire 99 €.
- Trois prestations sur devis : insertion 3D, projet complexe, demande
  particulière.
- La grille tarifaire de l'accueil emploie déjà 449 € et 649 € pour des
  prestations de permis de construire. Ces montants gardent leur sens propre :
  la distinction repose entièrement sur des **intitulés explicites**, point de
  vigilance à tenir dans toute rédaction commerciale ultérieure.

---

## D-013 — Aucune clé dynamique ne vient du navigateur

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Le prototype UX générait des noms de champs dans le navigateur :
`surf[Chambre 1]`, `surf[Salle de bain 1]`, `surf[Séjour]` — clés arbitraires,
accentuées, espacées, construites à partir de libellés affichés.

**Décision.** Le serveur **reconstruit** la liste des surfaces attendues.

- Les identifiants sont stables et sans accent : `sejour`, `chambre_1`,
  `sdb_1`, `terrasse_couverte`… Ce sont des clés de tableau et des noms de
  champs HTML, jamais des libellés.
- La définition arrête l'ensemble des **39 clés possibles**. La validation
  reconstruit ensuite, à partir des compteurs et des cases cochées, la liste des
  clés réellement **attendues**. Une clé doit franchir les deux barrières.
- Toute autre clé est **écartée et nommée**, sans erreur bloquante : un visiteur
  qui change d'avis ne doit pas être bloqué par une valeur restée dans le
  document.
- Le libellé lisible est reconstitué côté serveur. Il ne transite jamais.

**Conséquences.**
- Le banc de mutation mesure les deux barrières **séparément** : retirer l'une
  laisse l'autre protéger, retirer les deux laisse entrer les clés arbitraires.
- La même discipline s'appliquera à toute famille dynamique future.

---

## D-014 — La demande est écrite avant toute action externe

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Une soumission déclenche plusieurs effets : enregistrement,
notification à Urbizen, confirmation au client, et plus tard transmission au
service de génération. L'ordre de ces effets détermine ce qu'on perd quand l'un
d'eux échoue.

Le schéma naturel — valider, envoyer un courriel, et considérer que c'est fait —
fait reposer tout le dossier sur la délivrabilité d'un SMTP. Une panne de
Fluent SMTP, un rejet du destinataire, une adresse en quarantaine, et le
prospect disparaît sans laisser de trace.

**Décision.** La demande est **enregistrée en base avant tout appel externe**.

- Le courriel devient une **notification**, jamais le support de la demande.
- Un échec d'envoi est un incident réparable : la demande existe, elle est
  consultable, et l'envoi sera rejouable.
- Les métadonnées `_urbizen_mail_status` et `_urbizen_files_status` valent
  `not_started` en PR B1 et porteront l'état réel dès les PR B2 et B3.
- Si une métadonnée obligatoire ne peut pas être écrite, la demande
  partiellement créée est **supprimée** et l'échec est annoncé. Une demande
  amputée est pire qu'une absence de demande : elle laisse croire que le
  dossier est en main.

**Conséquences.**
- Aucune table SQL n'est créée. Une demande est un contenu WordPress privé,
  pas un schéma parallèle à maintenir et à migrer.
- `SubmissionRepository` est la **seule** couche autorisée à écrire une demande.
- La référence `URB-AAAA-NNNN` est attribuée par un compteur en option, mais
  l'unicité est vérifiée en base : deux soumissions simultanées se décalent au
  lieu de s'écraser.

---

## D-015 — Trois signaux anti-robot, aucun ne conservant d'adresse IP

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Un formulaire public reçoit des robots. Un seul garde-fou se
contourne ; il en faut plusieurs, indépendants. Mais la lutte contre le spam ne
justifie pas de constituer un fichier d'adresses IP.

**Décision.** Trois signaux, et **aucune adresse conservée**.

1. **Nonce WordPress**, action `urbizen_conception_submit`.
2. **Pot de miel** `company_website` : refus silencieux si rempli. Rien de ce
   qu'un robot a écrit n'est journalisé — ni la valeur, ni le nom du champ.
3. **Jeton signé** : identifiant aléatoire, heure d'émission, signature HMAC sur
   un sel WordPress. Le serveur en déduit le temps écoulé — un horodatage
   envoyé par le navigateur se falsifie en une ligne de JavaScript. Délai
   minimal 3 secondes, validité 24 heures, usage unique.

Le jeton consommé n'est mémorisé que sous forme de **condensat non réversible**,
dans un transient. Ni le jeton brut, ni sa signature, ni son identifiant
n'apparaissent en base.

**La limitation de débit ne conserve pas davantage.** Cinq soumissions par heure
et par origine, la clé du compteur étant un HMAC de l'adresse. Il permet de
reconnaître deux requêtes de la même origine, jamais de retrouver cette origine.

**Aucun en-tête de proxy n'est cru sur parole.** `X-Forwarded-For`, `X-Real-IP`
et `Client-IP` sont envoyés par le client : les accepter d'office offrirait un
contournement trivial de la limite. La source est `REMOTE_ADDR`. Un hébergement
derrière un proxy de confiance peut désigner un en-tête par le filtre
`urbizen_trusted_proxy_header` — décision explicite, jamais un défaut.

**Conséquences.**
- Les codes de refus sont **internes**. Expliquer à un robot pourquoi il a été
  refusé, c'est l'aider : la réponse publique reste générique.
- La comparaison de signature passe par `hash_equals()` : une comparaison naïve
  laisse fuir la signature attendue par mesure du temps de réponse.
- Le banc de mutation mesure chaque signal séparément.

---

## D-016 — Conservation limitée à 365 jours, sauf dossier client

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Une demande contient des données personnelles : nom, adresse
électronique, téléphone, localisation, description d'un projet. La conserver
indéfiniment n'est ni nécessaire, ni licite.

**Décision.** Purge quotidienne, **365 jours après le dernier contact**.

- Les états `received` et `closed` sont purgeables.
- L'état `converted` — la demande est devenue un dossier client — n'est
  **jamais** touché par ce mécanisme. Il relève d'une politique contractuelle et
  comptable distincte, à définir séparément.
- La durée vit à un seul endroit, ajustable par le filtre
  `urbizen_retention_days`. Une durée recopiée à trois endroits est une durée
  qu'on finit par ne plus respecter.
- Une durée nulle ou négative est ramenée à un jour : un filtre mal écrit ne
  doit pas pouvoir tout effacer au premier passage.
- Le hook `urbizen_before_submission_delete` est déclenché **avant** la
  suppression, la demande existant encore. La PR B2 s'y branchera pour effacer
  les fichiers : après, plus rien ne permettrait de les retrouver.

**Conséquences.**
- Une seconde barrière relit l'état de chaque demande avant de la supprimer :
  une requête de métadonnées mal interprétée ne doit pas pouvoir emporter un
  dossier client.
- La purge traite au plus 200 demandes par passage, pour ne jamais faire expirer
  une tâche planifiée. Le reliquat part au passage suivant.
- La tâche est programmée à l'activation et retirée à la désactivation, sous le
  nom `urbizen_purge_expired` que le `Deactivator` déprogrammait déjà.

---

## D-017 — L'atomicité repose sur l'unicité de `option_name`

**Date** : 20 juillet 2026 · **État** : actée · **Complète** [D-014] et [D-015]

**Contexte.** La première écriture de la réception employait des transients pour
mémoriser les jetons consommés et compter les soumissions. La revue a montré que
ce choix ne tenait pas sur deux points.

**Un transient exprime une durée maximale de conservation, jamais une
garantie.** Une purge du cache objet, un vidage LiteSpeed ou une éviction
mémoire peuvent le faire disparaître avant terme — et rendre réutilisable un
jeton déjà consommé.

**Et surtout, `lire puis écrire` n'arbitre rien.** Entre `is_used()` et
`mark_used()` s'ouvre une fenêtre par laquelle deux requêtes concurrentes
passent toutes les deux. Le même défaut affectait l'allocation des références :
deux requêtes pouvaient lire le même compteur avant que l'une n'écrive.

**Décision.** Toutes les réservations reposent sur **l'unicité de
`option_name`**, la seule primitive réellement atomique offerte par WordPress
sans table dédiée. `add_option()` échoue si le nom existe déjà, quel que soit le
nombre de requêtes simultanées.

| Ressource | Nom d'option | Contenu |
|---|---|---|
| Jeton | `urbizen_tok_<40 hex>` | état, expiration |
| Créneau de débit | `urbizen_rl_<32 hex>_<0..4>` | état, expiration |
| Référence | `urbizen_ref_URB-AAAA-NNNN` | état, date, demande |

Le nom porte un **condensat HMAC** : jamais le jeton, jamais sa signature,
jamais son identifiant lisible, jamais une adresse IP. **Toutes ces options sont
créées avec `autoload = false`** : elles ne pèsent sur aucune page.

**Le compteur de références reste, mais comme accélérateur seulement.** Il évite
de repartir de 1 à chaque allocation ; l'unicité vient de la réservation. Deux
barrières successives protègent en outre les références historiques, créées
avant ce mécanisme : la présence en base, puis la réservation atomique.

**Conséquences.**
- Une seconde requête portant le même jeton est refusée **pendant** le
  traitement de la première, sans attendre sa persistance.
- Une purge de cache ne rend rien réutilisable : les options vivent en base.
- Les entrelacements sont éprouvés de façon **déterministe** — la requête A
  s'arrête après avoir choisi son candidat, B s'exécute entièrement, puis A
  reprend — et non par une répétition qui espère tomber sur la course.
- Une référence libérée après échec n'est pas *bloquée*, mais le compteur ne
  recule pas : la série peut sauter un rang. C'est assumé — faire reculer un
  compteur rouvrirait exactement la course que la réservation vient de fermer.

---

## D-018 — Cinq demandes *enregistrées*, pas cinq tentatives

**Date** : 20 juillet 2026 · **État** : actée · **Remplace** la règle de comptage de [D-015]

**Contexte.** Le limiteur comptait toute tentative. Une personne qui oublie une
case obligatoire, corrige, se trompe encore, corrige — cinq allers-retours
ordinaires — se retrouvait bloquée une heure par sa propre application. Le
mécanisme censé écarter les robots punissait les clients.

**Décision.** Le quota porte sur les demandes **réellement enregistrées**.

Le limiteur fonctionne en trois temps : **réserver** un créneau avant le
traitement, le **libérer** si le traitement échoue pour une raison corrigible,
le **confirmer** une fois la demande écrite.

Ne consomment aucun créneau : `validation_failed`, `files_not_supported_yet`,
`pricing_failed`, `persistence_failed` — ni aucun refus de sécurité, qui
intervient avant la réservation.

**La réservation reste atomique.** Six requêtes valides simultanées ne peuvent
pas acquérir plus de cinq créneaux : leurs noms sont déterministes et numérotés,
et `add_option()` n'aboutit qu'une fois par nom.

Le jeton suit la même logique : un échec corrigible le rend, pour que la
personne puisse rectifier et renvoyer.

**Conséquences.**
- Confirmer ne repousse pas la fin de la fenêtre : un flux soutenu ne peut pas
  la prolonger indéfiniment.
- Les refus de sécurité restent bloqués mais ne sont jamais présentés comme des
  demandes enregistrées. Un dispositif anti-abus distinct pourra les traiter.

---

## D-019 — Une mise à jour ne doit rien exiger d'un administrateur

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** L'extension est active en production. Remplacer ses fichiers par
une nouvelle version **ne déclenche pas le hook d'activation** : la tâche de
purge, programmée uniquement à l'activation, n'aurait jamais existé sur un site
passé de 0.5.0 à 0.6.0. Il aurait fallu désactiver puis réactiver l'extension à
la main — ce qu'on ne peut pas exiger d'une mise en production, et qu'on
oublierait.

**Décision.** `Retention::ensure_scheduled()` est **idempotente** et appelée à
chaque chargement, sur `init`, en plus de l'activation.

Le coût est nul : `wp_next_scheduled()` interroge un tableau déjà chargé en
mémoire. Cent chargements ne créent qu'une tâche.

**Conséquences.**
- Les six scénarios sont éprouvés : installation neuve, remplacement de fichiers
  sans réactivation, chargements répétés, tâche déjà présente, désactivation,
  réactivation.
- La même tâche quotidienne assure le ménage des réservations techniques.
  Une réservation **attribuée** n'est jamais supprimée : la référence appartient
  à une demande et ne doit pas pouvoir resservir.

---

## D-020 — Données personnelles effaçables, registre des références permanent

**Date** : 20 juillet 2026 · **État** : actée · **Précise** [D-016] et [D-017]

**Contexte.** La revue a relevé une contradiction dans un compte rendu : une
réservation attribuée était présentée à la fois comme jamais supprimée et comme
libérée par la purge à 365 jours. Vérification faite, **le code était correct** —
c'était la prose qui ne l'était pas. Mais l'ambiguïté méritait d'être tranchée
et inscrite.

**Décision.** Deux natures de données, deux régimes.

**Les données personnelles** — nom, adresse électronique, téléphone, adresse du
terrain, description du projet — vivent dans la demande, contenu WordPress
privé. Elles sont **effacées 365 jours après le dernier contact**, sauf dossier
client. C'est la limitation de conservation.

**Le registre des références attribuées** est autre chose. Une option
`urbizen_ref_URB-AAAA-NNNN` portant l'état `attributed` est un **registre
technique permanent d'unicité**. Elle ne contient aucune donnée personnelle :
un état, une date technique d'attribution, l'identifiant du contenu WordPress.
Rien d'autre — ni nom, ni adresse, ni charge utile, ni adresse IP, ni fichier.

Elle n'est supprimée par **rien** : ni le nettoyage quotidien, ni la rétention,
ni la suppression de la demande. Sans elle, un numéro déjà communiqué à un
client pourrait être réattribué à un autre dossier des années plus tard — une
confusion que rien ne permettrait ensuite de démêler.

**Conséquences.**
- Effacer les données personnelles d'une demande **ne réautorise pas** l'usage
  de son numéro. Le pire des cas est éprouvé : demande supprimée, compteur remis
  à zéro, caches purgés — la référence n'est toujours pas réattribuée.
- Une réservation `reserved` reste, elle, temporaire : libérée si la persistance
  échoue, supprimée si elle est abandonnée depuis plus d'une heure.
- Le nettoyage ne touche **que** l'état `reserved`. Une valeur devenue illisible
  est conservée : garder une ligne inutile coûte une ligne, en supprimer une à
  tort rouvre une référence déjà donnée.
- Une ligne d'option par référence attribuée. Le volume suit celui des demandes
  réelles, et chaque ligne porte `autoload = false`.

---

## D-021 — La programmation du cron est protégée par un verrou atomique

**Date** : 20 juillet 2026 · **État** : actée · **Complète** [D-019]

**Contexte.** `wp_next_scheduled()` puis `wp_schedule_event()` est un « lire
puis écrire », exactement le motif écarté ailleurs. Juste après une mise à jour,
deux requêtes simultanées ne trouvent ni l'une ni l'autre de tâche, et en
programment deux. La démonstration d'idempotence portait sur des chargements
**successifs** ; elle ne disait rien de la concurrence.

**Décision.** Un verrou atomique, sur la même primitive que le reste : l'unicité
de `option_name`.

- **Chemin rapide** : si la tâche existe, on sort sans rien écrire. C'est le cas
  de l'immense majorité des requêtes — aucun verrou n'est même posé.
- **Sinon** : `add_option( 'urbizen_cron_lock', …, '', false )`. Une seule
  requête l'obtient ; les autres renoncent sans programmer.
- Le contrôle est **refait sous verrou** : entre le chemin rapide et
  l'obtention, une autre requête a pu programmer.
- Le verrou expire au bout de **30 secondes**. La section protégée se réduit à
  une lecture et une écriture ; un arrêt brutal au milieu ne doit pas empêcher
  la programmation pour toujours. Un verrou manifestement périmé est repris.
- Il est rendu immédiatement après. En fonctionnement normal, **aucun verrou ne
  subsiste** dans `wp_options`.

**Conséquences.**
- L'entrelacement est éprouvé de façon déterministe : A tient le verrou, B
  s'exécute entièrement et ne programme rien, A termine et rend le verrou, B
  repasse et constate que la tâche existe. Le nombre d'appels réels à
  `wp_schedule_event` est mesuré, et vaut exactement 1.
- Le verrou ne contient qu'une échéance. Aucune donnée personnelle.

---

## D-022 — Les documents vivent hors de la racine publique

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Croquis, photographies et relevés d'un projet sont des données
personnelles. La solution courante — un dossier de `wp-content/uploads` au nom
imprévisible — ne protège rien : un nom fuit par les journaux du serveur, par
le `Referer`, par une sauvegarde mal placée, par un listing mal configuré. La
seule barrière solide est qu'**aucun chemin d'URL ne mène au fichier**.

**Décision.** Les documents sont stockés **hors de la racine publique**.

L'emplacement est déduit de l'installation, jamais inscrit en dur : le parent
de `ABSPATH`, soit `<parent>/private/urbizen-conception`. Sur l'hébergement
actuel, cela donne un répertoire frère de `public_html` — que l'hébergeur
lui-même signale comme non servi.

Trois garde-fous entourent la résolution :

1. un chemin situé sous `ABSPATH` est **refusé avant toute création** — le
   refuser après l'avoir créé laisserait un répertoire dans l'arbre servi ;
2. un second contrôle après `realpath()` couvre le cas d'un lien symbolique
   qui ramènerait un chemin d'apparence privée dans la racine publique ;
3. le répertoire doit être inscriptible.

**En l'absence d'emplacement sûr, le stockage refuse.** Il ne se replie
**jamais** sur `wp-content/uploads` : une soumission refusée se corrige, un
document exposé ne se reprend pas. Le code interne est `storage_unavailable`.

Répertoires en `0700`, fichiers en `0600`. Un `index.php` et un `.htaccess`
sont posés en défense complémentaire — jamais comme protection principale.

**Conséquences.**
- Aucun document ne passe par la médiathèque WordPress : `wp_handle_upload()`
  déposerait le fichier derrière une URL publique. Un banc balaie tout le
  plugin pour le vérifier.
- Le seul accès est un lien signé, servi par un contrôleur dédié.
- Le chemin de stockage est configurable par la constante
  `URBIZEN_PRIVATE_STORAGE_DIR` et par le filtre
  `urbizen_private_storage_dir`, mais aucun réglage ne permet de contourner le
  refus des chemins publics.

---

## D-023 — Extension et contenu doivent concorder

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** `$_FILES['type']` est une **déclaration du navigateur**. Un
attaquant l'écrit ce qu'il veut. Une extension seule ne vaut pas mieux :
`photo.jpg` peut contenir du PHP.

**Décision.** Un document n'est accepté que si **trois** choses concordent :
une extension de la liste blanche, un type réel lu dans le contenu par
`finfo`, et le contrôle croisé de WordPress.

Formats acceptés : PDF, JPG, JPEG, PNG, WEBP. Refusés : SVG, GIF, HEIC,
bureautique, archives, exécutables, HTML, JavaScript, PHP, fichiers sans
extension, extensions doubles trompeuses.

Seule la **dernière** extension compte : `facture.pdf.php` a pour extension
`php`, et c'est bien ainsi qu'un serveur l'exécuterait.

Limites : 10 documents par bloc, 20 au total, 10 Mio par document, 25 Mio
cumulés — en octets réels, alignés sur `max_file_uploads` mesuré à 20.

**Conséquences.**
- Le banc de mutation mesure les deux barrières de type **séparément** :
  retirer la concordance laisse le contrôle croisé protéger, retirer le
  contrôle croisé laisse la concordance protéger, retirer les deux laisse
  passer un PHP renommé en JPG.
- La taille retenue est celle **du fichier sur le disque**, jamais celle
  annoncée.

---

## D-024 — Le traitement des documents est transactionnel

**Date** : 20 juillet 2026 · **État** : actée · **Complète** [D-014] et [D-017]

**Contexte.** Une soumission avec documents enchaîne une dizaine d'opérations
dont chacune peut échouer. Sans discipline, une panne au milieu laisse un
fichier sans demande, ou une demande annonçant des documents absents.

**Décision.** Deux temps, et un abandon complet à la moindre panne.

Les fichiers passent d'abord dans un **staging** identifié au hasard, avant
qu'une seule ligne ne soit écrite en base. Ils ne sont déplacés sous la
référence qu'une fois la demande créée. La demande, elle, naît en état
`pending` avec sa référence **simplement réservée** : elle n'est
**attribuée** qu'à la finalisation, quand tout est en place.

Invariants tenus, chacun éprouvé par un scénario de panne dédié : aucun
fichier permanent sans demande, aucune demande annoncée réussie avec des
documents incomplets, aucune référence attribuée avant la finalisation, aucun
staging résiduel, aucun jeton ni créneau consommé par un échec corrigible.

**Conséquences.**
- `files_status` suit le cycle `none` · `pending` · `stored` · `deleted`.
- Les documents sont effacés **avec** la demande, par le hook
  `urbizen_before_submission_delete` déclenché tant qu'elle existe encore.
- La réservation `attributed` survit à l'effacement des documents, comme elle
  survit à celui des données personnelles (D-020).
- Le nettoyage quotidien ne touche **que** le staging. Un document final n'est
  jamais supprimé au motif qu'une métadonnée semble manquante : en cas de
  doute, on conserve et on signale.

---

## D-025 — Les liens de téléchargement sont signés et temporaires

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Les documents étant hors de toute URL, il faut un moyen d'y
donner accès — notamment depuis un courriel ouvert sans session WordPress.

**Décision.** Un lien HMAC, valable **14 jours**, régénérable.

La signature couvre tous les champs : version du schéma, demande, document,
échéance. Modifier l'un d'eux invalide le lien. On ne peut ni prolonger une
échéance, ni glisser vers le document d'une autre demande.

L'URL ne porte **aucune** information métier : ni chemin, ni nom de fichier,
ni nom de personne, ni adresse, ni empreinte. Une URL se retrouve dans
l'historique du navigateur, dans les journaux du serveur et dans le `Referer`
envoyé au site suivant.

**Toute défaillance produit la même réponse** — un 404 identique. Signature
fausse, lien expiré, demande inexistante, document effacé : distinguer les cas
révélerait qu'une demande existe, et un identifiant de demande est un entier
qu'on essaie en quelques secondes.

**Conséquences.**
- Le contrôleur reconstruit le chemin par `Storage`, qui refuse toute sortie
  de la racine privée, tout lien symbolique et tout chemin inexistant.
- Le nom proposé au téléchargement est débarrassé des retours chariot, des
  guillemets et de tout chemin : il entre dans un en-tête HTTP.
- Aucun lien n'est affiché ni envoyé en PR B2. La PR B3 les emploiera dans le
  courriel administrateur.

---

## D-026 — Une interruption brutale se rattrape hors de la requête

**Date** : 20 juillet 2026 · **État** : actée · **Complète** [D-024]

**Contexte.** La PR B2 démontrait le nettoyage après des erreurs **interceptées**.
Elle ne disait rien d'une coupure de processus. Or quand PHP est tué, que le
serveur redémarre ou que la connexion tombe pendant l'écriture, ni `catch`, ni
`finally`, ni destructeur ne s'exécutent. Le rattrapage ne peut donc pas vivre
dans la requête.

**Décision.** Un **état durable**, relu par une requête ultérieure.

Chaque demande porte `_urbizen_transaction` — identifiant aléatoire, date de
début, état `processing` ou `committed`, staging, référence — et
`_urbizen_status = processing` tant que la transaction n'est pas achevée.
Aucune donnée personnelle n'y figure.

**Sept conditions doivent être réunies simultanément** pour qu'une transaction
soit jugée abandonnée : bon type de contenu, état `processing`, ancienneté
supérieure à une heure, absence de marqueur `committed`, référence lisible,
réservation encore `reserved`, et réservation rattachée à cette demande.
**Toute incertitude conduit à conserver et à signaler.**

L'ordre de nettoyage compte : le staging, puis les fichiers de la référence,
puis la demande, puis la réservation. À aucun moment un fichier ne survit à la
disparition de ce qui permet de le retrouver.

**Conséquences.**
- Le marqueur `committed` est posé **avant** l'attribution de la référence. Une
  coupure entre les deux laisse une demande que la récupération conserve,
  plutôt qu'une référence attribuée sans demande complète.
- La récupération passe **avant** le ménage des réservations : elle s'appuie
  sur la réservation `reserved` pour reconnaître une transaction abandonnée.
- Une réservation rattachée à une demande qui existe encore n'est jamais
  libérée par le ménage : c'est du ressort de la récupération.
- Neuf points d'arrêt sont éprouvés, chacun en abandonnant le traitement sans
  rollback puis en repartant comme d'une nouvelle requête.

---

## D-027 — La suppression est fermée par défaut

**Date** : 20 juillet 2026 · **État** : actée · **Corrige** [D-024]

**Contexte.** Un fichier qu'on ne parvient pas à effacer et dont on supprime
malgré tout la demande devient un **orphelin** : une donnée personnelle que plus
rien ne rattache à une personne, donc que plus rien ne permet d'effacer sur
demande. C'est précisément ce qu'une politique de conservation doit rendre
impossible.

`before_delete_post` ne pouvait rien empêcher : c'est une action.

**Décision.** Le blocage passe par le filtre **`pre_delete_post`**, seul capable
de court-circuiter `wp_delete_post()`, déclaré avec ses **trois** arguments.

Une API unique, `FileCleaner::delete()`, renvoie une issue explicite :
`success`, `already_deleted`, `partial_failure`, `unsafe_path`,
`filesystem_failure`. Un garde de réentrance évite les doubles nettoyages entre
la rétention, la suppression manuelle et le hook métier.

**Si le nettoyage échoue, la suppression n'a pas lieu.** La demande, ses
métadonnées et sa réservation attribuée sont conservées, l'état passe à
`delete_failed`, un code technique est consigné, et l'opération sera retentée.

---

## D-028 — Provenance HTTP et intégrité à la lecture

**Date** : 20 juillet 2026 · **État** : actée · **Corrige** [D-022]

**Contexte.** Deux fenêtres restaient ouvertes dans la PR B2.

`Storage::move_uploaded()` retombait sur `rename()` lorsque
`is_uploaded_file()` était faux. Un `tmp_name` forgé — `/etc/passwd`, un fichier
du dépôt, une sauvegarde — pouvait donc être déplacé dans le stockage privé,
puis servi par un lien signé.

Le téléchargement, lui, appelait `filesize()` puis `fopen()` : deux ouvertures
distinctes, sans vérifier l'empreinte. Un document remplacé entre les deux
aurait été servi sous couvert d'un lien valide.

**Décision.**

**Provenance** : une abstraction `UploadedFileMover`. L'implémentation de
production exige `is_uploaded_file()` puis `move_uploaded_file()`, **sans aucun
repli**. L'adaptateur d'essai n'est atteignable ni par filtre, ni par option, ni
par paramètre : `Storage::set_mover()` exige la ligne de commande ou une
constante définie hors du dépôt.

**Intégrité** : tout se fait sur **un seul descripteur** — `fstat()` pour la
taille, le flux pour le SHA-256, `hash_equals()` pour la comparaison, puis
rembobinage et diffusion. Refermer entre la vérification et la lecture rouvrirait
la fenêtre qu'on cherche à fermer.

Une atteinte à l'intégrité produit la **même réponse** qu'un document absent, et
ne journalise qu'un identifiant technique et le code `file_integrity_failed`.

---

## D-029 — Un corps écarté par PHP n'est pas un défaut de sécurité

**Date** : 20 juillet 2026 · **État** : actée

**Contexte.** Un corps de requête dépassant `post_max_size` est vidé par PHP
**avant** que le code ne s'exécute : `$_POST` et `$_FILES` arrivent vides. La
soumission se présentait alors comme dépourvue de nonce, et le visiteur recevait
un refus de sécurité pour un fichier simplement trop lourd — message trompeur et
incompréhensible.

**Décision.** Une détection précoce reconnaît la signature : requête POST,
`CONTENT_LENGTH` positif, ni champs ni fichiers, et longueur annoncée supérieure
à la limite. Le code interne est `request_too_large`, et aucun jeton, créneau ni
référence n'est consommé.

**Configuration relevée sur l'hébergement**, en lecture seule, sans rien
modifier : PHP 8.3.30 · `file_uploads` 1 · `upload_max_filesize` 1536M ·
`post_max_size` 1536M · `max_file_uploads` **20** · `upload_tmp_dir` vide
(`/tmp`) · `max_input_time` 360 · `max_execution_time` 360 · `memory_limit`
1536M.

La politique — 10 Mio par document, 25 Mio cumulés, 20 documents — est donc
applicable. **Un point de vigilance subsiste** : `max_file_uploads` vaut
exactement 20, soit notre plafond. PHP écarte silencieusement les fichiers
au-delà ; un envoi de 21 documents en verrait 20 arriver, sans que rien ne
signale la perte. Porter `max_file_uploads` à 21 permettrait de la détecter.
La politique serveur n'est pas réduite pour autant.

---

## D-030 — Le point de non-retour est l'attribution, pas le marqueur

**Date** : 21 juillet 2026 · **État** : actée · **Corrige** [D-026]

**Contexte.** La récupération conservait indéfiniment une transaction portant
`committed` mais dont la référence était restée `reserved`. La revue a montré
que ce n'était pas un état final acceptable.

Dans le modèle transactionnel, **une réponse de succès ne part qu'après
l'attribution définitive de la référence**. Une référence encore `reserved`
signifie donc que la transaction n'a jamais atteint son point irréversible — le
marqueur `committed` ne suffit pas à la rendre acceptée. La conserver
maintiendrait des documents et des données personnelles sans aucune finalité.

**Décision.** Trois issues, et trois seulement.

| Situation | Issue |
|---|---|
| Référence `reserved`, ancienneté dépassée, réservation rattachée | **annulation complète** |
| Référence `attributed` et tout concorde | **normalisation idempotente** du statut |
| Référence `attributed` mais quelque chose ne concorde pas | **conservation prudente**, aucun téléchargement, signalement |

Le point G rejoint donc A à F : aucun post, aucun fichier, aucun staging,
aucune réservation, aucune donnée personnelle résiduelle.

**Une référence `attributed` n'est jamais annulée.** La cohérence se juge sur
cinq critères : transaction `committed`, référence identique, `files_status` à
`stored` ou `none`, métadonnées obligatoires présentes, réservation rattachée au
bon contenu.

**Le rollback est fermé par défaut.** Si un seul fichier résiste, rien d'autre
n'est supprimé : la demande passe en `recovery_failed`, ses métadonnées et sa
réservation `reserved` sont conservées, et la tentative suivante reprendra. Un
nettoyage partiel n'est **jamais** compté comme un succès.

---

## D-031 — Un lien signé ne suffit pas

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-025]

**Contexte.** Une signature valable donnait accès au document, sans considérer
l'état de la demande. Un téléchargement pouvait donc survenir pendant une
suppression, ou après un nettoyage partiel.

**Décision.** Neuf conditions **cumulatives** avant toute ouverture de fichier :
bon type de contenu, statut métier dans une liste **fermée**
(`received`, `converted`, `closed`), transaction `committed`, référence de la
transaction identique, `files_status` exactement `stored`, réservation
existante, `attributed`, rattachée au même contenu, et au moins un document
déclaré.

Toute condition manquante produit **la même réponse** qu'un document absent ou
qu'une signature fausse.

**Le verrou est posé avant le premier `unlink`.** `_urbizen_status` passe à
`deleting` : à partir de cet instant, aucun lien ne fonctionne plus, y compris
pour les documents pas encore touchés. En cas d'échec partiel, l'état devient
`delete_failed` et le reste inaccessible ; le statut métier d'origine est
mémorisé à part, pour qu'une seconde tentative ne prenne pas `delete_failed`
pour l'état à restaurer.

---

## D-032 — `max_file_uploads` : prérequis avant publication

**Date** : 21 juillet 2026 · **État** : consignée

**Constat de production** : `max_file_uploads = 20`, exactement le plafond de la
politique applicative. PHP écarte **silencieusement** les fichiers au-delà : un
envoi de 21 documents en verrait 20 arriver, sans que rien ne signale la perte.

Ce point **ne bloque pas** la fusion du backend, le formulaire n'étant pas
public. Il devient **bloquant avant publication**. Deux voies :

1. porter `max_file_uploads` à **21 au minimum**, idéalement 25 ;
2. ou, en PR C, faire déclarer au client le nombre de documents par bloc et au
   total, puis vérifier que le nombre reçu correspond **exactement**.

Le manifeste ne permettrait jamais de dépasser les limites serveur : il sert
uniquement à détecter une perte silencieuse.

Aucune configuration Hostinger n'a été modifiée.

---

## D-033 — La Corbeille invalide les liens, sur deux verrous

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-031]

**Contexte.** L'audit a confirmé une faille : **aucun** hook de Corbeille
n'était enregistré — ni `pre_trash_post`, ni `pre_untrash_post`, ni
`untrashed_post`, ni `transition_post_status`. Le contrôleur de téléchargement
ne vérifiait que `post_type`, jamais `post_status`.

`wp_trash_post()` change le `post_status` sans toucher à l'état applicatif.
Une demande mise à la Corbeille — geste banal, souvent le premier réflexe pour
retirer un dossier — **restait donc téléchargeable** par ses liens signés,
alors que l'intention était précisément de la retirer.

**Décision.** Deux verrous complémentaires, chacun suffisant seul.

**Verrou applicatif.** `pre_trash_post`, avec ses **trois** arguments, passe
`_urbizen_status` à `trashed` **avant** que la Corbeille ne soit effective. Le
statut précédent est mémorisé **une seule fois** dans
`_urbizen_pre_trash_status`, et seulement s'il appartient à la liste fermée
`received` · `converted` · `closed`. Un état transitoire ou fautif ne se met
pas à la Corbeille : on ne saurait pas quoi restaurer ensuite.

Si l'invalidation ne peut être écrite et **vérifiée**, la mise à la Corbeille
est refusée. Mieux vaut une demande qui reste en place qu'un document
accessible alors qu'on croyait l'avoir retiré.

**Verrou natif.** Le téléchargement exige en outre un `post_status` figurant
dans une liste fermée : **`private` uniquement**, seule valeur que le
repository écrit. Sont refusés `trash`, `draft`, `pending`, `future`,
`auto-draft`, `inherit`, un statut absent, et tout statut inconnu. Ce verrou
tient même si un autre greffon ou un appel direct modifie le statut sans passer
par nos hooks.

**Conséquences.**
- La mise à la Corbeille ne supprime **aucun fichier** : elle rend seulement
  les documents inaccessibles. L'effacement physique reste l'affaire de la
  suppression définitive, qui passe par `FileCleaner`.
- La restauration exige **onze conditions**, dont la référence `attributed`
  rattachée au bon contenu. Elle rétablit le statut mémorisé **exactement** :
  une demande `converted` ne revient pas en `received`.
- Si le rétablissement échoue, la demande passe en `incoherent` plutôt que de
  retrouver un statut téléchargeable par défaut.
- `trashed` rejoint les états purgeables : une demande à la Corbeille conserve
  ses données personnelles, et l'en exclure la rendrait immortelle.
- Le vidage automatique emprunte `wp_delete_post()`, donc `pre_delete_post` :
  aucun second mécanisme de suppression physique n'est introduit.

---

## D-034 — Une mise à la Corbeille se rejoue

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-033]

**Contexte.** L'invalidation applicative précède le changement de
`post_status` : entre les deux, un autre greffon peut court-circuiter
`wp_trash_post()`, ou l'écriture native échouer.

L'audit du comportement antérieur montre que l'état n'était **pas bloqué** —
le téléchargement restait refusé et une nouvelle tentative aboutissait. Mais
rien ne permettait de **distinguer** une demande simplement *préparée* d'une
demande réellement *mise à la Corbeille* : aucune trace, aucun hook postérieur.
La rétention, la suppression définitive et la restauration raisonnaient donc
sur une apparence.

**Décision.** Un état durable de transition, `_urbizen_trash_transition`, à
deux valeurs : `prepared` et `completed`. Contenu minimal — un état, le statut
applicatif précédent, une date technique. Aucune donnée personnelle.

`pre_trash_post` mémorise **une seule fois**, écrit la transition `prepared`,
invalide, puis **relit chaque écriture**. `trashed_post` — seul hook exécuté
*après* le changement de `post_status` — confirme en `completed`. Il ne touche
ni aux fichiers, ni à la référence, et ne réactive aucun téléchargement.

**Conséquences.**
- Une nouvelle tentative est **idempotente** : elle réutilise le statut
  mémorisé, ne crée pas de seconde transition, n'écrase rien, et laisse
  WordPress retenter le passage natif.
- Tant que la transition est `prepared`, l'intention de suppression reste
  **fermée par défaut** : aucun téléchargement, aucune restauration
  automatique, aucune normalisation vers un état téléchargeable, aucun fichier
  supprimé, aucune référence libérée.
- La rétention **ne purge pas** un état `prepared` resté en `private` :
  l'ambiguïté ne se tranche pas toute seule. La suppression définitive y est
  également bloquée — jamais de post supprimé laissant des fichiers.
- Une restauration exige la transition **`completed`** : une simple préparation
  ne vaut pas mise à la Corbeille.
- `TrashGuard::reconcile()` répare sans rien détruire : elle confirme une
  transition dont le `post_status` est bien passé à `trash`, et marque
  `incoherent` une demande invalidée sans transition. Une transition seulement
  préparée est laissée telle quelle, rejouable.
- La restauration réussie supprime les deux métadonnées temporaires.

---

## D-035 — Deux statuts, deux restaurations

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-034]

**Contexte.** Une demande Urbizen porte deux statuts qu'il ne faut jamais
confondre :

- le **statut natif** WordPress, `private` — il décide de la visibilité du
  contenu, et il conditionne la remise des documents ;
- le **statut métier**, `received` / `converted` / `closed` — il décrit
  l'avancement du dossier.

Depuis WordPress 5.6, `wp_untrash_post()` ne rend plus son statut d'origine à
un contenu non joint : il le place en **`draft`**. Le comportement est
volontaire côté cœur — restaurer un article en `publish` le republierait sans
que personne l'ait décidé. Pour une demande, la conséquence était l'inverse
d'une protection : le dossier repassait en `draft`, la condition `private`
n'était plus remplie, et **tous ses documents devenaient inaccessibles pour
toujours** — sans erreur, sans trace, sans que la restauration paraisse avoir
échoué.

**Décision.** Rétablir explicitement `private`, et ne jamais s'y fier seul.

`wp_untrash_post_status` est filtré en **priorité 20**, avec ses trois
arguments. Il ne rend `private` que si quatre conditions sont réunies : le
contenu est une demande Urbizen, il est encore à la Corbeille, son statut natif
précédent était `private`, et aucun contrôle de cohérence ne s'y oppose. Dans
tous les autres cas, la valeur proposée par WordPress est rendue telle quelle —
un autre type de contenu n'est jamais touché.

La priorité 20 place notre règle après le défaut du cœur et après la plupart
des greffons. Elle n'est **pas** une garantie, et n'a pas à en être une : une
priorité extrême resterait contournable. La véritable barrière est ailleurs.

`untrashed_post` relit le `post_status` **réellement écrit**. S'il ne vaut pas
`private`, la restauration applicative n'a pas lieu. La sécurité ne dépend donc
d'aucun ordre d'exécution.

**Conséquences.**
- Une demande restaurée retrouve `private`, puis son statut métier **exact** —
  jamais une valeur par défaut, jamais `received` pour un dossier `closed`.
- Le téléchargement ne redevient possible qu'après la réussite **complète** des
  deux restaurations. Entre les deux, il reste refusé.
- Un greffon tiers proposant `draft` ou `publish` avant nous n'a aucun effet.
  Le même greffon exécuté **après** nous obtient bien son statut — et la
  demande est alors marquée `incoherent`, accès fermé : nous ne réécrivons pas
  par-dessus lui, nous refusons de rouvrir les documents.
- Toute défaillance — écriture native en échec, statut final inattendu,
  incohérence, statut métier non rétabli — marque la demande `incoherent`,
  **conserve** l'intégralité des métadonnées de diagnostic, et laisse l'état
  retentable. Les métadonnées temporaires ne sont supprimées qu'après la
  réussite complète.
- `wp_untrash_post_set_previous_status()` n'est **pas** employé : il
  rétablirait l'ancien statut pour *tous* les contenus du site, bien au-delà de
  notre domaine.
- La doublure de test applique le défaut `draft` du cœur et respecte les
  priorités des filtres. Tant qu'elle restaurait implicitement l'ancien statut,
  elle rendait le défaut invisible — et neuf mutations muettes.

---

## D-036 — Une restauration interrompue se répare, elle ne se contourne pas

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-035]

**Contexte.** Le cœur de WordPress efface `_wp_trash_meta_status` et
`_wp_trash_meta_time` **avant** d'écrire le statut de sortie de Corbeille. Si
cette écriture échoue, `wp_untrash_post()` rend `false`, le contenu reste à la
Corbeille — et plus rien n'indique d'où il venait. Une seconde tentative reçoit
un statut natif précédent **vide** et se voit refusée. La demande est bloquée
pour toujours.

Le compte rendu de la revue précédente affirmait le contraire — qu'une seconde
tentative aboutissait. C'était vrai de la doublure, qui conservait
artificiellement les métadonnées natives. Ce n'était pas vrai de WordPress.

**Décision.** Une réparation explicite, `TrashGuard::repair_native()`, plutôt
qu'une restauration interne qui simulerait le cycle du cœur.

Elle ne rétablit que les deux métadonnées natives, et seulement lorsque toute
la cohérence Urbizen est démontrée : contenu encore à la Corbeille, statut
applicatif `trashed`, transition `completed`, statut métier mémorisé
restaurable, transaction `committed`, référence `attributed` et rattachée au
même contenu, `files_status` final, métadonnées obligatoires complètes, et
métadonnée native effectivement absente.

**Conséquences.**
- La réparation ne change pas le `post_status`, ne restaure aucun statut
  métier, ne supprime aucune métadonnée Urbizen, ne réactive aucun lien, ne
  touche ni aux fichiers ni à la référence. Elle rend le cycle natif rejouable,
  rien de plus. Les téléchargements restent fermés jusqu'à la restauration
  complète.
- `_wp_trash_meta_time` reprend l'horodatage de la transition, pas l'heure
  courante : une réparation ne doit ni prolonger ni raccourcir le délai avant
  purge automatique.
- Idempotente. Une valeur native déjà correcte est un succès ; une valeur
  native **inattendue** n'est pas écrasée en silence.
- Protégée par un verrou `add_option()` par demande, en `autoload = false` et
  avec expiration. Deux tentatives simultanées ne peuvent pas écrire deux
  valeurs différentes ; un verrou périmé est repris.
- Une restauration interne atomique a été écartée : elle aurait dû reproduire
  toutes les garanties du cycle natif, et aurait supposé d'appeler
  `untrashed_post` à la main pour simuler une opération du cœur.

---

## D-037 — Le retour d'une écriture ne prouve pas l'écriture

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-036]

**Contexte.** `update_post_meta()` rend `false` dans deux situations que rien
ne distingue au retour :

- l'écriture a réellement échoué ;
- la valeur demandée était **déjà** enregistrée, et aucune modification n'était
  nécessaire.

Le dépôt lisait ce retour comme un booléen de réussite. `finalize()` réécrivait
`_urbizen_files_status` avec la valeur que `persist()` venait de poser : sur un
vrai WordPress, la première vérification échouait systématiquement. Toute
soumission rendait un succès, mais la transaction restait `processing`, la
référence restait `reserved`, `_urbizen_status` n'atteignait jamais `received`
— et la récupération transactionnelle supprimait le dossier une heure plus
tard.

Le défaut était invisible : la doublure rendait un identifiant en toutes
circonstances. Il a été trouvé par l'audit de parité contre le cœur 7.0.2, et
n'a jamais atteint la production — `finalize()` est né avec la PR #19.

**Décision.** `SubmissionRepository::persist_meta()` : écrire, relire, et ne
conclure que sur la relecture.

- Un `false` suivi d'une relecture conforme est un **succès**.
- Un `true`, ou un identifiant, suivi d'une relecture divergente est un
  **échec**.

**Conséquences.**
- Quatre emplacements corrigés : la boucle de `persist()`, `set_files()`, et
  les trois écritures de `finalize()`. Aucun autre appel du dépôt ne lit ce
  retour.
- La comparaison suit le type **écrit**, jamais le type relu : tableaux et
  objets comparés après restitution, booléens selon la représentation WordPress
  (`'1'` et chaîne vide), entiers et flottants en chaîne, chaînes — dont le
  JSON des transactions et des documents — **strictement**, caractère pour
  caractère. Deux JSON sémantiquement égaux mais textuellement différents ne
  sont pas équivalents.
- `update_option()` porte la même ambiguïté. Aucun appel de production n'en
  dépend aujourd'hui ; la doublure en est néanmoins rendue fidèle, pour qu'un
  futur usage fautif tombe dans les bancs plutôt qu'en production.
- Les doublures sont une commodité, pas une preuve. Un banc d'intégration
  s'exécute désormais contre un vrai WordPress 7.0.2 jetable.

---

## D-038 — Une notification est une conséquence, jamais une condition

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-037]

**Contexte.** Urbizen doit être prévenue lorsqu'un dossier est accepté. La
tentation est de faire de l'envoi une étape de la soumission. Ce serait une
faute : un serveur de messagerie indisponible transformerait alors une demande
parfaitement valide en soumission échouée, et le demandeur, qui n'y est pour
rien, recommencerait.

**Décision.** Séparer ce qui doit être garanti de ce qui doit être retenté.

Ce qui est **garanti** : à la finalisation, une notification `pending` est
enregistrée durablement, avec un identifiant serveur, **avant** que la demande
ne soit déclarée reçue. Si cette écriture échoue, la finalisation échoue et le
retour arrière transactionnel s'applique. Le succès garantit donc simultanément
transaction `committed`, référence `attributed`, statut `received`,
`files_status` final, identifiant de notification et `mail_status` `pending`.

Ce qui est **retenté** : l'envoi lui-même. Cinq tentatives au plus, espacées de
0, 5 min, 30 min, 2 h et 12 h, puis `failed`. Chaque tentative relit
l'éligibilité complète, prend un verrou atomique et relit l'état sous ce
verrou.

**Conséquences.**
- Un transport indisponible ne change rien au dossier : il reste `received`, sa
  référence reste attribuée, ses documents restent en place.
- Rien n'est envoyé pour une demande qui n'est pas, à l'instant même,
  pleinement cohérente. Une transition de Corbeille, même seulement préparée,
  suffit à tout suspendre.
- **La garantie est « au moins une fois », et c'est assumé.** `wp_mail()` ne
  permet pas mieux : une interruption peut survenir après l'appel et avant
  l'écriture de `sent`. Un état `sending` abandonné est donc repris. Un doublon
  exceptionnel, reconnaissable à son en-tête `X-Urbizen-Notification-ID`, vaut
  mieux qu'une notification définitivement perdue.
- `wp_mail()` rendant `true` signifie que **WordPress a accepté la requête
  d'envoi** — pas que le message est arrivé. L'état `sent` ne prétend rien de
  plus, et la documentation le dit.
- Le destinataire vient d'une constante serveur, d'un filtre, ou de
  `admin_email` — jamais d'une donnée de formulaire. Sans adresse valide,
  l'envoi est refusé, fermé, avec le code `recipient_unavailable`.
- Aucune pièce jointe : les documents restent derrière les liens signés de B2,
  générés au moment du rendu, jamais stockés, jamais journalisés.
- La base ne conserve que des états, des compteurs et des horodatages. Ni
  corps, ni destinataire, ni lien, ni signature, ni chemin — rien qui ferait
  d'elle une seconde copie des données personnelles.
- Le journal du serveur web n'étant pas lisible en SSH chez l'hébergeur,
  l'exploitation ne peut pas en dépendre : tout l'état utile est **persisté et
  consultable depuis l'administration**.

---

## D-039 — Un état partagé se sérialise, il ne se surveille pas

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-038]

**Contexte.** La première version de la notification prenait un verrou pour
l'envoi, et pour lui seul. Tout le reste — annulation à la Corbeille,
restauration, reprise administrative, suppression, planification — écrivait le
même état sans coordination.

Le défaut a été reproduit sur un vrai WordPress, avec **deux processus
distincts** : l'un arrêté juste avant le transport, l'autre mettant la demande
à la Corbeille. Résultat mesuré : la Corbeille aboutissait, `mail_status`
passait à `cancelled`, puis l'envoi reprenait la main, appelait `wp_mail()` et
écrivait `sent` **par-dessus** l'annulation. Un courriel partait pour un
dossier retiré, et la base affirmait le contraire de ce qui s'était passé.

Deux appels successifs dans un même processus n'auraient jamais montré cela :
ils partagent le cache d'objets, les variables statiques et l'ordre
d'exécution.

**Décision.** Un **verrou commun par notification**, et toute transition passe
par lui.

Le verrou porte un jeton propriétaire aléatoire et une échéance — rien d'autre,
et surtout aucune donnée personnelle. `release_lock()` vérifie le jeton :
un processus ne peut plus supprimer le verrou d'un autre. Le nettoyage de
fichiers le faisait, sans le savoir.

**Conséquences.**
- Mise à la Corbeille et suppression définitive sont **refusées** tant qu'un
  envoi est en vol. Elles restent rejouables dès le verrou rendu : différer
  vaut mieux qu'écrire par-dessus quelqu'un.
- L'éligibilité est relue une dernière fois **immédiatement avant** l'appel au
  transport, cache d'objets purgé — puis une nouvelle fois après, avant
  d'écrire `sent`. Si la demande s'est fermée pendant l'appel, le courriel est
  parti — la politique « au moins une fois » l'assume — mais l'annulation n'est
  pas écrasée, et le fait est consigné.
- Le TTL passe de 300 à **600 secondes**, avec un plancher à
  `max_execution_time + 1`. Un verrou ne doit jamais expirer pendant que son
  propriétaire peut encore s'exécuter : la production autorise 360 secondes.
- `schedule_unique()` encadre `wp_next_scheduled()` et
  `wp_schedule_single_event()` par le verrou. Pris séparément, ces deux appels
  ne sont pas atomiques : deux processus peuvent constater la même absence.
- **`sent` est une preuve historique.** Ni la Corbeille, ni la restauration, ni
  l'action administrative ne l'effacent ou ne le rejouent. `cancelled` ne
  concerne que `pending`, `retry` et `sending`.
- La limite demeure, et doit être dite : `wp_mail()` peut avoir rendu `true`
  juste avant une interruption, sans que `sent` ait été écrit. Dans cet état,
  nous ne connaissons pas la livraison réelle, et nous ne prétendons pas la
  connaître.

---

## D-040 — Un bail n'est pas une preuve de vie

**Date** : 21 juillet 2026 · **État** : actée · **Complète** [D-039]

**Contexte.** La sérialisation de D-039 reposait sur un bail : une option
portant un propriétaire et une échéance. Le raisonnement était que
`LOCK_TTL = 600` dépassant `max_execution_time = 360`, un propriétaire dont le
bail a expiré est nécessairement mort.

Ce raisonnement est faux hors Windows. `max_execution_time` ne comptabilise pas
le temps passé dans certaines opérations système — flux, réseau, appels
externes. Un envoi bloqué dans un transport peut donc survivre à son bail.
Deux processus se croient alors simultanément légitimes : l'un envoie, l'autre
ferme le dossier.

Reproduit avec deux processus réels, bail volontairement court, transport
volontairement long : le bail expirait, le propriétaire vivait, et rien ne les
distinguait.

**Décision.** Une exclusion mutuelle dont la propriété est **liée à la vie du
processus** : `flock()` sur un fichier technique.

La détention est attachée au descripteur ouvert. Le noyau la refuse tant que le
propriétaire vit, et la libère à sa disparition — fin normale, coupure ou
`kill -9`. C'est exactement la question qu'un bail ne sait pas poser.

Vérifié en lecture seule sur l'environnement cible : ext4 local, refus
inter-processus, libération automatique après terminaison forcée.

**Conséquences.**
- **Ordre d'acquisition unique** : mutex de processus, puis bail d'option. Posé
  en un seul endroit — `MailQueue::with_lock()` —, ce qui exclut l'interblocage.
  Aucun composant ne prend l'une des deux couches directement.
- **Le mutex fait autorité.** Le bail décrit le propriétaire logique et sert à
  l'observabilité et à la réconciliation différée ; il ne décide plus rien seul.
  Une expiration de bail, mutex encore détenu, ne permet aucune transition.
- Le propriétaire vivant réconcilie son bail sous le mutex avant d'écrire
  `sent`, `retry` ou `failed`.
- Après une mort réelle, le mutex se libère seul ; le bail subsiste jusqu'à son
  échéance, puis la réconciliation constate que plus personne ne travaille et
  reprend l'état `sending` — politique « au moins une fois » inchangée.
- Les fichiers techniques vivent sous la racine privée, en `0700` / `0600`,
  vides, nommés par HMAC. Ils ne sont pas supprimés à chaud : sur un système
  POSIX, supprimer puis recréer un chemin pendant qu'un autre processus détient
  encore l'inode donnerait deux verrous indépendants portant le même nom. Seule
  la suppression définitive d'une demande les efface, sous le mutex acquis.
- **Mode dégradé fermé.** Racine indisponible, répertoire non créable, chemin
  non confiné, lien symbolique, ouverture refusée : rien n'a lieu. Jamais de
  repli silencieux sur le bail seul.
- Le plancher de durée du bail demeure, comme précaution secondaire. Il ne se
  lève que sous `URBIZEN_TESTING`, constante définie hors du dépôt — le mode CLI
  seul ne suffit pas, les tâches planifiées s'y exécutant aussi.
