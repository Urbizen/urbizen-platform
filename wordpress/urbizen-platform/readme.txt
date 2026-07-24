=== Urbizen Platform ===
Contributors: urbizen
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.12.0
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
* comptes particuliers : rôle client, inscription, vérification du courriel,
  renvoi du lien et changement d'adresse (parcours publics servis par
  admin-post, avec réponse uniforme contre l'énumération) ;
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

Version 0.12.0 — la logique métier couvre les formulaires d'urbanisme, le
composant cadastre, la transmission au service documentaire, et le parcours
public des comptes particuliers (E2.2).

L'extension expose des shortcodes (formulaires, cadastre, inscription, renvoi,
changement d'adresse) et traite ses actions par `admin-post.php`. Elle emploie
des options WordPress pour la limitation de débit et les jetons anti-robot, et
des métadonnées utilisateur privées (préfixe `_urbizen_`) pour les comptes.

Elle **ne crée aucune table propre** et **n'ajoute aucune route REST** : elle
s'appuie sur les tables natives de WordPress. Cette livraison ne crée ni ne
publie aucune page WordPress ; l'exposition des shortcodes reste un geste
d'exploitation distinct.

Les modules sont ajoutés un par un, avec vérification à chaque étape.

== Changelog ==

= 0.12.0 =
* Parcours public des comptes (E2.2, D-046) : inscription, vérification de
  l'adresse (le GET inspecte le lien et affiche la confirmation sans
  consommation, le POST confirmé consomme le jeton et vérifie l'adresse),
  renvoi du lien, changement d'adresse différé. Actions `admin-post`,
  shortcodes, gabarits et style dédiés.
* Émission de courriel unifiée par un seul orchestrateur (préparer, envoyer
  hors verrou, clore) ; réponse uniforme contre l'énumération.
* Quota idempotent à source de vérité et miroir de compatibilité ;
  `wp urbizen accounts quota-verify` (lecture seule) et `--repair-mirror`.
* Aucune table propre, aucune route REST ; miroir du quota conservé au
  format 0.11.0 pour la compatibilité d'un retour arrière.

= 0.4.0 =
* Contrat de données canonique 1.0 entre le cadastre et les formulaires :
  structure imbriquée, versionnée, sans géométrie.
* Bloc « urbizen/formulaire » et shortcode « [urbizen_formulaire] » :
  formulaire de localisation reprenant la parcelle confirmée.
* Validation locale uniquement : aucune donnée n'est transmise à un serveur,
  et les données de localisation restent dans l'onglet du visiteur.

= 0.3.0 =
* Composant cadastre : bloc Gutenberg « urbizen/cadastre » et shortcode
  « [urbizen_cadastre] », partageant le même rendu dynamique côté serveur.
* Leaflet 1.9.4 embarqué localement (BSD 2-Clause, voir
  assets/vendor/leaflet/LICENSE) : aucun appel à un CDN.
* Aucune adresse ni parcelle enregistrée dans le contenu de la page :
  ces données restent dans l'onglet du visiteur.

= 0.1.0 =
* Amorçage : chargement automatique des classes, contrôle d'environnement,
  activation et désactivation sans effet de bord, journalisation, réglages,
  références de dossier normalisées.
