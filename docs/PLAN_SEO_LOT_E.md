# Lot E — données structurées

**Audit, relevé du 13 août 2026, après les lots A à D.**
Aucune modification n'a été faite.

Quatorze pages servies ont été lues, pages hors index comprises, et toutes les
URL citées dans le JSON-LD ont été interrogées une à une.

---

## Ce qui est sain, et qu'il faut préserver

**Un seul émetteur.** AIOSEO produit un unique bloc `application/ld+json` par
page. Le thème n'en émet aucun. **Aucun doublon**, sur aucune des quatorze pages.

**Aucune URL morte.** Les seize URL citées dans le JSON-LD répondent toutes 200,
logo compris. C'est vrai aussi du seul `sameAs` de l'organisation.

**Le graphe est cohérent.** `WebPage.isPartOf` pointe sur `WebSite`,
`WebSite.publisher` sur `Organization`, `WebPage.breadcrumb` sur
`BreadcrumbList`. Les `@id` se répondent.

**`Organization.name` et `WebSite.name` valent « Urbizen »** depuis le lot B.

---

## 1 · `Person` — le problème est dormant, pas résolu

| | |
|---|---|
| **Servi aujourd'hui** | **aucun nœud `Person`, sur aucune des quatorze pages** |
| **Pourquoi c'est perfectible** | ce n'est pas une correction, c'est un effet de bord |

Le point que vous signalez — `Person.url` pointant sur
`/author/anais-bacarisse/`, en 404 depuis le lot A — **a disparu** parce que le
lot B a mis `/hello-world/` à la corbeille. C'était la seule page qui émettait un
`Person` : AIOSEO ne construit ce nœud que sur les articles.

Il reviendra au premier article publié. Vérifié dans le code d'AIOSEO,
`app/Common/Schema/Graphs/WebPage/PersonAuthor.php`, ligne 52 :

```php
$authorUrl = get_author_posts_url( $userId );
…
'@id' => $authorUrl . '#author',
```

`get_author_posts_url()` renvoie `/author/anais-bacarisse/`, que le thème sert en
404 — délibérément, pour ne plus exposer l'adresse de courriel. Le premier
article du lot G rouvrira donc l'anomalie, sans que personne ne s'en aperçoive.

**Correction proposée** — filtrer le graphe pour retirer `url` du nœud `Person`
et remplacer son `@id` par un identifiant qui ne soit pas une URL de page.
Un `Person` sans `url` reste valide : Google ne l'exige pas.

**Sans recréer d'archive d'auteur**, conformément à votre consigne : la page
resterait une donnée personnelle indexable, et c'est précisément ce que le lot A
a supprimé.

**Effet SEO attendu** — nul en positions. L'enjeu est d'éviter qu'un nœud du
graphe pointe vers une page absente, ce que les outils de test signalent et ce
qui affaiblit la confiance dans le reste du balisage.

**Méthode** — filtre `aioseo_schema_output`, exposé par le greffon en
`app/Common/Schema/Helpers.php` ligne 82. Il reçoit `$schema['@graph']` et rend
le graphe modifié.

**À faire maintenant ou au lot G ?** Maintenant : la correction est invisible
tant qu'aucun article n'existe, et elle sera en place le jour où le premier
paraîtra. L'inverse — corriger après publication — laisserait une fenêtre.

---

## 2 · `BreadcrumbList` — « Home » sur un site francophone

| | |
|---|---|
| **Servi aujourd'hui** | `"name": "Home"`, sur les quatorze pages |
| **Pourquoi c'est incorrect** | le site est déclaré `inLanguage: fr-FR` et `lang="fr-FR"` |

Ce n'est pas un défaut de traduction : la langue du site est bien `fr_FR` et le
fichier `all-in-one-seo-pack-fr_FR.mo` est présent. La chaîne vient de
`__( 'Home', 'all-in-one-seo-pack' )` dans `app/Common/Schema/Breadcrumb.php`
ligne 322, que la traduction française du greffon ne couvre pas.

