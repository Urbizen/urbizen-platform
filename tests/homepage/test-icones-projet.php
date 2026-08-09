<?php
/**
 * Banc d'essai des icônes de la section « Quel est votre projet ? ».
 *
 * Les caractères typographiques provisoires — ▢ ▤ ▣ ◱ ◲ ◫ ◪ ☼ ⌂ ✎ — sont
 * remplacés par des illustrations SVG au trait. Ce banc vérifie que le
 * remplacement est complet et homogène, que les icônes restent purement
 * décoratives, et surtout qu'il n'a rien emporté d'autre : ni un libellé, ni
 * un `data-projet`, ni la logique de sélection.
 *
 * Hors WordPress : aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-76s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

/** Les dix cartes, dans l'ordre, telles qu'elles existent sur l'accueil. */
$attendu = array(
	'piscine'   => 'Piscine',
	'extension' => 'Extension',
	'garage'    => 'Garage',
	'abri'      => 'Abri de jardin',
	'pergola'   => 'Pergola',
	'facade'    => 'Modification de façade',
	'toiture'   => 'Toiture / fenêtres de toit',
	'solaire'   => 'Panneaux solaires',
	'maison'    => 'Maison individuelle',
	'transformation' => 'Transformer un espace existant',
);

$sources = array(
	'gabarit'  => $theme . '/templates/page-accueil-urbizen.html',
	'accueil'  => $theme . '/templates/front-page.html',
	'maquette' => $racine . '/frontend/homepage/index.html',
);

