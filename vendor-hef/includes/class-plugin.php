<?php
/**
 * Bootstrap del plugin.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Requisito mínimo de Elementor Pro.
	 */
	const MIN_ELEMENTOR_PRO = '3.5.0';

	/**
	 * Tipo del campo personalizado.
	 */
	const FIELD_TYPE = 'hubspot_config';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'hubspot-elementor-form', false, dirname( HEF_BASENAME ) . '/languages' );

		Logger::maybe_upgrade_table();

		// Cron de reintentos y limpieza: no depende de Elementor.
		Retry::init();

		if ( is_admin() ) {
			Admin\Admin::init();
			add_filter( 'plugin_action_links_' . HEF_BASENAME, array( $this, 'action_links' ) );
		}

		if ( ! $this->has_elementor_pro() ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_elementor_pro' ) );
			return;
		}

		// Registro del campo personalizado en Elementor Forms.
		add_action( 'elementor_pro/forms/fields/register', array( $this, 'register_field' ) );

		// Acción de Elementor: hace que el envío salga en el Actions Log.
		add_action( 'elementor_pro/forms/actions/register', array( $this, 'register_action' ) );

		// Control personalizado del grupo de mapeos.
		add_action( 'elementor/controls/register', array( $this, 'register_control' ) );

		// Escáner de formularios de HubSpot (AJAX + editor).
		Ajax::init();
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'editor_assets' ) );

		// Procesamiento del envío (solo se dispara si la validación fue correcta).
		Form_Handler::init();

		// Oculta el campo de configuración en el frontend.
		add_action( 'wp_head', array( $this, 'print_frontend_css' ), 99 );
	}

	/**
	 * Elementor Pro activo y con versión suficiente.
	 *
	 * @return bool
	 */
	public function has_elementor_pro() {
		if ( ! did_action( 'elementor/loaded' ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return false;
		}
		return version_compare( ELEMENTOR_PRO_VERSION, self::MIN_ELEMENTOR_PRO, '>=' );
	}

	/**
	 * Registra el campo "HubSpot" dentro del widget Formulario.
	 *
	 * @param \ElementorPro\Modules\Forms\Registrars\Form_Fields_Registrar $registrar Registrador.
	 */
	public function register_field( $registrar ) {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Fields\Field_Base' ) ) {
			return;
		}
		require_once HEF_PATH . 'includes/fields/class-config-field.php';
		$registrar->register( new Fields\Config_Field() );
	}

	/**
	 * Registra la acción "HubSpot" del widget Formulario.
	 *
	 * @param \ElementorPro\Modules\Forms\Registrars\Form_Actions_Registrar $registrar Registrador.
	 */
	public function register_action( $registrar ) {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
			return;
		}

		require_once HEF_PATH . 'includes/actions/class-hubspot-action.php';

		if ( class_exists( '\HEF\Actions\HubSpot_Action' ) ) {
			$registrar->register( new Actions\HubSpot_Action() );
		}
	}

	/**
	 * Registra el control del grupo de mapeos.
	 *
	 * @param \Elementor\Controls_Manager $controls_manager Gestor de controles.
	 */
	public function register_control( $controls_manager ) {
		if ( ! class_exists( '\Elementor\Base_Data_Control' ) ) {
			return;
		}

		require_once HEF_PATH . 'includes/controls/class-mapping-control.php';

		if ( ! class_exists( '\HEF\Controls\Mapping_Control' ) ) {
			return;
		}

		$control = new Controls\Mapping_Control();

		if ( method_exists( $controls_manager, 'register' ) ) {
			$controls_manager->register( $control );
		} else {
			$controls_manager->register_control( $control->get_type(), $control );
		}
	}

	/**
	 * Carga el escáner dentro del editor de Elementor.
	 */
	public function editor_assets() {
		wp_enqueue_style( 'hef-editor', HEF_URL . 'assets/css/editor.css', array(), HEF_VERSION );

		wp_enqueue_script( 'hef-editor', HEF_URL . 'assets/js/editor.js', array( 'jquery', 'jquery-ui-sortable', 'elementor-editor' ), HEF_VERSION, true );

		wp_localize_script(
			'hef-editor',
			'HEFEditor',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					'noContainer'  => __( 'No he podido leer el formulario. Guarda la página y recarga el editor.', 'hubspot-elementor-form' ),
					'noUrl'        => __( 'Pega antes la URL del formulario de HubSpot.', 'hubspot-elementor-form' ),
					'loading'      => __( 'Consultando HubSpot…', 'hubspot-elementor-form' ),
					'genericError' => __( 'No se pudo escanear el formulario.', 'hubspot-elementor-form' ),
					'ajaxError'    => __( 'Error de conexión con el servidor.', 'hubspot-elementor-form' ),
					'noConfig'     => __( 'No se cargaron los datos del plugin. Recarga el editor.', 'hubspot-elementor-form' ),
					'formOk'       => __( 'Formulario encontrado', 'hubspot-elementor-form' ),
					'properties'   => __( 'propiedades', 'hubspot-elementor-form' ),
					'mapped'       => __( 'Campos mapeados', 'hubspot-elementor-form' ),
					'noneMapped'   => __( 'No se ha podido emparejar ningún campo automáticamente. Asígnalos a mano.', 'hubspot-elementor-form' ),
					'kept'         => __( 'Mapeos que ya tenías y se han respetado', 'hubspot-elementor-form' ),
					'unmatched'    => __( 'Propiedades de HubSpot sin campo en Elementor', 'hubspot-elementor-form' ),
					'required'     => __( 'obligatoria', 'hubspot-elementor-form' ),
					'review'       => __( 'Revisa el grupo "Mapeo de campos": puedes cambiar la propiedad, quitar líneas o añadir más.', 'hubspot-elementor-form' ),
					'toast'        => __( 'campos mapeados con HubSpot', 'hubspot-elementor-form' ),
					'toastCreated' => __( 'creados', 'hubspot-elementor-form' ),
					'chooseField'  => __( '— elige un campo —', 'hubspot-elementor-form' ),
					'missingField' => __( 'campo eliminado', 'hubspot-elementor-form' ),
					'propertyPlaceholder' => __( 'propiedad (keyid)', 'hubspot-elementor-form' ),
					'object'       => __( 'Objeto de HubSpot', 'hubspot-elementor-form' ),
					'remove'       => __( 'Quitar', 'hubspot-elementor-form' ),
					'drag'         => __( 'Arrastra para reordenar', 'hubspot-elementor-form' ),
					'propNotInForm' => __( 'No está en el formulario de HubSpot: rechazaría el envío.', 'hubspot-elementor-form' ),
					'groupTotal'   => __( 'Líneas en el grupo de mapeo', 'hubspot-elementor-form' ),
					'review2'      => __( 'Revisa: puede que ya tengas el campo', 'hubspot-elementor-form' ),
					'reviewNote'   => __( 'No se han creado para no duplicar. Asigna la propiedad en el grupo de mapeo si corresponde.', 'hubspot-elementor-form' ),
					'maybe'        => __( '¿es tu campo', 'hubspot-elementor-form' ),
					'autoAssigned' => __( 'Asignados a un campo que ya tenías (obligatorios en HubSpot)', 'hubspot-elementor-form' ),
					'autoAssignedNote' => __( 'Se han asignado para no dejarlos sin enviar. Compruébalos.', 'hubspot-elementor-form' ),
					'reportTitle'  => __( 'HubSpot · resultado del escaneo', 'hubspot-elementor-form' ),
					'close'        => __( 'Cerrar', 'hubspot-elementor-form' ),
					'created'      => __( 'Campos creados en el formulario', 'hubspot-elementor-form' ),
					'requiredSynced' => __( 'Marcados como obligatorios (lo son en HubSpot)', 'hubspot-elementor-form' ),
					'obsolete'     => __( 'Propiedades que ya NO existen en HubSpot', 'hubspot-elementor-form' ),
					'obsoleteWarn' => __( 'HubSpot rechazará el envío completo mientras estén mapeadas. Borra la propiedad de estos campos o vuelve a crearla en HubSpot:', 'hubspot-elementor-form' ),
				),
			)
		);
	}

	/**
	 * El campo de configuración no debe verse en el sitio público.
	 */
	public function print_frontend_css() {
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return; // En el editor sí se muestra el aviso informativo.
		}
		echo '<style id="hef-hide-config-field">.elementor-field-type-' . esc_attr( self::FIELD_TYPE ) . '{display:none !important;}</style>' . "\n";
	}

	public function action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=hef-logs' ) ) . '">' . esc_html__( 'Registros', 'hubspot-elementor-form' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=hef-settings' ) ) . '">' . esc_html__( 'Ajustes', 'hubspot-elementor-form' ) . '</a>',
		);
		return array_merge( $custom, $links );
	}

	public function notice_missing_elementor_pro() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>HubSpot Elementor Form:</strong> ';
		printf(
			/* translators: %s: versión mínima de Elementor Pro */
			esc_html__( 'requiere Elementor Pro %s o superior para registrar el campo en los formularios. El registro de envíos y los reintentos siguen disponibles.', 'hubspot-elementor-form' ),
			esc_html( self::MIN_ELEMENTOR_PRO )
		);
		echo '</p></div>';
	}

	/**
	 * Activación: tabla de logs, ajustes por defecto y cron.
	 */
	public static function activate() {
		Logger::create_table();
		Settings::install_defaults();
		Retry::schedule_events();
	}

	/**
	 * Desactivación: limpia los eventos programados.
	 */
	public static function deactivate() {
		Retry::unschedule_events();
	}
}
