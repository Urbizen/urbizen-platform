# Bancs d'essai — composant cadastre

Deux bancs d'essai autonomes, sans base de données, sans WordPress installé et
**sans aucun appel réseau réel** : les services IGN sont simulés.

## `test-cadastre.mjs` — comportement JavaScript

DOM simulé avec jsdom. Couvre le montage automatique de plusieurs instances,
l'unicité des identifiants HTML, la non-interprétation d'une suggestion
hostile, les trois parcours d'erreur (adresse valide, adresse introuvable,
panne réseau) et la gestion de `sessionStorage`.

```bash
cd tests/cadastre
npm install
npm test
```

## `test-render.php` — rendu PHP

Les fonctions WordPress utilisées sont doublées dans le fichier. Vérifie
l'enregistrement du bloc et du shortcode, l'identité stricte de leurs rendus,
l'échappement des attributs hostiles, le refus d'une hauteur de carte
fantaisiste, et le fait qu'aucune ressource n'est enfilée avant le rendu.

```bash
php tests/cadastre/test-render.php
```

Sans PHP en local, le banc s'exécute sur le serveur d'hébergement sans rien y
écrire :

```bash
tar czf - tests/cadastre/test-render.php wordpress/urbizen-platform/src/Blocks/CadastreBlock.php \
  | ssh -i ~/.ssh/urbizen_hostinger -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}" \
    'D=$(mktemp -d) && tar xzf - -C $D && php $D/tests/cadastre/test-render.php; R=$?; rm -rf $D; exit $R'
```

Les deux bancs renvoient un code de sortie non nul en cas d'échec.
