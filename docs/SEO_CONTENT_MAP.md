# Carte de contenu SEO — cocon « projets »

**16 août 2026.** Branche `feat/pages-seo-projets`, partie de `main` à
`834bf84`. 21 contenus : 9 pages commerciales, 12 guides.

Ce document est le cadrage du lot. Il fixe, pour chaque URL, l'intention visée,
les pages internes à relier et la frontière avec l'existant. Il se lit **avant**
d'ouvrir un fichier de contenu.

---

## 0 · Méthode, et ce qu'elle ne prétend pas être

**Aucun volume de recherche n'est chiffré ici.** Aucun outil fiable n'était
accessible, et inventer des volumes aurait été pire qu'utile. Les expressions
sont classées sur quatre critères observables :

| Critère | Ce qu'il mesure |
|---|---|
| **Intention** | informationnelle, commerciale, ou transactionnelle |
| **SERP** | présence de spécialistes du dossier d'urbanisme, ou seulement de blogs |
| **Proximité devis** | distance entre la requête et une demande de prestation |
| **Adéquation** | correspondance avec ce qu'Urbizen réalise réellement |

Les résultats de recherche français ont servi à comprendre la SERP et à
**écarter** les formulations déjà occupées. Ils n'ont servi ni de source
juridique, ni de modèle de plan, ni de source d'inspiration rédactionnelle.

**Sources juridiques employées** : Legifrance (version en vigueur au 16 août
2026), service-public.gouv.fr, le portail officiel des formulaires, BOFiP pour
la fiscalité, Géoportail de l'urbanisme et Atlas des patrimoines pour la
cartographie. Détail dans `docs/VERIFICATION_REGLEMENTAIRE_SEO_PROJETS.md`.

---

## 1 · Le partage des intentions, en une page

C'est la règle qui évite la cannibalisation. Elle se lit de gauche à droite :
plus on va à droite, plus le lecteur est proche du dépôt.

```
  COMPRENDRE                QUALIFIER                 FAIRE FAIRE
  ────────────              ────────────              ────────────
  6 guides existants        12 nouveaux guides        9 pages projets
  (seuils, méthode)         (notions, pièces)         (prestation, dossier)
        │                          │                         │
        └──────────────────────────┴─────────────────────────┘
                                   │
                        /declarations-prealables/
                         hub commercial principal
```

- Les **six guides existants** gardent l'intention comparative et pédagogique
  sur leurs sujets. Aucun n'est réécrit, aucun n'est dépublié.
- Les **nouveaux guides** traitent des *notions* et des *pièces*, pas des types
  de projets. Ils ne refont pas le travail des six premiers.
- Les **pages projets** traitent d'un *projet précis* et mènent au formulaire.
  Elles n'expliquent pas le droit : elles disent quoi faire et ce qu'Urbizen
  fait.

### Les trois frontières qui demandaient un arbitrage

| Couple à risque | Décision |
|---|---|
| Guide « Piscine, garage, carport » ↔ pages Piscine et Pergola/Carport | Le guide garde la **comparaison des seuils entre trois familles**. Les pages traitent **un projet à la fois**, et vont jusqu'au dossier. Liens réciproques posés. |
| Guide « Extension : 5 vérifications » ↔ page Extension | Le guide s'adresse à qui **n'a rien dessiné** ; la page à qui **a un projet arrêté** et cherche qui monte le dossier. Le H1, le chapô et la FAQ n'ont aucune phrase commune. |
| Guide « DP ou permis » ↔ nouveau guide « Emprise au sol / surface de plancher » | Le premier **oriente vers une formalité** ; le second **définit deux notions** et les calcule. Le premier renvoie au second pour la méthode, le second au premier pour la conclusion. |

---

## 2 · Les 9 pages projets

Type : **page WordPress**, gabarit `page-projet-seo`. Balisage `WebPage` +
`Service` + `BreadcrumbList` + `FAQPage`. Index, follow.

### 2.1 · `/declaration-prealable-extension-maison/`

| | |
|---|---|
| **Intention** | commerciale — projet arrêté, cherche qui monte le dossier |
| **Mot-clé principal** | déclaration préalable extension maison |
| **Longues traînes** | déclaration de travaux extension · dossier extension maison · plan extension maison · agrandissement maison autorisation · extension 20 m² ou 40 m² · extension maison architecte · permis de construire extension |
| **Title** | Extension de maison : votre dossier de déclaration \| Urbizen |
| **Meta** | Extension de 5 à 40 m² : Urbizen détermine la démarche, dessine les plans et prépare le dossier complet à déposer en mairie. Devis avant commande. |
| **Sources** | R.421-14, R.421-17, R.431-2, R.111-22, R.420-1, A431-1 |
| **Liens sortants** | `/guides/extension-maison-verifications-avant-plans/` · `/guides/emprise-au-sol-surface-de-plancher/` · `/guides/recours-architecte-150-m2/` · `/tarifs/` · `/formulaire-declaration-prealable/` |
| **Liens entrants** | `/declarations-prealables/` · guide extension · guide DP ou permis |
| **Cannibalisation** | guide extension — arbitrée au § 1 |
| **Statut** | à publier |

