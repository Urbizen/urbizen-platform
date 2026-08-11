<?php
/**
 * Banc d'essai du repli SEO de la page Tarifs.
 *
 * POURQUOI CE BANC EXISTE
 *
 * `test-page-tarifs.php` vérifie que le garde-fou est ÉCRIT. C'est
 * insuffisant : la première version en contenait un, parfaitement lisible, et
 * la page a tout de même servi deux balises `<meta name="description">` en
 * production — parce qu'il énumérait trois greffons et que le site en employait
 * un quatrième, All in One SEO Pack.
 *
 * Une lecture de source ne pouvait pas attraper cela. Ce banc EXÉCUTE donc les
 * deux fonctions, greffon par greffon, et compte les balises réellement
 * émises.
 *
 * Les fonctions sont extraites du source plutôt que d'inclure tout
 * `functions.php`, dont l'amorçage suppose un WordPress complet.
 *
 * Aucune donnée réelle, aucun réseau, aucune base.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

define( 'ABSPATH', $racine );

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @param string $detail  Précision affichée en cas d'échec.
 * @return void
 */
function check( $libelle, $reussi, $detail = '' ) {
	global $echecs;

	if ( ! $reussi ) {
		++$echecs;
	}

	printf( "%-72s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );

	if ( ! $reussi && '' !== $detail ) {
		echo '    ' . $detail . "\n";
	}
}

// --- Doublures WordPress ----------------------------------------------------
function __( $t, $d = '' ) { return $t; }
function esc_attr__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function apply_filters( $hook, $valeur ) { return $valeur; }

/**
 * La page courante est-elle la page Tarifs ? Toujours oui dans ce banc :
 * on teste le garde-fou SEO, pas la détection de gabarit — celle-ci a son
 * propre contrôle dans `test-page-tarifs.php`.
 *
 * @return bool
 */
function urbizen_child_est_page_tarifs() { return true; }

// --- Extraction des deux fonctions sous test --------------------------------
$src = (string) file_get_contents( $theme . '/functions.php' );

foreach ( array( 'urbizen_child_seo_gere_ailleurs', 'urbizen_child_description_tarifs' ) as $fn ) {
	if ( ! preg_match( '/^function ' . $fn . '\(\).*?^}$/ms', $src, $m ) ) {
		echo "Fonction introuvable dans functions.php : $fn\n";
		exit( 1 );
	}
	eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- source du dépôt.
}

/**
 * Compte les balises `meta name="description"` émises par le thème.
 *
 * @return int
 */
function descriptions_emises() {
	ob_start();
	urbizen_child_description_tarifs();
	$sortie = (string) ob_get_clean();

	return substr_count( $sortie, '<meta name="description"' );
}

// ======================================================================
// 1 · AUCUN GREFFON SEO — le thème comble le vide
// ======================================================================
check(
	'1 · sans greffon SEO : aucun greffon détecté',
	false === urbizen_child_seo_gere_ailleurs()
);
check(
	'1 · sans greffon SEO : le thème émet SA description (repli)',
	1 === descriptions_emises(),
	'balises émises : ' . descriptions_emises()
);

// ======================================================================
// 2 · AIOSEO ACTIF — le thème se retire
// ======================================================================
// C'est le greffon réellement installé sur urbizen.fr, et celui que la
// première version du garde-fou ignorait. On le simule par sa constante.
define( 'AIOSEO_VERSION', '4.9.0' );

check(
	'2 · AIOSEO actif : le greffon est détecté',
	true === urbizen_child_seo_gere_ailleurs()
);
check(
	'2 · AIOSEO actif : le thème n\'émet AUCUNE description',
	0 === descriptions_emises(),
	'balises émises : ' . descriptions_emises() . ' — le doublon de production est de retour'
);

// ======================================================================
// 3 · LES AUTRES GREFFONS RESTENT COUVERTS
// ======================================================================
// La régression corrigée ne doit pas en introduire une autre : les greffons
// déjà reconnus avant AIOSEO doivent l'être encore. On vérifie que chaque
// marqueur figure bien dans la fonction.
$fn_src = '';
if ( preg_match( '/^function urbizen_child_seo_gere_ailleurs\(\).*?^}$/ms', $src, $m ) ) {
	$fn_src = $m[0];
}

foreach ( array(
	'AIOSEO_VERSION'            => 'All in One SEO Pack',
	'WPSEO_VERSION'             => 'Yoast SEO',
	'RANK_MATH_VERSION'         => 'Rank Math',
	'SEOPRESS_VERSION'          => 'SEOPress',
	'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
) as $marqueur => $nom ) {
	check( "3 · greffon couvert : $nom", str_contains( $fn_src, $marqueur ) );
}

check(
	'3 · plusieurs marqueurs par greffon (constante, fonction, classe)',
	str_contains( $fn_src, 'function_exists' ) && str_contains( $fn_src, 'class_exists' )
);
check(
	'3 · un filtre permet de trancher sans toucher au code',
	str_contains( $fn_src, 'urbizen_child_seo_gere_ailleurs' )
	&& str_contains( $fn_src, 'apply_filters' )
);

// ======================================================================
// 4 · VERSION DU THÈME — clé du cache de patterns de WordPress
// ======================================================================
// Un pattern ajouté sans montée de version reste invisible : WordPress indexe
// son cache de patterns sur l'en-tête `Version`. C'est exactement ce qui a
// rendu la grille tarifaire vide au premier déploiement.
$style = (string) file_get_contents( $theme . '/style.css' );
preg_match( '/^Version:\s*(.+)$/m', $style, $v );
$version = isset( $v[1] ) ? trim( $v[1] ) : '';

// On contrôle un PLANCHER, pas une égalité. 0.2.1 est la version qui a corrigé
// l'incident de cache de patterns : la garantie utile est qu'on ne redescende
// jamais en dessous. Figer l'égalité obligerait à retoucher ce banc à chaque
// montée légitime — et un test qu'on modifie par habitude ne protège plus rien.
check(
	'4 · version du thème ≥ 0.2.1 (plancher de l\'incident de cache)',
	'' !== $version && version_compare( $version, '0.2.1', '>=' ),
	'lue : ' . $version
);
check(
	'4 · version du thème au format sémantique',
	1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ),
	'lue : ' . $version
);
check(
	'4 · la version du thème n\'a qu\'une source de vérité',
	1 === preg_match_all( '/^Version:/m', $style )
);
check(
	'4 · la version du lot de formulaires reste distincte et intacte',
	str_contains( $src, "URBIZEN_CHILD_FORMS_VERSION = '0.2.8'" )
);

