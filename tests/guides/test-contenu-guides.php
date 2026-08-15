<?php
/**
 * Banc du CONTENU des guides — ce que `test-guides.php` ne regarde pas.
 *
 * CE QU'IL PROTÈGE
 *
 * `test-guides.php` fige les gabarits, les patterns et la feuille : le
 * contenant. Ce banc-ci fige le contenu versionné dans `content/guides/`, qui
 * est la **source de vérité** des articles publiés. Un guide corrigé dans
 * WordPress sans l'être ici repartirait à la prochaine republication.
 *
 * Il vérifie trois familles de choses, et rien d'autre :
 *
 * 1. **La structure Gutenberg** — délimiteurs équilibrés, aucun H1 dans le
 *    corps (le gabarit le rend déjà, un second casserait la règle du H1 unique),
 *    chaque image avec un `alt` non vide.
 * 2. **Le maillage** — tout lien `/guides/<slug>/` posé dans un article doit
 *    correspondre à un fichier de `content/guides/`. C'est le contrôle qui
 *    aurait empêché le lien mort que le guide 1 avait dû retirer à la rédaction.
 * 3. **Les règles éditoriales non négociables** — aucune promesse d'obtention
 *    d'autorisation, aucun montant de prestation, aucune statistique inventée,
 *    et un fichier de métadonnées cohérent avec le nom du fichier.
 *
 * CE QU'IL NE PEUT PAS VÉRIFIER
 *
 * L'exactitude réglementaire. Elle se contrôle à la source, et la trace est
 * dans `docs/VERIFICATION_REGLEMENTAIRE_GUIDES_01-02.md` et
 * `..._03-07.md`. Un banc ne sait pas lire Legifrance ; il sait en revanche
 * exiger que chaque article porte un encadré de sources daté, et c'est ce
 * qu'il fait.
 *
 * Aucun accès réseau, aucune base de données.
 */

$racine  = dirname( __DIR__, 2 );
$contenu = $racine . '/content/guides';
$images  = $racine . '/wordpress/urbizen-child/assets/images/guides';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

/*
 * Les sept guides du dispositif. La liste est écrite ici plutôt que déduite du
 * répertoire : un fichier supprimé par mégarde doit faire ÉCHOUER le banc, pas
 * réduire silencieusement son périmètre.
 */
const GUIDES = array(
	'piscine-garage-carport-autorisation',
	'dp-ou-permis-de-construire',
	'extension-maison-verifications-avant-plans',
	'lire-le-plu-de-son-terrain',
	'erreurs-dossier-urbanisme',
	'delais-urbanisme-debut-des-travaux',
);

// ------------------------------------------------- 1 · les fichiers existent --

$html = array();
$meta = array();

foreach ( GUIDES as $slug ) {
	$fh = "$contenu/$slug.html";
	$fm = "$contenu/$slug.meta.md";
	check( "$slug : le corps Gutenberg existe", is_file( $fh ) );
	check( "$slug : le fichier de métadonnées existe", is_file( $fm ) );
	$html[ $slug ] = is_file( $fh ) ? (string) file_get_contents( $fh ) : '';
	$meta[ $slug ] = is_file( $fm ) ? (string) file_get_contents( $fm ) : '';
}

// ------------------------------------------------- 2 · structure Gutenberg ----

