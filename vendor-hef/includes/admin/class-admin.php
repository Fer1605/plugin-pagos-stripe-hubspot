<?php
/**
 * Pantallas de administración.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF\Admin;

use HEF\Logger;
use HEF\Retry;
use HEF\Settings;

defined( 'ABSPATH' ) || exit;

class Admin {

	const CAP       = 'manage_options';
	const PAGE_LOGS = 'hef-logs';
	const PAGE_SET  = 'hef-settings';
	const PAGE_SCAN = 'hef-scanner';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_hef_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_hef_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_hef_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_hef_test_token', array( __CLASS__, 'handle_test_token' ) );
		add_action( 'admin_post_hef_verify', array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	public static function menu() {
		$hook = add_menu_page(
			__( 'HubSpot Elementor Form', 'hubspot-elementor-form' ),
			__( 'HubSpot Forms', 'hubspot-elementor-form' ),
			self::CAP,
			self::PAGE_LOGS,
			array( __CLASS__, 'render_logs' ),
			'dashicons-email-alt',
			58
		);

		// Opciones de pantalla del listado (cuántos registros por página).
		add_action( 'load-' . $hook, array( __CLASS__, 'screen_options' ) );

		add_submenu_page(
			self::PAGE_LOGS,
			__( 'Registros de envío', 'hubspot-elementor-form' ),
			__( 'Registros', 'hubspot-elementor-form' ),
			self::CAP,
			self::PAGE_LOGS,
			array( __CLASS__, 'render_logs' )
		);

		add_submenu_page(
			self::PAGE_LOGS,
			__( 'Escáner de formularios de HubSpot', 'hubspot-elementor-form' ),
			__( 'Escáner', 'hubspot-elementor-form' ),
			self::CAP,
			self::PAGE_SCAN,
			array( __CLASS__, 'render_scanner' )
		);

		add_submenu_page(
			self::PAGE_LOGS,
			__( 'Ajustes de HubSpot Elementor Form', 'hubspot-elementor-form' ),
			__( 'Ajustes', 'hubspot-elementor-form' ),
			self::CAP,
			self::PAGE_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * @param string $hook Hook de la pantalla.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_LOGS ) && false === strpos( $hook, self::PAGE_SET ) && false === strpos( $hook, self::PAGE_SCAN ) ) {
			return;
		}
		wp_enqueue_style( 'hef-admin', HEF_URL . 'assets/css/admin.css', array(), HEF_VERSION );
	}

	public static function register_settings() {
		register_setting(
			'hef_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( '\HEF\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Acciones
	 * ------------------------------------------------------------------ */

	public static function handle_retry() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::check( 'hef_retry_' . $id );

		$result = Retry::resend( $id, false );

		self::redirect_back(
			array(
				'hef_msg' => $result['success'] ? 'retry_ok' : 'retry_error',
			)
		);
	}

	/**
	 * Pregunta a HubSpot si el envío consta de verdad.
	 */
	public static function handle_verify() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::check( 'hef_verify_' . $id );

		$result = \HEF\Verifier::verify( $id );

		set_transient( 'hef_verify_' . get_current_user_id(), $result, 120 );

		self::redirect_back( array( 'hef_msg' => $result['found'] ? 'verify_ok' : 'verify_ko' ) );
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::check( 'hef_delete_' . $id );

		Logger::delete( array( $id ) );

		self::redirect_back( array( 'hef_msg' => 'deleted' ) );
	}

	public static function handle_bulk() {
		self::check( 'hef_bulk' );

		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = array_filter( $ids );

		// La caja "ir a la página" del paginador vive dentro de este mismo
		// formulario: si se envía sin elegir acción, es que se está paginando.
		if ( ! $action ) {
			self::redirect_back( array() );
		}

		if ( ! $ids ) {
			self::redirect_back( array( 'hef_msg' => 'nothing' ) );
		}

		if ( 'delete' === $action ) {
			Logger::delete( $ids );
			self::redirect_back( array( 'hef_msg' => 'deleted' ) );
		}

		if ( 'retry' === $action ) {
			$ok = 0;
			foreach ( $ids as $id ) {
				$result = Retry::resend( $id, false );
				$ok    += $result['success'] ? 1 : 0;
			}
			self::redirect_back(
				array(
					'hef_msg' => 'bulk_retry',
					'hef_ok'  => $ok,
					'hef_tot' => count( $ids ),
				)
			);
		}

		self::redirect_back( array( 'hef_msg' => 'nothing' ) );
	}

	/**
	 * Comprueba permisos y nonce.
	 *
	 * @param string $action Acción del nonce.
	 */
	protected static function check( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'hubspot-elementor-form' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Vuelve al listado conservando filtros.
	 *
	 * @param array $args Parámetros extra.
	 */
	protected static function redirect_back( array $args ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$keep = array();
		foreach ( array( 'status', 's', 'paged', 'orderby', 'order', 'hef_form', 'hef_page', 'hef_from', 'hef_to', 'hef_id' ) as $key ) {
			$source = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $source ) {
				$keep[ $key ] = $source;
			}
		}
		// phpcs:enable

		$url = add_query_arg(
			array_merge( array( 'page' => self::PAGE_LOGS ), $keep, $args ),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Vistas
	 * ------------------------------------------------------------------ */

	/**
	 * Registros por página, configurable desde "Opciones de pantalla".
	 */
	public static function screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Registros por página', 'hubspot-elementor-form' ),
				'default' => 20,
				'option'  => 'hef_logs_per_page',
			)
		);
	}

	/**
	 * @param mixed  $status Valor por defecto.
	 * @param string $option Opción.
	 * @param mixed  $value  Valor elegido.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		if ( 'hef_logs_per_page' === $option ) {
			return max( 5, min( 200, (int) $value ) );
		}

		return $status;
	}

	public static function render_logs() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		require_once HEF_PATH . 'includes/admin/class-logs-table.php';
		require HEF_PATH . 'includes/admin/views/logs.php';
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		require HEF_PATH . 'includes/admin/views/settings.php';
	}

	public static function render_scanner() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		require HEF_PATH . 'includes/admin/views/scanner.php';
	}

	/**
	 * Comprueba el token de app privada contra la API de HubSpot.
	 */
	public static function handle_test_token() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos para hacer esto.', 'hubspot-elementor-form' ), 403 );
		}

		check_admin_referer( 'hef_test_token' );

		$result = \HEF\Api::test_token( \HEF\Settings::access_token() );

		set_transient( 'hef_token_test_' . get_current_user_id(), $result, 120 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SET ) );
		exit;
	}

	/**
	 * Avisos tras una acción.
	 */
	public static function print_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['hef_msg'] ) ? sanitize_key( wp_unslash( $_GET['hef_msg'] ) ) : '';
		$ok  = isset( $_GET['hef_ok'] ) ? absint( $_GET['hef_ok'] ) : 0;
		$tot = isset( $_GET['hef_tot'] ) ? absint( $_GET['hef_tot'] ) : 0;
		// phpcs:enable

		if ( ! $msg ) {
			return;
		}

		$class = 'notice-success';
		$text  = '';

		if ( 'verify_ok' === $msg || 'verify_ko' === $msg ) {
			$result = get_transient( 'hef_verify_' . get_current_user_id() );
			delete_transient( 'hef_verify_' . get_current_user_id() );

			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				'verify_ok' === $msg ? 'notice-success' : 'notice-warning',
				esc_html( is_array( $result ) ? $result['message'] : '' )
			);

			return;
		}

		switch ( $msg ) {
			case 'retry_ok':
				$text = __( 'Reenviado correctamente a HubSpot.', 'hubspot-elementor-form' );
				break;
			case 'retry_error':
				$class = 'notice-error';
				$text  = __( 'El reenvío ha vuelto a fallar. Revisa el detalle del registro.', 'hubspot-elementor-form' );
				break;
			case 'deleted':
				$text = __( 'Registros eliminados.', 'hubspot-elementor-form' );
				break;
			case 'bulk_retry':
				$class = ( $ok === $tot ) ? 'notice-success' : 'notice-warning';
				/* translators: 1: correctos, 2: total */
				$text = sprintf( __( 'Reenviados %1$d de %2$d registros.', 'hubspot-elementor-form' ), $ok, $tot );
				break;
			case 'nothing':
				$class = 'notice-warning';
				$text  = __( 'No se ha seleccionado ninguna acción o registro.', 'hubspot-elementor-form' );
				break;
		}

		if ( $text ) {
			printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $text ) );
		}
	}
}