Le réglage `breadcrumbs.homepageLabel`, qui vaut lui aussi « Home », **n'est pas
en cause** : il n'alimente que le fil d'Ariane visuel
(`app/Common/Breadcrumbs/Breadcrumbs.php` ligne 530), pas le schéma.

**Correction proposée** — « Accueil ».

**Effet SEO attendu** — mineur mais réel : le fil d'Ariane est repris tel quel
dans les résultats de recherche. Un « Home » sur un site français y détonne.

**Méthode** — filtre `aioseo_schema_breadcrumbs_home`, prévu pour cela par le
greffon à la ligne même où la chaîne est posée. Une ligne, aucun réglage à
mémoriser dans l'interface.

---

## 3 · `BreadcrumbList` — un `@id` sans barre finale

| | |
|---|---|
| **Servi aujourd'hui** | `"@id": "https://urbizen.fr#listItem"` et `"item": "https://urbizen.fr"` |
| **Pourquoi c'est perfectible** | l'URL canonique du site est `https://urbizen.fr/`, avec barre |

Le premier maillon du fil d'Ariane désigne l'accueil sans barre finale, alors que
le même nœud porte plus loin `"url": "https://urbizen.fr/"` — le greffon applique
`trailingslashit()` à l'un et pas à l'autre.

Les deux adresses répondent 200 et la canonique tranche, donc le risque de
duplication est nul. Reste une incohérence interne au graphe.

**Correction proposée** — normaliser sur la forme avec barre.

**Effet SEO attendu** — négligeable. À traiter parce que c'est peu coûteux dans
le même filtre que le reste, pas parce que cela rapporte.

**Méthode** — `aioseo_schema_output`, dans la même passe que le point 1.

---

## 4 · `Organization` — ce qui manque

`siteRepresents` vaut `organization` : le site se présente comme une
organisation, pas comme une personne. **C'est le bon choix et il ne change pas.**

**Pas de `LocalBusiness`**, conformément à votre consigne, et pour une raison de
fond : `LocalBusiness` décrit un établissement qui reçoit une clientèle à une
adresse et à des horaires. Urbizen prépare des dossiers à distance, partout en
France. Déclarer un `LocalBusiness` reviendrait à revendiquer une implantation
locale qui n'existe pas — un signal faux, que Google recoupe avec sa fiche
d'établissement.

### 4.1 · Description = slogan

| | |
|---|---|
| **Servi** | `"description": "Votre dossier d'urbanisme en toute tranquillité"` |
| **Origine** | `organizationDescription` vaut `#tagline` |

Le slogan dit une promesse, pas une activité. Une description d'organisation
devrait dire ce que fait l'entreprise, pour qui, et où.

**Correction proposée** — une phrase littérale, sans balise dynamique, du type :
« Préparation à distance de dossiers d'urbanisme — déclaration préalable, permis
de construire, plans et pièces graphiques — partout en France. »

**Effet SEO attendu** — indirect. La description d'organisation nourrit le
panneau de connaissance et l'appariement entité / requête ; elle ne se positionne
pas seule.

**Méthode** — réglage AIOSEO `searchAppearance.global.schema.organizationDescription`,
écrit en clair. Même mécanisme applicatif qu'aux lots A et C.

### 4.2 · `sameAs` chargé de paramètres de partage

| | |
|---|---|
| **Servi** | `https://www.facebook.com/profile.php?id=61591225628821&mibextid=…&rdid=…&share_url=…` |

L'URL répond 200, mais c'est un **lien de partage**, pas l'adresse canonique de
la page. Les paramètres `mibextid`, `rdid` et `share_url` sont des marqueurs de
session Facebook : ils peuvent expirer, et ils rendent l'identification de
l'entité moins nette.

