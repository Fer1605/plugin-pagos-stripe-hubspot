<?php
/**
 * Registro de las piezas de Elementor del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Engancha el campo y las acciones a Elementor Pro.
 *
 * Todo se registra dentro de los ganchos propios de Elementor, de modo que las
 * clases que extienden las suyas solo se carguen cuando Elementor Pro existe. Sin
 * esa precaución, un sitio sin Pro daría un error fatal al arrancar.
 */
final class Loader {

	/**
	 * Engancha el registro.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'elementor_pro/forms/fields/register', array( $this, 'registrar_campos' ) );
		add_action( 'elementor_pro/forms/actions/register', array( $this, 'registrar_acciones' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'encolar' ) );

		( new Amount_Display() )->registrar();
	}

	/**
	 * Registra los tipos de campo propios.
	 *
	 * @param \ElementorPro\Modules\Forms\Registrars\Form_Fields_Registrar $registrar Registrador.
	 * @return void
	 */
	public function registrar_campos( $registrar ): void {
		// El campo de HubSpot lo registra el módulo incorporado, que trae
		// escaneo desde la URL y mapeo automático.
		// Registrar también el nuestro pondría dos campos que hacen lo mismo.
		unset( $registrar );
	}

	/**
	 * Registra las acciones tras el envío.
	 *
	 * @param \ElementorPro\Modules\Forms\Registrars\Form_Actions_Registrar $registrar Registrador.
	 * @return void
	 */
	public function registrar_acciones( $registrar ): void {
		$registrar->register( new Action_Stripe() );
	}

	/**
	 * Encola el script que rellena los campos técnicos y arrastra las UTM.
	 *
	 * @return void
	 */
	public function encolar(): void {
		// El campo de configuración de HubSpot lo oculta su propio módulo; aquí ya
		// no hace falta añadir nada de estilo.
		wp_enqueue_script(
			'swpagos-forms',
			SWPAGOS_URL . 'includes/Elementor/assets/js/swpagos-forms.js',
			array(),
			SWPAGOS_VERSION,
			true
		);

		wp_localize_script(
			'swpagos-forms',
			'swpagosForms',
			array(
				'campoTransaccion' => \SWPagos\Core\Compat::CAMPOS_TECNICOS['transaccion'],
				'campoFormulario'  => \SWPagos\Core\Compat::CAMPOS_TECNICOS['formulario'],
				'campoImporte'     => \SWPagos\Core\Compat::CAMPO_IMPORTE,
				'utms'             => array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ),
			)
		);
	}
}
