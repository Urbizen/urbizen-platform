<?php
/**
 * Chargement des ressources du formulaire de conception.
 *
 * Rien n'est mis en file tant que le formulaire n'est pas **réellement**
 * rendu : une page qui ne l'affiche pas ne charge ni feuille de style, ni
 * script, et n'expose donc aucun schéma. C'est la contrepartie naturelle d'un
 * garde d'accès posé côté serveur.
 *
 * @package Urbizen\Platform\Conception
 */

namespace Urbizen\Platform\Conception;

use Urbizen\Platform\Forms\FormDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Feuille de style et script du parcours.
 */
final class ConceptionAssets {

	public const HANDLE_CSS = 'urbizen-conception';
	public const HANDLE_JS  = 'urbizen-conception';

	/**
	 * Enregistre les ressources, sans les charger.
	 *
	 * @return void
	 */
	public static function register(): void {
		$base = defined( 'URBIZEN_PLATFORM_URL' ) ? URBIZEN_PLATFORM_URL : plugin_dir_url( dirname( __DIR__ ) . '/urbizen-platform.php' );
		$ver  = defined( 'URBIZEN_PLATFORM_VERSION' ) ? URBIZEN_PLATFORM_VERSION : null;

		wp_register_style( self::HANDLE_CSS, $base . 'assets/css/urbizen-conception.css', array(), $ver );
		wp_register_script( self::HANDLE_JS, $base . 'assets/js/urbizen-conception.js', array(), $ver, true );
	}

	/**
	 * Met en file les ressources et transmet le schéma réduit.
	 *
	 * @param FormDefinition $def      Définition serveur.
	 * @param string         $instance Identifiant d'instance.
	 * @return void
	 */
	public static function enqueue( FormDefinition $def, string $instance ): void {
		// Double barrière : même appelée ailleurs, cette méthode ne charge rien
		// pour qui n'a pas le droit de voir le formulaire.
		if ( ! ConceptionAvailability::can_render() ) {
			return;
		}

		if ( ! wp_style_is( self::HANDLE_CSS, 'registered' ) ) {
			self::register();
		}

		wp_enqueue_style( self::HANDLE_CSS );
		wp_enqueue_script( self::HANDLE_JS );

		wp_add_inline_script(
			self::HANDLE_JS,
			sprintf(
				'window.urbizenConception = window.urbizenConception || {}; window.urbizenConception[%s] = %s;',
				wp_json_encode( $instance ),
				wp_json_encode( ConceptionSchema::build( $def ) )
			),
			'before'
		);
	}
}
