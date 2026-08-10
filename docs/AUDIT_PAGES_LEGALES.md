# Audit des pages légales — état au 10 août 2026

> Audit **en lecture seule**. Aucune écriture en production, aucun contenu
> modifié. Ce document est la base du lot `feat/legal-pages-redesign`.

## 1 · État initial des trois pages

| ID | Titre actuel | Slug | Statut | Gabarit | Contenu |
|---|---|---|---|---|---|
| 14 | Mentions Légales | `mentions-legales` | publish | `no-title` | 4 350 o |
| 26 | Conditions générales de vente | `refund_returns` | publish | `no-title` | 19 890 o |
| 3 | Politiques de confidentialités | `privacy-policy` | publish | `no-title` | 11 388 o |

Les trois pages utilisent `no-title`, un gabarit du **thème parent Hostinger** :
aucune n'est versionnée dans le dépôt. Métadonnées AIOSEO : **toutes vides**
pour les trois pages — titres et descriptions sont générés depuis le contenu.

Liens entrants : `patterns/footer-accueil.php` (pied servi sur les pages
Urbizen) et `parts/footer.html` / `parts/footer-landing.html` (pieds du thème
parent, non servis sur les gabarits Urbizen).

## 2 · Constat le plus grave — il n'y a pas de mentions légales

La page `/mentions-legales/` ne contient **qu'un encart « À propos »** de deux
phrases. Contrôle sur la page réellement servie :

| Mention obligatoire | Présente ? |
|---|---|
| SIRET / SIREN | ❌ |
| Adresse | ❌ |
| Nom de l'entrepreneur | ❌ |
| Forme (entrepreneur individuel) | ❌ |
| Directeur de la publication | ❌ |
| Hébergeur | ❌ |
| TVA | ❌ |

*(Les 60 occurrences de « Hostinger » dans le HTML sont des chemins d'assets du
thème parent, pas du contenu.)*

L'article 6 III-1 de la LCEN impose ces mentions à tout éditeur de site
professionnel. **La page ne remplit aujourd'hui aucune de ses obligations.**

## 3 · Audit juridique des CGV (page 26)

| # | Problème | Gravité |
|---|---|---|
| 1 | Identité réduite au nom commercial « Urbizen » : ni nom de l'entrepreneur, ni mention EI, ni SIREN, ni RCS/RNE, ni statut TVA | élevée |
| 2 | Grille tarifaire recopiée et **obsolète** : 149 €, 249 €, 349 €, 449 € — les 149 € et 349 € n'existent plus | élevée |
| 3 | Médiateur non désigné : « seront communiquées une fois le médiateur désigné » | **bloquante** |
| 4 | Droit de rétractation imprécis : ni délai de 14 jours, ni formulaire type, ni mécanisme de demande expresse | élevée |
| 5 | Aucune distinction consommateurs / professionnels | élevée |
| 6 | Promesse générale de « 48 heures ouvrées » non contractualisée | moyenne |
| 7 | Dépôt : « destiné à être déposé par le client », contredit l'option de dépôt dématérialisé annoncée sur `/tarifs/` | moyenne |
| 8 | Aucune clause TVA | moyenne |
| 9 | Aucune mention des assurances RCP / décennale, alors que `/tarifs/` les met en avant | moyenne |
| 10 | Sections absentes : objet et champ d'application, sous-traitance, évolution des CGV, formulaire type de rétractation | moyenne |

## 4 · Audit de la politique de confidentialité (page 3)

Base saine mais imprécise. Écarts avec le fonctionnement réel :

- **Durée de conservation fausse.** La politique annonce « jusqu'à 3 ans » ;
  le code applique **365 jours après le dernier contact**
  (`Privacy/Retention.php`, `DEFAULT_DAYS = 365`, ajustable par le filtre
  `urbizen_retention_days`).
- **Aucun prestataire nommé.** Formule générique « ses prestataires
  techniques », alors que des tiers identifiables traitent des données.
- **Cookies traités par une formule vague** : « lorsqu'un module de gestion est
  disponible ». Il n'existe aucun module.
