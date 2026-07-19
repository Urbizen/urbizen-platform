<?php
/**
 * Composant cadastre : bloc Gutenberg et shortcode.
 *
 * Le bloc et le shortcode partagent exactement le même rendu et la même
 * logique d'enfilage : une seule méthode `render()`, appelée par les deux.
 *
 * Les attributs sont déclarés **une seule fois**, dans `blocks/cadastre/block.json`.
 * PHP les relit depuis ce fichier : aucune valeur par défaut n'est dupliquée
 * entre le bloc, le shortcode et l'éditeur.
 *
 * Le rendu est dynamique côté PHP. Le contenu enregistré dans `post_content`
 * se limite au commentaire de bloc et à ses attributs de présentation :
 * aucune adresse, aucune parcelle, aucune donnée métier n'y est stockée —
 * ces informations ne quittent jamais l'onglet du visiteur (voir D-008).
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistrement et rendu du composant cadastre.
 */
final class CadastreBlock {

	/**
	 * Nom du bloc, identique à `block.json`.
	 */
	private const BLOCK_NAME = 'urbizen/cadastre';

	/**
	 * Nom du shortcode équivalent.
	 */
	private const SHORTCODE = 'urbizen_cadastre';

	/**
	 * Version de Leaflet embarquée dans `assets/vendor/leaflet/`.
	 */
	private const LEAFLET_VERSION = '1.9.4';

	/**
	 * Format accepté pour la hauteur de carte : une longueur CSS simple.
	 * La même expression est appliquée dans `editor.js`, côté éditeur.
	 */
	private const MAP_HEIGHT_PATTERN = '/^\d{1,4}(px|vh|rem|em)$/';

	/**
	 * Schéma d'attributs mis en cache, lu depuis `block.json`.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $schema = null;

	/**
	 * Accroche l'enregistrement du bloc et du shortcode.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'render_shortcode' ) );
	}

	/**
	 * Répertoire du bloc (emplacement de `block.json`).
	 *
	 * @return string
	 */
	private static function block_dir(): string {
		return URBIZEN_PLATFORM_DIR . 'blocks/cadastre';
	}

	/**
	 * Déclare scripts et styles sans les enfiler.
	 *
	 * L'enfilage effectif a lieu dans render() : les ressources du site public
	 * ne sont donc chargées que sur les pages qui utilisent le composant.
	 * Les ressources d'éditeur, elles, sont enfilées par WordPress via
	 * `editorScript` / `editorStyle` de `block.json`, uniquement dans l'éditeur.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		$base = URBIZEN_PLATFORM_URL . 'assets/';
		$ver  = URBIZEN_PLATFORM_VERSION;

		// Leaflet, servi localement. Aucun CDN : les adresses IP des visiteurs
		// ne doivent pas partir chez un tiers (D-008).
		wp_register_style( 'leaflet', $base . 'vendor/leaflet/leaflet.css', array(), self::LEAFLET_VERSION );
		wp_register_script( 'leaflet', $base . 'vendor/leaflet/leaflet.js', array(), self::LEAFLET_VERSION, true );

		// Site public. Le style dépend de celui de Leaflet, ce qui garantit
		// l'ordre : leaflet.css d'abord, nos surcharges ensuite.
		wp_register_style( 'urbizen-cadastre', $base . 'css/urbizen-cadastre.css', array( 'leaflet' ), $ver );
		wp_register_script( 'urbizen-cadastre', $base . 'js/urbizen-cadastre.js', array( 'leaflet' ), $ver, true );

		// Éditeur. Ni Leaflet, ni le script du site public : l'éditeur affiche
		// un aperçu statique et n'appelle aucun service externe.
		$blocks = URBIZEN_PLATFORM_URL . 'blocks/cadastre/';

		wp_register_script(
			'urbizen-cadastre-editor',
			$blocks . 'editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
			$ver,
			true
		);

		wp_register_style( 'urbizen-cadastre-editor', $blocks . 'editor.css', array(), $ver );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'urbizen-cadastre-editor', 'urbizen-platform' );
		}
	}

	/**
	 * Enregistre le bloc à partir de `block.json`, avec un rendu dynamique.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			self::block_dir(),
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Schéma des attributs, lu une fois depuis `block.json`.
	 *
	 * Source unique : bloc, shortcode et éditeur partagent ces définitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function attribute_schema(): array {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		self::$schema = array();

		$file = self::block_dir() . '/block.json';

		if ( is_readable( $file ) ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( is_array( $decoded ) && isset( $decoded['attributes'] ) && is_array( $decoded['attributes'] ) ) {
				self::$schema = $decoded['attributes'];
			}
		}

		return self::$schema;
	}

	/**
	 * Valeur par défaut d'un attribut.
	 *
	 * @param string $key Nom de l'attribut.
	 * @return string
	 */
	private static function default_for( string $key ): string {
		$schema = self::attribute_schema();

		return isset( $schema[ $key ]['default'] ) ? (string) $schema[ $key ]['default'] : '';
	}