foreach ( $sources as $nom => $chemin ) {
	$h      = file_get_contents( $chemin );
	$cartes = array();
	preg_match_all( '#<button type="button" class="pcard".*?</button>#s', $h, $m );
	$cartes = $m[0];

	check( "[$nom] exactement 10 .pcard", 10 === count( $cartes ) );
	check( "[$nom] exactement 10 .pcard-ico", 10 === substr_count( $h, 'class="pcard-ico"' ) );

	if ( 10 !== count( $cartes ) ) { continue; }

	// --- un SVG par icône, et nulle part ailleurs dans les cartes ---
	$svgs = array();
	foreach ( $cartes as $c ) {
		preg_match_all( '#<svg.*?</svg>#s', $c, $s );
		$svgs = array_merge( $svgs, $s[0] );
	}

	check( "[$nom] exactement 10 SVG dans les cartes", 10 === count( $svgs ) );
	check( "[$nom] chaque carte porte un SVG et un seul",
		10 === count( array_filter( $cartes, static fn( $c ) => 1 === substr_count( $c, '<svg' ) ) ) );
	check( "[$nom] chaque SVG est dans son .pcard-ico",
		10 === preg_match_all( '#<span class="pcard-ico" aria-hidden="true"><svg #', $h ) );

	// --- aucun ancien symbole ne subsiste ---
	$anciens = array( '▢', '▤', '▣', '◱', '◲', '◫', '◪', '☼', '⌂', '✎' );
	$restants = array_values( array_filter( $anciens, static fn( $s ) => str_contains( $h, $s ) ) );

	check( "[$nom] aucun ancien symbole typographique", array() === $restants );

	if ( array() !== $restants ) { echo '    reste : ' . implode( ' ', $restants ) . "\n"; }

	// --- décoratifs, homogènes, sans texte ---
	check( "[$nom] les 10 SVG sont aria-hidden",
		10 === count( array_filter( $svgs, static fn( $s ) => str_contains( $s, 'aria-hidden="true"' ) ) ) );
	check( "[$nom] les 10 SVG sont focusable=\"false\"",
		10 === count( array_filter( $svgs, static fn( $s ) => str_contains( $s, 'focusable="false"' ) ) ) );

	$viewbox = array();
	foreach ( $svgs as $s ) {
		if ( preg_match( '#viewBox="([^"]*)"#', $s, $v ) ) { $viewbox[] = $v[1]; }
	}

	check( "[$nom] un seul viewBox pour les 10 icônes : " . implode( ', ', array_unique( $viewbox ) ),
		array( '0 0 24 24' ) === array_values( array_unique( $viewbox ) ) );
	check( "[$nom] aucun texte dans les icônes",
		0 === count( array_filter( $svgs, static fn( $s ) => str_contains( $s, '<text' ) ) ) );
	check( "[$nom] aucun titre ni description redondants",
		0 === count( array_filter( $svgs, static fn( $s ) => preg_match( '#<title|<desc#', $s ) ) ) );

	// --- homogénéité graphique ---
	check( "[$nom] les 10 icônes partagent la même épaisseur de trait",
		10 === count( array_filter( $svgs, static fn( $s ) => str_contains( $s, 'stroke-width="1.5"' ) ) ) );
	check( "[$nom] les 10 icônes héritent de la couleur du texte",
		10 === count( array_filter( $svgs, static fn( $s ) => str_contains( $s, 'stroke="currentColor"' ) ) ) );
	check( "[$nom] extrémités et jointures cohérentes",
		10 === count( array_filter( $svgs, static fn( $s ) =>
			str_contains( $s, 'stroke-linecap="round"' ) && str_contains( $s, 'stroke-linejoin="round"' ) ) ) );
	check( "[$nom] aucun dégradé, aucune ombre",
		0 === count( array_filter( $svgs, static fn( $s ) => preg_match( '#Gradient|filter=|drop-shadow#', $s ) ) ) );
	check( "[$nom] seules l'encre courante et le vert menthe sont employés",
		0 === count( array_filter( $svgs, static fn( $s ) =>
			preg_match_all( '~(?:fill|stroke)="(#[0-9A-Fa-f]{3,6})"~', $s, $c )
			&& array_diff( array_unique( $c[1] ), array( '#54CF99' ) ) ) ) );

	// --- rien d'autre n'a changé dans les cartes ---
	$ecarts = array();
	$i      = 0;

	foreach ( $attendu as $projet => $titre ) {
		$c = $cartes[ $i ];

		if ( ! str_contains( $c, 'data-projet="' . $projet . '"' ) ) { $ecarts[] = "ordre/data-projet #$i"; }
		if ( ! str_contains( $c, '<span class="pcard-t">' . $titre . '</span>' ) ) { $ecarts[] = "titre $projet"; }

		$i++;
	}

	check( "[$nom] les 10 data-projet et leurs titres, dans l'ordre", array() === $ecarts );
	check( "[$nom] la carte Autre projet est absente",
		! str_contains( $h, 'data-projet="autre"' ) && ! str_contains( $h, '>Autre projet<' ) );
	check( "[$nom] aucun sous-texte sous la carte de transformation",
		! str_contains( $h, 'pcard-sub' )
		&& ! str_contains( $h, 'Garage, combles, sous-sol, dépendance' ) );
	check( "[$nom] l'introduction mène directement au bon formulaire",
		str_contains( $h, 'accéder directement au bon formulaire' )
		&& ! str_contains( $h, 'démarche adaptée après étude' ) );

	/*
	 * La carte se réduit à une icône et un titre. Une refonte antérieure
	 * (`ee1415c`) y ajoutait une ligne de description — « Déclaration préalable
	 * souvent nécessaire » — qui promettait un verdict réglementaire avant
	 * toute étude. L'accueil retenu ne la porte pas, et ce banc empêche qu'elle
	 * revienne par une régénération.
	 */
	check( "[$nom] aucune carte ne porte de description réglementaire",
		! str_contains( $h, 'pcard-d' ) && ! str_contains( $h, 'souvent nécessaire' ) );

	if ( array() !== $ecarts ) { echo '    écart : ' . implode( ' | ', $ecarts ) . "\n"; }

	// --- l'interaction reste intacte ---
	check( "[$nom] les cartes restent des <button type=\"button\">",
		10 === preg_match_all( '#<button type="button" class="pcard" data-projet=#', $h ) );
	check( "[$nom] aucun gestionnaire d'événement en ligne",
		0 === count( array_filter( $cartes, static fn( $c ) => preg_match( '#\son[a-z]+\s*=#', $c ) ) ) );
}

// ------------------------------------------------ le JavaScript intact ------
$js_src = file_get_contents( $racine . '/frontend/homepage/homepage.js' );
$js_wp  = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );

check( 'JavaScript : la sélection repose toujours sur .pcard et .is-selected',
	str_contains( $js_wp, 'querySelectorAll(".pcard")' )
	&& str_contains( $js_wp, 'classList.add("is-selected")' )
	&& str_contains( $js_wp, 'getAttribute("data-projet")' ) );
