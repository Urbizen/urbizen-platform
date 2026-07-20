<?php
/**
 * Banc d'essai de la planche du hero — « PIÈCES DU DOSSIER ».
 *
 * La planche est une illustration décorative animée en CSS pur. Ce banc
 * vérifie qu'elle contient bien les quatre vignettes et leurs éléments
 * techniques, que l'animation reste sobre — une seule lecture, aucune boucle,
 * aucun JavaScript — et surtout qu'en mouvement réduit **tout est visible
 * immédiatement** : aucun délai, aucune opacité nulle qui persisterait.
 *
 * Hors WordPress : aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

/** Extrait la planche SVG d'un document. */
function planche( $html ) {
	return preg_match( '#<svg viewBox="0 0 440 360".*?</svg>#s', $html, $m ) ? $m[0] : '';
}

$sources = array(
	'gabarit'  => $theme . '/templates/page-accueil-urbizen.html',
	'accueil'  => $theme . '/templates/front-page.html',
	'maquette' => $racine . '/frontend/homepage/index.html',
);

// ------------------------------------------------------- une seule planche ---
foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );
	$p = planche( $h );

	check( "[$nom] exactement une .hero-plan", 1 === substr_count( $h, 'class="hero-plan"' ) );
	check( "[$nom] la planche SVG est présente", '' !== $p );

	if ( '' === $p ) { continue; }

	// Quatre cadres de vignette : les seuls rects au trait #C9D3DD.
	check( "[$nom] quatre cadres de vignette",
		4 === preg_match_all( '~<rect class="hp-fade hp-d[1-4]"[^>]*stroke="\#C9D3DD"~', $p ) );

	foreach ( array( 'DP2 · MASSE', 'DP4 · FA&#199;ADES', 'DP3 · COUPE', 'DP6 · INSERTION 3D' ) as $lib ) {
		check( "[$nom] libellé « " . str_replace( '&#199;', 'Ç', $lib ) . ' »', str_contains( $p, '>' . $lib . '</text>' ) );
	}

	// --- éléments techniques attendus, vignette par vignette ---
	$attendus = array(
		'DP2 : limite de parcelle'   => 'M36 56 L188 46 L198 150 L44 158 Z',
		'DP2 : emprise du bâtiment'  => '<rect x="86" y="80" width="64" height="46"',
		'DP2 : accès'                => 'M104 126 L126 126 L124 154 L102 155 Z',
		'DP2 : alignement en tireté' => '<line x1="44" y1="150" x2="198" y2="146"',
		'DP4 : toiture à débords'    => 'M290 94 L334 62 L378 94 Z',
		'DP4 : ligne de terrain'     => '<line x1="246" y1="152" x2="414" y2="152"',
		'DP4 : porte'                => '<rect x="326" y="120" width="16" height="32"',
		'DP4 : fenêtre gauche'       => '<rect x="306" y="104" width="14" height="14"',
		'DP4 : fenêtre droite'       => '<rect x="348" y="104" width="14" height="14"',
		'DP3 : mur coupé gauche'     => '<rect x="66" y="252" width="5" height="50"',
		'DP3 : mur coupé droit'      => '<rect x="153" y="252" width="5" height="50"',
		'DP3 : plancher'             => '<rect x="62" y="302" width="100" height="5"',
		'DP3 : toiture'              => 'M66 252 L112 226 L158 252 Z',
		'DP6 : ombre au sol'         => '<ellipse cx="330" cy="303"',
		'DP6 : porte'                => '<rect x="310" y="282" width="14" height="20"',
		'DP6 : fenêtre'              => '<rect x="328" y="272" width="9" height="9"',
		'DP6 : arbre'                => '<circle cx="386" cy="278" r="13"',
	);

	// Le faîtage relie le sommet du pignon avant au sommet arrière de la
	// toiture. Sans lui, les deux pans menthe — tous deux en stroke="none" —
	// se rejoignaient sans aucune ligne, et l'œil butait sur l'arête absente.
	check( "[$nom] DP6 : le faîtage est tracé", 1 === substr_count( $p, 'M320 246 L360 252' ) );
	check( "[$nom] DP6 : le faîtage est dans le tracé animé, pas ailleurs",
		(bool) preg_match( '#class="hp-draw hp-dr4"[^>]*M320 246 L360 252#', $p ) );
	// Retirer le segment doit redonner exactement le tracé d'avant : aucune
	// autre coordonnée du DP6 n'a bougé.
	check( "[$nom] DP6 : aucune autre coordonnée du tracé n'a changé",
		preg_match( '#<path class="hp-draw hp-dr4"[^>]*d="([^"]*)"#', $p, $m )
		&& 'M300 302 L300 264 L340 264 L340 302 M340 264 L360 252 L360 290 '
		 . 'M296 264 L320 246 L344 264 M344 264 L360 252 '
		 . 'M373 279 a13 13 0 1 0 26 0 a13 13 0 1 0 -26 0'
		   === str_replace( 'M320 246 L360 252 ', '', $m[1] ) );

	$manque = array();
	foreach ( $attendus as $intitule => $motif ) {
		if ( ! str_contains( $p, $motif ) ) { $manque[] = $intitule; }
	}

	check( "[$nom] les " . count( $attendus ) . ' éléments techniques sont présents', array() === $manque );

	if ( array() !== $manque ) { echo '    manquant : ' . implode( ' | ', $manque ) . "\n"; }

	// --- animation : SVG + CSS uniquement ---
	check( "[$nom] aucun animateTransform ni balise d'animation SMIL",
		! preg_match( '#<(animate|animateTransform|animateMotion|set)\b#', $p ) );
	check( "[$nom] aucun JavaScript dans la planche",
		! preg_match( '#<script|\son[a-z]+\s*=#', $p ) );

	// --- les quatre phases sont bien câblées ---
	check( "[$nom] phase 1 : 4 cadres en hp-fade", 4 === preg_match_all( '#hp-fade hp-d[1-4]"#', $p ) );
	check( "[$nom] phase 2 : 4 tracés hp-draw", 4 === preg_match_all( '#class="hp-draw hp-dr[1-4]"#', $p ) );
	check( "[$nom] phase 3 : 4 groupes de remplissage", 4 === preg_match_all( '#class="hp-fade hp-fd[1-4]"#', $p ) );
	// La quatrième couche de la première proposition est retirée : on revient
	// exactement au rythme de production, trois phases.
	check( "[$nom] aucune couche .hp-det", ! str_contains( $p, 'hp-det' ) && ! str_contains( $p, 'hp-td' ) );
	check( "[$nom] chaque tracé porte pathLength", 4 === substr_count( $p, 'pathLength="1"' ) );
	check( "[$nom] viewBox inchangé", str_contains( $p, 'viewBox="0 0 440 360"' ) );
	// Le faîtage est un segment ajouté à un tracé existant, pas un élément de
	// plus : ni SVG ni <path> supplémentaire.
	check( "[$nom] 13 <path> dans la planche, aucun ajouté",
		13 === preg_match_all( '#<path #', $p ) );
}

