# Déploiement des parcours DP et PC — protocole

> **Rien de ce document n'a été exécuté.** Les commandes sont écrites pour être relues avant de
> l'être. Le déploiement demande une validation humaine explicite.
>
> Référence : PR #53, branche `feat/dp-pc-live-forms`.

## 1 · Prérequis

| Élément | Valeur |
|---|---|
| Branche | `feat/dp-pc-live-forms` |
| Version du lot de formulaires | `0.2.3` |
| Migrations de schéma | **aucune** — catalogue vide, aucun `dbDelta`, aucun `CREATE TABLE` |
| Accès | SSH Hostinger, clé `~/.ssh/urbizen_hostinger`, port 65002 |
| Production | WordPress 7.0.2 · PHP 8.3.31 · `urbizen-platform` 0.13.1 |
| Variables | `SSH_USER`, `SSH_HOST`, `WP_ROOT` — jamais versionnées, jamais affichées |

L'absence de migration est ce qui rend le retour arrière simple : **restaurer le code suffit**, et la
base n'a normalement pas à être touchée.

---

## 2 · Ce que l'audit a déjà établi, sans SSH

### HTTPS et origine — **conforme**

| Contrôle | Constat |
|---|---|
| Schéma | `http://` → `301` → `https://` |
| Domaine canonique | `https://urbizen.fr/` ; `www` redirige en `301` vers le domaine nu |
| Port explicite | aucun — l'origine est `https://urbizen.fr` |
| `<link rel="canonical">` | `https://urbizen.fr/` |

L'origine composée par `urbizen_child_origine_site()` vaudra donc `https://urbizen.fr`, sans port,
et correspondra à `window.location.origin` du cadre. **Une réserve subsiste** : une visite arrivant
par `www` est redirigée avant tout rendu, donc la page et le cadre partagent toujours la même
origine. À reconfirmer au premier contrôle réel.

### Cache HTTP — la chaîne de requête ne nuit pas, mais la page est en cache

Trois requêtes sur une ressource statique existante, sans version puis avec `?v=controle-a` et
`?v=controle-b` :

| En-tête | Valeur, identique aux trois requêtes |
|---|---|
| `HTTP` | `200` |
| `cache-control` | `public, max-age=604800` (7 jours) |
| `etag` | identique |
| `last-modified` | identique |
| empreinte du contenu | identique |
| `x-litespeed-cache` | **absent** sur les statiques |

**Conclusion.** Le serveur sert le fichier quelle que soit la chaîne de requête, sans la faire
entrer dans une clé de cache serveur — il n'y a pas de cache serveur sur ces fichiers. Le cache du
**navigateur**, lui, est indexé sur l'URL complète : changer `?v=` produit bien une URL nouvelle,
donc un téléchargement neuf. Aucun CDN interposé n'a été observé (aucun en-tête `cf-`, `x-cache`).
Le versionnement fonctionne donc — pour les ressources.

**Mais les pages HTML sont servies par LiteSpeed** (`x-litespeed-cache: hit`). Une page en cache
continuera de porter l'**ancienne** URL de cadre après déploiement. **Purger le cache de pages fait
donc partie du déploiement**, ce n'est pas une précaution facultative.

### Divergences production ↔ dépôt — **un point bloquant**

Comparaison des fichiers publiquement lisibles du thème enfant.

| Fichier | État |
|---|---|
| `theme.json` | **identique au HEAD de la branche** |
| `style.css` | **identique au HEAD de la branche** |
| `assets/js/urbizen-form-page.js` | correspond à un commit ancien de la branche (`9e8cf98`…`797f91f`) |
| `assets/forms/dp-formulaire.html` | **ne correspond à AUCUN commit du dépôt** |
| `assets/forms/pc-formulaire.html` | **ne correspond à AUCUN commit du dépôt** |
| `assets/js/urbizen-form-bridge.js` | **absent** (404) |
| `assets/js/urbizen-form-tarifs.js` | **absent** (404) |
| `assets/js/urbizen-form-pieces.js` | **absent** (404) |
| `urbizenFormConfig` sur la page | **absent** — `functions.php` est antérieur |

Les deux documents déployés sont une version **antérieure** à cette branche : écran de confirmation
centré, pas de bloc d'estimation, et un panneau `.dp-debug` qui n'a rien à faire en production. Leur
empreinte n'existe dans **aucun** commit, sur **aucune** branche.

