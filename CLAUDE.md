# CLAUDE.md — Urbizen Platform

## Mission du projet

Urbizen est une plateforme française de gestion et de préparation de dossiers d’urbanisme :

- déclaration préalable de travaux ;
- permis de construire ;
- permis de construire maison individuelle ;
- collecte des informations clients ;
- gestion documentaire ;
- génération de documents administratifs ;
- suivi des dossiers ;
- espace client ;
- espace administrateur.

La plateforme ne doit jamais prétendre générer automatiquement les plans architecturaux. Les plans sont réalisés et fournis par Urbizen.

## Architecture générale

Le projet est organisé en plusieurs parties :

- `backend/` : API Python, génération de documents et automatisations ;
- `frontend/` : formulaires et composants d’interface ;
- `wordpress/` : intégration WordPress, thème enfant et plugin Urbizen ;
- `docs/` : architecture, règles métier et documentation ;
- `tests/` : tests automatiques ;
- `scripts/` : scripts d’installation, de maintenance et de déploiement.

## Règles de développement

1. Ne jamais modifier plusieurs domaines fonctionnels sans justification.
2. Ne jamais supprimer une fonctionnalité existante sans autorisation.
3. Ne jamais introduire de données clients réelles dans le dépôt.
4. Ne jamais versionner :
   - fichiers `.env` ;
   - mots de passe ;
   - clés API ;
   - pièces d’identité ;
   - dossiers clients ;
   - PDF clients ;
   - documents administratifs nominatifs.
5. Toujours utiliser des données fictives dans les exemples et tests.
6. Préserver la compatibilité avec WordPress.
7. Documenter les variables d’environnement dans `.env.example`.
8. Ajouter une gestion d’erreur explicite pour toute opération externe.
9. Valider et nettoyer toutes les données reçues depuis un formulaire.
10. Ne pas construire de dépendance forte entre WordPress et la génération PDF.

## Méthode de travail

Avant toute modification importante :

1. lire les fichiers concernés ;
2. expliquer brièvement le changement prévu ;
3. identifier les risques ;
4. modifier uniquement les fichiers nécessaires ;
5. vérifier la syntaxe ;
6. exécuter les tests disponibles ;
7. résumer les fichiers modifiés.

## Git

Ne jamais pousser directement sur `main` pour une fonctionnalité importante.

Branches recommandées :

- `main` : version stable ;
- `develop` : développement intégré ;
- `feature/nom-fonctionnalite` : nouvelle fonctionnalité ;
- `fix/nom-correction` : correction ;
- `docs/nom-documentation` : documentation.

Les messages de commit doivent être explicites.

Exemples :

- `feat: ajout du suivi des dossiers`
- `fix: correction du remplissage du Cerfa`
- `docs: ajout de l’architecture technique`
- `refactor: séparation de la génération PDF`
- `test: ajout des tests du formulaire DP`

## Design Urbizen

L’identité visuelle doit évoquer :

- l’urbanisme ;
- le cadastre ;
- les plans techniques ;
- l’architecture ;
- la rigueur administrative.

Principes :

- interface claire et professionnelle ;
- fond clair ou blanc cassé ;
- vert Urbizen comme couleur principale ;
- bleu nuit pour les textes importants ;
- quadrillage technique discret ;
- cartes structurées ;
- bordures fines ;
- angles modérément arrondis ;
- animations légères ;
- boutons compacts ;
- excellente lisibilité mobile.

Typographies recommandées :

- Space Grotesk pour les titres ;
- IBM Plex Sans pour le texte ;
- IBM Plex Mono pour les références techniques.

## Règles métier essentielles

Un dossier Urbizen peut contenir :

- informations du demandeur ;
- informations du terrain ;
- références cadastrales ;
- description du projet ;
- pièces administratives ;
- photographies ;
- plans fournis par Urbizen ;
- Cerfa ;
- notice descriptive ;
- bordereau des pièces ;
- dossier PDF final.

Statuts prévus :

- brouillon ;
- demande reçue ;
- acompte attendu ;
- documents attendus ;
- dossier incomplet ;
- analyse en cours ;
- plans en préparation ;
- validation client ;
- corrections ;
- solde attendu ;
- dossier finalisé ;
- dossier transmis ;
- dossier archivé.

## Sécurité et RGPD

La plateforme manipule potentiellement des données personnelles sensibles.

Toujours prévoir :

- contrôle des accès ;
- séparation des dossiers clients ;
- journalisation ;
- stockage sécurisé ;
- suppression maîtrisée des documents ;
- durée de conservation configurable ;
- liens de téléchargement protégés ;
- validation du type et de la taille des fichiers ;
- protection contre les téléchargements malveillants.

## Priorité actuelle

La priorité immédiate est de stabiliser le service existant de génération documentaire avant de développer l’espace client complet.
