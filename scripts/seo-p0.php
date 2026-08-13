<?php
/**
 * LOT A — correction des deux problèmes P0 de l'audit SEO du 13 août 2026.
 *
 * S'exécute sur le serveur, à la racine WordPress :
 *
 *     wp eval-file scripts/seo-p0.php              # simulation, n'écrit rien
 *     wp eval-file scripts/seo-p0.php appliquer    # écrit
 *
 * POURQUOI UN SCRIPT PLUTÔT QUE DES COMMANDES À LA MAIN
 *
 * Les deux corrections portent sur la base, pas sur des fichiers : elles
 * n'apparaîtraient dans aucun diff. Ce script est leur version relisible. Il
 * est **idempotent** — le relancer ne change rien de plus — et il affiche
 * systématiquement l'avant et l'après, y compris en simulation.
 *
 * P0.1 · MÉTADONNÉES DE LA PAGE « DÉCLARATION PRÉALABLE »
 *
 * Le titre stocké est un gabarit à balises dynamiques :
 *
 *     #post_title à partir de 149€#separator_sa #site_title
 *
 * Deux défauts en une ligne. Le prix de 149 € n'a plus cours — la grille
 * commence à 189 €. Et `#site_title` est vide, d'où le « - » orphelin en fin
 * de titre. Les six champs sont donc écrits en clair, sans balise dynamique,
 * exactement comme sur les pages légales et la page Tarifs : c'est le seul
 * moyen de ne pas dépendre d'une option de site vide.
 *
 * `og_title`, `og_description`, `twitter_title` et `twitter_description`
 * valaient NULL : ils héritaient du titre et de la description, prix compris.
 * Les laisser à NULL aurait laissé passer le prix par la porte de derrière.
 *
 * L'écriture passe par le modèle applicatif d'AIOSEO, jamais par un `UPDATE`
 * direct : le modèle tient à jour ses propres colonnes dérivées et invalide ses
 * caches. C'est le mécanisme déjà employé pour les pages légales.
 *
 * P0.2 · IDENTITÉ PUBLIQUE DE L'AUTRICE
 *
 * `user_nicename` et `display_name` valaient l'adresse de courriel. Le premier
 * fabrique l'URL d'archive, le second le titre de cette archive, le nom affiché
 * sur les articles, le `Person` des données structurées et le champ `name` de
 * l'API REST publique — quatre expositions pour une seule cause.
 *
 * `user_login` n'est pas touché : c'est l'identifiant de connexion, et le
 * changer risquerait l'authentification pour un gain nul, puisqu'il n'est
 * jamais publié.
 *
 * L'option AIOSEO `archives.author.show` passe à faux. Vérifié dans le code du
 * greffon (`Sitemap/Content.php`, `Sitemap/Root.php`, `Meta/Robots.php`) : cela
 * exclut l'archive du plan de site et lui applique `noindex`. Cela ne la
 * supprime pas — c'est le filtre `parse_query` du thème enfant qui s'en charge.
 * Les deux couches sont voulues : si le filtre disparaissait, AIOSEO tiendrait
 * encore la ligne.
 *
 * @package Urbizen\Scripts
 */

defined( 'ABSPATH' ) || exit;

$appliquer = in_array( 'appliquer', (array) ( $args ?? array() ), true );

echo $appliquer
	? "MODE : APPLICATION — la base va être modifiée\n\n"
	: "MODE : SIMULATION — aucune écriture (ajouter « appliquer » pour écrire)\n\n";

/* ---------------------------------------------------------------------------
 * P0.1 — métadonnées de la page Déclaration préalable
 * ------------------------------------------------------------------------ */

const URBIZEN_PAGE_DP = 5;

$cible = array(
	'title'               => 'Déclaration préalable de travaux à distance | Urbizen',
	'description'         => 'Urbizen prépare votre déclaration préalable de travaux à distance partout en France : plans, CERFA et pièces du dossier selon votre projet.',
	'og_title'            => 'Déclaration préalable de travaux à distance | Urbizen',
	'og_description'      => 'Urbizen prépare votre déclaration préalable de travaux à distance partout en France : plans, CERFA et pièces du dossier selon votre projet.',
	'twitter_title'       => 'Déclaration préalable de travaux à distance | Urbizen',
	'twitter_description' => 'Urbizen prépare votre déclaration préalable de travaux à distance partout en France : plans, CERFA et pièces du dossier selon votre projet.',
);

echo "══ P0.1 · page " . URBIZEN_PAGE_DP . " — " . get_the_title( URBIZEN_PAGE_DP ) . " ══\n";

