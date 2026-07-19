# Urbizen — Plan directeur du projet

> Document de référence principal. En cas de contradiction avec un autre document,
> c'est celui-ci qui fait foi. Toute évolution importante doit le mettre à jour
> **dans le même commit** que le code concerné.

Dernière mise à jour : 19 juillet 2026.

---

## 1. Mission

Urbizen est une plateforme française de préparation de dossiers d'urbanisme :
déclaration préalable, permis de construire, permis de construire pour maison
individuelle. Elle collecte les informations du client, centralise ses documents,
génère les pièces administratives et suit l'avancement du dossier.

**Les plans architecturaux ne sont jamais générés automatiquement.** Ils sont
réalisés et fournis par Urbizen. Aucune communication, aucune interface et aucun
document ne doit laisser entendre le contraire.

---

## 2. Règle de reconstructibilité

> **Toute l'architecture, tous les gabarits et toute la logique applicative doivent
> être reproductibles depuis Git, sans personnalisation technique cachée dans la
> base. Les contenus et données d'exploitation sont sauvegardés séparément.**

Ce que Git doit contenir intégralement :

| Reproductible depuis Git |
|---|
| architecture |
| thème enfant |
| gabarits (templates, parts, patterns) |
| extension et logique métier |
| assets (CSS, JavaScript, polices, bibliothèques) |
| configuration technique versionnable |

Ce qui reste légitimement en base de données ou dans les médias, et relève du
plan de sauvegarde :

| Données d'exploitation |
|---|
| pages et articles |
| logo et médias téléversés |
| réglages SEO |
| comptes utilisateurs |
| données clients et soumissions |

Conséquence pratique : aucune personnalisation technique ne doit exister
uniquement en base. C'est la raison pour laquelle les gabarits FSE ont été
exportés en fichiers (voir [DECISIONS.md](DECISIONS.md), D-005).

---

## 3. Architecture

Trois couches, dépendances à sens unique : **thème → extension → backend**.

### 3.1 Thème enfant `urbizen-child`

Emplacement dans le dépôt : `wordpress/urbizen-child/`.
Thème enfant de `hostinger-ai-theme` (thème FSE fourni par l'hébergeur).

Responsabilités : rendu, gabarits, patterns, styles.
**Interdits : logique métier, requête SQL, appel réseau, traitement de données
personnelles.**

Contient deux correctifs de compatibilité indispensables, décrits en détail dans
[AI_CONTEXT.md](AI_CONTEXT.md) : résolution des chemins du thème parent, et
gabarits FSE exportés en fichiers.

### 3.2 Extension `urbizen-platform`

Emplacement dans le dépôt : `wordpress/urbizen-platform/`.

Responsabilités : moteur de formulaires maison, composant cadastre, validation
serveur, pièces jointes, dossiers et statuts, transmission au backend Python,
conformité RGPD, administration.

L'extension ne dépend d'aucun thème : changer de thème ne doit rien casser.

### 3.3 Backend Python `dp-service`

Emplacement dans le dépôt : `backend/dp-service/`.

Responsabilités : remplissage des Cerfa, notice descriptive, bordereau des
pièces, assemblage du PDF final.

Aucune dépendance à WordPress. Communication par HTTP uniquement.

---

## 4. Règles intangibles

1. Aucune logique métier dans le thème.
2. Aucune donnée personnelle réelle dans Git — jamais, sous aucun prétexte.
3. Aucun secret dans Git : identifiants et clés vivent dans `wp-config.php` ou
   l'environnement, documentés par leur seul nom dans `.env.example`.
4. Aucune personnalisation technique cachée en base (voir §2).
5. Aucune dépendance à Fluent Forms dans l'architecture cible.
6. Validation serveur systématique : la validation côté navigateur n'est qu'un
   confort.
7. Les pièces jointes sont stockées **hors racine web**.
8. Toute opération externe est assortie d'une gestion d'erreur explicite.
9. Jamais de poussée directe sur `main` pour une fonctionnalité : branche + PR.
10. Un développement n'est terminé que lorsque la documentation est à jour **dans
    le même commit**.

---

## 5. État de la production

Site : <https://urbizen.fr> — WordPress 7.0.2, PHP 8.3, WP-CLI 2.12.

| Élément | État au 19/07/2026 |
|---|---|
| Thème actif | `hostinger-ai-theme` 2.0.18 |
| `urbizen-child` | déployé, **activation en cours de validation** |
| `urbizen-platform` | déployé et **actif** (0.1.0, sans module) |
| Fluent Forms | actif, **conservé** jusqu'à migration complète |
| Fluent SMTP | actif, **conservé** |
| Pages publiques | 13, toutes en HTTP 200 |

Détail complet de l'installation, des extensions et des pièges : voir
[AI_CONTEXT.md](AI_CONTEXT.md).

---

## 6. Étapes du projet

| Étape | Contenu | État |
|---|---|---|
| 1 | Thème enfant nu + extension nue | ✅ terminée |
| 2 | Gabarits FSE en fichiers versionnés + documentation permanente | 🔄 en cours |
| 3 | Composant cadastre en shortcode | à venir |
| 4 | Moteur de formulaires + formulaire de contact | à venir |
| 5 | Formulaires DP et PCMI + pièces jointes + backend Python | à venir |
| 6 | Refonte des pages dans l'univers Urbizen | à venir |
| 7 | Retrait de Fluent Forms | à venir |
| 8 | Espace client et suivi des dossiers | à venir |

Détail et jalons : [ROADMAP.md](ROADMAP.md).

---

## 7. Protocole de vérification obligatoire

Aucun déploiement n'est considéré comme réussi sans ces contrôles. Toute anomalie
déclenche un retour arrière immédiat et un rapport.

**Contrôles automatiques**
1. Les 11 pages publiques répondent en HTTP 200.
2. Le texte visible de chaque page est identique à l'instantané de référence.
3. Aucun CSS, JavaScript, image ou police utile ne retourne 404.
4. Aucune erreur PHP nouvelle dans le journal.

**Contrôles visuels**
5. Captures avant/après de la page d'accueil sur ordinateur.
6. Captures avant/après de la page d'accueil sur mobile.
7. En-tête, navigation et menu mobile conservés.
8. Pied de page conservé.
9. Aucun débordement horizontal sur mobile.
10. Console JavaScript sans erreur nouvelle, lorsque l'outillage le permet.

**Retour arrière** : `wp theme activate hostinger-ai-theme` puis
`wp litespeed-purge all`. Moins d'une minute, sans recours aux sauvegardes.

---

## 8. Documentation du projet

| Fichier | Rôle |
|---|---|
| `docs/PROJECT_MASTER_PLAN.md` | référence principale (ce document) |
| `docs/AI_CONTEXT.md` | reprise du projet par une nouvelle IA, sans historique |
| `docs/DECISIONS.md` | décisions d'architecture et leurs conséquences |
| `docs/CHANGELOG.md` | journal des évolutions |
| `docs/ROADMAP.md` | feuille de route détaillée |
| `docs/ARCHITECTURE.md` | architecture fonctionnelle d'origine |
| `docs/CONVENTIONS.md` | conventions de code et de nommage |
