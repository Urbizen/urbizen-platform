<?php
/**
 * Plugin Name:       Urbizen Platform
 * Plugin URI:        https://urbizen.fr
 * Description:       Logique métier Urbizen : formulaires d'urbanisme, composant cadastre, dossiers, transmission au service de génération documentaire. Indépendant du thème.
 * Version:           0.6.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Urbizen
 * Author URI:        https://urbizen.fr
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       urbizen-platform
 * Domain Path:       /languages
 *
 * @package Urbizen\Platform
 */

defined( 'ABSPATH' ) || exit;

const URBIZEN_PLATFORM_VERSION     = '0.6.0';
const URBIZEN_PLATFORM_MIN_PHP     = '8.1';
const URBIZEN_PLATFORM_MIN_WP      = '6.5';
const URBIZEN_PLATFORM_TEXT_DOMAIN = 'urbizen-platform';

define( 'URBIZEN_PLATFORM_FILE', __FILE__ );
define( 'URBIZEN_PLATFORM_DIR', plugin_dir_path( __FILE__ ) );
define( 'URBIZEN_PLATFORM_URL', plugin_dir_url( __FILE__ ) );

require_once URBIZEN_PLATFORM_DIR . 'src/Autoloader.php';

\Urbizen\Platform\Autoloader::register();

register_activation_hook( __FILE__, array( \Urbizen\Platform\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Urbizen\Platform\Deactivator::class, 'deactivate' ) );

/**
 * Démarre l'extension une fois WordPress chargé.
 *
 * @return void
 */
function urbizen_platform_boot() {
	\Urbizen\Platform\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'urbizen_platform_boot' );