// ------------------------------------------------------------- le CSS ------
$css_src = file_get_contents( $racine . '/frontend/homepage/homepage.css' );
$css_wp  = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );

// Bloc de la media query « no-preference » : c'est le seul endroit où des
// animations ont le droit d'exister.
$i = strpos( $css_src, '@media (prefers-reduced-motion: no-preference)' );
check( 'CSS : la media query « no-preference » existe', false !== $i );

$j    = strpos( $css_src, '{', $i );
$p    = 1;
$k    = $j + 1;
$len  = strlen( $css_src );

while ( $k < $len && $p > 0 ) {
	$p += ( '{' === $css_src[ $k ] ) - ( '}' === $css_src[ $k ] );
	$k++;
}

$bloc  = substr( $css_src, $j + 1, $k - $j - 2 );
$dehors = substr( $css_src, 0, $i ) . substr( $css_src, $k );

check( 'CSS : aucune animation en dehors de la media query',
	! preg_match( '#\.hero-plan[^{]*\{[^}]*animation[^}]*\}#', $dehors ) );
check( 'CSS : aucune opacité nulle en dehors de la media query',
	! preg_match( '#\.hero-plan[^{]*\{[^}]*opacity:\s*0[^.\d][^}]*\}#', $dehors ) );
check( 'CSS : aucun délai en dehors de la media query',
	! preg_match( '#\.hero-plan[^{]*\{[^}]*animation-delay#', $dehors ) );
// Les @keyframes vivent hors media query, c'est normal et nécessaire. Ce qui
// est interdit, c'est qu'une règle .hero-plan y impose un décalage non nul.
check( 'CSS : aucun stroke-dashoffset imposé au hero hors media query',
	! preg_match( '#\.hero-plan[^{]*\{[^}]*stroke-dashoffset#', $dehors ) );

check( 'CSS : les deux familles d\'animation de production, et elles seules',
	str_contains( $bloc, '.hp-fade {' ) && str_contains( $bloc, '.hp-draw {' )
	&& ! str_contains( $css_src, 'hp-det' ) && ! str_contains( $css_src, 'hp-td' )
	&& ! str_contains( $css_src, 'hp-mini' ) );

// --- une seule lecture, jamais de boucle ---
check( 'CSS : aucune animation infinie', ! str_contains( $css_src, 'infinite' ) );
check( 'CSS : aucun animation-iteration-count', ! str_contains( $css_src, 'animation-iteration-count' ) );
check( 'CSS : les animations se figent sur leur état final (forwards)',
	2 === preg_match_all( '#animation:\s*hp\w+\s+[\d.]+s\s+ease\s+forwards#', $bloc ) );
