<?php
/**
 * Type de contenu privé des demandes Urbizen.
 *
 * Une demande de conception contient des données personnelles. Elle n'est donc
 * ni publique, ni interrogeable, ni indexable, ni exposée par l'API REST, et
 * ne possède aucun permalien exploitable.
 *
 * **Les capacités ne sont pas héritées des articles.** Un contributeur ou un
 * éditeur possède `edit_posts` ; si ce type se contentait de reprendre les
 * capacités d'article, il ouvrirait les demandes à tout le personnel éditorial.
 * Chaque capacité est donc explicitement ramenée à `manage_options`, et
 * `map_meta_cap` reste désactivé pour qu'aucune correspondance implicite ne
 * s'installe.
 *
 * La création manuelle est interdite : seul `SubmissionRepository` crée une
 * demande, et il passe par `wp_insert_post()`, qui ne consulte pas les
 * capacités.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Déclaration du type de contenu `urbizen_demande`.
 */
final class SubmissionPostType {

	/**
	 * Identifiant du type de contenu.
	 */
	public const POST_TYPE = 'urbizen_demande';

	/**
	 * Capacité requise pour consulter les demandes.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * États métier d'une demande.
	 */
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DELETING   = 'deleting';
	public const STATUS_RECEIVED  = 'received';
	public const STATUS_CONVERTED = 'converted';
	public const STATUS_CLOSED    = 'closed';

	/**
	 * Accroche l'enregistrement.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	/**
	 * Déclare le type de contenu.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Demandes Urbizen', 'urbizen-platform' ),
					'singular_name' => __( 'Demande Urbizen', 'urbizen-platform' ),
					'menu_name'     => __( 'Demandes Urbizen', 'urbizen-platform' ),
					'all_items'     => __( 'Toutes les demandes', 'urbizen-platform' ),
					'search_items'  => __( 'Rechercher une référence', 'urbizen-platform' ),
					'not_found'     => __( 'Aucune demande.', 'urbizen-platform' ),
				),

				// --- Rien de public, à aucun titre ---
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'can_export'          => false,
				'delete_with_user'    => false,

				// --- Administration : visible, mais réservée ---
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-portfolio',

				// Ni éditeur, ni extrait, ni commentaires : rien qui invite à
				// saisir une donnée personnelle à la main.
				'supports'            => array( 'title' ),

				'capability_type'     => self::POST_TYPE,
				'map_meta_cap'        => false,
				'capabilities'        => self::capabilities(),
			)
		);
	}

	/**
	 * Table des capacités, toutes ramenées à une capacité administrative forte.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		$admin = self::CAPABILITY;

		return array(
			// Lecture et modification : administrateurs seulement.
			'read'                   => $admin,
			'read_post'              => $admin,
			'read_private_posts'     => $admin,
			'edit_post'              => $admin,
			'edit_posts'             => $admin,
			'edit_others_posts'      => $admin,
			'edit_private_posts'     => $admin,
			'edit_published_posts'   => $admin,
			'delete_post'            => $admin,
			'delete_posts'           => $admin,
			'delete_others_posts'    => $admin,
			'delete_private_posts'   => $admin,
			'delete_published_posts' => $admin,

			// Personne ne crée ni ne publie une demande à la main : elles
			// naissent d'une soumission, jamais de l'interface.
			'create_posts'           => 'do_not_allow',
			'publish_posts'          => 'do_not_allow',
		);
	}

	/**
	 * États métier reconnus.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array( self::STATUS_PROCESSING, self::STATUS_RECEIVED, self::STATUS_CONVERTED, self::STATUS_CLOSED );
	}

	/**
	 * États d'une demande dont les documents sont consultables.
	 *
	 * Liste **fermée** : tout ce qui n'y figure pas interdit le téléchargement,
	 * y compris un état inconnu. Mieux vaut refuser un document légitime qu'en
	 * servir un pendant une suppression ou après une incohérence.
	 *
	 * @return array<int, string>
	 */
	public static function downloadable_statuses(): array {
		return array( self::STATUS_RECEIVED, self::STATUS_CONVERTED, self::STATUS_CLOSED );
	}
}
