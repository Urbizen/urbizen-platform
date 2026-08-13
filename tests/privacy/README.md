# Bancs de consentement aux traceurs

Observation **réelle** dans un navigateur : requêtes réseau, cookies, stockage
local et de session. Aucune écriture sur le site.

## Prérequis

    cd tests/privacy && npm install && npx playwright install chromium

## Bancs

| Banc | Rôle |
|---|---|
| `audit-traceurs.mjs` | ce qu'une première visite déclenche réellement |
| `audit-chatway.mjs` | comportement isolé de Chatway, pour déterminer sa catégorie |
| `test-consentement.mjs` | les quatre états — aucun choix, refus, acceptation, retrait |

    node audit-traceurs.mjs [url]
    node audit-chatway.mjs
    node test-consentement.mjs [url]

## Ce que le banc de consentement NE contrôle pas

Il n'exige **pas** l'absence de requête vers `googletagmanager.com` ou
`googlesyndication.com`. Avec Consent Mode avancé, une connexion à Google peut
exister alors que tout stockage et tout usage soumis à consentement restent
refusés. Exiger l'absence de requête ferait échouer une configuration conforme,
et réussir une configuration qui bloque le script tout en posant des cookies
ailleurs.

Sont contrôlés : les quatre signaux Consent Mode, les cookies, le stockage, la
chaîne TCF, et l'état `gcs` des collectes GA4. **Une collecte sans `gcs` est
traitée comme un échec** : elle signifie qu'aucun Consent Mode n'encadre l'envoi.

## Sélecteurs du CMP

`SEL` en tête de `test-consentement.mjs` porte les sélecteurs Complianz. Ils
sont à confirmer après installation : un sélecteur faux rend le banc rouge pour
une raison qui n'est pas la conformité.
