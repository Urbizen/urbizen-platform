# Audit SEO technique — urbizen.fr

**Date du relevé : 13 août 2026.** Branche `audit/seo-foundation`.
Audit **en lecture seule** : aucune modification du site, aucune option touchée,
aucun contenu réécrit. Tout ce qui suit est mesuré, pas estimé.

## Méthode

- Crawl depuis l'accueil (profondeur ≤ 4) plus reprise de toutes les URL du plan
  de site, en `GET` sans suivi de redirection pour voir les 3xx.
- Sondage séparé des URL WordPress techniques et des variantes d'adresse.
- Lecture directe des options WordPress et AIOSEO en base, via `wp-cli`.
- Mesures de performance dans un Chromium réel, mobile 390 × 844, DPR 2.
- Les liens du bandeau de consentement sont retirés avant toute analyse de
  maillage : ce sont des commandes d'interface, pas du maillage éditorial.

**Limite à connaître.** Les mesures de performance sont des mesures *de
laboratoire*, sur une connexion non bridée. Le site n'a pas assez de trafic pour
disposer de données de terrain (CrUX), donc aucun INP réel n'est observable : la
valeur donnée plus bas est une approximation obtenue par événement synthétique.

---

## 1 · Inventaire

**19 URL HTML publiques répondent 200.** 19 pages publiées, 1 article, 104
fichiers joints (dont les pages redirigent, voir §2).

| Prof. | Indexable | Mots | Gabarit | URL |
|---:|---|---:|---|---|
| 0 | oui | 986 | `no-title` | `/` |
| 1 | oui | 650 | `page-conception` | `/conception/` |
| 1 | oui | 2 383 | `page-cgv` | `/conditions-generales-de-vente/` |
| 1 | oui | 1 724 | `page-declaration-prealable` | `/declarations-prealables/` |
| 1 | oui | 721 | `page-mentions-legales` | `/mentions-legales/` |
| 1 | oui | 1 909 | `page-permis-de-construire` | `/permis-de-construire/` |
| 1 | oui | 1 506 | `page-confidentialite` | `/politique-de-confidentialite/` |
| 1 | oui | 1 247 | `page-tarifs` | `/tarifs/` |
| 2 | oui | 621 | `page-formulaire-conception` | `/formulaire-conception/` |
| 2 | oui | **0** | `page-formulaire-declaration-prealable` | `/formulaire-declaration-prealable/` |
| 2 | oui | **0** | `page-formulaire-permis-de-construire` | `/formulaire-permis-de-construire/` |
| — | oui | 77 | `no-title` | `/autres-projets/` |
| — | oui | 137 | `no-title` | `/commander-un-dossier/` |
| — | oui | 51 | `no-title` | `/contact/` |
| — | oui | 39 | `no-title` | `/espace-professionnels/` |
| — | oui | 24 | (défaut) | `/category/uncategorized/` |
| — | oui | 2 | (défaut) | `/hello-world/` |
| — | oui | 1 | (défaut) | `/shop/` |
| — | oui | 0 | (défaut) | `/author/contact-urbizengmail-com/` |

« Prof. — » signifie **non atteignable depuis l'accueil** : 8 des 19 pages
indexables sont orphelines de tout lien éditorial, dont 4 qui n'ont aucun lien
entrant du tout.

### Titles, descriptions, H1