check( 'JavaScript : aucune référence à .pcard-ico', ! str_contains( $js_wp, 'pcard-ico' ) );
check( 'JavaScript : aria-pressed toujours géré', str_contains( $js_wp, 'aria-pressed' ) );
check( 'JavaScript : aucune saisie libre « Décrivez votre projet » sur l’accueil',
	! str_contains( $js_src, 'Décrivez votre projet en quelques mots' )
	&& ! str_contains( $js_wp, 'Décrivez votre projet en quelques mots' ) );

// --------------------------------------------------------------- le CSS ----
$css_src = file_get_contents( $racine . '/frontend/homepage/homepage.css' );
$css_wp  = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );

check( 'CSS : .pcard-ico dimensionne les SVG',
	(bool) preg_match( '#\.pcard-ico \{[^}]*width: 26px; height: 26px#', $css_src ) );
check( 'CSS : le SVG remplit son conteneur sans se déformer',
	str_contains( $css_src, '.pcard-ico svg { width: 100%; height: 100%; display: block; }' ) );
check( 'CSS : la couleur reste celle de la marque, donc currentColor suit',
	(bool) preg_match( '#\.pcard-ico \{[^}]*color: var\(--u-brand\)#', $css_src ) );
check( 'CSS : la sélection et le survol des cartes sont inchangés',
	str_contains( $css_src, '.pcard:hover { border-color: var(--u-line-strong); transform: translateY(-2px); }' )
	&& str_contains( $css_src, '.pcard.is-selected { border-color: var(--u-brand); background: var(--u-brand-sf); box-shadow: inset 0 0 0 1px var(--u-brand); }' ) );

$decls = static function ( $c ) {
	preg_match_all( '/([-a-z]+)\s*:\s*([^;{}]+)[;}]/', preg_replace( '#/\*.*?\*/#s', '', $c ), $m, PREG_SET_ORDER );
	$o = array_map( static fn( $x ) => trim( $x[1] ) . ':' . trim( $x[2] ), $m );
	sort( $o );
	return $o;
};

check( 'CSS WordPress : déclarations identiques à la source', $decls( $css_src ) === $decls( $css_wp ) );
check( 'CSS WordPress : le sélecteur des icônes est porté',
	str_contains( $css_wp, '.urbizen-accueil .pcard-ico svg' ) );

// ------------------------------------------------- gabarits synchronisés ---
$src   = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front = file_get_contents( $theme . '/templates/front-page.html' );

check( 'Les deux gabarits sont strictement identiques', $src === $front );
check( 'Empreintes SHA-256 identiques', hash( 'sha256', $src ) === hash( 'sha256', $front ) );

// ------------------------------------------ le reste de la page intact -----
foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );

	// La planche du hero voisine ne doit pas être emportée par une retouche des
	// cartes : on vérifie qu'elle est là, entière, avec ses quatre vignettes.
	check( "[$nom] la planche du hero est intacte",
		1 === substr_count( $h, 'class="hero-board"' )
		&& 4 === preg_match_all( '#class="hp-fade hp-d[1-4]"#', $h )
		&& str_contains( $h, '>DP2 · PLAN DE MASSE</text>' ) );
	// Les dix planches de « Votre dossier peut comprendre » sont le pendant
	// documentaire des cartes : même section, même risque de retouche.
	check( "[$nom] les dix planches du dossier sont intactes",
		10 === substr_count( $h, 'class="planche-item"' )
		&& 10 === substr_count( $h, 'class="planche-fig"' )
		&& str_contains( $h, '>DP1 · PCMI1</span>' )
		&& str_contains( $h, '>Bordereau des pièces</span>' ) );
	// Trois parcours tarifaires, un par prestation, chacun vers sa page.
	check( "[$nom] les trois parcours de prestation sont intacts",
		3 === substr_count( $h, 'class="service-route"' )
		&& 7 === substr_count( $h, 'class="tarif-price"' )
		&& str_contains( $h, 'href="https://urbizen.fr/declarations-prealables/"' )
		&& str_contains( $h, 'href="https://urbizen.fr/permis-de-construire/"' )
		&& str_contains( $h, 'href="https://urbizen.fr/conception/"' ) );
	// Et les blocs abandonnés de ee1415c ne reviennent pas.
	check( "[$nom] ni section #exemples ni planche .hero-plan",
		! str_contains( $h, 'id="exemples"' ) && ! str_contains( $h, 'class="hero-plan"' )
		&& ! str_contains( $h, 'class="exemple-img"' ) );
}

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
