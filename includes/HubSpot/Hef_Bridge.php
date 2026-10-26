<?php
/**
 * Puente con el módulo «HubSpot Elementor Form».
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

defined( 'ABSPATH' ) || exit;

/**
 * Arranca «HubSpot Elementor Form» desde dentro de este módulo.
 *
 * Su código viaja en vendor-hef/ sin modificaciones: para actualizarlo basta
 * con sustituir la carpeta. Todo lo que hace falta para integrarlo vive aquí.
 *
 * Si además está instalado como plugin suelto, este puente se aparta y deja que
 * mande el plugin: dos copias del mismo código compitiendo sería peor que
 * cualquiera de las dos por separado.
 */
final class Hef_Bridge {

	/**
	 * Versión del módulo incorporado.
	 *
	 * @var string
	 */
	public const VERSION = '1.9.1';

	/**
	 * Arranca el puente.
	 *
	 * @return void
	 */
	public static function arrancar(): void {
		if ( self::suelto_activo() ) {
			add_action( 'admin_notices', array( self::class, 'aviso_duplicado' ) );
			return;
		}

		if ( defined( 'HEF_VERSION' ) ) {
			return;
		}

		$ruta = SWPAGOS_DIR . 'vendor-hef/';

		if ( ! is_readable( $ruta . 'includes/class-plugin.php' ) ) {
			return;
		}

		define( 'HEF_VERSION', self::VERSION );
		define( 'HEF_FILE', SWPAGOS_FILE );
		define( 'HEF_PATH', $ruta );
		define( 'HEF_URL', SWPAGOS_URL . 'vendor-hef/' );
		define( 'HEF_BASENAME', plugin_basename( SWPAGOS_FILE ) );

		self::autoload( $ruta );

		\HEF\Plugin::instance();

		// Su menú cuelga del nuestro, para tener HubSpot y los cobros en un solo
		// lugar. Prioridad alta para llegar después de que se haya registrado.
		add_action( 'admin_menu', array( self::class, 'recolocar_menu' ), 100 );

		// Guarda el identificador del registro que acaba de crear, para poder
		// reenviarlo cuando el cobro se confirme.
		add_action( 'hef/after_submit', array( self::class, 'anotar_registro' ), 10, 3 );
	}

	/**
	 * Identificador del registro de HubSpot creado en esta petición.
	 *
	 * @var int
	 */
	private static int $registro = 0;

	/**
	 * Anota el registro del envío en curso.
	 *
	 * @param array $resultado Resultado del envío.
	 * @param array $payload   Payload.
	 * @param int   $log_id    Identificador del registro.
	 * @return void
	 */
	public static function anotar_registro( $resultado, $payload, $log_id ): void {
		unset( $resultado, $payload );

		self::$registro = (int) $log_id;
	}

	/**
	 * Devuelve y limpia el registro anotado en esta petición.
	 *
	 * @return int Cero si el formulario no envía a HubSpot.
	 */
	public static function registro_del_envio(): int {
		$id             = self::$registro;
		self::$registro = 0;

		return $id;
	}

	/**
	 * Manda a HubSpot un envío que quedó guardado sin enviar.
	 *
	 * Es la pieza que une las dos mitades: un formulario con cobro lleva el campo
	 * de HubSpot pero NO la acción, así que su módulo registra el envío completo
	 * y no lo manda. Cuando Stripe confirma el cobro, se manda desde aquí.
	 *
	 * @param int $log_id Registro.
	 * @return bool
	 */
	public static function reenviar( int $log_id ): bool {
		if ( $log_id <= 0 || ! class_exists( '\HEF\Retry' ) ) {
			return false;
		}

		$resultado = \HEF\Retry::resend( $log_id, true );

		return is_array( $resultado ) ? ! empty( $resultado['success'] ) : (bool) $resultado;
	}

