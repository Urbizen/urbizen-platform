# Déploiement — parcours de formulaires et tunnel de l'accueil

Branche `fix/forms-validation-ux`, empilée sur `feat/pages-seo-projets` (`3e9c60c`)
et **non** sur `main`. C'est délibéré : la production porte déjà le cocon SEO,
et déployer depuis `main` réécrirait des fichiers avec leur version antérieure.

**Rien de ce document n'a été appliqué.** Il énumère ce qu'il faudra faire, et
dans quel ordre.

---

## 1 · Ordre de déploiement

Le greffon **avant** le thème.

Le nouveau pont sait retomber sur `fields` seul si le serveur n'est pas encore à
jour — c'est testé. L'inverse ne casse rien non plus, mais n'apporte rien :
l'ancien pont ignorerait simplement `errors`. Greffon d'abord, thème ensuite,
donc, pour que le bénéfice soit acquis dès la première requête.

| Ordre | Cible | Contenu |
|---|---|---|
| 1 | greffon | `src/Http/SubmissionJsonResponse.php`, `src/Forms/ValidationMessages.php` |
| 2 | thème | `assets/js/urbizen-form-bridge.js`, les deux formulaires, `functions.php`, `parts/header.html`, `patterns/header-accueil.php`, les gabarits d'accueil, `assets/js/urbizen-homepage.js` |

## 2 · La version des ressources doit suivre

`URBIZEN_CHILD_FORMS_VERSION` passe de `0.2.8` à **`0.2.9`**.

Ce n'est pas cosmétique : les six `<script src>` des formulaires et l'`<iframe>`
des pages portent leur version en paramètre d'URL. Déployer les fichiers sans
elle laisserait les navigateurs servir l'ancien pont depuis leur cache, et la
correction resterait **invisible** — sans qu'aucune erreur ne le signale.

## 3 · Caches

`wp cache flush` puis `wp litespeed-purge all`.

Le **flush des règles de réécriture n'est pas nécessaire** : aucun contenu n'est
créé, aucun slug n'est nouveau. La redirection de l'ancien parcours passe par
`template_redirect`, pas par les règles de réécriture.

## 4 · Contrôles après déploiement

- `/formulaire-declaration-prealable/` et `/formulaire-permis-de-construire/` :
  provoquer un refus serveur et vérifier que la rubrique fautive s'ouvre, que le
  message apparaît sous le champ et que le focus y atterrit ;
- accueil : un projet menant à `none`, un autre à `confirm`, et vérifier que les
  deux boutons diffèrent ;
- `/commander-un-dossier/` : doit rendre **301** vers `/#localisation` ;
- en-tête : le bouton doit lire « Étudier mon projet » et mener au tunnel.

---

## 5 · Tâche restante — message de confirmation Fluent Forms

**À faire au moment du déploiement. Non appliqué à ce jour.**

Le formulaire **ID 5 « Renseignements »** annonce, après envoi :

> Merci pour votre message. Nous vous contacterons dans les plus brefs délais

À aligner sur la promesse tenue partout ailleurs sur le site — « Réponse sous
24 h ouvrées », que `test-contrat-renseignements.php` vérifie déjà dans les
pages :

> Merci pour votre message. Nous vous répondrons sous 24 h ouvrées.

**Pourquoi ce n'est pas dans ce dépôt.** La configuration de Fluent Forms vit
dans la base WordPress, pas en fichiers. Elle ne peut donc être ni versionnée ni
déployée depuis ici : la modification se fait dans l'administration, sur le
réglage « Confirmation » du formulaire 5. Aucun banc ne peut la garder ; c'est
la raison d'être de cette entrée.

### Ce que l'inspection en lecture seule a établi

| | |
|---|---|
| Champs | 6 — `custom_html`, `names_2`, `address_1`, `email_1`, `input_text_1`, `description` |
| Seul champ obligatoire | `email_1`, message « Ce champ est obligatoire » |
| Logique conditionnelle | **aucune** |
| Champs cachés | **aucun** |
| Champ à la fois caché et requis | **aucun** |
| Confirmation | `samePage`, sans redirection |

Le champ `description` — celui que `contextualiserRenseignements()` préremplit
avec le résumé de qualification — n'est **pas** obligatoire. Le préremplissage
est donc un confort et jamais une dépendance : si le tunnel n'a rien à
transmettre, le formulaire reste utilisable tel quel.

L'ancien formulaire de commande est l'**ID 6**, « Demande de déclaration
préalable de travaux ». Il **reste en base** : la redirection cesse d'y conduire,
elle ne le supprime pas. Retirer le hook `urbizen_child_rediriger_ancien_parcours`
remettrait la page en ligne telle quelle.