### 2.2 · `/declaration-prealable-piscine/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | déclaration préalable piscine |
| **Longues traînes** | déclaration de travaux piscine · autorisation piscine · dossier piscine mairie · plan de masse piscine DP2 · piscine 10 m² autorisation · piscine 100 m² permis de construire · piscine secteur protégé |
| **Title** | Dossier de déclaration pour une piscine \| Urbizen |
| **Meta** | Bassin, plage, local technique, abri : ce qui compte pour votre déclaration, et le dossier qu'Urbizen prépare pour la mairie. À partir de 249 €. |
| **Sources** | R\*421-2 d), R.421-9, R.421-11, BOFiP taxe d'aménagement |
| **Liens sortants** | `/guides/piscine-garage-carport-autorisation/` · `/guides/plan-masse-dp2/` · `/tarifs/` · formulaire DP |
| **Cannibalisation** | guide piscine/garage/carport — arbitrée au § 1 |
| **Statut** | à publier |

### 2.3 · `/declaration-prealable-abri-de-jardin/`

| | |
|---|---|
| **Intention** | commerciale, avec forte composante fiscale |
| **Mot-clé principal** | déclaration préalable abri de jardin |
| **Longues traînes** | déclaration de travaux abri de jardin · autorisation abri de jardin · abri de jardin 5 m² · abri de jardin 20 m² · permis de construire abri de jardin · taxe d'aménagement abri de jardin |
| **Title** | Abri de jardin : déclaration de travaux clé en main \| Urbizen |
| **Meta** | Au-delà de 5 m², un abri de jardin se déclare. Les seuils, la taxe d'aménagement et le dossier qu'Urbizen prépare pour vous. À partir de 189 €. |
| **Sources** | R\*421-2, R.421-9, R.421-11, CGI 1635 quater, service-public F23263 |
| **Vigilance** | la taxe d'aménagement est une **règle fiscale**, jamais un tarif Urbizen. Les deux sont présentés dans deux blocs distincts et explicitement séparés. |
| **Statut** | à publier |

### 2.4 · `/declaration-prealable-pergola-carport/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | déclaration préalable pergola carport |
| **Longues traînes** | autorisation pergola bioclimatique · pergola adossée autorisation · déclaration préalable carport · autorisation carport · permis de construire carport · carport limite de propriété |
| **Title** | Pergola ou carport : quelle déclaration, quel dossier \| Urbizen |
| **Meta** | Pergola ouverte, pergola couverte, carport, annexe fermée : quatre cas, quatre régimes. Urbizen qualifie le vôtre et prépare le dossier. |
| **Sources** | R\*420-1, R.111-22, R\*421-2, R.421-9, R.421-14 |
| **Vigilance** | quatre objets distincts sur une seule page — chacun a sa section et sa conclusion propre. |
| **Statut** | à publier |

### 2.5 · `/declaration-prealable-transformation-garage/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | transformation garage en pièce habitable autorisation |
| **Longues traînes** | déclaration préalable transformation garage · garage en chambre déclaration · garage en studio autorisation · remplacer porte de garage par baie vitrée · transformation garage surface de plancher · garage changement de destination |
| **Title** | Transformer un garage : le dossier à déposer \| Urbizen |
| **Meta** | Façade modifiée, surface de plancher créée, stationnement du PLU : ce que la transformation d'un garage déclenche vraiment, et le dossier associé. |
| **Sources** | R.111-22, R\*421-17, R.151-30, R.151-33, L.151-30 |
| **Vigilance** | distinguer explicitement modification de façade, création de surface de plancher et changement de destination — trois choses souvent confondues. |
| **Statut** | à publier |

### 2.6 · `/declaration-prealable-panneaux-solaires/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | déclaration préalable panneaux solaires |
| **Longues traînes** | déclaration de travaux panneaux photovoltaïques · autorisation panneaux solaires toiture · dossier panneaux solaires mairie · plan de façade panneaux solaires · panneaux solaires secteur ABF |
| **Title** | Panneaux solaires : déclaration préalable et dossier \| Urbizen |
| **Meta** | Toiture, sol ou ombrière : la formalité n'est pas la même. Urbizen qualifie votre installation et prépare les pièces attendues par la mairie. |
| **Sources** | R\*421-2, R.421-9, R\*421-17, R.421-11 |
| **Vigilance** | ne pas mélanger toiture, sol et construction neuve. Le décret n° 2026-117 ne touche **pas** au solaire — vérifié, voir la note de vérification. |
| **Statut** | à publier |

