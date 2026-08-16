# Manifeste du kit visuel SEO Urbizen

Source de vérité : `docs/SEO_VISUALS_HANDOFF.md`.

## Installation

- Copier `assets/seo-projects/` vers
  `wordpress/urbizen-child/assets/images/seo-projects/`.
- Copier `assets/dossier/` vers
  `wordpress/urbizen-child/assets/images/dossier/` sans remplacer une version
  plus récente validée du même fichier.
- Conserver les quatre variantes WebP de chaque photographie dans les `srcset`.
- Ne générer aucun visuel de substitution.

## Photographies de projets fictifs

Chaque racine existe en 1500, 960, 704 et 352 pixels :

- `extension-maison-photo`
- `piscine-photo`
- `abri-jardin-photo`
- `pergola-photo`
- `carport-photo`
- `transformation-garage-photo`
- `panneaux-solaires-photo`
- `fenetre-toit-photo`
- `modification-facade-photo`
- `cloture-portail-photo`
- `pieces-declaration-prealable`

Les photographies doivent être présentées comme des illustrations de projets
fictifs, jamais comme des réalisations de clients Urbizen.

## Planches métier avec cartouche Urbizen validé

- `dp1-plan-situation-cartouche.webp`
- `dp2-plan-masse-cartouche.webp`
- `dp3-plan-coupe-cartouche.webp`
- `dp4-facades-cartouche.webp`
- `dp6-insertion-cartouche.webp`
- `dp7-environnement-cartouche.webp`
- `dp8-paysage-cartouche.webp`

Ces planches sont les seuls visuels autorisés pour illustrer les pièces DP. Ne
pas les remplacer par des pictogrammes, des schémas simplifiés, un faux CERFA
ou un faux document administratif.

## Contrôles obligatoires

- ratios et dimensions explicites ;
- `srcset` et `sizes` présents ;
- texte alternatif repris du handoff ;
- chargement différé uniquement hors zone initiale ;
- aucun recadrage qui coupe le projet principal ou le cartouche ;
- aucun doublon en médiathèque ;
- aperçu visuel desktop et mobile avant publication.