foreach ( GUIDES as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) { continue; }

	/*
	 * Les délimiteurs de blocs doivent s'équilibrer. Un bloc auto-fermant
	 * (`<!-- wp:separator /-->`) n'ouvre rien : on ne compte que les ouvertures
	 * qui ne se referment pas sur elles-mêmes.
	 */
	$ouverts = preg_match_all( '/<!-- wp:[a-z-]+(?: \{.*?\})? -->/', $src );
	$fermes  = preg_match_all( '#<!-- /wp:[a-z-]+ -->#', $src );
	check( "$slug : les délimiteurs de blocs sont équilibrés", $ouverts === $fermes,
		"$ouverts ouvert(s), $fermes fermé(s)" );

	// Le gabarit rend déjà le titre de l'article. Un H1 dans le corps en ferait deux.
	check( "$slug : aucun H1 dans le corps, le gabarit le rend déjà",
		! preg_match( '/<h1[\s>]/i', $src ) );

	// La hiérarchie ne saute pas de niveau : pas de H3 avant le premier H2.
	$pos_h2 = strpos( $src, '<h2' );
	$pos_h3 = strpos( $src, '<h3' );
	check( "$slug : aucun H3 avant le premier H2",
		false === $pos_h3 || ( false !== $pos_h2 && $pos_h2 < $pos_h3 ) );

	// Une image sans alt utile est une image absente pour une part des lecteurs.
	preg_match_all( '/<img\b[^>]*>/i', $src, $balises );
	$sans_alt = 0;
	foreach ( $balises[0] as $img ) {
		if ( ! preg_match( '/\balt="([^"]{30,})"/', $img ) ) { $sans_alt++; }
	}
	check( "$slug : chaque image porte un alt d'au moins 30 caractères", 0 === $sans_alt,
		"$sans_alt image(s) sans alt exploitable" );

	// Un encadré de sources daté, sur chaque guide, sans exception.
	check( "$slug : encadré de sources présent",
		str_contains( $src, 'class="guide-sources"' ) );
	check( "$slug : les sources indiquent une date de consultation",
		(bool) preg_match( '/version en vigueur au \d{1,2} \p{L}+ \d{4}/u', $src ) );
	check( "$slug : au moins une source officielle liée",
		str_contains( $src, 'legifrance.gouv.fr' ) );

	// Les liens externes s'ouvrent sans exposer l'onglet d'origine.
	preg_match_all( '/<a\b[^>]*target="_blank"[^>]*>/i', $src, $externes );
	$sans_noopener = 0;
	foreach ( $externes[0] as $a ) {
		if ( ! str_contains( $a, 'rel="noopener"' ) ) { $sans_noopener++; }
	}
	check( "$slug : chaque lien en nouvel onglet porte rel=noopener", 0 === $sans_noopener,
		"$sans_noopener lien(s) sans noopener" );
}

// ------------------------------------------------- 3 · maillage interne -------

/*
 * C'EST LE CONTRÔLE CENTRAL DE CE BANC.
 *
 * Le guide 1 avait dû retirer son lien vers le guide 2 à la rédaction, faute
 * d'article cible. Rien n'aurait signalé l'oubli si on l'avait laissé. Désormais
 * un lien vers un guide inexistant fait échouer le banc.
 */
$pages_commerciales = array( '/declarations-prealables/', '/permis-de-construire/', '/conception/', '/tarifs/' );

foreach ( GUIDES as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) { continue; }

	preg_match_all( '#href="/guides/([a-z0-9-]+)/"#', $src, $vers );
	$morts = array();
	foreach ( array_unique( $vers[1] ) as $cible ) {
		if ( ! in_array( $cible, GUIDES, true ) ) { $morts[] = $cible; }
	}
	check( "$slug : aucun lien vers un guide inexistant", array() === $morts,
		implode( ', ', $morts ) );

	// Un guide qui ne renvoie à rien ne participe pas au maillage.
	check( "$slug : renvoie vers au moins un autre guide",
		count( array_diff( array_unique( $vers[1] ), array( $slug ) ) ) >= 1 );

	// Les liens internes ne pointent que vers des pages qui existent.
	preg_match_all( '#href="(/[a-z0-9/-]*)"#', $src, $internes );
	$inconnus = array();
	foreach ( array_unique( $internes[1] ) as $url ) {
		if ( str_starts_with( $url, '/guides/' ) ) { continue; }
		if ( ! in_array( $url, $pages_commerciales, true ) ) { $inconnus[] = $url; }
	}
	check( "$slug : aucun lien interne vers une page hors périmètre", array() === $inconnus,
		implode( ', ', $inconnus ) );
}

/*
 * La réciprocité voulue par le plan : le guide 1 et le guide Extension doivent
 * se répondre. Deux liens, dans les deux sens, et non un seul.
 */
check( 'Guide 1 → guide Extension : le lien réciproque est posé',
	str_contains( $html['piscine-garage-carport-autorisation'], '/guides/extension-maison-verifications-avant-plans/' ) );
check( 'Guide Extension → guide 1 : la réciproque existe',
	str_contains( $html['extension-maison-verifications-avant-plans'], '/guides/piscine-garage-carport-autorisation/' ) );

// Aucun lien vers /tarifs/ dans le CORPS : intention prix isolée au lot C.
foreach ( GUIDES as $slug ) {
	check( "$slug : aucun lien vers /tarifs/ dans le corps",
		! str_contains( $html[ $slug ], 'href="/tarifs/"' ) );
}

// ------------------------------------------------- 4 · schémas référencés ----

