<?php
/**
 * Pantalla «Formularios listos para usar».
 *
 * @package SWPagos
 */

namespace SWPagos\Library;

use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo de formularios listos para usar: crear la página, copiarlo o
 * guardarlo en «Mis plantillas» de Elementor.
 */
final class Form_Library {

	/**
	 * Identificador de la pantalla.
	 *
	 * @var string
	 */
	public const SLUG = 'swpagos-biblioteca';

	/**
	 * Mensajes a mostrar tras una acción.
	 *
	 * @var array<int,array{tipo:string,texto:string}>
	 */
	private array $avisos = array();

	/**
	 * Engancha la pantalla.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'encolar' ) );
	}

	/**
	 * Encola el script de la pantalla.
	 *
	 * @param string $hook Pantalla actual.
	 * @return void
	 */
	public function encolar( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'swpagos-biblioteca',
			SWPAGOS_URL . 'includes/Library/assets/library.js',
			array(),
			SWPAGOS_VERSION,
			true
		);

		wp_localize_script(
			'swpagos-biblioteca',
			'swpagosBiblioteca',
			( new Clipboard_Exporter() )->datos_para_pantalla()
		);
	}

	/**
	 * Pinta la pantalla.
	 *
	 * @return void
	 */
	public function renderizar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'sw-pagos' ) );
		}

		$this->procesar_acciones();

		$catalogo  = Variants::catalogo();
		$registrar = new Elementor_Template_Registrar();
		$estado    = $registrar->estado();
		$avisos    = $this->avisos;

		require SWPAGOS_DIR . 'includes/Library/Views/library.php';
	}

	/**
	 * Procesa las acciones enviadas por formulario.
	 *
	 * @return void
	 */
	private function procesar_acciones(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$accion = isset( $_POST['swpagos_accion'] ) ? sanitize_key( wp_unslash( $_POST['swpagos_accion'] ) ) : '';

		if ( '' === $accion ) {
			return;
		}

		check_admin_referer( 'swpagos_biblioteca_' . $accion );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para esta acción.', 'sw-pagos' ) );
		}

		$clave = isset( $_POST['swpagos_variante'] ) ? sanitize_text_field( wp_unslash( $_POST['swpagos_variante'] ) ) : '';

		if ( ! Variants::existe( $clave ) ) {
			$this->avisar( 'error', __( 'Variante desconocida.', 'sw-pagos' ) );
			return;
		}

		switch ( $accion ) {
			case 'registrar':
				$this->accion_registrar( $clave );
				break;

			case 'crear_pagina':
				$titulo = isset( $_POST['swpagos_titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['swpagos_titulo'] ) ) : '';
				$this->accion_crear_pagina( $clave, $titulo );
				break;
		}
	}

	/**
	 * Registra la variante en la biblioteca de Elementor.
	 *
	 * @param string $clave Variante.
	 * @return void
	 */
	private function accion_registrar( string $clave ): void {
		$resultado = ( new Elementor_Template_Registrar() )->registrar( $clave );

		if ( is_wp_error( $resultado ) ) {
			$this->avisar( 'error', $resultado->get_error_message() );
			Plugin::instancia()->logger()->warning(
				'No se pudo registrar la variante en la biblioteca de Elementor.',
				array(
					'variante' => $clave,
					'error'    => $resultado->get_error_message(),
				)
			);
			return;
		}

		$this->avisar(
			'success',
			__( 'Variante disponible en «Mis plantillas» del editor de Elementor.', 'sw-pagos' )
		);
	}

	/**
	 * Crea una página con el formulario y su plantilla.
	 *
	 * @param string $clave  Variante.
	 * @param string $titulo Título.
	 * @return void
	 */
	private function accion_crear_pagina( string $clave, string $titulo ): void {
		$resultado = ( new Page_Builder() )->crear( $clave, $titulo );

		if ( is_wp_error( $resultado ) ) {
			$this->avisar( 'error', $resultado->get_error_message() );
			return;
		}

		$this->avisar(
			'success',
			sprintf(
				/* translators: 1: enlace a la página, 2: enlace a la plantilla. */
				__( 'Creados: %1$s (borrador) y su %2$s. Edita la página con Elementor y completa la plantilla.', 'sw-pagos' ),
				'<a href="' . esc_url( (string) get_edit_post_link( $resultado['pagina'] ) ) . '">' . esc_html__( 'la página', 'sw-pagos' ) . '</a>',
				'<a href="' . esc_url( (string) get_edit_post_link( $resultado['plantilla'] ) ) . '">' . esc_html__( 'plantilla de pago', 'sw-pagos' ) . '</a>'
			)
		);
	}

	/**
	 * Acumula un aviso.
	 *
	 * @param string $tipo  success|error|warning.
	 * @param string $texto Texto.
	 * @return void
	 */
	private function avisar( string $tipo, string $texto ): void {
		$this->avisos[] = array(
			'tipo'  => $tipo,
			'texto' => $texto,
		);
	}
}
