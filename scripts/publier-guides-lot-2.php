<?php
/**
 * Publication du lot SEO 2 — 20 guides informationnels.
 *
 *     wp eval-file scripts/publier-guides-lot-2.php simulation
 *     wp eval-file scripts/publier-guides-lot-2.php
 *
 * Le script est volontairement séparé de `publier-pages-seo.php` : une mise
 * en ligne du lot 2 ne doit pas republier les 9 pages projets et les 12 guides
 * du premier cocon. La source de vérité reste `content/guides/<slug>.html`.
 *
 * Garde-fous :
 * - idempotence par slug, tous statuts confondus ;
 * - simulation sans aucune écriture ;
 * - catégories obligatoirement préexistantes ;
 * - aucun remplacement d'une vignette choisie manuellement ;
 * - AIOSEO limité au title, à la description et au focus keyphrase ;
 * - aucun cache purgé automatiquement.
 *
 * @package Urbizen
 */

defined( 'ABSPATH' ) || exit;

$simulation = in_array( 'simulation', (array) ( $args ?? array() ), true );
$source     = getenv( 'URBIZEN_CONTENU' ) ?: dirname( __DIR__ ) . '/content/guides';

if ( ! is_dir( $source ) ) {
	WP_CLI::error( "Répertoire de contenu introuvable : $source" );
}