if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
	echo "  ARRÊT : AIOSEO est introuvable. Rien n'a été fait.\n";
	return;
}

$fiche = \AIOSEO\Plugin\Common\Models\Post::getPost( URBIZEN_PAGE_DP );

foreach ( $cible as $champ => $valeur ) {
	$avant = $fiche->$champ;
	$etat  = ( $avant === $valeur ) ? 'déjà conforme' : 'À CORRIGER';
	echo sprintf( "  %-20s %s\n", $champ, $etat );
	echo sprintf( "     avant : %s\n", null === $avant ? '(NULL — hérite du titre/description)' : $avant );
	echo sprintf( "     après : %s\n", $valeur );

	if ( $appliquer ) {
		$fiche->$champ = $valeur;
	}
}

if ( $appliquer ) {
	$fiche->save();
	echo "  → enregistré via le modèle AIOSEO\n";
}

/* ---------------------------------------------------------------------------
 * P0.2 — identité publique de l'autrice
 * ------------------------------------------------------------------------ */

const URBIZEN_UTILISATEUR   = 1;
const URBIZEN_NICENAME      = 'anais-bacarisse';
const URBIZEN_NOM_AFFICHAGE = 'Anaïs Bacarisse';

echo "\n══ P0.2 · utilisateur " . URBIZEN_UTILISATEUR . " ══\n";

$u = get_userdata( URBIZEN_UTILISATEUR );

if ( ! $u ) {
	echo "  ARRÊT : utilisateur introuvable. Rien n'a été fait.\n";
	return;
}

$champs_utilisateur = array(
	'user_login'    => array( $u->user_login, $u->user_login, 'INCHANGÉ — identifiant de connexion' ),
	'user_email'    => array( $u->user_email, $u->user_email, 'INCHANGÉ — jamais publié' ),
	'user_nicename' => array( $u->user_nicename, URBIZEN_NICENAME, 'fabrique l\'URL d\'archive' ),
	'display_name'  => array( $u->display_name, URBIZEN_NOM_AFFICHAGE, 'titre d\'archive, nom d\'article, JSON-LD, API REST' ),
	'nickname'      => array( get_user_meta( URBIZEN_UTILISATEUR, 'nickname', true ), URBIZEN_NOM_AFFICHAGE, 'repli d\'affichage' ),
	'first_name'    => array( get_user_meta( URBIZEN_UTILISATEUR, 'first_name', true ), 'Anaïs', 'vide aujourd\'hui' ),
	'last_name'     => array( get_user_meta( URBIZEN_UTILISATEUR, 'last_name', true ), 'Bacarisse', 'vide aujourd\'hui' ),
);

foreach ( $champs_utilisateur as $champ => $trio ) {
	list( $avant, $apres, $note ) = $trio;
	echo sprintf( "  %-14s %s\n", $champ, $note );
	echo sprintf( "     avant : %s\n", '' === $avant ? '(vide)' : $avant );
	echo sprintf( "     après : %s\n", '' === $apres ? '(vide)' : $apres );
}

if ( $appliquer ) {
	wp_update_user(
		array(
			'ID'            => URBIZEN_UTILISATEUR,
			'user_nicename' => URBIZEN_NICENAME,
			'display_name'  => URBIZEN_NOM_AFFICHAGE,
			'nickname'      => URBIZEN_NOM_AFFICHAGE,
			'first_name'    => 'Anaïs',
			'last_name'     => 'Bacarisse',
		)
	);
	echo "  → enregistré via wp_update_user()\n";
}

/* ---------------------------------------------------------------------------
 * P0.2 (suite) — AIOSEO : archive d'auteur hors plan de site et en noindex
 * ------------------------------------------------------------------------ */

echo "\n══ P0.2 · option AIOSEO des archives d'auteur ══\n";

$avant_show = aioseo()->options->searchAppearance->archives->author->show;
echo sprintf( "  searchAppearance.archives.author.show\n     avant : %s\n     après : false\n", $avant_show ? 'true' : 'false' );
echo "     effet : exclusion du plan de site + noindex. NE supprime PAS l'URL —\n";
echo "             c'est le filtre parse_query du thème enfant qui le fait.\n";

if ( $appliquer ) {
	aioseo()->options->searchAppearance->archives->author->show = false;
	echo "  → enregistré\n";
}

echo "\n" . ( $appliquer ? "TERMINÉ. Purger les caches, puis lancer tests/seo/test-seo-p0.mjs.\n" : "Rien n'a été écrit.\n" );