// ======================================================================
// 5 · AUCUNE RÉFÉRENCE SEO À L'ANCIEN TARIF
// ======================================================================
$tpl     = (string) file_get_contents( $theme . '/templates/page-tarifs.html' );
$pattern = (string) file_get_contents( $theme . '/patterns/tarifs-grille.php' );

// On vise les CHAÎNES ÉMISES, pas le fichier entier : `functions.php` explique
// en commentaire pourquoi une source unique existe, et cette explication cite
// justement l'ancien tarif. Chercher « 149 » partout revenait à interdire d'en
// parler, y compris pour documenter la correction.
preg_match_all( "/esc_attr__\(\s*\n?\s*'([^']+)'/", $src, $descriptions );
preg_match_all( "/\\\$parties\['title'\] = __\( '([^']+)'/", $src, $titres );
$chaines_seo = implode( ' ', array_merge( $descriptions[1], $titres[1] ) );

check(
	'5 · aucune référence à 149 € dans les chaînes SEO émises par le thème',
	'' !== $chaines_seo && ! preg_match( '/\b149\b/', $chaines_seo ),
	'chaînes contrôlées : ' . substr( $chaines_seo, 0, 90 )
);
check( '5 · aucune référence à 149 € dans le gabarit', ! preg_match( '/\b149\b/', $tpl ) );
check( '5 · aucune référence à 149 € dans la grille', ! preg_match( '/\b149\b/', $pattern ) );

echo "\n";

if ( $echecs ) {
	echo "$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

echo "TOUS LES CONTROLES PASSENT\n";
exit( 0 );
