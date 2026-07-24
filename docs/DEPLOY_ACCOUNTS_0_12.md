# Protocole de déploiement — comptes E2.2, `0.10.0 → 0.12.0`

> **Approuver ce document n'autorise ni connexion ni déploiement en
> production. L'exécution nécessitera une autorisation distincte.**

Ce document est **exécutable étape par étape**, mais il ne s'exécute pas de
lui-même. Rien ci-dessous ne doit être lancé tant qu'une autorisation explicite,
séparée de la revue de ce document, n'a pas été donnée. Chaque phase se termine
par un **arrêt de sécurité** : si un contrôle échoue, on n'enchaîne pas.

## État de départ (au moment de la rédaction)

| Élément | Valeur |
|---|---|
| Commit de fusion `main` | `16806fa9f87e492672ddfc9bbd0e3fee1ad76625` |
| Arbre Git du plugin 0.12.0 | `2230fbb9fde3f6c25e367c60a7b0c800199304a4` |
| Arbre Git du plugin 0.10.0 (à `6bad17d`) | `01cd84ae5c18c8d9a7b9703a662fa7eb0aa7e00a` |
| Production, dernière constatée | `0.10.0` — **non revérifiée** |

Variables d'environnement de l'opérateur (jamais versionnées, voir
[AI_CONTEXT.md](AI_CONTEXT.md)) : `SSH_USER`, `SSH_HOST`, `SSH_PORT`, `WP_ROOT`,
`URBIZEN_STORAGE_ROOT`, `URBIZEN_PHP_WEB_LOG` (journal PHP réel du site, servi par
PHP-FPM — voir phase 1).

**Ne jamais afficher** `wp-config.php`, ni aucun identifiant, hôte, jeton, mot de
passe ou donnée personnelle dans un rapport d'exécution.

## Ce que ce lot fait — et ne fait pas

**Modifié :**

- le répertoire `wp-content/plugins/urbizen-platform/` (fichiers du plugin) ;
- **l'option WordPress des rôles** (`{prefixe}user_roles`) : `wp urbizen accounts
  install` y ajoute le rôle `urbizen_client`. Ce lot **écrit donc aussi en base**,
  dans cette seule option — aucune table n'est créée.

**Non touché :** aucune page publiée, aucune page créée, le thème enfant et
parent, toute autre extension, aucune autre option. **Aucune page de comptes
n'est créée ni publiée dans ce lot.**

> **Une fois 0.12.0 active, les cinq actions `admin-post` existent
> techniquement, même sans page publique** — soit **huit hooks** (phase 6).

---

## Deux shells réellement séparés

- **`# (local)`** — la machine de l'opérateur (le clone Git). Elle ne voit
  **jamais** un chemin serveur comme un chemin local ; ses échanges passent par la
  fonction `SSH` et les helpers logiques.
- **`# (serveur)`** — une session SSH. Son état persiste dans un **fichier d'état
  non secret** (`state.env`, valeurs échappées par `printf %q`) et des scripts
  d'aide sous `~/.urbz-deploy/`. L'état et le mainteneur de maintenance survivent
  à la chute de la session.

Chaque bloc charge son environnement commun **en mode strict** :

- serveur : `source ~/.urbz-deploy/common.sh` — active `set -euo pipefail`, charge
  l'état, se place dans `$WP_ROOT`, et expose `WP` = `wp --path="$WP_ROOT"` ;
- local : `source ~/.urbz-deploy-local/common.sh` — active `set -euo pipefail`.

**Toute commande WordPress passe par `WP`**, qui cible explicitement l'installation.

---

## Phase 0 — Préparation des deux shells

### 0a — (serveur) état persistant, emplacement hors racine web, helpers

