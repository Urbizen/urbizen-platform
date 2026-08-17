<?php
/**
 * Publication des guides Urbizen — idempotente, par slug.
 *
 *     wp eval-file scripts/publier-guides.php [simulation]
 *
 * « simulation » est passé en argument POSITIONNEL et non en option :
 * `wp eval-file` refuse les options qu'il ne connaît pas, et transmet en
 * revanche tout ce qui suit le nom du fichier dans `$args`.
 *
 * POURQUOI PAR SLUG, ET NON PAR IDENTIFIANT
 *
 * Un identifiant n'existe qu'après la première création : un script qui en
 * dépendrait ne pourrait pas être rejoué. Chaque guide est donc retrouvé par
 * son `post_name`. Trouvé, il est mis à jour ; absent, il est créé. Rejouer le
 * script deux fois de suite laisse la base dans le même état — c'est le seul
 * moyen sûr de ne pas semer de doublons quand une publication échoue à
 * mi-parcours et qu'on la reprend.
 *
 * LA SOURCE EST LE DÉPÔT
 *
 * Le corps de chaque article est lu dans `content/guides/<slug>.html`. Une
 * correction faite dans l'éditeur WordPress et non reportée au dépôt sera
 * écrasée à la prochaine exécution. C'est voulu : la source versionnée est la
 * référence, et deux sources de vérité en produiraient zéro.
 *
 * CE QU'IL NE FAIT PAS
 *
 * - Il ne touche pas à l'image mise en avant d'un article qui en a déjà une.
 *   Une vignette recadrée à la main dans l'admin ne doit pas être écrasée par
 *   un script de publication.
 * - Il ne crée aucune catégorie. Les trois attendues existent ; si l'une
 *   manquait, il s'arrête plutôt que d'en inventer une au mauvais slug.
 * - Il ne purge aucun cache. C'est une étape distincte, faite après contrôle.
 *
 * @package Urbizen
 */

defined( 'ABSPATH' ) || exit;

$simulation = in_array( 'simulation', (array) ( $args ?? array() ), true );

/** Racine des sources, copiée hors de public_html au moment du déploiement. */
$source = getenv( 'URBIZEN_CONTENU' ) ?: dirname( __DIR__ ) . '/content/guides';

if ( ! is_dir( $source ) ) {
	WP_CLI::error( "Répertoire de contenu introuvable : $source" );
}

/*
 * Le manifeste. Tout ce qui est servi aux moteurs est ici, en clair, et non
 * réparti entre le script et l'admin : c'est ce qui rend la publication
 * relisible sans ouvrir WordPress.
 *
 * `image` est le fichier livré avec le thème ; il n'est importé dans la
 * médiathèque que si l'article n'a pas déjà une image mise en avant.
 */
