<?php
/**
 * Publication du cocon SEO « projets » — 9 pages et 12 guides.
 *
 *     wp eval-file scripts/publier-pages-seo.php [simulation]
 *
 * Même mécanique que `publier-guides.php`, dont ce script reprend les
 * garde-fous : recherche par SLUG et non par identifiant, sources lues au
 * dépôt, image mise en avant jamais écrasée, aucune catégorie créée.
 *
 * CE QU'IL AJOUTE
 *
 * Il publie deux types de contenus. Les neuf pages projets sont de vraies
 * PAGES WordPress, avec le gabarit `page-projet-seo` posé en méta ; les douze
 * guides sont des ARTICLES rangés dans une catégorie existante. La distinction
 * n'est pas cosmétique : une page commerciale n'a pas à figurer dans l'index
 * des guides ni dans son flux.
 *
 * « simulation » est un argument POSITIONNEL : `wp eval-file` refuse les
 * options qu'il ne connaît pas.
 *
 * @package Urbizen
 */

defined( 'ABSPATH' ) || exit;

$simulation = in_array( 'simulation', (array) ( $args ?? array() ), true );

$racine = getenv( 'URBIZEN_CONTENU' ) ?: dirname( __DIR__ ) . '/content';

foreach ( array( 'pages', 'guides' ) as $sous ) {
	if ( ! is_dir( "$racine/$sous" ) ) {
		WP_CLI::error( "Répertoire de contenu introuvable : $racine/$sous" );
	}
}

/**
 * Racine des visuels du thème.
 *
 * Le manifeste porte un chemin RELATIF — `seo-projects/…` ou `dossier/…` —
 * parce que le kit sert deux familles : les photographies de projets fictifs,
 * et les planches métier au cartouche. Le handoff visuel désigne les unes ou
 * les autres selon l'URL, et un chemin en dur ici interdirait la moitié.
 */
$dossier_images = get_stylesheet_directory() . '/assets/images';

/*
 * LE MANIFESTE
 *
 * Tout ce qui est servi aux moteurs est ici, en clair : titre, slug, extrait,
 * métadonnées AIOSEO, image et texte alternatif. Les textes alternatifs sont
 * repris mot pour mot de `docs/SEO_VISUALS_HANDOFF.md`, qui fait foi.
 */
