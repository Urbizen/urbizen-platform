<?php
/**
 * Banc du lot E — le schéma que produira un futur article.
 *
 * POURQUOI CE BANC EXISTE
 *
 * Le nœud `Person` d'AIOSEO n'apparaît que sur les articles. Le blog étant vide,
 * aucune page servie ne permet de vérifier qu'il ne pointe plus vers
 * `/author/…`, qui répond 404 depuis le lot A. Le défaut serait donc invisible
 * jusqu'au premier article publié — c'est-à-dire jusqu'au lot G, où personne ne
 * penserait à le chercher.
 *
 * Ce banc crée un article **en brouillon**, demande à AIOSEO le graphe qu'il
 * produirait, contrôle, puis supprime l'article définitivement. Un brouillon
 * n'est pas public : rien n'est exposé pendant l'exécution.
 *
 * S'exécute sur le serveur :
 *
 *     wp eval-file tests/seo/test-seo-lot-e-article.php
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 *
 * @package Urbizen\Tests
 */

defined( 'ABSPATH' ) || exit;

/*
 * `$echecs` est déclaré global explicitement. `wp eval-file` exécute ce fichier
 * dans une portée de fonction : un `$echecs = 0` au premier niveau y serait une
 * variable LOCALE, tandis que le `global $echecs` de la fonction de contrôle
 * viserait une autre variable. Le compteur s'incrémentait donc dans le vide, et
 * le banc concluait « tous les contrôles passent » en affichant un échec deux
 * lignes plus haut. Constaté à la première exécution.
 */
global $echecs;
$echecs = 0;

/**
 * Affiche le résultat d'un contrôle.
 *
 * @param string $nom    Intitulé.
 * @param bool   $ok     Résultat.
 * @param string $detail Précision en cas d'échec.
 * @return void
 */
function urbizen_check_article( $nom, $ok, $detail = '' ) {
	global $echecs;
	printf( "   %s  %s\n", $ok ? 'OK   ' : 'ECHEC', $nom );
	if ( ! $ok && '' !== $detail ) {
		echo "           $detail\n";
	}
	if ( ! $ok ) {
		++$echecs;
	}
}

echo "\n════ LOT E — SCHÉMA D'UN FUTUR ARTICLE ════\n\n";

if ( ! function_exists( 'aioseo' ) ) {
	echo "ARRÊT : AIOSEO introuvable.\n";
	exit( 1 );
}

// --- Article temporaire, en brouillon ---------------------------------------
$id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_title'   => 'Contrôle technique du schéma — brouillon temporaire',
		'post_content' => 'Article créé par tests/seo/test-seo-lot-e-article.php, supprimé à la fin de son exécution.',
		'post_author'  => 1,
	),
	true
);

if ( is_wp_error( $id ) || ! $id ) {
	echo 'ARRÊT : impossible de créer le brouillon de contrôle.' . "\n";
	exit( 1 );
}

echo "   article de contrôle : #$id (brouillon)\n\n";

// --- On place la requête dans le contexte de cet article --------------------
global $wp_query, $post;

$ancienne_requete = $wp_query;
$ancien_post      = $post;

$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
setup_postdata( $post );

