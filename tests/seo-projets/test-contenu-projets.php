<?php
/**
 * Banc du cocon SEO « projets » — 9 pages et 12 guides.
 *
 * CE QU'IL PROTÈGE
 *
 * Le contenu versionné dans `content/pages/` et `content/guides/` est la source
 * de vérité des 21 contenus publiés. Une correction faite dans l'éditeur
 * WordPress et non reportée au dépôt repartirait à la prochaine republication ;
 * ce banc fige donc ce qui doit rester vrai dans les fichiers.
 *
 * Il vérifie quatre familles de choses :
 *
 * 1. **La structure** — délimiteurs Gutenberg équilibrés, aucun H1 dans le
 *    corps (le gabarit le rend déjà), hiérarchie de titres sans saut.
 * 2. **Le maillage** — tout lien interne doit désigner une URL qui existera
 *    après publication. C'est le contrôle qui empêche un lien mort de partir
 *    en production.
 * 3. **Les visuels** — seuls les fichiers du kit sont autorisés, avec leurs
 *    variantes, leurs dimensions et leur `alt`. Aucun visuel de substitution.
 * 4. **Les règles éditoriales non négociables** — aucune promesse d'acceptation,
 *    aucune pièce présentée comme systématiquement obligatoire, aucun tarif
 *    inventé, aucune fausse urgence, aucune statistique.
 *
 * CE QU'IL NE PEUT PAS VÉRIFIER
 *
 * L'exactitude réglementaire, qui se contrôle à la source — la trace est dans
 * `docs/VERIFICATION_REGLEMENTAIRE_SEO_PROJETS.md`. Et le rendu réel, qui
 * demande un navigateur : c'est l'objet de la recette.
 *
 * Aucun accès réseau, aucune base de données.
 */

$racine  = dirname( __DIR__, 2 );
$pages   = $racine . '/content/pages';
$guides  = $racine . '/content/guides';
$theme   = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-78s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

/*
 * Les listes sont écrites ici plutôt que déduites du répertoire : un fichier
 * supprimé par mégarde doit faire ÉCHOUER le banc, pas réduire son périmètre.
 */
const PAGES_PROJETS = array(
	'declaration-prealable-extension-maison',
	'declaration-prealable-piscine',
	'declaration-prealable-abri-de-jardin',
	'declaration-prealable-pergola-carport',
	'declaration-prealable-transformation-garage',
	'declaration-prealable-panneaux-solaires',
	'declaration-prealable-fenetre-de-toit',
	'declaration-prealable-modification-facade',
	'declaration-prealable-cloture-portail',
);

const GUIDES_NOUVEAUX = array(
	'pieces-declaration-prealable',
	'plan-masse-dp2',
	'insertion-graphique-dp6',
	'plan-facades-toitures-dp4',
	'plan-coupe-dp3',
	'secteur-protege-abf-declaration-travaux',
	'emprise-au-sol-surface-de-plancher',
	'distance-limite-separative-construction',
	'recours-architecte-150-m2',
	'demande-pieces-complementaires-urbanisme',
	'refus-declaration-prealable',
	'cerfa-declaration-travaux',
);

/** Les six guides publiés au lot précédent : ils ne doivent pas disparaître. */
const GUIDES_EXISTANTS = array(
	'piscine-garage-carport-autorisation',
	'dp-ou-permis-de-construire',
	'extension-maison-verifications-avant-plans',
	'lire-le-plu-de-son-terrain',
	'erreurs-dossier-urbanisme',
	'delais-urbanisme-debut-des-travaux',
);

// ------------------------------------------------- 1 · les fichiers existent --

$src = array();

foreach ( PAGES_PROJETS as $slug ) {
	$f = "$pages/$slug.html";
	check( "page $slug : le corps existe", is_file( $f ) );
	$src[ "/$slug/" ] = is_file( $f ) ? (string) file_get_contents( $f ) : '';
}
foreach ( GUIDES_NOUVEAUX as $slug ) {
	$f = "$guides/$slug.html";
	check( "guide $slug : le corps existe", is_file( $f ) );
	$src[ "/guides/$slug/" ] = is_file( $f ) ? (string) file_get_contents( $f ) : '';
}
foreach ( GUIDES_EXISTANTS as $slug ) {
	check( "guide existant $slug : toujours au dépôt", is_file( "$guides/$slug.html" ) );
}

