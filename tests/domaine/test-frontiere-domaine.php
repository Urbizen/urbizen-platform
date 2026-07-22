<?php
/**
 * Banc : `src/Domain/` ne dépend pas de WordPress.
 *
 * Une recherche naïve de « WP_ » serait inutilisable : elle signalerait le
 * commentaire que vous lisez. Ce banc **analyse les jetons PHP**, écarte
 * commentaires et chaînes, puis cherche des dépendances réellement
 * exécutables ou typées :
 *
 * - appel d'une fonction WordPress ;
 * - `new WP_*`, `WP_*::`, `instanceof WP_*` ;
 * - variable `$wpdb` ;
 * - `use` important un symbole WordPress ;
 * - type déclaré ou retourné mentionnant une classe WordPress ;
 * - nom de table WordPress écrit en clair.
 *
 * Un contrôle négatif obligatoire éprouve l'instrument lui-même : un fichier
 * qui mentionne `WP_User` dans un commentaire et dans une chaîne doit passer,
 * le même appelant `get_option()` doit tomber. Sans ce contrôle, un banc qui
 * ne détecte plus rien passerait pour un banc satisfait.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

/**
 * Motifs de fonctions WordPress.
 *
 * La liste couvre les préfixes réellement employés par le greffon. La couche
 * décisive n'est pas ici mais dans le banc d'intégration, qui confronte les
 * identifiants à la table des symboles d'un WordPress réellement chargé.
 */
const URBIZEN_FONCTIONS_WP = array(
	'/^wp_/', '/^get_option$/', '/^update_option$/', '/^add_option$/', '/^delete_option$/',
	'/^get_post/', '/^update_post/', '/^add_post/', '/^delete_post/',
	'/^get_user/', '/^update_user/', '/^add_user/', '/^get_current_user_id$/',
	'/^current_user_can$/', '/^is_user_logged_in$/',
	'/^add_action$/', '/^add_filter$/', '/^apply_filters$/', '/^do_action$/', '/^remove_/',
	'/^esc_/', '/^sanitize_/', '/^_e$/', '/^__$/', '/^_n$/', '/^_x$/', '/^esc_html__$/',
	'/^admin_url$/', '/^home_url$/', '/^site_url$/', '/^plugin_dir_/', '/^get_transient$/',
	'/^set_transient$/', '/^delete_transient$/', '/^register_/', '/^size_format$/',
	'/^is_admin$/', '/^is_singular$/', '/^is_front_page$/', '/^dbDelta$/', '/^absint$/',
	'/^wpdb$/',
);

/**
 * Analyse un fichier et rend ses dépendances WordPress effectives.
 *
 * @param string $chemin Fichier.
 * @return array<int, string>
 */
function dependances_wordpress( string $chemin ): array {
	$source = (string) file_get_contents( $chemin );
	$jetons = token_get_all( $source );

	// Commentaires et chaînes : écartés d'emblée. C'est ce qui évite les faux
	// positifs, et c'est aussi ce qui rend le contrôle négatif indispensable.
	$ignores = array(
		T_COMMENT,
		T_DOC_COMMENT,
		T_CONSTANT_ENCAPSED_STRING,
		T_ENCAPSED_AND_WHITESPACE,
		T_INLINE_HTML,
		T_WHITESPACE,
	);

	$utiles = array();

	foreach ( $jetons as $jeton ) {
		if ( is_array( $jeton ) ) {
			if ( in_array( $jeton[0], $ignores, true ) ) {
				continue;
			}

			$utiles[] = array( $jeton[0], $jeton[1] );
			continue;
		}

		$utiles[] = array( -1, $jeton );
	}

	$trouve = array();
	$total  = count( $utiles );

	for ( $i = 0; $i < $total; $i++ ) {
		list( $type, $texte ) = $utiles[ $i ];

		// Variable $wpdb.
		if ( T_VARIABLE === $type && '$wpdb' === $texte ) {
			$trouve[] = 'variable $wpdb';
			continue;
		}

		// Classe WordPress : new WP_X, WP_X::, instanceof WP_X, type WP_X.
		if ( T_STRING === $type && 1 === preg_match( '/^(WP_|wpdb$)/', $texte ) ) {
			$trouve[] = 'classe ' . $texte;
			continue;
		}

		if ( T_STRING !== $type ) {
			continue;
		}

		// Un appel : identifiant suivi d'une parenthèse ouvrante.
		$suivant = $utiles[ $i + 1 ][1] ?? '';

		if ( '(' !== $suivant ) {
			continue;
		}

		// Un appel de méthode (`->nom(`) ou statique (`::nom(`) n'est pas un
		// appel de fonction globale : il vise un objet du domaine.
		$precedent = $utiles[ $i - 1 ][1] ?? '';

		if ( '->' === $precedent || '::' === $precedent || 'function' === $precedent ) {
			continue;
		}

		foreach ( URBIZEN_FONCTIONS_WP as $motif ) {
			if ( 1 === preg_match( $motif, $texte ) ) {
				$trouve[] = 'appel ' . $texte . '()';
				break;
			}
		}
	}

	// `use` important un symbole WordPress.
	if ( 1 === preg_match_all( '/^\s*use\s+(\\\\?(?:WP_|wpdb)[A-Za-z0-9_\\\\]*)\s*;/m', $source, $imports ) ) {
		foreach ( $imports[1] as $importe ) {
			$trouve[] = 'import ' . $importe;
		}
	}

	/*
	 * Accès à une table WordPress.
	 *
	 * Un nom de table n'apparaît jamais qu'en chaîne — or les chaînes ont été
	 * écartées plus haut, et pour cause : « ce commentaire parle de wp_options »
	 * n'est pas un accès. On ne retient donc une chaîne que si elle porte AUSSI
	 * un verbe SQL. C'est la différence entre parler d'une table et l'interroger.
	 */
	foreach ( $jetons as $jeton ) {
		if ( ! is_array( $jeton ) ) {
			continue;
		}

		if ( T_CONSTANT_ENCAPSED_STRING !== $jeton[0] && T_ENCAPSED_AND_WHITESPACE !== $jeton[0] ) {
			continue;
		}

		$chaine = $jeton[1];

		if ( 1 !== preg_match( '/\b(SELECT|INSERT|UPDATE|DELETE|FROM|JOIN|CREATE TABLE|ALTER TABLE)\b/i', $chaine ) ) {
			continue;
		}

		foreach ( array( 'wp_options', 'wp_posts', 'wp_users', 'wp_postmeta', 'wp_usermeta' ) as $table ) {
			if ( false !== strpos( $chaine, $table ) ) {
				$trouve[] = 'requête sur ' . $table;
			}
		}
	}

	return array_values( array_unique( $trouve ) );
}