**Correction proposée** — `https://www.facebook.com/profile.php?id=61591225628821`,
sans paramètres. À confirmer par vous : c'est la seule valeur de cet audit que je
ne peux pas vérifier sans ouvrir la page.

**Effet SEO attendu** — consolidation d'entité. `sameAs` sert à relier le site à
ses profils ; une URL stable le fait mieux qu'une URL de partage.

**Méthode** — réglage AIOSEO `social.profiles.urls.facebookPageUrl`.

### 4.3 · Champs disponibles et vides

AIOSEO expose, et laisse vides :

| Champ | Valeur | Remarque |
|---|---|---|
| `email` | vide | `contact@urbizen.fr`, déjà public au pied de page et dans les mentions légales |
| `foundingDate` | vide | date de début d'activité |
| `websiteAlternateName` | vide | sans objet ici, un seul nom |
| `numberOfEmployees` | vide | à laisser vide : entrepreneur individuel |

**Correction proposée** — renseigner `email`, et `foundingDate` si vous
souhaitez la publier. Laisser les deux autres vides.

**Effet SEO attendu** — faible et cumulatif : un nœud d'entité mieux rempli est
plus facilement recoupé.

**Méthode** — réglages AIOSEO du même bloc `schema`.

### 4.4 · Aucune adresse postale — et AIOSEO Free ne sait pas la déclarer

| | |
|---|---|
| **Servi** | aucun `address`, aucun `PostalAddress` |

Le bloc `schema` d'AIOSEO Free ne comporte **aucun champ d'adresse** : les clés
disponibles sont `websiteName`, `websiteAlternateName`, `siteRepresents`,
`person`, `organizationName`, `organizationDescription`, `organizationLogo`,
`personName`, `personLogo`, `phone`, `email`, `foundingDate`,
`numberOfEmployees`. C'est tout.

L'adresse est pourtant publiée dans les mentions légales et sur `/contact/` :
59 rue de Ponthieu, 75008 Paris.

**Correction proposée** — ajouter un `PostalAddress` à l'`Organization`, **sans**
en faire un `LocalBusiness`. Une organisation peut avoir un siège sans recevoir
de clientèle : la nuance est exactement celle que vous demandez de tenir.

**Effet SEO attendu** — consolidation d'entité, et cohérence avec ce que le site
publie déjà en clair. Aucun effet de référencement local, ce qui est voulu.

**Méthode** — filtre `aioseo_schema_output`. Aucun réglage ne le permet.

---

## 5 · Aucun `Service`, aucune `Offer`

| | |
|---|---|
| **Servi** | rien, alors que `/tarifs/` publie sept prix structurés |

La grille est explicite et lisible : DP 189 € / 249 € / 549 €, PC 449 € / 649 € /
849 €, conception 449 €, option 80 €.

**Correction proposée** — un `Service` par prestation, rattaché à
l'`Organization` par `provider`, avec `areaServed` « France » et une `Offer`
portant `priceCurrency: EUR` et `price`.

**Une réserve que je pose franchement** : cela réintroduit un montant dans une
donnée publiée automatiquement — exactement le mécanisme du P0 du lot A, où un
prix périmé est resté des mois dans Google. La différence est qu'un prix en
`Offer` peut être lu depuis la grille plutôt que recopié, ce qui supprime la
divergence à la source. **Si cette lecture depuis la source n'est pas possible
proprement, mieux vaut un `Service` sans `Offer`** : décrire la prestation sans
en figer le prix.

**Effet SEO attendu** — éligibilité aux résultats enrichis de service. Effet réel
mais non garanti : Google affiche ces enrichissements quand il le juge utile.

**Méthode** — filtre `aioseo_schema_output`, avec les montants lus dans le
gabarit `page-tarifs.html` ou dans une source unique dédiée.

---

## 6 · Aucun `FAQPage` — alors que les questions existent déjà

| | |
|---|---|
| **Servi** | rien |

**Vingt-deux questions** sont déjà balisées en `<details>` / `<summary>` dans les
gabarits :