```bash
# (serveur)
set -euo pipefail
: "${WP_ROOT:?WP_ROOT manquant}"
: "${URBIZEN_PHP_WEB_LOG:?URBIZEN_PHP_WEB_LOG manquant (journal PHP-FPM du site)}"
command -v wp >/dev/null || { echo "ARRÊT : wp introuvable"; exit 1; }
command -v setsid >/dev/null && command -v nohup >/dev/null \
  || { echo "ARRÊT : setsid et nohup requis pour la maintenance surveillée"; exit 1; }

# Se placer dans WP_ROOT AVANT le premier appel wp, et le canoniser en absolu.
cd -- "$WP_ROOT" || { echo "ARRÊT : WP_ROOT inaccessible"; exit 1; }
WP_ROOT="$(pwd -P)"
WP() { wp --path="$WP_ROOT" "$@"; }

R_ACTIVE="$WP_ROOT/wp-content/plugins/urbizen-platform"
PLUGDIR_REAL="$(realpath "$(dirname "$R_ACTIVE")")"
WEBROOT_REAL="$WP_ROOT"

# Emplacement HORS racine web, sur le MÊME volume, CANONIQUE et sans lien
# symbolique. On résout le chemin final, pas seulement son parent.
R_BASE=""
for cand in "${URBIZEN_STORAGE_ROOT:-}" "$HOME/urbz-deploy-store"; do
  [ -n "$cand" ] || continue
  [ -L "$cand" ] && continue                                  # candidat = lien : refus
  if [ -e "$cand" ]; then
    canon="$(realpath "$cand")"
  else
    parent="$(dirname "$cand")"; [ -d "$parent" ] || continue
    [ -L "$parent" ] && continue
    canon="$(realpath "$parent")/$(basename "$cand")"
  fi
  case "$canon/" in "$WEBROOT_REAL"/*) continue ;; esac        # hors racine web (canonique)
  case "$canon/" in "$PLUGDIR_REAL"/*) continue ;; esac        # hors wp-content/plugins
  probe="$canon"; while [ ! -e "$probe" ]; do probe="$(dirname "$probe")"; done
  [ "$(stat -c '%d' "$probe")" = "$(stat -c '%d' "$PLUGDIR_REAL")" ] || continue  # même volume
  R_BASE="$canon"; break                                       # SEUL le chemin canonique validé
done
[ -n "$R_BASE" ] || { echo "ARRÊT : aucun emplacement hors racine web, même volume que le plugin"; exit 1; }

TS="$(date +%Y%m%d-%H%M%S)"
R_STORE="$R_BASE/deploy-012-$TS/store"
R_STAGE="$R_BASE/deploy-012-$TS/stage"
R_PREP="$R_STAGE/urbizen-platform.new"
R_ROLLBACK="$R_STAGE/urbizen-platform.rollback-$TS"

# Valider l'ABSOLUITÉ de tous les chemins AVANT la première création de répertoire.
for p in "$R_ACTIVE" "$R_BASE" "$R_STORE" "$R_STAGE" "$R_PREP" "$R_ROLLBACK"; do
  case "$p" in /*) : ;; *) echo "ARRÊT : chemin non absolu : $p"; exit 1 ;; esac
done
( umask 077; mkdir -p "$R_STORE" "$R_STAGE" )

[ -f "$URBIZEN_PHP_WEB_LOG" ] && [ -r "$URBIZEN_PHP_WEB_LOG" ] \
  || { echo "ARRÊT : journal PHP web illisible : $URBIZEN_PHP_WEB_LOG"; exit 1; }
PHPLOG="$(realpath "$URBIZEN_PHP_WEB_LOG")"

PREFIX="$(WP db prefix)"                  # préfixe RÉEL, pas « wp_ » codé en dur
SITE="$(WP option get home)"

# Fichier d'état NON SECRET, chaque valeur échappée par printf %q (jamais
# d'injection directe d'URL ou de chemin dans un fichier ensuite « source »).
{
  printf 'TS=%q\n'         "$TS"
  printf 'WP_ROOT=%q\n'    "$WP_ROOT"
  printf 'R_ACTIVE=%q\n'   "$R_ACTIVE"
  printf 'R_BASE=%q\n'     "$R_BASE"
  printf 'R_STORE=%q\n'    "$R_STORE"
  printf 'R_STAGE=%q\n'    "$R_STAGE"
  printf 'R_PREP=%q\n'     "$R_PREP"
  printf 'R_ROLLBACK=%q\n' "$R_ROLLBACK"
  printf 'PREFIX=%q\n'     "$PREFIX"
  printf 'SITE=%q\n'       "$SITE"
  printf 'PHPLOG=%q\n'     "$PHPLOG"
} > "$R_STORE/state.env"

mkdir -p "$HOME/.urbz-deploy"
ln -sfn "$R_STORE" "$HOME/.urbz-deploy/current"

# Environnement commun serveur : mode strict, état, WP_ROOT, wrapper WP, outils.
cat > "$HOME/.urbz-deploy/common.sh" <<'COMMON'
set -euo pipefail
. "$HOME/.urbz-deploy/current/state.env"
cd -- "$WP_ROOT"
WP() { wp --path="$WP_ROOT" "$@"; }
if command -v sha256sum >/dev/null 2>&1; then HASHCMD="sha256sum"; else HASHCMD="shasum -a 256"; fi
manifeste() {
  [ -d "${1:-}" ] || { echo "ARRÊT : répertoire absent : ${1:-}" >&2; return 1; }
  ( cd -- "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 $HASHCMD )
}
COMMON

# Transferts (le local n'appelle que des noms logiques).
cat > "$HOME/.urbz-deploy/get" <<'GET'
#!/bin/bash
set -euo pipefail
. "$HOME/.urbz-deploy/current/state.env"
f="$R_STORE/$1"; [ -f "$f" ] || { echo "get : absent : $1" >&2; exit 1; }
cat -- "$f"
GET
cat > "$HOME/.urbz-deploy/put" <<'PUT'
#!/bin/bash
set -euo pipefail
. "$HOME/.urbz-deploy/current/state.env"
cat > "$R_STORE/$1"
PUT
# Artefact : R_PREP doit être absent ou vide ; sinon n'effacer QUE le chemin
# canonique exact attendu sous R_STAGE, jamais un rm -rf aveugle.
cat > "$HOME/.urbz-deploy/put-artefact" <<'PUTA'
#!/bin/bash
set -euo pipefail
. "$HOME/.urbz-deploy/current/state.env"
if [ -e "$R_PREP" ]; then
  canon="$(realpath -- "$R_PREP")"
  stage="$(realpath -- "$R_STAGE")"
  [ "$canon" = "$stage/urbizen-platform.new" ] \
    || { echo "put-artefact : R_PREP ($canon) ≠ chemin canonique attendu, refus" >&2; exit 1; }
  rm -rf -- "$canon"                 # sûr : chemin canonique exact sous R_STAGE vérifié
fi
mkdir -p "$R_PREP"
tar -C "$R_PREP" -xf -
PUTA

# Mainteneur : rafraîchit .maintenance par écriture ATOMIQUE (fichier temporaire
# du même répertoire puis renommage) toutes les 30 s ⇒ jamais âgé de 10 min.
cat > "$HOME/.urbz-deploy/keep-maintenance.sh" <<'KEEP'
#!/bin/bash
set -u
WP_ROOT="$1"
while [ -f "$WP_ROOT/.maintenance.keep" ]; do
  tmp="$WP_ROOT/.maintenance.tmp.$$"
  printf '<?php $upgrading = %s; ?>\n' "$(date +%s)" > "$tmp"
  mv -f "$tmp" "$WP_ROOT/.maintenance"
  sleep 30
done
KEEP

# running : le PID enregistré est-il VRAIMENT notre mainteneur (pas un PID recyclé) ?
cat > "$HOME/.urbz-deploy/maint-on" <<'MON'
#!/bin/bash
set -euo pipefail
. "$HOME/.urbz-deploy/current/state.env"
running() {
  local pid; pid="$(cat "$R_STORE/keep.pid" 2>/dev/null || true)"
  [ -n "${pid:-}" ] && kill -0 "$pid" 2>/dev/null || return 1
  if [ -r "/proc/$pid/cmdline" ]; then
    tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'keep-maintenance.sh' || return 1
  fi
  return 0
}
: > "$WP_ROOT/.maintenance.keep"
tmp="$WP_ROOT/.maintenance.tmp.$$"
printf '<?php $upgrading = %s; ?>\n' "$(date +%s)" > "$tmp"; mv -f "$tmp" "$WP_ROOT/.maintenance"
if ! running; then
  setsid nohup "$HOME/.urbz-deploy/keep-maintenance.sh" "$WP_ROOT" >"$R_STORE/keep.log" 2>&1 &
  echo $! > "$R_STORE/keep.pid"
fi
echo "maintenance ARMÉE (pid $(cat "$R_STORE/keep.pid"))"
MON

cat > "$HOME/.urbz-deploy/maint-off" <<'MOFF'
#!/bin/bash
set -euo pipefail
. "$HOME/.urbz-deploy/current/state.env"
rm -f "$WP_ROOT/.maintenance.keep"
if [ -f "$R_STORE/keep.pid" ]; then
  pid="$(cat "$R_STORE/keep.pid")"
  for _ in $(seq 1 40); do kill -0 "$pid" 2>/dev/null || break; sleep 1; done
  rm -f "$R_STORE/keep.pid"                # retirer le PID périmé
fi
rm -f "$WP_ROOT/.maintenance"
echo "maintenance LEVÉE"
MOFF

# maint-panic : utilisable même si state.env ne peut plus être chargé. WP_ROOT
# vient de l'argument ou de l'environnement.
cat > "$HOME/.urbz-deploy/maint-panic" <<'PANIC'
#!/bin/bash
set -u
WP="${1:-${WP_ROOT:-}}"
[ -n "$WP" ] || { echo "usage : maint-panic <WP_ROOT>"; exit 1; }
rm -f "$WP/.maintenance.keep" "$WP/.maintenance"
. "$HOME/.urbz-deploy/current/state.env" 2>/dev/null || true
[ -n "${R_STORE:-}" ] && [ -f "$R_STORE/keep.pid" ] && kill "$(cat "$R_STORE/keep.pid")" 2>/dev/null || true
echo "maintenance forcée à zéro pour $WP"
PANIC

chmod +x "$HOME/.urbz-deploy/"get "$HOME/.urbz-deploy/"put "$HOME/.urbz-deploy/"put-artefact \
  "$HOME/.urbz-deploy/"keep-maintenance.sh "$HOME/.urbz-deploy/"maint-on \
  "$HOME/.urbz-deploy/"maint-off "$HOME/.urbz-deploy/"maint-panic

printf '%s\n' "$SITE" > "$R_STORE/site.txt"     # valeur publique, pas un secret
echo "phase 0a OK : pwd=$(pwd -P) ; R_STORE=$R_STORE"
```

