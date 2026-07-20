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
