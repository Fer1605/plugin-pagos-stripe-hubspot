<?php
/**
 * Acción de Elementor: cobrar con Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Elementor;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Stripe\Checkout_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Para formularios con cobro, único o mensual.
 *
 * No envía nada a HubSpot: en un formulario con cobro eso pertenece a la
 * confirmación del pago, no al envío del formulario. Aquí solo se
 * valida, se resuelve el importe en el servidor y se redirige a Stripe.
 */
final class Action_Stripe extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/**
	 * Nombre interno con el que Elementor indexa la acción.
	 *
	 * @return string
	 */
	public function get_name() {
		return Compat::ACCIONES_ELEMENTOR['stripe'];
	}

	/**
	 * Etiqueta visible en «Acciones tras el envío».
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Cobro con Stripe', 'sw-pagos' );
	}

	/**
	 * Sección de ajustes.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form_Base $widget Widget.
	 * @return void
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'swpagos_seccion_stripe',
			array(
				'label'     => esc_html__( 'Cobro con Stripe', 'sw-pagos' ),
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);

		$widget->add_control(
			Compat::CONTROL_PASARELA,
			array(
				'label'   => esc_html__( 'Pasarela', 'sw-pagos' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array( 'stripe' => 'Stripe' ),
				'default' => 'stripe',
			)
		);

		$widget->add_control(
			Compat::CONTROL_MODO,
			array(
				'label'        => esc_html__( 'Cobrar de verdad', 'sw-pagos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Sí', 'sw-pagos' ),
				'label_off'    => esc_html__( 'No', 'sw-pagos' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Desactivado usa las credenciales de prueba de Stripe. Actívalo solo cuando el formulario esté listo para recibir dinero real.', 'sw-pagos' ),
			)
		);

		$widget->add_control(
			'swpagos_stripe_aviso',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => wp_kses_post(
					__( 'El importe, la moneda y las frecuencias los decide la <strong>plantilla de pago</strong> cuyo identificador de formulario coincida con el de este formulario, no este panel.<br><br>Lo que escriba la persona en el campo de importe es una solicitud: el servidor la comprueba contra la plantilla y rechaza lo que quede fuera.', 'sw-pagos' )
				),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Nada que exportar.
	 *
	 * @param array $element Elemento.
	 * @return void
	 */
	public function on_export( $element ) {}

	/**
	 * Ejecuta el cobro.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Envío.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Respuesta.
	 * @return void
	 */
	public function run( $record, $ajax_handler ) {
		$ajustes = (array) $record->get( 'form_settings' );
		$campos  = (array) $record->get( 'fields' );

		$modo = ( $ajustes[ Compat::CONTROL_MODO ] ?? '' ) === 'yes' ? 'live' : 'test';

		$resultado = ( new Checkout_Service() )->iniciar(
			$ajustes,
			$campos,
			array(
				'modo'   => $modo,
				'envio'  => $this->id_envio( $record, $ajax_handler ),
				'locale' => get_locale(),
			)
		);

		$this->anotar_envio( $resultado['transaccion'] ?? null, $ajax_handler );

		if ( ! $resultado['ok'] ) {
			$ajax_handler->add_error_message( $resultado['mensaje'] );

			if ( '' !== $resultado['campo'] ) {
				$ajax_handler->add_error( $resultado['campo'], $resultado['mensaje'] );
			}

			return;
		}

		// Elementor redirige el navegador cuando la respuesta trae esta clave.
		$ajax_handler->add_response_data( 'redirect_url', $resultado['url'] );
	}

	/**
	 * Anota el envío de Elementor en la transacción recién creada.
	 *
	 * Elementor guarda el envío en su propia acción, que corre antes que esta,
	 * pero no lo deja a mano y la consulta inmediata no siempre lo encuentra: la
	 * fila puede no estar todavía a la vista de esta petición. Al terminar todas
	 * las acciones ya no hay carrera, así que se busca entonces.
	 *
	 * Sin esto el cobro queda sin número de envío, y ese número es lo que permite
	 * casar una donación con lo que la persona escribió en el formulario.
	 *
	 * @param mixed                                            $transaccion  Transacción, si se creó.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Respuesta.
	 * @return void
	 */
	private function anotar_envio( $transaccion, $ajax_handler ): void {
		if ( ! $transaccion instanceof Transaction || (int) $transaccion->valor( 'submission_id', 0 ) > 0 ) {
			return;
		}

		if ( ! method_exists( $ajax_handler, 'get_current_form' ) ) {
			return;
		}

		$formulario = (array) $ajax_handler->get_current_form();
		$element_id = (string) ( $formulario['id'] ?? '' );

		if ( '' === $element_id ) {
			return;
		}

		add_action(
			'elementor_pro/forms/new_record',
			static function () use ( $transaccion, $element_id ): void {
				$envio = Submissions::cercano( $element_id, (string) $transaccion->valor( 'created_at', '' ) );

				if ( $envio > 0 ) {
					( new Transaction_Repository() )->actualizar( $transaccion, array( 'submission_id' => $envio ) );
				}
			},
			100
		);
	}

	/**
	 * Identificador del envío guardado por Elementor, si lo hay.
	 *
	 * Elementor no se lo pasa a las acciones, así que casi siempre hay que ir a
	 * buscarlo. Para cuando llega aquí ya está guardado: «Collect Submissions» se
	 * registra con prioridad cero, antes que cualquier otra acción.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Envío.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Respuesta.
	 * @return int
	 */
	private function id_envio( $record, $ajax_handler ): int {
		if ( method_exists( $record, 'get' ) ) {
			$id = $record->get( 'submission_id' );

			if ( is_numeric( $id ) && (int) $id > 0 ) {
				return (int) $id;
			}
		}

		if ( ! method_exists( $ajax_handler, 'get_current_form' ) ) {
			return 0;
		}

		$formulario = (array) $ajax_handler->get_current_form();

		return Submissions::cercano( (string) ( $formulario['id'] ?? '' ) );
	}
}
