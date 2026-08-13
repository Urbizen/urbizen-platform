<?php
/**
 * LOT E — réglages AIOSEO des données structurées. Plan : `docs/PLAN_SEO_LOT_E.md`.
 *
 *     wp eval-file scripts/seo-lot-e.php              # simulation, n'écrit rien
 *     wp eval-file scripts/seo-lot-e.php appliquer    # écrit
 *
 * CE QUE CE SCRIPT FAIT, ET CE QU'IL NE FAIT PAS
 *
 * Il ne touche qu'aux valeurs qu'AIOSEO expose en réglage. Le reste du lot —
 * `Person` sans `url`, adresse postale, fil d'Ariane en français — passe par des
 * filtres du thème enfant, parce qu'aucun réglage ne les permet.
 *
 * `foundingDate` reste vide : aucune date de fondation n'est confirmée, et en
 * déduire une reviendrait à publier une donnée inventée.
 *
 * `numberOfEmployees` reste vide : entrepreneur individuel.
 *
 * `siteRepresents` reste `organization`, jamais `person` : le site présente
 * l'entreprise, pas sa dirigeante.
 *
 * POURQUOI LA DESCRIPTION EST ÉCRITE EN CLAIR
 *
 * Elle valait `#tagline`, soit le slogan — « Votre dossier d'urbanisme en toute
 * tranquillité ». Un slogan dit une promesse ; une description d'organisation
 * doit dire l'activité, pour qui, et où. Elle est donc littérale, comme les
 * métadonnées des lots A et C, et pour la même raison : ne dépendre d'aucune
 * option de site.
 *
 * POURQUOI L'URL FACEBOOK EST NETTOYÉE
 *
 * Elle portait `mibextid`, `rdid` et `share_url` : des marqueurs de session
 * ajoutés par un bouton de partage. Ils peuvent expirer, et ils brouillent
 * l'identification de l'entité, qui est tout l'objet de `sameAs`.
 *
 * @package Urbizen\Scripts
 */

defined( 'ABSPATH' ) || exit;

/*
 * UN PIÈGE DE LECTURE, MESURÉ
 *
 * Mémoriser un sous-objet d'options d'AIOSEO et y lire plusieurs propriétés ne
 * marche que pour la première : les suivantes reviennent `NULL`, sans erreur.
 * Vérifié sur cette installation —
 *
 *     $s = aioseo()->options->searchAppearance->global->schema;
 *     $s->phone          → '+33664895815'
 *     $s->siteRepresents → NULL          alors que l'option vaut 'organization'
 *
 * — tandis que le chemin complet rend les bonnes valeurs à chaque fois. Toutes
 * les lectures ci-dessous passent donc par le chemin complet. Un script qui
 * l'ignorerait afficherait « (vide) » pour des valeurs bien présentes, et
 * pourrait conclure à tort qu'il faut les écrire.
 */

$appliquer = in_array( 'appliquer', (array) ( $args ?? array() ), true );

echo $appliquer
	? "MODE : APPLICATION — la base va être modifiée\n\n"
	: "MODE : SIMULATION — aucune écriture (ajouter « appliquer » pour écrire)\n\n";

if ( ! function_exists( 'aioseo' ) ) {
	echo "ARRÊT : AIOSEO introuvable.\n";
	return;
}

$description = 'Urbizen prépare à distance des dossiers d\'urbanisme pour les particuliers et professionnels partout en France : déclarations préalables, permis de construire et plans sur mesure.';

/* ---------------------------------------------------------------------------
 * 1 · Description de l'organisation
 * ------------------------------------------------------------------------ */

echo "══ 1 · description de l'organisation ══\n";
echo sprintf( "  avant : %s\n", aioseo()->options->searchAppearance->global->schema->organizationDescription );
echo sprintf( "  après : %s\n", $description );
echo sprintf( "  longueur : %d caractères\n", mb_strlen( $description ) );

if ( $appliquer ) {
	aioseo()->options->searchAppearance->global->schema->organizationDescription = $description;
	echo "  → enregistré\n";
}

/* ---------------------------------------------------------------------------
 * 2 · Courriel public
 * ------------------------------------------------------------------------ */

echo "\n══ 2 · courriel de contact ══\n";

$legales = function_exists( 'urbizen_child_donnees_legales' ) ? urbizen_child_donnees_legales() : array();
$courriel = $legales['email'] ?? '';

echo sprintf( "  avant : %s\n", null === aioseo()->options->searchAppearance->global->schema->email || '' === aioseo()->options->searchAppearance->global->schema->email ? '(vide)' : aioseo()->options->searchAppearance->global->schema->email );
echo sprintf( "  après : %s\n", '' === $courriel ? '(source légale illisible — non modifié)' : $courriel );
echo "  lu dans urbizen_child_donnees_legales(), pas recopié.\n";

