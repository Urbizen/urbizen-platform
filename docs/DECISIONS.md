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

**Mise à jour — descopé (Lot 2, C2C).** La ventilation « Surface par pièce »
(`surfaces`) décrite ici a été **entièrement retirée** : facultative, non
tarifante et jamais réellement collectée, elle est remplacée par la **surface
globale** `surface` et le champ libre `pieces_detail`. Les deux barrières et
leurs clés n'existent plus dans le formulaire Conception. La discipline « aucune
clé dynamique du navigateur » **reste** la règle pour toute famille future ; voir
la mise à jour C2C de **D-051**.

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

---

## D-042 — `max_file_uploads` : le manifeste clôt D-032

**Date** : 21 juillet 2026 · **État** : actée · **Clôt** [D-032]

**Contexte.** `max_file_uploads` vaut 20 en production. Au-delà, PHP livre une
partie des fichiers **sans le signaler**. Le serveur ne peut pas connaître un
fichier qui ne lui est jamais parvenu : rien ne distingue « l'utilisateur en a
joint 19 » de « il en a joint 20 et l'un s'est perdu ».

**Décision.** Le navigateur déclare ce qu'il envoie ; le serveur compare avec
ce qu'il reçoit. Cette comparaison clôt D-032, sans toucher à Hostinger.

**Preuves, sur un vrai WordPress 7.0.2, par requêtes multipart écrites octet
par octet et envoyées sur une socket :**

- manifeste 20, corps contenant 19 parties → **`upload_incomplete`** ;
- dernière partie coupée en plein contenu → **`upload_partial`** ;
- **témoin** manifeste 20, corps contenant 20 parties → succès complet :
  demande finalisée, référence `attributed`, notification `pending`, un
  événement mail, vingt documents stockés, aucun courriel externe.

Le témoin est indispensable : sans lui, les deux refus auraient pu venir d'une
cause parasite. Ils l'ont d'ailleurs fait une fois — `token_too_fast` — et
c'est le témoin qui l'a révélé.

**Ce que le manifeste est, et n'est pas.**

- Il est **contrôlé mais non fiable par nature** : un client peut y écrire
  n'importe quoi. Sa forme est validée strictement — clés exactes, entiers
  canoniques, cohérence du total avec la somme des blocs, blocs connus — mais
  son contenu reste une affirmation.
- Il exprime **ce que le navigateur affirme avoir sélectionné**, avant
  transport.
- Il sert uniquement à **détecter une différence** entre cette affirmation et
  ce que PHP a réellement reçu.
- Il **ne valide aucun fichier** et **ne remplace jamais `UploadPolicy`** :
  extension réelle, type réel, provenance HTTP, tailles et nombres continuent
  de s'appliquer intégralement. Un manifeste parfaitement exact ne fait passer
  ni un SVG, ni un onzième document dans un bloc.

**Les tailles comparées sont mesurées**, par `filesize()` sur le fichier
temporaire — jamais `declared_size`, qui n'est qu'une prétention de la requête.
Une mesure impossible — fichier absent, effacé, illisible, chemin vide,
répertoire — refuse la soumission plutôt que de convertir `false` en zéro.

**Conséquences.**
- `max_file_uploads` reste à **20**. Aucune modification Hostinger n'a été
  nécessaire, ni demandée.
- Le formulaire limite le client à vingt documents ; le manifeste détecte les
  réceptions partielles ; le refus est transactionnel — ni demande finalisée,
  ni référence attribuée, ni notification, ni document, ni staging.
- Aucun faux succès n'est possible : sans comparaison certaine, on refuse.

## D-043 — La maquette de référence, pas la production, fait foi visuellement

**Contexte.** La propriétaire a jugé le formulaire de conception « ancien et
générique », et a désigné les formulaires DP et PC comme référence visuelle
obligatoire.

Deux objets portent ce nom, et ils ne se ressemblent pas :

1. les **pages publiques** `/declaration-prealable/` et `/permis-de-construire/`,
   qui servent aujourd'hui des formulaires Fluent Forms — Poppins, Open Sans,
   rayon 7px, bouton `#002D6B` ;
2. les **maquettes versionnées** `frontend/formulaires/dp-formulaire.html` et
   `pc-formulaire.html` — papier quadrillé, cartouche, rail de légende, Space
   Grotesk, IBM Plex, vert `#128A5A`, rayon 3px.

La référence retenue est la **seconde**. Les pages publiques sont destinées à
être refaites : s'aligner dessus reviendrait à copier ce qui doit disparaître.

**Conséquences.**

- Les deux maquettes portent un CSS **strictement identique** — comparaison
  ligne à ligne, zéro différence. Il n'y a donc qu'une référence, pas deux, et
  aucun arbitrage à rendre entre elles.
- Là où la maquette et `urbizen-tokens.css` divergent, c'est la maquette qui
  l'emporte : rayon **3px** et non 4px, coque **960px** et non 1120px.
- La palette annoncée en consigne (`#0B1F3A`, `#7BDCB5`, `#F6F8FB`) est celle du
  gabarit Hostinger, pas celle de la maquette. Elle n'est pas retenue :
  l'encre est `#14233B`, l'accent `#128A5A`, le papier `#EAEEF2`.
- `urbizen-conception.css` ne déclare aucun token global : elle **consomme**
  `var(--u-*)` avec le repli de la maquette, comme `urbizen-form.css` et
  `urbizen-cadastre.css`. Le rendu global reste au thème (D-002).
- Le thème enfant ne met sa charte en file que sous le gabarit de l'accueil.
  `ConceptionAssets` déclare donc `urbizen-fonts` et `urbizen-tokens` en
  dépendance de sa propre feuille — sans jamais en embarquer de copie, et sans
  réenregistrer un handle que le thème aurait déjà posé.
- L'alignement est **entièrement en CSS**. Aucun champ, aucune étape, aucune
  règle tarifaire, aucun calcul serveur, aucun brouillon, aucun manifeste,
  aucune limite de dépôt n'est touché.

**Ce qui n'est pas repris.** La maquette ouvre sur un cartouche d'en-tête —
sur-titre monospace, titre, sous-titre, logo, rose des vents. Le rendu serveur
ne produit aucun de ces éléments : les ajouter demanderait de modifier
`ConceptionRenderer`, hors du périmètre d'un commit de style. La numérotation
des étapes reste « 1 » et non « 01 », pour la même raison.

## D-044 — Tables propres derrière WordPress, migrations en avant seulement

**Contexte.** Le socle des comptes (phase E) exige des relations que
`user_meta` ne sait pas porter : « cette personne est administratrice de
l'agence A et simple lectrice chez B » est une relation *utilisateur ×
organisation*, pas une paire clé-valeur. Jusqu'ici le greffon n'avait **jamais
écrit une ligne de SQL** — zéro `$wpdb`, zéro `dbDelta` en cinquante classes.
E1 franchit ce seuil.

**Décision.** WordPress fournit **exclusivement** l'identité,
l'authentification, les sessions, la récupération de mot de passe et les
primitives CSRF. Le domaine Urbizen — organisations, adhésions, projets,
autorisations, documents — vit dans des tables propres, derrière des
interfaces.

**Conséquences.**

- **Le domaine ignore WordPress.** Aucun fichier de `src/Domain/` n'appelle une
  fonction WordPress, ne référence `WP_User` ou `WP_Post`, ne touche `$wpdb`.
  Un banc l'éprouve par analyse lexicale — commentaires et chaînes écartés,
  faute de quoi il signalerait sa propre documentation — et une seconde couche
  confronte les identifiants à la table des symboles d'un WordPress chargé.
- **Une seule porte d'autorisation**, à refus par défaut. Aucune vue n'appelle
  `current_user_can()` : c'est ainsi qu'une règle finit par exister en deux
  exemplaires divergents. `administrator` n'est pas un court-circuit implicite.
- **Les migrations sont en avant seulement.** Pas de méthode `annuler()` : une
  migration qui supprime une colonne ne peut pas la restaurer, et lui donner
  une méthode d'annulation aurait promis une réversibilité qui aurait fini par
  être crue un jour de panne. Le retour arrière repose sur quatre niveaux —
  rollback du code, migration compensatrice, procédure manuelle, restauration
  de sauvegarde.
- **Le DDL de MariaDB n'est pas transactionnel.** `CREATE TABLE` valide
  implicitement ; une migration interrompue laisse un schéma partiel qu'aucun
  `ROLLBACK` ne rattrape. D'où : `appliquer()` idempotente, `verifier()` fondée
  sur le schéma réel et non sur le registre, une migration par requête, et
  aucune inscription avant vérification positive.
- **Le registre est l'unique source de vérité** de l'état du schéma. Aucune
  option miroir : deux sources divergent toujours.
- **Aucune migration ne part du trafic.** L'exécuteur n'est accroché à aucun
  hook ; son seul point d'entrée est `wp urbizen schema migrate`. Faire dépendre
  l'état d'un schéma des visites, c'est confier une migration au premier
  visiteur venu, éventuellement plusieurs à la fois, éventuellement sur un
  greffon à moitié copié.
- **Le catalogue est vide en E1**, et c'est ce qui rend la garantie mesurable :
  catalogue vide, retour immédiat, aucune requête. Prouvé par une doublure qui
  lève à tout appel, et par le compteur `$wpdb->num_queries` sur un WordPress
  réel.
- **Deux barrières, jamais une.** Le verrou d'exécution coordonne ; la
  contrainte `UNIQUE` du registre refuse le doublon même si le verrou tombait.

**Règle de discipline, à faire tenir dans les PR suivantes.** *Aucune classe,
table ou interface ne rejoint le socle sans un usage écrit dans la même PR.*
Les entités `Organization`, `Membership` et `Project`, ainsi que toutes les
interfaces de dépôt, ont été délibérément **retirées d'E1** : une interface
écrite avant sa première implémentation est une conjecture. Elles naîtront en
E3 et E4, avec leurs cas d'usage, leurs invariants, leur persistance et leurs
bancs d'intégration.

