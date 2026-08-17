# Visuels SEO — consignes d'intégration

Ce dossier remplace toute proposition antérieure de schémas illustratifs. Les
pages commerciales utilisent des photographies architecturales réalistes de
projets entièrement fictifs. Les guides techniques utilisent les véritables
planches de démonstration Urbizen avec le cartouche validé.

## Règles impératives

- Ne générer aucun visuel de remplacement.
- Ne pas ajouter de texte, de cote ou de flèche sur une photographie.
- Légender les photographies comme des illustrations de projets fictifs.
- Ne jamais les présenter comme des réalisations de clients Urbizen.
- Pour expliquer une pièce DP, employer la planche métier correspondante, pas
  une maison en pictogramme ou un faux document administratif.
- Préserver le ratio 3:2 des photographies et ne pas couper le projet principal.
- Utiliser les variantes WebP 352, 704, 960 et 1500 dans un `srcset`.
- Pour les planches au cartouche, conserver le ratio 1600 × 1131.
- Toutes les images hors zone initiale sont chargées en différé.

## Pages commerciales

| URL | Image principale | Texte alternatif |
|---|---|---|
| `/declaration-prealable-extension-maison/` | `seo-projects/extension-maison-photo.webp` | Extension contemporaine sobre accolée à l'arrière d'une maison en brique, illustration d'un projet fictif. |
| `/declaration-prealable-piscine/` | `seo-projects/piscine-photo.webp` | Piscine rectangulaire implantée dans le jardin d'une maison individuelle, illustration d'un projet fictif. |
| `/declaration-prealable-abri-de-jardin/` | `seo-projects/abri-jardin-photo.webp` | Abri de jardin en bois installé dans une parcelle résidentielle, illustration d'un projet fictif. |
| `/declaration-prealable-pergola-carport/` | `seo-projects/pergola-photo.webp` | Pergola en aluminium adossée à la façade arrière d'une maison, illustration d'un projet fictif. |
| même page, section Carport | `seo-projects/carport-photo.webp` | Carport en aluminium implanté sur l'accès d'une maison, illustration d'un projet fictif. |
| `/declaration-prealable-transformation-garage/` | `seo-projects/transformation-garage-photo.webp` | Ancienne ouverture de garage remplacée par une large baie vitrée, illustration d'un projet fictif. |
| `/declaration-prealable-panneaux-solaires/` | `seo-projects/panneaux-solaires-photo.webp` | Panneaux photovoltaïques alignés sur un pan de toiture en tuiles, illustration d'un projet fictif. |
| `/declaration-prealable-fenetre-de-toit/` | `seo-projects/fenetre-toit-photo.webp` | Deux fenêtres de toit intégrées à une toiture en tuiles, illustration d'un projet fictif. |
| `/declaration-prealable-modification-facade/` | `seo-projects/modification-facade-photo.webp` | Façade de maison avec une grande ouverture et des menuiseries anthracite, illustration d'un projet fictif. |
| `/declaration-prealable-cloture-portail/` | `seo-projects/cloture-portail-photo.webp` | Clôture et portails anthracite sur un soubassement maçonné en bord de rue, illustration d'un projet fictif. |

## Guides techniques

| URL | Image principale | Usage secondaire |
|---|---|---|
| `/guides/pieces-declaration-prealable/` | `seo-projects/pieces-declaration-prealable.webp` | Galerie des sept planches uniquement si le texte précise que toutes ne sont pas systématiques. |
| `/guides/plan-masse-dp2/` | `dossier/dp2-plan-masse-cartouche.webp` | Montrer des détails recadrés du même fichier, jamais un autre faux plan. |
| `/guides/insertion-graphique-dp6/` | `dossier/dp6-insertion-cartouche.webp` | La photographie d'extension peut illustrer la différence entre photo et insertion. |
| `/guides/plan-facades-toitures-dp4/` | `dossier/dp4-facades-cartouche.webp` | La photo de modification de façade sert seulement de contexte. |
| `/guides/plan-coupe-dp3/` | `dossier/dp3-plan-coupe-cartouche.webp` | Aucun schéma simplifié supplémentaire. |

