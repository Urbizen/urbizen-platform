<?php
/**
 * Banc d'essai de fidélité du portage WordPress de la page d'accueil.
 *
 * Compare le markup rendu par les patterns et par le gabarit avec la maquette
 * de référence `frontend/homepage/index.html`. Toute divergence autre que
 * l'URL du logo et ses dimensions intrinsèques fait échouer le test.
 *
 * Hors WordPress : les quelques fonctions utilisées sont doublées ci-dessous.
 * Aucun accès réseau, aucune base de données.
 */

define( 'ABSPATH', __DIR__ );

$racine   = dirname( __DIR__, 2 );
$theme    = $racine . '/wordpress/urbizen-child';
$maquette = $racine . '/frontend/homepage/index.html';

function get_theme_file_uri( $chemin = '' ) {
	return 'https://exemple.test/wp-content/themes/urbizen-child/' . ltrim( $chemin, '/' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-66s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

$lignes = explode( "\n", file_get_contents( $maquette ) );

/** Extrait des lignes de la maquette, bornes 1-indexées incluses. */
function maquette( array $lignes, $debut, $fin ) {
	return implode( "\n", array_slice( $lignes, $debut - 1, $fin - $debut + 1 ) );
}

/** Rend un pattern et retire l'enrobage de bloc. */
function rendre_pattern( $fichier ) {
	ob_start();
	include $fichier;
	$sortie = ob_get_clean();
	$sortie = preg_replace( '/^.*?<!-- wp:html -->\n/s', '', $sortie );
	$sortie = preg_replace( '/\n<!-- \/wp:html -->\s*$/s', '', $sortie );
	return $sortie;
}

/** Remet la ligne du logo dans sa forme d'origine, pour comparer le reste. */
function neutraliser_logo( $html ) {
	$html = preg_replace(
		'#<img src="https://exemple\.test/wp-content/themes/urbizen-child/assets/img/logo-urbizen\.png"\s*\n\s*alt="([^"]*)"((?: class="[^"]*")?) />#',
		'<img src="assets/logo-urbizen.png" alt="$1"$2 />',
		$html
	);
	return $html;
}

// ---------------------------------------------------------------- en-tête ---
$entete_rendu = neutraliser_logo( rendre_pattern( $theme . '/patterns/header-accueil.php' ) );
$entete_ref   = maquette( $lignes, 77, 110 );

check( 'En-tête : markup identique à la maquette (hors URL du logo)', $entete_rendu === $entete_ref );
check( 'En-tête : logo résolu par le thème, aucune URL en dur',
	str_contains( rendre_pattern( $theme . '/patterns/header-accueil.php' ), '/wp-content/themes/urbizen-child/assets/img/logo-urbizen.png' ) );
// Mesuré en conditions réelles : ces attributs donnent à l'image un rapport
// d'aspect définitif qui change le calcul flex de l'en-tête. Le logo passait de
// 109 à 290 px et le menu perdait 135 px. Leur absence est donc une exigence.
check( 'En-tête : AUCUN attribut width/height sur le logo',
	! preg_match( '/<img[^>]*logo-urbizen\.png[^>]*(width|height)=/', rendre_pattern( $theme . '/patterns/header-accueil.php' ) ) );
// Le nombre de liens est comparé à la maquette, pas à un total deviné :
// 17 balises <a> — menu desktop, menu mobile, connexion et CTA.
check( 'En-tête : tous les liens de la maquette présents',
	substr_count( $entete_rendu, '<a ' ) === substr_count( $entete_ref, '<a ' ) );
check( 'En-tête : lien de connexion et CTA « Démarrer » conservés',
	str_contains( $entete_rendu, 'class="link-login"' )
	&& str_contains( $entete_rendu, 'js-start' ) );
check( 'En-tête : burger mobile et ses attributs ARIA conservés',
	str_contains( $entete_rendu, 'class="burger"' )
	&& str_contains( $entete_rendu, 'aria-expanded="false"' )
	&& str_contains( $entete_rendu, 'aria-controls="mmenu"' ) );

// ----------------------------------------------------------- pied de page ---
$pied_rendu = neutraliser_logo( rendre_pattern( $theme . '/patterns/footer-accueil.php' ) );
$pied_ref   = maquette( $lignes, 412, 447 );

check( 'Pied de page : markup identique à la maquette (hors URL du logo)', $pied_rendu === $pied_ref );
// La grille .foot est en quatre colonnes (CSS) : une marque + trois listes.
check( 'Pied de page : marque et trois listes de liens conservées',
	str_contains( $pied_rendu, 'class="foot-brand"' )
	&& 3 === substr_count( $pied_rendu, '<ul>' )
	&& substr_count( $pied_rendu, '<h4>' ) === substr_count( $pied_ref, '<h4>' ) );
check( 'Pied de page : AUCUN attribut width/height sur le logo',
	! preg_match( '/<img[^>]*logo-urbizen\.png[^>]*(width|height)=/', rendre_pattern( $theme . '/patterns/footer-accueil.php' ) ) );
check( 'Pied de page : coordonnées inchangées',
	str_contains( $pied_rendu, 'contact@urbizen.fr' ) && str_contains( $pied_rendu, '+33 6 64 89 58 15' ) );

// ---------------------------------------------------------------- gabarit ---
$gabarit = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );

check( 'Gabarit : aucun PHP', ! str_contains( $gabarit, '<?php' ) && ! str_contains( $gabarit, '<?=' ) );
check( 'Gabarit : appelle les deux template parts Urbizen',
	str_contains( $gabarit, '"slug":"header-urbizen"' ) && str_contains( $gabarit, '"slug":"footer-urbizen"' ) );
check( 'Gabarit : ne réutilise pas les parts Hostinger',
	! preg_match( '/"slug":"(header|footer|footer-landing|superposition-de-navigation)"/', $gabarit ) );
check( 'Gabarit : bloc cadastre présent avec storageKey « accueil »',
	str_contains( $gabarit, '<!-- wp:urbizen/cadastre' ) && str_contains( $gabarit, '"storageKey":"accueil"' ) );
check( 'Gabarit : ancien point de montage supprimé', ! str_contains( $gabarit, 'cadastre-mount' ) );
check( 'Gabarit : conteneur de portée .urbizen-accueil', str_contains( $gabarit, '<div class="urbizen-accueil">' ) );

// Corps : le contenu de <main> doit être identique à la maquette, au bloc près.
$corps_rendu = $gabarit;
$corps_rendu = preg_replace( '/.*?<!-- wp:html -->\n<main/s', '<main', $corps_rendu, 1 );
$corps_rendu = preg_replace( '#</main>.*#s', '</main>', $corps_rendu );
$corps_rendu = str_replace(
	array(
		"      <!-- Le bloc urbizen/cadastre est rendu ici par WordPress -->\n<!-- /wp:html -->\n\n<!-- wp:urbizen/cadastre {\"label\":\"Adresse du projet\",\"placeholder\":\"Commencez à saisir une adresse…\",\"continueLabel\":\"Continuer\",\"storageKey\":\"accueil\"} /-->\n\n<!-- wp:html -->\n",
	),
	array(
		"      <!-- Le composant partagé se monte ici -->\n      <div id=\"cadastre-mount\"></div>\n",
	),
	$corps_rendu
);
$corps_ref = maquette( $lignes, 112, 409 );
check( 'Corps : les 12 sections identiques à la maquette', $corps_rendu === $corps_ref );

check( 'Corps : SVG du hero inline et inchangé',
	substr_count( $gabarit, '<svg' ) === substr_count( $corps_ref, '<svg' )
	&& str_contains( $gabarit, 'DP6 · INSERTION 3D' ) );

// ------------------------------------------------------------ ressources ---
foreach ( array(
	'assets/css/urbizen-tokens.css', 'assets/css/urbizen-fonts.css', 'assets/css/urbizen-homepage.css',
	'assets/js/urbizen-homepage.js', 'assets/img/logo-urbizen.png',
	'assets/fonts/space-grotesk-latin.woff2', 'assets/fonts/ibm-plex-sans-latin.woff2',
	'assets/fonts/ibm-plex-mono-latin.woff2', 'assets/fonts/OFL-space-grotesk.txt', 'assets/fonts/OFL-ibm-plex.txt',
) as $f ) {
	check( 'Ressource présente : ' . $f, is_file( $theme . '/' . $f ) );
}

$js = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );
check( 'JavaScript : aucun montage manuel du cadastre', ! str_contains( $js, 'UrbizenCadastre.mount' ) );
check( 'JavaScript : comportements de la maquette conservés',
	str_contains( $js, 'urbizen:parcel-confirmed' ) && str_contains( $js, 'js-start' ) && str_contains( $js, 'burger' ) );