### 0b — (local) répertoire d'exécution **unique**, helpers

```bash
# (local)
set -euo pipefail
: "${SSH_USER:?}" "${SSH_HOST:?}" "${SSH_PORT:?}"

# Refuser de réutiliser les références d'un déploiement précédent/interrompu.
[ -e "$HOME/.urbz-deploy-local/state.env" ] \
  && { echo "ARRÊT : état local existant — nettoyer ~/.urbz-deploy-local avant de recommencer"; exit 1; }

mkdir -p "$HOME/.urbz-deploy-local"
L_DIR="$(mktemp -d "$HOME/.urbz-deploy-local/run.XXXXXX")"   # UNIQUE par déploiement
L_REF="$L_DIR/ref"
mkdir -p "$L_REF"

{
  printf 'L_DIR=%q\n' "$L_DIR"
  printf 'L_REF=%q\n' "$L_REF"
} > "$HOME/.urbz-deploy-local/state.env"

cat > "$HOME/.urbz-deploy-local/common.sh" <<'COMMON'
set -euo pipefail
. "$HOME/.urbz-deploy-local/state.env"
: "${SSH_USER:?}" "${SSH_HOST:?}" "${SSH_PORT:?}"
SSH() { ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "$@"; }
if command -v sha256sum >/dev/null 2>&1; then HASHCMD="sha256sum"; else HASHCMD="shasum -a 256"; fi
manifeste() {
  [ -d "${1:-}" ] || { echo "ARRÊT : répertoire absent : ${1:-}" >&2; return 1; }
  ( cd -- "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 $HASHCMD )
}
refname() { printf '%s' "$1" | $HASHCMD | cut -c1-32; }   # nom sans collision
# normaliser : neutralise les nonces (partout) et le paramètre ver= UNIQUEMENT
# pour les ressources du plugin Urbizen. Tout autre ver= reste comparé.
normaliser() {
  sed -E \
    -e 's/(name="_w[a-z_]*nonce"[^>]*value=")[A-Za-z0-9]+"/\1NONCE"/g' \
    -e 's#(wp-content/plugins/urbizen-platform/[^?" ]*\?ver=)[0-9A-Za-z._-]+#\1NORM#g'
}
# res_norm : idem, appliqué à une liste de ressources (une URL par ligne).
res_norm() { sed -E 's#(wp-content/plugins/urbizen-platform/[^?" ]*\?ver=)[0-9A-Za-z._-]+#\1NORM#g'; }
# echec_public : gestionnaire COMMUN d'échec après réouverture. Il ré-arme la
# maintenance, confirme le 503 externe, puis arrête et dirige vers la phase 8.
echec_public() {
  echo "ÉCHEC PUBLIC : $1" >&2
  SSH '~/.urbz-deploy/maint-on' >&2 || true
  c=""
  for _ in 1 2 3 4 5; do
    c="$(curl -s -o /dev/null -w '%{http_code}' -- "$SITE/")"
    [ "$c" = 503 ] && break
    sleep 2
  done
  [ "$c" = 503 ] && echo "maintenance ré-armée (503 confirmé) — passer à la phase 8" >&2 \
                 || echo "ALERTE : 503 non confirmé ($c) — intervention manuelle, phase 8" >&2
  exit 1
}
COMMON

source "$HOME/.urbz-deploy-local/common.sh"
SITE="$(SSH '~/.urbz-deploy/get site.txt')"
printf 'SITE=%q\n' "$SITE" >> "$HOME/.urbz-deploy-local/state.env"
echo "phase 0b OK : L_REF=$L_REF ; SITE=$SITE"
```

---

## Phase 1 — Précontrôle en lecture seule (attentes **bloquantes**)

### 1a — (serveur)

