# Leaflet — dépendance embarquée

| | |
|---|---|
| Bibliothèque | [Leaflet](https://leafletjs.com) |
| Version | **1.9.4** |
| Source | `https://unpkg.com/leaflet@1.9.4/dist/` |
| Licence | **BSD 2-Clause** — texte intégral dans `LICENSE` |
| Auteurs | © 2010-2023 Volodymyr Agafonkin, © 2010-2011 CloudMade |

## Pourquoi une copie dans le dépôt

Leaflet est servi **localement**, jamais depuis un CDN : un CDN ferait fuiter
l'adresse IP de chaque visiteur vers un tiers, sans base légale (décision D-008).

## Contenu

```
leaflet.js       bibliothèque minifiée, en-tête @preserve conservé
leaflet.css      styles, référence images/ en chemins relatifs
images/          layers.png, layers-2x.png, marker-icon.png,
                 marker-icon-2x.png, marker-shadow.png
LICENSE          licence BSD 2-Clause officielle
```

Empreintes SHA-256 des fichiers téléchargés :

```
db49d009c841f5ca34a888c96511ae936fd9f5533e90d8b2c4d57596f4e5641a  leaflet.js
a7837102824184820dfa198d1ebcd109ff6d0ff9a2672a074b9a1b4d147d04c6  leaflet.css
```

## Règles

- Ne pas modifier ces fichiers : toute correction se fait par montée de version.
- Pour changer de version : remplacer les fichiers, mettre à jour la constante
  `LEAFLET_VERSION` de `src/Blocks/CadastreBlock.php`, ce tableau et les
  empreintes ci-dessus.
- Ne jamais réintroduire d'appel CDN, y compris pour les polices ou les images.