// ======================================================================
// 1 · L'INSTRUMENT SE VÉRIFIE LUI-MÊME
// ======================================================================
$temporaire = sys_get_temp_dir() . '/urbizen-frontiere-' . getmypid();
@mkdir( $temporaire, 0700, true );

$innocent = $temporaire . '/innocent.php';
file_put_contents(
	$innocent,
	"<?php\n"
	. "/**\n * Ce commentaire parle de WP_User, de \$wpdb et de get_option().\n */\n"
	. "final class Innocent {\n"
	. "\tpublic function texte(): string {\n"
	. "\t\treturn 'WP_User get_option wp_options \$wpdb';\n"
	. "\t}\n"
	. "}\n"
);

check( '1 · UN COMMENTAIRE MENTIONNANT WP_User NE COMPTE PAS',
	array() === dependances_wordpress( $innocent ) );

$coupable = $temporaire . '/coupable.php';
file_put_contents(
	$coupable,
	"<?php\nfinal class Coupable {\n\tpublic function lire() {\n\t\treturn get_option( 'x' );\n\t}\n}\n"
);

$vus = dependances_wordpress( $coupable );

check( '1 · UN APPEL RÉEL À get_option() EST DÉTECTÉ',
	in_array( 'appel get_option()', $vus, true ) );

$coupable2 = $temporaire . '/coupable2.php';
file_put_contents(
	$coupable2,
	"<?php\nfinal class Coupable2 {\n\tpublic function faire( WP_User \$u ) {\n\t\tglobal \$wpdb;\n\t\treturn \$wpdb;\n\t}\n}\n"
);

$vus2 = dependances_wordpress( $coupable2 );

check( '1 · un type WP_User est détecté', in_array( 'classe WP_User', $vus2, true ) );
check( '1 · la variable $wpdb est détectée', in_array( 'variable $wpdb', $vus2, true ) );

// Une requête réelle sur une table WordPress est détectée ; la seule mention
// du nom, elle, ne l'est pas — c'est le contrôle innocent de plus haut.
$coupable3 = $temporaire . '/coupable3.php';
file_put_contents(
	$coupable3,
	"<?php\nfinal class Coupable3 {\n\tpublic function sql(): string {\n\t\treturn 'SELECT * FROM wp_options';\n\t}\n}\n"
);

check( '1 · UNE REQUÊTE SUR wp_options EST DÉTECTÉE',
	in_array( 'requête sur wp_options', dependances_wordpress( $coupable3 ), true ) );

@unlink( $coupable3 );

@unlink( $innocent );
@unlink( $coupable );
@unlink( $coupable2 );
@rmdir( $temporaire );

// ======================================================================
// 2 · LE DOMAINE EST PROPRE
// ======================================================================
$racine = dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Domain';

$fichiers = array();
$iterateur = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine ) );

foreach ( $iterateur as $fichier ) {
	if ( $fichier->isFile() && 'php' === $fichier->getExtension() ) {
		$fichiers[] = $fichier->getPathname();
	}
}

sort( $fichiers );

check( '2 · le domaine contient bien des fichiers', count( $fichiers ) >= 8 );

$fautifs = array();

foreach ( $fichiers as $fichier ) {
	$vus = dependances_wordpress( $fichier );

	$court = str_replace( $racine . '/', '', $fichier );

	check(
		sprintf( '2 · %s ne dépend pas de WordPress', $court ),
		array() === $vus
	);

	if ( array() !== $vus ) {
		$fautifs[ $court ] = $vus;
	}
}

check( '2 · AUCUN FICHIER DU DOMAINE NE DÉPEND DE WORDPRESS', array() === $fautifs );

if ( array() !== $fautifs ) {
	foreach ( $fautifs as $court => $vus ) {
		echo "      $court : " . implode( ', ', $vus ) . "\n";
	}
}

// ======================================================================
// 3 · LES ADAPTATEURS, EUX, ONT LE DROIT
// ======================================================================
// Contrôle de cohérence : si les adaptateurs ne dépendaient de rien, c'est
// qu'ils n'adapteraient rien, et la frontière serait vide de sens.
$adaptateur = dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Adapter/WpCurrentUser.php';

check( '3 · l’adaptateur d’identité dépend bien de WordPress',
	array() !== dependances_wordpress( $adaptateur ) );

verdict();
