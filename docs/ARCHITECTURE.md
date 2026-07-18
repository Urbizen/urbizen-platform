# Architecture technique Urbizen

## 1. Objectif

Urbizen doit permettre de collecter les informations d’un client, centraliser ses documents, suivre la production d’un dossier d’urbanisme et générer les pièces administratives nécessaires.

## 2. Composants

### WordPress

WordPress assure :

- les pages publiques ;
- le référencement naturel ;
- les pages commerciales ;
- les articles de blog ;
- l’authentification client ;
- l’espace client ;
- l’espace administrateur ;
- les paiements ;
- les formulaires.

### Plugin Urbizen

Un plugin WordPress dédié devra gérer :

- les dossiers ;
- les statuts ;
- les documents ;
- les validations ;
- les commentaires ;
- les paiements ;
- les échanges avec l’API Python.

### Backend Python

Le backend Python assure :

- le remplissage des Cerfa ;
- la génération des notices ;
- la génération des bordereaux ;
- l’assemblage des PDF ;
- le contrôle documentaire ;
- le renommage normalisé des pièces ;
- les automatisations métiers.

### Stockage

Les fichiers clients ne doivent pas être conservés dans le dépôt Git.

Le stockage devra être :

- privé ;
- organisé par dossier ;
- protégé par authentification ;
- compatible avec une suppression RGPD ;
- accessible avec des liens temporaires ou protégés.

## 3. Flux principal

1. Le client choisit une prestation.
2. Il crée son compte.
3. Il remplit le questionnaire.
4. Il verse l’acompte.
5. Il transmet les documents demandés.
6. Urbizen analyse le projet.
7. Urbizen produit les plans.
8. Le client valide les plans.
9. Les documents administratifs sont générés.
10. Le client règle le solde.
11. Le dossier final est assemblé.
12. Le dossier est remis au client.

## 4. API envisagée

Exemples de routes :

- `POST /api/dossiers`
- `GET /api/dossiers/{id}`
- `POST /api/dossiers/{id}/documents`
- `POST /api/dossiers/{id}/generate`
- `GET /api/dossiers/{id}/status`
- `GET /api/dossiers/{id}/downloads`
- `POST /api/dossiers/{id}/validate`

Toutes les routes sensibles devront être authentifiées.

## 5. Principes

- WordPress orchestre l’expérience client.
- Python réalise les traitements documentaires.
- Les plans restent produits manuellement.
- Les fichiers sont versionnés.
- Les traitements sont traçables.
- Les données clients ne sont jamais intégrées au code source.