$css     = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );
$css_ref = file_get_contents( $racine . '/frontend/homepage/homepage.css' );

check( 'CSS : aucun !important ajouté',
	substr_count( $css, '!important' ) === substr_count( $css_ref, '!important' ) );

// `:root` désigne <html>, un ancêtre de la portée : le préfixer produit un
// sélecteur qui ne peut jamais correspondre. C'est ce défaut qui avait rendu
// muette la règle `--u-pad: 18px` et décalé de 10 px la rupture mobile.
check( 'CSS : aucun sélecteur mort « .urbizen-accueil :root »',
	! str_contains( $css, '.urbizen-accueil :root' ) );
check( 'CSS : aucun sélecteur mort « .urbizen-accueil body »',
	! str_contains( $css, '.urbizen-accueil body' ) );

// La règle décisive du responsive mobile doit exister, portée par le conteneur.
check( 'CSS : la media query 420px porte --u-pad sur le conteneur',
	(bool) preg_match(
		'/@media\s*\(max-width:\s*420px\)\s*\{[^}]*\.urbizen-accueil\s*\{[^}]*--u-pad:\s*18px/',
		$css
	) );

// Déclarations : mêmes couples propriété/valeur, dans le même ordre trié.
$decls = static function ( $source ) {
	$sans = preg_replace( '#/\*.*?\*/#s', '', $source );
	preg_match_all( '/([-a-z]+)\s*:\s*([^;{}]+)[;}]/', $sans, $m, PREG_SET_ORDER );
	$out = array_map( static fn( $x ) => trim( $x[1] ) . ':' . trim( $x[2] ), $m );
	sort( $out );
	return $out;
};
$d_ref = $decls( $css_ref );
$d_css = $decls( $css );
check( 'CSS : 541 déclarations conservées', 541 === count( $d_ref ) && 541 === count( $d_css ) );
check( 'CSS : aucune valeur de propriété modifiée', $d_ref === $d_css );