```bash
# (serveur)
source ~/.urbz-deploy/common.sh

WP plugin is-active urbizen-platform || { echo "ARRÊT : extension inactive"; exit 1; }
[ "$(WP plugin get urbizen-platform --field=version)" = "0.10.0" ] \
  || { echo "ARRÊT : version installée ≠ 0.10.0"; exit 1; }
[ "$(WP core version)" = "7.0.2" ] || { echo "ARRÊT : WordPress ≠ 7.0.2"; exit 1; }
case "$(php -r 'echo PHP_VERSION;')" in 8.3.*) : ;; *) echo "ARRÊT : PHP ≠ 8.3.x"; exit 1 ;; esac

# Rôle : ATTENDU ABSENT. Enregistré ET bloquant s'il est présent — ainsi la
# phase 8 ne supprimera jamais un rôle préexistant.
ROLE="$(WP eval 'echo get_role("urbizen_client") ? "present" : "absent";')"
printf '%s\n' "$ROLE" > "$R_STORE/role-avant.txt"
[ "$ROLE" = "absent" ] || { echo "ARRÊT : rôle urbizen_client déjà présent — décision humaine requise"; exit 1; }

# Pages publiées à shortcode de comptes : ATTENDU 0 (comptage seul).
PAGES_SC="$(WP eval '
  $q = new WP_Query(array("post_type"=>"page","post_status"=>"publish","posts_per_page"=>-1,"fields"=>"ids"));
  $n = 0;
  foreach ($q->posts as $id) {
    $c = get_post_field("post_content", $id);
    if (has_shortcode($c,"urbizen_inscription") || has_shortcode($c,"urbizen_renvoi") || has_shortcode($c,"urbizen_changer_adresse")) { $n++; }
  }
  echo (int) $n;
')"
printf '%s\n' "$PAGES_SC" > "$R_STORE/pages-shortcodes-avant.txt"
[ "$PAGES_SC" -eq 0 ] || { echo "ARRÊT : $PAGES_SC page(s) portent déjà un shortcode de comptes"; exit 1; }

manifeste "$R_ACTIVE" > "$R_STORE/plugin-manifeste-avant.txt"
WP db tables --all-tables | LC_ALL=C sort > "$R_STORE/tables-avant.txt"
{ printf '%s\n' "$SITE"; WP post list --post_type=page --post_status=publish --field=url; } \
  > "$R_STORE/urls-avant.txt"
stat -c '%i %s' -- "$PHPLOG" > "$R_STORE/phplog-ref.txt"
echo "phase 1a OK"
```

### 1b — (local)

```bash
# (local)
source ~/.urbz-deploy-local/common.sh

git rev-parse '6bad17d:wordpress/urbizen-platform' | grep -qx '01cd84ae5c18c8d9a7b9703a662fa7eb0aa7e00a' \
  || { echo "ARRÊT : arbre 0.10.0 inattendu"; exit 1; }
REF010="$(mktemp -d "${TMPDIR:-/tmp}/urbz-010.XXXXXX")"
git archive '6bad17d:wordpress/urbizen-platform' | tar -x -C "$REF010"
manifeste "$REF010" | SSH '~/.urbz-deploy/put plugin-manifeste-attendu-010.txt'

# Le serveur compare l'attendu au manifeste installé (le local ne lit aucun chemin serveur).
SSH 'set -euo pipefail; . ~/.urbz-deploy/current/state.env
     diff -- "$R_STORE/plugin-manifeste-attendu-010.txt" "$R_STORE/plugin-manifeste-avant.txt"' \
  && echo "plugin installé == arbre 0.10.0 attendu" \
  || { echo "ARRÊT : dérive inconnue du plugin installé"; exit 1; }

# Références PUBLIQUES capturées depuis le SEUL point d'observation (le local).
SSH '~/.urbz-deploy/get urls-avant.txt' > "$L_REF/urls.txt"
while IFS= read -r u; do
  [ -n "$u" ] || continue
  name="$(refname "$u")"
  curl -fsS -- "$u" > "$L_REF/$name.avant.html"
  printf '%s\n' "$u" > "$L_REF/$name.url"
  { grep -oE '(src|href)="[^"]+"' "$L_REF/$name.avant.html" || true; } | LC_ALL=C sort -u \
    > "$L_REF/$name.res.avant.txt"
done < "$L_REF/urls.txt"
echo "phase 1b OK"
```

**Arrêts (bloquants) :** extension inactive · version ≠ 0.10.0 · WP ≠ 7.0.2 · PHP ≠
8.3.x · rôle présent · page à shortcode · dérive du plugin.

---

## Phase 2 — Sauvegardes

Le mot de passe n'apparaît **jamais** dans `argv`. Le gestionnaire de signaux
**nettoie puis termine** (jamais de reprise).

```bash
# (serveur)
source ~/.urbz-deploy/common.sh
umask 077
CNF="$(mktemp "$R_STORE/my.XXXXXX.cnf")"
trap 'rm -f -- "$CNF"' EXIT
trap 'rm -f -- "$CNF"; trap - EXIT; exit 129' HUP
trap 'rm -f -- "$CNF"; trap - EXIT; exit 130' INT
trap 'rm -f -- "$CNF"; trap - EXIT; exit 143' TERM

WP eval '
  function urbz_myq($s){ return str_replace(array("\\","\""), array("\\\\","\\\""), $s); }
  printf("[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\n",
    urbz_myq(DB_USER), urbz_myq(DB_PASSWORD), urbz_myq(DB_HOST));
' > "$CNF"
chmod 600 -- "$CNF"

DB_NAME="$(WP eval 'echo DB_NAME;')"
DUMP="$R_STORE/urbizen-db-$TS.sql.gz"
ARCH="$R_STORE/urbizen-plugin-$TS.tar.gz"

# (a) Base. set -o pipefail garantit qu'un mysqldump en échec fait échouer le pipe.
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --no-tablespaces \
  -- "$DB_NAME" | gzip > "$DUMP"

# (b) Archive du plugin INSTALLÉ.
tar -C "$(dirname -- "$R_ACTIVE")" -czf "$ARCH" -- "$(basename -- "$R_ACTIVE")"

# (c) Vérifications. Le dump .sql.gz n'est pas un tar : jamais de tar -tzf dessus.
: > "$R_STORE/sauvegardes.sha256"
for f in "$DUMP" "$ARCH"; do
  [ -s "$f" ] || { echo "ARRÊT : sauvegarde vide : $f"; exit 1; }
  gzip -t -- "$f" || { echo "ARRÊT : gzip -t en échec : $f"; exit 1; }
  $HASHCMD "$f" >> "$R_STORE/sauvegardes.sha256"
done
( cd "$R_STORE" && $HASHCMD -c sauvegardes.sha256 ) \
  || { echo "ARRÊT : empreinte de sauvegarde non revérifiée"; exit 1; }
tar -tzf "$ARCH" > "$R_STORE/urbizen-plugin-$TS.inventaire.txt" \
  || { echo "ARRÊT : inventaire tar illisible"; exit 1; }

rm -f -- "$CNF"; trap - EXIT HUP INT TERM
echo "phase 2 OK"
```

