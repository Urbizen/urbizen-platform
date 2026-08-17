# Kit visuel des guides Urbizen — version 2

Ce kit contient **18 visuels éditoriaux distincts**, chacun associé à un guide précis. Les données, adresses, parcelles et projets montrés sont entièrement fictifs.

## Principes de la série

- Les pièces graphiques conservent le cartouche Urbizen et sont présentées comme des exemples de plans réalisés par Urbizen.
- Les visuels éditoriaux n’imitent pas des documents administratifs officiels et ne montrent aucune donnée client.
- Aucun visuel ne doit être présenté comme une réalisation client réelle.
- Les plans techniques ne doivent pas être recadrés avec `object-fit: cover` : afficher l’image entière avec `object-fit: contain`.
- Les photographies et compositions éditoriales peuvent être recadrées en 3:2.
- Chaque image principale possède trois variantes responsives : `-960.webp`, `-704.webp` et `-352.webp`.

## Affectation des 18 visuels

| # | Guide | Fichier principal | Nature | Texte alternatif conseillé |
|---|---|---|---|---|
| 01 | Quel CERFA pour une déclaration de travaux en 2026 ? | `guide-cerfa-declaration-travaux.webp` | Composition éditoriale | Formulaire administratif, dossier et plan de maison disposés sur un bureau, projet fictif. |
| 02 | Que faire après un refus de déclaration préalable ? | `guide-refus-declaration-prealable.webp` | Composition éditoriale | Plan de maison avec une zone à corriger mise en évidence, projet fictif. |
| 03 | Demande de pièces complémentaires en urbanisme | `guide-pieces-complementaires.webp` | Composition éditoriale | Dossier réunissant plans, photographies, croquis et liste de pièces, projet fictif. |
| 04 | Quand faut-il recourir à un architecte au-delà de 150 m² ? | `guide-architecte-150m2.webp` | Composition éditoriale | Plan de maison avec surface mise en évidence et maquette architecturale, projet fictif. |
| 05 | Distance aux limites séparatives | `guide-distance-limites.webp` | Plan de masse DP2 inédit | Plan de masse Urbizen d’un garage indépendant avec cotes aux limites, projet fictif. |
| 06 | Emprise au sol et surface de plancher | `guide-emprise-surface.webp` | Plan de masse DP2 inédit | Plan de masse Urbizen d’une piscine distinguant emprise au sol et surface de plancher, projet fictif. |
| 07 | Travaux en secteur protégé et avis de l’ABF | `guide-secteur-protege-abf.webp` | Photographie fictive | Rue d’un centre ancien dominée par un édifice patrimonial, lieu entièrement fictif. |
| 08 | Plan en coupe DP3 | `guide-plan-coupe-dp3.webp` | Coupe DP3 inédite | Plan en coupe Urbizen d’une extension implantée sur un terrain en pente, projet fictif. |
| 09 | Plan des façades et toitures DP4 | `guide-facades-toitures-dp4.webp` | Planche DP4 existante | Planche Urbizen présentant les quatre façades d’une maison avec extension, projet fictif. |
| 10 | Insertion graphique DP6 | `guide-insertion-dp6.webp` | Planche DP6 existante | Insertion graphique Urbizen d’une extension contemporaine sur une maison en brique, projet fictif. |
| 11 | Plan de masse DP2 | `guide-plan-masse-dp2.webp` | Planche DP2 existante | Plan de masse Urbizen d’une extension à l’arrière d’une maison, projet fictif. |
| 12 | Pièces d’une déclaration préalable | `guide-pieces-declaration-prealable.webp` | Montage de pièces | Ensemble de pièces Urbizen DP1, DP2, DP4 et DP6 d’un même projet fictif. |
| 13 | Délais d’urbanisme et début des travaux | `guide-delais-urbanisme.webp` | Composition éditoriale | Calendrier et étapes d’un dossier d’urbanisme présentés sur un bureau, illustration fictive. |
| 14 | Les 7 erreurs d’un dossier d’urbanisme | `guide-erreurs-dossier.webp` | Composition éditoriale | Sept points de contrôle repérés sur les plans d’une maison individuelle fictive. |
| 15 | Lire le PLU de son terrain | `guide-plu-terrain.webp` | Composition cartographique | Plan de zonage urbain avec une parcelle mise en évidence, territoire fictif. |
| 16 | Extension de maison : 5 vérifications | `guide-extension-maison.webp` | Photographie fictive | Extension contemporaine en rez-de-chaussée accolée à une maison en brique, projet fictif. |
| 17 | Déclaration préalable ou permis de construire ? | `guide-dp-ou-permis.webp` | Composition comparative | Deux dossiers de plans de tailles différentes illustrant la comparaison entre deux démarches. |
| 18 | Piscine, garage et carport : quelle autorisation ? | `guide-piscine-garage-carport.webp` | Triptyque fictif | Piscine, garage indépendant et carport présentés dans trois projets résidentiels fictifs. |

## Trois nouvelles pièces techniques

1. **DP2 garage — distance aux limites** : parcelle étroite de 17 × 38 m, garage projeté de 24 m², retraits cotés.
2. **DP2 piscine — emprise et surface** : parcelle de 576 m², bassin de 32 m², surface de plancher créée de 0 m².
3. **DP3 terrain en pente** : maison existante et extension de plain-pied, terrain naturel, niveaux et coupe A–A.

Les fichiers SVG modifiables sont dans `sources/`. Les PNG pleine définition correspondants sont conservés dans `final/` en plus des WebP optimisés.

## Intégration responsive

```html
<img
  src="guide-distance-limites-704.webp"
  srcset="guide-distance-limites-352.webp 352w,
          guide-distance-limites-704.webp 704w,
          guide-distance-limites-960.webp 960w"
  sizes="(max-width: 767px) 100vw, (max-width: 1199px) 50vw, 33vw"
  width="1600"
  height="1131"
  alt="Plan de masse Urbizen d’un garage indépendant avec cotes aux limites, projet fictif."
  loading="lazy"
  decoding="async">
```

Pour le premier visuel visible au-dessus de la ligne de flottaison, remplacer `loading="lazy"` par `fetchpriority="high"`.

## Contrôles effectués

- 18 fichiers principaux distincts ;
- 72 WebP valides, variantes comprises ;
- largeur maximale 1 600 px ;
- aucun fichier principal ne dépasse 250 Ko ;
- textes, cotes et cartouches techniques composés en SVG, donc sans pseudo-texte généré ;
- aperçu global vérifié visuellement en grille ;
- aucun logo tiers, filigrane, donnée réelle ou document administratif reproduit.