- **Aucune mention de transfert hors EEE**, alors que des transferts existent.
- Titre fautif : « Politiques de confidentialités ».

## 5 · Audit RGPD — blocage

### Traceurs chargés sans consentement

Mesuré sur la page d'accueil servie, première visite :

| Domaine tiers | Rôle | Chargement |
|---|---|---|
| `www.googletagmanager.com` | Google Analytics via MonsterInsights (`gtag`) | **inconditionnel** |
| `pagead2.googlesyndication.com` | Google Ads / AdSense | **inconditionnel** |
| `cdn.chatway.app` | Chat en direct Chatway | **inconditionnel** |

### Aucun gestionnaire de consentement

Recherche de bandeau ou CMP dans le HTML servi — `cookie`, `consent`, `cmp`,
Axeptio, Tarteaucitron, Complianz, CookieYes, Didomi, Borlabs : **zéro
occurrence**. Aucun Google Consent Mode non plus.

**Conséquence.** Des traceurs de mesure d'audience et de publicité sont déposés
avant tout consentement, sans possibilité de refus. C'est contraire à
l'article 82 de la loi Informatique et Libertés et à la doctrine de la CNIL.
**Aucune rédaction ne corrige cela** : c'est une correction technique, pas
rédactionnelle.

### Greffons actifs susceptibles de traiter des données

`all-in-one-seo-pack`, `broken-link-checker-seo`, `chatway-live-chat`, `chaty`,
`fluentform`, `fluent-smtp`, `google-analytics-for-wordpress`,
`hostinger-easy-onboarding`, `hostinger-reach`, `hostinger`, `kadence-blocks`,
`litespeed-cache`, `optinmonster`, `google-site-kit`,
`universally-language-translation-multilingual-tool`, `urbizen-platform`.

## 6 · Audit des formulaires

- **Aucune information RGPD au point de collecte** : ni finalité, ni lien vers
  la politique de confidentialité dans les formulaires DP, PC et Conception.
- Les champs `type => consent` existants ne sont **pas** des consentements
  RGPD : ce sont des attestations d'exactitude (« Je certifie exactes les
  informations fournies ») et une acceptation d'usage pour constituer le
  dossier. La base légale réelle est l'exécution précontractuelle et
  contractuelle — c'est cohérent, et il ne faut pas y ajouter de case de
  consentement.
- **Aucun mécanisme de demande expresse de démarrage anticipé** avant la fin du
  délai de rétractation. Recherche de « rétractation », « 14 jours », « demande
  expresse » dans les parcours : aucune occurrence pertinente.

## 7 · Données légales — ce que le dépôt permet de vérifier

| Donnée | Dans le dépôt | Statut |
|---|---|---|
| Nom commercial Urbizen | oui | vérifié |
| Adresse 59 rue de Ponthieu, Bureau 326, 75008 Paris | oui (CGV, politique) | vérifié côté site |
| SIRET 105 253 132 00010 | oui (CGV, politique) | vérifié côté site |
| `contact@urbizen.fr` · 06 64 89 58 15 | oui | vérifié |
| Nom et prénom de l'entrepreneur | **non** | à confirmer |
| Mention « entrepreneur individuel » / EI | **non** | à confirmer |
| SIREN | **non** | à confirmer |
| RCS / RNE et ville | **non** | à confirmer |
| Numéro de TVA / régime fiscal | **non** — aucune occurrence | à confirmer |
| Assureur RCP et décennale | **non** | à confirmer |
| Médiateur de la consommation | **non** — aucune occurrence | **bloquant** |

Les éléments cités dans le brief (Anais Bacarisse, EI, SIREN 105 253 132, RCS
Paris) **n'apparaissent nulle part dans le dépôt** : ils ne sont donc pas
vérifiés par une source interne, conformément à la consigne de ne pas les
traiter comme acquis.

## 8 · Hébergeur

Le site est hébergé chez Hostinger — confirmé par les greffons Hostinger actifs
et l'environnement serveur. L'entité contractante applicable au contrat Urbizen
et ses coordonnées téléphoniques **restent à confirmer** : elles ne figurent
dans aucune source interne, et il ne faut pas en inventer.
