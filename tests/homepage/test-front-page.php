<?php
/**
 * Banc d'essai du gabarit front-page.html du thème enfant.
 *
 * Contexte : pour la page d'accueil du site, la hiérarchie de WordPress
 * consulte `front-page` AVANT le gabarit personnalisé de la page. Le thème
 * parent fournissant son propre `templates/front-page.html`, affecter le
 * gabarit « Accueil Urbizen » à la page d'accueil restait sans effet.
 *
 * Le thème enfant fournit donc son propre `front-page.html`, copie stricte de
 * `page-accueil-urbizen.html`. Ce banc contrôle :
 *
 *   - que le fichier existe ;
 *   - qu'il est identique octet pour octet au gabarit validé ;
 *   - qu'il n'appelle aucun en-tête ni pied de page Hostinger ;
 *   - qu'il contient le bloc cadastre avec storageKey « accueil » ;
 *   - qu'il ne rend pas le contenu Gutenberg historique de la page ;
 *   - que les ressources conditionnelles de l'accueil sont bien mises en file
 *     lorsque WordPress emploie ce gabarit.
 *
 * Hors WordPress : les fonctions utilisées sont doublées ci-dessous. Aucun
 * accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-70s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

// ------------------------------------------------------------- existence ---
$source_path = $theme . '/templates/page-accueil-urbizen.html';
$front_path  = $theme . '/templates/front-page.html';

check( 'front-page.html existe dans le thème enfant', is_file( $front_path ) );
check( 'page-accueil-urbizen.html est conservé', is_file( $source_path ) );

if ( ! is_file( $front_path ) || ! is_file( $source_path ) ) {
	echo "\nGABARIT ABSENT — contrôles interrompus\n";
	exit( 1 );
}

$front  = file_get_contents( $front_path );
$source = file_get_contents( $source_path );

// --------------------------------------------------- identité des copies ---
// Deux fichiers, donc deux occasions de diverger. L'invariant est l'égalité
// binaire : elle est simple, totale, et se régénère par
// `python3 scripts/sync-front-page.py`.
check( 'front-page.html strictement identique au gabarit Accueil Urbizen', $front === $source );
check( 'front-page.html : empreinte SHA-256 identique à la source',
	hash( 'sha256', $front ) === hash( 'sha256', $source ) );

// Le script de synchronisation doit lui aussi confirmer l'égalité : c'est lui
// qui sera lancé avant chaque déploiement.
$sync = escapeshellarg( $racine . '/scripts/sync-front-page.py' );
exec( 'python3 ' . $sync . ' --verifier 2>&1', $sortie_sync, $code_sync );
check( 'scripts/sync-front-page.py --verifier ne signale aucun écart', 0 === $code_sync );

if ( 0 !== $code_sync ) {
	echo '    ' . implode( "\n    ", $sortie_sync ) . "\n";
}

// ------------------------------------------------------------- structure ---
check( 'front-page : aucun PHP',
	! str_contains( $front, '<?php' ) && ! str_contains( $front, '<?=' ) );
check( 'front-page : appelle les deux template parts Urbizen',
	str_contains( $front, '"slug":"header-urbizen"' ) && str_contains( $front, '"slug":"footer-urbizen"' ) );
check( 'front-page : aucun en-tête ni pied de page Hostinger',
	! preg_match( '/"slug":"(header|footer|footer-landing|superposition-de-navigation)"/', $front ) );
check( 'front-page : conteneur de portée .urbizen-accueil',
	str_contains( $front, '<div class="urbizen-accueil">' ) );

// ---------------------------------------------------------------- bloc ---
check( 'front-page : bloc urbizen/cadastre présent',
	str_contains( $front, '<!-- wp:urbizen/cadastre' ) );
check( 'front-page : bloc cadastre avec storageKey « accueil »',
	(bool) preg_match( '/<!-- wp:urbizen\/cadastre \{[^}]*"storageKey":"accueil"[^}]*\} \/-->/', $front ) );

// ------------------------------------------------- pas de contenu ancien ---
// Le gabarit parent rendait <!-- wp:post-content /-->, c'est-à-dire les
// 106 Ko de blocs Gutenberg historiques de la page 4. Le nôtre ne doit rien
// en rendre : le contenu reste en base, intact, mais n'est plus affiché.
check( 'front-page : aucun bloc post-content',
	! str_contains( $front, 'wp:post-content' ) );
check( 'front-page : aucun bloc post-title ni post-excerpt',
	! str_contains( $front, 'wp:post-title' ) && ! str_contains( $front, 'wp:post-excerpt' ) );
check( 'front-page : aucune boucle de requête',
	! str_contains( $front, 'wp:query' ) );

// --------------------------------------------- theme.json inchangé côté ---
// front-page est un gabarit réservé de WordPress : il ne se déclare pas dans
// customTemplates, contrairement au gabarit assignable depuis l'éditeur.
$json = json_decode( file_get_contents( $theme . '/theme.json' ), true );
$noms = array_column( $json['customTemplates'] ?? array(), 'name' );

check( 'theme.json : « page-accueil-urbizen » toujours proposé dans l\'éditeur',
	in_array( 'page-accueil-urbizen', $noms, true ) );
check( 'theme.json : « front-page » n\'est pas déclaré en gabarit assignable',
	! in_array( 'front-page', $noms, true ) );

// ------------------------------------------ chargement des assets accueil ---
// On charge le vrai functions.php avec des doublures WordPress, puis on
// vérifie que les ressources sont mises en file dans les DEUX situations :
// accueil du site (front-page.html) et page portant le gabarit personnalisé.

define( 'ABSPATH', __DIR__ );

$GLOBALS['urbizen_theme_dir'] = $theme;
$GLOBALS['urbizen_front']     = false;
$GLOBALS['urbizen_singular']  = false;
$GLOBALS['urbizen_slug']      = '';
$GLOBALS['urbizen_styles']    = array();
$GLOBALS['urbizen_scripts']   = array();
$GLOBALS['urbizen_filtres']   = array();

function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['urbizen_filtres'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['urbizen_filtres'][ $hook ][] = $cb; }
function get_template_directory() { return $GLOBALS['urbizen_theme_dir'] . '/../hostinger-ai-theme'; }
function get_template_directory_uri() { return 'https://exemple.test/wp-content/themes/hostinger-ai-theme'; }
function get_stylesheet_directory() { return $GLOBALS['urbizen_theme_dir']; }
function get_stylesheet_directory_uri() { return 'https://exemple.test/wp-content/themes/urbizen-child'; }
function get_stylesheet_uri() { return get_stylesheet_directory_uri() . '/style.css'; }
function load_child_theme_textdomain( $domain, $path ) { return true; }
function wp_json_file_decode( $fichier, $args = array() ) { return json_decode( file_get_contents( $fichier ), true ); }
function is_front_page() { return $GLOBALS['urbizen_front']; }
function is_singular( $t = '' ) { return $GLOBALS['urbizen_singular']; }
function get_queried_object_id() { return $GLOBALS['urbizen_singular'] ? 4 : 0; }
function get_page_template_slug( $id = 0 ) { return $GLOBALS['urbizen_slug']; }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['urbizen_styles'][] = $handle; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['urbizen_scripts'][] = $handle; }

require_once $theme . '/functions.php';

/** Rejoue les accroches et renvoie les ressources mises en file. */
function contexte_accueil( $front, $singular, $slug ) {
	$GLOBALS['urbizen_front']    = $front;
	$GLOBALS['urbizen_singular'] = $singular;
	$GLOBALS['urbizen_slug']     = $slug;
	$GLOBALS['urbizen_styles']   = array();
	$GLOBALS['urbizen_scripts']  = array();

	urbizen_child_enqueue_accueil();

	return array(
		'styles'  => $GLOBALS['urbizen_styles'],
		'scripts' => $GLOBALS['urbizen_scripts'],
		'classes' => urbizen_child_body_class( array() ),
	);
}