$guides = array(
	array(
		'slug'      => 'plan-situation-dp1-declaration-prealable',
		'titre'     => 'Plan de situation DP1 : le faire correctement',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'La seule pièce demandée dans toutes les déclarations préalables : rôle, échelle, repérage de la parcelle et erreurs qui rendent un DP1 ambigu.',
		'mot_cle'   => 'plan de situation DP1',
		'seo_titre' => 'Plan de situation DP1 : le faire correctement | Urbizen',
		'seo_desc'  => 'DP1 : rôle, échelle, repérage du terrain, orientation et prises de vue. Une méthode concrète pour produire un plan de situation lisible.',
		'image'     => 'dossier/dp1-plan-situation-cartouche.webp',
		'image_alt' => 'Plan de situation DP1 Urbizen localisant une parcelle fictive dans la commune.',
	),
	array(
		'slug'      => 'dp5-aspect-exterieur-declaration-prealable',
		'titre'     => 'DP5 : représenter l’aspect extérieur du projet',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'Quand la DP5 est nécessaire, ce qu’elle doit montrer et pourquoi elle ne remplace pas le plan des façades et toitures DP4.',
		'mot_cle'   => 'DP5 aspect extérieur',
		'seo_titre' => 'DP5 : représenter l’aspect extérieur du projet | Urbizen',
		'seo_desc'  => 'DP5 : quand cette pièce est demandée, ce qu’elle doit représenter et comment la distinguer du plan des façades et toitures DP4.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'photo-dp7-environnement-proche',
		'titre'     => 'Photo DP7 : cadrer l’environnement proche',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'La DP7 doit montrer le terrain et son voisinage immédiat. Cadrage, point de vue, lisibilité et différence avec l’insertion DP6 et la photo DP8.',
		'mot_cle'   => 'photo DP7 environnement proche',
		'seo_titre' => 'Photo DP7 : cadrer l’environnement proche | Urbizen',
		'seo_desc'  => 'Photo DP7 : choisir le bon cadrage, montrer l’environnement proche et éviter la confusion avec l’insertion DP6 ou la photographie DP8.',
		'image'     => 'dossier/dp7-environnement-cartouche.webp',
		'image_alt' => 'Photographie DP7 Urbizen montrant l’environnement proche d’un projet fictif.',
	),
	array(
		'slug'      => 'photo-dp8-paysage-lointain',
		'titre'     => 'Photo DP8 : montrer le paysage lointain',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'La DP8 replace le terrain dans un paysage plus large. Distance, angle de prise de vue et différence avec la photographie de proximité DP7.',
		'mot_cle'   => 'photo DP8 paysage lointain',
		'seo_titre' => 'Photo DP8 : montrer le paysage lointain | Urbizen',
		'seo_desc'  => 'Photo DP8 : comment montrer le terrain dans son paysage lointain, choisir le point de vue et la distinguer de la photographie DP7.',
		'image'     => 'dossier/dp8-paysage-cartouche.webp',
		'image_alt' => 'Photographie DP8 Urbizen replaçant un projet fictif dans son paysage lointain.',
	),
	array(
		'slug'      => 'plan-cadastral-plan-situation-plan-masse-difference',
		'titre'     => 'Cadastre, plan de situation ou plan de masse ?',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'Le cadastre identifie la parcelle, le DP1 la situe dans la commune et le DP2 implante le projet : trois documents proches, trois usages différents.',
		'mot_cle'   => 'plan cadastral plan de situation plan de masse',
		'seo_titre' => 'Cadastre, plan de situation ou plan de masse ? | Urbizen',
		'seo_desc'  => 'Cadastre, DP1 et DP2 : trois documents différents. Comprendre leur rôle et savoir lequel utiliser dans une déclaration préalable.',
		'image'     => 'dossier/dp2-plan-masse-cartouche.webp',
		'image_alt' => 'Plan de masse DP2 Urbizen illustrant l’implantation d’un projet fictif sur sa parcelle.',
	),
	array(
		'slug'      => 'deposer-autorisation-urbanisme-en-ligne-gnau',
		'titre'     => 'Déposer une autorisation d’urbanisme en ligne',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'GNAU, téléservice communal, accusé de réception et preuve de dépôt : les étapes pratiques pour transmettre une DP ou un permis en ligne.',
		'mot_cle'   => 'déposer déclaration préalable en ligne',
		'seo_titre' => 'Déposer une autorisation d’urbanisme en ligne | Urbizen',
		'seo_desc'  => 'Où déposer une DP ou un permis en ligne, comment conserver la preuve de dépôt et quels contrôles faire avant l’envoi au téléservice.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'panneau-affichage-declaration-prealable',
		'titre'     => 'Panneau de déclaration préalable : règles d’affichage',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'Dimensions, mentions, visibilité, continuité et preuve : ce qu’il faut vérifier lorsqu’une non-opposition à déclaration préalable est affichée sur le terrain.',
		'mot_cle'   => 'panneau déclaration préalable',
		'seo_titre' => 'Panneau de déclaration préalable : règles d’affichage | Urbizen',
		'seo_desc'  => 'Panneau de déclaration préalable : dimensions, mentions, visibilité, durée et preuves d’affichage pour sécuriser le départ du recours des tiers.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'daact-fin-travaux-declaration-conformite',
		'titre'     => 'DAACT : que déposer à la fin des travaux ?',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'La déclaration attestant l’achèvement et la conformité des travaux clôt la phase de chantier : quand la déposer, ce qu’elle atteste et ce qui suit.',
		'mot_cle'   => 'DAACT déclaration achèvement travaux',
		'seo_titre' => 'DAACT : que déposer à la fin des travaux ? | Urbizen',
		'seo_desc'  => 'DAACT : quand déclarer l’achèvement et la conformité des travaux, quelles attestations joindre et comment se déroule le contrôle ensuite.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'modifier-autorisation-urbanisme-dp-pc',
		'titre'     => 'Modifier une déclaration préalable ou un permis',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'Une autorisation encore valide peut parfois être modifiée sans repartir de zéro. Ce qui peut évoluer, ce qui impose un nouveau dépôt et comment raisonner.',
		'mot_cle'   => 'modification autorisation urbanisme',
		'seo_titre' => 'Modifier une déclaration préalable ou un permis | Urbizen',
		'seo_desc'  => 'DP ou permis modificatif : quand une autorisation existante peut être modifiée et quand la nature du projet impose une nouvelle demande.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'regulariser-travaux-sans-autorisation-urbanisme',
		'titre'     => 'Travaux sans autorisation : comment régulariser ?',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'La régularisation n’efface pas automatiquement l’infraction : elle consiste à déposer un dossier décrivant correctement l’existant et les travaux à régulariser.',
		'mot_cle'   => 'régulariser travaux sans autorisation',
		'seo_titre' => 'Travaux sans autorisation : comment régulariser ? | Urbizen',
		'seo_desc'  => 'Travaux non déclarés : comprendre la régularisation, reconstituer le dossier, vérifier les règles actuelles et éviter les fausses garanties.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'vendre-maison-travaux-non-declares',
		'titre'     => 'Vendre avec des travaux non déclarés : quoi vérifier ?',
		'categorie' => 'conseils-demarches',
		'extrait'   => 'Avant une vente, des travaux anciens non déclarés doivent être qualifiés : autorisation nécessaire à l’époque, conformité actuelle, prescription et régularisation éventuelle.',
		'mot_cle'   => 'vendre maison travaux non déclarés',
		'seo_titre' => 'Vendre avec des travaux non déclarés : quoi vérifier ? | Urbizen',
		'seo_desc'  => 'Maison avec travaux non déclarés : les vérifications à faire avant la vente et les situations dans lesquelles une régularisation doit être étudiée.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'division-terrain-declaration-prealable-permis-amenager',
		'titre'     => 'Division de terrain : DP ou permis d’aménager ?',
		'categorie' => 'regles-urbanisme',
		'extrait'   => 'Diviser en vue de construire peut relever d’une déclaration préalable ou d’un permis d’aménager selon la configuration de l’opération et les équipements créés.',
		'mot_cle'   => 'division terrain déclaration préalable permis aménager',
		'seo_titre' => 'Division de terrain : DP ou permis d’aménager ? | Urbizen',
		'seo_desc'  => 'Division de terrain en vue de construire : comprendre quand déposer une DP et quand un permis d’aménager est nécessaire.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'certificat-urbanisme-information-operationnel',
		'titre'     => 'Certificat d’urbanisme : information ou opérationnel ?',
		'categorie' => 'regles-urbanisme',
		'extrait'   => 'CU d’information ou CU opérationnel : deux outils pour connaître le droit applicable et, pour le second, tester une opération avant l’autorisation.',
		'mot_cle'   => 'certificat urbanisme opérationnel',
		'seo_titre' => 'Certificat d’urbanisme : information ou opérationnel ? | Urbizen',
		'seo_desc'  => 'CUa ou CUb : différences, délais, validité de 18 mois, prolongation et intérêt du certificat d’urbanisme avant un projet ou un achat.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'changement-destination-sous-destination-urbanisme',
		'titre'     => 'Destination et sous-destination : quelle différence ?',
		'categorie' => 'regles-urbanisme',
		'extrait'   => 'Habitation, commerce, activité, équipement : identifier destination et sous-destination permet de qualifier correctement un changement d’usage urbanistique.',
		'mot_cle'   => 'changement destination sous-destination',
		'seo_titre' => 'Destination et sous-destination : quelle différence ? | Urbizen',
		'seo_desc'  => 'Comprendre destinations et sous-destinations en urbanisme, identifier un changement de destination et savoir quand une DP ou un permis peut être nécessaire.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'transformer-local-commercial-logement-autorisation',
		'titre'     => 'Transformer un local commercial en logement',
		'categorie' => 'autorisations-projets',
		'extrait'   => 'Passer d’un local commercial ou professionnel à un logement implique de vérifier destination, façade, structure porteuse, PLU et, le cas échéant, copropriété.',
		'mot_cle'   => 'transformer local commercial en logement autorisation',
		'seo_titre' => 'Transformer un local commercial en logement | Urbizen',
		'seo_desc'  => 'Local commercial vers logement : changement de destination, DP ou permis, façade, structure, PLU et copropriété à vérifier avant les travaux.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'lotissement-reglement-cahier-charges-plu',
		'titre'     => 'Lotissement : règlement, cahier des charges ou PLU ?',
		'categorie' => 'regles-urbanisme',
		'extrait'   => 'PLU, règlement du lotissement et cahier des charges peuvent se superposer. Il faut distinguer règles d’urbanisme opposables et obligations privées entre colotis.',
		'mot_cle'   => 'règlement lotissement ou PLU',
		'seo_titre' => 'Lotissement : règlement, cahier des charges ou PLU ? | Urbizen',
		'seo_desc'  => 'Lotissement : distinguer PLU, règlement et cahier des charges, comprendre la caducité des règles d’urbanisme et les obligations entre colotis.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'terrain-zone-inondable-ppri-projet-construction',
		'titre'     => 'Terrain en zone inondable : lire le PPRI avant le projet',
		'categorie' => 'regles-urbanisme',
		'extrait'   => 'Un terrain en zone inondable n’est pas automatiquement inconstructible : le zonage et le règlement local du PPRI déterminent interdictions et prescriptions.',
		'mot_cle'   => 'terrain zone inondable PPRI',
		'seo_titre' => 'Terrain en zone inondable : lire le PPRI avant le projet | Urbizen',
		'seo_desc'  => 'PPRI : repérer la zone exacte d’un terrain, lire le règlement local et distinguer interdiction, prescriptions et règles du PLU avant de construire.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'climatisation-pompe-chaleur-declaration-prealable',
		'titre'     => 'Climatisation ou pompe à chaleur : faut-il une DP ?',
		'categorie' => 'autorisations-projets',
		'extrait'   => 'En 2026, la formalité dépend de l’aspect extérieur, de la visibilité d’une PAC et du contexte protégé : les cas à distinguer avant l’installation.',
		'mot_cle'   => 'pompe à chaleur déclaration préalable',
		'seo_titre' => 'Climatisation ou pompe à chaleur : faut-il une DP ? | Urbizen',
		'seo_desc'  => 'PAC ou climatisation : quand une déclaration préalable est nécessaire en 2026, rôle de la visibilité, de l’aspect extérieur et des secteurs protégés.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'veranda-declaration-prealable-permis-construire',
		'titre'     => 'Véranda : déclaration préalable ou permis ?',
		'categorie' => 'autorisations-projets',
		'extrait'   => '20 m² n’est pas un seuil universel : en zone urbaine de PLU, une véranda peut relever de la DP jusqu’à 40 m² sous certaines conditions.',
		'mot_cle'   => 'véranda déclaration préalable permis construire',
		'seo_titre' => 'Véranda : déclaration préalable ou permis ? | Urbizen',
		'seo_desc'  => 'Véranda : seuils de 20 et 40 m², zone urbaine du PLU, surface totale après travaux et seuil de 150 m² pour choisir entre DP et permis.',
		'image'     => '',
		'image_alt' => '',
	),
	array(
		'slug'      => 'surelevation-maison-declaration-prealable-permis',
		'titre'     => 'Surélévation de maison : DP ou permis de construire ?',
		'categorie' => 'autorisations-projets',
		'extrait'   => 'Une surélévation suit les seuils d’agrandissement, mais sa faisabilité dépend aussi de la hauteur, du gabarit et de la structure existante.',
		'mot_cle'   => 'surélévation maison déclaration préalable permis',
		'seo_titre' => 'Surélévation de maison : DP ou permis de construire ? | Urbizen',
		'seo_desc'  => 'Surélévation : seuils de DP ou permis, zone urbaine du PLU, règle de hauteur, seuil de 150 m² et pièces à préparer avant le dépôt.',
		'image'     => '',
		'image_alt' => '',
	),
);

