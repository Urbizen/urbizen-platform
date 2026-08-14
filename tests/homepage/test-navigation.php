<?php
/**
 * Banc du menu principal — contrat de balisage et de style.
 *
 * `test-navigation.py` rejoue le menu dans un moteur de rendu ; celui-ci fige
 * ce qui se lit dans les sources et n'a pas besoin de navigateur : la
 * composition du menu, le fait que le parent ne soit pas un lien, les valeurs
 * de couleur et de graisse réellement écrites, et le rendu du pattern sur une
 * PAGE INTERNE — que le banc de fidélité ne voit jamais, puisqu'il le rend
 * toujours en position d'accueil.
 *
 * L'EN-TÊTE RÉELLEMENT SERVI
 *
 * Le thème contient un menu WordPress hérité — `parts/header.html` et
 * `parts/superposition-de-navigation.html`, quatre entrées chacun — qu'AUCUN
 * gabarit n'appelle. Modifier « le menu » là ne changerait rien à l'écran.
 * Un contrôle ci-dessous vérifie que les douze gabarits passent tous par
 * `header-urbizen`, donc par le pattern : c'est le seul en-tête servi.
 *
 * Hors WordPress : les fonctions employées sont doublées ci-dessous.
 * Aucun accès réseau, aucune base de données.
 */

define( 'ABSPATH', __DIR__ );

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

// Le menu attendu, dans l'ordre. Seule description de référence : le banc ne
// devine pas, il compare.
const PREMIER_NIVEAU = array( 'Accueil', 'Nos prestations', 'Comment ça marche', 'Tarifs', 'Espace client', 'Contact' );
const PRESTATIONS    = array(
	'https://urbizen.fr/declarations-prealables/' => 'Déclaration préalable',
	'https://urbizen.fr/permis-de-construire/'    => 'Permis de construire',
	'https://urbizen.fr/conception/'              => 'Conception de plans',
);

// -------------------------------------------------- doublons WordPress ------
// `$GLOBALS` plutôt que des constantes : le pattern est rendu plusieurs fois,
// dans des positions différentes, et chaque rendu doit pouvoir les changer.
$GLOBALS['banc_front']     = true;
$GLOBALS['banc_permalien'] = '';

