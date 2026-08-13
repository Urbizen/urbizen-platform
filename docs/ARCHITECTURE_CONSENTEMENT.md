# Architecture du consentement — comparaison des scénarios à 0 €

> Analyse du 12 août 2026, **avant toute modification**. Complète
> `AUDIT_CONSENTEMENT.md`, qui établit les 13 écarts mesurés.

## 1 · Le fait qui commande la décision

AdSense **ne diffuse aucune annonce**.

| Mesure | Valeur |
|---|---|
| `accountStatus` (Site Kit) | `client-getting-ready` |
| `siteStatus` | vide |
| Réponse `/pagead/ads` | 200, **603 octets** |
| Emplacements `<ins class="adsbygoogle">` | 1 |
| Emplacements remplis | **0** — `data-ad-status="unfilled"` |
| Paramètre du script | `host=ca-host-pub-…` → compte via plateforme |

Le site supporte donc aujourd'hui **toute la charge de conformité publicitaire
sans aucune recette**. Les requêtes partent, les données circulent, rien n'est
diffusé.

## 2 · Correction — `npa=1` n'exonère pas du TCF

L'observation `npa=1` (annonces non personnalisées) avait été avancée comme
susceptible de rendre le TCF inutile. **C'est faux, et il faut le corriger.**

Depuis le 16 janvier 2024, Google exige un **CMP certifié intégrant le TCF**
pour toute diffusion d'annonces auprès d'utilisateurs de l'EEE et du Royaume-Uni
— AdSense, AdMob et Ad Manager. Cette exigence porte sur la **diffusion**, pas
sur la personnalisation : servir des annonces non personnalisées n'y échappe pas.

Par ailleurs, les annonces non personnalisées déposent encore des identifiants
— limitation de répétition, mesure, lutte contre la fraude — qui relèvent de
l'article 82 de la loi Informatique et Libertés et requièrent un consentement.

**Conséquence : conserver AdSense actif impose le TCF**, donc soit le CMP Google
natif, soit Complianz Premium. Il n'existe pas de voie « AdSense actif sans TCF ».

## 3 · Complianz gratuit — ce que la version 7.5.2 couvre réellement

Vérifié sur la fiche officielle wordpress.org, version disponible ce jour.

| Fonction | Version gratuite |
|---|---|
| WP Consent API | **oui** |
| Google Consent Mode | **oui** — « with Google Tag Manager or Google Analytics » |
| Intégration Site Kit | **oui** |
| Blocage de scripts tiers | **oui** — « Blocks 3rd party cookies like Google Maps, Facebook, AdSense… » |
| Bandeau Accepter / Refuser / Personnaliser | **oui** |
| Registre de preuve | oui |
| **TCF / IAB** | **non — Premium uniquement** |

Compatibilité : testé jusqu'à **WP 7.0.3**, soit exactement la version en
production. PHP 7.4 minimum, la production est en 8.3.31.

**Point à confirmer à l'installation** : le blocage d'un script **non répertorié**
comme Chatway. La liste nommée couvre les services connus ; l'ajout d'une règle
personnalisée sur `cdn.chatway.app` doit être vérifié dans l'interface plutôt
que présumé.

## 4 · Site Kit — déjà prêt

```
googlesitekit_consent_mode = [ 'enabled' => false, 'regions' => [ 32 pays EEE, dont FR ] ]
```

Le Consent Mode est présent et pré-réglé sur l'EEE, simplement désactivé.
Site Kit 1.181 embarque `Core/Consent_Mode/` et appelle **`wp_set_consent`** :
il attend un CMP compatible **WP Consent API**. Complianz en est un.

L'activer pose les quatre signaux à `denied` par défaut ; Complianz les fait
passer à `granted` selon les catégories acceptées.

## 5 · Les deux scénarios

### A — AdSense désactivé tant qu'il ne sert pas

```
Complianz gratuit  +  WP Consent API  +  Site Kit Consent Mode
Site Kit : Analytics conservé, AdSense désactivé
Chatway  : bloqué par le script blocker, chargé après consentement
```

| | |
|---|---|
| Coût | 0 € |
| TCF | **sans objet** — aucune annonce diffusée |
| GA4 | Consent Mode v2, `denied` par défaut, `granted` sur acceptation |
| Chatway | bloqué en amont, catégorie fondée sur son comportement réel |
| État de consentement côté serveur | **oui**, via WP Consent API |
| Registre de preuve | oui |
| Bancs des quatre états | sélecteurs stables, mesure fiable |
| Requêtes publicitaires avant consentement | **supprimées** |

Effet immédiat : les appels vers `googlesyndication.com` et
`adtrafficquality.google` disparaissent purement et simplement, puisqu'ils ne
servaient à rien.

### B — AdSense conservé actif

```
CMP Google natif (Confidentialité et messages)  →  seul CMP certifié gratuit
```

| | |
|---|---|
| Coût | 0 € |
| TCF | **obligatoire**, fourni par le CMP Google |
| Accès au CMP | **à vérifier** — compte `client-getting-ready`, ouvert via plateforme |
| GA4 | signaux transmis par le CMP Google, à valider empiriquement |
| Chatway | **non couvert** — le CMP Google ne pilote que les balises Google et les fournisseurs TCF |
| État côté serveur | **aucun** — pas d'appel `wp_set_consent` |
| Registre de preuve | chaîne TCF chez Google, pas de journal exploitable |
| Bancs | interface en iframe transverse Google, balisage instable |
| Recette actuelle | **nulle** |

Chatway devrait être conditionné à un objectif TCF choisi par approximation.
Or consentir à un objectif TCF ne vaut pas consentement pour un prestataire qui
n'est pas fournisseur TCF : l'arrangement serait techniquement fonctionnel et
juridiquement bancal.

### Quand le compte passera à `ready`

La diffusion démarre, une recette devient possible, et **l'exigence de CMP
certifié TCF devient opérante** pour le trafic EEE. Il faudra alors trancher :
adopter le CMP Google, ou passer Complianz en Premium. Le scénario A ne ferme
aucune porte — il diffère une décision qui n'a pas lieu d'être prise
aujourd'hui.

## 6 · Recommandation

**Le scénario A est le plus robuste, techniquement et juridiquement, à 0 €.**

Trois raisons :

1. **Il supprime le problème au lieu de l'encadrer.** Aucune annonce n'étant
   diffusée, désactiver AdSense retire d'un coup les requêtes publicitaires,
   le contrôle antifraude et l'exigence TCF — sans perte, puisqu'il n'y a pas
   de recette.
2. **Il traite Chatway proprement.** Un vrai état de consentement côté serveur,
   une catégorie assumée, un blocage préalable — au lieu d'une approximation
   sur un objectif TCF.
3. **Il est vérifiable.** Sélecteurs stables, `wp_has_consent()` interrogeable,
   quatre états mesurables sans dépendre d'une iframe Google.

Le scénario B n'a d'intérêt que si la diffusion démarre et rapporte. Cette
condition n'est pas remplie aujourd'hui.
