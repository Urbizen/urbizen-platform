# Bancs SEO

Non-régression des corrections issues de l'audit du 13 août 2026
(`docs/AUDIT_SEO.md`).

    ./run-all.sh                      # production
    ./run-all.sh https://autre.site   # autre cible

## Deux volets, et pourquoi

| Banc | Portée |
|---|---|
| `test-seo-p0.php` | le dépôt : le code qui supprime les archives d'auteur, la fidélité du script de correction |
| `test-seo-p0.mjs` | le site servi : métadonnées, JSON-LD, API REST, plan de site |

Les deux défauts P0 vivent **dans la base**, pas dans le dépôt : un prix écrit
dans une ligne d'AIOSEO, une identité écrite dans `wp_users`. Aucun contrôle sur
les fichiers ne peut les voir — d'où le volet en ligne.

Mais un volet en ligne seul serait vert si l'on retirait le filtre du thème tout
en laissant la base corrigée : la faille ne rouvrirait qu'au déploiement suivant.
D'où le volet statique. Aucun des deux ne suffit.

## Ce que le banc en ligne contrôle

**P0.1** — les six métadonnées de `/declarations-prealables/` sont renseignées et
ne contiennent **aucun montant**. La règle n'est pas « un prix à jour » mais
« aucun prix » : une métadonnée est permanente, un tarif ne l'est pas. Le JSON-LD
et le HTML complet sont balayés à leur tour, parce que `og_*` et `twitter_*`
héritent du titre quand ils valent `NULL` — c'est par là que le prix passait.

**P0.2** — l'archive d'auteur ne répond plus 200 et **ne redirige pas** ;
`?author=1` ne mène plus à une archive ; l'API REST publique ne contient ni
l'adresse de courriel ni l'ancien slug ; aucune page ne porte l'adresse ni de
lien `/author/` ; le plan de site n'annonce aucune archive d'auteur.

## Pièges rencontrés

Le paramètre anti-cache doit choisir son séparateur : sur `/?author=1`, un « ? »
ajouté en aveugle produit `/?author=1?nc=…`, que le serveur traite autrement. Le
contrôle passait alors au vert sans rien avoir vérifié.

## Codes de sortie

`0` conforme · `1` au moins un écart · `2` prérequis absent.