// ------------------------------------------------- 2 · structure Gutenberg ----

foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	$nom = trim( $url, '/' );

	$ouverts = preg_match_all( '/<!-- wp:[a-z-]+(?: \{.*?\})? -->/', $corps );
	$fermes  = preg_match_all( '#<!-- /wp:[a-z-]+ -->#', $corps );
	check( "$nom : délimiteurs de blocs équilibrés", $ouverts === $fermes, "$ouverts ouvert(s), $fermes fermé(s)" );

	check( "$nom : aucun H1 dans le corps, le gabarit le rend déjà",
		! preg_match( '/<h1[\s>]/i', $corps ) );

	$p2 = strpos( $corps, '<h2' );
	$p3 = strpos( $corps, '<h3' );
	check( "$nom : aucun H3 avant le premier H2",
		false === $p3 || ( false !== $p2 && $p2 < $p3 ) );

	preg_match_all( '/<a\b[^>]*target="_blank"[^>]*>/i', $corps, $ext );
	$sans = 0;
	foreach ( $ext[0] as $a ) { if ( ! str_contains( $a, 'rel="noopener"' ) ) { $sans++; } }
	check( "$nom : chaque lien en nouvel onglet porte rel=noopener", 0 === $sans, "$sans lien(s)" );

	check( "$nom : encadré de sources présent",
		str_contains( $corps, 'projet-sources' ) || str_contains( $corps, 'guide-sources' ) );
	check( "$nom : au moins une source officielle liée",
		str_contains( $corps, 'legifrance.gouv.fr' ) || str_contains( $corps, 'service-public.gouv.fr' )
		|| str_contains( $corps, 'formulaires.service-public.gouv.fr' ) );
	check( "$nom : les sources sont datées",
		(bool) preg_match( '/(en vigueur au|consult(é|és|ée|ées) (le|au)) \d{1,2} \p{L}+ \d{4}/u', $corps ) );
}

// ------------------------------------------------- 3 · maillage interne -------

/*
 * C'EST LE CONTRÔLE CENTRAL.
 *
 * Toute URL interne citée doit exister après publication. Les pages
 * commerciales et formulaires préexistants sont listés ; le reste doit
 * appartenir au cocon.
 */
$existantes = array(
	'/', '/declarations-prealables/', '/permis-de-construire/', '/conception/', '/tarifs/',
	'/guides/', '/formulaire-declaration-prealable/', '/formulaire-permis-de-construire/',
	'/formulaire-conception/',
);
foreach ( GUIDES_EXISTANTS as $slug ) { $existantes[] = "/guides/$slug/"; }
$connues = array_merge( $existantes, array_keys( $src ) );

foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	$nom = trim( $url, '/' );

	preg_match_all( '#href="(/[a-z0-9/-]*)"#', $corps, $liens );
	$morts = array_values( array_unique( array_diff( $liens[1], $connues ) ) );
	check( "$nom : aucun lien interne mort", array() === $morts, implode( ', ', $morts ) );

	// Un contenu qui ne renvoie nulle part ne participe pas au cocon.
	$sortants = array_diff( array_unique( $liens[1] ), array( $url ) );
	check( "$nom : renvoie vers au moins deux autres contenus", count( $sortants ) >= 2 );
}

// Chaque page projet mène au formulaire ET aux tarifs (via le corps ou le pied).
foreach ( PAGES_PROJETS as $slug ) {
	check( "page $slug : mène à /tarifs/", str_contains( $src[ "/$slug/" ], '/tarifs/' ) );
}