$guides = array(
	array(
		'slug'        => 'dp-ou-permis-de-construire',
		'titre'       => 'DP ou permis de construire : lequel faut-il pour votre projet ?',
		'categorie'   => 'autorisations-projets',
		'extrait'     => 'Construction nouvelle ou travaux sur l’existant, emprise ou surface de plancher, zone U ou non, total après travaux : quatre questions qui se répondent dans l’ordre, et trois cas limites tranchés pas à pas.',
		'seo_titre'   => 'DP ou permis de construire : comment trancher | Urbizen',
		'seo_desc'    => 'Quatre questions, dans l’ordre, pour savoir si votre projet relève d’une déclaration préalable ou d’un permis — et ce qui fait basculer un cas limite.',
		'image'       => 'seo-guides-v2/guide-dp-ou-permis.webp',
		'image_titre' => 'Deux dossiers de plans de tailles différentes illustrant la comparaison entre deux démarches.',
		'image_alt'   => 'Deux dossiers de plans de tailles différentes illustrant la comparaison entre deux démarches.',
	),
	array(
		'slug'        => 'extension-maison-verifications-avant-plans',
		'titre'       => 'Extension de maison : 5 vérifications avant de dessiner les plans',
		'categorie'   => 'autorisations-projets',
		'extrait'     => 'Ce que le règlement de zone autorise, la surface que vous aurez au total après travaux, le seuil qui fait basculer vers le permis, l’architecte, le contexte du terrain — et une check-list à reprendre avant le premier trait.',
		'seo_titre'   => 'Extension de maison : 5 vérifications avant les plans | Urbizen',
		'seo_desc'    => 'Avant de dessiner une extension : ce que le PLU autorise, comment se calcule le total après travaux, et cinq points à vérifier pour ne pas refaire les plans.',
		'image'       => 'seo-guides-v2/guide-extension-maison.webp',
		'image_titre' => 'Extension contemporaine en rez-de-chaussée accolée à une maison en brique, projet fictif.',
		'image_alt'   => 'Extension contemporaine en rez-de-chaussée accolée à une maison en brique, projet fictif.',
	),
	array(
		'slug'        => 'lire-le-plu-de-son-terrain',
		'titre'       => 'PLU : ce que vous pouvez vraiment construire sur votre terrain',
		'categorie'   => 'regles-urbanisme',
		'extrait'     => 'Un PLU tient en cinq pièces, dont deux seulement s’imposent à votre projet. Retrouver sa zone, ouvrir le bon chapitre, lire les six familles de règles, et savoir ce que les annexes cachent de décisif.',
		'seo_titre'   => 'Lire le PLU de son terrain : le guide pratique | Urbizen',
		'seo_desc'    => 'Retrouver la zone d’une parcelle, ouvrir le bon chapitre du règlement, lire emprise, hauteur, reculs et servitudes — et distinguer faisabilité et autorisation.',
		'image'       => 'seo-guides-v2/guide-plu-terrain.webp',
		'image_titre' => 'Plan de zonage urbain avec une parcelle mise en évidence, territoire fictif.',
		'image_alt'   => 'Plan de zonage urbain avec une parcelle mise en évidence, territoire fictif.',
	),
	array(
		'slug'        => 'erreurs-dossier-urbanisme',
		'titre'       => 'Dossier d’urbanisme : 7 erreurs qui peuvent retarder l’accord',
		'categorie'   => 'conseils-demarches',
		'extrait'     => 'Ces erreurs ne font pas refuser un dossier : elles le font recommencer. Sept points à vérifier avant le dépôt, avec pour chacun le symptôme, la conséquence, la méthode de vérification et la correction.',
		'seo_titre'   => '7 erreurs qui retardent un dossier d’urbanisme | Urbizen',
		'seo_desc'    => 'Une pièce oubliée ne coûte pas quelques jours : le délai d’instruction recommence entier. Sept erreurs fréquentes, leur symptôme et comment les corriger.',
		'image'       => 'seo-guides-v2/guide-erreurs-dossier.webp',
		'image_titre' => 'Sept points de contrôle repérés sur les plans d’une maison individuelle fictive.',
		'image_alt'   => 'Sept points de contrôle repérés sur les plans d’une maison individuelle fictive.',
	),
	array(
		'slug'        => 'delais-urbanisme-debut-des-travaux',
		'titre'       => 'Délais d’urbanisme : quand pouvez-vous vraiment commencer les travaux ?',
		'categorie'   => 'conseils-demarches',
		'extrait'     => '« Deux mois d’instruction » est un chiffre juste qui ne répond pas à la question. Le calendrier réel, étape par étape, en distinguant ce qu’un texte fixe de ce qui dépend de vous.',
		'seo_titre'   => 'Délais d’urbanisme : quand commencer les travaux | Urbizen',
		'seo_desc'    => 'Du dépôt au premier coup de pelle : départ du délai, pièces manquantes, décision tacite, affichage, recours des tiers — et ce que vous pouvez raccourcir.',
		'image'       => 'seo-guides-v2/guide-delais-urbanisme.webp',
		'image_titre' => 'Calendrier et étapes d’un dossier d’urbanisme présentés sur un bureau, illustration fictive.',
		'image_alt'   => 'Calendrier et étapes d’un dossier d’urbanisme présentés sur un bureau, illustration fictive.',
	),
	/*
	 * Le guide 1 est déjà publié. Il figure ici parce que son corps a changé le
	 * 15 août 2026 — lien réciproque vers le guide Extension, et correction de
	 * la liste des pièces de la déclaration préalable sur la version de
	 * R.431-36 en vigueur depuis le 1er juillet 2026. Son image mise en avant
	 * est déjà posée : le script n'y touchera pas.
	 */
	array(
		'slug'        => 'piscine-garage-carport-autorisation',
		'titre'       => 'Piscine, garage, carport : les seuils qui changent votre autorisation',
		'categorie'   => 'autorisations-projets',
		'extrait'     => 'Trois seuils, et surtout trois façons de mesurer. Ce qui entre dans la surface d’un bassin, ce qu’une dalle sous auvent change, et pourquoi un carport crée de l’emprise sans créer de surface de plancher.',
		'seo_titre'   => 'Piscine, garage, carport : quelle autorisation ? | Urbizen',
		'seo_desc'    => 'Piscine, garage ou carport : ce qui compte vraiment dans le calcul des surfaces, et comment savoir si votre projet relève d’une déclaration ou d’un permis.',
		'image'       => 'seo-guides-v2/guide-piscine-garage-carport.webp',
		'image_titre' => 'Piscine, garage indépendant et carport présentés dans trois projets résidentiels fictifs.',
		'image_alt'   => 'Piscine, garage indépendant et carport présentés dans trois projets résidentiels fictifs.',
	),
);