---

## Phase 3 — Construction de l'artefact exact

```bash
# (local)
source ~/.urbz-deploy-local/common.sh
git rev-parse '16806fa9f87e492672ddfc9bbd0e3fee1ad76625:wordpress/urbizen-platform' \
  | grep -qx '2230fbb9fde3f6c25e367c60a7b0c800199304a4' \
  || { echo "ARRÊT : arbre du plugin ≠ 2230fbb9…"; exit 1; }
L_ART="$(mktemp -d "${TMPDIR:-/tmp}/urbz-art.XXXXXX")"
git archive '16806fa9f87e492672ddfc9bbd0e3fee1ad76625:wordpress/urbizen-platform' | tar -x -C "$L_ART"
manifeste "$L_ART" > "$L_REF/artefact.manifeste.local.txt"
tar -C "$L_ART" -cf - . | SSH '~/.urbz-deploy/put-artefact'
SSH '~/.urbz-deploy/put artefact.manifeste.local.txt' < "$L_REF/artefact.manifeste.local.txt"
echo "artefact transféré"
```

```bash
# (serveur)
source ~/.urbz-deploy/common.sh
for p in "$R_ACTIVE" "$R_STAGE" "$R_PREP"; do
  case "$p" in /*) : ;; *) echo "ARRÊT : chemin non absolu : $p"; exit 1 ;; esac
done
manifeste "$R_PREP" > "$R_STORE/artefact.manifeste.serveur.txt"
diff -- "$R_STORE/artefact.manifeste.local.txt" "$R_STORE/artefact.manifeste.serveur.txt" \
  && echo "manifeste identique après transfert" \
  || { echo "ARRÊT : divergence de manifeste après transfert"; exit 1; }
if find "$R_PREP" -type f -name '*.php' -print0 | LC_ALL=C sort -z \
     | xargs -0 -n1 php -l > "$R_STORE/lint-php.out" 2>&1; then
  echo "lint PHP OK : $(grep -c 'No syntax errors' "$R_STORE/lint-php.out") fichiers"
else
  echo "ARRÊT : erreur de syntaxe PHP —"; grep -v 'No syntax errors detected' "$R_STORE/lint-php.out"; exit 1
fi
```

**Jamais** de `rsync --delete` dans le plugin actif : basculement par renommage
(phase 4).

---

## Phase 4 — Remplacement protégé (maintenance surveillée)

Chaque **renommage est atomique** ; la **paire** ne l'est pas : la **maintenance
protège l'intervalle**, rafraîchie toutes les 30 s et survivant à la chute SSH.

```bash
# (serveur) contrôle du VOLUME AVANT toute maintenance (un échec ici ne doit
# jamais laisser le site sous maintenance) ; puis armer et VÉRIFIER le mainteneur.
source ~/.urbz-deploy/common.sh
DIRPLUG="$(dirname -- "$R_ACTIVE")"
[ "$(stat -c '%d' -- "$DIRPLUG")" = "$(stat -c '%d' -- "$R_STAGE")" ] \
  || { echo "ARRÊT : $R_STAGE n'est pas sur le même volume que $DIRPLUG (aucune maintenance armée)"; exit 1; }

~/.urbz-deploy/maint-on
# Le mainteneur détaché est-il RÉELLEMENT vivant et identifié ? Sinon on lève tout
# de suite (rien n'est encore modifié).
pid="$(cat "$R_STORE/keep.pid" 2>/dev/null || true)"
{ [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; } \
  || { echo "ARRÊT : mainteneur introuvable après maint-on"; ~/.urbz-deploy/maint-off; exit 1; }
if [ -r "/proc/$pid/cmdline" ]; then
  tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'keep-maintenance.sh' \
    || { echo "ARRÊT : PID $pid n'est pas le mainteneur attendu"; ~/.urbz-deploy/maint-off; exit 1; }
fi
echo "mainteneur vivant et identifié (pid $pid)"
WP litespeed-purge all 2>/dev/null || WP cache flush
```

```bash
# (local) VÉRIFIER le 503 externe AVANT tout renommage. S'il n'est pas confirmé,
# lever immédiatement, contrôler le retour en 200, et arrêter sans déploiement.
source ~/.urbz-deploy-local/common.sh
code="$(curl -s -o /dev/null -w '%{http_code}' -- "$SITE/")"
if [ "$code" != 503 ]; then
  echo "ARRÊT : 503 non confirmé (HTTP $code) — levée immédiate, aucun renommage"
  SSH '~/.urbz-deploy/maint-off'
  back="$(curl -s -o /dev/null -w '%{http_code}' -- "$SITE/")"
  [ "$back" = 200 ] && echo "accueil revenu en HTTP 200" \
                    || echo "ALERTE : accueil en HTTP $back après levée — intervention manuelle"
  exit 1
fi
echo "maintenance visible (503)"
```

