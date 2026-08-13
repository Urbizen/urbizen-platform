<?php
/**
 * LOT B — assainissement de l'index. Plan : `docs/PLAN_SEO_LOT_B.md`.
 *
 *     wp eval-file scripts/seo-lot-b.php              # simulation, n'écrit rien
 *     wp eval-file scripts/seo-lot-b.php appliquer    # écrit
 *
 * SUPPRESSION VEUT DIRE CORBEILLE
 *
 * `wp_trash_post()`, jamais `wp_delete_post( $id, true )`. Les pages restent
 * récupérables depuis l'administration, et le retour arrière ne demande pas de
 * restaurer la base. Elles répondent 404 dès la mise à la corbeille, ce qui est
 * l'effet recherché. La suppression définitive, si elle est souhaitée, est une
 * décision distincte à prendre plus tard.
 *
 * CE QUI N'EST PAS FAIT ICI, ET POURQUOI
 *
 * `/commander-un-dossier/` (page 12) est **retirée de ce lot**. Le contrôle de
 * valeur préalable a montré qu'elle porte le formulaire Fluent Forms n° 6
 * (« Demande de déclaration préalable de travaux »), qui compte 3 soumissions
 * réelles et n'est rendu **nulle part ailleurs**. La requête en base laissait
 * croire qu'il figurait aussi sur l'accueil, mais c'était dans le `post_content`
 * hérité de la page 4, que le gabarit de blocs ne rend jamais. Supprimer la page
 * couperait donc le seul accès public à ce formulaire.
 *
 * @package Urbizen\Scripts
 */

defined( 'ABSPATH' ) || exit;

$appliquer = in_array( 'appliquer', (array) ( $args ?? array() ), true );

echo $appliquer
	? "MODE : APPLICATION — la base va être modifiée\n\n"
	: "MODE : SIMULATION — aucune écriture (ajouter « appliquer » pour écrire)\n\n";

/* ---------------------------------------------------------------------------
 * 1 · Mises à la corbeille
 * ------------------------------------------------------------------------ */

$a_jeter = array(
	22 => 'Shop — page WooCommerce, extension inactive, aucun produit',
	23 => 'Cart — idem',
	24 => 'Checkout — idem',
	25 => 'My account — idem',
	11 => 'Espace Professionnels — 39 mots, aucun H1, aucun lien entrant',
	1  => 'Hello world! — article de démonstration WordPress',
);

echo "══ 1 · Mises à la corbeille ══\n";

foreach ( $a_jeter as $id => $motif ) {
	$p = get_post( $id );

	if ( ! $p ) {
		echo sprintf( "  %-5s introuvable — rien à faire\n", $id );
		continue;
	}

	echo sprintf( "  %-5s %-26s [%s] %s\n", $id, $p->post_title, $p->post_status, $motif );

	if ( 'trash' === $p->post_status ) {
		echo "        déjà à la corbeille\n";
		continue;
	}

	if ( $appliquer ) {
		wp_trash_post( $id );
		echo "        → corbeille\n";
	}
}

// Le commentaire de démonstration part avec son article.
$commentaires = get_comments( array( 'post_id' => 1, 'status' => 'all' ) );
echo sprintf( "  commentaires de l'article 1 : %d\n", count( $commentaires ) );

foreach ( $commentaires as $c ) {
	echo sprintf( "        #%s de « %s »\n", $c->comment_ID, $c->comment_author );

	if ( $appliquer ) {
		wp_trash_comment( $c->comment_ID );
	}
}

/* ---------------------------------------------------------------------------
 * 2 · Pages conservées mais retirées de l'index
 * ------------------------------------------------------------------------ */

$a_desindexer = array(
	8    => '/autres-projets/ — matière des clusters clôtures, abris, panneaux solaires (lot G)',
	1171 => '/formulaire-declaration-prealable/ — coque d\'application, 0 mot',
	1172 => '/formulaire-permis-de-construire/ — coque d\'application, 0 mot',
	1190 => '/formulaire-conception/ — étape de tunnel ; la page Conception reste la référence',
);

echo "\n══ 2 · Passage en noindex ══\n";

if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
	echo "  ARRÊT : AIOSEO introuvable.\n";
	return;
}

foreach ( $a_desindexer as $id => $motif ) {
	$p = get_post( $id );

	if ( ! $p ) {
		echo sprintf( "  %-5s introuvable — rien à faire\n", $id );
		continue;
	}

	$fiche = \AIOSEO\Plugin\Common\Models\Post::getPost( $id );
	$avant = sprintf( 'robots_default=%s robots_noindex=%s', $fiche->robots_default, $fiche->robots_noindex );

	echo sprintf( "  %-5s %s\n        %s\n", $id, $motif, $avant );
	echo "        après : robots_default=0 robots_noindex=1\n";

	if ( $appliquer ) {
		// `robots_default` doit tomber : tant qu'il vaut 1, AIOSEO ignore les
		// réglages de la page et applique la règle globale.
		$fiche->robots_default = 0;
		$fiche->robots_noindex = 1;
		$fiche->save();
		echo "        → enregistré\n";
	}
}

