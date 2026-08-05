# Test navigateur intégré — parcours DP et PC

> **Préalable bloquant.** Ce test exige le thème parent `hostinger-ai-theme`, absent du dépôt.
> Sans lui, WordPress ne rend pas les pages de formulaire : le thème enfant n'a pas de parent, et
> les gabarits `page-formulaire-*` ne sont pas résolus. Tout ce qui suit suppose un environnement
> disposant de la configuration réelle du site.
>
> **La PR #53 ne doit pas être fusionnée avant que cette checklist soit passée.**

## Pourquoi ce test ne peut pas être remplacé par les bancs

Les bancs couvrent le pont, la tarification, la validation, les notifications et le contenu des
messages — sur le HTML réel des documents, mais dans un DOM simulé et sans WordPress. Trois choses
leur échappent structurellement :

1. **La coque du site.** Le formulaire est servi en iframe dans une page rendue par le thème
   parent. Le redimensionnement du cadre, l'en-tête collant, les polices et la largeur disponible
   ne se vérifient que là.
2. **Le nonce réel.** Les bancs injectent une configuration ; en conditions réelles elle est émise
   par `wp_create_nonce()`, transmise par `postMessage`, et elle **expire**.
3. **Le transport de courriel.** Aucun message n'a encore été réellement remis : localement, la
   file reste en `retry` faute de transport.

## Périmètre

Ce qui est touché : le thème enfant, le greffon, et les demandes créées pendant l'essai.
Ce qui ne l'est pas : les pages publiées, les réglages, les autres extensions, la base hors les
demandes d'essai.

Toutes les données saisies doivent être **fictives**. Les demandes créées sont à supprimer en fin
d'essai, corbeille vidée.

---

## 1 · Déclaration préalable

| # | Contrôle | Attendu |
|---|---|---|
| 1.1 | Chargement de la page | En-tête et pied du site présents, iframe visible, aucun débordement horizontal |
| 1.2 | Initialisation sécurisée | Le bouton d'envoi part **désactivé**, puis devient actif ; aucun nonce dans l'URL du cadre |
| 1.3 | Navigation complète | Les 8 étapes s'enchaînent, le compteur suit, le retour arrière conserve la saisie |
| 1.4 | Garage et Carport | Deux cartes distinctes, deux libellés distincts, chacune sélectionnable seule |
| 1.5 | Projets supplémentaires | Ajout, suppression, renumérotation ; le projet principal n'est jamais proposé ; aucun doublon possible |
| 1.6 | ABF | La ligne « Secteur Bâtiments de France » apparaît à +80 € et le total suit |
| 1.7 | Dépôt numérique | La ligne n'apparaît **que** si l'option est cochée, à +30 € |
| 1.8 | Informations cadastrales différées | La case vide et neutralise les trois champs sans les présenter en erreur ; le récapitulatif porte « à compléter ultérieurement » |
| 1.9 | Documents différés | Report possible sans fichier ; l'envoi n'est pas bloqué |
| 1.10 | Dépôt réel | Un JPEG et un PDF réels partent ; le manifeste accompagne la requête |
| 1.11 | Soumission | HTTP 201, `success: true` |
| 1.12 | Référence | Une référence réelle s'affiche à l'écran final, au format `URB-AAAA-NNNN` |
| 1.13 | Tarif serveur | Le total affiché à l'écran final **vient de la réponse**, pas de l'estimation de saisie |
| 1.14 | Administration | La demande apparaît, avec sa référence, son tarif et l'état de sa notification |
| 1.15 | Notification Urbizen | Reçue, sujet à la référence seule, corps complet, liens signés valides |
| 1.16 | Accusé client | Reçu à l'adresse saisie, sujet `Votre demande Urbizen a bien été reçue — {RÉFÉRENCE}`, **aucun lien**, mention tarifaire au caractère près |

## 2 · Permis de construire chiffré

| # | Contrôle | Attendu |
|---|---|---|
| 2.1 | Maison neuve | Socle à 849 € |
| 2.2 | Un projet supplémentaire | +100 € |
| 2.3 | ABF | +80 € |
| 2.4 | Dépôt numérique | +30 € |
| 2.5 | Tarif final | **1 059 €**, identique à l'écran de saisie, à l'écran final, dans la réponse JSON et en base |
| 2.6 | Enregistrement | Demande créée, référence attribuée, type `permis_construire` |
| 2.7 | Notifications | Les **deux** créneaux ouverts, identifiants distincts, tous deux remis |

## 3 · Permis de construire sur étude

| # | Contrôle | Attendu |
|---|---|---|
| 3.1 | Projet « Autre » | Aucun socle chiffré à l'écran |
| 3.2 | Un supplément | Listé et chiffré séparément |
| 3.3 | Total | **« Tarif sur étude »**, à l'écran de saisie et à l'écran final |
| 3.4 | Aucun total artificiel | Ni « 0 € », ni la somme des seuls suppléments |
| 3.5 | Enregistrement | `total = null`, `pricing_status = sur_etude` en base |
| 3.6 | Notifications | Les deux messages portent « Tarif sur étude », jamais un montant |

## 4 · Interface et robustesse

| # | Contrôle | Attendu |
|---|---|---|
| 4.1 | Ordinateur | Mise en page correcte, cadre redimensionné à son contenu |
| 4.2 | Mobile 390 px | Aucun débordement horizontal, cibles tactiles ≥ 44 px |
| 4.3 | Accessibilité clavier | Parcours complet à la tabulation, focus visible, ordre logique |
| 4.4 | Erreurs utilisateur | Champ manquant → message clair, focus porté sur le champ nommé par le serveur |
| 4.5 | Expiration du nonce | Refus **propre** : message public, bouton rendu, aucun écran de réussite |
| 4.6 | Double clic | Une seule requête, une seule demande créée |
| 4.7 | Erreur réseau | Message neutre, bouton rendu, rien de technique à l'écran |
| 4.8 | Fichier interdit | Un `.php` renommé en `.jpg` est refusé ; le message ne révèle rien du contrôle |