```bash
# (serveur) basculer par renommages atomiques, avec TROIS états explicites.
source ~/.urbz-deploy/common.sh

# Ne lever la maintenance QUE si 0.10.0 est réellement active : R_ACTIVE existe,
# son manifeste == 0.10.0 attendu, et sa version == 0.10.0. Sinon : rester en
# maintenance et arrêter pour intervention.
lever_si_010() {
  if [ -d "$R_ACTIVE" ] \
     && manifeste "$R_ACTIVE" | diff -q - "$R_STORE/plugin-manifeste-attendu-010.txt" >/dev/null 2>&1 \
     && [ "$(WP plugin get urbizen-platform --field=version 2>/dev/null)" = "0.10.0" ]; then
    ~/.urbz-deploy/maint-off
    echo "0.10.0 confirmée active — maintenance levée"
    return 0
  fi
  echo "ARRÊT : 0.10.0 non confirmée active — MAINTENANCE MAINTENUE, intervention requise"
  return 1
}

# ── État 1 : avant le premier renommage — aucun fichier modifié, maintenance levable.
[ -d "$R_ACTIVE" ] && [ -d "$R_PREP" ] \
  || { echo "ARRÊT : R_ACTIVE ou R_PREP manquant avant bascule"; lever_si_010 || true; exit 1; }

if ! mv -- "$R_ACTIVE" "$R_ROLLBACK"; then     # renommage 1 : 0.10.0 sort de /plugins
  echo "ARRÊT : renommage 1 refusé — aucun fichier déplacé"
  lever_si_010 || true                          # R_ACTIVE intact = 0.10.0 → levée sûre
  exit 1
fi

# ── État 2 : ancien plugin déplacé, nouveau NON installé.
if ! mv -- "$R_PREP" "$R_ACTIVE"; then          # renommage 2 : 0.12.0 prend la place
  echo "ARRÊT : renommage 2 refusé — restauration immédiate de 0.10.0"
  [ -e "$R_ACTIVE" ] && mv -- "$R_ACTIVE" "$R_STAGE/urbizen-platform.partiel-$TS"
  mv -- "$R_ROLLBACK" "$R_ACTIVE" \
    || { echo "ARRÊT GRAVE : restauration impossible — MAINTENANCE MAINTENUE"; exit 1; }
  lever_si_010 || true                          # vérifie manifeste + version 0.10.0
  exit 1
fi

# ── État 3 : nouveau plugin installé — poursuivre phases 5 et 6 (sous maintenance).
echo "basculement effectué — 0.12.0 installée, poursuivre phases 5 et 6"
```

**Urgence** (WordPress ne démarre plus) : rétablir l'arbre, puis lever la
maintenance sans dépendre de `state.env` :

```bash
# (serveur) URGENCE
source ~/.urbz-deploy/common.sh
[ -d "$R_ACTIVE" ] || mv -- "$R_ROLLBACK" "$R_ACTIVE"
~/.urbz-deploy/maint-panic "$WP_ROOT"
```

**Arrêts (états explicites) :** volume différent → **avant** `maint-on`, rien
d'armé ; 503 non confirmé → **levée immédiate**, retour en 200, aucun renommage ;
renommage 1 refusé → 0.10.0 intacte, maintenance levée ; renommage 2 refusé →
0.10.0 **restaurée et vérifiée** avant toute levée ; 0.10.0 non confirmée active →
**maintenance maintenue** pour intervention.

---

## Phase 5 — Installation du rôle (sous maintenance, avant réouverture)

```bash
# (serveur)
source ~/.urbz-deploy/common.sh
[ "$(WP plugin get urbizen-platform --field=version)" = "0.12.0" ] \
  || { echo "ARRÊT : version active ≠ 0.12.0"; exit 1; }

WP urbizen accounts status
WP urbizen accounts install                          # SEUL point d'entrée du rôle
WP urbizen accounts verify || { echo "ARRÊT : verify en échec"; exit 1; }

# quota-verify : code non nul ⇒ ARRÊT IMMÉDIAT sous maintenance. Sortie capturée
# hors racine web, seuls les agrégats reportés (jamais les « compte <ID> »).
QV="$R_STORE/quota-verify-$TS.out"
if ! ( umask 077; WP urbizen accounts quota-verify > "$QV" 2>&1 ); then
  grep -E '^(comptes examinés|miroirs divergents|sources illisibles)' "$QV" || true
  echo "ARRÊT : quota-verify signale une divergence (détail hors racine web, non recopié)"; exit 1
fi
grep -E '^(comptes examinés|miroirs divergents|sources illisibles)' "$QV" || true
echo "phase 5 OK"
```

`--repair-mirror` reste **interdit** dans ce lot.

---

## Phase 6 — Contrôles sous maintenance

```bash
# (serveur)
source ~/.urbz-deploy/common.sh
WP plugin is-active urbizen-platform || { echo "ARRÊT : extension inactive"; exit 1; }
[ "$(WP plugin get urbizen-platform --field=version)" = "0.12.0" ] || { echo "ARRÊT : version"; exit 1; }
grep -q "URBIZEN_PLATFORM_VERSION *= *'0.12.0'" "$R_ACTIVE/urbizen-platform.php" || { echo "ARRÊT : constante"; exit 1; }
for b in cadastre formulaire; do
  grep -q '"version": *"0.12.0"' "$R_ACTIVE/blocks/$b/block.json" || { echo "ARRÊT : block.json $b"; exit 1; }
done

# CINQ actions ⇒ HUIT hooks : trois anonyme+connecté, deux connecté seul.
WP eval '
  $attendus = array(
    "admin_post_nopriv_urbizen_inscription","admin_post_urbizen_inscription",
    "admin_post_nopriv_urbizen_resultat","admin_post_urbizen_resultat",
    "admin_post_nopriv_urbizen_verification","admin_post_urbizen_verification",
    "admin_post_urbizen_changer_adresse",
    "admin_post_urbizen_renvoi_connecte",
  );
  $interdits = array(
    "admin_post_nopriv_urbizen_changer_adresse",
    "admin_post_nopriv_urbizen_renvoi_connecte",
  );
  $manque   = array_values(array_filter($attendus,  fn($h)=> false === has_action($h)));
  $presents = array_values(array_filter($interdits, fn($h)=> false !== has_action($h)));
  printf("hooks attendus présents : %d/8\n", count($attendus) - count($manque));
  printf("hooks nopriv interdits présents : %d (attendu 0)\n", count($presents));
  if ($manque || $presents) { fwrite(STDERR, "ARRET : périmètre des hooks incorrect\n"); exit(1); }
' || exit 1

WP eval '
  foreach (array("urbizen_inscription","urbizen_renvoi","urbizen_changer_adresse") as $s) {
    if (! shortcode_exists($s)) { fwrite(STDERR, "ARRET : shortcode manquant $s\n"); exit(1); }
  }
  echo "3 shortcodes présents\n";
' || exit 1

WP eval '
  $r = get_role("urbizen_client");
  if (! $r) { fwrite(STDERR, "ARRET : rôle absent\n"); exit(1); }
  $caps = array_keys(array_filter($r->capabilities));
  sort($caps);
  if ($caps !== array("read")) { fwrite(STDERR, "ARRET : capacités ".implode(",",$caps)."\n"); exit(1); }
  echo "rôle urbizen_client : capacité read seule\n";
' || exit 1

# Aucune table CRÉÉE ; préfixe RÉEL.
WP db tables --all-tables | LC_ALL=C sort > "$R_STORE/tables-apres.txt"
NOUVELLES="$(comm -13 "$R_STORE/tables-avant.txt" "$R_STORE/tables-apres.txt")"
[ -z "$NOUVELLES" ] || { echo "ARRÊT : nouvelle(s) table(s) : $NOUVELLES"; exit 1; }
URBZ="$(WP db tables "${PREFIX}urbizen_*" --all-tables 2>/dev/null || true)"
[ -z "$URBZ" ] || { echo "ARRÊT : table ${PREFIX}urbizen_* présente : $URBZ"; exit 1; }
echo "phase 6 OK : aucune table créée, aucune ${PREFIX}urbizen_*"
```

