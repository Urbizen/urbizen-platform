<?php
/**
 * Génère un aperçu autonome d'un document légal.
 *
 * Même mécanique que `tests/tarifs/apercu-tarifs.php`, dont ce script est le
 * pendant pour les trois pages légales : le dépôt ne contient pas de
 * WordPress, et un gabarit de blocs n'est pas un fichier qu'on ouvre mais une
 * suite de directives qu'un moteur assemble.
 *
 * USAGE
 *
 *   php tests/legal/apercu-legal.php page-mentions-legales > tests/legal/apercu-mentions.html
 *   php tests/legal/apercu-legal.php page-cgv              > tests/legal/apercu-cgv.html
 *   php tests/legal/apercu-legal.php page-confidentialite  > tests/legal/apercu-confidentialite.html
 *
 * FIXTURE DE MÉDIATEUR — APERÇU UNIQUEMENT
 *
 * Passer `--fixture-mediateur` injecte un médiateur fictif afin de vérifier le
 * rendu de la section correspondante. Cette valeur n'existe QUE dans l'aperçu :
 * elle ne touche ni le thème, ni la base, ni la production. Le contrôle de
 * préparation `test-legal-readiness.php` l'ignore délibérément — sans quoi une
 * fixture de test pourrait faire croire à une conformité qui n'existe pas.
 *
 * Le fichier produit est un artefact, ignoré par Git.
 */

$racine  = dirname( __DIR__, 2 );
$theme   = $racine . '/wordpress/urbizen-child';
$gabarit = $argv[1] ?? 'page-mentions-legales';
$fixture = in_array( '--fixture-mediateur', (array) $argv, true );

if ( ! preg_match( '/^page-(mentions-legales|cgv|confidentialite)$/', $gabarit ) ) {
	fwrite( STDERR, "Gabarit inconnu : $gabarit\n" );
	exit( 2 );
}

define( 'ABSPATH', $racine );
const APERCU_URI = '/wordpress/urbizen-child';

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function add_action() {}
function add_filter() {}
function get_stylesheet_directory() { return ABSPATH . '/wordpress/urbizen-child'; }
function get_stylesheet_directory_uri() { return APERCU_URI; }
function get_template_directory() { return ABSPATH . '/wordpress/urbizen-child'; }
function get_template_directory_uri() { return APERCU_URI; }
function get_theme_file_uri( $f = '' ) { return APERCU_URI . '/' . ltrim( (string) $f, '/' ); }
function home_url( $p = '/' ) { return $p; }
function is_front_page() { return false; }
function is_singular() { return true; }
function get_queried_object_id() { return 1; }
function wp_kses_post( $t ) { return (string) $t; }

$GLOBALS['apercu_gabarit'] = $gabarit;
function get_page_template_slug( $id = 0 ) { return $GLOBALS['apercu_gabarit']; }

/*
 * DOUBLONS AJOUTÉS LE 15 AOÛT 2026 — L'EN-TÊTE INTERROGE LA PAGE D'ARTICLES
 *
 * `header-accueil.php` a gagné l'entrée « Guides » le 14 août 2026. Pour savoir
 * s'il faut l'allumer, il appelle `get_option( 'page_for_posts' )`, puis
 * `get_permalink()`, et distingue `is_home()` des autres contextes.
 *
 * `tests/homepage/test-fidelite.php` a reçu ces doublons le même jour ; cet
 * aperçu-ci ne les a pas eus, et rendait donc les trois pages légales sur une
 * erreur fatale « Call to undefined function get_option() ». Le banc
 * `test-geometrie-legal.py`, qui mesure un rendu réel, échouait en conséquence.
 *
 * Les valeurs décrivent un site où /guides/ existe et où l'on est ailleurs que
 * dessus : c'est exactement la situation d'une page légale. Aucune entrée du
 * menu ne pointant vers une page légale, `aria-current` ne s'y pose sur rien —
 * ce qui est le comportement attendu, et non un effet du doublon.
 */
function get_option( $nom, $defaut = false ) {
	return 'page_for_posts' === $nom ? 1204 : $defaut;
}
function get_permalink( $id = 0 ) {
	return 1204 === (int) $id ? '/guides/' : '/' . $GLOBALS['apercu_gabarit'] . '/';
}
function is_home() { return false; }
function is_category() { return false; }
function is_tag() { return false; }
function is_date() { return false; }
function untrailingslashit( $chaine ) { return rtrim( (string) $chaine, '/\\' ); }