### 2.7 · `/declaration-prealable-fenetre-de-toit/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | déclaration préalable fenêtre de toit |
| **Longues traînes** | déclaration fenêtre de toit · autorisation fenêtre de toit · création fenêtre de toit mairie · modification toiture déclaration · aménagement combles fenêtre de toit · remplacement fenêtre de toit autorisation |
| **Title** | Fenêtre de toit : déclarer la création en mairie \| Urbizen |
| **Meta** | Créer ou agrandir une fenêtre de toit modifie l'aspect extérieur. Ce que la mairie attend, et le dossier qu'Urbizen prépare. À partir de 189 €. |
| **Sources** | R\*421-17, R.111-22, R.421-11 |
| **Vigilance** | « Velux » est une **marque déposée**. Le vocabulaire de la page est « fenêtre de toit » ; la marque n'apparaît qu'une fois, pour lever l'ambiguïté du lecteur, jamais dans le title, le H1 ni les H2. |
| **Statut** | à publier |

### 2.8 · `/declaration-prealable-modification-facade/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | déclaration préalable modification façade |
| **Longues traînes** | création ouverture façade · agrandissement fenêtre autorisation · remplacement porte de garage baie vitrée · changement couleur façade autorisation · ravalement déclaration préalable · modification menuiseries déclaration |
| **Title** | Modifier une façade : déclaration et pièces à fournir \| Urbizen |
| **Meta** | Ouverture nouvelle, menuiseries, enduit, ravalement : ce qui relève d'une déclaration et ce qui n'en relève pas, puis le dossier prêt à déposer. |
| **Sources** | R\*421-17, R.421-11, L.151-18, R\*421-17-1 |
| **Vigilance** | distinguer **entretien à l'identique** et **modification de l'aspect extérieur**. La distinction commande tout le reste. |
| **Statut** | à publier |

### 2.9 · `/declaration-prealable-cloture-portail/`

| | |
|---|---|
| **Intention** | commerciale |
| **Mot-clé principal** | déclaration préalable clôture |
| **Longues traînes** | déclaration de travaux clôture · autorisation portail mairie · déclaration mur de clôture · hauteur clôture PLU · clôture secteur protégé · remplacement portail autorisation |
| **Title** | Clôture et portail : faut-il déclarer ? \| Urbizen |
| **Meta** | La clôture est le cas où la commune décide. Comment savoir si la vôtre est soumise à déclaration, et le dossier qu'Urbizen prépare si elle l'est. |
| **Sources** | R\*421-2, R\*421-12, R.421-11, L.151-18 |
| **Vigilance** | c'est **la** page où la règle nationale ne suffit pas : R\*421-12 laisse la commune soumettre les clôtures à déclaration par délibération. Le message central est « vérifiez auprès de votre mairie », pas un seuil. |
| **Statut** | à publier |

---

## 3 · Les 5 guides « pièces »

Type : **article**, catégorie **Conseils & démarches**. Balisage `Article` +
`BreadcrumbList`. Visuels : planches Urbizen à cartouche, imposées par
`docs/SEO_VISUALS_HANDOFF.md`.

| URL | Mot-clé principal | Title | Planche |
|---|---|---|---|
| `/guides/pieces-declaration-prealable/` | pièces déclaration préalable | Les pièces d'une déclaration préalable, une par une \| Urbizen | montage des sept |
| `/guides/plan-masse-dp2/` | plan de masse DP2 | Le plan de masse DP2, lu ligne à ligne \| Urbizen | `dp2-plan-masse-cartouche` |
| `/guides/insertion-graphique-dp6/` | insertion graphique DP6 | L'insertion graphique DP6 : ce qu'elle montre \| Urbizen | `dp6-insertion-cartouche` |
| `/guides/plan-facades-toitures-dp4/` | plan de façade DP4 | Plan des façades et toitures DP4 : comment le lire \| Urbizen | `dp4-facades-cartouche` |
| `/guides/plan-coupe-dp3/` | plan de coupe DP3 | Le plan en coupe DP3 et le profil du terrain \| Urbizen | `dp3-plan-coupe-cartouche` |

**Le fil rouge de ces cinq guides** : ils décrivent **le même projet fictif**,
celui des planches — parcelle AB 0123, terrain de 319 m², maison de 84 m²,
extension de 18 m² à l'arrière. Les chiffres cités dans le texte sont ceux qui
figurent sur les planches, jamais d'autres. C'est ce qui rend la démonstration
vérifiable par le lecteur.

**Règle éditoriale imposée** : rappeler dans chacun que **toutes les pièces ne
sont pas exigées dans tous les dossiers**. La composition dépend du projet, de
sa localisation et des règles applicables (R\*431-36).

