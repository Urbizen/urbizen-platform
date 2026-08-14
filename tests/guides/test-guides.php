<?php
/**
 * Banc des gabarits de guides — contrat de structure.
 *
 * CE QU'IL PROTÈGE
 *
 * Le thème enfant n'avait aucun gabarit d'article. Sans `home.html`,
 * `single.html` et `archive.html`, WordPress retombe sur ceux du thème parent
 * Hostinger : son en-tête (le menu mort à quatre entrées), son pied de page, et
 * un `<h1>` écrit en dur — « Latest posts », en anglais. Un guide publié
 * aujourd'hui serait donc servi avec l'apparence d'un autre site.
 *
 * Ce banc vérifie que ces trois gabarits existent, qu'ils passent par les
 * template parts Urbizen, et qu'aucun ne laisse entrer une part du parent.
 *
 * CE QU'IL NE PEUT PAS VÉRIFIER
 *
 * Le rendu réel demande WordPress, une base et des articles. Il a été fait à
 * part, sur une installation locale, et les captures sont dans la PR. Ici, on
 * fige ce qui se lit dans les sources — et notamment les pièges qui ne
 * pardonnent pas : un `wp:html` non refermé, une fonction déclarée dans un
 * pattern rendu deux fois, un lien vers l'archive d'auteur.
 *
 * Aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

const GABARITS = array( 'home', 'single', 'archive' );
const PATTERNS = array( 'guides-grille', 'guide-entete', 'guide-pied', 'guides-archive-entete' );

// ------------------------------------------------- 1 · les gabarits existent --

foreach ( GABARITS as $nom ) {
	check( "Le gabarit $nom.html existe", is_file( $theme . "/templates/$nom.html" ) );
}

$contenus = array();
foreach ( GABARITS as $nom ) {
	$chemin = $theme . "/templates/$nom.html";
	$contenus[ $nom ] = is_file( $chemin ) ? file_get_contents( $chemin ) : '';
}

// ------------------------------------------------- 2 · en-tête et pied Urbizen --

foreach ( GABARITS as $nom ) {
	check(
		"$nom.html : en-tête et pied de page Urbizen",
		str_contains( $contenus[ $nom ], '"slug":"header-urbizen"' )
		&& str_contains( $contenus[ $nom ], '"slug":"footer-urbizen"' )
	);
	// C'est le défaut que ce lot corrige : ne pas le réintroduire par mégarde.
	check(
		"$nom.html : aucune part du thème parent (header, footer, superposition)",
		! preg_match( '/"slug":"(header|footer|footer-landing|superposition-de-navigation)"/', $contenus[ $nom ] )
	);
	check(
		"$nom.html : porte les classes de portée des feuilles Urbizen",
		(bool) preg_match( '/class="urbizen-accueil urbizen-page urbizen-guides/', $contenus[ $nom ] )
	);
	check( "$nom.html : lien d'évitement vers le contenu", str_contains( $contenus[ $nom ], 'class="u-skip"' ) );
}

// ------------------------------------------------- 3 · le titre de l'index ----

// `home.html` interroge la LISTE des articles : l'objet interrogé n'est pas la
// page assignée à `page_for_posts`. Un `core/post-title` y rendrait le titre du
// premier article, ou rien. Le libellé doit donc être écrit dans le gabarit.
check(
	'home.html : le H1 « Guides d’urbanisme » est écrit dans le gabarit',
	(bool) preg_match( '#<h1>Guides d’urbanisme</h1>#u', $contenus['home'] )
);
check(
	'home.html : aucun bloc de titre dynamique, dont le rendu serait incertain',
	! str_contains( $contenus['home'], 'wp:post-title' )
	&& ! str_contains( $contenus['home'], 'wp:query-title' )
);
check(
	'archive.html : le titre vient du terme, pas d’un bloc préfixé',
	! str_contains( $contenus['archive'], 'wp:query-title' )
);
check(
	'single.html : le corps de l’article est rendu par wp:post-content',
	str_contains( $contenus['single'], 'wp:post-content' )
);

// ------------------------------------------------- 4 · les patterns ----------

foreach ( PATTERNS as $nom ) {
	$chemin = $theme . "/patterns/$nom.php";
	check( "Le pattern $nom.php existe", is_file( $chemin ) );

	if ( ! is_file( $chemin ) ) {
		continue;
	}

	$src = file_get_contents( $chemin );

	check( "$nom.php : slug déclaré urbizen-child/$nom", str_contains( $src, "Slug: urbizen-child/$nom" ) );
	check( "$nom.php : sortie directe refusée hors WordPress", str_contains( $src, "defined( 'ABSPATH' ) || exit;" ) );

	// Un `wp:html` non refermé casse le gabarit entier : le parseur avale tout
	// ce qui suit. Les deux délimiteurs doivent s'équilibrer.
	$ouverts  = substr_count( $src, '<!-- wp:html -->' );
	$fermes   = substr_count( $src, '<!-- /wp:html -->' );
	check( "$nom.php : les blocs wp:html sont équilibrés", $ouverts === $fermes, "$ouverts ouvert(s), $fermes fermé(s)" );
	check( "$nom.php : le markup est bien dans un bloc wp:html (sinon wpautop le mange)", $ouverts >= 1 );

	// Un fichier de pattern est inclus à CHAQUE rendu. Une fonction déclarée
	// dedans est redéclarée au second — erreur fatale. `guide-pied` rend la
	// même page que `guides-grille` sur un article.
	check( "$nom.php : ne déclare aucune fonction", ! preg_match( '/^\s*function\s+\w+\s*\(/m', $src ) );

	check( "$nom.php : aucun lien vers une archive d’auteur", ! str_contains( $src, '/author/' ) );
}

// ------------------------------------------------- 5 · aucun /author/ servi ---

foreach ( GABARITS as $nom ) {
	check(
		"$nom.html : la chaîne « author » n’est jamais servie au client",
		! str_contains( $contenus[ $nom ], 'author' ),
		'même en commentaire HTML : le commentaire part chez le visiteur'
	);
}

// ------------------------------------------------- 6 · functions.php ---------

$fn = file_get_contents( $theme . '/functions.php' );

// `is_home()`, `is_single()` et `is_category()` ne sont PAS des pages :
// `get_page_template_slug()` n'y renvoie rien, et le test des gabarits internes
// les rejetait tous les trois. Sans ce prédicat, les guides sortaient sans
// polices, sans charte et sans feuille.
check( 'functions.php : le contexte « guides » est reconnu',
	str_contains( $fn, 'function urbizen_child_est_page_guides()' ) );
check( 'functions.php : il couvre l’index, l’article et les archives',
	(bool) preg_match( '/is_home\(\)\s*\|\|\s*is_singular\(\s*\'post\'\s*\)\s*\|\|\s*is_category\(\)/', $fn ) );
// Le corps de la fonction, et lui seul : un `.*?` non borné aurait couru
// jusqu'au premier `is_author()` du reste du fichier et échoué à tort.
preg_match( '/function urbizen_child_est_page_guides\(\)\s*\{(.*?)\n\}/s', $fn, $corps_guides );
check( 'functions.php : l’archive d’auteur reste exclue, elle est en 404',
	isset( $corps_guides[1] ) && ! str_contains( $corps_guides[1], 'is_author' ) );
check( 'functions.php : le contexte guides ouvre droit aux feuilles Urbizen',
	(bool) preg_match( '/function urbizen_child_est_page_urbizen\(\).*?urbizen_child_est_page_guides\(\)/s', $fn ) );
check( 'functions.php : la feuille des guides est enfilée sur ce seul contexte',
	(bool) preg_match( '/urbizen_child_est_page_guides\(\)\s*\)\s*\{\s*\$guides_css/s', $fn ) );

// Les helpers doivent être ici, et nulle part ailleurs : deux patterns les
// utilisent, et l'un d'eux est rendu sur une page où l'autre est absent.
foreach ( array( 'urbizen_child_vignette_guide', 'urbizen_child_categorie_guide' ) as $helper ) {
	check( "functions.php : $helper() y est déclaré, une seule fois",
		1 === preg_match_all( '/function ' . $helper . '\s*\(/', $fn ) );
}

// ------------------------------------------------- 7 · noindex des catégories --

check( 'functions.php : les trois catégories de guides sont nommées',
	str_contains( $fn, "'autorisations-projets'" )
	&& str_contains( $fn, "'regles-urbanisme'" )
	&& str_contains( $fn, "'conseils-demarches'" ) );
// La règle existante ne désindexe que les archives VIDES : elle s'efface au
// premier article. Celle-ci tient les trois catégories hors de l'index même
// remplies, le temps de juger de leur utilité.
check( 'functions.php : elles restent noindex même une fois remplies',
	str_contains( $fn, 'function urbizen_child_noindex_categories_guides' )
	&& (bool) preg_match( '/urbizen_child_noindex_categories_guides.*?\$attributs\[\'noindex\'\] = \'noindex\'/s', $fn ) );
check( 'functions.php : elle passe par aioseo_robots_meta, comme la précédente',
	str_contains( $fn, "add_filter( 'aioseo_robots_meta', 'urbizen_child_noindex_categories_guides'" ) );
check( 'functions.php : la règle des archives vides est conservée',
	str_contains( $fn, 'function urbizen_child_noindex_archives_vides' ) );

// ------------------------------------------------- 8 · la feuille -------------

$css = file_get_contents( $theme . '/assets/css/urbizen-guides.css' );

check( 'La feuille des guides existe', '' !== $css );
// Une règle non scopée déborderait sur l'accueil et les pages commerciales.
$non_scopees = array();
foreach ( preg_split( '/\}/', preg_replace( '#/\*.*?\*/#s', '', $css ) ) as $bloc ) {
	$selecteur = trim( preg_replace( '/\{.*$/s', '', $bloc ) );
	if ( '' === $selecteur || str_starts_with( $selecteur, '@' ) ) {
		continue;
	}
	/*
	 * Découpe sur les virgules de PREMIER NIVEAU seulement : `:is(img,
	 * .wp-block-image img)` en contient une, et un `explode` naïf en tirait un
	 * faux sélecteur « .wp-block-image img) » réputé non scopé.
	 */
	$parts = array();
	$niveau = 0;
	$courant = '';
	foreach ( str_split( $selecteur ) as $c ) {
		if ( '(' === $c ) { $niveau++; }
		if ( ')' === $c ) { $niveau--; }
		if ( ',' === $c && 0 === $niveau ) {
			$parts[] = $courant;
			$courant = '';
			continue;
		}
		$courant .= $c;
	}
	$parts[] = $courant;

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' !== $part && ! str_contains( $part, '.urbizen-guides' ) ) {
			$non_scopees[] = $part;
		}
	}
}
check( 'Chaque règle est scopée .urbizen-guides', array() === $non_scopees,
	implode( ' | ', array_slice( $non_scopees, 0, 4 ) ) );

