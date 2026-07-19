# Protocole de déploiement limité — composant cadastre

> **Ce protocole n'a pas été exécuté.** Il attend une autorisation explicite.
> Aucune commande ci-dessous ne doit être lancée avant accord.

Objectif : vérifier en conditions réelles ce que les bancs d'essai simulés ne
peuvent pas prouver — l'insertion du bloc dans Gutenberg, son enregistrement,
son rechargement et son rendu public — **sans toucher à une seule page
publiée**.

Variables attendues dans l'environnement : `SSH_USER`, `SSH_HOST`, `SSH_PORT`,
`WP_ROOT` (voir [AI_CONTEXT.md](AI_CONTEXT.md)).

---

## Périmètre

**Ce qui est modifié**

- le répertoire `wp-content/plugins/urbizen-platform/` ;
- une page **nouvelle**, en brouillon, non indexée, créée pour l'essai.

**Ce qui n'est pas touché**

- la page d'accueil et les 10 autres pages publiées ;
- le thème enfant et le thème parent ;
- toute autre extension, y compris Fluent Forms ;
- les réglages WordPress, les menus, les options ;
- la base de données, hors la page de test créée puis supprimée.

---

## 1. Sauvegarde préalable

```bash
ssh -i ~/.ssh/urbizen_hostinger -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}"
cd "${WP_ROOT}"
TS=$(date +%Y%m%d-%H%M)

# Base — wp db export échoue sous CageFS, passer par mysqldump
CNF=$(mktemp); chmod 600 $CNF
wp eval 'printf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\n", DB_USER, DB_PASSWORD, DB_HOST);' > $CNF
mysqldump --defaults-extra-file=$CNF --single-transaction --default-character-set=utf8mb4 \
  $(wp eval 'echo DB_NAME;') | gzip > ~/backups/urbizen-db-$TS.sql.gz
shred -u $CNF

# Extension seule, avant modification
tar czf ~/backups/urbizen-plugin-$TS.tar.gz wp-content/plugins/urbizen-platform

ls -la ~/backups/ | tail -3
```

Ne pas continuer si l'une des deux archives est absente ou de taille nulle.

## 2. Envoi de l'extension, et d'elle seule

```bash
rsync -az --delete -e "ssh -i ~/.ssh/urbizen_hostinger -p ${SSH_PORT}" \
  wordpress/urbizen-platform/ \
  "${SSH_USER}@${SSH_HOST}:${WP_ROOT}/wp-content/plugins/urbizen-platform/"
```

`--delete` s'applique **uniquement** à ce répertoire. Le thème, les autres
extensions et les téléversements ne sont pas dans le périmètre de la commande.

Contrôles immédiats :

```bash
cd "${WP_ROOT}"
for f in $(find wp-content/plugins/urbizen-platform -name '*.php'); do php -l $f; done | grep -v "No syntax"
wp plugin get urbizen-platform --field=version      # attendu : 0.3.0
wp eval 'var_dump( WP_Block_Type_Registry::get_instance()->is_registered( "urbizen/cadastre" ) );'
curl -sS -o /dev/null -w "accueil %{http_code}\n" https://urbizen.fr/
```

L'accueil doit rester en 200 et son rendu inchangé : l'extension n'enfile ses
ressources que sur les pages qui contiennent le composant.

## 3. Page de test, en brouillon et non indexée

```bash
wp post create --post_type=page --post_status=draft \
  --post_title="Test cadastre (interne)" \
  --post_content='<!-- wp:urbizen/cadastre /-->' --porcelain
# note l'ID renvoyé, par exemple 1200
wp post meta update <ID> _yoast_wpseo_meta-robots-noindex 1 2>/dev/null || true
wp post get <ID> --field=post_status     # attendu : draft
```

Un brouillon n'est ni indexé, ni accessible publiquement. Pour la prévisualiser,
utiliser le lien de prévisualisation signé de WordPress, jamais une publication.

## 4. Contrôles à effectuer

**Dans l'éditeur** — le cœur de ce qui n'a pas pu être testé :

1. le bloc « Cadastre Urbizen » apparaît dans l'outil d'insertion ;
2. il s'insère dans la page ;
3. l'aperçu statique s'affiche, sans carte interactive ni requête réseau ;
4. les cinq réglages de la barre latérale modifient l'aperçu ;
5. une hauteur invalide (`bidon`) déclenche l'avertissement ;
6. la page s'enregistre sans erreur ni « contenu inattendu » ;
7. après rechargement complet, le bloc revient avec ses réglages ;
8. la console du navigateur ne montre aucune erreur.

**Sur la prévisualisation** :

9. autocomplétion sur une adresse réelle ; suggestions affichées ;
10. carte affichée, bascule photo / plan ;
11. sélection puis confirmation d'une parcelle ;
12. section, numéro et surface indicative cohérents avec le cadastre ;
13. onglet réseau : aucun 404, aucune requête vers un CDN ;
14. essai sur ordinateur **et** sur mobile, ou en émulation 390 px.

**Vérification du périmètre** :

15. une page publiée quelconque ne charge **ni** `urbizen-cadastre.js` **ni**
    Leaflet ;
16. l'accueil est identique à avant l'essai.

## 5. Retour arrière immédiat

À la moindre anomalie, dans cet ordre :

```bash
cd "${WP_ROOT}"
wp post delete <ID> --force                 # supprime la page de test
tar xzf ~/backups/urbizen-plugin-<TS>.tar.gz -C .   # restaure l'extension
wp litespeed-purge all
curl -sS -o /dev/null -w "accueil %{http_code}\n" https://urbizen.fr/
```

En dernier recours, l'extension se désactive sans effet de bord — elle ne crée
ni table, ni option :

```bash
wp plugin deactivate urbizen-platform
```

Restauration de la base uniquement si elle a été altérée, ce que ce protocole
n'implique pas.

## 6. Nettoyage en cas de succès

```bash
wp post delete <ID> --force     # la page de test n'a pas vocation à rester
wp litespeed-purge all
```

Puis consigner le résultat dans `CHANGELOG.md` et `ROADMAP.md` : le bloc ne
passe de `[~]` à `[x]` **que** si les 16 contrôles ci-dessus sont concluants.