foreach ( GUIDES as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) { continue; }

	preg_match_all( '#assets/images/guides/([a-z0-9-]+\.svg)#', $src, $svg );
	foreach ( array_unique( $svg[1] ) as $nom ) {
		$chemin = "$images/$nom";
		check( "$slug : le schéma $nom existe", is_file( $chemin ) );
		if ( ! is_file( $chemin ) ) { continue; }

		$dessin = (string) file_get_contents( $chemin );
		check( "$nom : porte un <title> et un <desc>",
			str_contains( $dessin, '<title' ) && str_contains( $dessin, '<desc' ) );
		check( "$nom : les deux sont liés par aria-labelledby",
			str_contains( $dessin, 'aria-labelledby' ) );
		check( "$nom : déclaré role=img pour les lecteurs d'écran",
			str_contains( $dessin, 'role="img"' ) );
		// Un schéma sans viewBox ne peut pas se redimensionner sur mobile.
		check( "$nom : porte un viewBox", str_contains( $dessin, 'viewBox' ) );
		// Aucune ressource distante : le rendu ne doit dépendre de rien d'externe.
		check( "$nom : n'appelle aucune ressource distante",
			! preg_match( '#(https?:)?//#', preg_replace( '/xmlns(:\w+)?="[^"]*"/', '', $dessin ) ) );
	}
}

/*
 * La charte ne s'élargit pas en douce. Toute couleur d'un schéma doit être un
 * token Urbizen. `#C0392B` est `--u-error`, la seule teinte d'alerte de la
 * charte : elle n'est pas une nouveauté, elle est déjà dans `urbizen-tokens.css`.
 */
const PALETTE = array(
	'#14233B', '#55617A', '#8791A6', '#C9D3DD', '#9FADBC', '#EAEEF2', '#F2F5F8',
	'#FBFCFD', '#128A5A', '#0E6E48', '#E4F5EC', '#54CF99', '#C0392B', '#DCE3EA',
);

foreach ( glob( "$images/*.svg" ) as $chemin ) {
	$nom = basename( $chemin );
	preg_match_all( '/#[0-9A-Fa-f]{6}\b/', (string) file_get_contents( $chemin ), $couleurs );
	$hors_charte = array_values( array_unique( array_filter(
		$couleurs[0],
		static fn( $c ) => ! in_array( strtoupper( $c ), PALETTE, true )
	) ) );
	check( "$nom : n'emploie que des couleurs de la charte", array() === $hors_charte,
		implode( ' ', $hors_charte ) );
}

// ------------------------------------------------- 5 · règles éditoriales ----

/*
 * Urbizen prépare et remet un dossier. La décision appartient à
 * l'administration. Cette règle a été posée au lot C pour les métadonnées ; un
 * guide qui la contredirait serait pire qu'une balise, parce qu'il argumente.
 */
$promesses = array(
	'/nous obtenons votre (permis|autorisation)/i',
	'/garanti[e]? d.obtention/i',
	'/permis garanti/i',
	'/autorisation garantie/i',
	'/(nous vous )?obtenons l.autorisation/i',
	'/100\s*% d.accord/i',
	'/accord assuré/i',
);

foreach ( GUIDES as $slug ) {
	$src = $html[ $slug ];
	if ( '' === $src ) { continue; }

	$trouvees = array();
	foreach ( $promesses as $motif ) {
		if ( preg_match( $motif, $src, $m ) ) { $trouvees[] = $m[0]; }
	}
	check( "$slug : aucune promesse d'obtention d'autorisation", array() === $trouvees,
		implode( ' | ', $trouvees ) );

	// Aucun montant de prestation dans un guide : les prix vivent sur /tarifs/.
	check( "$slug : aucun tarif de prestation annoncé",
		! preg_match( '/\b\d{2,4}\s*(&nbsp;)?€\s*(TTC|HT|par|\/)/i', $src ) );

	/*
	 * Aucune statistique inventée. On interdit la forme « NN % des dossiers /
	 * des demandes / des permis », qui est celle qu'on écrit sans source. Les
	 * pourcentages réglementaires (« 35 % d'emprise au sol ») restent permis :
	 * ils décrivent une règle, pas une mesure.
	 */
	check( "$slug : aucune statistique sur les dossiers",
		! preg_match( '/\d+\s*(&nbsp;)?%\s+des\s+(dossiers|demandes|permis|déclarations|projets)/iu', $src ) );
}

/*
 * Les exemples doivent être fictifs ET dits fictifs. Tout guide qui déroule un
 * cas chiffré doit le signaler dans le corps du texte — pas seulement dans une
 * note de bas de page que personne ne lit.
 */
foreach ( array( 'dp-ou-permis-de-construire', 'lire-le-plu-de-son-terrain', 'delais-urbanisme-debut-des-travaux', 'extension-maison-verifications-avant-plans' ) as $slug ) {
	check( "$slug : les exemples chiffrés sont annoncés comme fictifs",
		(bool) preg_match( '/fictif|fictive|invent(é|ée|ées|és)|imaginaire/u', $html[ $slug ] ) );
}

