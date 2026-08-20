<?php
/**
 * Banc statique du lot SEO 2 — 20 guides.
 *
 * Aucun réseau, aucune base WordPress. Il contrôle le contrat que l'on peut
 * vérifier avant toute publication : présence des 20 sources, structure
 * Gutenberg, liens, sources, règles éditoriales et cohérence avec le publisher.
 */

$racine  = dirname( __DIR__, 2 );
$contenu = $racine . '/content/guides';
$publish = $racine . '/scripts/publier-guides-lot-2.php';

$fail = 0;
function lot2_check( $label, $condition, $detail = '' ) {
	global $fail;
	if ( ! $condition ) {
		$fail++;
	}
	printf( "%-82s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
	if ( ! $condition && '' !== $detail ) {
		echo '    ' . $detail . "\n";
	}
}

const GUIDES_LOT_2 = array(
	'plan-situation-dp1-declaration-prealable',
	'dp5-aspect-exterieur-declaration-prealable',
	'photo-dp7-environnement-proche',
	'photo-dp8-paysage-lointain',
	'plan-cadastral-plan-situation-plan-masse-difference',
	'deposer-autorisation-urbanisme-en-ligne-gnau',
	'panneau-affichage-declaration-prealable',
	'daact-fin-travaux-declaration-conformite',
	'modifier-autorisation-urbanisme-dp-pc',
	'regulariser-travaux-sans-autorisation-urbanisme',
	'vendre-maison-travaux-non-declares',
	'division-terrain-declaration-prealable-permis-amenager',
	'certificat-urbanisme-information-operationnel',
	'changement-destination-sous-destination-urbanisme',
	'transformer-local-commercial-logement-autorisation',
	'lotissement-reglement-cahier-charges-plu',
	'terrain-zone-inondable-ppri-projet-construction',
	'climatisation-pompe-chaleur-declaration-prealable',
	'veranda-declaration-prealable-permis-construire',
	'surelevation-maison-declaration-prealable-permis',
);

$html = array();

// -------------------------------------------------------------- fichiers ----
lot2_check( 'Le lot contient exactement 20 slugs attendus', 20 === count( GUIDES_LOT_2 ) );

foreach ( GUIDES_LOT_2 as $slug ) {
	$fichier = "$contenu/$slug.html";
	lot2_check( "$slug : source HTML présente", is_file( $fichier ) );
	$html[ $slug ] = is_file( $fichier ) ? (string) file_get_contents( $fichier ) : '';
	lot2_check( "$slug : source non vide", '' !== trim( $html[ $slug ] ) );
}

// ------------------------------------------------------------ Gutenberg ----
foreach ( GUIDES_LOT_2 as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) {
		continue;
	}

	lot2_check( "$slug : aucun H1 dans le corps", ! preg_match( '/<h1[\s>]/i', $src ) );

	$pos_h2 = strpos( $src, '<h2' );
	$pos_h3 = strpos( $src, '<h3' );
	lot2_check(
		"$slug : aucun H3 avant le premier H2",
		false === $pos_h3 || ( false !== $pos_h2 && $pos_h2 < $pos_h3 )
	);

	/*
	 * Valide explicitement le JSON des commentaires de blocs. C'est le contrôle
	 * qui détecte par exemple une accolade manquante dans
	 * `<!-- wp:paragraph {"className":"…"} -->`.
	 */
	preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+(?:\s+(\{.*?\}))?\s*(\/)?-->/s', $src, $blocs, PREG_SET_ORDER );
	$json_invalides = 0;
	foreach ( $blocs as $bloc ) {
		$json = $bloc[1] ?? '';
		if ( '' === $json ) {
			continue;
		}
		json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$json_invalides++;
		}
	}
	lot2_check( "$slug : JSON des commentaires Gutenberg valide", 0 === $json_invalides, "$json_invalides bloc(s) invalide(s)" );

	$ouverts = preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+(?:\s+\{.*?\})?\s+-->/s', $src );
	$fermes  = preg_match_all( '#<!--\s+/wp:[a-z0-9\/-]+\s+-->#', $src );
	lot2_check( "$slug : délimiteurs Gutenberg équilibrés", $ouverts === $fermes, "$ouverts ouvert(s), $fermes fermé(s)" );

	preg_match_all( '/<img\b[^>]*>/i', $src, $images );
	$sans_alt = 0;
	foreach ( $images[0] as $img ) {
		if ( ! preg_match( '/\balt="([^"]{30,})"/u', $img ) ) {
			$sans_alt++;
		}
	}
	lot2_check( "$slug : images avec alt descriptif", 0 === $sans_alt, "$sans_alt image(s) sans alt exploitable" );

	preg_match_all( '/<a\b[^>]*target="_blank"[^>]*>/i', $src, $externes );
	$sans_noopener = 0;
	foreach ( $externes[0] as $a ) {
		if ( ! str_contains( $a, 'rel="noopener"' ) ) {
			$sans_noopener++;
		}
	}
	lot2_check( "$slug : target=_blank toujours protégé par noopener", 0 === $sans_noopener, "$sans_noopener lien(s)" );
}

