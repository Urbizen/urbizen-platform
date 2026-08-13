<?php
/**
 * LOT C — métadonnées des pages commerciales. Plan : `docs/PLAN_SEO_LOT_C.md`.
 *
 *     wp eval-file scripts/seo-lot-c.php              # simulation, n'écrit rien
 *     wp eval-file scripts/seo-lot-c.php appliquer    # écrit
 *
 * LE PROBLÈME QUE CE LOT RÈGLE
 *
 * Quatre pages annonçaient les deux mêmes requêtes. L'accueil disait
 * « Déclaration préalable – Permis de construire » et la page Tarifs « tarifs
 * pour une déclaration de travaux et permis de construire », c'est-à-dire
 * exactement ce que les deux pages dédiées travaillent sur 1 724 et 1 918 mots.
 * Les deux pages au contenu le plus faible sur le sujet occupaient les
 * métadonnées les plus visibles.
 *
 * Chaque page reçoit donc une promesse distincte : l'accueil prend la requête
 * parapluie, Tarifs prend l'intention de prix, DP et PC gardent leurs démarches,
 * Conception prend les plans.
 *
 * SIX CHAMPS, PAS DEUX
 *
 * `og_*` et `twitter_*` valant `NULL` héritent du titre et de la description.
 * C'est par là que le prix périmé de 149 € s'était propagé dans Open Graph et
 * le JSON-LD au lot A. Les six champs sont donc écrits en clair.
 *
 * AUCUNE BALISE DYNAMIQUE, AUCUN MONTANT
 *
 * Pas de `#site_title` : les valeurs sont littérales, donc insensibles à un
 * réglage de site. Et aucun prix, aucun délai chiffré, aucune référence
 * réglementaire — une métadonnée est permanente, un tarif ne l'est pas. C'est
 * la règle tirée du P0 du lot A, appliquée ici par prévention à la page Tarifs,
 * dont la description annonçait « dès 189 € ».
 *
 * PERMIS DE CONSTRUIRE : CE QUI EST PRÉPARÉ, PAS CE QUI EST OBTENU
 *
 * La description parle du **dossier** de permis de construire et de sa
 * préparation. Urbizen ne délivre ni n'obtient l'autorisation : elle prépare
 * les pièces. Le H1 de la page reçoit le même mot pour la même raison.
 *
 * @package Urbizen\Scripts
 */

defined( 'ABSPATH' ) || exit;

$appliquer = in_array( 'appliquer', (array) ( $args ?? array() ), true );

echo $appliquer
	? "MODE : APPLICATION — la base va être modifiée\n\n"
	: "MODE : SIMULATION — aucune écriture (ajouter « appliquer » pour écrire)\n\n";

if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
	echo "ARRÊT : AIOSEO introuvable. Rien n'a été fait.\n";
	return;
}

/**
 * Métadonnées cibles, par identifiant de page.
 *
 * La page 5 — Déclaration préalable — n'apparaît pas : ses métadonnées ont été
 * refaites au lot A et tiennent leur rôle. Y revenir ferait perdre le bénéfice
 * d'un changement déjà pris en compte par les moteurs.
 */
$cibles = array(
	4    => array(
		'nom'         => 'Accueil',
		'title'       => 'Dossiers d\'urbanisme à distance | Urbizen',
		'description' => 'Urbizen prépare vos dossiers d\'urbanisme à distance, partout en France : déclaration préalable, permis de construire, plans et pièces prêts à déposer.',
	),
	6    => array(
		'nom'         => 'Permis de construire',
		'title'       => 'Dossier de permis de construire à distance | Urbizen',
		'description' => 'Urbizen prépare votre dossier de permis de construire à distance : CERFA, plans PCMI, notice descriptive et insertion paysagère, prêts à déposer en mairie.',
	),
	1169 => array(
		'nom'         => 'Conception',
		'title'       => 'Plans sur mesure pour dossier d\'urbanisme | Urbizen',
		'description' => 'Urbizen dessine les plans de votre projet : plan de masse, plan de coupe, façades et insertion paysagère, réalisés sur mesure pour votre dossier d\'urbanisme.',
	),
	10   => array(
		'nom'         => 'Tarifs',
		'title'       => 'Tarifs déclaration préalable et permis | Urbizen',
		'description' => 'Le prix de votre dossier d\'urbanisme selon la nature du projet : déclaration préalable, permis de construire ou plans. Ce qui est inclus, devis avant commande.',
	),
);

$anomalies = array();

foreach ( $cibles as $id => $c ) {
	$p = get_post( $id );

	if ( ! $p ) {
		echo sprintf( "  %-5s INTROUVABLE — ignorée\n", $id );
		$anomalies[] = "page $id introuvable";
		continue;
	}

	echo sprintf( "══ page %d · %s ══\n", $id, $c['nom'] );

	$fiche = \AIOSEO\Plugin\Common\Models\Post::getPost( $id );

	$champs = array(
		'title'               => $c['title'],
		'description'         => $c['description'],
		'og_title'            => $c['title'],
		'og_description'      => $c['description'],
		'twitter_title'       => $c['title'],
		'twitter_description' => $c['description'],
	);

	foreach ( $champs as $champ => $valeur ) {
		$avant = $fiche->$champ;
		echo sprintf( "  %-20s %s\n", $champ, ( $avant === $valeur ) ? 'déjà conforme' : 'À CORRIGER' );
		echo sprintf( "     avant : %s\n", null === $avant ? '(NULL — hérite du titre/description)' : $avant );
		echo sprintf( "     après : %s\n", $valeur );

		if ( $appliquer ) {
			$fiche->$champ = $valeur;
		}
	}

	// Garde-fous : ce que ces valeurs ne doivent jamais contenir.
	if ( preg_match( '/\d+\s*(€|euros)/iu', $c['title'] . ' ' . $c['description'] ) ) {
		$anomalies[] = "page $id : un montant figure dans les métadonnées";
	}
	if ( preg_match( '/#(post_title|site_title|separator_sa|tagline)/', $c['title'] . ' ' . $c['description'] ) ) {
		$anomalies[] = "page $id : une balise dynamique subsiste";
	}
	if ( preg_match( '/UrbiZen|URBIZEN/', $c['title'] . ' ' . $c['description'] ) ) {
		$anomalies[] = "page $id : casse de marque non normalisée";
	}
	if ( mb_strlen( $c['title'] ) > 60 ) {
		$anomalies[] = sprintf( 'page %d : title de %d caractères', $id, mb_strlen( $c['title'] ) );
	}
	if ( mb_strlen( $c['description'] ) < 120 || mb_strlen( $c['description'] ) > 160 ) {
		$anomalies[] = sprintf( 'page %d : description de %d caractères', $id, mb_strlen( $c['description'] ) );
	}

	echo sprintf( "  longueurs : title %d c. · description %d c.\n", mb_strlen( $c['title'] ), mb_strlen( $c['description'] ) );

	if ( $appliquer ) {
		$fiche->save();
		echo "  → enregistré via le modèle AIOSEO\n";
	}

	echo "\n";
}

if ( $anomalies ) {
	echo "ANOMALIES RELEVÉES :\n";
	foreach ( $anomalies as $a ) {
		echo "  · $a\n";
	}
	echo "\n";
}

echo $appliquer
	? "TERMINÉ. Purger les caches, puis lancer tests/seo/run-all.sh.\n"
	: "Rien n'a été écrit.\n";