// ------------------------------------------------- 6 · métadonnées -----------

foreach ( GUIDES as $slug ) {
	$src = $meta[ $slug ];
	if ( '' === $src ) { continue; }

	check( "$slug.meta.md : déclare le bon slug", str_contains( $src, "`$slug`" ) );
	check( "$slug.meta.md : déclare l'URL publique",
		str_contains( $src, "https://urbizen.fr/guides/$slug/" ) );
	check( "$slug.meta.md : déclare une catégorie connue",
		(bool) preg_match( '/\*\*Catégorie\*\*\s*\|\s*(Autorisations & projets|Règles d\'urbanisme|Conseils & démarches)/u', $src ) );
	check( "$slug.meta.md : déclare une image mise en avant",
		(bool) preg_match( '/assets\/images\/blog\/[a-z0-9-]+\.webp/', $src ) );
	check( "$slug.meta.md : porte un extrait de publication",
		(bool) preg_match( '/##\s*Extrait/u', $src ) );
	check( "$slug.meta.md : porte un bloc AIOSEO", str_contains( $src, '## AIOSEO' ) );
}

/*
 * Les métadonnées servies aux moteurs ont des longueurs utiles. Au-delà, elles
 * sont tronquées ; le contrôle vise donc la valeur annoncée dans le fichier.
 */
foreach ( GUIDES as $slug ) {
	if ( '' === $meta[ $slug ] ) { continue; }

	/*
	 * 65 et non 60. Google tronque à la largeur en pixels, pas au nombre de
	 * signes ; 60 est une règle de pouce prudente, 65 la borne haute usuelle.
	 * Le plan validé le 14 août 2026 a arrêté un title de 63 caractères pour le
	 * guide Extension, en connaissance de cause. Fixer le banc à 60 aurait
	 * annulé une décision validée sans que personne l'ait demandé — on encode
	 * donc la règle réelle du projet, et on la commente.
	 */
	if ( preg_match( '/\*\*Title\*\*.*?```\s*\n(.+?)\n```/s', $meta[ $slug ], $m ) ) {
		$n = mb_strlen( trim( $m[1] ) );
		check( "$slug : le title tient en 65 caractères ou moins", $n <= 65, "$n caractères" );
		check( "$slug : le title porte la marque", str_contains( $m[1], 'Urbizen' ) );
	}
	if ( preg_match( '/\*\*Meta description\*\*.*?```\s*\n(.+?)\n```/s', $meta[ $slug ], $m ) ) {
		$n = mb_strlen( trim( preg_replace( '/\s+/', ' ', $m[1] ) ) );
		check( "$slug : la description tient entre 120 et 160 caractères",
			$n >= 120 && $n <= 160, "$n caractères" );
	}
}

/*
 * Deux guides du même site qui se présentent sous le même intitulé se
 * pénalisent. Les titles doivent être deux à deux distincts — et aucun ne doit
 * commencer par les formulations réservées aux pages commerciales au lot C.
 */
$titles = array();
foreach ( GUIDES as $slug ) {
	if ( preg_match( '/\*\*Title\*\*.*?```\s*\n(.+?)\n```/s', $meta[ $slug ], $m ) ) {
		$titles[ $slug ] = trim( $m[1] );
	}
}
check( 'Les titles des guides sont deux à deux distincts',
	count( $titles ) === count( array_unique( $titles ) ) );
foreach ( $titles as $slug => $titre ) {
	check( "$slug : le title ne commence pas par une formulation des pages commerciales",
		! preg_match( '/^(Déclaration préalable|Permis de construire|Tarifs)/iu', $titre ), $titre );
}

// ------------------------------------------------- 7 · traçabilité -----------

$verif = $racine . '/docs/VERIFICATION_REGLEMENTAIRE_GUIDES_03-07.md';
check( 'La note de vérification réglementaire 03-07 est au dépôt', is_file( $verif ) );
if ( is_file( $verif ) ) {
	$note = (string) file_get_contents( $verif );
	foreach ( array( 'dp-ou-permis-de-construire', 'extension-maison-verifications-avant-plans',
		'lire-le-plu-de-son-terrain', 'erreurs-dossier-urbanisme',
		'delais-urbanisme-debut-des-travaux' ) as $slug ) {
		check( "La note couvre $slug", str_contains( $note, $slug ) );
	}
	// Un tableau de vérification sans URL ni date ne prouve rien.
	check( 'La note porte des URL officielles', str_contains( $note, 'legifrance.gouv.fr' ) );
	check( 'La note porte des dates de consultation',
		(bool) preg_match( '#\d{2}/\d{2}/2026#', $note ) );
}

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