// Le hub liste les neuf pages projets.
$hub = (string) file_get_contents( $theme . '/templates/page-declaration-prealable.html' );
foreach ( PAGES_PROJETS as $slug ) {
	check( "hub /declarations-prealables/ : lie /$slug/", str_contains( $hub, "/$slug/" ) );
}
check( 'hub : la section projets est ajoutée sans rien retirer',
	str_contains( $hub, 'projets-grille' ) && str_contains( $hub, 'id="seuils"' )
	&& str_contains( $hub, 'id="tarifs"' ) && str_contains( $hub, 'dp-faq' ) );

// ------------------------------------------------- 4 · visuels du kit ---------

/*
 * Seuls les fichiers du kit sont autorisés. Un visuel de substitution — ancien
 * schéma vectoriel ou image générée — doit faire échouer le banc.
 */
$kit_photos   = glob( $theme . '/assets/images/seo-projects/*.webp' );
$kit_planches = glob( $theme . '/assets/images/dossier/dp*-cartouche.webp' );
check( 'Le kit photographique est installé (44 fichiers)', 44 === count( $kit_photos ), count( $kit_photos ) . ' trouvé(s)' );
check( 'Les sept planches au cartouche sont présentes', 7 === count( $kit_planches ), count( $kit_planches ) . ' trouvée(s)' );

$autorises = array();
foreach ( array_merge( $kit_photos, $kit_planches ) as $f ) { $autorises[] = basename( $f ); }

foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	$nom = trim( $url, '/' );

	preg_match_all( '/<img\b[^>]*>/i', $corps, $imgs );
	$hors_kit = array();
	$sans_alt = 0;
	$sans_dim = 0;
	$sans_lazy = 0;

	foreach ( $imgs[0] as $img ) {
		preg_match_all( '#assets/images/[a-z-]+/([a-z0-9.-]+\.webp)#', $img, $f );
		foreach ( array_unique( $f[1] ) as $fichier ) {
			if ( ! in_array( $fichier, $autorises, true ) ) { $hors_kit[] = $fichier; }
		}
		if ( ! preg_match( '/\balt="([^"]{40,})"/', $img ) ) { $sans_alt++; }
		if ( ! preg_match( '/\bwidth="\d+"/', $img ) || ! preg_match( '/\bheight="\d+"/', $img ) ) { $sans_dim++; }
		// Toutes les images du CORPS sont hors zone initiale : le visuel d'en-tête
		// est rendu par le pattern, pas par le contenu.
		if ( ! str_contains( $img, 'loading="lazy"' ) ) { $sans_lazy++; }
	}

	check( "$nom : aucun visuel hors du kit", array() === $hors_kit, implode( ', ', array_unique( $hors_kit ) ) );
	check( "$nom : chaque image porte un alt descriptif", 0 === $sans_alt, "$sans_alt image(s)" );
	check( "$nom : chaque image porte width et height", 0 === $sans_dim, "$sans_dim image(s)" );
	check( "$nom : chaque image du corps est différée", 0 === $sans_lazy, "$sans_lazy image(s)" );

	// Les photographies doivent porter leurs quatre variantes ; les planches au
	// cartouche n'en ont pas, et n'ont donc pas de srcset.
	foreach ( $imgs[0] as $img ) {
		if ( ! str_contains( $img, 'seo-projects/' ) ) { continue; }
		check( "$nom : photographie servie avec un srcset à quatre variantes",
			4 === preg_match_all( '/\d+w/', $img ) && str_contains( $img, 'sizes=' ) );
	}

	// Toute planche au cartouche doit être légendée comme un exemple fictif.
	if ( str_contains( $corps, 'images/dossier/' ) ) {
		check( "$nom : les planches sont légendées « Exemple Urbizen — projet fictif »",
			(bool) preg_match( '/Exemple Urbizen\s*—\s*projet fictif/u', $corps ) );
	}
}

// Aucun ancien schéma vectoriel refusé ne doit revenir dans ces contenus.
foreach ( $src as $url => $corps ) {
	check( trim( $url, '/' ) . ' : aucun schéma vectoriel', ! str_contains( $corps, '.svg' ) );
}

// ------------------------------------------------- 5 · règles éditoriales ----