function is_front_page() { return $GLOBALS['banc_front']; }
function is_singular()   { return ! $GLOBALS['banc_front']; }
function get_permalink() { return $GLOBALS['banc_permalien']; }
function home_url( $c = '/' ) { return 'https://urbizen.fr' . $c; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function get_theme_file_uri( $c = '' ) {
	return 'https://urbizen.fr/wp-content/themes/urbizen-child/' . ltrim( $c, '/' );
}

/** Rend le pattern dans une position donnée. */
function rendre( $fichier, $front, $permalien = '' ) {
	$GLOBALS['banc_front']     = $front;
	$GLOBALS['banc_permalien'] = $permalien;
	ob_start();
	include $fichier;
	return ob_get_clean();
}

$pattern  = $theme . '/patterns/header-accueil.php';
$accueil  = rendre( $pattern, true );
$maquette = file_get_contents( $racine . '/frontend/homepage/index.html' );
$css      = file_get_contents( $racine . '/frontend/homepage/homepage.css' );

/** Extrait le bloc du menu de bureau d'un document. */
function bloc_nav( $html ) {
	return preg_match( '#<nav class="nav-links".*?</nav>#s', $html, $m ) ? $m[0] : '';
}

// ------------------------------------------- 1 · l'en-tête réellement servi --

$gabarits = glob( $theme . '/templates/*.html' );
check( 'Les 12 gabarits sont trouvés', 12 === count( $gabarits ), count( $gabarits ) . ' trouvé(s)' );

$hors = array();
foreach ( $gabarits as $g ) {
	if ( ! str_contains( file_get_contents( $g ), '"slug":"header-urbizen"' ) ) {
		$hors[] = basename( $g );
	}
}
check( 'Tous les gabarits passent par header-urbizen, donc par le pattern',
	array() === $hors, implode( ', ', $hors ) );

// Le menu Hostinger hérité peut rester dans le dépôt ; ce qui compte est qu'il
// ne soit branché nulle part. Le jour où il le serait, on servirait deux menus.
$morts = array();
foreach ( array( 'header', 'superposition-de-navigation' ) as $part ) {
	foreach ( $gabarits as $g ) {
		if ( preg_match( '/"slug":"' . preg_quote( $part, '/' ) . '"/', file_get_contents( $g ) ) ) {
			$morts[] = basename( $g ) . ' → ' . $part;
		}
	}
}
check( 'Le menu WordPress hérité n\'est branché sur aucun gabarit',
	array() === $morts, implode( ', ', $morts ) );

// ------------------------------------------- 2 · composition du menu ---------

$nav = bloc_nav( $accueil );
check( 'Le bloc du menu de bureau est repéré', '' !== $nav );

preg_match_all( '#<(a|button|span)\b[^>]*>(?:(?!</\1>).)*?</\1>#s', $nav, $tout );
// Les entrées de premier niveau : les enfants directs, dont le libellé propre.
preg_match_all( '#>([^<>]+)<#', preg_replace( '#<div class="nav-sous-menu".*?</div>#s', '', $nav ), $m );
$libelles = array_values( array_filter( array_map( 'trim', $m[1] ) ) );
$libelles = array_values( array_diff( $libelles, array( 'bientôt' ) ) );
check( 'Premier niveau : exactement ' . implode( ' · ', PREMIER_NIVEAU ),
	PREMIER_NIVEAU === $libelles, implode( ' | ', $libelles ) );

check( 'Le parent est un <button>, jamais un lien',
	str_contains( $nav, '<button type="button" class="nav-parent"' )
	&& ! preg_match( '#<a[^>]*class="[^"]*nav-parent#', $nav ) );
check( 'Le parent déclare son état et sa cible (aria-expanded + aria-controls)',
	str_contains( $nav, 'aria-expanded="false"' )
	&& str_contains( $nav, 'aria-controls="sous-menu-prestations"' )
	&& str_contains( $nav, 'id="sous-menu-prestations"' ) );
check( 'Le sous-menu est replié à l\'arrivée (attribut hidden)',
	(bool) preg_match( '#<div class="nav-sous-menu" id="sous-menu-prestations" hidden>#', $nav ) );

$manquantes = array();
foreach ( PRESTATIONS as $url => $texte ) {
	if ( ! str_contains( $accueil, '>' . $texte . '</a>' ) || ! str_contains( $accueil, $url ) ) {
		$manquantes[] = $texte;
	}
}
check( 'Les 3 prestations sont dans le sous-menu, aux URL inchangées',
	array() === $manquantes, implode( ', ', $manquantes ) );

// Le sous-menu regroupe ; il ne doit pas laisser de doublon au premier niveau.
$hors_sous_menu = preg_replace( '#<div class="nav-sous-menu".*?</div>#s', '', $nav );
$doublons = array();
foreach ( PRESTATIONS as $url => $texte ) {
	if ( str_contains( $hors_sous_menu, $texte ) ) { $doublons[] = $texte; }
}
check( 'Aucune prestation ne reste au premier niveau', array() === $doublons,
	implode( ', ', $doublons ) );

// ------------------------------------------- 3 · les promesses non tenues ----

// Délimiteur `~` et non `#` : `#` est ici dans le motif, et l'aurait refermé.
check( 'Aucun href vide ni href="#" dans tout l\'en-tête',
	! preg_match( '~href="#?"~', $accueil ) );
check( 'Espace client : un <span aria-disabled>, sans href, ni au bureau ni en mobile',
	2 === preg_match_all( '#<span class="(?:nav|mmenu)-bientot"[^>]*aria-disabled="true"#', $accueil )
	&& ! preg_match( '#class="(?:nav|mmenu)-bientot"[^>]*href=#', $accueil ) );
check( 'Aucune page « Nos prestations » n\'est inventée',
	! str_contains( $accueil, 'nos-prestations' ) && ! str_contains( $accueil, 'prestations/' ) );
check( 'Aucun lien vers un espace professionnels',
	! str_contains( $accueil, 'espace-professionnel' ) );
// L'entrée « Guides » attend la page index du lot G : la poser maintenant
// créerait un lien mort. Ce contrôle échouera le jour où elle arrivera — c'est
// son rôle : il force à créer la page et l'entrée dans le même mouvement.
check( 'Guides : aucune entrée tant que /guides/ n\'existe pas',
	! str_contains( $accueil, '/guides/' ) );

// ------------------------------------------- 4 · le tiroir mobile ------------

check( 'Le tiroir mobile porte le même menu, groupe compris',
	str_contains( $accueil, '<p class="mmenu-groupe">Nos prestations</p>' )
	&& 3 === substr_count( $accueil, 'class="mmenu-enfant"' ) );
check( 'L\'intitulé du groupe mobile n\'est pas un lien',
	! preg_match( '#<a[^>]*class="[^"]*mmenu-groupe#', $accueil ) );
check( 'Les deux appels à l\'action du tiroir sont conservés',
	str_contains( $accueil, 'js-open-inquiry' ) && str_contains( $accueil, '>Démarrer mon projet</a>' ) );

// ------------------------------------------- 5 · page courante ---------------

check( 'Accueil : « Accueil » est marqué page courante',
	1 <= substr_count( $accueil, '<a href="#top" aria-current="page">Accueil</a>' ) );
check( 'Accueil : rien d\'autre n\'est marqué page courante',
	2 === substr_count( $accueil, 'aria-current="page"' ),
	substr_count( $accueil, 'aria-current="page"' ) . ' occurrence(s) — attendu 2 (bureau + mobile)' );

// C'est ici que le banc de fidélité est aveugle : il rend toujours l'accueil.
$tarifs = rendre( $pattern, false, 'https://urbizen.fr/tarifs/' );
check( 'Page interne : « Tarifs » est marqué, et lui seul',
	str_contains( $tarifs, '"https://urbizen.fr/tarifs/" aria-current="page">Tarifs</a>' )
	&& 2 === substr_count( $tarifs, 'aria-current="page"' ) );
check( 'Page interne : « Accueil » cesse de l\'être et pointe vers l\'accueil',
	str_contains( $tarifs, '<a href="https://urbizen.fr/">Accueil</a>' )
	&& ! str_contains( $tarifs, '#top" aria-current' ) );

$conception = rendre( $pattern, false, 'https://urbizen.fr/conception/' );
// « Comment ça marche » vise une section de l'ACCUEIL. Sans le préfixe, l'ancre
// nue chercherait un #methode sur la page interne, où il n'existe pas : le lien
// ne mènerait nulle part, en silence.
check( 'Accueil : l\'ancre « Comment ça marche » est nue (défilement en page)',
	str_contains( $accueil, '<a href="#methode">Comment ça marche</a>' ) );
check( 'Page interne : la même ancre est préfixée par l\'accueil',
	str_contains( $tarifs, '<a href="https://urbizen.fr/#methode">Comment ça marche</a>' )
	&& ! str_contains( $tarifs, '<a href="#methode">' ) );
// L'ancre doit exister là où elle pointe, sinon le lien est mort.
check( 'La section #methode existe bien dans les gabarits d\'accueil',
	str_contains( file_get_contents( $theme . '/templates/front-page.html' ), 'id="methode"' )
	&& str_contains( $maquette, 'id="methode"' ) );

check( 'Page interne : une prestation ouverte allume aussi son groupe',
	str_contains( $conception, 'class="nav-parent is-actif"' )
	&& str_contains( $conception, '"https://urbizen.fr/conception/" aria-current="page">Conception de plans</a>' ) );
check( 'Le groupe reste éteint quand aucune prestation n\'est ouverte',
	str_contains( $tarifs, 'class="nav-parent"' ) && ! str_contains( $tarifs, 'is-actif' ) );

// ------------------------------------------- 6 · maquette et pattern ---------

check( 'La maquette porte le même menu que le pattern',
	bloc_nav( $maquette ) === bloc_nav( $accueil ),
	'la maquette est la source de vérité du banc de fidélité : les deux doivent bouger ensemble' );

// ------------------------------------------- 7 · lisibilité des intitulés ----

// Les valeurs sont lues dans la feuille, pas devinées : c'est le seul moyen de
// détecter qu'une règle a été remise à son ancienne valeur par mégarde.
preg_match( '#^\.nav-links a \{([^}]*)\}#m', $css, $regle );
$decl = $regle[1] ?? '';
check( 'Intitulés : couleur = var(--u-ink), le bleu nuit de la charte',
	str_contains( $decl, 'color: var(--u-ink)' ) && ! str_contains( $decl, 'var(--u-ink-soft)' ), trim( $decl ) );
check( 'Intitulés : graisse 600', str_contains( $decl, 'font-weight: 600' ), trim( $decl ) );
check( 'Intitulés : taille inchangée à 14 px', str_contains( $decl, 'font-size: 14px' ), trim( $decl ) );
check( 'Le parent du groupe suit les mêmes valeurs',
	(bool) preg_match( '#\.nav-parent \{[^}]*font-size: 14px;[^}]*font-weight: 600;[^}]*color: var\(--u-ink\)#s', $css ) );
check( 'Le tiroir mobile suit aussi',
	(bool) preg_match( '#\.mmenu a \{[^}]*color: var\(--u-ink\);[^}]*font-weight: 600#', $css ) );
// Mesuré sur le fond de l'en-tête (#FBFCFD) : --u-brand donne 4,25:1 et
// --u-brand-dk 6,12:1. Avec des libellés à 15,33:1, le premier ferait du
// survol un affaiblissement.
check( 'Survol : vert foncé de la charte, celui qui reste lisible',
	(bool) preg_match( '#\.nav-links a:hover[^{]*\{[^}]*color: var\(--u-brand-dk\)#s', $css )
	&& ! preg_match( '#\.nav-links a:hover[^{]*\{[^}]*color: var\(--u-brand\)[;\s]#s', $css ) );
check( 'Survol : marqué aussi par un soulignement, indépendant de la couleur',
	(bool) preg_match( '#\.nav-links a:hover[^{]*\{[^}]*text-decoration: underline#s', $css ) );
check( 'Page courante : elle garde le bleu nuit, pour ne pas se lire comme un survol',
	(bool) preg_match( '#\[aria-current="page"\][^{]*\{[^}]*color: var\(--u-ink\);[^}]*font-weight: 700#s', $css ) );
check( 'Page courante : signalée par une couleur ET un soulignement',
	(bool) preg_match( '#\[aria-current="page"\][^{]*\{[^}]*text-decoration: underline#s', $css ) );

// Une bordure aurait ajouté 2 px de hauteur à l'entrée courante : dans une
// barre à hauteur fixe et contenu centré, la ligne du menu se serait décalée.
check( 'Page courante : aucun décalage vertical (aucune bordure ajoutée)',
	! preg_match( '#\[aria-current="page"\][^{]*\{[^}]*border-(bottom|top)#s', $css ) );

check( 'Le focus clavier reste visible, et seulement au clavier',
	str_contains( $css, '.nav-parent:focus-visible' )
	&& str_contains( $css, '.nav-sous-menu a:focus-visible' )
	&& ! preg_match( '#\.nav-links a:focus \{#', $css ) );

// ------------------------------------------- 8 · ce qui ne doit pas bouger ---

check( 'Le logo est inchangé : aucune dimension, chargement immédiat',
	! preg_match( '/<img[^>]*logo-urbizen\.png[^>]*(width|height)=/', $accueil )
	&& str_contains( $accueil, 'loading="eager"' ) );
check( 'Le CTA « Démarrer mon projet » est inchangé',
	str_contains( $accueil, 'class="btn btn-primary btn-sm js-start"' )
	&& str_contains( $accueil, 'class="nav-cta-long">Démarrer mon projet</span>' )
	&& str_contains( $accueil, 'class="nav-cta-short"' ) );
check( 'Les icônes téléphone et compte, et le burger, sont inchangés',
	str_contains( $accueil, 'class="icon-btn link-tel"' )
	&& str_contains( $accueil, 'class="icon-btn link-login"' )
	&& str_contains( $accueil, 'class="burger"' ) );
check( 'Aucune règle n\'a été ajoutée sur le CTA ni sur le logo',
	! preg_match( '#\.nav-links[^{]*\.js-start#', $css )
	&& ! preg_match( '#\.nav-links[^{]*\.logo#', $css ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
