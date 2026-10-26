<?php
/**
 * Plugin Name: HubSpot Elementor Form
 * Plugin URI:  https://www.soydiaz.com
 * Description: Envía las respuestas de Elementor Forms a HubSpot (Forms Submissions API v3) solo cuando el formulario pasa todas las validaciones. Incluye mapeo de campos por keyid, configuración única de Portal ID / Form ID, registro (log) de cada envío y reintentos.
 * Version:     1.9.1
 * Author:      Jonathan Diaz
 * Author URI:  https://www.soydiaz.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hubspot-elementor-form
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 *
 * @package HubSpot_Elementor_Form
 */

defined( 'ABSPATH' ) || exit;

define( 'HEF_VERSION', '1.9.1' );
define( 'HEF_FILE', __FILE__ );
define( 'HEF_PATH', plugin_dir_path( __FILE__ ) );
define( 'HEF_URL', plugin_dir_url( __FILE__ ) );
define( 'HEF_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader PSR-4 "a la WordPress":
 * HEF\Logger              -> includes/class-logger.php
 * HEF\Fields\Config_Field -> includes/fields/class-config-field.php
 * HEF\Admin\Logs_Table    -> includes/admin/class-logs-table.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'HEF\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'HEF\\' ) );
		$parts    = explode( '\\', $relative );
		$class_nm = array_pop( $parts );
		$dirs     = array_map( 'strtolower', $parts );

		$file = HEF_PATH . 'includes/';
		if ( $dirs ) {
			$file .= implode( '/', $dirs ) . '/';
		}
		$file .= 'class-' . str_replace( '_', '-', strtolower( $class_nm ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'HEF\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HEF\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'HEF\\Plugin', 'instance' ), 20 );