> **4.5 mérite une attention particulière.** C'est le seul scénario que les bancs ne peuvent pas
> reproduire fidèlement : ils injectent un nonce, ils ne le laissent pas vieillir. Un nonce WordPress
> vit 24 h ; pour l'éprouver sans attendre, filtrer `nonce_life` à quelques secondes sur
> l'environnement d'essai, puis **retirer le filtre**.

---

## Après l'essai

- Supprimer les demandes créées, vider la corbeille, vérifier qu'aucun fichier ne subsiste dans le
  stockage privé.
- Retirer tout filtre posé pour l'essai (`nonce_life` notamment).
- Consigner ici le résultat de chaque ligne, et **toute divergence**, avant d'ouvrir la PR à la
  fusion.


---

## Résultat du premier essai intégré local — 5 août 2026

Réalisé sur MAMP avec le thème parent `hostinger-ai-theme` 2.0.18 récupéré en lecture seule.
**Trois défauts trouvés, tous corrigés**, et deux limites d'environnement consignées.

### Ce que l'essai a trouvé, et que les bancs ne pouvaient pas trouver

1. **L'origine omettait le port.** `urbizen_child_configuration_formulaire()` composait
   `schéma://hôte`, sans le port. Le pont compare cette chaîne à `window.location.origin` au
   caractère près : sur tout serveur écoutant ailleurs que sur 80/443, la configuration était
   rejetée et le bouton d'envoi ne se déverrouillait jamais. Latent en production — `urbizen.fr`
   répond sur 443, que le navigateur n'écrit pas — mais réel. Corrigé par
   `urbizen_child_origine_site()`.

2. **Le jeton anti-robot n'était pas transmis.** Les documents DP et PC sont statiques : ils ne
   peuvent pas porter un jeton signé, et le pont ne l'ajoutait pas. La route refusait donc
   **toute** soumission réelle avec `invalid_antispam_token`. Aucun banc ne le voyait : tous
   fabriquaient le jeton eux-mêmes avant d'appeler le pipeline. C'est le défaut le plus grave de
   la série — il rendait les deux formulaires inutilisables en production. Le jeton est désormais
   émis par la page parente, comme le nonce, et transmis par le pont avec le pot de miel.

3. **Les scripts de l'iframe n'étaient pas versionnés.** Un document statique qui charge
   `urbizen-form-bridge.js` sans version fait servir un pont périmé à tout visiteur revenant
   après un déploiement — ici, des erreurs techniques silencieuses. Les cinq références portent
   désormais `?v=0.2.0`, à faire suivre la version du thème enfant.

### Ce qui a été vérifié dans la coque réelle

Page et iframe chargées, en-tête et pied du site présents, aucun débordement · initialisation du
pont, bouton désactivé puis déverrouillé · navigation sur les 8 étapes · Garage et Carport
distincts · surfaces facultatives franchies sans saisie · report cadastral neutralisant les trois
champs · report d'une pièce · projet supplémentaire, ABF et dépôt cumulés à **459 €** à l'écran ·
soumission acceptée avec le jeu de champs corrigé, référence réelle et **459 € recalculés côté
serveur**.

### Ce qui n'a PAS pu être vérifié localement

- **Le parcours d'envoi de bout en bout dans le navigateur.** Le document de l'iframe était servi
  depuis le cache du navigateur, avec ses anciennes balises de script, et le pont chargé restait
  la version d'avant correctif. Le correctif a donc été vérifié par une soumission portant
  exactement le jeu de champs que le pont compose désormais — acceptée, référence attribuée — mais
  le clic réel reste à rejouer sur un cache vidé.
- **Le permis de construire de bout en bout.** Seule la configuration émise par sa page a été
  vérifiée : action, jeton et origine corrects.
- **Le transport réel des courriels.** Aucun message n'est remis localement ; la file reste en
  `retry`. La configuration SMTP Hostinger n'est pas éprouvée.
- **Les extensions présentes uniquement en production**, et les règles de cache ou de sécurité du
  serveur Hostinger.

### Une course à surveiller

Le pont expire au bout de huit secondes s'il ne reçoit pas sa configuration. Sur un cadre servi
depuis le cache, l'iframe peut poster `urbizen_form_ready` **avant** que la page parente n'ait
attaché son écouteur : le message est perdu et le formulaire s'annonce non initialisé, sans
recours. Observé une fois pendant l'essai. Le parent devrait émettre sa configuration sans attendre
la demande, ou la réémettre périodiquement jusqu'à accusé.

### Échafaudage local, à ne jamais déployer

Le thème parent s'accroche à `admin_init` et redirige **toute** requête d'administration — donc
`admin-post.php` — vers son assistant tant que l'option `hostinger_ai_version` est absente. Sur un
WordPress local neuf, elle l'est. La poser pour de bon fait entrer le thème dans un état à demi
configuré où son résolveur de polices plante sur le rendu public. Un `mu-plugin` local fait donc
croire à l'option **uniquement** pendant une soumission :
`wp-content/mu-plugins/urbizen-essai-local.php`. Il vit hors du dépôt et ne doit jamais partir en
production, où l'assistant a déjà écrit son jeu d'options complet.