$contenus = array(

	// ------------------------------------------------------ 9 pages projets --

	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-extension-maison',
		'titre'     => 'Extension de maison : le dossier de déclaration préalable',
		'extrait'   => 'Extension, véranda, surélévation : comment savoir si votre projet relève d’une déclaration préalable ou d’un permis, et ce qu’Urbizen prépare pour la mairie.',
		'seo_titre' => 'Extension de maison : votre dossier de déclaration | Urbizen',
		'seo_desc'  => 'Extension de 5 à 40 m² : Urbizen détermine la démarche, dessine les plans et prépare le dossier complet à déposer en mairie. Devis avant commande.',
		'image'     => 'seo-projects/extension-maison-photo.webp',
		'image_alt' => 'Extension contemporaine sobre accolée à l’arrière d’une maison en brique, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-piscine',
		'titre'     => 'Piscine : le dossier de déclaration préalable',
		'extrait'   => 'Bassin, plage, local technique, abri : ce qui compte dans le calcul, ce que la mairie attend, et le dossier qu’Urbizen prépare pour vous.',
		'seo_titre' => 'Dossier de déclaration pour une piscine | Urbizen',
		'seo_desc'  => 'Bassin, plage, local technique, abri : ce qui compte pour votre déclaration, et le dossier qu’Urbizen prépare pour la mairie. À partir de 249 €.',
		'image'     => 'seo-projects/piscine-photo.webp',
		'image_alt' => 'Piscine rectangulaire implantée dans le jardin d’une maison individuelle, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-abri-de-jardin',
		'titre'     => 'Abri de jardin : la déclaration de travaux, de bout en bout',
		'extrait'   => 'Au-delà de 5 m², un abri de jardin se déclare. Les seuils, la taxe d’aménagement et le dossier prêt à déposer, expliqués sans jargon.',
		'seo_titre' => 'Abri de jardin : déclaration de travaux clé en main | Urbizen',
		'seo_desc'  => 'Au-delà de 5 m², un abri de jardin se déclare. Les seuils, la taxe d’aménagement et le dossier qu’Urbizen prépare pour vous. À partir de 189 €.',
		'image'     => 'seo-projects/abri-jardin-photo.webp',
		'image_alt' => 'Abri de jardin en bois installé dans une parcelle résidentielle, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-pergola-carport',
		'titre'     => 'Pergola et carport : quelle déclaration pour votre projet',
		'extrait'   => 'Pergola ouverte, pergola couverte, carport, annexe fermée : quatre objets que le code ne traite pas de la même façon. Urbizen qualifie le vôtre.',
		'seo_titre' => 'Pergola ou carport : quelle déclaration, quel dossier | Urbizen',
		'seo_desc'  => 'Pergola ouverte, pergola couverte, carport, annexe fermée : quatre cas, quatre régimes. Urbizen qualifie le vôtre et prépare le dossier.',
		'image'     => 'seo-projects/pergola-photo.webp',
		'image_alt' => 'Pergola en aluminium adossée à la façade arrière d’une maison, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-transformation-garage',
		'titre'     => 'Transformer un garage en pièce de vie : la démarche',
		'extrait'   => 'Façade modifiée, surface de plancher créée, stationnement du PLU : trois notions distinctes que ce projet met en jeu d’un coup.',
		'seo_titre' => 'Transformer un garage : le dossier à déposer | Urbizen',
		'seo_desc'  => 'Façade modifiée, surface de plancher créée, stationnement du PLU : ce que la transformation d’un garage déclenche vraiment, et le dossier associé.',
		'image'     => 'seo-projects/transformation-garage-photo.webp',
		'image_alt' => 'Ancienne ouverture de garage remplacée par une large baie vitrée, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-panneaux-solaires',
		'titre'     => 'Panneaux solaires : la déclaration et les pièces attendues',
		'extrait'   => 'Toiture, sol ou ombrière : la formalité n’est pas la même. Urbizen qualifie votre installation et prépare le dossier pour la mairie.',
		'seo_titre' => 'Panneaux solaires : déclaration préalable et dossier | Urbizen',
		'seo_desc'  => 'Toiture, sol ou ombrière : la formalité n’est pas la même. Urbizen qualifie votre installation et prépare les pièces attendues par la mairie.',
		'image'     => 'seo-projects/panneaux-solaires-photo.webp',
		'image_alt' => 'Panneaux photovoltaïques alignés sur un pan de toiture en tuiles, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-fenetre-de-toit',
		'titre'     => 'Fenêtre de toit : déclarer la création en mairie',
		'extrait'   => 'Créer une fenêtre de toit modifie l’aspect extérieur. Où passe la ligne avec l’entretien, et ce que le dossier doit montrer.',
		'seo_titre' => 'Fenêtre de toit : déclarer la création en mairie | Urbizen',
		'seo_desc'  => 'Créer ou agrandir une fenêtre de toit modifie l’aspect extérieur. Ce que la mairie attend, et le dossier qu’Urbizen prépare. À partir de 189 €.',
		'image'     => 'seo-projects/fenetre-toit-photo.webp',
		'image_alt' => 'Deux fenêtres de toit intégrées à une toiture en tuiles, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-modification-facade',
		'titre'     => 'Modifier une façade : la déclaration et les pièces à fournir',
		'extrait'   => 'Ouverture nouvelle, menuiseries, enduit, ravalement : ce qui relève d’une déclaration, ce qui n’en relève pas, et pourquoi.',
		'seo_titre' => 'Modifier une façade : déclaration et pièces à fournir | Urbizen',
		'seo_desc'  => 'Ouverture nouvelle, menuiseries, enduit, ravalement : ce qui relève d’une déclaration et ce qui n’en relève pas, puis le dossier prêt à déposer.',
		'image'     => 'seo-projects/modification-facade-photo.webp',
		'image_alt' => 'Façade de maison avec une grande ouverture et des menuiseries anthracite, illustration d’un projet fictif.',
	),
	array(
		'type'      => 'page',
		'slug'      => 'declaration-prealable-cloture-portail',
		'titre'     => 'Clôture et portail : faut-il une déclaration de travaux ?',
		'extrait'   => 'C’est le seul sujet où la règle nationale ne tranche pas : tout dépend de ce que votre commune a décidé. Comment le vérifier, et quoi déposer.',
		'seo_titre' => 'Clôture et portail : faut-il déclarer ? | Urbizen',
		'seo_desc'  => 'La clôture est le cas où la commune décide. Comment savoir si la vôtre est soumise à déclaration, et le dossier qu’Urbizen prépare si elle l’est.',
		'image'     => 'seo-projects/cloture-portail-photo.webp',
		'image_alt' => 'Clôture et portails anthracite sur un soubassement maçonné en bord de rue, illustration d’un projet fictif.',
	),

	// -------------------------------------------- 5 guides sur les pièces ----

	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'pieces-declaration-prealable',
		'titre'     => 'Les pièces d’une déclaration préalable, une par une',
		'extrait'   => 'Une seule pièce est obligatoire dans tous les cas. Les sept autres dépendent de ce que fait votre projet, et de l’endroit où il le fait.',
		'seo_titre' => 'Les pièces d’une déclaration préalable, une par une | Urbizen',
		'seo_desc'  => 'DP1 à DP8 : ce que chaque pièce montre, quand elle est demandée, ce qu’elle doit faire apparaître, et l’erreur qui la fait revenir.',
		'image'     => 'seo-projects/pieces-declaration-prealable.webp',
		'image_alt' => 'Ensemble des planches d’un dossier de déclaration préalable Urbizen présentées côte à côte avec leur cartouche, sur un projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'plan-masse-dp2',
		'titre'     => 'Le plan de masse DP2, lu ligne à ligne',
		'extrait'   => 'La pièce sur laquelle l’instruction vérifie l’implantation, les distances et les hauteurs. Une planche réelle, décomposée.',
		'seo_titre' => 'Le plan de masse DP2, lu ligne à ligne | Urbizen',
		'seo_desc'  => 'Échelle, orientation, cotes en trois dimensions, distances aux limites : ce que le plan de masse doit porter, et les cinq questions à lui poser.',
		'image'     => 'dossier/dp2-plan-masse-cartouche.webp',
		'image_alt' => 'Plan de masse coté d’une extension à l’arrière d’une maison, avec les distances aux limites et le récapitulatif des surfaces, sous cartouche Urbizen — projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'insertion-graphique-dp6',
		'titre'     => 'L’insertion graphique DP6 : ce qu’elle montre vraiment',
		'extrait'   => 'Ce n’est pas une belle image du projet. C’est une démonstration : voilà ce qu’on verra, depuis là où on le verra.',
		'seo_titre' => 'L’insertion graphique DP6 : ce qu’elle montre | Urbizen',
		'seo_desc'  => 'La différence entre un rendu et une insertion, les trois erreurs qui vident la pièce de son sens, et ce que l’architecte des Bâtiments de France y cherche.',
		'image'     => 'dossier/dp6-insertion-cartouche.webp',
		'image_alt' => 'Document graphique d’insertion montrant une extension intégrée à la photographie du site réel, sous cartouche Urbizen — projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'plan-facades-toitures-dp4',
		'titre'     => 'Plan des façades et des toitures DP4 : comment le lire',
		'extrait'   => 'La pièce que l’instruction compare. Une planche qui ne montre qu’un seul état ne permet aucune comparaison.',
		'seo_titre' => 'Plan des façades et toitures DP4 : comment le lire | Urbizen',
		'seo_desc'  => 'Quand cette pièce est exigée, pourquoi toutes les façades concernées doivent y figurer, et à quoi sert la légende des matériaux et des teintes.',
		'image'     => 'dossier/dp4-facades-cartouche.webp',
		'image_alt' => 'Planche des façades et toitures présentant quatre élévations avec la légende des matériaux et des teintes, sous cartouche Urbizen — projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'plan-coupe-dp3',
		'titre'     => 'Le plan en coupe DP3 et le profil du terrain',
		'extrait'   => 'Sa fonction est précise : montrer ce que le projet fait au terrain. Pas au bâtiment, pas à la distribution intérieure.',
		'seo_titre' => 'Le plan en coupe DP3 et le profil du terrain | Urbizen',
		'seo_desc'  => 'Deux lignes de terrain, avant et après, les cotes qui comptent, où passe le trait de coupe, et les trois erreurs qui font revenir la pièce.',
		'image'     => 'dossier/dp3-plan-coupe-cartouche.webp',
		'image_alt' => 'Plan en coupe montrant le profil du terrain avant et après travaux et l’implantation de la construction, sous cartouche Urbizen — projet fictif.',
	),

	// ------------------------------------ 7 guides de qualification ----------

	array(
		'type'      => 'post',
		'categorie' => 'regles-urbanisme',
		'slug'      => 'secteur-protege-abf-declaration-travaux',
		'titre'     => 'Savoir si votre terrain est en secteur protégé',
		'extrait'   => 'Ce n’est pas une impression, c’est un périmètre — tracé, consultable, et vérifiable en quelques minutes.',
		'seo_titre' => 'Savoir si votre terrain est en secteur protégé | Urbizen',
		'seo_desc'  => 'Trois périmètres souvent confondus, où les vérifier, et ce qu’ils changent : dispenses qui tombent, avis de l’ABF, délai majoré d’un mois.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'type'      => 'post',
		'categorie' => 'regles-urbanisme',
		'slug'      => 'emprise-au-sol-surface-de-plancher',
		'titre'     => 'Emprise au sol et surface de plancher : deux compteurs',
		'extrait'   => 'Deux grandeurs qui décident de presque tout, qui ne se calculent pas de la même façon, et qui ne s’additionnent jamais.',
		'seo_titre' => 'Emprise au sol et surface de plancher : deux compteurs | Urbizen',
		'seo_desc'  => 'Les deux définitions, la règle du « et » et celle du « ou », et quatre calculs chiffrés qui isolent chacun un mécanisme.',
		'image'     => 'dossier/dp2-plan-masse-cartouche.webp',
		'image_alt' => 'Plan de masse coté montrant l’emprise au sol d’une maison et de son extension sur la parcelle, sous cartouche Urbizen — projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'regles-urbanisme',
		'slug'      => 'distance-limite-separative-construction',
		'titre'     => 'À quelle distance de la limite peut-on construire ?',
		'extrait'   => 'Le code de l’urbanisme ne fixe aucune distance générale. Elle est locale par construction — voici où la lire.',
		'seo_titre' => 'À quelle distance de la limite peut-on construire ? | Urbizen',
		'seo_desc'  => 'Pourquoi il n’existe pas de réponse nationale, les trois formes que prend la règle, et depuis quel point la distance se mesure réellement.',
		'image'     => 'dossier/dp2-plan-masse-cartouche.webp',
		'image_alt' => 'Plan de masse portant les distances d’implantation aux limites séparatives, sous cartouche Urbizen — projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'regles-urbanisme',
		'slug'      => 'recours-architecte-150-m2',
		'titre'     => 'Le seuil des 150 m² et le recours à l’architecte',
		'extrait'   => 'Ce qu’on compte pour l’atteindre, et les deux cas où on le franchit sans agrandir d’un mètre carré.',
		'seo_titre' => 'Le seuil des 150 m² et le recours à l’architecte | Urbizen',
		'seo_desc'  => 'Le calcul en trois temps, les conditions de la dispense, et les deux situations contre-intuitives qui prennent les projets de vitesse.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'demande-pieces-complementaires-urbanisme',
		'titre'     => 'Répondre à une demande de pièces complémentaires',
		'extrait'   => 'Ce n’est ni un refus ni un mauvais signe. Ce qui coûte cher, c’est le temps qu’on met à y répondre.',
		'seo_titre' => 'Répondre à une demande de pièces complémentaires | Urbizen',
		'seo_desc'  => 'Le délai qui recommence au lieu de reprendre, les deux protections que le code vous donne, et les cinq gestes pour répondre vite et bien.',
		'image'     => 'seo-projects/pieces-declaration-prealable.webp',
		'image_alt' => 'Ensemble des planches d’un dossier de déclaration préalable Urbizen présentées côte à côte avec leur cartouche, sur un projet fictif.',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'refus-declaration-prealable',
		'titre'     => 'Après un refus de déclaration préalable',
		'extrait'   => 'Un refus est une décision motivée : elle dit précisément ce qui ne va pas. La première chose à faire est de la lire.',
		'seo_titre' => 'Après un refus de déclaration préalable | Urbizen',
		'seo_desc'  => 'Lire la motivation, distinguer les quatre types de motifs, et connaître les voies ouvertes — sans improviser sur les délais de recours.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'type'      => 'post',
		'categorie' => 'conseils-demarches',
		'slug'      => 'cerfa-declaration-travaux',
		'titre'     => 'Quel CERFA pour une déclaration de travaux en 2026',
		'extrait'   => 'Le formulaire a changé de numéro, et les anciens restent visibles en ligne. Les références en vigueur, avec la source qui fait foi.',
		'seo_titre' => 'Quel CERFA pour une déclaration de travaux en 2026 | Urbizen',
		'seo_desc'  => 'Le CERFA 16702 a remplacé les 13703 et 13404 pour les demandes déposées depuis 2025. Les numéros en vigueur, et comment les vérifier soi-même.',
		'image'     => '',
		'image_alt' => '',
	),
);