**Aucun compte ni courriel de test réel** n'est créé ou envoyé.

---

## Phase 7 — Réouverture et contrôles publics

Après réouverture, **tout échec ré-arme automatiquement la maintenance** via
`echec_public` (ré-armement + confirmation du 503 + sortie vers la phase 8).

```bash
# (serveur) purge puis réouverture (lève la maintenance, arrête le mainteneur).
source ~/.urbz-deploy/common.sh
WP litespeed-purge all 2>/dev/null || WP cache flush
~/.urbz-deploy/maint-off
```

```bash
# (local) accueil + pages publiées : 200, rendu et RESSOURCES inchangés (hors
# ver= du seul plugin Urbizen). Tout échec ⇒ echec_public.
source ~/.urbz-deploy-local/common.sh
while IFS= read -r u; do
  [ -n "$u" ] || continue
  name="$(refname "$u")"
  code="$(curl -s -o "$L_REF/$name.apres.html" -w '%{http_code}' -- "$u")"
  [ "$code" = 200 ] || echec_public "$u → HTTP $code"
  diff <(normaliser < "$L_REF/$name.avant.html") <(normaliser < "$L_REF/$name.apres.html") >/dev/null \
    || echec_public "rendu modifié sur $u"
  # Ressources : liste après, comparée à l'avant. Seul le ver= Urbizen est
  # neutralisé ; toute autre ressource (thème, autre extension) doit être identique.
  { grep -oE '(src|href)="[^"]+"' "$L_REF/$name.apres.html" || true; } | LC_ALL=C sort -u \
    > "$L_REF/$name.res.apres.txt"
  diff <(res_norm < "$L_REF/$name.res.avant.txt") <(res_norm < "$L_REF/$name.res.apres.txt") >/dev/null \
    || echec_public "ressources modifiées sur $u"
  if grep -q 'urbizen-comptes' "$L_REF/$name.apres.html" \
       && ! grep -q 'urbizen-comptes' "$L_REF/$name.avant.html"; then
    echec_public "ressource de comptes inattendue sur $u"
  fi
done < "$L_REF/urls.txt"
echo "pages publiques inchangées"
```

```bash
# (local) page de résultat SANS effet : 200 + en-têtes protecteurs.
source ~/.urbz-deploy-local/common.sh
RES="$SITE/wp-admin/admin-post.php?action=urbizen_resultat&code=verifiez"
hdr="$(curl -s -D - -o /dev/null -- "$RES")"
printf '%s' "$hdr" | grep -qE '^HTTP/[0-9.]+ 200'                        || echec_public "résultat ≠ 200"
printf '%s' "$hdr" | grep -qiE '^cache-control:.*no-store'               || echec_public "no-store manquant"
printf '%s' "$hdr" | grep -qiE '^referrer-policy:[[:space:]]*no-referrer' || echec_public "no-referrer manquant"
printf '%s' "$hdr" | grep -qiE '^x-robots-tag:.*noindex'                 || echec_public "noindex manquant"
echo "page de résultat conforme"
```

```bash
# (serveur) journal PHP web : le plus sûr pour un déploiement court est inode
# identique ET taille non décroissante. Disparition, rotation ou troncature ⇒
# ARRÊT pour inspection manuelle (les lignes du fichier renommé pourraient être
# manquées). Aucune ligne recopiée. Tout échec ré-arme la maintenance.
source ~/.urbz-deploy/common.sh
read -r OLD_INODE OLD_SIZE < "$R_STORE/phplog-ref.txt"
if [ ! -f "$PHPLOG" ]; then
  ~/.urbz-deploy/maint-on; echo "ARRÊT : journal PHP disparu — inspection manuelle"; exit 1
fi
read -r CUR_INODE CUR_SIZE < <(stat -c '%i %s' -- "$PHPLOG")
if [ "$CUR_INODE" != "$OLD_INODE" ] || [ "$CUR_SIZE" -lt "$OLD_SIZE" ]; then
  ~/.urbz-deploy/maint-on
  echo "ARRÊT : rotation ou troncature du journal PHP — inspection manuelle (aucune ligne recopiée)"; exit 1
fi
tail -c "+$((OLD_SIZE + 1))" -- "$PHPLOG" > "$R_STORE/phplog-delta.txt"
NEW="$(grep -cE 'PHP (Parse error|Fatal error|Warning|Recoverable|Uncaught)' "$R_STORE/phplog-delta.txt" || true)"
echo "nouvelles erreurs PHP : $NEW"
if [ "$NEW" -ne 0 ]; then
  ~/.urbz-deploy/maint-on
  echo "ARRÊT : $NEW nouvelle(s) erreur(s) PHP (détail hors racine web, non recopié)"; exit 1
fi
echo "phase 7 OK"
```

**Arrêt :** page/ressource/ en-tête modifiés, résultat ≠ 200, ou nouvelle erreur
PHP → maintenance ré-armée, **retour arrière** (phase 8).

---

## Phase 8 — Retour arrière

