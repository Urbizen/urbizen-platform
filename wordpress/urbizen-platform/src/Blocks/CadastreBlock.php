<?php
/**
 * Composant cadastre : bloc Gutenberg et shortcode.
 *
 * Le bloc et le shortcode partagent exactement le même rendu et la même
 * logique d'enfilage : une seule méthode `render()`, appelée par les deux.
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
	 * Nom du bloc.
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
	 * Déclare scripts et styles sans les enfiler.
	 *
	 * L'enfilage effectif a lieu dans render() : les ressources ne sont donc
	 * chargées que sur les pages qui utilisent réellement le composant.
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

		wp_register_style( 'urbizen-cadastre', $base . 'css/urbizen-cadastre.css', array( 'leaflet' ), $ver );
		wp_register_script( 'urbizen-cadastre', $base . 'js/urbizen-cadastre.js', array( 'leaflet' ), $ver, true );
	}

	/**
	 * Enregistre le bloc avec un rendu dynamique.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			self::BLOCK_NAME,
			array(
				'api_version'     => 3,
				'title'           => __( 'Cadastre Urbizen', 'urbizen-platform' ),
				'description'     => __( 'Saisie d’adresse, carte IGN et confirmation de parcelle.', 'urbizen-platform' ),
				'category'        => 'widgets',
				'icon'            => 'location-alt',
				'attributes'      => self::attribute_schema(),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
	}

	/**
	 * Schéma des attributs, commun au bloc et au shortcode.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function attribute_schema(): array {
		return array(
			'label'         => array(
				'type'    => 'string',
				'default' => 'Adresse du projet',
			),
			'placeholder'   => array(
				'type'    => 'string',
				'default' => 'Commencez à saisir une adresse…',
			),
			'continueLabel' => array(
				'type'    => 'string',
				'default' => 'Continuer',
			),
			'storageKey'    => array(
				'type'    => 'string',
				'default' => 'parcel',
			),
			'mapHeight'     => array(
				'type'    => 'string',
				'default' => '',
			),
		);
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
	 * @param array<string, mixed>|string $atts Attributs du shortcode.
	 * @return string HTML.
	 */
	public static function render_shortcode( $atts = array() ): string {
		$defaults = array();

		foreach ( self::attribute_schema() as $key => $schema ) {
			$defaults[ strtolower( $key ) ] = $schema['default'];
		}

		$atts = shortcode_atts( $defaults, is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		// Le shortcode utilise des clés en minuscules ; on rétablit les clés
		// du schéma pour appeler exactement le même rendu que le bloc.
		$normalized = array();

		foreach ( self::attribute_schema() as $key => $schema ) {
			$normalized[ $key ] = $atts[ strtolower( $key ) ] ?? $schema['default'];
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
		$schema = self::attribute_schema();
		$values = array();

		foreach ( $schema as $key => $definition ) {
			$raw            = $attributes[ $key ] ?? $definition['default'];
			$values[ $key ] = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
		}

		// Hauteur de carte : seule une longueur CSS simple est acceptée.
		$map_height = '';

		if ( '' !== $values['mapHeight'] && preg_match( '/^\d{1,4}(px|vh|rem|em)$/', $values['mapHeight'] ) ) {
			$map_height = $values['mapHeight'];
		}

		self::enqueue();

		$attrs = array(
			'class'                  => 'urbizen-cadastre',
			'data-urbizen-cadastre'  => '1',
			'data-label'             => $values['label'],
			'data-placeholder'       => $values['placeholder'],
			'data-continue-label'    => $values['continueLabel'],
			'data-storage-key'       => $values['storageKey'],
			'data-map-height'        => $map_height,
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
	 * composant. Aucun chargement global.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		wp_enqueue_style( 'leaflet' );
		wp_enqueue_style( 'urbizen-cadastre' );
		wp_enqueue_script( 'leaflet' );
		wp_enqueue_script( 'urbizen-cadastre' );
	}
}