/**
 * Charge les fonctions de données légales depuis le thème, sans exécuter tout
 * `functions.php` — dont l'amorçage suppose un WordPress complet.
 *
 * @return void
 */
function apercu_charger_donnees() {
	$src = (string) file_get_contents( get_stylesheet_directory() . '/functions.php' );

	if ( ! preg_match( '/^function urbizen_child_donnees_legales\(\).*?^}$/ms', $src, $m ) ) {
		fwrite( STDERR, "urbizen_child_donnees_legales() introuvable\n" );
		exit( 1 );
	}

	eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- source du dépôt.
}

apercu_charger_donnees();

if ( $fixture ) {
	// Fixture d'aperçu, jamais de production : voir l'en-tête de ce fichier.
	function urbizen_child_donnees_legales_fixture() {
		$d              = urbizen_child_donnees_legales();
		$d['mediateur'] = array( 'nom' => 'FIXTURE — MÉDIATEUR DE TEST', 'site' => 'https://example.invalid' );

		return $d;
	}
}

/**
 * Rend un fichier PHP et retourne sa sortie.
 *
 * @param string $chemin Chemin absolu.
 * @return string
 */
function apercu_rendre_php( $chemin ) {
	ob_start();
	include $chemin;

	return (string) ob_get_clean();
}

/**
 * Déplie un gabarit de blocs en HTML.
 *
 * @param string $contenu Contenu du gabarit.
 * @return string
 */
function apercu_deplier( $contenu ) {
	global $theme;

	$contenu = preg_replace_callback(
		'/<!--\s*wp:template-part\s*(\{.*?\})\s*\/-->/s',
		function ( $m ) use ( $theme ) {
			$attrs = json_decode( $m[1], true );
			$part  = $theme . '/parts/' . ( $attrs['slug'] ?? '' ) . '.html';

			if ( ! is_file( $part ) ) {
				return '';
			}

			$classe = trim( 'wp-block-template-part ' . ( $attrs['className'] ?? '' ) );

			return '<div class="' . esc_attr( $classe ) . '">' . apercu_deplier( (string) file_get_contents( $part ) ) . '</div>';
		},
		$contenu
	);

	$contenu = preg_replace_callback(
		'/<!--\s*wp:pattern\s*(\{.*?\})\s*\/-->/s',
		function ( $m ) use ( $theme ) {
			$attrs   = json_decode( $m[1], true );
			$slug    = (string) ( $attrs['slug'] ?? '' );
			$fichier = $theme . '/patterns/' . substr( $slug, strrpos( $slug, '/' ) + 1 ) . '.php';

			return is_file( $fichier ) ? apercu_deplier( apercu_rendre_php( $fichier ) ) : '';
		},
		$contenu
	);

	return preg_replace( '/<!--\s*\/?wp:html\s*-->/', '', $contenu );
}

$fichier = $theme . '/templates/' . $gabarit . '.html';

if ( ! is_file( $fichier ) ) {
	fwrite( STDERR, "Gabarit introuvable : $fichier\n" );
	exit( 2 );
}

$corps = apercu_deplier( (string) file_get_contents( $fichier ) );

// Ordre EXACT de mise en file par urbizen_child_enqueue_accueil().
$liens = '';

foreach ( array(
	'/assets/css/urbizen-fonts.css',
	'/assets/css/urbizen-tokens.css',
	'/style.css',
	'/assets/css/urbizen-homepage.css',
	'/assets/css/urbizen-accueil-entete.css',
	'/assets/css/urbizen-pages.css',
) as $feuille ) {
	if ( is_file( $theme . $feuille ) ) {
		$liens .= '<link rel="stylesheet" href="' . APERCU_URI . $feuille . '" />' . "\n";
	}
}

$titres = array(
	'page-mentions-legales' => 'Mentions légales | Urbizen',
	'page-cgv'              => 'Conditions générales de vente | Urbizen',
	'page-confidentialite'  => 'Politique de confidentialité | Urbizen',
);

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $titres[ $gabarit ] ); ?></title>
<?php echo $liens; ?>
</head>
<body class="u-grid-bg">
<?php echo $corps; ?>
</body>
</html>