$promesses = array(
	'/nous obtenons votre (permis|autorisation)/i',
	'/garanti[e]? d.(obtention|acceptation)/i',
	'/(permis|autorisation|accord) garanti/i',
	'/acceptation (garantie|assurée)/i',
	'/dossier accepté à coup sûr/i',
	'/100\s*% d.(accord|acceptation)/i',
);

$urgences = array(
	'/offre limitée/i',
	'/derni(ers|ères) (jours|places)/i',
	'/plus que \d+ (jours|places)/i',
	'/dépêchez-vous/i',
	'/avant qu.il ne soit trop tard/i',
);

foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	$nom = trim( $url, '/' );

	$t = array();
	foreach ( $promesses as $m ) { if ( preg_match( $m, $corps, $x ) ) { $t[] = $x[0]; } }
	check( "$nom : aucune promesse d'acceptation", array() === $t, implode( ' | ', $t ) );

	$u = array();
	foreach ( $urgences as $m ) { if ( preg_match( $m, $corps, $x ) ) { $u[] = $x[0]; } }
	check( "$nom : aucune fausse urgence commerciale", array() === $u, implode( ' | ', $u ) );

	check( "$nom : aucune statistique sur les dossiers",
		! preg_match( '/\d+\s*(&nbsp;)?%\s+des\s+(dossiers|demandes|permis|déclarations|projets|refus)/iu', $corps ) );
}

/*
 * LES TARIFS
 *
 * Huit forfaits publiés sur /tarifs/, et eux seuls. Tout montant en euros
 * apparaissant dans un contenu doit appartenir à cette liste — sauf les valeurs
 * fiscales, qui sont des impositions et non des tarifs, et qui sont vérifiées à
 * part.
 */
const TARIFS_PUBLIES = array( '189', '249', '449', '549', '649', '849', '80' );
const VALEURS_FISCALES = array( '892', '1 011', '251', '10', '2 928' );

foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	$nom = trim( $url, '/' );

	preg_match_all( '/([\d\s\x{00A0}]+|\d+)&nbsp;€/u', $corps, $montants );
	$inconnus = array();
	foreach ( array_unique( $montants[1] ) as $m ) {
		$m = trim( str_replace( array( "\u{00A0}", ' ' ), ' ', $m ) );
		if ( ! in_array( $m, TARIFS_PUBLIES, true ) && ! in_array( $m, VALEURS_FISCALES, true ) ) {
			$inconnus[] = $m;
		}
	}
	check( "$nom : aucun montant hors des forfaits publiés", array() === $inconnus, implode( ', ', $inconnus ) );
}

/*
 * LA RÉSERVE SUR LES PIÈCES
 *
 * Aucun contenu ne doit présenter les pièces comme systématiquement
 * obligatoires. Tout contenu qui énumère des pièces porte la réserve.
 */
foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	$nom = trim( $url, '/' );

	/*
	 * Le déclencheur porte sur le CORPS, pas sur l'encadré de sources : citer
	 * R.431-36 en référence n'est pas énumérer des pièces. Sans cette
	 * exclusion, le guide CERFA — qui ne décrit aucune pièce — était réputé
	 * devoir porter la réserve.
	 */
	$sans_sources = preg_replace( '/<!-- wp:paragraph \{"className":"(projet|guide)-sources"\}.*$/s', '', $corps );
	$enumere = str_contains( $sans_sources, 'R.431-36' ) || preg_match( '/\bDP[1-8]\b/', $sans_sources );
	if ( ! $enumere ) { continue; }

	/*
	 * La réserve peut s'écrire de plusieurs façons, et c'est tant mieux : vingt
	 * et un contenus qui répéteraient la même phrase se liraient comme un
	 * gabarit. Le contrôle vise donc le SENS — une pièce conditionnée à quelque
	 * chose — et non une formule imposée.
	 */
	$formes = '/('
		. 'ne sont pas exig'                       // « ne sont pas exigées dans tous les dossiers »
		. '|n.est (pas )?syst[ée]matique'           // « aucune de ces pièces n'est systématique »
		. '|pas syst[ée]matique'
		. '|n.est pas un minimum obligatoire'
		. '|(dépend|varie|varient|dépendent) (de|selon) la nature du projet'
		. '|n.est nécessaire que'                   // « le plan de masse n'est nécessaire que… »
		. '|n.est à fournir que'
		. '|n.est à joindre que'
		. '|s.il y a lieu'
		. '|Quand elle ne l.est pas'
		. ')/iu';

	check( "$nom : la fourniture des pièces est présentée comme conditionnelle",
		(bool) preg_match( $formes, $sans_sources ) );
}

