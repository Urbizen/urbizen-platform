<?php
/**
 * Banc d'essai de la page commerciale « Conception de plans sur mesure ».
 *
 * Contrôles statiques sur le thème enfant (aucun WordPress requis) :
 * structure du gabarit, galerie, protections, absence de fuite des fichiers
 * maîtres, carte d'accueil et enregistrement du gabarit. Sort en code ≠ 0 à
 * la première assertion en échec.
 */

$theme = dirname( __DIR__, 2 ) . '/wordpress/urbizen-child';
$repo  = dirname( __DIR__, 2 );

$errors = 0;
function check( $label, $cond ) {
	global $errors;
	if ( $cond ) {
		echo "  OK   $label\n";
	} else {
		echo "  FAIL $label\n";
		$errors++;
	}
}

$tpl  = (string) file_get_contents( "$theme/templates/page-conception.html" );
$css  = (string) file_get_contents( "$theme/assets/css/urbizen-conception.css" );
$js   = (string) file_get_contents( "$theme/assets/js/urbizen-conception-gallery.js" );
$home    = (string) file_get_contents( "$theme/templates/front-page.html" );
$home_js = (string) file_get_contents( "$theme/assets/js/urbizen-homepage.js" );
$header  = (string) file_get_contents( "$theme/patterns/header-accueil.php" );
$fns     = (string) file_get_contents( "$theme/functions.php" );
$json    = (string) file_get_contents( "$theme/theme.json" );

// --- Structure du gabarit ---
check( 'Un seul <h1> dans le gabarit', 1 === preg_match_all( '/<h1[\s>]/', $tpl ) );
check( 'Formulaire Conception présent une seule fois',
	1 === substr_count( $tpl, '[urbizen_formulaire formtype="conception"]' ) );
check( 'Hiérarchie des titres : au moins 6 <h2>', preg_match_all( '/<h2[\s>]/', $tpl ) >= 6 );

// --- Ancres attendues ---
foreach ( array( 'realisations', 'fonctionnement', 'formulaire-conception' ) as $anchor ) {
	check( "Ancre #$anchor présente", str_contains( $tpl, "id=\"$anchor\"" ) );
}

// --- Galerie : 6 rendus, tous illustrés et décrits ---
preg_match_all( '/<img\b[^>]*>/', $tpl, $imgs );
$imgs = $imgs[0];
check( 'Galerie + hero : 7 images (1 hero + 6 rendus)', 7 === count( $imgs ) );
$noalt = array_filter( $imgs, fn( $i ) => ! preg_match( '/\balt="[^"]+"/', $i ) );
check( 'Toutes les images ont un alt non vide', 0 === count( $noalt ) );

// --- Les dérivés référencés existent réellement ---
preg_match_all( '#/assets/images/conception/([a-z0-9\-]+\.webp)#', $tpl, $refs );
$missing = array();
foreach ( array_unique( $refs[1] ) as $file ) {
	if ( ! is_file( "$theme/assets/images/conception/$file" ) ) {
		$missing[] = $file;
	}
}
check( 'Tous les dérivés référencés existent', 0 === count( $missing ) );

// --- 12 dérivés présents (6 galerie 1500px + 6 cartes 800px) ---
$derives = glob( "$theme/assets/images/conception/*.webp" );
check( '12 dérivés WebP présents', 12 === count( $derives ) );

// --- Aucune fuite des fichiers maîtres ---
$masters = array( 'BALI', 'HOLLYWOOD', 'MIAMI', 'MONACO', 'SEATTLE', 'TOKYO' );
$leak    = array_filter( $masters, fn( $m ) => str_contains( $tpl, $m ) || str_contains( $home, $m ) || str_contains( $css, $m ) );
check( 'Aucun nom de fichier maître dans le thème', 0 === count( $leak ) );
check( 'Aucun chemin vers les sources hors dépôt', ! str_contains( $tpl . $home . $css, 'urbizen-assets' ) );

