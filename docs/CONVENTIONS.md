# Conventions Urbizen

## Nommage des fichiers

Utiliser des noms explicites en minuscules, séparés par des tirets ou des underscores selon le langage.

Exemples :

- `dossier_service.py`
- `document-generator.php`
- `client-dashboard.css`

## Python

- respecter PEP 8 ;
- utiliser des annotations de types ;
- isoler la configuration ;
- gérer explicitement les exceptions ;
- éviter les fonctions trop longues ;
- ne pas utiliser de chemins absolus ;
- écrire des tests pour la logique métier.

## PHP et WordPress

- utiliser les fonctions WordPress de sécurité ;
- échapper les sorties ;
- nettoyer les entrées ;
- vérifier les nonces ;
- vérifier les permissions ;
- préfixer les fonctions avec `urbizen_` ;
- ne pas modifier directement le cœur de WordPress.

## HTML, CSS et JavaScript

- conception responsive ;
- formulaires accessibles ;
- labels explicites ;
- messages d’erreur visibles ;
- composants réutilisables ;
- JavaScript non bloquant ;
- aucun secret dans le frontend.

## Documents clients

Format recommandé :

`URB-AAAA-NNNN_type-document_version.ext`

Exemples :

- `URB-2026-0001_cerfa_v1.pdf`
- `URB-2026-0001_plan-masse_v2.pdf`
- `URB-2026-0001_dossier-final_v1.pdf`
