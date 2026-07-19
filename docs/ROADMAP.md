# Feuille de route Urbizen

> Alignée sur l'architecture réelle et l'avancement constaté en production.
> La référence d'ensemble reste [PROJECT_MASTER_PLAN.md](PROJECT_MASTER_PLAN.md).

Dernière mise à jour : 19 juillet 2026.

---

## Étape 1 — Socle technique ✅ terminée

- [x] Audit complet du dépôt et de la production
- [x] Thème enfant `urbizen-child` nu, avec correctifs de compatibilité
- [x] Extension `urbizen-platform` nue, sans effet de bord
- [x] Déploiement et activation de l'extension, sans erreur PHP
- [x] Procédure de sauvegarde et de retour arrière éprouvée

---

## Étape 2 — Gabarits et mémoire du projet ✅ terminée

- [x] Export des gabarits FSE en fichiers versionnés
- [x] Report des styles globaux dans `theme.json`
- [x] Suppression de la dépendance à `wp_navigation` ID 15
- [x] Documentation permanente en cinq documents
- [x] Activation du thème enfant validée par le protocole complet, captures
      d'écran ordinateur et mobile pixel-identiques

---

## Étape 3 — Composant cadastre

- [x] Auto-hébergement de Leaflet, fin des appels CDN (RGPD) — *19/07/2026*
- [x] Shortcode `[urbizen_cadastre]` et rendu dynamique commun — *19/07/2026*
- [x] Bloc Gutenberg `urbizen/cadastre` : `block.json`, `editor.js`,
      `InspectorControls`, aperçu statique — **validé dans l'éditeur réel**
      le 19/07/2026 (insertion, édition des 5 réglages, enregistrement,
      rechargement sans erreur de validation)
- [x] Source de vérité unique dans l'extension, copies supprimées (D-008) — *19/07/2026*
- [x] Suppression des `innerHTML` et identifiants uniques par instance — *19/07/2026*
- [x] Licence Leaflet BSD 2-Clause versionnée, source et empreintes documentées — *19/07/2026*
- [x] Bancs d'essai **simulés** `tests/cadastre/` : 32 contrôles JS (jsdom),
      36 contrôles PHP (doublures) — *19/07/2026*
- [x] **Test WordPress réel** sur page brouillon non indexée — *19/07/2026* :
      13 contrôles passés, aucune erreur, aucun 404 (voir DEPLOY_CADASTRE.md)
- [ ] Auto-hébergement des **polices** (Google Fonts encore appelé par le thème)
- [ ] Événement `urbizen:parcel-confirmed` consommé par les formulaires (étape 4)

Légende : `[x]` terminé et vérifié · `[~]` écrit, non validé en conditions réelles.

---

## Étape 4 — Moteur de formulaires

- [ ] Définitions déclaratives versionnées dans `src/Forms/definitions/`
- [ ] Rendu accessible : labels, `aria-describedby`, navigation clavier
- [ ] Validation serveur, nonces, limitation de débit, honeypot et délai minimal
- [ ] Tables `wp_urbizen_submissions`, `_submission_fields`, `_files`, `_log`
- [ ] Formulaire de contact, en remplacement de Fluent Forms n° 5
- [ ] Bascule de la page Contact, puis 7 jours d'observation

---

## Étape 5 — Formulaires métier et backend

- [ ] Formulaire DP, porté depuis `frontend/formulaires/dp-formulaire.html`
- [ ] Formulaire PCMI, porté depuis `pc-formulaire.html`
- [ ] Pièces jointes : type MIME réel, plafonds, SHA-256, stockage hors racine web
- [ ] Client HTTP vers le service Python : HMAC, idempotence, rejeu
- [ ] Authentification de `POST /api/dp` et CORS restreint côté Python
- [ ] Complétion des mappings Cerfa, aujourd'hui tous en `TODO_`
- [x] `requirements.txt` et `.env.example` — *fait le 19/07/2026*
- [ ] Normalisation de la génération des PDF selon la convention
      `URB-AAAA-NNNN_type-document_version.ext`
- [x] Documentation du lancement local du service Python — *fait le 19/07/2026*
- [ ] Tests automatiques du service : `tests/` est vide à ce jour

---

## Étape 6 — Refonte des pages dans l'univers Urbizen

Toutes les pages sont refaites à partir de `frontend/homepage/` et de
`urbizen-tokens.css`, sous forme de patterns versionnés dans le thème enfant, en
conservant les URL existantes.

- [ ] Accueil
- [ ] Déclaration préalable · Permis de construire
- [ ] Tarifs · Autres projets · Espace professionnels
- [ ] Commander un dossier · Contact
- [ ] Pages légales : mentions, confidentialité, CGV
- [ ] Correction du slug `refund_returns` avec redirection 301
- [ ] Futures pages SEO et blog
- [ ] Nettoyage du CSS personnalisé hérité de l'éditeur

---

## Étape 7 — Retrait de Fluent Forms

- [ ] Export chiffré des 6 définitions et des 4 entrées, hors dépôt
- [ ] Import des entrées dans les tables Urbizen
- [ ] Désactivation, puis 30 jours d'observation
- [ ] Suppression de l'extension et de ses 7 tables
- [ ] Reprise du transport SMTP par l'extension, puis retrait de Fluent SMTP

---

## Étape 8 — Dossiers et espace client

- [ ] Modèle de dossier et les 13 statuts métier
- [ ] Historique des changements et versions des documents
- [ ] Inscription, connexion, tableau de bord client
- [ ] Dépôt de pièces, liste des pièces manquantes, validation des plans
- [ ] Téléchargements protégés par jeton expirant

---

## Étape 9 — Administration, paiement, industrialisation

- [ ] Tableau de bord Urbizen, filtres par statut, commentaires internes
- [ ] Acompte, solde, factures, Stripe
- [ ] Courriels transactionnels et rappels automatiques
- [ ] Purge RGPD planifiée et outils d'export et d'effacement
- [ ] Environnement de préproduction, sauvegardes automatisées, supervision
- [ ] Tests automatisés dans `tests/`, scripts de déploiement dans `scripts/`

---

## Dette technique suivie

| Sujet | Origine | Étape de traitement |
|---|---|---|
| Mappings Cerfa en `TODO_` | existant | 5 |
| `POST /api/dp` non authentifié, CORS `*` | existant | 5 |
| ~~`requirements.txt` et `.env.example` absents~~ | existant | **soldé le 19/07/2026** |
| Aucun test du service Python (`tests/cadastre/` existe désormais) | existant | 5 |
| CSS personnalisé dupliqué et caractère parasite | hérité de l'éditeur | 6 |
| Slug CGV `refund_returns` | héritage WooCommerce | 6 |
| 393 Mo de médias non optimisés | existant | 6 |
| Pages WooCommerce publiées, extension inactive | existant | 7 |
| Triple analytics, 5 extensions marketing | existant | 7 |
| Compte administrateur unique, sans 2FA | existant | 9 |