| Page | Questions |
|---|---:|
| `/tarifs/` | 6 |
| `/declarations-prealables/` | 5 |
| `/permis-de-construire/` | 5 |
| `/conception/` | 5 |
| `/` | 1 |

Le contenu est écrit, structuré et visible. Seul le balisage manque.

**Correction proposée** — un `FAQPage` sur les quatre pages qui portent une
véritable rubrique de questions. **Pas sur l'accueil** : un unique `<details>`
n'est pas une FAQ, et le déclarer comme telle serait un balisage complaisant.

**Effet SEO attendu** — le plus tangible du lot, mais à tempérer : depuis août
2023, Google réserve l'affichage des FAQ enrichies aux sites d'autorité
reconnue. Le balisage reste utile à la compréhension de la page ; **il ne faut
pas en attendre des accordéons dans les résultats.**

**Méthode** — filtre `aioseo_schema_output`, en lisant les `<details>` du
gabarit, ou en déclarant les paires question / réponse dans une source unique.
Lire le gabarit évite la divergence entre ce qui est affiché et ce qui est
déclaré — divergence que Google sanctionne.

---

## 7 · Points mineurs relevés, sans correction proposée

**`WebPage` sans `primaryImageOfPage`.** Sans conséquence : les pages n'ont pas
d'image principale éditoriale.

**Le schéma est émis sur les pages `noindex`** — `/autres-projets/`,
`/commander-un-dossier/`, les trois formulaires, la catégorie vide. Sans effet :
une page non indexée n'a pas de résultat à enrichir. Le retirer coûterait plus
que cela ne rapporte.

**`CollectionPage` sur `/category/non-classe/`** : correct pour une archive, et la
page est `noindex`.

---

## 8 · Récapitulatif

| # | Anomalie | Gravité | Méthode |
|---|---|---|---|
| 1 | `Person.url` vers une page 404 — **dormant**, revient au premier article | à traiter avant le lot G | filtre `aioseo_schema_output` |
| 2 | Fil d'Ariane « Home » | mineure, visible en SERP | filtre `aioseo_schema_breadcrumbs_home` |
| 3 | `@id` d'accueil sans barre finale | cosmétique | filtre `aioseo_schema_output` |
| 4.1 | Description d'organisation = slogan | perfectible | réglage AIOSEO |
| 4.2 | `sameAs` avec paramètres de partage | perfectible | réglage AIOSEO |
| 4.3 | `email` et `foundingDate` vides | perfectible | réglages AIOSEO |
| 4.4 | Pas d'adresse postale | perfectible | filtre — aucun réglage ne l'expose |
| 5 | Aucun `Service` / `Offer` | opportunité | filtre |
| 6 | Aucun `FAQPage` malgré 22 questions | opportunité | filtre |

### Ordre proposé

1. **Le filtre unique** — points 1, 2 et 3. Correctif pur, sans arbitrage, et le
   point 1 doit être en place avant le premier article.
2. **Les réglages** — points 4.1 à 4.3, après vos décisions sur la description,
   l'URL Facebook et la date.
3. **L'adresse** — point 4.4, dans le même filtre, une fois confirmé qu'elle doit
   figurer dans le schéma.
4. **`Service` et `FAQPage`** — points 5 et 6, à décider ensemble : ce sont les
   seuls qui ajoutent des affirmations nouvelles plutôt que de corriger
   l'existant.

---

## 9 · Ce que cet audit n'a pas fait

- **Aucun test avec l'outil de Google.** Le validateur de résultats enrichis n'a
  pas été interrogé : la conformité au vocabulaire schema.org a été vérifiée à
  la lecture, pas par un outil.
- **Aucune vérification de ce que Google affiche réellement.** Sans Search
  Console, l'état d'indexation des données structurées est inconnu.
- **L'URL Facebook n'a pas été ouverte** : seul son code de réponse a été relevé.