> **Point d'arrêt.** Ces fichiers ont été déployés hors du dépôt, ou modifiés sur le serveur. Le
> déploiement les remplacerait. Il faut d'abord les archiver et confirmer que rien n'y a été ajouté
> qu'il faille conserver. C'est le sens de la sauvegarde du § 4 : elle n'est pas une formalité ici,
> elle est la seule copie de cet état.

### Relevé de production — audit SSH en lecture seule

| Élément | Valeur |
|---|---|
| WordPress | **7.0.2** |
| PHP | **8.3.31** |
| Thème actif | `urbizen-child` sur `hostinger-ai-theme` |
| `hostinger_ai_version` | **définie** (`1779364309`) |
| Transport de courriel | **`fluent-smtp` 2.2.95** |
| Cache | **`litespeed-cache` 7.8.1** |
| Sécurité dédiée | aucune extension spécialisée ; `hostinger` 3.0.69 et `hostinger-easy-onboarding` 2.1.27 |
| Autres extensions notables | `fluentform` 6.2.5, `kadence-blocks`, `all-in-one-seo-pack`, `google-site-kit`, `optinmonster` |
| Greffon Urbizen | `urbizen-platform` **0.13.1**, actif |
| WP-Cron | fonctionnel — « spawning is working as expected » |
| Thème enfant | `wp-content/themes/urbizen-child`, `755`, propriétaire = compte SSH, modifié le 1ᵉʳ août |
| Greffon | `wp-content/plugins/urbizen-platform`, `755`, propriétaire = compte SSH, modifié le 29 juillet |
| Espace disque | 5,0 To libres sur 21 To (77 % utilisés) |

**Trois conséquences directes.**

1. **`hostinger_ai_version` est définie.** La redirection d'onboarding du thème parent ne se
   déclenche donc pas, et `admin-post.php` est joignable. C'est la confirmation — par la mesure et
   non plus par déduction — que l'échafaudage `mu-plugin` employé en local est bien un artefact
   local, sans équivalent ni utilité en production. **Il ne doit pas partir.**
2. **`fluent-smtp` assure le transport.** Les courriels partiront donc réellement, ce qui rend le
   contrôle du § 7 concluant sur ce point. Sa configuration — expéditeur, domaine d'envoi — est à
   vérifier **dans son interface**, pas en lisant ses options : elles contiennent des identifiants.
3. **LiteSpeed 7.8.1 est présent**, donc `wp litespeed-purge all` est disponible. La purge du § 6
   n'est pas hypothétique.

**Deux points de vigilance.**

- `fluentform` occupe déjà le terrain des formulaires. Nos routes sont nommées
  `admin_post_urbizen_*` et ne peuvent pas entrer en collision, mais un contrôle de la page DP après
  déploiement doit s'assurer qu'aucune de ses ressources ne s'injecte dans le cadre.
- Les permissions `755` avec le compte SSH pour propriétaire permettent un `rsync` sans élévation.
  Aucune commande de ce document n'a besoin de `sudo`, et aucune ne doit en employer.

### Ce qui reste à relever

L'inventaire fichier par fichier du greffon déployé, pour compléter la comparaison du § 2.3 au-delà
des seuls fichiers publiquement lisibles du thème.

---

## 3 · Audit à exécuter en lecture seule

Aucune de ces commandes n'écrit.

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 "${SSH_USER}@${SSH_HOST}"
cd "${WP_ROOT}"

wp core version
php -v | head -1
wp theme list --format=table
wp option get hostinger_ai_version || echo "(option absente)"
wp plugin list --format=table
wp plugin list --status=active --field=name | grep -iE 'smtp|mail|cache|litespeed|securit|wordfence|firewall' || echo "(aucune extension de courriel, cache ou sécurité)"
wp post list --post_type=page --fields=ID,post_name,post_status --format=table
wp cron event list --format=table | head -20
wp cron test

