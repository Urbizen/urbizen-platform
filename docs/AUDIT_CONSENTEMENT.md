# Audit du consentement aux traceurs — état au 12 août 2026

> Audit **en lecture seule**, mesuré sur la production servie, première visite,
> aucun choix exprimé. Aucune interaction, aucune écriture. Base du lot
> `fix/privacy-consent-compliance`.
>
> Rejoué par `tests/privacy/audit-traceurs.mjs` et `tests/privacy/audit-chatway.mjs`.

## 1 · Ce qui se produit réellement avant tout consentement

Mesuré dans un navigateur réel, profil vierge, neuf secondes après le chargement.

| Constat | Détail |
|---|---|
| **Collecte GA4 effectuée** | `POST region1.google-analytics.com/g/collect` — `tid=G-T0R3WYXBG1` |
| **Cookies Analytics déposés** | `_ga` et `_ga_T0R3WYXBG1`, domaine `.urbizen.fr`, **400 jours** |
| **AdSense jusqu'à la demande d'annonce** | `adsbygoogle.js`, `show_ads_impl.js`, `zrt_lookup.html`, puis `/pagead/ads?client=ca-pub-9709175909126940` |
| **Contrôle antifraude Google** | `ep1/ep2.adtrafficquality.google` — 5 requêtes (`sodar`) |
| **Chatway chargé** | `cdn.chatway.app/widget.js` |
| **Aucun Consent Mode** | aucune entrée `consent` dans le `dataLayer` |
| **Aucun CMP** | `window.__tcfapi` absent, `window.__gpp` absent |

Il ne s'agit donc pas d'un simple dépôt de cookies : **une mesure d'audience est
réellement transmise et une publicité réellement demandée** avant que le
visiteur n'ait pu se prononcer.

### Une seule implémentation GA4

`dataLayer` ne contient qu'un `config` : `GT-NGKQBJ4D`, qui route vers la
propriété `G-T0R3WYXBG1`. **Il n'y a pas de doublon Analytics.**

MonsterInsights émet uniquement des commentaires HTML :

```
<!-- Remarque : MonsterInsights n'est actuellement pas configuré sur ce site. -->
<!-- No tracking code set -->
```

`monsterinsights_get_v4_id_to_output()` renvoie vide ; les profils site et
réseau valent `false`. Ses réglages `demographics = 1` et `anonymize_ips = 0`
paramètrent un traceur qui ne s'exécute pas : **ce ne sont pas des écarts de
conformité actifs**, et les corriger ne changerait rien au comportement observé.

L'état de Google Signals se lit dans la propriété GA4, côté Google. Il **n'est
pas** modifiable depuis WordPress et reste un point de contrôle manuel.

## 2 · Un CMP Google est peut-être déjà armé

`localStorage` contient, dès la première visite :

```
google_auto_fc_cmp_setting = [1]
```

C'est un indicateur de **Funding Choices**, le mécanisme de message de
consentement automatique de Google pour AdSense. Aucun bandeau n'apparaît
aujourd'hui, mais la présence de cette clé impose de vérifier l'état de
« Confidentialité et messages » dans le compte AdSense **avant** d'activer
Complianz : deux interfaces de consentement concurrentes se contrediraient.

Vérification à faire dans la console AdSense — hors de portée depuis WordPress.

## 3 · Chatway — catégorie déterminée par le comportement, non par la nature

### Observé sans interaction (20 s, autres tiers coupés)

| | |
|---|---|
| Requêtes | `cdn.chatway.app/widget.js` + un script local du greffon |
| Cookies | **aucun** |
| `localStorage` / `sessionStorage` | **aucun** |
| Iframes | aucune |
| Éléments injectés | 4 |

Passivement, Chatway **n'écrit rien sur le terminal**.

### Ce que son code prévoit dès activation

Analyse statique de `widget.js` (83 Ko) :

| Mécanisme | Occurrences |
|---|---|
| `sessionStorage` | 30 |
| `localStorage` | 13 |
| `document.cookie` | 4 |

Clés portant un **identifiant de visiteur persistant** :

```
ch_session_info_${widgetID}
ch_visitor_details_${widgetID}
ch_quick_reply_open_${widgetID}
```

Points de terminaison contactés :

```
prod-api.chatway.app/api/pixel/chat-contacts/
prod-api.chatway.app/api/v2/pixel/widget-agents/
prod-api.chatway.app/api/v2/pixel/widgets
www.cloudflare.com/cdn-cgi/trace          → adresse IP et pays du visiteur
prod-chaty-uploads.s3.us-west-2.amazonaws.com → États-Unis
```

### Conclusion

Trois éléments écartent la qualification de simple outil fonctionnel :

1. un **identifiant de visiteur persistant** en `localStorage` ;
2. des points de terminaison explicitement nommés `/pixel/` ;
3. un appel à `cdn-cgi/trace` qui **récupère l'IP et le pays**, et un stockage
   en `us-west-2` — donc un **transfert hors EEE**.

**Chatway requiert un consentement** et doit être bloqué en amont. La nuance à
préserver : si le visiteur clique lui-même pour ouvrir la conversation, le
chargement devient un service qu'il a explicitement demandé.

Aucune intégration native de Chatway dans Complianz n'est présumée : à vérifier
à l'installation, et à défaut, blocage manuel du script.

## 4 · OptinMonster — inerte

Compte non connecté, **zéro campagne**, aucun script servi. L'`omepage.js`
relevé dans une première passe était `urbizen-homepage.js` — un faux positif.

Aucune logique de blocage ne lui est consacrée. Les bancs vérifient en revanche
qu'il **le reste** : une campagne créée plus tard ne doit pas réactiver
silencieusement un traceur non couvert.

## 5 · Périmètre retenu

**Trois traceurs à bloquer**, et non cinq :

| Traceur | Source | Catégorie visée |
|---|---|---|
| Google Analytics 4 | Site Kit — `GT-NGKQBJ4D` | mesure d'audience |
| Google AdSense | Site Kit — `ca-pub-9709175909126940` | publicité |
| Chatway | greffon `chatway-live-chat` | à confirmer — au minimum soumis à consentement |

MonsterInsights et OptinMonster sont **inertes** : leur désactivation relève du
nettoyage technique, pas du correctif de conformité.

## 6 · Ce qui reste à vérifier hors WordPress

| Point | Où |
|---|---|
| Google Signals | propriété GA4, interface Analytics |
| Message CMP Google concurrent | AdSense → « Confidentialité et messages » |
| Licence Complianz Premium | compte Complianz |