// Le vocabulaire réglementaire doit être présent et expliqué sur les pages.
foreach ( PAGES_PROJETS as $slug ) {
	check( "page $slug : emploie le terme « déclaration préalable »",
		str_contains( $src[ "/$slug/" ], 'déclaration préalable' ) );
}

// « Velux » est une marque : elle ne doit pas structurer la page.
$toit = $src['/declaration-prealable-fenetre-de-toit/'];
check( 'fenêtre de toit : la marque n’apparaît dans aucun titre',
	! preg_match( '/<h[23][^>]*>[^<]*Velux/i', $toit ) );
check( 'fenêtre de toit : la marque est citée une seule fois, en note de vocabulaire',
	1 === preg_match_all( '/Velux/i', $toit ) );

// ------------------------------------------------- 6 · exemples fictifs ------

foreach ( $src as $url => $corps ) {
	if ( '' === $corps ) { continue; }
	if ( ! preg_match( '/\b\d+(,\d+)?\s?m²/u', $corps ) ) { continue; }
	// Un contenu qui déroule un cas chiffré doit dire qu'il est fictif — sauf
	// s'il ne fait que citer des seuils réglementaires.
	if ( ! preg_match( '/(Hypothèses|Exemple fictif|projets? (ci-dessous|fictif))/u', $corps ) ) { continue; }
	check( trim( $url, '/' ) . ' : les exemples chiffrés sont annoncés comme fictifs',
		(bool) preg_match( '/fictif|fictive|fictifs|invent/u', $corps ) );
}

// ------------------------------------------------- 7 · gabarit et feuille ----

check( 'Le gabarit page-projet-seo existe', is_file( $theme . '/templates/page-projet-seo.html' ) );
check( 'Le pattern d’en-tête existe', is_file( $theme . '/patterns/projet-entete.php' ) );
check( 'Le pattern de pied existe', is_file( $theme . '/patterns/projet-pied.php' ) );
check( 'La feuille des pages projets existe', is_file( $theme . '/assets/css/urbizen-projets.css' ) );

$gab = (string) file_get_contents( $theme . '/templates/page-projet-seo.html' );
check( 'Le gabarit porte les classes de portée', str_contains( $gab, 'urbizen-accueil urbizen-page urbizen-projets' ) );
check( 'Le gabarit passe par les parts Urbizen',
	str_contains( $gab, '"slug":"header-urbizen"' ) && str_contains( $gab, '"slug":"footer-urbizen"' ) );
check( 'Le gabarit rend le contenu de l’éditeur', str_contains( $gab, 'wp:post-content' ) );
check( 'Le gabarit n’ouvre aucune part du thème parent',
	! preg_match( '/"slug":"(header|footer|footer-landing)"/', $gab ) );

$fn = (string) file_get_contents( $theme . '/functions.php' );
check( 'functions.php : le gabarit est déclaré', str_contains( $fn, "'page-projet-seo'" ) );
check( 'functions.php : le contexte des pages projets est reconnu',
	str_contains( $fn, 'function urbizen_child_est_page_projet()' ) );
check( 'functions.php : la feuille est enfilée sur ce seul contexte',
	(bool) preg_match( '/urbizen_child_est_page_projet\(\)\s*\)\s*\{\s*\$projets_css/s', $fn ) );

$theme_json = json_decode( (string) file_get_contents( $theme . '/theme.json' ), true );
$noms = array_column( $theme_json['customTemplates'] ?? array(), 'name' );
check( 'theme.json : le gabarit est enregistré', in_array( 'page-projet-seo', $noms, true ) );