$fonts = file_get_contents( $theme . '/assets/css/urbizen-fonts.css' );
check( 'Polices : aucune référence à Google', ! preg_match( '#url\([^)]*(googleapis|gstatic)#', $fonts ) );
check( 'Polices : seules les graisses relevées sont déclarées',
	str_contains( $fonts, 'font-weight: 500 700' )   // Space Grotesk 500/600/700
	&& str_contains( $fonts, 'font-weight: 400 600' ) // IBM Plex Sans 400/500/600
	&& str_contains( $fonts, 'font-weight: 400;' ) ); // IBM Plex Mono 400

$json = json_decode( file_get_contents( $theme . '/theme.json' ), true );
check( 'theme.json : gabarit déclaré dans customTemplates',
	'page-accueil-urbizen' === ( $json['customTemplates'][0]['name'] ?? '' ) );
check( 'theme.json : parts Urbizen déclarés',
	array( 'header-urbizen', 'footer-urbizen' ) === array_column( $json['templateParts'] ?? array(), 'name' ) );
// Comparaison au fichier tel qu'il était avant ce portage : ni la palette ni
// le CSS personnalisé hérité de la production ne doivent avoir bougé.
$json_ref = json_decode( file_get_contents( $racine . '/tests/homepage/theme-json-reference.json' ), true );
check( 'theme.json : palette intacte',
	( $json_ref['settings']['color']['palette'] ?? null ) === ( $json['settings']['color']['palette'] ?? null ) );
check( 'theme.json : CSS personnalisé intact',
	( $json_ref['styles']['css'] ?? null ) === ( $json['styles']['css'] ?? null ) );
check( 'theme.json : seules customTemplates et templateParts ont été ajoutées',
	array( 'customTemplates', 'templateParts' ) === array_values( array_diff( array_keys( $json ), array_keys( $json_ref ) ) )
	&& array() === array_diff( array_keys( $json_ref ), array_keys( $json ) ) );

echo "\n", 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