/* ---------------------------------------------------------------------------
 * 3 · Catégorie par défaut
 * ------------------------------------------------------------------------ */

echo "\n══ 3 · Catégorie par défaut ══\n";

$terme = get_term( (int) get_option( 'default_category' ), 'category' );

if ( $terme && ! is_wp_error( $terme ) ) {
	echo sprintf( "  terme %d — nom « %s », slug « %s », %d article(s)\n", $terme->term_id, $terme->name, $terme->slug, $terme->count );
	echo "  après : nom « Non classé », slug « non-classe », noindex explicite\n";

	// `noIndexEmptyCat` NE FAIT RIEN. Le plan du lot supposait qu'elle
	// désindexerait la catégorie une fois vide. Vérification faite dans le code
	// d'AIOSEO 5.0.0.1 : l'option n'existe que comme définition dans
	// `Options.php` et n'est lue nulle part ailleurs — un reste des versions 3.
	// Mesuré en production : catégorie vide, `robots: max-image-preview:large`,
	// donc indexable. Le `noindex` est donc posé sur le terme lui-même.
	echo "  (noIndexEmptyCat est un réglage mort dans cette version : sans effet)\n";

	if ( $appliquer ) {
		wp_update_term( $terme->term_id, 'category', array( 'name' => 'Non classé', 'slug' => 'non-classe' ) );
		echo "  → renommé\n";

		if ( class_exists( '\AIOSEO\Plugin\Common\Models\Term' ) ) {
			$ft                  = \AIOSEO\Plugin\Common\Models\Term::getTerm( $terme->term_id );
			$ft->robots_default  = 0;
			$ft->robots_noindex  = 1;
			$ft->save();
			echo "  → noindex posé sur le terme\n";
		} else {
			echo "  ATTENTION : modèle Term d'AIOSEO introuvable, noindex NON posé\n";
		}
	}
}

/* ---------------------------------------------------------------------------
 * 4 · Archives de date
 * ------------------------------------------------------------------------ */

echo "\n══ 4 · Archives de date ══\n";

$avant_date = aioseo()->options->searchAppearance->archives->date->show;
echo sprintf( "  searchAppearance.archives.date.show : %s → false\n", $avant_date ? 'true' : 'false' );
echo "  effet : exclusion du plan de site + noindex sur /2026/, /2026/05/ et suivantes.\n";
echo "  Pas de 404 ici, contrairement aux archives d'auteur du lot A : une archive\n";
echo "  de date n'expose aucune donnée personnelle, le noindex suffit.\n";

if ( $appliquer ) {
	aioseo()->options->searchAppearance->archives->date->show = false;
	echo "  → enregistré\n";
}

/* ---------------------------------------------------------------------------
 * 5 · Titre du site
 * ------------------------------------------------------------------------ */

echo "\n══ 5 · Titre du site ══\n";

$avant_nom = get_option( 'blogname' );
echo sprintf( "  blogname : « %s » → « Urbizen »\n", '' === $avant_nom ? '(vide)' : $avant_nom );
echo "  effet : les titles sans title AIOSEO explicite cessent de finir par « - »,\n";
echo "  et les données structurées cessent de prendre le slogan pour le nom.\n";

if ( $appliquer ) {
	update_option( 'blogname', 'Urbizen' );
	echo "  → enregistré\n";
}

/* ---------------------------------------------------------------------------
 * 6 · Nom d'organisation dans les données structurées
 *
 * Renseigner `blogname` ne suffisait pas. Mesuré juste après : le nom passait
 * de « Votre dossier d'urbanisme en toute tranquillité » à « Urbizen Votre
 * dossier d'urbanisme en toute tranquillité ». La cause est le gabarit
 * `#site_title #tagline`, qui concaténait les deux : tant que le titre de site
 * était vide, seul le slogan restait, et il passait pour le nom.
 *
 * Le slogan n'est pas un nom d'entreprise. `organizationDescription` le porte
 * déjà, à sa place.
 *
 * Le reste du schéma `Organization` — adresse, téléphone, mentions juridiques —
 * relève du lot E et n'est pas touché ici.
 * ------------------------------------------------------------------------ */

echo "\n══ 6 · Nom d'organisation dans le schéma ══\n";

$avant_orga = aioseo()->options->searchAppearance->global->schema->organizationName;
echo sprintf( "  organizationName : « %s » → « #site_title »\n", $avant_orga );

if ( $appliquer ) {
	aioseo()->options->searchAppearance->global->schema->organizationName = '#site_title';
	echo "  → enregistré\n";
}

echo "\n" . ( $appliquer ? "TERMINÉ. Purger les caches, puis lancer tests/seo/run-all.sh.\n" : "Rien n'a été écrit.\n" );