---

## 4 · Les 7 guides de qualification

Type : **article**. Catégorie **Règles d'urbanisme** pour les notions,
**Conseils & démarches** pour les démarches.

| URL | Mot-clé principal | Catégorie | Title |
|---|---|---|---|
| `/guides/secteur-protege-abf-declaration-travaux/` | secteur ABF | Règles d'urbanisme | Savoir si votre terrain est en secteur protégé \| Urbizen |
| `/guides/emprise-au-sol-surface-de-plancher/` | emprise au sol surface de plancher | Règles d'urbanisme | Emprise au sol et surface de plancher : deux compteurs \| Urbizen |
| `/guides/distance-limite-separative-construction/` | distance construction limite propriété | Règles d'urbanisme | À quelle distance de la limite peut-on construire ? \| Urbizen |
| `/guides/recours-architecte-150-m2/` | architecte obligatoire 150 m² | Règles d'urbanisme | Le seuil des 150 m² et le recours à l'architecte \| Urbizen |
| `/guides/demande-pieces-complementaires-urbanisme/` | demande de pièces complémentaires mairie | Conseils & démarches | Répondre à une demande de pièces complémentaires \| Urbizen |
| `/guides/refus-declaration-prealable/` | refus déclaration préalable | Conseils & démarches | Après un refus de déclaration préalable \| Urbizen |
| `/guides/cerfa-declaration-travaux/` | CERFA déclaration préalable | Conseils & démarches | Quel CERFA pour une déclaration de travaux en 2026 \| Urbizen |

### Deux vigilances particulières

**`/guides/cerfa-declaration-travaux/`** — c'est le guide le plus exposé à
l'erreur, et la vérification a montré pourquoi : l'article A431-1 prescrit le
**CERFA 16702** depuis le 1er janvier 2025, alors que les anciens 13703 et
13404 restent affichés comme « en vigueur » sur le portail des formulaires. La
version en cours est **16702\*03, valide depuis le 1er juillet 2026**. Aucun
numéro n'est repris d'un article tiers.

**`/guides/refus-declaration-prealable/`** — aucun conseil juridique
personnalisé. Le guide expose les voies générales et renvoie à un professionnel
compétent. Aucun faux arrêté municipal en illustration, conformément au handoff.

---

## 5 · Maillage

### Ce que devient `/declarations-prealables/`

Elle devient le **hub des pages projets**, sans être réécrite et sans perdre son
positionnement. Une seule section est ajoutée : une grille de liens vers les
neuf pages projets. Son H1, son title, son chapô, son tableau de seuils et sa
FAQ ne sont pas touchés.

### Les liens réciproques posés

| Depuis | Vers |
|---|---|
| `/declarations-prealables/` | les 9 pages projets |
| chaque page projet | le guide correspondant, `/tarifs/`, le formulaire, 1 à 2 nouveaux guides |
| chaque nouveau guide « pièce » | `/guides/pieces-declaration-prealable/` et la page projet la plus proche |
| `/guides/pieces-declaration-prealable/` | les 4 autres guides pièces |
| guide « emprise / surface » | guide « DP ou permis », guide piscine/garage/carport |
| guide « architecte 150 m² » | guide extension, page extension |
| guide « CERFA » | `/declarations-prealables/`, `/permis-de-construire/` |
| guide « pièces complémentaires » | guide « 7 erreurs », guide « délais » |

**`/tarifs/` reste atteinte par un CTA secondaire sur chaque page projet**, pas
par des liens dans le corps : l'intention prix est isolée depuis le lot C.

---

## 6 · Tarifs affichés

Repris de `/tarifs/`, jamais recalculés :

| Prestation | À partir de |
|---|---|
| DP simple | 189 € |
| DP standard | 249 € |
| DP importante | 549 € |
| PC simple | 449 € |
| PC extension | 649 € |
| PC maison individuelle | 849 € |
| Conception personnalisée | 449 € |
| Supplément ABF | 80 € |

Chaque page projet affiche **le seul forfait pertinent pour son projet**, avec
la mention « à partir de » et le renvoi à `/tarifs/`. Aucune page n'invente un
montant, aucune ne promet un prix ferme sans devis.

---

## 7 · Ce que ce lot ne fait pas

- **Aucune page locale par ville.** Aucune génération automatisée.
- **Aucune réécriture** de l'accueil, du hero validé, de `/tarifs/`, ni des six
  guides publiés.
- **Aucune promesse d'acceptation** d'un dossier par la mairie, sur aucune des
  21 pages.
- **Aucun visuel généré** : le kit `urbizen-visuels-seo-professionnels` est la
  seule source, et les planches DP du dépôt sont déjà celles du kit, à l'octet
  près.
