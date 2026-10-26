<?php
/**
 * Plugin Name:       Pagos Stripe + HubSpot para Elementor
 * Plugin URI:        https://github.com/Fer1605/plugin-pagos-stripe-hubspot
 * Description:       Cobros con Stripe —pago único y suscripción mensual— desde formularios de Elementor, con registro en HubSpot, plantillas de correo, páginas de resultado, bitácoras y reportería. Convive con otros módulos de pago.
 * Version:           0.6.7
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Fer Santizo
 * Author URI:        https://github.com/Fer1605
 * License:           GPL-2.0-or-later
 * Text Domain:       sw-pagos
 * Domain Path:       /languages
 * Requires Plugins:  elementor
 *
 * @package SWPagos
 */

defined( 'ABSPATH' ) || exit;

/*
 * Prefijo del módulo: SWPAGOS_ / swpagos_ / SWPagos.
 *
 * Se consideró `SWP_`, pero ese prefijo lo usa un plugin de amplia
 * difusión (Social Warfare: SWP_VERSION, SWP_PLUGIN_DIR…). Definir una constante
 * con un nombre ya tomado no da error visible: deja al módulo leyendo el valor
 * del otro plugin, que es peor que un choque evidente. Por eso se usa un prefijo
 * distintivo.
 */

if ( defined( 'SWPAGOS_VERSION' ) ) {
	// Otra copia del módulo ya está cargada. Nos retiramos sin romper nada.
	return;
}

define( 'SWPAGOS_VERSION', '0.6.7' );
define( 'SWPAGOS_DB_VERSION', 1 );
define( 'SWPAGOS_FILE', __FILE__ );
define( 'SWPAGOS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWPAGOS_URL', plugin_dir_url( __FILE__ ) );
define( 'SWPAGOS_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Cargador de clases del módulo.
 *
 * Traduce SWPagos\Sub\Nombre_Clase a includes/Sub/Nombre_Clase.php.
 *
 * Se registra como cierre y no como función con nombre porque la regla de
 * convivencia es no declarar nada en el ámbito global, y el cargador es lo único que no
 * puede vivir dentro del espacio de nombres: tiene que existir antes de que haya
 * una sola clase cargada. Las constantes SWPAGOS_ son la única excepción, y van
 * prefijadas.
 *
 * No se usa el autoload de Composer para las clases propias: el módulo debe
 * poder arrancar en un sitio donde vendor/ aún no esté instalado, para que el
 * fallo sea un aviso claro del SDK ausente y no una pantalla en blanco.
 */
spl_autoload_register(
	static function ( $clase ) {
		if ( ! str_starts_with( $clase, 'SWPagos\\' ) ) {
			return;
		}

		$relativa = substr( $clase, strlen( 'SWPagos\\' ) );

		// Defensa frente a nombres de clase manipulados que intenten salir del directorio.
		if ( str_contains( $relativa, '..' ) ) {
			return;
		}

		$ruta = SWPAGOS_DIR . 'includes/' . str_replace( '\\', '/', $relativa ) . '.php';

		if ( is_readable( $ruta ) ) {
			require_once $ruta;
		}
	}
);

// Dependencias de terceros, si están instaladas.
if ( is_readable( SWPAGOS_DIR . 'vendor/autoload.php' ) ) {
	require_once SWPAGOS_DIR . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, array( 'SWPagos\\Core\\Activator', 'activar' ) );
register_deactivation_hook( __FILE__, array( 'SWPagos\\Core\\Activator', 'desactivar' ) );

\SWPagos\Core\Plugin::instancia()->arrancar();