| URL | lg. title | lg. desc | H1 |
|---|---:|---:|---:|
| `/` | 54 | 168 | 1 |
| `/tarifs/` | **62** | 142 | 1 |
| `/declarations-prealables/` | 39 | 163 | 1 |
| `/permis-de-construire/` | 22 | **382** | 1 |
| `/conception/` | 12 | **absente** | 1 |
| `/conditions-generales-de-vente/` | 39 | 154 | 1 |
| `/mentions-legales/` | 26 | 147 | 1 |
| `/politique-de-confidentialite/` | 38 | 149 | 1 |
| `/commander-un-dossier/` | 22 | **23** | 1 |
| `/contact/` | 9 | 108 | 1 |
| `/espace-professionnels/` | 23 | **316** | **0** |
| `/autres-projets/` | 16 | **404** | **0** |
| `/formulaire-declaration-prealable/` | 37 | **absente** | **0** |
| `/formulaire-permis-de-construire/` | 36 | **absente** | **0** |
| `/formulaire-conception/` | 35 | **absente** | 1 |
| `/shop/` | 6 | **absente** | 1 |
| `/hello-world/` | 14 | 85 | 1 |
| `/category/uncategorized/` | 15 | **absente** | 1 |

### URL techniques WordPress

| URL | Code | Indexable |
|---|---:|---|
| `/author/contact-urbizengmail-com/` | 200 | **oui** |
| `/category/uncategorized/` | 200 | **oui** |
| `/2026/` | 200 | **oui** |
| `/2026/05/` | 200 | **oui** |
| `/?s=…` | 200 | non (`noindex`) |
| `/page/2/` | 200 | non (`noindex`) |
| `/feed/`, `/comments/feed/` | 200 | `noindexFeed` actif |
| `/author/admin/` | 404 | — |
| `/blog/` | 404 | — |
| `?attachment_id=…` | 301 → fichier | — |

Les pages de fichiers joints redirigent vers le fichier : le comportement par
défaut d'AIOSEO fait son office, les 104 médias ne créent pas 104 URL indexables.

---

## 2 · Crawl et indexabilité

**`robots.txt`** — correct et minimal : `Disallow: /wp-admin/`, `Allow` sur
`admin-ajax.php`, deux plans de site déclarés. Aucun blocage involontaire.

**Plan de site** — `sitemap.xml` (AIOSEO 5.0.0.1) → `post-sitemap.xml`,
`page-sitemap.xml`, `category-sitemap.xml`. **18 URL**, toutes en 200. Archives
d'auteur et de date exclues du plan de site (`sitemap.general.author = false`,
`date = false`) — mais **elles restent indexables**, ce qui est le pire des deux
mondes : Google les trouve par les liens, sans qu'elles soient déclarées.

**URL indexables absentes du plan de site** — aucune de conséquence : seule
`https://urbizen.fr` (sans barre finale) manque, et sa canonique pointe déjà sur
`https://urbizen.fr/`.

**URL inutiles indexables — 6.** `/shop/`, `/hello-world/`,
`/category/uncategorized/`, `/2026/`, `/2026/05/`,
`/author/contact-urbizengmail-com/`.

**Redirections** — aucune chaîne. Tous les cas testés résolvent en **un seul
saut**, sauf `http://www.` qui en demande deux (protocole puis hôte), ce qui est
normal.

| Cas | Résultat |
|---|---|
| `http://urbizen.fr/` | 301 → `https://urbizen.fr/` (1 saut) |
| `http://www.urbizen.fr/` | → `https://urbizen.fr/` (2 sauts) |
| `https://www.urbizen.fr/` | 301 → `https://urbizen.fr/` (1 saut) |
| `/tarifs` sans barre finale | 301 → `/tarifs/` (1 saut) |
| `/index.php/tarifs/` | 301 → `/tarifs/` (1 saut) |
| `/Tarifs/` (casse haute) | **200 sans redirection**, canonique `/tarifs/` |
| `/tarifs/?utm_source=test` | 200, canonique `/tarifs/` |

**Canonicals** — auto-référentes et cohérentes avec `og:url` sur **19/19** pages.
Aucune incohérence. La casse haute et les paramètres parasites sont neutralisés
par la canonique, pas par une redirection : correct, quoique moins net.

**Liens internes cassés — 2**, tous deux depuis des pages orphelines :

| Depuis | Vers | Ancre | Code |
|---|---|---|---|
| `/espace-professionnels/` | `/comment-ca-marche/` | « Contactez-nous », « Accéder » | **404** |
| `/autres-projets/` | `/panneaux-solaires/` | « Envoyer » | **404** |