WP_CLI::log( $simulation ? "\n=== SIMULATION — aucune écriture ===\n" : "\n=== PUBLICATION ===\n" );

$resume = array();

foreach ( $contenus as $c ) {
	$slug    = $c['slug'];
	$est_page = 'page' === $c['type'];
	$fichier = $racine . ( $est_page ? '/pages/' : '/guides/' ) . "$slug.html";

	if ( ! is_file( $fichier ) ) {
		WP_CLI::error( "Corps introuvable pour $slug : $fichier" );
	}
	$corps = (string) file_get_contents( $fichier );
	if ( '' === trim( $corps ) ) {
		WP_CLI::error( "Corps vide pour $slug — publication interrompue." );
	}

	// La catégorie doit préexister : on n'en invente aucune.
	$terme = null;
	if ( ! $est_page ) {
		$terme = get_term_by( 'slug', $c['categorie'], 'category' );
		if ( ! $terme ) {
			WP_CLI::error( "Catégorie absente : {$c['categorie']} (guide $slug)" );
		}
	}

	/*
	 * Recherche par slug, tous statuts confondus — brouillons et corbeille
	 * compris, faute de quoi un slug déjà pris ressortirait suffixé « -2 ».
	 */
	$existant = get_page_by_path( $slug, OBJECT, $est_page ? 'page' : 'post' );
	$id       = $existant ? (int) $existant->ID : 0;

	if ( $simulation ) {
		$resume[] = sprintf( '%-8s %-46s %s', $c['type'], $slug, $id ? "mise à jour (ID $id)" : 'création' );
		continue;
	}

	$donnees = array(
		'post_title'   => $c['titre'],
		'post_name'    => $slug,
		'post_content' => $corps,
		'post_excerpt' => $c['extrait'],
		'post_status'  => 'publish',
		'post_type'    => $est_page ? 'page' : 'post',
		'post_author'  => $existant ? $existant->post_author : 1,
	);

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

	// Gabarit pour les pages, catégorie unique pour les guides.
	if ( $est_page ) {
		update_post_meta( $id, '_wp_page_template', 'page-projet-seo' );
	} else {
		wp_set_post_categories( $id, array( (int) $terme->term_id ), false );
	}

	/*
	 * Image mise en avant : on ne touche à rien si le contenu en a déjà une.
	 * Les guides sans image du manifeste n'en reçoivent pas — le handoff visuel
	 * dit expressément qu'un visuel décoratif vaut moins que pas d'image.
	 */
	$vignette = (int) get_post_thumbnail_id( $id );
	if ( ! $vignette && '' !== $c['image'] ) {
		$chemin = "$dossier_images/{$c['image']}";
		if ( ! is_file( $chemin ) ) {
			WP_CLI::warning( "Visuel absent du thème : $chemin — contenu publié sans image." );
		} else {
			// Marqueur d'idempotence : sans lui, une seconde exécution créerait
			// un second attachement pointant sur le même fichier.
			$deja = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_urbizen_seo_image',   // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => basename( $c['image'] ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);

			if ( $deja ) {
				$att = (int) $deja[0];
			} else {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				// Copie obligatoire : `media_handle_sideload` DÉPLACE le fichier
				// qu'on lui donne, et viderait le thème de ses visuels.
				$tmp = wp_tempnam( basename( $c['image'] ) );
				if ( ! copy( $chemin, $tmp ) ) {
					WP_CLI::error( "Copie impossible : $chemin" );
				}
				$att = media_handle_sideload(
					array( 'name' => basename( $c['image'] ), 'tmp_name' => $tmp ),
					0,
					$c['image_alt']
				);
				if ( is_wp_error( $att ) ) {
					@unlink( $tmp );
					WP_CLI::error( "Import du visuel en échec ($slug) : " . $att->get_error_message() );
				}
				$att = (int) $att;
				update_post_meta( $att, '_urbizen_seo_image', basename( $c['image'] ) );
				update_post_meta( $att, '_wp_attachment_image_alt', $c['image_alt'] );
			}

			set_post_thumbnail( $id, $att );
			$vignette = $att;
		}
	}

	// AIOSEO : le greffon crée sa ligne au `save_post`, on ne pose que le titre
	// et la description. Si la ligne manque, on l'insère au strict minimum.
	global $wpdb;
	$table = $wpdb->prefix . 'aioseo_posts';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$ligne = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB

		if ( $ligne ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array( 'title' => $c['seo_titre'], 'description' => $c['seo_desc'], 'updated' => current_time( 'mysql' ) ),
				array( 'post_id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'post_id'     => $id,
					'title'       => $c['seo_titre'],
					'description' => $c['seo_desc'],
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
		'%-8s %-46s ID %-6s vignette %-6s %s',
		$c['type'],
		$slug,
		$id,
		$vignette ?: '—',
		$est_page ? 'gabarit page-projet-seo' : $c['categorie']
	);
	WP_CLI::log( "  ✓ $slug" );
}

WP_CLI::log( "\n" . str_repeat( '─', 112 ) );
foreach ( $resume as $ligne ) {
	WP_CLI::log( $ligne );
}
WP_CLI::log( str_repeat( '─', 112 ) . "\n" );

if ( ! $simulation ) {
	WP_CLI::success( count( $contenus ) . ' contenu(s) traité(s). Purger le cache, puis contrôler chaque URL.' );
}