	/**
	 * Rendu du bloc Gutenberg.
	 *
	 * @param array<string, mixed> $attributes Attributs du bloc.
	 * @return string HTML.
	 */
	public static function render_block( $attributes = array() ): string {
		return self::render( is_array( $attributes ) ? $attributes : array() );
	}

	/**
	 * Rendu du shortcode.
	 *
	 * Les attributs de shortcode sont insensibles à la casse et arrivent en
	 * minuscules : on rétablit les clés du schéma pour appeler exactement le
	 * même rendu que le bloc.
	 *
	 * @param array<string, mixed>|string $atts Attributs du shortcode.
	 * @return string HTML.
	 */
	public static function render_shortcode( $atts = array() ): string {
		$schema   = self::attribute_schema();
		$defaults = array();

		foreach ( $schema as $key => $definition ) {
			$defaults[ strtolower( $key ) ] = self::default_for( $key );
		}

		$atts = shortcode_atts( $defaults, is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		$normalized = array();

		foreach ( $schema as $key => $definition ) {
			$normalized[ $key ] = $atts[ strtolower( $key ) ] ?? self::default_for( $key );
		}

		return self::render( $normalized );
	}

	/**
	 * Rendu commun — unique point de vérité du bloc et du shortcode.
	 *
	 * Ne produit qu'un conteneur vide porteur de ses options : le composant
	 * JavaScript construit ensuite son DOM. Aucune donnée personnelle n'est
	 * écrite côté serveur, aucune requête n'est émise ici.
	 *
	 * @param array<string, mixed> $attributes Attributs validés.
	 * @return string HTML échappé.
	 */
	private static function render( array $attributes ): string {
		$values = array();

		foreach ( self::attribute_schema() as $key => $definition ) {
			$raw            = $attributes[ $key ] ?? self::default_for( $key );
			$values[ $key ] = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
		}

		// Hauteur de carte : seule une longueur CSS simple est acceptée. Une
		// valeur fantaisiste est ignorée, jamais recopiée dans la page.
		$map_height = '';

		if ( isset( $values['mapHeight'] ) && '' !== $values['mapHeight']
			&& preg_match( self::MAP_HEIGHT_PATTERN, $values['mapHeight'] ) ) {
			$map_height = $values['mapHeight'];
		}

		self::enqueue();

		$attrs = array(
			'class'                 => 'urbizen-cadastre',
			'data-urbizen-cadastre' => '1',
			'data-label'            => $values['label'] ?? '',
			'data-placeholder'      => $values['placeholder'] ?? '',
			'data-continue-label'   => $values['continueLabel'] ?? '',
			'data-storage-key'      => $values['storageKey'] ?? '',
			'data-map-height'       => $map_height,
		);

		$html = '<div';

		foreach ( $attrs as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		$html .= '>';

		// Message de repli, remplacé dès que le composant se monte. Il reste
		// visible si JavaScript est indisponible : jamais de conteneur muet.
		$html .= '<noscript><p class="urbizen-cadastre-noscript">';
		$html .= esc_html__(
			'La localisation cadastrale nécessite JavaScript. Activez-le, ou indiquez votre adresse et vos références cadastrales directement dans le formulaire.',
			'urbizen-platform'
		);
		$html .= '</p></noscript>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Enfile les ressources du composant.
	 *
	 * Appelé depuis le rendu, donc uniquement sur les pages qui affichent le
	 * composant. `wp_enqueue_*` est idempotent : plusieurs blocs, ou un bloc et
	 * un shortcode sur la même page, ne produisent qu'un seul chargement.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		wp_enqueue_style( 'urbizen-cadastre' );
		wp_enqueue_script( 'urbizen-cadastre' );
	}
}
