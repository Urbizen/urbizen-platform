# Passation de session

Photographie de l'état du projet à la fin d'une session de travail, pour qu'une
reprise — humaine ou assistée — parte du réel et non d'une supposition.
Ce fichier est **réécrit à chaque fin de session**, jamais complété par empilement.

Contexte durable et règles de travail : [AI_CONTEXT.md](AI_CONTEXT.md).
Architecture et cap du projet : [PROJECT_MASTER_PLAN.md](PROJECT_MASTER_PLAN.md).

---

## Session du 20 juillet 2026

### Point de reprise

| Élément | Valeur |
|---|---|
| Branche stable | **`main`** |
| Commit courant | **`90191f062b0e1ef4e0804d9af1c9b1b4d8c56a79`** |
| Dernière PR fusionnée | [#7](https://github.com/Urbizen/urbizen-platform/pull/7) — **MERGED** |
| Plugin en production | `urbizen-platform` **0.4.0**, **actif** |
| Production vs `main` | **identiques fichier par fichier** (53 fichiers, empreinte `7834f6a4…`) |
| Page 1157 | **brouillon de validation interne**, conservé |
| Dépôt | `Urbizen/urbizen-platform` — **public** |

Historique des fusions : PR [#4](https://github.com/Urbizen/urbizen-platform/pull/4)
socle (`5989ba9`) · [#5](https://github.com/Urbizen/urbizen-platform/pull/5)
reproductibilité backend (`8214ae6`) ·
[#6](https://github.com/Urbizen/urbizen-platform/pull/6) composant cadastre
(`639e131`) · [#7](https://github.com/Urbizen/urbizen-platform/pull/7)
formulaire de localisation (`90191f0`).

### Où en est le projet

Le socle WordPress, le composant cadastre et le **premier formulaire Urbizen**
sont fusionnés dans `main` et déployés. La chaîne **cadastre → formulaire de
localisation est validée en conditions réelles** : une parcelle confirmée sur la
carte remplit le formulaire, la personne peut corriger ses valeurs, revenir
modifier son adresse, ou effacer ses données.

**Aucune transmission serveur en 0.4.0.** La validation est entièrement locale :
ni `fetch`, ni `XMLHttpRequest`, ni `sendBeacon`, ni soumission HTML. Le contrat
validé est publié par l'événement `urbizen:location-form-validated`, à charge de
l'hôte d'en faire quelque chose. Rien n'est écrit en base, aucune route REST
n'existe.

### Ce qui a été fait

- Socle : thème enfant `urbizen-child` et extension `urbizen-platform`, avec les
  correctifs de compatibilité du thème FSE Hostinger.
- Gabarits FSE exportés en fichiers versionnés : le rendu ne dépend plus de la base.
- Composant cadastre : bloc et shortcode au rendu commun, Leaflet 1.9.4 embarqué
  avec sa licence, aucun `innerHTML` sur une donnée, identifiants uniques.
- **Contrat canonique 1.0** (D-009) : structure imbriquée et versionnée, sans
  géométrie, avec les deux codes commune conservés séparément.
- **Formulaire de localisation** : `FormDefinition`, `FormRegistry`, `Renderer`,
  bloc `urbizen/formulaire` et shortcode `[urbizen_formulaire]`, pont
  `urbizen-form.js` indépendant du script cadastre.
- Reproductibilité du backend Python : `requirements.txt`, `.env.example`,
  documentation de lancement local.
- Documentation permanente : plan directeur, contexte, décisions, changelog,
  feuille de route, ce fichier.

### Contrôles effectués

- **243 assertions automatisées**, toutes vertes, par une commande unique :
  `cd tests/cadastre && npm test`. Elle régénère la fixture depuis le rendu réel
  de `Renderer.php`, puis enchaîne les quatre bancs et s'arrête au premier échec.
  Répartition : 32 + 126 côté JavaScript, 36 + 49 côté rendu PHP.
- **Validation navigateur réussie** sur la page 1157 : bloc présent dans l'outil
  d'insertion, réglages, enregistrement et rechargement sans erreur de
  validation, parcelle réelle reprise dans le formulaire, messages d'erreur
  accessibles, correction d'adresse, deux instances cloisonnées, effacement
  explicite, rendu mobile sans débordement horizontal.
- **Aucune erreur PHP liée à Urbizen** : le journal du serveur ne contient
  aucune entrée mentionnant l'extension, ni aucune erreur fatale.
- Aucune requête réseau émise par la validation locale, mesurée par interception.
- Aucune donnée personnelle en console, y compris en cas d'erreur.

### Réserves non bloquantes

1. **Une correction manuelle n'est pas persistée.** Une surface corrigée dans le
   formulaire figure bien dans le contrat validé, mais `sessionStorage` conserve
   la valeur cadastrale : après rechargement, la correction est perdue. Cohérent
   avec la règle « la validation n'écrit pas dans le stockage », mais surprenant
   pour la personne. À trancher avec la soumission serveur.
2. **Accords grammaticaux** de certains messages d'erreur, composés par
   concaténation, sans accord en genre ni en nombre : « Section cadastrale
   incorrect », « 5 chiffres attendu ».
3. **Google Fonts est encore chargé par le thème** — connu, **hors périmètre**
   de la 0.4.0, à traiter avec l'auto-hébergement des polices.

### Prochaine étape — décision d'architecture, pas encore du code

**Définir le contrat de soumission serveur sécurisé avant toute implémentation.**

C'est une décision à prendre, pas un développement autorisé. Rien ne doit être
écrit tant que les points suivants ne sont pas tranchés et consignés dans
`DECISIONS.md` :

- **Périmètre d'une demande client** : quelles données la constituent, et
  lesquelles restent hors dossier.
- **Autorité de la saisie** : à quel moment la correction manuelle devient la
  valeur de référence face à la donnée cadastrale.
- **Confirmation explicite** avant tout envoi : rien ne part sans un acte clair.
- **Validation PHP complète**, sans aucune confiance envers les champs masqués.
- **Nonce REST** et contrôle des capacités.
- **Limitation de débit** et **anti-spam** (honeypot, délai minimal de saisie).
- **Stockage WordPress ou transmission directe** au service Python : l'un,
  l'autre, ou les deux, et pourquoi.
- **Politique de conservation et de suppression RGPD**, avec durées chiffrées.
- **Gestion des pièces jointes** : type MIME réel, plafonds, stockage hors
  racine web.
- **Relation avec le backend Python** : authentification, idempotence, rejeu.
- **États métier d'une soumission**, à raccorder aux 13 statuts du `CLAUDE.md`.
- **Stratégie de reprise après erreur** : ce que voit la personne, ce que
  devient sa saisie.

### État des branches

| Branche | État | Recommandation |
|---|---|---|
| `feature/cadastre-block` | fusionnée par la PR #6 | **supprimable** — contenu doublement recouvert depuis |
| `feature/form-cadastre-integration` | fusionnée par la PR #7 | **temporairement conservée** — isole proprement les deux commits en cas de retour arrière ciblé |
| `docs/passation-0.4.0` | branche documentaire courante | à supprimer après le merge de sa PR |

Supprimer une branche distante n'efface aucun commit : tous restent atteignables
depuis `main`.

### Interdictions

1. **Ne pas publier la page 1157** : elle reste en brouillon et sert de page de
   validation interne et de non-régression. Aucune page publiée n'utilise le
   composant.
2. Ne pas fusionner de branche sans revue ni sauvegarde préalable.
3. Ne jamais pousser directement sur `main`.
4. **Ancien format 0.3.0** : un onglet ouvert avant le déploiement conserve un
   payload plat, désormais ignoré. La personne devra confirmer à nouveau sa
   parcelle. Aucune migration n'est prévue, rien n'est effacé automatiquement.
5. Ne jamais versionner de coordonnée serveur, de secret, de donnée personnelle
   ni de sauvegarde : le dépôt est public.
6. Ne jamais afficher le contenu de `wp-config.php`.
7. Ne rien modifier en production via l'éditeur de fichiers de WordPress.

### Points de vigilance pour la reprise

1. **Palette et filtre du parent** — le thème parent écrase palette et police des
   titres via `wp_theme_json_data_theme` en priorité 999 ; l'enfant les réapplique
   en priorité 1000. Recontrôler le rendu après toute mise à jour du parent
   (2.0.29 disponible).
2. **Gabarits en base** — `wp_template_part` et `wp_global_styles` restent
   rattachés au terme `wp_theme` du **parent**. La source de vérité est le
   dossier `parts/` du thème enfant.
3. **Dépôt public** — aucune coordonnée serveur, aucune donnée personnelle,
   aucune sauvegarde dans Git.
4. **Données réelles** — les 4 entrées Fluent Forms sont des données
   personnelles : les exporter chiffrées, hors dépôt, avant toute désactivation.
5. **`wp db export` échoue sous CageFS** — passer par `mysqldump` avec un fichier
   d'identifiants temporaire en mode 600, détruit par `shred -u` après usage.
6. **Cas corse** — les codes INSEE de Corse ne sont pas cinq chiffres (`2B033`
   pour Bastia). Toute règle de validation sur un code commune doit les accepter.