**Ce qui n'est pas décidé ici.** La durée de purge des brouillons, le nom
définitif du rôle client (introduit en E2), et le modèle des documents
(inchangé jusqu'en E5).

## D-045 — Comptes WordPress, sans table propre

**Contexte.** La phase E exige un socle de comptes particuliers. D-044 a posé
la règle : aucune table sans démonstration qu'un `user_meta` ne suffit pas.

**Décision.** E2 n'a besoin que d'identité, mot de passe, session, état de
vérification et jeton en cours. Les quatre premiers appartiennent à WordPress ;
les deux derniers sont **mono-valués par utilisateur**, sans jointure ni tri.
`user_meta` suffit, et la démonstration du contraire n'existe pas. **Aucune
table n'est créée.** Les tables viendront en E3, quand apparaîtront des
relations *utilisateur × organisation* que `user_meta` ne sait pas porter.

**Conséquences.**

- **`Compte` n'est pas `ActeurCourant`.** Le premier est la ressource visée,
  le second le sujet qui agit. `Authorization::peut( 'compte.modifier', $compte )`
  demande si l'acteur courant peut modifier ce compte-là. `Compte` ne porte donc
  pas de rôles : ils appartiennent à celui qui agit.
- **Le domaine valide, l'adaptateur normalise.** Reproduire `sanitize_email()`
  dans `AdresseCourriel` l'obligerait à suivre WordPress sans jamais pouvoir le
  vérifier ; deux normalisations divergentes valent moins qu'une seule.
- **Absence vaut « non vérifié ».** `_urbizen_courriel_verifie` ne connaît
  qu'une valeur, la chaîne `'1'`. Tout le reste — absente, vide, `true`, `'oui'` —
  signifie non vérifié. L'administratrice historique est donc non vérifiée :
  exister dans `wp_users` ne prouve pas qu'une adresse a été confirmée.
- **Le jeton est lié à ce qu'il confirme.** Le condensat couvre identifiant,
  cible et génération. Sans la cible, un lien émis pour une adresse en attente
  pourrait en confirmer une autre — il suffirait de demander un changement, de
  recevoir le lien, puis d'en demander un second avant de cliquer.
- **Le verrou est temporaire, jamais une marque de consommation.** Une marque
  permanente rendrait un jeton légitime inutilisable après un simple échec de
  promotion. Le condensat n'est effacé qu'après une vérification **relue** — la
  seule preuve d'écriture, leçon déjà payée sur les métadonnées de demande.
- **Aucune lecture de stockage ne précède le verrou.** Le contrôle préalable de
  `consommer()` est une validation d'**arguments** — identifiant positif, jeton
  de forme plausible — qui n'interroge ni la base ni les métadonnées. Il
  n'autorise rien et n'écarte que des valeurs qui ne peuvent pas être un jeton,
  quel que soit l'état du stockage. La propriété se prouve donc par l'ORDRE des
  opérations, non par un entrelacement : le code correct ne lisant rien avant le
  verrou, rien ne peut y devenir périmé, et la course n'a pas lieu.
- **Une seule émission peut être en vol à la fois.** Confirmer après l'envoi ne
  suffisait pas : entre la préparation de P1 et son départ, rien ne disait que
  P1 existait, et P2 pouvait préparer un second jeton qui invalidait le premier
  avant même qu'il parte. Deux courriels arrivaient alors, dont l'un portait un
  lien déjà mort, sans que le destinataire puisse distinguer lequel.
  `_urbizen_verif_emission_en_attente` est l'état manquant ; `confirmer` et
  `annuler` exigent l'identifiant rendu à la préparation, de sorte qu'un appelant
  lent ne puisse pas clore l'émission d'un autre.
- **Annuler détruit le jeton ; le conserver n'aurait aucun sens.** Le jeton brut
  n'est pas stocké : il n'existait que dans la réponse rendue à l'appelant. Un
  jeton « conservé » serait un condensat que plus personne ne peut satisfaire,
  occupant la place du suivant. Le quota, lui, reste intact : on peut repréparer
  aussitôt.
- **Consommer un jeton décompte le créneau si son émission est encore ouverte.**
  Suivre le lien est la preuve d'envoi la plus forte qui soit. Sans ce décompte,
  cliquer plus vite que l'appelant ne confirme rendrait le créneau gratuit, et
  l'opération répétée viderait le quota de sa fonction.
- **Le rôle est réconcilié en place, jamais retiré puis reposé.** Contrairement
  à ce qu'on pourrait croire, `remove_role()` ne vide pas le `wp_capabilities`
  des utilisateurs : le rôle recréé leur revient. Le danger est la **fenêtre** —
  entre le retrait et la repose, aucun objet de rôle ne répond, `read` est
  refusée à tout client dont une requête passe là, et une mort du processus
  laisse l'installation sans rôle. La correction se fait donc capacité par
  capacité, et le rôle figure dans chaque écriture de l'option.
- **La garde `profile_update` est comptée, par utilisateur.** Globale, elle
  ferait taire l'invalidation d'un compte pendant qu'on en promeut un autre dans
  la même requête. Simple booléen, elle ne survivrait pas à l'imbrication : la
  promotion interne, en se retirant, désarmerait celle qui l'englobe.
- **Le rôle n'est jamais installé par le trafic.** Une visite ne doit provoquer
  aucune écriture d'installation, même principe que l'exécuteur de migrations.
  `wp urbizen accounts install` est le chemin réel d'un déploiement, un `rsync`
  ne déclenchant pas le crochet d'activation.
- **Une seule capacité, `read`.** Elle sert la navigation, jamais une décision.
  Aucune politique ne lit de rôle, et un contrôle lexical l'exige de chacune.
- **Un échec ne détruit rien.** Un compte créé dont l'émission échoue demeure,
  non vérifié et récupérable : le supprimer effacerait un mot de passe déjà
  choisi par quelqu'un.

**Ce qui n'est pas décidé ici.** Les écrans, les courriels et le changement
d'adresse public appartiennent à E2.2 ; la suppression et l'anonymisation des
comptes à la phase RGPD ; l'authentification à deux facteurs à la phase H.

## D-046 — Le parcours public des comptes n'ouvre que quatre portes

**Contexte.** E2.1 a posé un socle complet mais entièrement fermé : aucun écran,
aucune route, aucun courriel. Le code existe, il est éprouvé, et rien ne
l'appelle en production. E2.2 ouvre ce socle au public, et c'est le moment où
les erreurs cessent d'être théoriques.

**Décision.** E2.2 livre **quatre parcours, et rien d'autre** : inscription d'un
particulier, émission et renvoi du courriel de vérification, consommation du
lien, changement d'adresse. L'authentification, la session, la reconnaissance
par adresse et la réinitialisation du mot de passe restent à **WordPress** :
E2.2 n'écrit aucun mécanisme de session, aucun cookie, aucun jeton
d'authentification.

**Conséquences.**

- **Une vérification réussie ne connecte pas.** Donner une session à quiconque
  suit un lien reçu par courriel ferait de l'accès à une boîte un accès au
  compte. Le lien confirme une adresse ; il n'ouvre rien.
- **La réponse d'inscription ne dépend pas de l'existence de l'adresse.**
  Accepter de dire « cette adresse est déjà prise » offrirait un annuaire de la
  clientèle à qui prendrait la peine de sonder. Inscription et renvoi public
  rendent le même code 303, la même page et le même message, que l'adresse soit
  libre, prise non vérifiée, prise vérifiée, ou que le quota soit épuisé.
- **Le renvoi public passe par l'action d'inscription.** `InscriptionService`
  sait déjà relancer un compte non vérifié et refuser un compte vérifié ; une
  seconde action ferait une seconde règle à tenir en cohérence. Le renvoi
  demandé depuis une session ouverte, lui, est une action distincte soumise à
  une politique.
- **Le lien ne consomme rien en GET.** La page affiche l'adresse concernée et un
  bouton, sans toucher au jeton ; la consommation part en POST. Un lien qui
  vérifierait en GET serait consommé par le premier antivirus de messagerie ou
  préchargeur qui le suit, et le client recevrait un lien mort sans avoir rien
  fait.
- **L'ancienne adresse reste celle du compte jusqu'à la consommation.** Le
  changement écrit `_urbizen_courriel_en_attente` puis émet un jeton vers la
  **nouvelle** adresse ; la promotion n'a lieu qu'à la consommation, et
  `WpComptes` la protège de sa garde. L'ancienne adresse est prévenue du
  changement demandé, **sans lien ni jeton** — c'est le seul avertissement dont
  dispose quelqu'un dont la boîte a été compromise.
- **Un seul chemin de code émet, et il ne connaît pas `wp_mail()`.**
  `EnvoiVerification` reçoit un `MailTransport` par construction et appelle le
  contrat existant sans le modifier. Le contrôle lexical de
  `tests/submissions/test-compat.php` reste **inchangé au caractère près** ;
  E2.2 lui ajoute un contrôle **additif** exigeant que `MailTransport::send()`
  ne soit appelé, dans le domaine des comptes, que depuis `EnvoiVerification`.
- **Une exception du transport est un échec, sans distinction.** `ok = false` et
  une exception mènent au même appel, `annuler_emission()`, immédiatement. La
  laisser remonter laisserait l'émission en vol jusqu'à son expiration : le
  compte resterait fermé cinq minutes après un envoi qui n'a jamais eu lieu, et
  le quota serait juste mais le client bloqué sans raison.
- **Après `ok = true`, l'annulation est interdite, sans exception.** Le message a
  été accepté par le transport et le lien est peut-être déjà dans une boîte ;
  annuler détruirait le jeton d'un lien vivant. Un échec de
  `confirmer_emission()` est **journalisé**, l'émission en attente reste posée,
  et elle sera rejouée ou expirera. Le seul risque est un créneau non décompté ;
  le risque inverse serait un client bloqué avec un lien mort.
- **Le verrou n'est pas tenu pendant l'envoi.** Tenir un verrou de 60 secondes
  pendant un envoi SMTP le ferait expirer en cours de route. C'est précisément
  la raison d'être de l'émission en attente.
- **`admin-post.php`, pas de route REST.** Une route REST imposerait `fetch()`,
  donc JavaScript, sur un parcours qui doit rester accessible. Chaque action
  répond par une **redirection 303** vers une page de résultat générique, dont
  l'URL ne porte ni adresse, ni jeton, ni motif technique. `admin_post_nopriv_*`
  n'est déclaré que pour les trois actions réellement anonymes : un visiteur
  déconnecté ne doit pas même atteindre le code des deux actions de session.
- **La politique n'intervient pas sur les actes anonymes.** Une politique répond
  à la question « cet acteur peut-il agir sur cette ressource ». À l'inscription
  il n'y a ni acteur ni ressource ; au renvoi public l'appelant n'a rien prouvé ;
  à la consommation, l'autorisation **est** la validation du condensat par
  `VerificationService::consommer()`. Y superposer `AutorisationComptes`
  donnerait l'illusion d'un contrôle supplémentaire là où le vrai contrôle est
  déjà cryptographique.
- **Le nonce anonyme n'est pas une protection et ne sera pas présenté comme
  telle.** WordPress calcule les nonces à partir de l'identifiant de
  l'utilisateur, qui vaut **zéro pour tout visiteur déconnecté** : c'est une
  valeur partagée, obtenue en chargeant la page publique, et rejouable. La
  protection des actions anonymes repose sur un empilement dont aucun étage ne
  suffit seul — contrôle de méthode, nonce, jeton anti-robot du socle de
  formulaires, limitation par origine réseau, réponse uniforme, `LimiteEnvois`
  par compte. Sur les deux actions de session, le nonce redevient une protection
  CSRF liée à l'utilisateur, et il **s'ajoute** à la politique métier.
- **La clé d'idempotence vit dans le même enregistrement que l'effet.**
  `_urbizen_verif_envois` ne portait que des horodatages : si l'écriture du
  quota réussissait et que l'effacement de l'émission échouait, une seconde
  confirmation décomptait un second créneau. `_urbizen_verif_emissions` devient
  la **source de vérité**, seule lue pour décider, et porte `{a, e}` —
  horodatage et identifiant d'émission. Un marqueur séparé n'aurait fait que
  déplacer la fenêtre.
- **Le miroir n'est jamais lu pour décider, et jamais un recours.**
  `_urbizen_verif_envois` reste écrit au format 0.11.0, réécrit **en entier**
  depuis la source à chaque confirmation qui aboutit. Lire le miroir pour
  « sauver » une source corrompue transformerait un état incompris en
  autorisation, c'est-à-dire exactement l'inversion contre laquelle tout le
  reste est bâti.
- **Absente ≠ corrompue.** Source absente : amorçage depuis le miroir, chaque
  horodatage devenant `{a: t, e: ""}`. Source corrompue : `quota_illisible`,
  refus, aucune émission jusqu'à intervention. Un identifiant vide ne peut
  jamais correspondre, donc les créneaux hérités **bornent sans autoriser** —
  c'est la direction sûre.
- **Au-delà de `MAX`, l'état est corrompu, et un quota corrompu est traité comme
  plein.** On ne tronque pas silencieusement : tronquer choisirait quelles
  émissions oublier, ce qui est exactement la décision qu'on n'a pas les moyens
  de prendre.
- **L'émission en attente n'est effacée que si source et miroir ont été
  écrits.** C'est elle qui permet le rejeu ; le rejeu reconnaît l'identifiant,
  saute l'ajout, réécrit le miroir et efface l'émission, **sans second
  créneau**.
- **Bornes réelles.** Sous 0.12.0 le quota est exact en toutes circonstances. Le
  miroir peut être en retard d'un nombre quelconque de confirmations — de 0 à
  `MAX` entrées — sans effet sur aucune décision ; son seul effet est un retour
  arrière en 0.11.0, où le code hérité sous-compterait jusqu'à `MAX` créneaux.
  À la montée 0.11.0 → 0.12.0, une confirmation restée en suspens sera rejouée
  sans que son identifiant soit reconnu et ajoutera **un créneau de trop**, une
  seule fois par compte, dans le sens restrictif.
- **Aucune purge de quota.** Purger détruit un mécanisme de sécurité et le
  besoin ne le justifie pas. `wp urbizen accounts quota-verify` est en **lecture
  seule** par défaut et rend un code de sortie non nul en cas de divergence ;
  `--repair-mirror` réécrit le **miroir seul** depuis la source, ne touche
  jamais la source, ne supprime aucun créneau et ne peut donc jamais élargir un
  droit.
- **Aucun `sleep()`, aucun délai artificiel.** Une attente fixe ne masque pas le
  canal : le temps d'un envoi réel varie de plusieurs ordres de grandeur, et une
  constante choisie au hasard est soit trop courte pour couvrir la variance,
  soit assez longue pour devenir une arme — chaque requête de sondage
  immobiliserait un processus PHP, transformant la protection en amplificateur
  de saturation.
- **Le canal temporel résiduel est réel et demeure exploitable.** Une
  inscription sur adresse libre crée un utilisateur et remet un message au
  transport ; sur adresse déjà vérifiée, elle sort presque aussitôt. L'écart est
  mesurable, et **une origine distribuée le rend praticable** en échappant à la
  limitation par origine. E2.2 **réduit** ce canal, elle ne le ferme pas. Le
  fermer supposerait une mise en file uniforme et un envoi hors requête, à
  démontrer et à éprouver ; son absence est un risque **assumé et consigné**,
  non un point réglé. Les bancs n'éprouvent donc que ce qui est vrai et stable :
  corps et statuts **identiques à l'octet près**.
- **La page portant le jeton ne fuit pas par ses en-têtes.**
  `nocache_headers()`, `Cache-Control: private, no-store, max-age=0`,
  `Referrer-Policy: no-referrer` — sans quoi le jeton partirait dans le `Referer`
  de la première ressource externe — `X-Robots-Tag: noindex, nofollow` doublé
  d'un `<meta name="robots">`, aucun contenu tiers ni police distante, aucune
  journalisation applicative du jeton, et après le POST une redirection 303 vers
  une URL **nettoyée**.
- **Le jeton reste néanmoins dans les journaux d'accès, et ce n'est pas
  masqué.** Le parcours devant fonctionner sans JavaScript, le lien porte le
  jeton dans sa chaîne de requête ; un fragment `#` ne serait pas lisible par un
  formulaire sans script. Le risque se formule exactement : quiconque peut lire
  les journaux d'accès, dans les 24 heures de validité et **avant** que le
  destinataire n'ait cliqué, peut consommer le jeton à sa place. Ce qui le
  borne : la validité de 24 heures, l'usage unique, l'invalidation à la
  consommation, et le fait qu'une vérification ne donne aucune session. Le
  supprimer demanderait du JavaScript ou un code court saisi à la main — deux
  options qui restent un arbitrage ouvert.
- **La consommation distingue quatre issues seulement** : succès, lien expiré,
  lien invalide ou déjà utilisé, indisponible. La fusion des deux derniers n'est
  pas une coquetterie : les séparer révélerait qu'un compte donné existe et n'a
  pas de jeton en cours.
- **Une assertion historique est resserrée, non affaiblie.** Celle d'E2.1 qui
  exige qu'aucune vue ni contrôleur n'utilise l'infrastructure d'autorisation
  sera réécrite pour **nommer les deux seuls contrôleurs** autorisés à le faire,
  inventoriée et justifiée dans un commit dédié, puis réprouvée par mutation —
  même procédure qu'en E2.1.
- **Dix-neuf mutations obligatoires**, dont chacune doit faire tomber un
  contrôle **nommé**, et aucun fichier muté ne doit subsister.
- **Les pages WordPress portant les shortcodes sont un geste d'exploitant.**
  Aucune page n'est créée ni publiée par le code.

**Ce qui n'est pas décidé ici.** L'espace client et son tableau de bord ; le
rattachement des demandes existantes à un compte ; la suppression et
l'anonymisation RGPD ; les organisations et l'espace professionnel ;
l'authentification à deux facteurs ; la réinitialisation du mot de passe,
laissée à WordPress ; toute table propre, qui reste soumise à D-044.

## D-047 — Préparation assistée et vérifiable des pièces d'urbanisme

**Contexte.** Le dépôt sait recueillir une demande et constituer un PDF :
`backend/dp-service/` produit notice, bordereau et assemblage, et convertit les
photographies en pages. Il ne remplit aucun Cerfa — les mappings de `cerfa.py` sont
tous en `TODO_` — et l'extension ne l'appelle jamais : `src/Backend/` est vide. Le
composant cadastre obtient déjà de l'IGN l'adresse, la parcelle et sa géométrie, mais
**depuis le navigateur du visiteur**, et la géométrie est explicitement exclue du
contrat 1.0 (D-009). Aucune pièce graphique n'est produite. La question n'est donc pas
d'améliorer une automatisation existante, mais de décider ce que l'on automatise, ce
que l'on assiste, et ce que l'on refuse d'automatiser.

**Décision.** Les pièces se répartissent en **trois niveaux**, et rien ne passe de l'un
à l'autre sans une nouvelle décision.

**Niveau 1 — automatisation forte.** Une fois les entrées nécessaires **obtenues et
validées**, la génération est **déterministe** et ne demande **aucun dessin manuel** :
deux exécutions sur les mêmes entrées rendent la même pièce. Cela ne signifie pas
« sans intervention humaine » : la confirmation de la référence cadastrale est un acte
de la personne, et c'est lui qui ouvre le niveau 1.

| Pièce | Entrées |
|---|---|
| Recherche et confirmation de la référence cadastrale | adresse saisie, **confirmation par la personne** |
| Géométrie de la parcelle, **récupérée par le serveur** | identifiant de parcelle |
| DP1/PCMI1 — plan de situation | géométrie serveur, fond cartographique |
| Préremplissage des Cerfa | champs du formulaire, références cadastrales |
| Bordereau des pièces | inventaire des pièces réellement jointes |
| Mise en page, légendes et classement des photographies | fichiers fournis par le client |

**Niveau 2 — automatisation assistée, validation humaine obligatoire.** Le système
propose, une personne d'Urbizen relit, corrige et valide. **Aucune de ces pièces ne
part sans cette validation**, et la validation est un acte tracé — identité et date —
non une case cochée par défaut.

| Pièce | Produite à partir de |
|---|---|
| DP2/PCMI2 — plan de masse | implantation saisie : emprise, dimensions, orientation, distances aux limites, accès, stationnement, arbres, réseaux |
| DP3/PCMI3 — coupe du terrain et de la construction | implantation et altimétrie |
| DP4/PCMI5 — façades et toiture | modèles et données du projet |
| DP5, DP6/PCMI6 — représentation et insertion | modèles, photographies, données du projet |
| DP7/DP8, PCMI7/PCMI8 — planches photographiques | photographies fournies, repérage sur plan |
| Notice descriptive (PCMI4) | formulaire, préremplie puis relue |

**Niveau 3 — ce qui n'est pas automatisé, et ne le sera pas ici.** Ces limites ne sont
pas des réserves de prudence : ce sont des règles opposables, chacune devant être tenue
par un contrôle nommé.

- **Le cadastre n'est jamais présenté comme une délimitation juridique de propriété.**
  Le parcellaire est une donnée **fiscale**, **millésimée** et périodiquement
  actualisée, dont la contenance est indicative. Toute pièce et toute interface qui
  l'affichent portent cette mention. Seul un géomètre-expert borne une limite.
- **Aucune promesse de plans architecturaux entièrement automatiques.** Ni dans le
  produit, ni dans la documentation, ni dans un support commercial.
- **Les plans définitifs sont contrôlés, réalisés ou validés et fournis par Urbizen.**
  Une pièce de niveau 2 non validée est un brouillon interne, jamais un livrable.
- **Aucune transmission automatique à l'administration.** Le dépôt sur le guichet
  dématérialisé reste un acte humain, vérifié et signé. Le système ne détient aucun
  identifiant de téléprocédure.
- **Le serveur récupère lui-même les données officielles.** Une géométrie envoyée par
  le navigateur n'est jamais acceptée, pour une pièce ni pour un calcul. C'est
  l'application de D-013 à la donnée géographique : ce qui vient du navigateur est une
  **intention**, jamais une source.
- **La provenance est conservée avec la donnée.** Source, date de récupération,
  références et version du référentiel sont conservées **intégralement dans les
  métadonnées du dossier**. Sur la pièce elle-même, seules sont reproduites **la date,
  la source et les attributions nécessaires** : une planche surchargée de références
  techniques devient illisible. Une pièce dont la provenance n'est pas retrouvable
  n'est pas auditable.
- **Les cas limites sont traités explicitement, jamais par un repli silencieux :**
  adresse ambiguë — la personne tranche, le système ne choisit pas ; projet sur
  plusieurs parcelles — plusieurs identifiants, jamais une fusion inventée ;
  indisponibilité d'une API — la pièce n'est pas produite et le motif est affiché,
  plutôt qu'une pièce fausse ou une géométrie périmée.

---

**Planches photographiques — DP7/DP8 et PCMI7/PCMI8.**

C'est une **préparation assistée**, jamais une garantie automatique de conformité
administrative. Le système propose des points de vue et des images candidates ; il ne
certifie rien.

- **La source principale reste une photographie récente fournie par le client.** Tout le
  reste est un préremplissage. **Le remplacement par une photographie téléversée par le
  client est possible à tout moment**, sur chaque planche, sans condition.
- **Deux points de prise de vue sont proposés automatiquement** : environnement proche
  et paysage lointain, chacun avec son orientation et ses consignes de prise de vue.
- **Une image est refusée, ou soumise à remplacement**, lorsqu'elle est trop ancienne,
  ne montre pas clairement le terrain, est obstruée, est prise depuis un angle
  inadéquat, ou ne correspond plus à l'état actuel du terrain.

**Deux actes de validation distincts, et le second est bloquant.**

| Acte | Qui | Objet |
|---|---|---|
| Confirmation | le client | la photographie correspond bien à son terrain et à son **état actuel** |
| Validation | Urbizen | **aptitude administrative** et cadrage de la pièce |

L'assemblage définitif du dossier **reste bloqué tant que la validation Urbizen
manque**. La confirmation du client ne s'y substitue jamais : elle porte sur ce que le
client est seul à savoir, l'état réel du terrain ; la validation Urbizen porte sur ce
qu'il n'a pas à connaître, la recevabilité de la pièce.

**Google Street View — ce qui est garanti, et ce qui ne peut pas l'être.**

Street View est une **aide visuelle ponctuelle** et rien d'autre. Un contrôle lexical
seul serait une fausse garantie : il ne reconnaîtra jamais une capture d'écran Street
View téléversée comme un fichier ordinaire. La décision ne garantit donc que ce qui est
**techniquement démontrable** :

- **aucun appel ni endpoint Google Street View dans le code** ;
- **aucune récupération, conservation ou fourniture d'image Google par Urbizen** ;
- **liste fermée des sources internes autorisées à l'assemblage** — une image dont la
  source n'est pas dans cette liste n'entre pas dans un document ;
- **provenance obligatoire pour toute image proposée par le système** ;
- **attestation de droits et contrôle humain pour tout fichier téléversé** — c'est la
  seule barrière qui puisse répondre d'une capture d'écran, et elle est humaine.

Le contrôle lexical **complète** ce dispositif ; il n'en est pas le fondement et ne
doit jamais être présenté comme suffisant.

**Panoramax — un seul point d'entrée dans le premier périmètre.**

Panoramax peut proposer une image de remplacement ou de préremplissage là où la
couverture existe. Dans le premier périmètre, **`panoramax.ign.fr` est la seule instance
automatisée autorisée**, sous **Licence Ouverte 2.0**. Les autres instances et les
licences CC-BY-SA restent **hors périmètre** jusqu'à une analyse distincte : une
obligation de partage à l'identique ne s'introduit pas par accident dans un dossier
client.

**Toute image Panoramax porte sa fiche de provenance**, conservée et affichée :
identifiant, instance source, auteur, licence, URL, date de prise de vue, date de
récupération.

**Report du point et de l'angle — trois métadonnées distinctes, à ne pas confondre.**

| Métadonnée | Ce qu'elle donne | Ce qu'elle ne donne pas |
|---|---|---|
| `Orientation` | la rotation ou le retournement à appliquer aux **pixels** | **aucune** information sur la direction de visée |
| `GPSLatitude`, `GPSLongitude` | le **point** de prise de vue | la direction |
| `GPSImgDirection` | la **direction** de visée, lorsqu'elle existe | rien si absente, et sa fiabilité est variable |

Confondre `Orientation` avec l'angle de prise de vue produirait des flèches fausses sur
les plans. **Quand `GPSImgDirection` est absente ou insuffisamment fiable, la direction
est saisie ou confirmée à la main sur la carte** — la flèche n'est jamais devinée.

**Toutes ces métadonnées sont des entrées non fiables.** Elles proviennent d'un fichier
fourni : types, plages et coordonnées sont validés, et une confirmation humaine
intervient avant tout report sur une pièce.

Le point et l'angle sont reportés **sur le plan de situation dès le lot 3** ; le report
**sur le plan de masse arrive avec le lot 4**, qui le produit.

**Prérequis du lot photographique, consignés et non traités ici :**

1. Corriger la nomenclature du formulaire DP — DP6 insertion, DP7 environnement proche,
   DP8 paysage lointain.
2. Extraire les informations EXIF utiles depuis l'original **avant toute conversion**.
3. Appliquer l'orientation EXIF (rotation des pixels) avant génération.
4. Obtenir le **consentement** avant d'exploiter ou de conserver la géolocalisation.
5. Ne conserver que les données nécessaires au dossier, **pas l'intégralité des
   métadonnées EXIF**.
6. Prendre en charge le HEIC, ou prévoir une conversion guidée côté client.
7. Mettre en place le dispositif Street View ci-dessus : absence d'appel et d'endpoint,
   liste fermée des sources à l'assemblage, provenance obligatoire, attestation de
   droits et contrôle humain au téléversement — le contrôle lexical en complément.

---

**Conséquences.**

- **Seul l'identifiant traverse le formulaire ; la géométrie est refaite côté serveur.**
  Le navigateur transmet ce qu'il transmet déjà — identifiant de parcelle, code INSEE,
  section, numéro — et le serveur réinterroge l'IGN. **Le contrat 1.0 de D-009 n'est pas
  rouvert** et la géométrie n'y entre pas.
- **Le composant cadastre cesse d'être le seul appelant de l'IGN.** Aujourd'hui aucune
  donnée ne parvient à un serveur Urbizen depuis ce composant ; demain l'identifiant y
  parvient, et c'est notre serveur qui interroge l'IGN. On déplace un appel du visiteur
  vers nous, ce qui nous rend responsables du cache, de la rétention et du quota. La
  politique de confidentialité et AI_CONTEXT devront le refléter **au moment de la
  livraison**, pas avant.
- **Panoramax devient un service externe supplémentaire**, avec ses quotas, sa couverture
  inégale et ses obligations d'attribution. Il rejoindra le tableau des services externes
  d'AI_CONTEXT quand il sera réellement appelé.
- **Les données officielles sont mises en cache, et le cache est daté.** Un cache expiré
  ne produit pas une pièce périmée : il déclenche une nouvelle récupération, ou un refus.
- **Une pièce de niveau 2 non validée n'est jamais livrable.** L'état de validation vit
  avec la pièce, porte l'identité du valideur et la date, et son absence bloque
  l'assemblage du dossier final.
- **Le préremplissage Cerfa est déterministe mais aujourd'hui inopérant.** Les mappings
  de `cerfa.py` sont tous en `TODO_`.
- **La conversion d'image actuelle détruit l'EXIF utile.** `_image_to_pdf()` convertit en
  RGB puis réenregistre en JPEG : orientation et données GPS disparaissent. Le lot 3 ne
  peut pas s'appuyer dessus en l'état.
- **Aucune table n'est créée par cette décision.** D-044 s'applique.

**Ordre de réalisation et dépendances techniques — deux choses différentes.** L'ordre
1 → 5 est un ordre **choisi** ; il n'est pas la carte des dépendances. Plusieurs lots
peuvent avancer en parallèle dès que leurs dépendances propres sont tenues.

| Lot | Contenu | Dépendance technique réelle |
|---|---|---|
| 1 | DP1/PCMI1 — plan de situation, géométrie serveur | pont HTTP authentifié |
| 2 | Cerfa préremplis, bordereau, notice | mappings `cerfa.py` et données validées — **pas le lot 1** |
| 3 | Planches photographiques DP7/DP8, PCMI7/PCMI8 | lot 1 pour le report sur le plan de situation, plus ses sept prérequis — **pas le lot 2** |
| 4 | Éditeur simple d'implantation, puis DP2/PCMI2 | lot 1 |
| 5 | Coupe, façades, insertion — DP3/PCMI3, DP4/PCMI5, DP5, DP6/PCMI6 | lot 4 |

**Ce qui n'est pas décidé ici.** Le choix des référentiels altimétriques et des fonds
cartographiques imprimables ; le format d'échange de l'implantation ; la technologie de
rendu des pièces graphiques ; le modèle de stockage des versions de pièces ; le seuil
d'ancienneté au-delà duquel une photographie est refusée ; l'ouverture à d'autres
instances Panoramax et aux licences CC-BY-SA ; la tarification des lots ; le dépôt sur
le guichet dématérialisé, qui reste hors du système.

---

## D-048 — Le script d'accueil du thème est une copie manuelle, à écart documenté

**Contexte.** La feuille de l'accueil est **générée** : `scripts/scope-css.py` transforme
`frontend/homepage/homepage.css` en `wordpress/urbizen-child/assets/css/urbizen-homepage.css`
en préfixant chaque sélecteur par `.urbizen-accueil`. Le **script**, lui, ne l'est pas :
`wordpress/urbizen-child/assets/js/urbizen-homepage.js` est une **copie manuelle** de
`frontend/homepage/homepage.js`. Aucun script de synchronisation n'existe pour le JS.

Deux écarts volontaires séparent désormais la copie de sa source :

1. **Pas de montage manuel du cadastre.** Sous WordPress, le bloc `urbizen/cadastre` monte
   son propre conteneur ; un `mount()` manuel provoquerait un double montage.
2. **Sélection des cartes projet conditionnée au tunnel de l'accueil.** La même feuille et
   le même script sont partagés par les pages internes (déclaration préalable, etc.). La
   liaison de sélection des `.pcard` est donc gardée derrière la présence du bouton de
   continuation (`#js-continue`) : ailleurs, ces vignettes restent purement informatives —
   ni écouteur, ni `aria-pressed`, ni état sélectionné qui promettrait une suite.

**Décision.** La copie manuelle est **assumée**, et ses écarts sont **énumérés dans l'en-tête
du fichier**. `frontend/homepage/` n'est **pas** modifié pour un besoin propre au thème :
c'est la source d'un autre contexte, protégée par les bancs de fidélité de l'accueil.

**Conséquences — dette consignée.**

- **Risque de dérive.** Les deux fichiers peuvent diverger, et une recopie naïve de la
  source **effacerait silencieusement** les deux écarts voulus.
- **Garde-fous actuels.** L'en-tête du script documente les écarts ; `tests/homepage/test-fidelite.php`
  vérifie des invariants du JS (`js-start`, `burger`, `urbizen:parcel-confirmed`, absence de
  montage manuel du cadastre) — mais **pas** encore la garde de sélection des cartes.
- **Options non tranchées ici.** Un jour, au choix : une étape de génération/synchronisation
  du JS (comme `scope-css.py` pour le CSS) ; un banc qui affirme l'ensemble exact des écarts
  autorisés ; ou l'extraction d'un JS partagé. À décider quand une deuxième page interne ou
  une évolution du script rendra la dérive probable — pas avant.

Ceci est une **dette consignée**, pas un travail planifié.

---

## D-049 — Une feuille dédiée aux pages internes, scopée `.urbizen-page`

**Contexte.** La première itération de l'Étape 6 (page Déclaration préalable) a suivi une
doctrine **« aucune CSS nouvelle »** : n'employer que les classes de l'accueil, servies par
`urbizen-homepage.css` (elle-même générée depuis `frontend/homepage/homepage.css` par
`scripts/scope-css.py`). Cette règle protégeait l'accueil de toute régression, mais elle a
atteint sa limite dès qu'une page a porté un **contenu riche** : des seuils réglementaires,
une procédure en étapes, coulés dans des `pcard` prévues pour trois mots — sections
brouillonnes, illisibles, et un hero identique à l'accueil.

**Décision.** Les **pages internes** reçoivent une feuille dédiée,
`wordpress/urbizen-child/assets/css/urbizen-pages.css`, **intégralement scopée
`.urbizen-page`**. Cette classe est **absente de l'accueil** : aucune règle de cette feuille
ne peut donc l'atteindre, et le risque de régression y est **nul par construction**. La
feuille n'emploie que les tokens `--u-*`, respecte `prefers-reduced-motion`, et n'est chargée
— par `functions.php` — que **sur les pages internes, après `urbizen-homepage`**, jamais sur
l'accueil.

**Conséquences.**

- **La doctrine « zéro CSS nouveau » est remplacée pour les pages internes.** Elle était juste
  pour le premier cadrage — ne pas toucher à la feuille de l'accueil — mais trop stricte pour
  un vrai contenu de page. La contrainte réelle n'était pas « aucune CSS » : c'était « ne pas
  modifier l'accueil ». Le scope `.urbizen-page` tient cette contrainte-là.
- **L'accueil reste protégé.** Sa feuille `urbizen-homepage.css` est générée et **inchangée** ;
  ses bancs de fidélité (`tests/homepage/test-fidelite.php`) restent verts.
- **Règle opposable.** Toute règle de `urbizen-pages.css` **commence par `.urbizen-page`** ;
  un simple `grep` (ou un banc) peut le vérifier. Une règle non scopée est un défaut.
- **Réemploi.** Les pages internes suivantes (permis de construire, etc.) reprennent les
  mêmes composants de `urbizen-pages.css`.
- **Dette à surveiller.** Contrairement au CSS de l'accueil, `urbizen-pages.css` est écrit à
  la main, sans source dans `frontend/` ni génération. Sa discipline tient à deux règles :
  rester scopé `.urbizen-page` et n'utiliser que des tokens `--u-*`. Se rapproche de la dette
  du script d'accueil (D-048).

## D-050 — Socle multi-formulaire : liste blanche serveur, routage explicite, renderer générique

**Statut.** Adoptée — **implémentation progressive dans le Lot 1**.
**Incrément 1 réalisé** : généralisation sécurisée de `FormRegistry` (liste blanche +
`register()`/`has()`/`all()`, `reset_for_tests()` réservé aux bancs, sinon `LogicException`) +
caractérisation.
**Incrément 2 réalisé** : routage serveur explicite des soumissions — la route Conception porte
désormais **action + type + nonce** (`urbizen_conception → conception, urbizen_conception_submit`),
**choisie par le hook** via une valeur littérale (jamais `$_POST`) ; le **nonce** et le **type**
opérationnels proviennent de la route ; un `action`/`form_type` reçu dans le POST ne sert qu'à un
**contrôle de cohérence**.
**Incrément 3 réalisé** : extraction du renderer multi-étapes **générique** `StepFormRenderer`
(sans dépendance ni chaîne métier), piloté par la `FormDefinition` et un objet-valeur immuable
`StepFormRenderConfig` (valeurs techniques serveur : action, nonce, jeton déjà généré, pot de
miel, retour, cible, `accept`). `ConceptionRenderer` est conservé comme **façade de
compatibilité** : même API, même sortie **octet pour octet** (banc de parité contre une référence
figée), il ne fait plus que bâtir la configuration Conception et injecter ses fragments propres
(cartouche, consignes, brouillon).
**Incrément 4 réalisé** : `FormBlock` (bloc + shortcode) est raccordé aux renderers **côté
serveur**. Le type demandé par le bloc est validé par la liste blanche `FormRegistry`, puis un
**résolveur serveur** `Blocks\FormRendererResolver` — table privée immuable de fabriques de
confiance — associe le type à son renderer autorisé : **Localisation → `Forms\Renderer` (plat)**,
**Conception → `Conception\ConceptionRenderer` (façade → `StepFormRenderer`)**. Le navigateur ne
choisit jamais une classe, un chemin, un callable ou un namespace ; aucun `new $client`, aucun
`class_exists($client)`, aucun `require` calculé. Un type absent ou hors liste blanche retombe sur
le formulaire par défaut (repli historique contractualisé) ; un type en liste blanche mais sans
renderer autorisé **échoue proprement** (aucun rendu public). Les ressources de bloc `urbizen-form`
ne sont enfilées que pour le renderer plat ; Conception enfile les siennes via sa façade. Les
quatre fragments de confiance de `StepFormRenderConfig` sont renommés `trusted_*_html` et leur
contrat (HTML serveur de confiance, jamais issu d'une superglobale/attribut/URL, échappement à la
charge de l'appelant, pas de moteur de template) est explicité.
**Incrément 5 réalisé** : les politiques d'upload sont **par profil serveur**. `UploadPolicy`
reste le **moteur de validation générique** (MIME réel `finfo`, concordance extension/contenu,
neutralisation du nom, dernière extension, contrôle croisé WordPress, quantités, tailles) ; un
`Files\UploadProfile` **immuable** porte les bornes métier (blocs, formats, quantités, tailles,
dépôts ouverts ou non). `Files\UploadProfileRegistry` associe le **type serveur** (issu de la
route) à son profil : **conception → profil ouvert** (identique à l'existant, à l'octet près),
tout autre type — dont **localisation** — → **aucun profil** (`null`). Un seul profil commercial
ouvre les dépôts : **conception**. Aucun profil DP, PC, PCMI, permis ou CERFA.
**Incrément 5 bis réalisé — pipeline entièrement piloté par le profil, sans repli implicite.**
`UploadPolicy::validate()` et `validate_one()` **exigent** un profil explicite (plus de paramètre
nullable : le moteur ne suppose jamais Conception). `UploadNormalizer::normalize($files, $profile)`
filtre les blocs **selon le profil**, jamais une liste Conception globale : un profil fictif dont
les blocs diffèrent traverse la chaîne sans une ligne de code métier. `Storage` et `UploadManifest`
n'appliquent plus la liste Conception : ils gardent un contrôle **générique** de format d'identifiant
de bloc (`UploadPolicy::is_valid_block_id()`), sur des documents déjà validés par le profil ;
structure du manifeste et du stockage **intactes**, aucune migration. Le `SubmissionController`
résout le profil depuis `$type` **avant** la normalisation (jamais depuis `$_POST`/`$_FILES`) et le
transmet à chaque étape ; un type sans profil refuse tout document (`upload_not_allowed`), jamais un
repli « tout autorisé » ni sur Conception. `default_profile()` est renommé `conception_profile()`
(source serveur clairement identifiable) et l'ancien nom, sans aucun appelant, est **supprimé** —
plus aucun concept de profil « par défaut ». Un banc de bout en bout prouve qu'un profil fictif
traverse normalisation, validation, manifeste et staging/finalisation.
**Incrément 5 ter réalisé — rejet explicite des fichiers hors profil.** La normalisation
distingue désormais un **emplacement réellement vide** (`UPLOAD_ERR_NO_FILE`, généré par le
navigateur pour un champ non rempli — ignoré sans faux rejet) d'une **tentative réelle** de dépôt
dans un bloc ou un champ hors profil : cette dernière **échoue explicitement**
(`upload_invalid_structure`) pour tout le lot, **avant** manifeste, staging, référence, demande,
persistance, finalisation ou courriel. Un lot mixte (un fichier autorisé + un fichier interdit) est
rejeté **en entier**, sans persistance partielle. `ignored` ne contient plus que des emplacements
vides inoffensifs ; aucun fichier hostile n'y est masqué en silence. Un mutant forçant
l'écartement silencieux est mordu.
**Incrément 6 réalisé — stratégie tarifaire par type serveur.** Le prix est **toujours recalculé
côté serveur** ; la stratégie est résolue depuis le **type serveur** (`Forms\PricingStrategyRegistry`
: `conception → ConceptionPricingStrategy`, tout autre type — dont `localisation` — → `null`),
jamais depuis `$_POST`/`pricing_strategy`/`price`/`amount`, un attribut de bloc ou un nom de classe.
`Forms\PricingStrategy` est le contrat générique ; `ConceptionPricingStrategy` n'est qu'un
adaptateur mince sur le catalogue historique `Forms\Pricing` (constantes et calcul **inchangés**, à
l'euro près). Le `Validator` délègue à la stratégie résolue depuis `$def->type()` au lieu d'appeler
`Pricing` en dur ; le `SubmissionController` contrôle le socle du montant contre `strategie->base()`
(plus de `Pricing::BASE` codé en dur). Un type sans stratégie n'obtient **aucun** prix et n'entraîne
aucune demande (rejet avant persistance) — jamais de repli sur Conception. **Unité inchangée :
euros entiers** (aucun flottant, aucune migration). Le montant persisté (`_urbizen_pricing`) et lu
par `MailRenderer` reste identique. Un mutant détournant le type vers `localisation` fait disparaître
le prix Conception ; le mutant historique du prix client POST reste mordu. **Mails non encore
généralisés.**
**Incrément 7 réalisé — notification interne par type serveur.** La stratégie de notification est
résolue depuis le **type serveur** de la demande persistée (`_urbizen_form_type`, écrit par la
route), jamais depuis `$_POST`/`mail_strategy`/`recipient`/`subject`/un nom de template.
`Mail\NotificationStrategy` est le contrat générique ; `Mail\ConceptionNotificationStrategy` n'est
qu'un adaptateur mince sur `MailRenderer` (destinataire serveur via `MailPolicy`, sujet à référence
seule, corps échappé, en-têtes sûrs — **inchangés**). `MailScheduler` résout la stratégie depuis le
type persisté et lui délègue la construction du message ; **queue, transport, verrou, backoff et
retries restent inchangés** (la stratégie ne touche qu'au message, jamais à l'envoi). Un type sans
stratégie n'envoie **rien** et ne retombe jamais sur Conception (`no_strategy`, la demande n'est pas
supprimée). **Seule la notification interne `conception` existe** ; **aucun accusé de réception au
demandeur**, aucun nouveau destinataire ; `localisation` n'a aucune notification. La notification ne
met **pas** l'adresse du demandeur en `Reply-To` (courriel interne) : aucune surface d'injection
d'en-tête de ce côté. **Invariant des types renforcé** : `FormRegistry::get()` **rejette** une
définition dont le type déclaré diffère de sa clé de résolution — le type de la route reste la
source canonique (prix, upload, notification). Deux mutants mordent : un repli Conception injecté,
une garde d'invariant retirée. Le courriel Conception reste **identique** (`submissions 20/20`).
Les valeurs saisies et les erreurs de validation **ne sont toujours pas** reprises au rendu (API
ouverte, non réalisée).
Aucun formulaire DP/PC/CERFA, aucun tunnel n'est livré par cette décision.

**Contexte.** Le socle de soumission (validation serveur, nonce, anti-spam, rate limiting,
uploads hors racine web, CPT privé `urbizen_demande`, file de courriel, rétention) est mûr
mais **centré sur `conception`** : `SubmissionController::FORM_TYPE = 'conception'` est codé en
dur, et `FormRegistry` connaît les deux formulaires livrés (`localisation`, `conception`) via
une liste blanche en dur. Nous voulons accueillir plus tard plusieurs formulaires **commerciaux**
(conception, déclaration préalable, permis de construire) **sans** régression et **sans** ouvrir
de faille : une valeur du navigateur ne doit jamais pouvoir choisir un pipeline, charger un
fichier, une classe ou une définition.

**Décision.**

- **A — Registre en liste blanche.** Seuls des types **explicitement enregistrés côté serveur**
  sont résolubles. `FormRegistry` valide chaque identifiant (`^[a-z][a-z0-9_-]{0,63}$` : ni
  chemin, ni classe, ni Unicode, ni majuscule, ni octet nul), refuse l'inconnu, refuse le
  doublon **sans jamais écraser** une déclaration existante, et n'appelle `require` que pour un
  identifiant déjà en liste blanche → jamais depuis une chaîne libre. `KNOWN` reste l'inventaire
  minimal (`localisation`, `conception`). API additive : `register()`, `has()`, `all()` ; `get()`
  et `default_type()` inchangés pour les appelants. **Seuls types actifs à la fin de l'incrément :
  `localisation`, `conception`.**
- **B — Routage — RÉALISÉ (incrément 2).** Une table serveur `SubmissionController::ROUTES`
  associe chaque **action** à `{ type, nonce_action }`. La route est **choisie par le hook**
  (valeur littérale de l'action enregistrée), jamais par `$_POST` ; le nonce et le type
  opérationnels en découlent. Un `action`/`form_type` du POST ne sert qu'à un **contrôle de
  cohérence** (rejet `invalid_form` s'il contredit la route, avant tout effet de bord). Les
  constantes historiques `ACTION`/`NONCE_ACTION`/`FORM_TYPE` restent la **valeur canonique**
  référencée par la route (une seule source, pas de divergence). Une seule route réelle
  aujourd'hui ; DP/PC ajouteront leur entrée, jamais via le navigateur.
- **C — Renderer générique — RÉALISÉ (incrément 3).** `StepFormRenderer` (namespace `Forms`, PSR-4)
  est piloté par la `FormDefinition` et un `StepFormRenderConfig` **immuable** ; il ignore
  Conception/DP/PC/CERFA, ne lit aucune superglobale, ne choisit aucune route et ne porte aucune
  chaîne métier (scan statique en banc). Il conserve les neuf types de champs, les conditions
  `visible_if`, l'accessibilité (fieldset/legend, ARIA, repli no-JS) et la navigation. Les
  fragments propres à un formulaire (cartouche, consignes, brouillon) sont injectés par la
  configuration (`prelude`/`header`/`footer`/`step_extras`), rendus par l'appelant. `ConceptionRenderer`
  est désormais cette **façade** : une seule implémentation du rendu, sortie inchangée octet pour
  octet. `FormBlock` **reste inchangé** (raccordement générique reporté à l'incrément suivant) ;
  les formulaires plats gardent le renderer adapté ; aucune logique DP/PC/CERFA n'a rejoint le
  renderer.
- **D — CERFA.** Le futur outil CERFA conservera un **pipeline métier séparé** : pas de CPT
  `urbizen_demande`, pas de rétention commerciale partagée, contrôleur dédié.
- **E — Persistance.** **Aucune table** dans le Lot 1 ; CPT privé + post meta conservés pour les
  parcours commerciaux ; **aucune migration**. Le type de formulaire persisté proviendra de la
  résolution serveur, jamais d'une valeur client non vérifiée.

**Alternatives rejetées.** Découverte dynamique des définitions par balayage de répertoire (ouvre
un chemin non maîtrisé) ; sélection du type depuis un champ caché (le navigateur choisirait le
pipeline) ; conteneur de dépendances générique (surdimensionné, sans usage réel) ; réécriture du
renderer à neuf (risque de régression face à un `ConceptionRenderer` déjà éprouvé et testé).

**Conséquences.**
- `FormRegistry` expose une API d'enregistrement explicite, testée (`tests/forms/test-registry.php`).
- Le comportement de `localisation` et `conception` est **inchangé** (bancs `submissions` 18/18 et
  `conception` 4/4 verts ; rendu, prix, uploads, persistance, notification figés par la
  caractérisation existante).
- La suite du Lot 1 (routage, renderer, bloc, uploads) s'appuiera sur cette liste blanche.

## D-051 — Confirmation post-soumission émise et vérifiée côté serveur (Lot 2, C1)

**Statut.** Adoptée — **incrément C1 réalisé**. Aucune publication publique de Conception,
aucun accusé de réception par courriel : cet incrément ne fait qu'afficher, de façon **fiable**,
le résultat d'une soumission après la redirection.

**Contexte.** Après une soumission, `SubmissionController` redirige (303) vers la page portant le
formulaire. Jusqu'ici l'issue voyageait en clair (`?urbizen_submission=success&reference=URB-…`) et
**rien ne l'affichait côté serveur** : une personne sans JavaScript ne voyait aucune confirmation, et
une URL forgée à la main aurait pu faire croire à un succès ou exposer une référence arbitraire.

**Décision.**

- **Aucune confiance dans le GET brut.** Le résultat public n'est jamais déterminé par
  `urbizen_submission`, `reference` ou un `error` libres de l'URL. Une URL forgée n'affiche **rien**.
- **Jeton signé, émis par le serveur.** `Http\SubmissionFeedbackToken` émet un jeton
  `base64url(json).base64url(hmac_sha256)` sur une charge **minimale** — `version`, `type serveur`,
  `statut` (`success`/`error`), `catégorie publique d'erreur` **ou** `référence` (jamais les deux),
  `expiration`. Signé par HMAC-SHA256 avec un secret adossé au sel WordPress (hors dépôt) et un
  **contexte propre** (`|urbizen-feedback`), distinct du jeton anti-robot. Vérification à temps
  constant (`hash_equals`), format et longueur strictement bornés, **validité courte** (600 s).
- **Charge sans donnée personnelle.** Ni nom, ni adresse, ni téléphone, ni réponse, ni erreur par
  champ, ni prix. La **référence** est une information technique : elle ne voyage **que** dans le
  jeton signé (jamais comme paramètre falsifiable) et ne donne accès à **aucune** donnée persistée.
- **Catégories d'erreur publiques en liste blanche** (`validation`, `rate_limited`, `unavailable`,
  `technical`). Le contrôleur mappe chaque code interne vers l'une d'elles ; les défenses (nonce, pot
  de miel, jeton, doublon), le stockage, la persistance et l'interne restent **opaques** (`technical`).
- **Lecture confinée, rendu accessible.** `Http\SubmissionResultNotice` est le seul point qui lit
  l'URL ; il vérifie le jeton, traduit en message public et rend un HTML **accessible**
  (`role="status"` au succès, `role="alert"` à l'erreur, titre explicite, référence échappée, sans
  dépendance à JavaScript). Le `StepFormRenderer` **générique** reste inchangé : il ne lit aucune
  superglobale, ne vérifie aucun jeton. La façade Conception injecte ce HTML **déjà échappé** dans le
  fragment de tête, **avant** le formulaire. En l'absence de jeton valide, le fragment vaut la chaîne
  vide et le rendu du formulaire est **identique au caractère près** (parité préservée).
- **Compatibilité de l'interface progressive.** `urbizen_submission` (`success`/`error`) reste émis
  car le script client lit l'issue de sa propre requête ; il est **cosmétique** et ne fonde jamais, à
  lui seul, une confirmation fiable. Le paramètre `reference` en clair est **supprimé**.
- **Émission au mieux.** Si l'encodage du jeton échoue, la redirection se fait **sans** jeton (aucune
  confirmation), jamais avec un jeton malformé : une demande réellement enregistrée n'est jamais
  transformée en faux échec.

**Mise à jour — C1 bis (suppression de la confiance JavaScript dans l'URL).** Le paramètre
cosmétique `urbizen_submission` est **supprimé** : l'adresse de redirection ne transporte plus que
`urbizen_feedback` (le jeton signé). Les paramètres historiques falsifiables — `urbizen_submission`,
`reference`, `error` — sont **toujours purgés** de l'adresse cible, même s'ils y traînaient. La
notice vérifiée porte désormais un **marqueur serveur** `data-urbizen-feedback-status` (`success`/
`error`, en liste blanche, échappé, sans référence ni PII), posé **uniquement** après vérification
du jeton. `urbizen-conception.js` ne lit **plus aucune valeur d'URL** : son verdict se fonde
exclusivement sur ce marqueur, lu dans la **page réellement servie**. Conséquence : une URL forgée
(`?urbizen_submission=success`) ne produit ni notice, ni marqueur, ni effacement de brouillon, ni
apparence de succès — le **brouillon n'est jamais effacé depuis une affirmation libre de l'URL**. Le
navigateur n'a plus de seconde source de vérité. Le jeton reste réutilisable pendant sa courte durée
(pas de mécanisme à usage unique en C1 bis) : il ne donne accès à aucune donnée supplémentaire.

**Mise à jour — C3 (aperçu administrateur inerte).** Le rendu Conception distingue désormais deux
modes, **choisis exclusivement côté serveur** (jamais depuis GET/POST, un attribut de bloc, un
cookie ou une valeur JavaScript) : **opérationnel** quand le formulaire est public, **aperçu** sinon
(administration, avant publication). Le rendu **opérationnel reste strictement inchangé** — action,
nonce, jeton anti-robot, bouton actif, feedback C1 — et la fixture de parité l'ancre désormais
octet pour octet (elle ne diffère de l'ancienne que par le retrait du bandeau d'aperçu, propre au
mode preview). Le rendu d'**aperçu** est visuellement représentatif (six étapes, 45 champs,
navigation, tarification, styles, accessibilité) mais **techniquement inerte** : **aucun appel à
`AntiSpam::issue_token()`**, aucun nonce généré (prouvé par comptage direct), aucune action
opérationnelle, aucun champ technique exploitable, bouton d'envoi **désactivé**
(`disabled aria-disabled="true"`), et une **notice d'aperçu** explicite (« ne peut pas être
envoyé »). Le conteneur porte un **marqueur serveur** `data-urbizen-render-mode="preview"`
(liste blanche, échappé, jamais issu de l'URL) ; `urbizen-conception.js` s'y fie pour **neutraliser**
la soumission (aucun `fetch`, aucun comportement post-succès) et n'écrire **aucun brouillon réel**.
Un marqueur absent ou inconnu vaut **opérationnel** : les défenses ne sont jamais relâchées sur un
marqueur douteux. L'aperçu **n'affiche aucun feedback C1** (aucune lecture d'URL en aperçu). Le simple
rendu d'aperçu **ne crée ni demande, ni référence, ni staging, ni courriel, ni réservation**. La
**route réelle conserve toutes ses défenses** serveur (nonce, anti-robot, validation) : l'inertie de
l'aperçu empêche une soumission *accidentelle*, elle ne prétend pas remplacer ces contrôles.
**Conception reste désactivé publiquement** ; **C2 n'est pas commencé.**

**Mise à jour — C2A (canal serveur de reprise des valeurs et erreurs).** Après un rejet
**corrigeable** (validation métier : route, nonce, anti-robot, débit et définition tous passés,
**aucune** demande persistée), le serveur conserve brièvement une **reprise** — les **valeurs
nettoyées** (issues du `Validator`) et les **erreurs publiques par champ** — pour que la personne
retrouve sa saisie. **Seul ce cas** ouvre une reprise ; nonce/formulaire invalides, pot de miel,
anti-robot, limitation de débit, profil/stratégie manquants, structure d'upload hostile, erreur
interne, ou demande déjà persistée n'en ouvrent **jamais**. La reprise ne contient **que** des
champs déclarés dans la définition — **jamais** de POST brut, de nonce, de jeton, de pot de miel,
d'URL de retour, de prix, de profil, de **donnée de fichier** (ni nom, ni chemin, ni contenu, ni
manifeste), de référence, de trace ni de code interne ; le **consentement n'est pas conservé** (il
est re-confirmé à chaque soumission) ; une clé d'erreur inconnue bascule en erreur globale générique.
Le stockage (`SubmissionRecoveryStore`) est un **transient court** (600 s) derrière un **identifiant
opaque** aléatoire fort (`random_bytes`), à **clé dérivée par HMAC** (aucune donnée personnelle dans
la clé), **à usage unique** (lecture + suppression). L'identifiant voyage **uniquement à l'intérieur
du jeton C1 signé** (champ `k`, couvert par la signature HMAC, présent seulement pour une erreur
`validation`, jamais pour un succès ni une erreur de sécurité) : l'**URL ne contient toujours que**
`urbizen_feedback=<jeton signé>`, aucune valeur ni erreur en clair. Si l'émission du jeton échoue
après le dépôt, la reprise est **supprimée** (pas d'orphelin) ; si le dépôt échoue, le rejet reste un
rejet générique. La couche Conception expose une API de consommation
(`SubmissionResultNotice::consume_recovery()`), qui vérifie le feedback signé et consomme la reprise
— **mais C2A ne branche rien au rendu** : les valeurs et erreurs **ne sont pas encore réaffichées**
(ce sera **C2B**), aucun HTML de champ n'est modifié, la fixture opérationnelle est inchangée, et
**l'aperçu ne lit ni ne consomme aucune reprise**. **Conception reste désactivé publiquement.**

**Mise à jour — C2B (réaffichage sécurisé des valeurs et erreurs).** Le rendu opérationnel consomme
la reprise **une seule fois** par requête (seule la première instance opérationnelle la reçoit ;
l'aperçu jamais) et la transforme en un **état de rendu générique et immuable**
(`Forms\StepFormRenderState` : valeurs nettoyées, messages publics par champ, message global). Le
`StepFormRenderer` — toujours **sans connaissance métier, sans lecture de GET, de HMAC ni de
transient** — réaffiche : la **valeur** échappée selon le type (`value` pour texte/nombre, contenu de
`textarea`, `option selected`, `radio/checkbox checked`) ; **jamais** le consentement (re-confirmé) ;
**jamais** de fichier (un fichier ne se restaure pas). Chaque erreur pose `aria-invalid="true"`, un
message visible (via `Forms\ValidationMessages`, présentateur des **codes** en phrases publiques — un
code inconnu reçoit un message générique, jamais le code brut) et un marqueur serveur strict
(`data-urbizen-field-error`). Un **résumé accessible** (`role="alert"`, `data-urbizen-error-summary`)
liste les erreurs **dans l'ordre de la définition** (jamais celui du POST), avec liens vers les
champs ; l'erreur globale y figure sans lien ; **aucune référence de demande** (il n'y en a aucune
après `VALIDATION_FAILED`). **Sans reprise, la sortie est strictement inchangée** (parité octet pour
octet préservée). La réponse portant une reprise est marquée **non stockable** (`DONOTCACHEPAGE`,
`nocache_headers()`) : un cache public ne restitue jamais à un autre visiteur l'HTML des valeurs
saisies. Côté **JavaScript** (flux `fetch`), la réponse serveur est lue **une seule fois** ; ses
erreurs par champ et son résumé sont appliqués au **formulaire courant sans le remplacer** — les
`FileList` et les valeurs saisies **restent intactes**, aucune deuxième navigation, aucune deuxième
consommation, aucun message construit à partir de l'URL, jamais d'`innerHTML` depuis la réponse. En
**l'absence de JavaScript**, la navigation native suit la redirection, la page consomme la reprise et
réaffiche valeurs, erreurs et résumé, avec un **nouveau nonce et un nouveau jeton anti-robot** ; les
fichiers restent à re-sélectionner. **Les fichiers ne sont ni conservés ni restaurés** ; la reprise
après **erreur d'upload** n'est pas gérée. **Conception reste désactivé publiquement.**

**Mise à jour — C2B bis (renouvellement des identifiants et reprise des familles).** *Renouvellement.*
Après une validation échouée, le serveur libère le jeton anti-robot (`release_token`, pas
`consume_token`) : le renvoi avec l'ancien jeton **réussit déjà** (reproduit par exécution). Par
robustesse, le JavaScript **renouvelle** malgré tout, depuis le formulaire serveur de la réponse
**déjà téléchargée**, les identifiants techniques à usage unique — **nonce** et **jeton anti-robot** —
selon une **liste blanche fixe de noms définie côté code** (jamais un nom reçu de la réponse) et
après **validation de format**. Il met à jour les seuls champs cachés existants, **vide le pot de
miel**, ne touche ni aux champs métier ni aux `file`, ne remplace pas le formulaire et ne reconstruit
aucune `FileList`. Si la réponse ne fournit pas un nonce **et** un jeton **valides**, le renvoi est
**bloqué** (bouton désactivé, message générique, aucune boucle) plutôt que de renvoyer avec des
identifiants potentiellement périmés. Le parcours **sans JavaScript** utilise nativement les nouveaux
identifiants (la page rendue les porte). *Familles dynamiques.* La famille `surfaces` était rendue
**comme un unique contrôle** générique et n'était **pas collectée par pièce** (ni le serveur ni le
JavaScript ne rendait de contrôle `surfaces[clé]`) : sa saisie par pièce reste dynamique et
**dépendante de JavaScript** (les pièces dépendent des réponses précédentes). Le `Validator`
produit néanmoins une structure `surfaces` nettoyée dès qu'un POST porte des `surfaces[clé]`, et
C2B bis **restaure** cette structure **côté serveur** : `StepFormRenderer` rend, **avec reprise**, un
contrôle **par clé déclarée présente** (jamais une clé reçue du POST, uniquement les `keys` de la
définition, **bornées**, dans l'ordre de la définition, valeurs échappées), chacun avec son erreur
`surfaces[clé]` sur le bon contrôle et un lien de résumé vers `#instance-surfaces-clé`. **Sans
reprise, la famille conserve son contrôle unique** (parité octet pour octet préservée). Le parcours
**sans JavaScript** réaffiche ainsi toutes les valeurs nettoyées présentes, avec nouveau nonce et
nouveau jeton, et reste **corrigeable**. **Consommation unique, non-cache, aperçu inerte et absence
de PII dans l'URL sont préservés. Conception reste désactivé publiquement.**

**Mise à jour — C2C (descope de la ventilation facultative des surfaces par pièce).** L'audit métier
(C2C) a établi que la « Surface par pièce » (`surfaces`, pluriel) était **facultative**, **non
tarifante** et **jamais collectée** : aucun contrôle `surfaces[clé]` n'était rendu, ni par le serveur
ni par le JavaScript, si bien que le contrat « famille dynamique » restauré en C2B bis n'avait aucune
saisie réelle à reprendre. **Décision produit : Option A — retrait complet du champ `surfaces`.** Sont
**conservés** : la **surface globale** `surface` (champ unique, bornée 10–1 000 m², « Surface de
plancher envisagée »), les **quantités de pièces** (chambres, etc.) et le champ libre `pieces_detail`
(« Précisions sur la distribution ») qui recueille désormais toute ventilation souhaitée en texte.
**Retirés** avec le champ : la clôture `keys`/`total_max`, les attributs de définition `family`/`keys`/
`total_max`, les méthodes `Validator::clean_surfaces()`/`surfaces_attendues()` et la constante
`FAMILLE_SURFACES`, la branche famille de `StepFormRenderer` (`champ_famille()`) et la reprise
`surfaces[clé]` de `SubmissionRecovery`. **Aucun contrat mort ne subsiste** : un POST portant
artificiellement `surfaces[clé]` est traité comme un **champ inconnu** (écarté, nommé dans `ignored`),
sans nettoyage ni erreur `surfaces[…]`, et la reprise n'en conserve rien. Le formulaire passe de
**45 à 44 champs** ; la fixture de parité et la fixture JS sont régénérées **volontairement** (une
seule ligne retirée). **Aucun impact tarifaire** (la note de devis `devis_requis:surface_totale`
disparaît avec le cumul par pièce), **aucun** courriel demandeur, **aucune** modification de version
plugin. **Consommation unique, non-cache, aperçu inerte, absence de PII dans l'URL et désactivation
publique de Conception restent inchangés.**

**Mise à jour — H1 (durcissement avant publication).** Quatre renforcements, **sans** publication ni
changement de version :

- **Usage unique concurrent (réservation exclusive, sans vol).** `SubmissionRecoveryStore::consume()`
  ne fait plus un simple *get-puis-delete* : il acquiert d'abord une **réservation exclusive** via
  `OptionMutex::claim()` — un `INSERT IGNORE` direct (voir la mise à jour H1 ter ci-dessous ; **pas**
  `add_option()`, dont le retour est ambigu). De deux consommateurs concurrents, **un seul** obtient la
  charge ; l'autre reçoit `null` **avant toute lecture**. Le verrou
  est une option dédiée (`urbizen_recl_…`), nommée par **condensat HMAC** (aucune donnée personnelle,
  aucun identifiant lisible). **Il n'est jamais recyclé ni volé pendant qu'il court** : sa durée de vie
  est **alignée sur la durée de vie maximale de la reprise** (`TTL_VERROU` = `TTL` = **600 s**), de sorte
  qu'un ancien propriétaire simplement suspendu ne puisse jamais reprendre pendant qu'un nouveau
  propriétaire tiendrait le même verrou. **Un processus qui meurt rend la reprise définitivement
  indisponible** (fail-closed assumé, préférable à une double restitution). Le verrou n'est retiré que
  par son **propriétaire** (fin de traitement, suppression conditionnée par la confirmation de
  `delete_transient`) ou, **une fois la reprise devenue inconsommable**, par le ménage quotidien
  (`Retention::run_daily`, rubrique `verrous`) — jamais tant qu'elle peut encore être lue, et **aucun
  verrou permanent** ne subsiste dans `wp_options`. **Si la suppression de la charge n'est pas
  confirmée** (`delete_transient` renvoie faux), rien n'est restitué et le verrou est **conservé** :
  aucune seconde restitution n'est possible. La libération inconditionnelle du propriétaire est sûre
  précisément parce qu'aucun vol n'a lieu — il n'existe jamais deux propriétaires du même verrou.
- **Borne temporelle du feedback signé.** `SubmissionFeedbackToken::verify()` refuse désormais une
  expiration **arbitrairement lointaine** : l'expiration entière doit tomber dans `now < x ≤
  now + TTL + tolérance`, avec `TOLERANCE_HORLOGE` = 5 s (borne haute seulement ; la borne basse reste
  stricte — un jeton expiré est mort). Ni chaîne, ni flottant, ni null, ni tableau, ni valeur négative
  ne franchissent `is_int()` et les bornes.
- **Association stricte à l'instance du formulaire.** Chaque instance opérationnelle porte un
  identifiant **produit par le serveur**, déterministe et borné (`data-urbizen-form-instance`, format
  `urbizen-conception-<n>`). Le JavaScript retrouve dans la réponse le formulaire portant **exactement**
  cet identifiant — jamais « le premier formulaire » — et n'y applique erreurs et renouvellement de
  nonce/jeton que **si une seule instance correspond**. Aucune correspondance ou une ambiguïté :
  réponse **refusée** (aucune action, réessai possible). **Aucune confiance de sécurité** n'est accordée
  à cet identifiant (nonce et jeton restent la seule frontière) ; il ne sert qu'à corréler deux rendus
  du même formulaire. Le parcours **sans JavaScript** reste inchangé.
- **Fail-closed sans `DOMParser`.** La détection de statut se fait **uniquement** par analyse structurée
  du document (`DOMParser`), sur l'élément portant `data-urbizen-feedback-status` — **jamais** par
  recherche de sous-chaîne dans le HTML brut (un marqueur dans un commentaire, un `<script>` ou une
  valeur saisie ne peut plus accorder de faux succès). En l'absence de `DOMParser`, on **échoue fermé** :
  aucun succès, aucun effacement de brouillon, aucune copie de nonce/jeton, renvoi **bloqué** (aucune
  réutilisation d'un identifiant à usage unique).

**Mise à jour — H1 ter (primitive de verrouillage non ambiguë).** L'acquisition ne repose plus sur la
valeur de retour d'`add_option()`. **Pourquoi.** Le cœur WordPress implémente `add_option()` par un
`INSERT … ON DUPLICATE KEY UPDATE option_name = VALUES(option_name)`, précédé d'un contrôle d'existence
non atomique (cache `alloptions`/`notoptions`). Sa valeur de retour dépend alors du nombre de lignes
affectées : **sans** `MYSQLI_CLIENT_FOUND_ROWS` (défaut WordPress, y compris la production 7.0.2) un
doublon renvoie 0 ligne → `false`, et le verrou est correct ; **mais** si l'hôte active
`CLIENT_FOUND_ROWS`, un doublon renvoie 1 ligne « trouvée » → `true`, et **deux appelants concurrents
peuvent croire avoir gagné**. La garantie est donc *incidente et fragile*, jamais contractuelle. La
doublure de test le confirme : `add_option()` y est un SETNX propre qui **masque** cette ambiguïté (banc
de régression `test-recovery.php` H5). **Primitive retenue.** `Support\OptionMutex` : un `INSERT IGNORE`
direct via `$wpdb` — exactement **une** insertion réussit (1 ligne), les doublons sont ignorés (0), une
erreur SQL rend `false` (échec fermé) ; contrat **indépendant** de `ON DUPLICATE KEY UPDATE` et de
`CLIENT_FOUND_ROWS`. Le verrou de reprise est **isolé du cache Options** (acquisition, lecture,
libération, purge passent toutes par `$wpdb`, jamais `get_option()` ; le cache du nom est invalidé après
chaque écriture, donc un cache périmé ne peut ni créer un faux gagnant ni masquer un verrou réel). La
**valeur du verrou** porte un **propriétaire aléatoire** (`random_bytes`) et l'expiration ; la
**libération est conditionnée à ce propriétaire exact** (`DELETE … WHERE option_name = … AND
option_value = …`) : un processus ne peut **jamais** supprimer le verrou d'un autre. Le ménage lit en
base **directement**, borné par préfixe et LIMIT ; un verrou dont la valeur est **corrompue** (non
datable) est mis en **quarantaine** (conservé, journalisé) plutôt que traité comme expiré.

**Restent obligatoires avant C5 (non traités ici).** (1) Vérifier et **configurer l'exclusion de cache**
LiteSpeed/CDN de toute réponse portant `urbizen_feedback` (H2). (2) **Exécuter le test d'intégration
réel** `test-multipart-reel.php` contre un vrai WordPress. (3) **Migrer les autres réservations de
sécurité vers `OptionMutex`** — `Security\AntiSpam::reserve_token()` (anti-rejeu / soumission en double)
et `Security\RateLimiter::reserve()` (fréquence) reposent **encore** sur le retour d'`add_option()` :
**sûres en configuration WordPress par défaut** (le perdant reçoit 0 → `false`), mais **fragiles** sous
`CLIENT_FOUND_ROWS`. Leur migration exige d'ajouter un `$wpdb` gérant `INSERT IGNORE` à **plusieurs
doublures de test indépendantes** (submissions, comptes, …), tâche traitée séparément (H1 quater) pour
ne pas déstabiliser des défenses critiques bien couvertes. Les verrous de **fiabilité** (verrou de cron
`Retention`, `MailQueue`, `TrashGuard`, réservation de référence `SubmissionRepository`, `MigrationLock`)
partagent le même motif `add_option` mais protègent des opérations **idempotentes** (double exécution
sans conséquence de sécurité) ; leur migration est recommandée, non bloquante. Ces points **ne sont pas
déployés** et Conception **reste désactivé publiquement**.

**Hors de cet incrément (dettes ouvertes).** Reprise des valeurs saisies et des erreurs par champ
(C2) ; aperçu éditeur sans consommer de jeton (C3) ; centralisation des coordonnées Urbizen (C4) ;
**publication publique de Conception (C5), toujours désactivée par défaut** ; aucun accusé de
réception par courriel au demandeur.

**Conséquences.**
- Nouvelles classes `Http\SubmissionFeedback`, `Http\SubmissionFeedbackToken`,
  `Http\SubmissionResultNotice` ; `SubmissionController::redirect_url()` émet le jeton et mappe les
  catégories ; `ConceptionRenderer` injecte le message avant le formulaire.
- Banc dédié `tests/submissions/test-feedback.php` : succès réel, erreur réelle, absence de feedback,
  URL forgée, jeton altéré/expiré/d'un autre formulaire, échappement, et **aucune PII dans l'URL**.
- Rendu Conception **inchangé** en l'absence de feedback (parité octet pour octet préservée).

## D-053 — Estimation tarifaire affichée dans les formulaires DP et PCMI

**Statut.** Adoptée — prototype d'interface réalisé, non publié. Ne concerne **que** les parcours
DP et PCMI ; le formulaire Conception, déjà en production, garde son estimation propre.

**Contexte.** Les formulaires DP et PCMI affichaient déjà un tarif de base calculé côté navigateur.
Deux besoins s'y ajoutent : un client regroupe souvent plusieurs travaux dans un même dossier, et
il demande parfois à Urbizen de déposer le dossier à sa place sur le guichet numérique de la
commune. Aucun des deux n'était chiffré, alors que l'un et l'autre changent le prix. Par ailleurs
le barème vivait en **quatre exemplaires** inline — deux maquettes, deux copies servies par le
thème — sans source de vérité : la dérive n'était qu'une question de temps.

**Décision.**

- **Le prix reste affiché pendant la saisie et sur l'écran de confirmation.** Le visiteur qui
  remplit un formulaire est en phase de décision : lui cacher l'ordre de grandeur ne le protège de
  rien et le pousse à abandonner. Ce qui est affiché est une **estimation**, jamais un engagement.
- **Formule unique.**
  `total = tarif du projet principal + 100 € × travaux supplémentaires + 80 € si ABF + 30 € si dépôt`.
  Les barèmes existants ne changent pas : DP 189 / 249 / 549 €, PCMI 449 / 649 / 849 €.
- **Le projet principal est un choix unique.** L'étape « Projet » passe des cases à cocher aux
  boutons radio. Une nature multiple rendait le tarif de base dépendant de l'ordre de sélection —
  le même dossier pouvait coûter 300 € de moins selon la case cochée en premier. Le choix unique
  supprime l'ambiguïté à la racine plutôt que de l'arbitrer.
- **Les travaux supplémentaires ont leur propre étape**, placée avant l'envoi. Chacun coûte 100 €.
  Le projet principal n'est **jamais** compté comme travail supplémentaire.
- **Le doublon est rendu impossible, pas signalé.** Une nature déjà retenue — par le projet
  principal ou par une autre ligne — n'est tout simplement pas proposée dans les listes. Si le
  client change de projet principal pour une nature déjà en ligne, cette ligne est **vidée**, non
  supprimée en silence : il doit voir qu'il a un choix à refaire.
- **Le regroupement reste soumis à vérification humaine.** Le formulaire l'énonce : mêmes
  demandeur, adresse et parcelle, et compatibilité dans un dossier administratif unique. Urbizen
  vérifie après réception. Le formulaire ne prétend pas trancher une question juridique.
- **Option de dépôt, décochée par défaut.** « Je souhaite qu'Urbizen dépose mon dossier sur le
  guichet numérique de la commune : +30 € ». Une option payante ne se pré-coche pas.
- **Le récapitulatif détaille chaque poste** — principal, travaux, ABF, dépôt, total. Une ligne à
  zéro est **absente**, jamais affichée à « 0 € » : le client ne lit que ce qu'il a choisi.
- **Mention imposée sous le total**, reproduite au caractère près : « Estimation indicative. Le
  tarif définitif sera confirmé par Urbizen après vérification de votre projet, avant toute
  commande. »
- **« Autre » en PCMI n'est pas chiffré.** Le total affiche « Tarif sur étude ». Les suppléments
  restent détaillés ligne à ligne, mais on n'additionne pas des suppléments à un socle inconnu
  pour en tirer un chiffre qui aurait l'air d'un prix.

**Réserve d'architecture — le calcul est client, il n'engage rien.** `PricingStrategyRegistry`
n'expose **aucune** stratégie pour DP ni PCMI, et n'en invente pas : un type sans stratégie vaut
`null`, sans repli sur Conception (D-050). Les formulaires ne postent nulle part
(`ENDPOINT = ""`). Le montant affiché n'est donc, à ce stade, qu'une commodité d'interface — et il
n'est **volontairement pas sérialisé** dans la charge envoyée. Le jour où DP et PCMI passeront par
`SubmissionController`, le barème devra exister **côté serveur**, comme `Forms\Pricing` pour la
conception, et le montant reçu du navigateur ne devra jamais être cru.

**Hors de cette décision.** Le sort de l'estimation du formulaire Conception, qui suit ses propres
règles et son propre catalogue serveur ; l'enregistrement réel des demandes DP/PCMI ; le
rattachement d'une demande à un compte.

**Conséquences.**
- Source unique : `wordpress/urbizen-child/assets/js/urbizen-form-tarifs.js` porte **tout** le
  calcul, le répéteur, l'anti-doublon et le rendu du récapitulatif ; `assets/css/urbizen-form-tarifs.css`
  porte la présentation. Les quatre documents de formulaire chargent **ces mêmes fichiers** et ne
  déclarent plus qu'un barème d'une dizaine de lignes. Les natures sont lues **dans le formulaire**,
  pas configurées en double.
- Aucune mise en file WordPress n'a été nécessaire : les formulaires sont servis en `iframe` depuis
  un document autonome, les chemins relatifs suffisent.
- Banc `tests/cadastre/test-tarifs.mjs`, exécuté sur le **HTML réel** des quatre documents :
  barèmes, choix unique, suppléments et cumuls, répéteur, doublon impossible, cas « sur étude »,
  masquage des lignes à zéro, mention au caractère près, écran final, sérialisation sans montant,
  et **parité maquette ≡ thème**.
- La duplication `frontend/formulaires/` ↔ `wordpress/urbizen-child/assets/forms/` subsiste pour le
  balisage, mais elle est désormais **testée** : elle rejoint la dette documentée en D-048 au lieu
  de rester tacite.

**Mise à jour — Garage et Carport, natures propres en DP.** « Abri, annexe » servait de fourre-tout
à trois projets que le client distingue parfaitement : l'abri de jardin, le garage et le carport.
Un demandeur qui cherche « garage » et ne trouve qu'« annexe » doute d'être au bon endroit. Deux
natures sont donc ajoutées au formulaire DP — `Garage` et `Carport / abri de voiture`, **249 €**
chacune — et « Abri, annexe » demeure pour les annexes qui ne sont ni l'un ni l'autre. Leur tarif
est déclaré **explicitement** au barème alors que `__defaut` vaut déjà 249 : c'est une décision
produit, pas un repli, et un futur changement de `__defaut` ne doit pas les emporter en silence.
Le tunnel d'accueil route désormais `garage` vers la nature `Garage` (et `carport`, prévu) au lieu
de `Abri / annexe`. Aucun autre tarif n'est modifié.

**Mise à jour — « projets supplémentaires », et des cibles tactiles utilisables.** Le parcours
disait « travaux supplémentaires » ; le client, lui, raisonne en projets — c'est le mot employé
partout ailleurs dans le formulaire (« projet principal », « Votre projet est prêt à être étudié »).
L'interface est donc harmonisée : étape « Projets supplémentaires », bouton « + Ajouter un projet »,
en-tête « Projet supplémentaire 1 », récapitulatif « Projets supplémentaires (n) » avec un détail
« Projet supplémentaire — [nature] : +100 € ». La légende du rail devient « Autres projets » plutôt
que « Projets », qui aurait voisiné avec l'étape « Projet ». Le mot « travaux » reste réservé à
l'objet même de la déclaration — « déclaration préalable de travaux », « nature des travaux » — où
il est juridiquement exact. Les identifiants internes (`dp-travail-*`, `this.travaux`) ne sont pas
renommés : invisibles du client, leur renommage n'apporterait qu'un risque.

Deux cibles tactiles étaient trop petites au doigt. La case « dépôt sur le guichet numérique »
mesurait 13 px : l'`<input>` n'était pas imbriqué dans son `<label>`, seule la case était cliquable.
Le `<label>` enveloppe désormais la case et le texte, et porte une hauteur minimale de 44 px — toute
la ligne, montant compris, est cliquable. Le lien « Supprimer », déjà un `<button type="button">`,
mesurait 19 px de haut : il offre maintenant 44 × 44 px, absorbés par des marges négatives pour que
la rangée ne grandisse pas, le soulignement portant sur le mot et non sur la zone. Son `aria-label`
nomme la ligne visée : « Supprimer le projet supplémentaire 1 ».

**Mise à jour — les surfaces ne conditionnent pas la demande initiale.** Six champs de surface
étaient déclarés, dont un obligatoire. Or cinq des douze natures DP — clôture, panneaux solaires,
modification de façade, ravalement, toiture — ne créent **aucune** surface : exiger des mètres
carrés y rejetait des dossiers parfaitement recevables. Et pour une extension, un garage ou une
piscine, un demandeur qui n'a pas encore mesuré doit pouvoir envoyer sa demande ; Urbizen réclame
les cotes après étude. Aucune surface n'est donc obligatoire au stade de la demande initiale. Une
absence reste une **absence** : elle n'est jamais remplacée par un `0`, qui se lirait comme une
mesure prise et fausserait l'instruction. Une matrice de banc éprouve les douze natures, chacune
sans aucun champ de surface.

**Mise à jour — la cohérence entre champs est une étape, pas un effet de bord.** Une définition
juge chaque champ isolément : type, longueur, appartenance à une liste fermée. Elle ne peut rien
dire de ce qui lie deux champs. Un projet supplémentaire répétant le projet principal, un doublon
ou une liste forgée passaient donc la validation de forme, et le catalogue tarifaire se contentait
de ne pas les facturer — la demande était **acceptée** avec un contenu incohérent. Un calcul
prudent ne vaut pas acceptation.

`Forms\ValidationMetier` et son registre introduisent donc une étape dédiée, résolue depuis le
**type serveur** comme les registres tarifaire et d'upload, et intercalée dans
`SubmissionController` **avant** le calcul du prix et avant toute écriture. Elle refuse la nature
principale inconnue, le projet supplémentaire inconnu, celui qui répète le principal, le doublon,
la liste malformée et la liste trop longue — avec des messages destinés à une personne, jamais un
code interne. Le refus est corrigeable : ni jeton, ni créneau de débit, ni référence ne sont
consommés. Le catalogue tarifaire reste défensif de son côté, mais cette défense n'est plus la
seule ligne.

**La limite de projets supplémentaires n'est pas un chiffre choisi**, elle découle du catalogue :
`count(NATURES) - 1`, soit **11**. Les doublons étant interdits et le projet principal exclu des
suppléments, un dossier ne peut pas réunir plus de natures distinctes qu'il n'en existe. Toute
liste plus longue est nécessairement forgée. La dériver plutôt que l'écrire évite qu'une nature
ajoutée un jour laisse derrière elle un plafond devenu faux — et un banc vérifie cette dérivation.

## D-054 — Une pièce manquante ne bloque pas l'envoi d'une demande

**Statut.** Adoptée — appliquée aux formulaires DP et PCMI, thème et maquettes.

**Contexte.** L'étape « Documents » demande sept familles de pièces : photos du terrain, vues depuis
la rue, façades, croquis, plans possédés, relevés, autres documents. Aucune n'était techniquement
obligatoire, mais rien ne le disait. Un client qui n'a pas encore photographié sa façade lit une
liste de sept demandes et en conclut qu'il doit tout réunir avant d'écrire — donc il repousse, et
souvent il ne revient pas. Le formulaire perdait des demandes sur un malentendu, pas sur un refus.

**Décision.**

- **Aucune pièce de cette étape ne conditionne l'envoi.** Ce sont des aides à l'étude, pas les
  pièces réglementaires du dossier. Le message d'ouverture le dit explicitement, et précise que
  seuls les champs marqués d'un astérisque aux étapes précédentes sont obligatoires.
- **Les informations réellement indispensables restent obligatoires** — identité du demandeur,
  terrain, nature du projet principal. Le report ne s'applique qu'aux documents.
- **Le report est déclaré, pas subi.** Chaque pièce porte une case « Je transmettrai ce document
  ultérieurement ». Cocher cette case vaut engagement explicite et fait apparaître la pièce dans le
  récapitulatif sous « À transmettre ultérieurement » — une information utile à Urbizen, là où un
  champ resté vide n'en est pas une.
- **L'explication vit dans l'encadré d'ouverture, et nulle part ailleurs.** Une première version
  répétait « Vous pourrez nous transmettre cet élément plus tard. » sous chacune des sept pièces :
  à l'écran, ces sept lignes concurrençaient l'encadré au lieu de le renforcer, et alourdissaient
  une étape que l'on veut légère. Chaque rangée se limite donc au **document demandé**, au
  **bouton de dépôt** et à la **case de report**.
- **Aucun vocabulaire d'erreur.** Un report n'est ni un manque, ni un défaut, ni une alerte : ni
  rouge, ni « obligatoire », ni « incomplet ». Un banc le vérifie sur le texte rendu.
- **Aucune promesse de dispense.** Le texte rassure sur le *moment* de la transmission, jamais sur
  la nécessité. Un dossier déposé en mairie exige ses pièces réglementaires, et rien ici ne laisse
  entendre le contraire — un banc contrôle l'absence de formulations de ce type.
- **Un fichier déposé prime sur le report.** Déposer un document décoche automatiquement la case :
  « fourni » et « à transmettre » ne peuvent pas coexister sur la même pièce.

**Mise à jour — les références cadastrales deviennent facultatives.** Section cadastrale, numéro de
parcelle et superficie étaient obligatoires. Or ces trois valeurs ne figurent que sur l'acte de
propriété : le client qui ne l'a pas sous la main se heurtait à un mur au deuxième écran, alors même
que la localisation cartographique sait les renseigner et qu'Urbizen peut les vérifier après
réception. Elles perdent donc leur astérisque — c'est ce marqueur, et lui seul, que `validateStep()`
inspecte — et le groupe reçoit une case « Je ne connais pas ces informations. », décochée par
défaut. Cocher vide et neutralise les trois champs, sans les présenter comme en erreur ; décocher
les rend. Le client peut aussi n'en renseigner qu'un : les trois ne forment pas un tout indivisible.
Une valeur déjà détectée par la localisation cadastrale est **conservée**, et la case n'est jamais
cochée d'office ; à l'inverse, saisir une valeur alors qu'elle est cochée lève la déclaration, car
les deux ne peuvent pas être vraies en même temps. Le récapitulatif porte alors « Informations
cadastrales : à compléter ultérieurement », et la charge envoyée gagne `informations_differees` —
sans quoi Urbizen ne distinguerait pas « le client ne sait pas » de « le client n'a rien saisi »,
les champs désactivés ne voyageant pas dans le `FormData`. Adresse, code postal et commune restent
obligatoires : identifier le terrain demeure indispensable.

**Conséquences.**
- `wordpress/urbizen-child/assets/js/urbizen-form-pieces.js` et
  `assets/css/urbizen-form-pieces.css` : source unique du rendu de l'étape. Les quatre documents
  chargent ces fichiers ; le rendu inline des pièces, jusqu'ici dupliqué quatre fois, est supprimé.
  Le module porte aussi les groupes d'informations déclarables inconnus, afin que le récapitulatif
  « À transmettre ultérieurement » reste tenu par un seul endroit.
- La charge envoyée gagne `pieces_differees` — la liste lisible des codes reportés — en plus des
  cases `piece_later_*`.
- Banc `tests/cadastre/test-pieces.mjs` sur le HTML réel : présence et conformité du message,
  mention contextuelle, récapitulatif, absence de champ obligatoire dans l'étape, atteinte de
  l'écran final sans aucun fichier, sérialisation, priorité du fichier sur le report, parité
  maquette ≡ thème.
