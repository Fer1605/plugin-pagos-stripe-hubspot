<?php
/**
 * Arranque del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Contenedor y punto de arranque. Todo el módulo cuelga de aquí; no hay una sola
 * función ni clase en el ámbito global.
 */
final class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instancia = null;

	/**
	 * Evita arrancar dos veces si algo llama a arrancar() de nuevo.
	 *
	 * @var bool
	 */
	private bool $arrancado = false;

	/**
	 * Registro del módulo.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Ajustes del módulo.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Constructor privado: la instancia se obtiene por instancia().
	 */
	private function __construct() {}

	/**
	 * Devuelve la instancia única.
	 *
	 * @return Plugin
	 */
	public static function instancia(): Plugin {
		if ( null === self::$instancia ) {
			self::$instancia = new self();
		}
		return self::$instancia;
	}

	/**
	 * Registro del módulo.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		if ( null === $this->logger ) {
			$this->logger = new Logger();
		}
		return $this->logger;
	}

	/**
	 * Ajustes del módulo.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
		return $this->settings;
	}

	/**
	 * Engancha el módulo a WordPress.
	 *
	 * @return void
	 */
	public function arrancar(): void {
		if ( $this->arrancado ) {
			return;
		}
		$this->arrancado = true;

		add_action( 'init', array( $this, 'cargar_traducciones' ), 0 );
		add_action( 'plugins_loaded', array( $this, 'comprobar_esquema' ) );
		add_action( 'plugins_loaded', array( Compat::class, 'arrancar' ) );

		// Las plantillas se registran siempre, no solo en administración: las
		// páginas de resultado y el cobro las consultan desde el frontal.
		( new \SWPagos\Templates\Cpt_Plantilla_Pago() )->registrar();
		// El motor de HubSpot es el módulo «HubSpot Elementor Form», incorporado tal cual.
		// Va antes que nuestro cargador de Elementor porque registra su propio
		// campo y su propia acción en el widget de formulario.
		\SWPagos\HubSpot\Hef_Bridge::arrancar();

		( new \SWPagos\Elementor\Loader() )->registrar();
		( new \SWPagos\Stripe\Loader() )->registrar();
		( new \SWPagos\Delivery\Post_Payment() )->registrar();
		( new \SWPagos\Frontend\Loader() )->registrar();
		( new \SWPagos\Admin\Reports\Scheduler() )->registrar();
		( new Cleanup() )->registrar();

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( $this, 'arrancar_admin' ) );
		}
	}

	/**
	 * Carga el dominio de traducción.
	 *
	 * @return void
	 */
	public function cargar_traducciones(): void {
		load_plugin_textdomain( 'sw-pagos', false, dirname( SWPAGOS_BASENAME ) . '/languages' );
	}

	/**
	 * Aplica migraciones de esquema cuando el módulo se actualiza sin pasar por
	 * el gancho de activación, que es lo que ocurre con las actualizaciones
	 * automáticas desde el repositorio.
	 *
	 * @return void
	 */
	public function comprobar_esquema(): void {
		$instalada = (int) get_option( 'swpagos_db_version', 0 );

		if ( $instalada === SWPAGOS_DB_VERSION ) {
			return;
		}

		Activator::crear_tablas();
		update_option( 'swpagos_db_version', SWPAGOS_DB_VERSION, false );

		$this->logger()->info(
			'Esquema de base de datos actualizado.',
			array(
				'desde' => $instalada,
				'hasta' => SWPAGOS_DB_VERSION,
			)
		);
	}

	/**
	 * Arranca la capa de administración.
	 *
	 * @return void
	 */
	public function arrancar_admin(): void {
		( new \SWPagos\Admin\Menu() )->registrar();
	}
}
