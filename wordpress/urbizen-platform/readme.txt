=== Urbizen Platform ===
Contributors: urbizen
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Logique métier Urbizen : formulaires d'urbanisme, composant cadastre, dossiers.

== Description ==

Cette extension porte l'intégralité de la logique métier Urbizen, indépendamment
du thème actif :

* moteur de formulaires maison (déclaration préalable, permis de construire,
  contact, devis, professionnels) — sans dépendance à une extension tierce ;
* validation serveur, nonces, limitation de débit et protection anti-spam ;
* contrôle des pièces jointes (type MIME réel, taille, intégrité) et stockage
  hors racine web ;
* composant cadastre s'appuyant sur la Géoplateforme IGN ;
* transmission au service Python de génération documentaire ;
* rétention et droits RGPD.

Les plans d'architecte restent réalisés et fournis par Urbizen : la plateforme
ne les génère jamais automatiquement.

== Architecture ==

* Thème enfant `urbizen-child` : rendu et gabarits uniquement.
* Extension `urbizen-platform` : toute la logique métier.
* Service Python `dp-service` : Cerfa, notice, bordereau, assemblage PDF.

Le sens des dépendances est unique : thème -> extension -> service Python.

== État actuel ==

Version 0.1.0 — amorçage nu (étape 1). L'extension ne crée aucune table,
aucune option et aucun fichier ; elle n'expose ni formulaire ni route REST.
Les modules seront ajoutés un par un, avec vérification à chaque étape.

== Changelog ==

= 0.1.0 =
* Amorçage : chargement automatique des classes, contrôle d'environnement,
  activation et désactivation sans effet de bord, journalisation, réglages,
  références de dossier normalisées.
