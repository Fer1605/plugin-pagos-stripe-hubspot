<?php
/**
 * Menú de administración del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registra el menú propio del módulo.
 *
 * Menú propio y no submenú de otro: el módulo de referencia cuelga su
 * configuración de su propio tipo de contenido, y compartir ubicación mezclaría
 * dos módulos distintos en la misma pantalla.
 */
final class Menu {

	/**
	 * Identificador de la página de ajustes.
	 *
	 * @var string
	 */
	public const SLUG_AJUSTES = 'swpagos-ajustes';

	/**
	 * Biblioteca de formularios.
	 *
	 * @var string
	 */
	public const SLUG_BIBLIOTECA = \SWPagos\Library\Form_Library::SLUG;

	/**
	 * Transacciones.
	 *
	 * @var string
	 */
	public const SLUG_TRANSACCIONES = Page_Transactions::SLUG;

	/**
	 * Suscripciones.
	 *
	 * @var string
	 */
	public const SLUG_SUSCRIPCIONES = Page_Subscriptions::SLUG;

	/**
	 * Historial de entregas.
	 *
	 * @var string
	 */
	public const SLUG_ENTREGAS = Page_Delivery_Logs::SLUG;

	/**
	 * Engancha el menú.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'admin_menu', array( $this, 'anadir_paginas' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'encolar_estilos' ) );

		( new \SWPagos\Library\Form_Library() )->registrar();
		( new Page_Transactions() )->registrar();
		( new Page_Subscriptions() )->registrar();
		( new Page_Delivery_Logs() )->registrar();
	}

	/**
	 * Añade las páginas del módulo.
	 *
	 * @return void
	 */
	public function anadir_paginas(): void {
		// Una sola instancia por pantalla. Dos instancias distintas del mismo
		// objeto son dos callbacks distintos para add_action(), y la página
		// top-level comparte hook con su primer submenú: se pintaría dos veces.
		$ajustes      = array( new Page_Settings(), 'renderizar' );
		$transacciones = array( new Page_Transactions(), 'renderizar' );
		$suscripciones = array( new Page_Subscriptions(), 'renderizar' );
		$entregas      = array( new Page_Delivery_Logs(), 'renderizar' );
		$biblioteca    = array( new \SWPagos\Library\Form_Library(), 'renderizar' );

		add_menu_page(
			__( 'Pagos Stripe', 'sw-pagos' ),
			__( 'Pagos Stripe', 'sw-pagos' ),
			'manage_options',
			self::SLUG_AJUSTES,
			$ajustes,
			'dashicons-cart',
			56
		);

		add_submenu_page(
			self::SLUG_AJUSTES,
			__( 'Configuración', 'sw-pagos' ),
			__( 'Configuración', 'sw-pagos' ),
			'manage_options',
			self::SLUG_AJUSTES,
			$ajustes
		);

		add_submenu_page(
			self::SLUG_AJUSTES,
			__( 'Transacciones', 'sw-pagos' ),
			__( 'Transacciones', 'sw-pagos' ),
			'manage_options',
			self::SLUG_TRANSACCIONES,
			$transacciones
		);

		add_submenu_page(
			self::SLUG_AJUSTES,
			__( 'Suscripciones', 'sw-pagos' ),
			__( 'Suscripciones', 'sw-pagos' ),
			'manage_options',
			self::SLUG_SUSCRIPCIONES,
			$suscripciones
		);

		add_submenu_page(
			self::SLUG_AJUSTES,
			__( 'Logs de envíos', 'sw-pagos' ),
			__( 'Logs de envíos', 'sw-pagos' ),
			'manage_options',
			self::SLUG_ENTREGAS,
			$entregas
		);

		add_submenu_page(
			self::SLUG_AJUSTES,
			__( 'Formularios listos para usar', 'sw-pagos' ),
			__( 'Formularios listos', 'sw-pagos' ),
			'manage_options',
			self::SLUG_BIBLIOTECA,
			$biblioteca
		);
	}

	/**
	 * Encola los estilos solo en las pantallas del módulo.
	 *
	 * @param string $hook Pantalla actual.
	 * @return void
	 */
	public function encolar_estilos( string $hook ): void {
		if ( ! str_contains( $hook, 'swpagos' ) ) {
			return;
		}

		wp_enqueue_style(
			'swpagos-admin',
			SWPAGOS_URL . 'assets/admin.css',
			array(),
			SWPAGOS_VERSION
		);
	}
}