WP_CLI::log( $simulation ? "\n=== SIMULATION LOT SEO 2 — aucune écriture ===\n" : "\n=== PUBLICATION LOT SEO 2 ===\n" );

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

	$terme = get_term_by( 'slug', $g['categorie'], 'category' );
	if ( ! $terme ) {
		WP_CLI::error( "Catégorie absente : {$g['categorie']} (guide $slug)" );
	}

	$existant = get_page_by_path( $slug, OBJECT, 'post' );
	$id       = $existant ? (int) $existant->ID : 0;

	if ( $simulation ) {
		$resume[] = sprintf( '%-52s %-22s %s', $slug, $g['categorie'], $id ? "mise à jour (ID $id)" : 'création' );
		continue;
	}

	$donnees = array(
		'post_title'   => $g['titre'],
		'post_name'    => $slug,
		'post_content' => $corps,
		'post_excerpt' => $g['extrait'],
		'post_status'  => 'publish',
		'post_type'    => 'post',
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
	wp_set_post_categories( $id, array( (int) $terme->term_id ), false );

	/*
	 * Vignettes : seulement les visuels explicitement réutilisables du kit
	 * dossier sont référencés ici. Aucun visuel générique n'est inventé pour les
	 * autres guides. Une vignette choisie manuellement reste prioritaire.
	 */
	$vignette = (int) get_post_thumbnail_id( $id );
	$attendu  = '' !== $g['image'] ? basename( $g['image'] ) : '';
	$marqueur = $vignette ? (string) get_post_meta( $vignette, '_urbizen_seo_lot2_image', true ) : '';

	if ( $vignette && '' === $marqueur ) {
		WP_CLI::warning( "$slug : vignette posée hors du publisher lot 2, conservée." );
	} elseif ( '' !== $attendu && basename( $marqueur ) !== $attendu ) {
		$dossier_images = get_stylesheet_directory() . '/assets/images';
		$chemin         = "$dossier_images/{$g['image']}";

		if ( ! is_file( $chemin ) ) {
			WP_CLI::warning( "Visuel absent du thème : $chemin — guide publié sans vignette." );
		} else {
			$deja = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_urbizen_seo_lot2_image', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => $attendu,                  // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);

			if ( $deja ) {
				$att = (int) $deja[0];
			} else {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$tmp = wp_tempnam( $attendu );
				if ( ! copy( $chemin, $tmp ) ) {
					WP_CLI::error( "Copie impossible : $chemin" );
				}

				$att = media_handle_sideload(
					array( 'name' => $attendu, 'tmp_name' => $tmp ),
					0,
					$g['image_alt']
				);

				if ( is_wp_error( $att ) ) {
					@unlink( $tmp );
					WP_CLI::error( "Import du visuel en échec ($slug) : " . $att->get_error_message() );
				}

				$att = (int) $att;
				update_post_meta( $att, '_urbizen_seo_lot2_image', $attendu );
				update_post_meta( $att, '_wp_attachment_image_alt', $g['image_alt'] );
			}

			set_post_thumbnail( $id, $att );
			$vignette = $att;
		}
	}

	global $wpdb;
	$table = $wpdb->prefix . 'aioseo_posts';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$ligne = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE post_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$keyphrases = wp_json_encode(
			array(
				'focus'      => array( 'keyphrase' => $g['mot_cle'] ),
				'additional' => array(),
			)
		);

		if ( $ligne ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array(
					'title'       => $g['seo_titre'],
					'description' => $g['seo_desc'],
					'keyphrases'  => $keyphrases,
					'updated'     => current_time( 'mysql' ),
				),
				array( 'post_id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'post_id'     => $id,
					'title'       => $g['seo_titre'],
					'description' => $g['seo_desc'],
					'keyphrases'  => $keyphrases,
					'created'     => current_time( 'mysql' ),
					'updated'     => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	} else {
		WP_CLI::warning( 'Table AIOSEO absente — métadonnées non posées.' );
	}

	$resume[] = sprintf(
		'%-52s ID %-6s %-22s vignette %s',
		$slug,
		$id,
		$g['categorie'],
		$vignette ?: '—'
	);
	WP_CLI::log( "  ✓ $slug" );
}

WP_CLI::log( "\n" . str_repeat( '─', 112 ) );
foreach ( $resume as $ligne ) {
	WP_CLI::log( $ligne );
}
WP_CLI::log( str_repeat( '─', 112 ) . "\n" );

if ( ! $simulation ) {
	WP_CLI::success( count( $guides ) . ' guide(s) lot 2 traité(s). Purger le cache puis contrôler les 20 URL.' );
}
