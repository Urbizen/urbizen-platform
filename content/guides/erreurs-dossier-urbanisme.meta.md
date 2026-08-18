# Guide 6 — métadonnées de publication

Accompagne `erreurs-dossier-urbanisme.html`.

| Champ | Valeur |
|---|---|
| **Titre** (H1) | Dossier d'urbanisme : 7 erreurs qui peuvent retarder l'accord |
| **Slug** | `erreurs-dossier-urbanisme` |
| **URL** | `https://urbizen.fr/guides/erreurs-dossier-urbanisme/` |
| **Catégorie** | Conseils & démarches |
| **Image mise en avant** | `assets/images/blog/erreurs-dossier-urbanisme.webp` (déjà au dépôt, carte 05 de l'accueil) |

## Pourquoi ce slug

`erreurs-dossier-urbanisme` sans le chiffre : le « 7 » porte la promesse dans le
H1, mais figer un nombre dans une URL interdit d'en ajouter une huitième sans
changer d'adresse. Même arbitrage que pour le guide 4, rendu le 14 août 2026 et
appliqué ici.

## Intention de recherche

**Informationnelle, en phase de préparation.** Le lecteur a un dossier en cours
ou vient d'en recevoir une demande de pièces. Il cherche à comprendre ce qui
cloche, ou à éviter que cela cloche.

Requêtes secondaires visées : *dossier permis de construire incomplet · demande
de pièces complémentaires mairie · plan de masse coté trois dimensions · erreurs
déclaration préalable · pourquoi mon dossier est refusé · délai instruction
recommence*.

## AIOSEO

**Title** — 55 caractères
```
7 erreurs qui retardent un dossier d'urbanisme | Urbizen
```

**Meta description** — 156 caractères
```
Une pièce oubliée ne coûte pas quelques jours : le délai d'instruction recommence entier. Sept erreurs fréquentes, leur symptôme et comment les corriger.
```

`og_*` et `twitter_*` laissés NULL.

## Extrait (post_excerpt)

```
Ces erreurs ne font pas refuser un dossier : elles le font recommencer. Sept
points à vérifier avant le dépôt, avec pour chacun le symptôme, la conséquence,
la méthode de vérification et la correction.
```

## Ce que l'article contient

| | |
|---:|---:|
| Mots (hors légendes, alt et sources) | **2 216** |
| H2 | 10 |
| Schéma dédié | 1 |
| Check-list de relecture | 11 points |
| Liens internes | 7 |
| Références au code, liées | 10 |

Pas de H3 : chaque erreur est un H2, ce qui donne une table des matières
directement utilisable et un ancrage clair. La structure interne de chaque
erreur — symptôme, conséquence, vérification, correction — est portée par des
paragraphes en gras et un bloc `guide-resultat`, pas par des sous-titres qui
auraient produit vingt-huit H3 sans rien ajouter.

## Les sept erreurs, et la huitième

1. Le formulaire et les plans se contredisent.
2. Les surfaces ne sont pas calculées selon la bonne définition.
3. Le plan de masse n'est pas coté dans les trois dimensions.
4. Les pièces graphiques sont illisibles.
5. Les photographies ne montrent pas ce qu'elles doivent montrer.
6. Le projet est mal qualifié dès le formulaire.
7. Les règles locales ou le secteur protégé n'ont pas été regardés.

Puis, sous un H2 distinct : **modifier une pièce sans les autres**. Elle n'est
pas comptée dans les sept parce qu'elle n'est pas de même nature — elle survient
*après coup*, sur un dossier qui était juste, et ramène l'erreur n° 1 par une
autre porte. C'est celle que le schéma sert à neutraliser.

## L'angle : le mécanisme avant la liste

L'article ouvre par trois articles du code, avant la première erreur :

- **R.423-38** — demande dans le délai d'un mois, par LRAR, *de façon
  exhaustive* ;
- **R.423-39** — trois mois pour produire, et le délai d'instruction
  **commence à courir** à la réception des pièces ;
- **R.431-36** — « aucune autre information ou pièce ne peut être exigée ».

C'est ce qui donne au guide sa valeur : le lecteur comprend *pourquoi* une pièce
oubliée coûte un délai entier, et découvre au passage **deux protections** dont
il dispose (l'exhaustivité, et R.423-41 sur la demande tardive ou non exigible).
Aucune page commerciale ne porte cela.

## Liens internes posés

| Cible | Emplacement |
|---|---|
| `/declarations-prealables/` | introduction, 2ᵉ paragraphe — la relecture d’avant remise |
| `/guides/piscine-garage-carport-autorisation/` | erreur 2, sur la méthode de métré |
| `/guides/dp-ou-permis-de-construire/` | erreur 6, sur la qualification du projet |
| `/guides/lire-le-plu-de-son-terrain/` | erreur 7, sur la lecture du règlement |
| `/guides/delais-urbanisme-debut-des-travaux/` | conclusion, sur le coût réel d'un aller-retour |
| `/declarations-prealables/` | dernier paragraphe |
| `/permis-de-construire/` | dernier paragraphe |
| `/conception/` | dernier paragraphe |

> Les cibles ci-dessus étaient déjà liées ailleurs dans l'article : le décompte
> « Liens internes », qui porte sur les cibles distinctes, ne change pas. Ce que
> le lot GUIDES ajoute est un **emplacement** — l'introduction — et non une cible.


Le CTA de fin d'article, servi par la catégorie « Conseils & démarches », mène à
`/tarifs/`. C'est le seul chemin vers cette page, et il suffit : le corps n'en
porte aucun lien.

## Schéma

`assets/images/guides/schema-coherence-dossier.svg` — tableau croisant quatre
valeurs pivots (surface de plancher créée, emprise au sol, distances aux limites
séparatives, hauteur) avec cinq pièces du dossier (Cerfa, plan de masse, plan en
coupe, plan des façades, document d'insertion).

Format matriciel plutôt que schéma à liaisons : quatre valeurs reliées à cinq
pièces produisent onze traits qui se croisent, illisibles à 640 px de large. Une
matrice se lit ligne par ligne, à toutes les largeurs, et se restitue mot pour
mot en `alt`.

## Point de vigilance daté

L'erreur 5 signale le changement du **1er juillet 2026** : R.431-36 exige les
documents des c) et d) de R.431-10 — insertion et photographies — pour les
projets **visibles depuis l'espace public**, ainsi qu'en secteur protégé. C'est
la conséquence du décret n° 2026-291 du 17 avril 2026, relevée à la vérification
et absente de la plupart des ressources en ligne.

## Vérification réglementaire

`docs/VERIFICATION_REGLEMENTAIRE_GUIDES_03-07.md`, sections 1 et 2.

## Ce qui n'est pas dans l'article, volontairement

- **Aucune statistique.** Pas de « X % des dossiers sont incomplets » : ce
  chiffre n'existe dans aucune source publique consultable, donc il n'est pas
  écrit. C'était la tentation principale sur ce sujet.
- **Aucune dramatisation.** L'article dit dès sa première phrase que ces erreurs
  ne font pas refuser un dossier — elles le retardent. Seule l'erreur 6, la
  mauvaise qualification, est présentée comme d'une autre nature, parce qu'elle
  l'est.
- Aucun montant de prestation, aucune promesse d'obtention.