## Guides de qualification

Ces guides n'ont pas besoin d'une image artificielle différente à tout prix.
La preuve métier est plus crédible qu'un visuel décoratif.

| URL | Visuel recommandé |
|---|---|
| `/guides/secteur-protege-abf-declaration-travaux/` | Une capture provenant exclusivement d'une source cartographique officielle, correctement créditée, ou aucune image. Ne pas inventer un périmètre ABF. |
| `/guides/emprise-au-sol-surface-de-plancher/` | Associer `dp2-plan-masse-cartouche.webp` et `dp3-plan-coupe-cartouche.webp` dans la mise en page HTML avec deux légendes distinctes. |
| `/guides/distance-limite-separative-construction/` | Utiliser un recadrage du `dp2-plan-masse-cartouche.webp` où les limites et distances sont réellement cohérentes. |
| `/guides/recours-architecte-150-m2/` | Pas de schéma de seuil figé dans une image. Utiliser une mise en page éditoriale et un tableau HTML maintenable. |
| `/guides/demande-pieces-complementaires-urbanisme/` | Utiliser le montage `pieces-declaration-prealable.webp` ou une planche précise concernée par l'exemple. |
| `/guides/refus-declaration-prealable/` | Pas de faux arrêté municipal. Utiliser une composition typographique HTML sobre, sans reproduire un document administratif fictif. |
| `/guides/cerfa-declaration-travaux/` | Afficher uniquement un extrait du formulaire officiel en vigueur si sa réutilisation et sa version ont été vérifiées ; sinon, aucune fausse reproduction de CERFA. |

## Fichiers disponibles

### Photographies 3:2

Chaque racine possède quatre fichiers :

- `nom.webp` : 1500 × 1000 ;
- `nom-960.webp` : 960 × 640 ;
- `nom-704.webp` : 704 × 469 ;
- `nom-352.webp` : 352 × 235.

Les racines sont :

- `abri-jardin-photo`
- `carport-photo`
- `cloture-portail-photo`
- `extension-maison-photo`
- `fenetre-toit-photo`
- `modification-facade-photo`
- `panneaux-solaires-photo`
- `pergola-photo`
- `piscine-photo`
- `transformation-garage-photo`

### Planches Urbizen

- `dossier/dp1-plan-situation-cartouche.webp`
- `dossier/dp2-plan-masse-cartouche.webp`
- `dossier/dp3-plan-coupe-cartouche.webp`
- `dossier/dp4-facades-cartouche.webp`
- `dossier/dp6-insertion-cartouche.webp`
- `dossier/dp7-environnement-cartouche.webp`
- `dossier/dp8-paysage-cartouche.webp`

## Exemple de balise responsive

```html
<img
  src="/wp-content/themes/urbizen-child/assets/images/seo-projects/extension-maison-photo-960.webp"
  srcset="
    /wp-content/themes/urbizen-child/assets/images/seo-projects/extension-maison-photo-352.webp 352w,
    /wp-content/themes/urbizen-child/assets/images/seo-projects/extension-maison-photo-704.webp 704w,
    /wp-content/themes/urbizen-child/assets/images/seo-projects/extension-maison-photo-960.webp 960w,
    /wp-content/themes/urbizen-child/assets/images/seo-projects/extension-maison-photo.webp 1500w"
  sizes="(max-width: 760px) 100vw, 50vw"
  width="1500"
  height="1000"
  alt="Extension contemporaine sobre accolée à l'arrière d'une maison en brique, illustration d'un projet fictif."
  decoding="async">
```

Ajouter `loading="lazy"` seulement lorsque l'image se trouve hors de la zone
initialement visible.