$attendus = array( 'urbizen-fonts', 'urbizen-tokens', 'urbizen-homepage' );

// 1. Accueil du site — c'est front-page.html qui rend, et la métadonnée de
//    gabarit de la page vaut « no-title » : c'est précisément le cas qui
//    échouait avant ce correctif.
$r = contexte_accueil( true, true, 'no-title' );
check( 'Accueil du site : les 3 feuilles de style sont chargées',
	array() === array_diff( $attendus, $r['styles'] ) );
check( 'Accueil du site : le script de l\'accueil est chargé',
	in_array( 'urbizen-homepage', $r['scripts'], true ) );
check( 'Accueil du site : la classe u-grid-bg est ajoutée au body',
	in_array( 'u-grid-bg', $r['classes'], true ) );

// 2. Page portant le gabarit personnalisé — brouillon de recette, prévisualisation.
$r = contexte_accueil( false, true, 'page-accueil-urbizen' );
check( 'Gabarit personnalisé : les 3 feuilles de style sont chargées',
	array() === array_diff( $attendus, $r['styles'] ) );
check( 'Gabarit personnalisé : le script de l\'accueil est chargé',
	in_array( 'urbizen-homepage', $r['scripts'], true ) );

// 3. Toute autre page — rien ne doit être chargé.
$r = contexte_accueil( false, true, '' );
check( 'Autre page : aucune feuille de style de l\'accueil', array() === $r['styles'] );
check( 'Autre page : aucun script de l\'accueil', array() === $r['scripts'] );
check( 'Autre page : pas de classe u-grid-bg', ! in_array( 'u-grid-bg', $r['classes'], true ) );

// 4. Archive, résultat de recherche — non singulier, non accueil.
$r = contexte_accueil( false, false, '' );
check( 'Archive : aucune ressource de l\'accueil',
	array() === $r['styles'] && array() === $r['scripts'] );

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