check( 'La colonne de lecture est bornée', (bool) preg_match( '/\.guide-corps \{[^}]*max-width: 72ch/s', $css ) );
check( 'Les tableaux longs défilent seuls plutôt que d’élargir la page',
	(bool) preg_match( '/wp-block-table \{[^}]*overflow-x: auto/s', $css ) );
check( 'Le visuel d’article est cadré et ne remplit pas l’écran',
	(bool) preg_match( '/\.guide-visuel img \{[^}]*max-height/s', $css ) );
check( 'La pagination garde des cibles de 44 px',
	(bool) preg_match( '/\.guides-pagination \.page-numbers \{[^}]*min-height: 44px/s', $css ) );
check( 'Le focus clavier est visible sur la carte entière',
	str_contains( $css, ':has(.guide-lien:focus-visible)' ) );
check( 'Le mouvement réduit est respecté', str_contains( $css, 'prefers-reduced-motion' ) );

// ------------------------------------------------- 9 · ce qui ne bouge pas ----

// Miroir du contrôle de `test-navigation.php` : les deux ont basculé ensemble
// le jour où /guides/ a répondu 200.
$entete = file_get_contents( $theme . '/patterns/header-accueil.php' );
check( 'Le menu porte l’entrée Guides', str_contains( $entete, '>Guides</a>' ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