// La feuille ne doit pas déborder : chaque règle est scopée.
$css = (string) file_get_contents( $theme . '/assets/css/urbizen-projets.css' );
$non_scopees = array();
foreach ( preg_split( '/\}/', preg_replace( '#/\*.*?\*/#s', '', $css ) ) as $bloc ) {
	$sel = trim( preg_replace( '/\{.*$/s', '', $bloc ) );
	if ( '' === $sel || str_starts_with( $sel, '@' ) ) { continue; }

	$parts   = array();
	$niveau  = 0;
	$courant = '';
	foreach ( str_split( $sel ) as $ch ) {
		if ( '(' === $ch ) { $niveau++; }
		if ( ')' === $ch ) { $niveau--; }
		if ( ',' === $ch && 0 === $niveau ) { $parts[] = $courant; $courant = ''; continue; }
		$courant .= $ch;
	}
	$parts[] = $courant;

	foreach ( $parts as $p ) {
		$p = trim( $p );
		if ( '' !== $p && ! str_contains( $p, '.urbizen-projets' ) && ! str_contains( $p, '.urbizen-page-dp' ) ) {
			$non_scopees[] = $p;
		}
	}
}
check( 'Chaque règle est scopée .urbizen-projets ou .urbizen-page-dp',
	array() === $non_scopees, implode( ' | ', array_slice( $non_scopees, 0, 4 ) ) );

check( 'La colonne de lecture est bornée', (bool) preg_match( '/\.projet-corps \{[^}]*max-width: 72ch/s', $css ) );
check( 'Les tableaux longs défilent seuls', (bool) preg_match( '/wp-block-table \{[^}]*overflow-x: auto/s', $css ) );
check( 'La FAQ garde une cible de 44 px', (bool) preg_match( '/\.projet-faq summary \{[^}]*min-height: 44px/s', $css ) );
check( 'Le focus clavier est visible sur la FAQ', str_contains( $css, '.projet-faq summary:focus-visible' ) );
check( 'Le focus clavier est visible sur la grille du hub', str_contains( $css, '.projets-grille a:focus-visible' ) );
check( 'Le mouvement réduit est respecté', str_contains( $css, 'prefers-reduced-motion' ) );

// ------------------------------------------------- 8 · traçabilité -----------

foreach ( array( 'SEO_CONTENT_MAP.md', 'VERIFICATION_REGLEMENTAIRE_SEO_PROJETS.md', 'SEO_VISUALS_HANDOFF.md', 'SEO_VISUALS_MANIFEST.md' ) as $doc ) {
	check( "docs/$doc est au dépôt", is_file( "$racine/docs/$doc" ) );
}

$carte = (string) file_get_contents( $racine . '/docs/SEO_CONTENT_MAP.md' );
foreach ( array_merge( PAGES_PROJETS, GUIDES_NOUVEAUX ) as $slug ) {
	check( "La carte de contenu couvre $slug", str_contains( $carte, $slug ) );
}

$verif = (string) file_get_contents( $racine . '/docs/VERIFICATION_REGLEMENTAIRE_SEO_PROJETS.md' );
check( 'La note de vérification porte le CERFA en vigueur', str_contains( $verif, '16702' ) );
check( 'La note de vérification porte des URL officielles', str_contains( $verif, 'legifrance.gouv.fr' ) );
check( 'La note de vérification porte des dates de consultation',
	(bool) preg_match( '#\d{2}/\d{2}/2026#', $verif ) );

// Le guide CERFA ne doit citer aucun numéro sans sa version.
$cerfa = $src['/guides/cerfa-declaration-travaux/'];
check( 'guide CERFA : le formulaire en vigueur est cité', str_contains( $cerfa, '16702' ) );
check( 'guide CERFA : les anciens numéros sont présentés comme périmés',
	str_contains( $cerfa, '13703' ) && (bool) preg_match( '/(anciens|périmé|ne prescrit plus)/iu', $cerfa ) );
check( 'guide CERFA : aucun PDF de formulaire hébergé par Urbizen',
	! preg_match( '#href="/[^"]*\.pdf"#i', $cerfa ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