check( 'CSS : les deux keyframes finissent sur l\'état visible',
	str_contains( $css_src, '@keyframes hpFade { to { opacity: 1; } }' )
	&& str_contains( $css_src, '@keyframes hpDraw { to { stroke-dashoffset: 0; } }' ) );

// --- chronologie : dernier départ + durée dans la cible 2,4 à 3 s ---
preg_match_all( '#animation-delay:\s*([\d.]+)s#', $bloc, $md );
$delais = array_map( 'floatval', $md[1] );
preg_match_all( '#animation:\s*hp\w+\s+([\d.]+)s#', $bloc, $mdur );
$durees = array_map( 'floatval', $mdur[1] );

// Chaque phase a sa propre durée : apparier le délai maximal avec la durée
// maximale donnerait une borne haute, pas la fin réelle.
$phase = static function ( $prefixe, $duree ) use ( $bloc ) {
	preg_match_all( '#\.' . $prefixe . '\d \{ animation-delay:\s*([\d.]+)s#', $bloc, $m );
	return $m[1] ? max( array_map( 'floatval', $m[1] ) ) + $duree : 0.0;
};

$fin = max( $phase( 'hp-d', 0.6 ), $phase( 'hp-dr', 0.9 ), $phase( 'hp-fd', 0.6 ) );

check( 'Chronologie : 12 délais déclarés (3 phases × 4 vignettes)', 12 === count( $delais ) );
check( 'Chronologie : fin réelle à ' . number_format( $fin, 2 ) . ' s — celle de production',
	abs( $fin - 2.60 ) < 0.01 );
// Les délais de production, à la milliseconde près.
check( 'Chronologie : délais identiques à la production',
	array( 0.05, 0.12, 0.19, 0.26, 0.35, 0.80, 1.25, 1.70, 0.55, 1.00, 1.45, 1.90 ) === $delais );
check( 'Chronologie : les phases se suivent sans trou',
	min( $delais ) <= 0.10 && max( $delais ) <= 2.2 );

// --- aucune annotation miniature ne subsiste ---
check( 'CSS : aucune règle hp-mini résiduelle', ! str_contains( $css_src, 'hp-mini' ) );

// --- le CSS WordPress vient bien de la source ---
$decls = static function ( $c ) {
	preg_match_all( '/([-a-z]+)\s*:\s*([^;{}]+)[;}]/', preg_replace( '#/\*.*?\*/#s', '', $c ), $m, PREG_SET_ORDER );
	$o = array_map( static fn( $x ) => trim( $x[1] ) . ':' . trim( $x[2] ), $m );
	sort( $o );
	return $o;
};

check( 'CSS WordPress : déclarations identiques à la source', $decls( $css_src ) === $decls( $css_wp ) );
check( 'CSS WordPress : les sélecteurs du hero sont portés',
	str_contains( $css_wp, '.urbizen-accueil .hero-plan .hp-fade' )
	&& str_contains( $css_wp, '.urbizen-accueil .hero-plan .hp-draw' ) );
check( 'CSS WordPress : les keyframes ne sont pas portés',
	str_contains( $css_wp, '@keyframes hpFade' ) && ! str_contains( $css_wp, '.urbizen-accueil @keyframes' ) );

// ------------------------------------------------- gabarits synchronisés ---
$src   = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front = file_get_contents( $theme . '/templates/front-page.html' );

check( 'Les deux gabarits sont strictement identiques', $src === $front );
check( 'Empreintes SHA-256 identiques', hash( 'sha256', $src ) === hash( 'sha256', $front ) );
check( 'La planche est identique dans les trois fichiers',
	planche( $src ) === planche( $front )
	&& planche( $src ) === planche( file_get_contents( $racine . '/frontend/homepage/index.html' ) ) );

// ---------------------------------------- rien d'autre n'a été touché ------
foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );

	check( "[$nom] les textes de la PR #12 sont intacts",
		str_contains( $h, 'Un concepteur humain dédié' )
		&& str_contains( $h, '<b>Plans et pièces graphiques réalisés par nos soins</b>' )
		&& str_contains( $h, 'Un interlocuteur humain dédié' ) );
	check( "[$nom] les illustrations de la PR #13 sont intactes",
		4 === substr_count( $h, 'class="exemple-img"' ) && str_contains( $h, 'M74 45 L74 32' ) );
	check( "[$nom] les 5 tarifs sont intacts",
		2 === substr_count( $h, '149&nbsp;€' ) && 1 === substr_count( $h, '249&nbsp;€' )
		&& 2 === substr_count( $h, '449&nbsp;€' ) && 1 === substr_count( $h, '649&nbsp;€' )
		&& 1 === substr_count( $h, '849&nbsp;€' ) );
}

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
