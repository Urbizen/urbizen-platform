# Bancs d'essai Urbizen

Quatre bancs autonomes : **sans WordPress installé**, **sans base de données**,
**sans aucun appel réseau réel**. Les fonctions WordPress sont doublées dans les
bancs PHP, les services IGN sont simulés dans les bancs JavaScript.

## Commande unique

```bash
cd tests/cadastre
npm install     # une seule fois : installe jsdom
npm test        # équivaut à ./run-all.sh
```

`run-all.sh` régénère d'abord la fixture depuis le rendu réel de `Renderer.php`,
puis enchaîne les quatre bancs en annonçant chacun par son nom. Il **s'arrête
avec un code non nul dès qu'un banc échoue** : `0` succès, `1` au moins un banc
en échec, `2` prérequis manquant.

## Prérequis

| Outil | Version | Rôle |
|---|---|---|
| Node | 18 ou plus | exécution des bancs JavaScript |
| jsdom | ^27 | DOM simulé — `npm install` |
| PHP | 8.1 ou plus | bancs de rendu et génération de la fixture |

macOS ne fournit plus PHP depuis Monterey. Désignez le vôtre si besoin :

```bash
PHP_BIN=/opt/homebrew/bin/php npm test
```

## Les quatre bancs

| Fichier | Portée |
|---|---|
| `test-cadastre.mjs` | composant cadastre : montage, identifiants, suggestions hostiles, parcours d'erreur, stockage |
| `test-form.mjs` | pont cadastre → formulaire, **sur le HTML réel de `Renderer.php`** |
| `test-render.php` | rendu du bloc et du shortcode cadastre |
| `test-form-render.php` | rendu du bloc et du shortcode formulaire |

## La fixture

`test-form.mjs` ne contient **aucune copie manuelle** de la structure HTML : il
consomme `fixture.html`, produite par `make-fixture.php` à partir du vrai
`Renderer.php`. Si le rendu serveur change de façon incompatible, le banc
JavaScript échoue au lieu de rester vert sur un gabarit périmé.

La fixture est régénérée à chaque exécution de `run-all.sh` et n'est pas
versionnée. Elle ne contient aucune donnée personnelle : tous les champs sont
rendus vides, et `make-fixture.php` refuse de produire un fichier contenant une
valeur non vide.

Pour la régénérer seule :

```bash
npm run fixture
```

## Exécuter un banc PHP sans PHP local

Les bancs PHP peuvent tourner sur le serveur d'hébergement sans rien y écrire —
répertoire temporaire, supprimé après :

```bash
tar czf - tests/cadastre wordpress/urbizen-platform --exclude=node_modules \
  | ssh -i ~/.ssh/urbizen_hostinger -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}" \
    'D=$(mktemp -d) && tar xzf - -C $D && cd $D/tests/cadastre && php test-form-render.php; R=$?; rm -rf $D; exit $R'
```