# Emplacements, propriétaire, permissions.
ls -ld wp-content/themes/urbizen-child wp-content/themes/hostinger-ai-theme wp-content/plugins/urbizen-platform
find wp-content/themes/urbizen-child -type f | wc -l
find wp-content/plugins/urbizen-platform -type f | wc -l
df -h . | tail -1
```

**Ne jamais afficher** : `wp config list`, le contenu de `wp-config.php`, les clés SMTP, les secrets
WordPress, ni aucune donnée personnelle. `wp option get hostinger_ai_version` est nommément
autorisée ; aucune autre option ne doit être lue.

### Divergence complète des fichiers

À exécuter **depuis le poste local**, sans rien écrire sur le serveur :

```bash
# Inventaire distant, empreintes comprises.
ssh -i ~/.ssh/urbizen_hostinger -p 65002 "${SSH_USER}@${SSH_HOST}" \
  "cd ${WP_ROOT} && find wp-content/themes/urbizen-child wp-content/plugins/urbizen-platform \
     -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.html' -o -name '*.json' \) \
     -exec sha256sum {} +" | sort -k2 > /tmp/urbizen-prod.sha

# Le même inventaire, depuis la branche.
git archive HEAD wordpress/urbizen-child wordpress/urbizen-platform | tar -tf - >/dev/null   # contrôle
# puis comparaison manuelle, fichier par fichier, sur les chemins équivalents.
```

Trois familles doivent en sortir, **présentées avant toute proposition de déploiement** :
fichiers identiques · fichiers modifiés directement en production · fichiers présents en production
et absents du dépôt.

---

## 4 · Sauvegarde préalable — à exécuter avant tout envoi

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 "${SSH_USER}@${SSH_HOST}"
cd "${WP_ROOT}"

TS=$(date -u +%Y%m%d-%H%M%S)
DEST=~/backups/urbizen-dp-pc-$TS
mkdir -p "$DEST"          # HORS de la racine publique

# 1 · Base complète.
wp db export "$DEST/db.sql" --add-drop-table
gzip -9 "$DEST/db.sql"

# 2 · Thème enfant tel qu'il est déployé — SEULE copie de l'état divergent.
tar -czf "$DEST/urbizen-child.tar.gz" -C wp-content/themes urbizen-child

# 3 · Plugin tel qu'il est déployé.
tar -czf "$DEST/urbizen-platform.tar.gz" -C wp-content/plugins urbizen-platform

# 4 · Configuration : copie conservée sur le serveur, jamais rapatriée, jamais affichée.
cp -p wp-config.php "$DEST/wp-config.php.copie"
chmod 600 "$DEST/wp-config.php.copie"

# 5 · Inventaire des versions et empreintes.
{
  echo "date_utc=$(date -u +%FT%TZ)"
  echo "wp=$(wp core version)"
  echo "php=$(php -r 'echo PHP_VERSION;')"
  echo "theme=$(wp option get stylesheet) parent=$(wp option get template)"
  echo "plugin=$(wp plugin get urbizen-platform --field=version)"
  echo "--- empreintes ---"
  find wp-content/themes/urbizen-child wp-content/plugins/urbizen-platform -type f -exec sha256sum {} + | sort -k2
} > "$DEST/inventaire.txt"

# 6 · Intégrité — une archive qu'on ne sait pas relire n'est pas une sauvegarde.
gzip -t "$DEST/db.sql.gz" && echo "db.sql.gz OK"
tar -tzf "$DEST/urbizen-child.tar.gz"    >/dev/null && echo "urbizen-child.tar.gz OK"
tar -tzf "$DEST/urbizen-platform.tar.gz" >/dev/null && echo "urbizen-platform.tar.gz OK"
[ -s "$DEST/db.sql.gz" ] || { echo "ARRÊT : sauvegarde de base vide"; exit 1; }
sha256sum "$DEST"/*.gz > "$DEST/CHECKSUMS"
ls -lh "$DEST"
```

**Emplacement** : `~/backups/urbizen-dp-pc-<horodatage>/`, hors de `public_html`.

---

## 5 · Manifeste de déploiement

### Ce qui part

**Thème enfant** — `wordpress/urbizen-child/` :

| Chemin | Nature |
|---|---|
| `functions.php` | table des gabarits, configuration, jeton, origine, version de lot |
| `theme.json` | déjà identique en production — part quand même, par cohérence du dossier |
| `templates/page-formulaire-declaration-prealable.html` | cadre versionné |
| `templates/page-formulaire-permis-de-construire.html` | cadre versionné |
| `assets/forms/dp-formulaire.html` | document DP |
| `assets/forms/pc-formulaire.html` | document PC |
| `assets/js/urbizen-form-page.js` | pont côté parent |
| `assets/js/urbizen-form-bridge.js` | pont côté document — **nouveau** |
| `assets/js/urbizen-form-tarifs.js` | moteur tarifaire — **nouveau** |
| `assets/js/urbizen-form-pieces.js` | pièces et reports — **nouveau** |
| `assets/css/urbizen-form-page.css` | **nouveau** |
| `assets/css/urbizen-form-tarifs.css` | **nouveau** |
| `assets/css/urbizen-form-pieces.css` | **nouveau** |