$wp_query = new WP_Query( array( 'p' => $id, 'post_type' => 'post', 'post_status' => 'draft' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wp_query->is_single    = true;
$wp_query->is_singular  = true;
$wp_query->is_home      = false;
$wp_query->queried_object    = $post;
$wp_query->queried_object_id = $id;

$graphe = array();

try {
	aioseo()->schema->context = 'post';
	aioseo()->schema->id      = $id;
	$json = aioseo()->schema->get();
	$brut = json_decode( $json, true );
	$graphe = $brut['@graph'] ?? array();
} catch ( \Throwable $e ) {
	echo '   ATTENTION : ' . $e->getMessage() . "\n";
}

// --- Remise en état AVANT les contrôles, pour ne rien laisser traîner --------
$wp_query = $ancienne_requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$post     = $ancien_post;      // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
wp_reset_postdata();
wp_delete_post( $id, true );

echo "   article de contrôle supprimé définitivement\n\n";

// --- Contrôles ---------------------------------------------------------------
urbizen_check_article( 'un graphe a bien été produit', array() !== $graphe, 'graphe vide' );

if ( array() === $graphe ) {
	echo "\n$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

$types = array();
foreach ( $graphe as $n ) {
	$t = $n['@type'] ?? '?';
	$types[] = is_array( $t ) ? implode( ',', $t ) : $t;
}

echo '   types produits : ' . implode( ', ', $types ) . "\n\n";

$serialise = wp_json_encode( $graphe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

// Le cœur du banc : aucune adresse d'archive d'auteur, où que ce soit.
urbizen_check_article(
	'aucune URL /author/ dans tout le graphe',
	false === strpos( (string) $serialise, '/author/' ),
	'une archive d\'auteur est citée — elle répond 404'
);

// Le nœud Person lui-même.
$person = null;
foreach ( $graphe as $n ) {
	$t = (array) ( $n['@type'] ?? array() );
	if ( in_array( 'Person', $t, true ) ) {
		$person = $n;
		break;
	}
}

/*
 * Ce banc se contentait d'une NOTE quand le nœud manquait, et passait au vert.
 * Publié le 14 août 2026, le premier guide a montré ce que cette indulgence
 * cachait : `BlogPosting` et `WebPage` annoncent un `author` et un `creator`
 * pointant vers l'identifiant de l'autrice, sans qu'aucun nœud ne le définisse.
 * Un graphe qui référence un `@id` inexistant est incomplet. L'absence devient
 * donc un échec, à la condition que la référence existe — c'est elle qui rend
 * le nœud obligatoire.
 */
$reference = str_contains( wp_json_encode( $graphe ), URBIZEN_CHILD_ID_AUTRICE );

urbizen_check_article(
	'Le nœud Person existe dès que le graphe le référence',
	! $reference || null !== $person,
	'author/creator pointent vers ' . URBIZEN_CHILD_ID_AUTRICE . ' sans nœud correspondant'
);

if ( null === $person ) {
	echo "   NOTE   aucun nœud Person et aucune référence — rien à contrôler ici\n";
} else {
	urbizen_check_article( 'Person conserve son nom', ! empty( $person['name'] ), 'nom absent' );
	urbizen_check_article(
		'Person porte bien « Anaïs Bacarisse »',
		'Anaïs Bacarisse' === ( $person['name'] ?? '' ),
		'lu : ' . ( $person['name'] ?? '(absent)' )
	);
	urbizen_check_article( 'Person n\'a plus de propriété url', ! isset( $person['url'] ), 'url : ' . ( $person['url'] ?? '' ) );
	urbizen_check_article(
		'Person a un @id stable, hors archive',
		( $person['@id'] ?? '' ) === URBIZEN_CHILD_ID_AUTRICE,
		'lu : ' . ( $person['@id'] ?? '(absent)' )
	);
}

// Le fil d'Ariane, sur un article comme ailleurs.
urbizen_check_article(
	'le fil d\'Ariane ne dit pas « Home »',
	false === strpos( (string) $serialise, '"name":"Home"' )
);

// L'organisation garde ce que le lot lui a donné.
$orga = null;
foreach ( $graphe as $n ) {
	if ( in_array( 'Organization', (array) ( $n['@type'] ?? array() ), true ) ) {
		$orga = $n;
		break;
	}
}

if ( null !== $orga ) {
	urbizen_check_article( 'Organization.name vaut Urbizen', 'Urbizen' === ( $orga['name'] ?? '' ), 'lu : ' . ( $orga['name'] ?? '' ) );
	urbizen_check_article( 'Organization a une adresse postale', isset( $orga['address']['@type'] ) && 'PostalAddress' === $orga['address']['@type'] );
	urbizen_check_article( 'Organization n\'est pas un LocalBusiness', ! in_array( 'LocalBusiness', (array) $orga['@type'], true ) );
}

echo "\n";

global $echecs;

if ( $echecs ) {
	echo "$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

echo "TOUS LES CONTROLES PASSENT\n";
exit( 0 );