Restauration et contrôles internes **sous maintenance** ; la maintenance n'est
levée qu'**après** ces contrôles ; l'accueil n'est vérifié **qu'une fois levée**.

```bash
# (serveur) sous maintenance : restaurer 0.10.0 et contrôler EN INTERNE.
source ~/.urbz-deploy/common.sh
~/.urbz-deploy/maint-on

[ -d "$R_ROLLBACK" ] || { echo "ARRÊT : dossier de retour arrière introuvable"; exit 1; }
[ -e "$R_ACTIVE" ] && mv -- "$R_ACTIVE" "$R_STAGE/urbizen-platform.echec-$TS"
mv -- "$R_ROLLBACK" "$R_ACTIVE"

manifeste "$R_ACTIVE" > "$R_STORE/plugin-manifeste-retour.txt"
diff -- "$R_STORE/plugin-manifeste-attendu-010.txt" "$R_STORE/plugin-manifeste-retour.txt" \
  || { echo "ARRÊT : arbre restauré ≠ 0.10.0 — maintenance maintenue"; exit 1; }
[ "$(WP plugin get urbizen-platform --field=version)" = "0.10.0" ] \
  || { echo "ARRÊT : version restaurée ≠ 0.10.0 — maintenance maintenue"; exit 1; }

# Rôle : ne supprimer QUE s'il était ABSENT avant (relecture) ET porté par personne.
ROLE_AVANT="$(cat "$R_STORE/role-avant.txt")"
if [ "$ROLE_AVANT" != "absent" ]; then
  echo "rôle préexistant ($ROLE_AVANT) : AUCUNE suppression"
else
  N="$(WP user list --role=urbizen_client --format=count)"
  if [ "$N" -eq 0 ]; then
    WP role delete urbizen_client
    WP eval 'exit(get_role("urbizen_client") ? 1 : 0);' \
      && echo "rôle urbizen_client supprimé et absent" \
      || { echo "ARRÊT : rôle encore présent — maintenance maintenue"; exit 1; }
  else
    echo "ARRÊT : $N utilisateur(s) portent urbizen_client — aucune suppression, maintenance maintenue, décision humaine"
    exit 1
  fi
fi

# Contrôles internes réussis : purge puis LEVÉE de la maintenance.
WP litespeed-purge all 2>/dev/null || WP cache flush
~/.urbz-deploy/maint-off
echo "phase 8 (serveur) OK — 0.10.0 restaurée, maintenance LEVÉE"
```

```bash
# (local) MAINTENANCE LEVÉE : contrôler l'accueil (rendu ET ressources ==
# références initiales, HTTP 200). Échec ⇒ ré-armer immédiatement la maintenance.
source ~/.urbz-deploy-local/common.sh
home_url="$(head -1 "$L_REF/urls.txt")"
name="$(refname "$home_url")"
code="$(curl -s -o "$L_REF/$name.rollback.html" -w '%{http_code}' -- "$home_url")"
{ grep -oE '(src|href)="[^"]+"' "$L_REF/$name.rollback.html" || true; } | LC_ALL=C sort -u \
  > "$L_REF/$name.res.rollback.txt"
if [ "$code" != 200 ] \
   || ! diff <(normaliser < "$L_REF/$name.avant.html") <(normaliser < "$L_REF/$name.rollback.html") >/dev/null \
   || ! diff <(res_norm < "$L_REF/$name.res.avant.txt") <(res_norm < "$L_REF/$name.res.rollback.txt") >/dev/null; then
  echo "ÉCHEC BLOQUANT : accueil différent ou non-200 après retour arrière — ré-armement"
  SSH '~/.urbz-deploy/maint-on'
  exit 1
fi
echo "retour arrière vérifié : accueil conforme (200), maintenance déjà levée"
```

- **Ne jamais effacer les sauvegardes.**
- La **restauration complète de la base est un dernier recours** : E2.2 ne crée
  aucune table (seule l'option des rôles est écrite, annulée ci-dessus). Restaurer
  la base écraserait toute **modification concurrente** depuis la sauvegarde.

---

## Phase 9 — Après succès

```bash
# (serveur)
source ~/.urbz-deploy/common.sh
~/.urbz-deploy/maint-off || true       # idempotent
echo "sauvegardes conservées sous : $R_STORE"
```

- **Conserver** les sauvegardes et l'ancien plugin (`$R_ROLLBACK`).
- **Ne créer aucune page** dans ce lot ; la création des pages de comptes en
  brouillon relève d'une **autorisation et d'un protocole distincts**.

---

## Récapitulatif des arrêts de sécurité

| Phase | On s'arrête si… |
|---|---|
| 0 | aucun emplacement hors racine web (canonique, sans lien) sur le même volume ; chemin non absolu ; `setsid`/`nohup` absents ; journal PHP illisible ; état local préexistant |
| 1 | extension inactive ; version ≠ 0.10.0 ; WP ≠ 7.0.2 ; PHP ≠ 8.3.x ; rôle présent ; page à shortcode ; dérive du plugin |
| 2 | sauvegarde vide, `mysqldump`/`gzip -t` en échec (pipefail), empreinte non revérifiée, inventaire illisible |
| 3 | arbre ≠ `2230fbb9…`, manifeste divergent, erreur `php -l` |
| 4 | volume différent (avant `maint-on`) ; mainteneur mort/non identifié ; 503 non confirmé (levée immédiate) ; renommage 1 refusé (0.10.0 intacte) ; renommage 2 refusé (0.10.0 restaurée et vérifiée) — levée seulement si 0.10.0 active |
| 5 | `verify` en échec, ou `quota-verify` de code non nul → arrêt immédiat sous maintenance |
| 6 | hook `nopriv` interdit, capacité ≠ `read`, **nouvelle** table ou `{prefixe}urbizen_*` |
| 7 | page/ressource/en-tête modifiés, résultat ≠ 200, **nouvelle erreur PHP**, journal disparu/roté/tronqué → maintenance ré-armée |
| 8 | arbre restauré ≠ 0.10.0, utilisateur portant le rôle, ou accueil non conforme après levée → maintenance (ré)armée |

**Rappel final.** Ce document décrit une procédure ; il ne l'autorise pas. Toute
exécution suppose une autorisation distincte, explicite, postérieure à cette revue.