	/**
	 * Mueve las pantallas de HubSpot bajo el menú del módulo.
	 *
	 * @return void
	 */
	public static function recolocar_menu(): void {
		if ( ! class_exists( '\HEF\Admin\Admin' ) ) {
			return;
		}

		remove_menu_page( \HEF\Admin\Admin::PAGE_LOGS );

		$padre = \SWPagos\Admin\Menu::SLUG_AJUSTES;

		// El callback de HEF va SIN barra inicial: WordPress identifica los
		// callbacks estáticos por la cadena «Clase::metodo», y «\HEF\…» frente a
		// «HEF\…» le parecen dos distintos, así que pintaría la pantalla dos veces.
		$paginas = array(
			array( \HEF\Admin\Admin::PAGE_LOGS, __( 'Envíos a HubSpot', 'sw-pagos' ), array( 'HEF\Admin\Admin', 'render_logs' ) ),
			array( \HEF\Admin\Admin::PAGE_SCAN, __( 'Escáner de HubSpot', 'sw-pagos' ), array( 'HEF\Admin\Admin', 'render_scanner' ) ),
			// Los ajustes llevan debajo la sección de negocios del CRM, que es la
			// mitad de HubSpot que aporta este módulo.
			array( \HEF\Admin\Admin::PAGE_SET, __( 'Ajustes de HubSpot', 'sw-pagos' ), array( self::class, 'pantalla_ajustes' ) ),
		);

		foreach ( $paginas as list( $slug, $titulo, $callback ) ) {
			$hook = add_submenu_page(
				$padre,
				$titulo,
				$titulo,
				'manage_options',
				$slug,
				$callback
			);

			// Las «Opciones de pantalla» del listado estaban enganchadas al hook
			// de su menú propio, que ya no existe: hay que rehacerlas en el nuevo.
			if ( \HEF\Admin\Admin::PAGE_LOGS === $slug && is_string( $hook ) ) {
				add_action( 'load-' . $hook, array( 'HEF\Admin\Admin', 'screen_options' ) );
			}
		}
	}

	/**
	 * Pantalla de ajustes de HubSpot: la suya y, debajo, la de negocios.
	 *
	 * @return void
	 */
	public static function pantalla_ajustes(): void {
		\HEF\Admin\Admin::render_settings();
		Crm_Panel::renderizar();
	}

	/**
	 * Crea las tablas del módulo incorporado al activar este plugin.
	 *
	 * @return void
	 */
	public static function activar(): void {
		if ( self::suelto_activo() || ! class_exists( '\\HEF\\Logger' ) ) {
			return;
		}

		\HEF\Logger::create_table();
	}

	/**
	 * Registra el autoloader de HEF\, idéntico al de su plugin.
	 *
	 * @param string $ruta Carpeta del módulo incorporado.
	 * @return void
	 */
	private static function autoload( string $ruta ): void {
		spl_autoload_register(
			static function ( $clase ) use ( $ruta ): void {
				if ( 0 !== strpos( $clase, 'HEF\\' ) ) {
					return;
				}

				$partes   = explode( '\\', substr( $clase, strlen( 'HEF\\' ) ) );
				$nombre   = array_pop( $partes );
				$carpetas = array_map( 'strtolower', $partes );

				$archivo = $ruta . 'includes/';

				if ( array() !== $carpetas ) {
					$archivo .= implode( '/', $carpetas ) . '/';
				}

				$archivo .= 'class-' . str_replace( '_', '-', strtolower( $nombre ) ) . '.php';

				if ( is_readable( $archivo ) ) {
					require_once $archivo;
				}
			}
		);
	}

	/**
	 * Indica si el plugin suelto de HubSpot está activo.
	 *
	 * @return bool
	 */
	public static function suelto_activo(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'hubspot_elementor_form/hubspot_elementor_form.php' );
	}

	/**
	 * Avisa de que hay dos copias y cuál manda.
	 *
	 * @return void
	 */
	public static function aviso_duplicado(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Pagos Stripe + HubSpot incluye «HubSpot Elementor Form», y además lo tienes instalado como plugin aparte. Manda el plugin suelto; la copia incorporada queda apagada para que no compitan. Puedes desactivar el plugin suelto sin perder nada.', 'sw-pagos' );
		echo '</p></div>';
	}
}