if ( $appliquer && '' !== $courriel ) {
	aioseo()->options->searchAppearance->global->schema->email = $courriel;
	echo "  → enregistré\n";
}

/* ---------------------------------------------------------------------------
 * 3 · Téléphone — contrôle seulement
 * ------------------------------------------------------------------------ */

echo "\n══ 3 · téléphone ══\n";
echo sprintf( "  valeur : %s\n", aioseo()->options->searchAppearance->global->schema->phone );
echo sprintf(
	"  cohérent avec la source légale (%s) : %s\n",
	$legales['telephone_lien'] ?? '?',
	( aioseo()->options->searchAppearance->global->schema->phone === ( $legales['telephone_lien'] ?? null ) ) ? 'oui' : 'NON — à vérifier'
);

/* ---------------------------------------------------------------------------
 * 4 · sameAs Facebook
 * ------------------------------------------------------------------------ */

echo "\n══ 4 · profil Facebook ══\n";

$avant_fb = aioseo()->options->social->profiles->urls->facebookPageUrl;
$apres_fb = $avant_fb;

if ( is_string( $avant_fb ) && '' !== $avant_fb ) {
	// On garde le chemin et le seul paramètre qui identifie la page ; tout le
	// reste est du marquage de partage.
	$morceaux = wp_parse_url( $avant_fb );
	$requete  = array();

	if ( ! empty( $morceaux['query'] ) ) {
		parse_str( $morceaux['query'], $params );
		if ( isset( $params['id'] ) ) {
			$requete['id'] = $params['id'];
		}
	}

	$apres_fb = ( $morceaux['scheme'] ?? 'https' ) . '://' . ( $morceaux['host'] ?? '' ) . ( $morceaux['path'] ?? '' );

	if ( $requete ) {
		$apres_fb .= '?' . http_build_query( $requete );
	}
}

echo sprintf( "  avant : %s\n", $avant_fb );
echo sprintf( "  après : %s\n", $apres_fb );

$parasites = array( 'mibextid', 'rdid', 'share_url', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign' );
$restants  = array();

foreach ( $parasites as $p ) {
	if ( false !== strpos( (string) $apres_fb, $p ) ) {
		$restants[] = $p;
	}
}

echo sprintf( "  paramètres de suivi restants : %s\n", $restants ? implode( ', ', $restants ) : 'aucun' );

if ( $appliquer && $apres_fb !== $avant_fb ) {
	aioseo()->options->social->profiles->urls->facebookPageUrl = $apres_fb;
	echo "  → enregistré\n";
}

/* ---------------------------------------------------------------------------
 * 5 · Libellé d'accueil du fil d'Ariane
 *
 * Il valait « Home » sur un site francophone. Ce n'est pas un défaut de
 * traduction : la langue est `fr_FR` et le fichier français d'AIOSEO est
 * installé. C'est une valeur stockée en anglais à l'installation.
 *
 * Le filtre `aioseo_schema_breadcrumbs_home`, qu'AIOSEO expose pourtant à
 * l'endroit exact où la chaîne est écrite, ne produit aucun effet : essayé,
 * mesuré, « Home » restait. Le graphe ne passe pas par là —
 * `Schema/Graphs/BreadcrumbList.php` ligne 23 lit
 * `aioseo()->breadcrumbs->frontend->getBreadcrumbs()`, donc le même fil
 * d'Ariane que celui affiché, dont le libellé vient de cette option.
 * ------------------------------------------------------------------------ */

echo "\n══ 5 · libellé d'accueil du fil d'Ariane ══\n";
echo sprintf( "  avant : %s\n", aioseo()->options->breadcrumbs->homepageLabel );
echo "  après : Accueil\n";

if ( $appliquer ) {
	aioseo()->options->breadcrumbs->homepageLabel = 'Accueil';
	echo "  → enregistré\n";
}

/* ---------------------------------------------------------------------------
 * 6 · Ce qui reste volontairement vide
 * ------------------------------------------------------------------------ */

echo "\n══ 6 · laissés vides, délibérément ══\n";
echo sprintf( "  foundingDate       : %s — aucune date confirmée\n", null === aioseo()->options->searchAppearance->global->schema->foundingDate ? '(vide)' : aioseo()->options->searchAppearance->global->schema->foundingDate );
echo sprintf( "  websiteAlternateName : %s — un seul nom\n", null === aioseo()->options->searchAppearance->global->schema->websiteAlternateName ? '(vide)' : aioseo()->options->searchAppearance->global->schema->websiteAlternateName );
echo sprintf( "  siteRepresents     : %s — inchangé\n", aioseo()->options->searchAppearance->global->schema->siteRepresents );

echo "\n" . ( $appliquer ? "TERMINÉ. Purger les caches, puis lancer tests/seo/run-all.sh.\n" : "Rien n'a été écrit.\n" );