// --------------------------------------------------------------- sources ----
$domaines_officiels = array(
	'legifrance.gouv.fr',
	'service-public.fr',
	'service-public.gouv.fr',
	'formulaires.service-public.fr',
	'georisques.gouv.fr',
	'geoportail.gouv.fr',
	'conseil-etat.fr',
);

foreach ( GUIDES_LOT_2 as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) {
		continue;
	}

	lot2_check( "$slug : encadré de sources présent", str_contains( $src, 'class="guide-sources"' ) );
	lot2_check(
		"$slug : date de consultation des sources présente",
		(bool) preg_match( '/Sources? consultées? le \d{1,2} \p{L}+ 20\d{2}/u', $src )
	);

	$officiel = false;
	foreach ( $domaines_officiels as $domaine ) {
		if ( str_contains( $src, $domaine ) ) {
			$officiel = true;
			break;
		}
	}
	lot2_check( "$slug : au moins une source institutionnelle liée", $officiel );
}

// --------------------------------------------------------- maillage interne --
$slugs_guides_existants = array();
foreach ( glob( "$contenu/*.html" ) as $fichier ) {
	$slugs_guides_existants[] = basename( $fichier, '.html' );
}

$pages_connues = array(
	'/#localisation',
	'/declarations-prealables/',
	'/permis-de-construire/',
	'/conception/',
	'/declaration-prealable-extension-maison/',
	'/declaration-prealable-piscine/',
	'/declaration-prealable-abri-de-jardin/',
	'/declaration-prealable-pergola-carport/',
	'/declaration-prealable-transformation-garage/',
	'/declaration-prealable-panneaux-solaires/',
	'/declaration-prealable-fenetre-de-toit/',
	'/declaration-prealable-modification-facade/',
	'/declaration-prealable-cloture-portail/',
);

foreach ( GUIDES_LOT_2 as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) {
		continue;
	}

	preg_match_all( '#href="/guides/([a-z0-9-]+)/"#', $src, $vers_guides );
	$cibles = array_values( array_unique( $vers_guides[1] ) );
	$morts  = array_values( array_diff( $cibles, $slugs_guides_existants ) );
	lot2_check( "$slug : aucun lien vers un guide source absent", array() === $morts, implode( ', ', $morts ) );
	lot2_check( "$slug : au moins un lien vers un autre guide", count( array_diff( $cibles, array( $slug ) ) ) >= 1 );

	preg_match_all( '#href="(/[^"#?]*)"#', $src, $internes );
	$inconnus = array();
	foreach ( array_unique( $internes[1] ) as $url ) {
		if ( str_starts_with( $url, '/guides/' ) ) {
			continue;
		}
		if ( ! in_array( $url, $pages_connues, true ) ) {
			$inconnus[] = $url;
		}
	}
	lot2_check( "$slug : liens internes hors guides connus", array() === $inconnus, implode( ', ', $inconnus ) );

	lot2_check( "$slug : aucun tarif de prestation dans le corps", ! preg_match( '/\b\d{2,4}\s*(?:&nbsp;)?€\s*(?:TTC|HT|par|\/)/iu', $src ) );
}

// ------------------------------------------------------ promesses / qualité --
$promesses = array(
	'/nous obtenons votre (permis|autorisation)/iu',
	'/garanti[e]? d.obtention/iu',
	'/permis garanti/iu',
	'/autorisation garantie/iu',
	'/(?:nous vous )?obtenons l.autorisation/iu',
	'/100\s*% d.accord/iu',
	'/accord assuré/iu',
);

foreach ( GUIDES_LOT_2 as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) {
		continue;
	}
	$trouvees = array();
	foreach ( $promesses as $motif ) {
		if ( preg_match( $motif, $src, $m ) ) {
			$trouvees[] = $m[0];
		}
	}
	lot2_check( "$slug : aucune promesse d’obtention administrative", array() === $trouvees, implode( ' | ', $trouvees ) );
}

// -------------------------------------------------------------- publisher ----
lot2_check( 'Publisher lot 2 présent', is_file( $publish ) );
$publisher = is_file( $publish ) ? (string) file_get_contents( $publish ) : '';

if ( '' !== $publisher ) {
	preg_match_all( "/'slug'\s*=>\s*'([a-z0-9-]+)'/", $publisher, $m );
	$slugs_publisher = array_values( array_unique( $m[1] ) );
	$attendus        = GUIDES_LOT_2;
	sort( $slugs_publisher );
	sort( $attendus );
	lot2_check( 'Publisher : exactement les 20 slugs du lot', $attendus === $slugs_publisher,
		'publisher=' . count( $slugs_publisher ) . ' / attendus=20' );
	lot2_check( 'Publisher : mode simulation présent', str_contains( $publisher, "in_array( 'simulation'" ) );
	lot2_check( 'Publisher : publication idempotente par slug', str_contains( $publisher, 'get_page_by_path' ) );
	lot2_check( 'Publisher : métadonnées AIOSEO prévues', str_contains( $publisher, "aioseo_posts" ) && str_contains( $publisher, "keyphrases" ) );
}

printf( "\n" );
if ( 0 === $fail ) {
	echo "\033[32mLot SEO 2 : banc statique vert.\033[0m\n";
	exit( 0 );
}

echo "\033[31mLot SEO 2 : $fail contrôle(s) en échec.\033[0m\n";
exit( 1 );