**Plugin** — `wordpress/urbizen-platform/` : le dossier `src/` dans son ensemble, plus le fichier
principal du greffon. Les classes nouvelles concernées : `Forms/Catalogue*`, `Forms/Pricing*`,
`Forms/ValidationMetier*`, `Forms/ProjetsPricingStrategy.php`,
`Forms/definitions/{declaration_prealable,permis_construire}.php`, `Http/AcceptNegotiation.php`,
`Http/SubmissionJsonResponse.php`, `Mail/NotificationSlot.php`,
`Mail/CustomerAcknowledgement{Renderer,Strategy}.php`,
`Mail/{DeclarationPrealable,PermisConstruire}NotificationStrategy.php`.

### Ce qui ne part pas

`.git/` · `docs/` · `tests/` · `frontend/` (maquettes de travail) · `backend/` · `scripts/` ·
`node_modules/` · le `mu-plugin` d'échafaudage MAMP · le thème parent récupéré ·
les demandes de démonstration · tout cache ou fichier temporaire · `.env*` · les captures.

Le déploiement **ne copie pas le dépôt**. Deux dossiers applicatifs, et rien d'autre.

---

## 6 · Commandes rsync

Le sens est **toujours** local → production. Aucune commande de ce document ne remonte quoi que ce
soit vers le dépôt, et aucune ne comporte `--delete` : un premier déploiement qui supprime est un
déploiement qui ne se rattrape pas.

### Simulation

```bash
cd ~/Desktop/urbizen
RSH="ssh -i ~/.ssh/urbizen_hostinger -p 65002"

# Thème enfant.
rsync -avzn --checksum \
  --exclude='.DS_Store' --exclude='*.map' \
  -e "$RSH" \
  wordpress/urbizen-child/ \
  "${SSH_USER}@${SSH_HOST}:${WP_ROOT}/wp-content/themes/urbizen-child/"

# Plugin.
rsync -avzn --checksum \
  --exclude='.DS_Store' --exclude='tests/' --exclude='*.map' \
  -e "$RSH" \
  wordpress/urbizen-platform/ \
  "${SSH_USER}@${SSH_HOST}:${WP_ROOT}/wp-content/plugins/urbizen-platform/"
```

### Envoi réel

Les mêmes commandes, `-avzn` devenant `-avz`. Rien d'autre ne change.

### Ce qui est structurellement protégé

Le thème parent, `wp-config.php`, les téléversements, les extensions tierces et les fichiers
Hostinger **ne figurent dans aucun chemin de destination**. Ce n'est pas une exclusion qu'on pourrait
oublier : ils sont hors du périmètre des deux commandes. L'absence de `--delete` garantit en outre
qu'aucun fichier présent sur le serveur et absent en local ne disparaît.

### Après l'envoi

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 "${SSH_USER}@${SSH_HOST}" "cd ${WP_ROOT} && \
  find wp-content/plugins/urbizen-platform/src wp-content/themes/urbizen-child -name '*.php' -print0 \
  | xargs -0 -n1 php -l | grep -v 'No syntax errors' || echo 'syntaxe : aucune erreur'"

# Purge du cache de pages : sans elle, les pages en cache portent l'ancienne URL de cadre.
ssh -i ~/.ssh/urbizen_hostinger -p 65002 "${SSH_USER}@${SSH_HOST}" "cd ${WP_ROOT} && \
  (wp litespeed-purge all 2>/dev/null || wp cache flush)"