---

## 3 · On-page

**Titles dupliqués — 1 paire**, sans gravité : `https://urbizen.fr` et
`https://urbizen.fr/` partagent le même title, et la canonique tranche.

**Le titre de site WordPress est vide.** `blogname` est une chaîne vide, alors
que le format AIOSEO global est `#site_title #separator_sa #tagline`.
Conséquence directe et mesurée : **13 des 19 titles se terminent par « - »**,
un séparateur suivi de rien.

> `Permis De Construire -` · `Conception -` · `Shop -` · `Hello world! -` ·
> `2026 -` · `contact.urbizen@gmail.com -`

**Descriptions** — 6 absentes (`/conception/`, les trois pages de formulaire,
`/category/uncategorized/`, `/shop/`). 3 sont générées automatiquement à partir
des premiers mots de la page et dépassent largement la longueur utile :
**404, 382 et 316 caractères** (`/autres-projets/`, `/permis-de-construire/`,
`/espace-professionnels/`). 1 est trop courte : **23 caractères**
(`/commander-un-dossier/`).

**H1 — 4 pages n'en ont aucun** : `/autres-projets/`,
`/espace-professionnels/`, `/formulaire-declaration-prealable/`,
`/formulaire-permis-de-construire/`. Aucune page n'a de H1 multiple.

**Hiérarchie H2/H3** — saine sur les pages travaillées. Les quatre pages
commerciales ont 8 à 12 H2 et 8 à 20 H3, structurés. Les pages héritées
(`/contact/`, `/commander-un-dossier/`) n'ont **aucun H2** malgré des H3, soit un
saut de niveau.

**Pages trop pauvres — 9 sous 300 mots**, dont deux à **zéro mot** : les pages
de formulaire DP et PC, qui ne sont qu'une coque autour d'une iframe, et sont
pourtant indexables.

| Mots | URL |
|---:|---|
| 0 | `/formulaire-declaration-prealable/` |
| 0 | `/formulaire-permis-de-construire/` |
| 1 | `/shop/` |
| 2 | `/hello-world/` |
| 24 | `/category/uncategorized/` |
| 39 | `/espace-professionnels/` |
| 51 | `/contact/` |
| 77 | `/autres-projets/` |
| 137 | `/commander-un-dossier/` |

**Contenu dupliqué** — aucun entre pages distinctes. Les quatre pages
commerciales partagent des blocs de charte (« Parlons de votre projet », « Prêt à
faire avancer votre projet ? ») mais leur corps est propre à chacune.

**Images** — **6 sur 56 sans `alt`**, toutes le même logo dans l'en-tête des
pages héritées. En revanche **43 sur 56 sans `width`/`height`** et **35 sur 56
sans `loading`** : c'est ce qui pèse, pas les `alt`.

**Ancres internes** — **aucune ancre générique** du type « en savoir plus » ou
« cliquez ici ». Les ancres sont descriptives et cohérentes
(« Déclaration préalable » ×17, « Permis de construire » ×17, « Tarifs » ×17).
C'est un point fort du site.

---

## 4 · Pages commerciales

### `/` — accueil · 986 mots

- **Title** `Déclaration préalable – Permis de construire - UrbiZen` (54 c.)
- **H1** « Vos démarches d'urbanisme, simplement. »
- **Intention principale** : navigationnelle et transactionnelle mixte.
  La page essaie de couvrir DP *et* PC *et* conception dans un seul title.
- **Intentions secondaires** : « qui prépare mon dossier d'urbanisme »,
  « combien ça coûte », « comment ça se passe ».
- **Mot-clé réellement ciblé aujourd'hui** : aucun de façon nette. Le title
  juxtapose deux requêtes sans en travailler une.