// --- Aucun lien de téléchargement / original ---
check( 'Aucun attribut download', ! preg_match( '/\bdownload\b/i', $tpl ) );
check( 'Aucune image non filigranée (suffixe -master/-full/-hd)',
	! preg_match( '/(master|-full|-hd|original)\.(webp|jpg|jpeg|png)/i', $tpl ) );

// --- Protection scopée, jamais globale ---
check( 'Conteneurs protégés marqués', substr_count( $tpl, 'data-urbizen-protected-media' ) >= 2 );
check( 'JS scopé via closest([data-urbizen-protected-media])',
	str_contains( $js, "closest('[data-urbizen-protected-media]')" ) );
check( 'JS ne désactive pas le clic droit globalement',
	! preg_match( '/document\.body|oncontextmenu\s*=\s*/', $js ) );
check( 'CSS de protection scopé .urbizen-page-conception',
	str_contains( $css, '.urbizen-page-conception [data-urbizen-protected-media]' ) );

// --- Deux accès distincts : carte vers le formulaire, menu vers la page ---
check( 'Carte Conception présente sur l\'accueil',
	str_contains( $home, 'data-projet="conception"' )
	&& str_contains( $home, '>Conception de plans sur mesure</span>' ) );
check( 'La carte d\'accueil ouvre directement #formulaire-conception',
	str_contains( $home_js, 'conception: "/conception/#formulaire-conception"' )
	&& str_contains( $home_js, 'if (selectedProjet === "conception")' )
	&& str_contains( $home_js, 'window.location.href = FORM_URLS.conception' ) );
check( 'Le menu Conception ouvre le haut de la page, sans ancre',
	str_contains( $header, 'href="https://urbizen.fr/conception/"' )
	&& str_contains( $header, '>Conception de plans</a>' )
	&& ! str_contains( $header, 'conception/#formulaire-conception' ) );

// --- Aucun lien mort vers des parcours non livrés (DP/PC) depuis la page ---
check( 'Pas de lien vers /declaration-prealable depuis la page conception',
	! str_contains( $tpl, 'declaration-prealable' ) );
check( 'Pas de lien vers /permis-de-construire depuis la page conception',
	! str_contains( $tpl, 'permis-de-construire' ) );

// --- Gabarit enregistré ---
check( 'page-conception déclaré dans theme.json', str_contains( $json, '"page-conception"' ) );
check( 'page-conception inscrit dans URBIZEN_CHILD_TEMPLATES_PAGES',
	preg_match( '/URBIZEN_CHILD_TEMPLATES_PAGES\s*=\s*array\([^)]*page-conception/s', $fns ) );
check( 'Détecteur urbizen_child_est_page_conception présent',
	str_contains( $fns, 'function urbizen_child_est_page_conception' ) );
check( 'Feuille et script Conception mis en file conditionnellement',
	str_contains( $fns, 'urbizen-conception.css' ) && str_contains( $fns, 'urbizen-conception-gallery.js' ) );
// Handle de style distinct du plugin (qui enregistre déjà « urbizen-conception »).
check( 'Handle de la feuille de page = urbizen-conception-page (pas de collision plugin)',
	str_contains( $fns, "wp_enqueue_style( 'urbizen-conception-page'" )
	&& ! preg_match( "/wp_enqueue_style\(\s*'urbizen-conception'/", $fns ) );

// --- Les sources ne sont pas versionnées ---
$tracked = shell_exec( "cd " . escapeshellarg( $repo ) . " && git ls-files | grep -iE '(BALI|HOLLYWOOD|MIAMI|MONACO|SEATTLE|TOKYO)\\.' 2>/dev/null" );
check( 'Aucun fichier maître versionné dans Git', empty( trim( (string) $tracked ) ) );

echo "\n" . ( $errors ? "ÉCHEC : $errors assertion(s) en échec\n" : "SUCCÈS : toutes les assertions passent\n" );
exit( $errors ? 1 : 0 );