```

---

## 7 · Contrôle privé en production

Il n'existe pas de staging. Le contrôle se fait donc en production, à exposition minimale.

- **Plage de faible trafic.**
- Les pages DP et PC **ne sont ajoutées à aucun menu** pendant le contrôle. Elles existent déjà et
  sont publiées : les passer en privé le temps de l'essai est possible et préférable
  (`wp post update <ID> --post_status=private`), puis les republier.
- `<meta name="robots" content="noindex">` le temps du contrôle si les pages restent publiques.
- **Une demande DP, deux demandes PC** — une chiffrée, une sur étude. Pas davantage.
- Adresse de courriel **réellement relevable**, pour vérifier l'arrivée des deux messages.
- Vérifier : la notification interne, l'accusé client, **et les indésirables**.
- Vérifier en administration : référence, tarif persisté, statut, état des deux créneaux.
- Puis **supprimer les demandes de contrôle** par la Corbeille, qui annule les notifications
  restantes et nettoie les fichiers — c'est le chemin prévu, pas une suppression manuelle.

---

## 8 · Retour arrière

**La base n'est normalement pas concernée.** Aucune migration de schéma n'accompagne cette PR : le
code seul change. Restaurer la base ne serait nécessaire que dans un cas — une écriture massive et
manifestement fautive sur les demandes existantes — qui ne peut pas résulter de ce déploiement.
Restaurer la base ferait au contraire **disparaître les demandes reçues entre-temps**, y compris de
vrais clients.

```bash
ssh -i ~/.ssh/urbizen_hostinger -p 65002 "${SSH_USER}@${SSH_HOST}"
cd "${WP_ROOT}"
DEST=~/backups/urbizen-dp-pc-<horodatage>

# 1 · Contrôler l'archive avant de s'en servir.
sha256sum -c "$DEST/CHECKSUMS"

# 2 · Thème enfant : on écarte l'actuel, on remet l'archivé.
mv wp-content/themes/urbizen-child wp-content/themes/urbizen-child.rollback-$(date -u +%H%M%S)
tar -xzf "$DEST/urbizen-child.tar.gz" -C wp-content/themes

# 3 · Plugin, de même.
mv wp-content/plugins/urbizen-platform wp-content/plugins/urbizen-platform.rollback-$(date -u +%H%M%S)
tar -xzf "$DEST/urbizen-platform.tar.gz" -C wp-content/plugins

# 4 · Les documents statiques reviennent avec le thème : ils en font partie.

# 5 · Purger le cache de pages, et lui seul.
wp litespeed-purge all 2>/dev/null || wp cache flush

# 6 · Contrôles.
php -l wp-content/plugins/urbizen-platform/urbizen-platform.php
wp theme list --format=table
wp plugin list --status=active --format=table
curl -sI https://urbizen.fr/ | head -1
```

Les téléversements ne sont jamais touchés : ils ne figurent dans aucune commande. **Les demandes
créées entre-temps sont conservées** — la restauration ne porte que sur du code.

---

## 9 · Checklist après déploiement

| # | Contrôle | Arrêt si |
|---|---|---|
| 1 | Syntaxe PHP sur les deux dossiers | une seule erreur |
| 2 | Thème et plugin actifs | l'un des deux ne l'est plus |
| 3 | Aucune erreur fatale au journal PHP | une seule apparaît |
| 4 | L'accueil répond en 200 | autre code |
| 5 | Les pages DP et PC répondent en 200 | autre code |
| 6 | Le pont s'initialise, bouton déverrouillé | il reste désactivé |
| 7 | Une demande DP réelle | refus, ou absence de référence |
| 8 | Un PC chiffré réel — 1 059 € attendus | montant divergent |
| 9 | Un PC sur étude réel — `total = null` | un montant apparaît |
| 10 | Les deux courriels arrivent | l'un des deux manque après 15 min |
| 11 | Administration : référence, tarif, créneaux | incohérence |
| 12 | Mobile 390 px, aucun débordement | débordement |
| 13 | Journaux PHP propres | erreur répétée |
| 14 | `wp litespeed-purge all` exécuté, ancienne URL de cadre disparue | l'ancienne persiste |
| 15 | **Décision : maintien ou retour arrière** | — |

### Critères de succès

Les quinze lignes passent ; les trois demandes de contrôle portent les tarifs attendus ; les deux
messages arrivent pour chacune ; aucune erreur fatale ; l'accueil et les pages existantes sont
inchangés.

### Critères d'arrêt immédiat

Erreur fatale sur une page publique · accueil ou page publiée cassée · demande enregistrée sans
référence ou sans tarif · tarif divergent · courriel parti vers une adresse qui n'est pas celle du
demandeur · fichier déposé accessible sans lien signé · toute perte de données existante.

En cas d'arrêt : appliquer le § 8, puis **conserver** l'état fautif dans les dossiers
`.rollback-<heure>` pour diagnostic — ne pas les supprimer.
