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
	 * Ressources de charte fournies par le thème, dans l'ordre de dépendance.
	 *
	 * Les maquettes de référence sont composées en Space Grotesk et IBM Plex.
	 * Ces polices sont auto-hébergées par le thème enfant, mais celui-ci ne les
	 * met en file que sous le gabarit de l'accueil : une page portant le
	 * formulaire de conception ne les recevrait pas, et le parcours retomberait
	 * sur la police du système — l'écart visuel constaté par la propriétaire.
	 *
	 * @var array<string, string>
	 */
	private const CHARTE = array(
		'urbizen-fonts'  => '/assets/css/urbizen-fonts.css',
		'urbizen-tokens' => '/assets/css/urbizen-tokens.css',
	);

	/**
	 * Enregistre les ressources, sans les charger.
	 *
	 * @return void
	 */
	public static function register(): void {
		$base = defined( 'URBIZEN_PLATFORM_URL' ) ? URBIZEN_PLATFORM_URL : plugin_dir_url( dirname( __DIR__ ) . '/urbizen-platform.php' );
		$ver  = defined( 'URBIZEN_PLATFORM_VERSION' ) ? URBIZEN_PLATFORM_VERSION : null;

		wp_register_style( self::HANDLE_CSS, $base . 'assets/css/urbizen-conception.css', self::charte(), $ver );
		wp_register_script( self::HANDLE_JS, $base . 'assets/js/urbizen-conception.js', array(), $ver, true );
	}

	/**
	 * Enregistre la charte du thème si elle existe, et rend la liste des
	 * dépendances utilisables.
	 *
	 * Le greffon n'embarque **aucune** copie de la charte : il se contente de
	 * déclarer celle du thème, qui reste seule source de vérité (D-002). Hors
	 * WordPress, ou sous un thème qui ne la fournit pas, la liste est vide et la
	 * feuille reste correcte : chaque `var(--u-*)` porte sa valeur de repli.
	 *
	 * Un handle déjà enregistré n'est jamais réenregistré : si le thème l'a
	 * posé lui-même, c'est sa version qui sert.
	 *
	 * @return array<int, string>
	 */
	private static function charte(): array {
		if ( ! function_exists( 'get_stylesheet_directory' ) ) {
			return array();
		}

		$dir  = get_stylesheet_directory();
		$uri  = get_stylesheet_directory_uri();
		$deps = array();

		foreach ( self::CHARTE as $handle => $chemin ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				$deps[] = $handle;
				continue;
			}

			if ( ! file_exists( $dir . $chemin ) ) {
				continue;
			}

			wp_register_style( $handle, $uri . $chemin, $deps, (string) filemtime( $dir . $chemin ) );
			$deps[] = $handle;
		}

		return $deps;
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
