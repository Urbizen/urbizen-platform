<?php
/**
 * Formulaire Urbizen : bloc Gutenberg et shortcode.
 *
 * Même patron que CadastreBlock : attributs déclarés une seule fois dans
 * `blocks/formulaire/block.json`, rendu dynamique côté PHP, un unique
 * `render()` partagé par le bloc et le shortcode, ressources enfilées
 * seulement par le rendu.
 *
 * `post_content` ne reçoit que des attributs de présentation : type de
 * formulaire, clé de stockage et identifiant d'instance. Jamais d'adresse,
 * jamais de parcelle.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Blocks;

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistrement et rendu du formulaire.
 */
final class FormBlock {

	/**
	 * Nom du bloc, identique à `block.json`.
	 */
	private const BLOCK_NAME = 'urbizen/formulaire';

	/**
	 * Nom du shortcode équivalent.
	 */
	private const SHORTCODE = 'urbizen_formulaire';

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
	 * Répertoire du bloc.
	 */
	private static function block_dir(): string {
		return URBIZEN_PLATFORM_DIR . 'blocks/formulaire';
	}

	/**
	 * Déclare scripts et styles sans les enfiler.
	 *
	 * Le script du formulaire ne dépend **pas** de celui du cadastre : les deux
	 * communiquent par événement et par `sessionStorage`. Un formulaire peut
	 * donc vivre sur une page sans carte.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		$base   = URBIZEN_PLATFORM_URL . 'assets/';
		$blocks = URBIZEN_PLATFORM_URL . 'blocks/formulaire/';
		$ver    = URBIZEN_PLATFORM_VERSION;

		wp_register_style( 'urbizen-form', $base . 'css/urbizen-form.css', array(), $ver );
		wp_register_script( 'urbizen-form', $base . 'js/urbizen-form.js', array(), $ver, true );

		wp_register_script(
			'urbizen-form-editor',
			$blocks . 'editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
			$ver,
			true
		);

		wp_register_style( 'urbizen-form-editor', $blocks . 'editor.css', array(), $ver );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'urbizen-form-editor', 'urbizen-platform' );
		}
	}

	/**
	 * Enregistre le bloc à partir de `block.json`.
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
	 * @param array<string, mixed> $attributes Attributs.
	 * @return string HTML échappé.
	 */
	private static function render( array $attributes ): string {
		$values = array();

		foreach ( self::attribute_schema() as $key => $definition ) {
			$raw            = $attributes[ $key ] ?? self::default_for( $key );
			$values[ $key ] = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
		}

		// Le type de formulaire ne peut valoir que ce que le code connaît.
		$type = $values['formType'] ?? '';
		$def  = FormRegistry::get( $type );

		if ( null === $def ) {
			$def = FormRegistry::get( FormRegistry::default_type() );
		}

		if ( null === $def ) {
			return '';
		}

		// Clé de stockage : caractères sûrs uniquement, jamais vide.
		$storage_key = preg_replace( '/[^A-Za-z0-9_:-]/', '', (string) ( $values['storageKey'] ?? '' ) );

		if ( '' === (string) $storage_key ) {
			$storage_key = self::default_for( 'storageKey' );
		}

		$form_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $values['formId'] ?? '' ) );

		self::enqueue();

		return Renderer::render(
			$def,
			array(
				'storageKey' => (string) $storage_key,
				'formId'     => (string) $form_id,
			)
		);
	}

	/**
	 * Enfile les ressources du formulaire.
	 *
	 * Idempotent : plusieurs formulaires, ou un bloc et un shortcode sur la
	 * même page, ne produisent qu'un seul chargement.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		wp_enqueue_style( 'urbizen-form' );
		wp_enqueue_script( 'urbizen-form' );
	}
}