/*
 * Répertoire des visuels livrés avec le thème. La racine `assets/images` et non
 * plus `assets/images/blog` : les visuels du kit v2 vivent dans
 * `assets/images/seo-guides-v2`, et `image` porte désormais le chemin relatif —
 * même convention que `publier-pages-seo.php`, qui sert les douze autres
 * guides. Partout où le nom de fichier seul est attendu (médiathèque, marqueur
 * d'idempotence), `basename()` le rétablit.
 */
$dossier_images = get_stylesheet_directory() . '/assets/images';

WP_CLI::log( $simulation ? "\n=== SIMULATION — aucune écriture ===\n" : "\n=== PUBLICATION ===\n" );

$resume = array();

foreach ( $guides as $g ) {
	$slug    = $g['slug'];
	$fichier = "$source/$slug.html";

	if ( ! is_file( $fichier ) ) {
		WP_CLI::error( "Corps introuvable pour $slug : $fichier" );
	}
	$corps = (string) file_get_contents( $fichier );
	if ( '' === trim( $corps ) ) {
		WP_CLI::error( "Corps vide pour $slug — publication interrompue." );
	}

	// La catégorie doit préexister : on n'en invente pas au mauvais slug.
	$terme = get_term_by( 'slug', $g['categorie'], 'category' );
	if ( ! $terme ) {
		WP_CLI::error( "Catégorie absente : {$g['categorie']} (guide $slug)" );
	}

	/*
	 * Recherche par slug, tous statuts confondus. `get_page_by_path()` couvre
	 * les brouillons et les articles en corbeille, qu'une requête sur les seuls
	 * articles publiés laisserait passer — et qui produiraient alors un doublon
	 * de slug suffixé « -2 ».
	 */
	$existant = get_page_by_path( $slug, OBJECT, 'post' );
	$id       = $existant ? (int) $existant->ID : 0;

	$donnees = array(
		'post_title'   => $g['titre'],
		'post_name'    => $slug,
		'post_content' => $corps,
		'post_excerpt' => $g['extrait'],
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_author'  => $existant ? $existant->post_author : 1,
	);

	if ( $simulation ) {
		$resume[] = sprintf(
			'%-44s %s',
			$slug,
			$id ? "mise à jour (ID $id)" : 'création'
		);
		continue;
	}

	if ( $id ) {
		$donnees['ID'] = $id;
		$r             = wp_update_post( $donnees, true );
	} else {
		$r = wp_insert_post( $donnees, true );
	}

	if ( is_wp_error( $r ) ) {
		WP_CLI::error( "Échec sur $slug : " . $r->get_error_message() );
	}
	$id = (int) $r;

	// Catégorie unique, la précédente remplacée et non ajoutée.
	wp_set_post_categories( $id, array( (int) $terme->term_id ), false );

	/*
	 * Image mise en avant.
	 *
	 * La règle d'origine — « ne rien écraser » — protégeait une vignette
	 * choisie à la main dans l'admin. Elle avait un effet de bord : le
	 * manifeste ci-dessus, pourtant versionné, ne pouvait plus rien changer une
	 * fois la première vignette posée. Le kit visuel v2 remplace les six
	 * visuels d'origine ; sans levée de ce verrou, le changement n'aurait
	 * jamais atteint le site.
	 *
	 * La distinction se fait donc sur l'ORIGINE de la vignette, pas sur sa
	 * présence. `_urbizen_guide_image` marque les attachements posés par ce
	 * script : si le marqueur désigne un autre fichier que celui du manifeste,
	 * la source versionnée gagne et la vignette est remplacée. Si le marqueur
	 * est absent, quelqu'un a choisi cette image dans l'admin — on n'y touche
	 * pas, et on le signale plutôt que de le taire.
	 */
	$vignette = (int) get_post_thumbnail_id( $id );
	$attendu  = basename( $g['image'] );
	$marqueur = $vignette ? (string) get_post_meta( $vignette, '_urbizen_guide_image', true ) : '';

	if ( $vignette && '' === $marqueur ) {
		WP_CLI::warning( "$slug : vignette posée hors de ce script, conservée — le manifeste demandait $attendu." );
	} elseif ( basename( $marqueur ) !== $attendu ) {
		$chemin = "$dossier_images/{$g['image']}";
		if ( ! is_file( $chemin ) ) {
			WP_CLI::warning( "Visuel absent du thème : $chemin — article publié sans image." );
		} else {
			/*
			 * On marque l'attachement du nom de fichier source. C'est ce qui
			 * rend l'import idempotent : sans marqueur, une seconde exécution
			 * créerait un second attachement pointant sur la même image.
			 */
			$deja = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_urbizen_guide_image',   // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => $attendu,                 // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);

			if ( $deja ) {
				$att = (int) $deja[0];
			} else {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				// Copie : `media_handle_sideload` DÉPLACE le fichier qu'on lui donne.
				// Sans copie, le visuel disparaîtrait du thème déployé.
				$tmp = wp_tempnam( $attendu );
				if ( ! copy( $chemin, $tmp ) ) {
					WP_CLI::error( "Copie impossible : $chemin" );
				}
				$att = media_handle_sideload(
					array( 'name' => $attendu, 'tmp_name' => $tmp ),
					0,
					$g['image_titre']
				);
				if ( is_wp_error( $att ) ) {
					@unlink( $tmp );
					WP_CLI::error( "Import du visuel en échec ($slug) : " . $att->get_error_message() );
				}
				$att = (int) $att;
				update_post_meta( $att, '_urbizen_guide_image', $attendu );
				update_post_meta( $att, '_wp_attachment_image_alt', $g['image_alt'] );
			}

			set_post_thumbnail( $id, $att );
			$vignette = $att;
		}
	}

	/*
	 * AIOSEO. Le greffon crée sa ligne au `save_post` ; on ne fait donc que
	 * poser le titre et la description. Si la ligne manque — le hook peut ne
	 * pas s'exécuter selon le contexte —, on l'insère avec le strict minimum et
	 * on laisse les autres colonnes à leurs valeurs par défaut.
	 */
	global $wpdb;
	$table = $wpdb->prefix . 'aioseo_posts';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$ligne = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB

		if ( $ligne ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array(
					'title'       => $g['seo_titre'],
					'description' => $g['seo_desc'],
					'updated'     => current_time( 'mysql' ),
				),
				array( 'post_id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'post_id'     => $id,
					'title'       => $g['seo_titre'],
					'description' => $g['seo_desc'],
					'created'     => current_time( 'mysql' ),
					'updated'     => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
	} else {
		WP_CLI::warning( 'Table AIOSEO absente — métadonnées non posées.' );
	}

	$resume[] = sprintf(
		'%-44s ID %-6s vignette %-6s cat %s',
		$slug,
		$id,
		$vignette ?: '—',
		$g['categorie']
	);
	WP_CLI::log( "  ✓ $slug" );
}

WP_CLI::log( "\n" . str_repeat( '─', 96 ) );
foreach ( $resume as $ligne ) {
	WP_CLI::log( $ligne );
}
WP_CLI::log( str_repeat( '─', 96 ) . "\n" );

if ( ! $simulation ) {
	WP_CLI::success( count( $guides ) . ' guide(s) traité(s). Purger le cache, puis contrôler chaque URL.' );
}