- **Maillage** : 8 destinations internes, 16 pages pointent vers elle.
- **CTA** : aucun lien direct vers un formulaire ou le contact depuis l'accueil.
  Les parcours passent par les pages intermédiaires.
- **Manque** : une preuve locale (zone d'intervention), un signal de confiance
  chiffré, un accès direct au formulaire depuis le hero.

### `/declarations-prealables/` · 1 724 mots

- **Title** `Déclaration Préalable à partir de 149€-` (39 c.) — **prix faux, voir §8**
- **H1** « La déclaration préalable de travaux, sans prise de tête. »
- **Intention principale** : informationnelle forte (« c'est quoi », « quels
  projets », « quelles pièces ») avec bascule transactionnelle en fin de page.
- **Intentions secondaires** : délais d'instruction, motifs de rejet, pièces
  DP1–DP8, seuils de surface.
- **Mot-clé ciblé** : « déclaration préalable de travaux » — cohérent.
- **Maillage** : 6 liens vers son formulaire, 5 vers le PC, 3 vers les tarifs.
  17 pages pointent vers elle.
- **CTA** : « Démarrer ma déclaration », « Accéder au formulaire », « Commencer ».
- **Manque** : le slug est au pluriel (`/declarations-prealables/`) alors que la
  requête est au singulier ; aucune déclinaison par type de projet.

### `/permis-de-construire/` · 1 909 mots

- **Title** `Permis De Construire -` (22 c.) — **title brut, non travaillé**
- **Description** générée automatiquement, **382 caractères**
- **H1** « Votre permis de construire, préparé de A à Z. »
- **Intention principale** : informationnelle puis transactionnelle, identique
  en structure à la page DP.
- **Intentions secondaires** : seuil des 150 m² et recours à l'architecte,
  surface de plancher / emprise au sol, délais.
- **Mot-clé ciblé** : « permis de construire », mais le title ne fait que
  répéter le nom de la page avec une casse de titre anglaise.
- **Maillage** : 17 entrants, 6 liens vers son formulaire.
- **CTA** : « Démarrer mon permis de construire », « Accéder au formulaire ».
- **Manque** : le title et la description sont les deux seuls éléments non
  travaillés d'une page par ailleurs la plus riche du site.

### `/conception/` · 650 mots

- **Title** `Conception -` (12 c.) — **le plus faible du site**
- **Description** **absente**
- **H1** « Donnez forme à votre projet avec des plans pensés pour vous »
- **Intention principale** : transactionnelle (« faire faire des plans »).
- **Intentions secondaires** : plan de maison sur mesure, rendu 3D, plans pour
  dossier d'urbanisme.
- **Mot-clé ciblé** : aucun. « Conception » seul ne correspond à aucune requête
  d'urbanisme.
- **Maillage** : 11 entrants — la moins liée des quatre pages commerciales.
- **CTA** : « Démarrer ma conception sur mesure », « Demander le plan de cette
  maison → ».
- **Manque** : c'est la page la plus courte et la moins bien intitulée alors
  qu'elle porte la prestation la plus chère (449 €).

### `/tarifs/` · 1 247 mots

- **Title** `tarifs pour une déclaration de travaux et permis de construire`
  (62 c., **initiale en minuscule**)
- **H1** « Des tarifs clairs pour votre projet d'urbanisme »
- **Intention principale** : commerciale comparative (« combien coûte une
  déclaration préalable »).
- **Intentions secondaires** : prix permis de construire, prix plans, ce qui est
  inclus, options.
- **Mot-clé ciblé** : « tarif déclaration préalable / permis de construire » —
  correct, c'est la page la mieux ciblée du site.
- **Maillage** : 17 entrants, 10 destinations.
- **CTA** : trois, dont « Estimer mon projet ».
- **Grille en vigueur** : DP 189 € (projet simple) / 249 € (courant) /
  549 € (création de surface) · PC 449 € / 649 € / 849 € · conception 449 € ·
  option 80 €.
- **Manque** : aucun balisage `Offer` ni `Service` sur des prix pourtant
  explicites et structurés.

---

## 5 · Données structurées

Un seul émetteur : **AIOSEO**. Le thème n'émet aucun JSON-LD — **aucun doublon**.
Un bloc `@graph` par page, 19 pages sur 19.

| Type | Occurrences | État |
|---|---:|---|
| `Organization` | 19 | **nom erroné** |
| `WebSite` | 19 | **sans `name`** |
| `WebPage` | 18 | correct |
| `BreadcrumbList` | 19 | libellé racine en anglais |
| `CollectionPage` | 1 | `/category/uncategorized/` |
| `BlogPosting` + `Person` | 1 | `/hello-world/` |

**`Organization.name` vaut « Votre dossier d'urbanisme en toute tranquillité »** —
c'est le slogan, pas la marque. Cause : `organizationName` est réglé sur
`#site_title #tagline` et `#site_title` est vide.

**`WebSite` n'a pas de propriété `name`** : `websiteName` vaut `#site_title`,
donc rien.

**`BreadcrumbList`** — le premier maillon s'appelle **« Home »** sur un site
entièrement francophone (`inLanguage: fr-FR`).

**Absents** : aucun `LocalBusiness` / `ProfessionalService` (aucune zone
d'intervention déclarée), aucun `Service`, aucune `Offer` malgré une grille
tarifaire structurée, aucune `FAQPage` malgré **21 questions/réponses** déjà
balisées en `<details>` sur quatre pages (6 sur `/tarifs/`, 5 sur chacune des
trois autres).

---

## 6 · Blog et contenu

**Il n'y a pas de blog.** Inventaire exhaustif :

- **1 article publié** : `Hello world!`, l'article de démonstration de
  WordPress, 2 mots, publié le 21 mai 2026, jamais supprimé — et **indexable**.
- **1 catégorie** : `Uncategorized`, en anglais, 1 article.
- **0 étiquette.**
- Aucune page ne sert de page d'articles (`page_for_posts = 0`), `/blog/` répond
  404.

Classement par cluster demandé :

| Cluster | Articles | Trou de contenu |
|---|---:|---|
| Déclaration préalable | **0** | total |
| Permis de construire | **0** | total |
| Piscine | **0** | total |
| Extension | **0** | total |
| Panneaux solaires | **0** | total |
| Abris / garages | **0** | total |
| ABF | **0** | total |
| Urbanisme pratique | **0** | total |

**Cannibalisation : aucune**, faute de contenu à cannibaliser. Le seul
chevauchement possible est entre `/declarations-prealables/` et
`/permis-de-construire/`, qui partagent une structure identique mais traitent de
deux démarches distinctes — pas de cannibalisation réelle.

Deux signaux montrent qu'un contenu de ce type a existé ou était prévu : les
liens morts vers `/panneaux-solaires/` et `/comment-ca-marche/`, et la mention
« panneaux solaires, garage » dans la description de l'accueil.

---

## 7 · Performance

Mesures en laboratoire, Chromium, mobile 390 × 844, connexion non bridée.

| Page | TTFB | FCP | LCP | CLS | INP approché | Requêtes | Poids |
|---|---:|---:|---:|---:|---:|---:|---:|
| `/` | 152 ms | 444 ms | **708 ms** (DIV) | **0,001** | 0 ms | 49 | **1 453 Ko** |
| `/tarifs/` | 95 ms | 336 ms | **460 ms** (DIV) | **0,028** | 0 ms | 40 | 820 Ko |
| `/declarations-prealables/` | 85 ms | 404 ms | **404 ms** (P) | 0,011 | 0 ms | 40 | 820 Ko |
| `/permis-de-construire/` | 98 ms | 424 ms | **424 ms** (P) | **0** | 0 ms | 40 | 820 Ko |
| `/conception/` | 418 ms | 724 ms | **816 ms** (DIV) | **0** | 0 ms | 45 | 1 264 Ko |

Les seuils Core Web Vitals sont tenus avec une marge confortable en laboratoire.
Une tâche longue unique de 54–56 ms est observée sur chaque page.

### Répartition du poids — `/tarifs/`, 820 Ko

| Type | Requêtes | Poids | Part |
|---|---:|---:|---:|
| **Polices** | 4 | **443 Ko** | **54 %** |
| Scripts | 17 | 298 Ko | 36 % |
| Feuilles de style | 14 | 73 Ko | 9 % |
| Images | 1 | 5 Ko | < 1 % |

### Le poste dominant : les polices du thème parent

| Fichier | Poids | Origine |
|---|---:|---|
| `OpenSans-Variable.ttf` | **332 Ko** | thème parent Hostinger |
| `IBMPlexMono-Regular.ttf` | **50 Ko** | thème parent Hostinger |
| `ibm-plex-sans-latin.woff2` | 40 Ko | thème enfant Urbizen |
| `space-grotesk-latin.woff2` | 22 Ko | thème enfant Urbizen |

**382 Ko sur 443 viennent du thème parent, en `.ttf` non compressé.** Open Sans
n'appartient pas à la charte Urbizen et n'est utilisée nulle part dans la
maquette ; IBM Plex Mono est chargée **deux fois**, une fois en `.ttf` de 50 Ko
par le parent et une fois en `.woff2` de 10 Ko par l'enfant.

C'est, et de loin, le premier gisement de performance : **47 % du poids de
`/tarifs/`** pour des polices dont une seule est utilisée.

### Scripts tiers

| Hôte | Requêtes | Poids |
|---|---:|---:|
| `www.googletagmanager.com` | 1 | **166 Ko** |
| `region1.google-analytics.com` | 2 | < 1 Ko |

Un seul tiers pesant, et il n'est chargé qu'après consentement. Aucun autre
domaine externe.

### Ressources bloquantes

**15 feuilles de style** et **2 scripts** bloquants dans le `<head>` de
l'accueil ; 14 CSS sur les autres pages. Les deux scripts sont
`jquery.min.js` (29 Ko) et `jquery-migrate.min.js` (5 Ko), chargés sans `defer`.

### Images

Aucune image au-dessus de 150 Ko sur `/tarifs/`. Sur l'accueil, deux fichiers à
153 et 151 Ko. Le vrai défaut est le **surdimensionnement** :

| Rapport | Servie | Affichée | Fichier |
|---:|---|---|---|
| ×3,7 | 430 × 120 | 115 × 32 | `logo-urbizen.png` |
| ×2,7 | 960 × 640 | 352 × 234 | `extension-maison.webp` |
| ×2,7 | 960 × 640 | 352 × 234 | `plu-terrain.webp` |
| ×2,7 | 960 × 640 | 352 × 234 | `piscine-garage-carport.webp` |
| ×2,7 | 960 × 640 | 352 × 234 | `autorisation-dp-permis.webp` |
| ×2,7 | 960 × 640 | 352 × 234 | `erreurs-dossier-urbanisme.webp` |

**8 images servies au moins deux fois plus grandes que leur affichage.** Aucun
attribut `srcset` n'est employé sur ces visuels.

---

## 8 · Anomalies héritées

Balayage des huit pages principales servies en production.

| Recherche | Résultat |
|---|---|
| Ancien slug `refund_returns` | **aucune occurrence** |
| Ancien slug `privacy-policy` (hors classe `<body>`) | **aucune occurrence** |
| Chatway | **1 occurrence, volontaire** : la phrase de la politique de confidentialité qui indique que le service n'est plus utilisé |
| AdSense (`adsbygoogle`, `googlesyndication`, `ca-pub-`) | **aucune occurrence** sur aucune page |
| URL mortes | **2**, listées au §2 |
| Anciens tarifs | **1, sérieuse — voir ci-dessous** |

### Le prix de 149 € n'existe plus

`/declarations-prealables/` annonce **« à partir de 149€ »** dans son `<title>`,
sa `meta description` et son `og:title` :

> `Déclaration Préalable à partir de 149€-`
>
> « Obtenez votre déclaration préalable de travaux prête à déposer, préparée à
> distance avec CERFA, plans et pièces graphiques adaptés à votre projet **à
> partir de 149€**. »

Or ce montant **n'apparaît nulle part dans le corps de la page**, et la grille en
vigueur sur `/tarifs/` commence à **189 €** pour un projet simple et **249 €**
pour une déclaration préalable courante. La description de `/tarifs/` annonce
d'ailleurs correctement « dès 189 € ».

C'est donc un prix périmé de **40 à 100 € en dessous du réel**, affiché aux
internautes dans les résultats de recherche, sur la page qui porte la démarche la
plus demandée. Un visiteur qui clique découvre un tarif supérieur à celui qui l'a
fait cliquer.

### L'adresse de courriel de la propriétaire est indexable

`/author/contact-urbizengmail-com/` répond **200**, est **indexable**, et son
`<title>` est :

> `contact.urbizen@gmail.com -`

L'adresse figure aussi dans le corps de la page. WordPress a construit le slug
d'auteur à partir de l'identifiant de connexion, qui est l'adresse
elle-même. Cette page est donc éligible à l'affichage dans Google avec l'adresse
personnelle en titre de résultat — et elle est offerte aux moissonneurs
d'adresses.

---

## 9 · Classement des problèmes

### P0 — grave

| # | Problème | Mesure |
|---|---|---|
| 1 | **Prix périmé de 149 € dans le title et la description** de `/declarations-prealables/` | réel : 189 € / 249 € |
| 2 | **Adresse de courriel de la propriétaire indexable** sur `/author/contact-urbizengmail-com/` | 200, indexable, adresse en `<title>` |

### P1 — impact SEO important

| # | Problème | Mesure |
|---|---|---|
| 3 | **Titre de site WordPress vide** | 13 titles sur 19 finissent par « - » |
| 4 | `Organization.name` = le slogan, `WebSite` sans `name` | 19 pages |
| 5 | **Aucun contenu éditorial** : 8 clusters sur 8 vides | 1 article, celui de démonstration |
| 6 | **6 URL inutiles indexables** | `/shop/`, `/hello-world/`, `/category/uncategorized/`, `/2026/`, `/2026/05/`, `/author/…` |
| 7 | **Titles non travaillés** sur deux pages commerciales | `Permis De Construire -` (22 c.), `Conception -` (12 c.) |
| 8 | **Descriptions absentes ou générées** | 6 absentes, 3 auto-générées de 316 à 404 c. |
| 9 | **382 Ko de polices inutilisées** chargées sur chaque page | 47 % du poids de `/tarifs/` |
| 10 | **4 pages sans H1** | dont 2 pages de formulaire |
| 11 | **Pages de formulaire indexables à 0 mot** | 2 pages |
| 12 | **2 liens internes en 404** | `/comment-ca-marche/`, `/panneaux-solaires/` |
| 13 | **8 pages orphelines** du maillage éditorial | dont 4 sans aucun lien entrant |

### P2 — optimisation

| # | Problème | Mesure |
|---|---|---|
| 14 | Aucun `FAQPage` malgré des FAQ existantes | 21 `<details>` sur 4 pages |
| 15 | Aucun `Service`, `Offer` ni `LocalBusiness` | grille de 7 prix non balisée |
| 16 | Images servies 2 à 3,7 × trop grandes, sans `srcset` | 8 images |
| 17 | 43 images sur 56 sans `width`/`height` | risque de CLS futur |
| 18 | 15 CSS + jQuery bloquants dans le `<head>` | 34 Ko de JS bloquant |
| 19 | Fil d'Ariane « Home » en anglais | 19 pages |
| 20 | Title `/tarifs/` à 62 c. et en minuscule initiale | 1 page |
| 21 | Pages héritées pauvres et sans H2 | `/contact/` 51 mots, `/commander-un-dossier/` 137 mots |

### P3 — cosmétique

| # | Problème | Mesure |
|---|---|---|
| 22 | 6 images sans `alt` | toutes le même logo d'en-tête |
| 23 | Catégorie `Uncategorized` en anglais | 1 terme |
| 24 | `/Tarifs/` répond 200 en casse haute | canonique correcte, pas de redirection |
| 25 | 35 images sur 56 sans `loading` | poids déjà faible |

---

## 10 · Plan proposé, en lots indépendants

Chaque lot est autonome : il peut être validé, exécuté et déployé sans les
autres. Ordre proposé par rapport bénéfice / risque.

### Lot A — corrections d'urgence (P0)

Corriger le prix de 149 € dans les métadonnées de `/declarations-prealables/`.
Rendre l'archive d'auteur inaccessible, ou au minimum `noindex`, et découpler le
slug d'auteur de l'adresse de courriel.

*Touche : AIOSEO (1 page), option WordPress d'auteur. Aucun contenu réécrit.*

### Lot B — assainissement de l'index (P1)

Renseigner le titre de site. Traiter les 6 URL inutiles : suppression des pages
WooCommerce et de l'article de démonstration, `noindex` sur les archives de date
et de catégorie. Corriger les 2 liens morts. Décider du sort des pages orphelines
`/autres-projets/` et `/espace-professionnels/`.

*Effet attendu : l'index passe de 19 à 11 URL, toutes voulues.*

### Lot C — métadonnées des pages commerciales (P1)

Rédiger title et description pour `/permis-de-construire/`, `/conception/`,
`/tarifs/`, et les trois pages de formulaire. Décider si les pages de formulaire
doivent être indexables.

*Aucune réécriture de contenu, uniquement les métadonnées.*

### Lot D — allègement des polices (P1)

Empêcher le chargement des polices du thème parent, inutilisées par la charte.
Gain attendu : **382 Ko par page**, soit environ 47 % du poids de `/tarifs/`.

*Lot technique pur, mesurable avant / après, sans effet sur le contenu.*

### Lot E — données structurées (P2)

Corriger `Organization.name` et `WebSite.name`, franciser le fil d'Ariane,
ajouter `FAQPage` sur les quatre pages qui ont déjà les questions,
`Service` + `Offer` sur `/tarifs/`, et un `ProfessionalService` avec zone
d'intervention si la couverture géographique est arrêtée.

### Lot F — images (P2)

`srcset` sur les visuels servis 2 à 3,7 × trop grands, `width`/`height`
systématiques, `loading="lazy"` sous la ligne de flottaison.

### Lot G — stratégie éditoriale (P1, le plus long)

C'est le chantier de fond : **huit clusters entièrement vides**. Il suppose
d'abord un arbitrage — quelles requêtes viser, à quel rythme, avec quelle
capacité de rédaction — avant toute production. Les deux liens morts vers
`/panneaux-solaires/` et `/comment-ca-marche/` indiquent que la question s'est
déjà posée.

*Ce lot ne doit pas être lancé avant les lots A à D : publier sur des fondations
qui affichent un prix faux et une adresse personnelle serait prématuré.*

---

## Ce que cet audit n'a pas mesuré

- **Aucune donnée de terrain.** Pas de Search Console, pas de CrUX : le trafic
  est trop faible. Les Core Web Vitals ci-dessus sont des mesures de
  laboratoire sur connexion rapide, pas ce que vivent les visiteurs.
- **Aucune analyse de positions ni de volumes de recherche.** Les intentions du
  §4 sont déduites du contenu, pas d'un outil de mots-clés.
- **Aucun audit de netlinking externe.**
- **L'état d'indexation réel chez Google n'a pas été vérifié** : il demande un
  accès à la Search Console.
