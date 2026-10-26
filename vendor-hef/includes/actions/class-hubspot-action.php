<?php
/**
 * Acción de Elementor Forms "HubSpot".
 *
 * Registrarse como acción hace que el envío aparezca en el "Actions Log" de
 * cada envío guardado por Elementor, junto a Email, Redirect, etc.
 *
 * Es opcional: si no se añade a "Acciones después de enviar", el plugin sigue
 * enviando por su cuenta al validarse el formulario.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF\Actions;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
	return;
}

class HubSpot_Action extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	const NAME = 'hubspot';

	public function get_name() {
		return self::NAME;
	}

	public function get_label() {
		return esc_html__( 'HubSpot', 'hubspot-elementor-form' );
	}

	/**
	 * La configuración vive en el campo "HubSpot (configuración)" del
	 * formulario, así que la acción no añade ajustes propios.
	 *
	 * @param \Elementor\Widget_Base $widget Widget.
	 */
	public function register_settings_section( $widget ) {}

	/**
	 * @param array $element Datos del elemento.
	 * @return array
	 */
	public function on_export( $element ) {
		return $element;
	}

	/**
	 * Ejecuta el envío a HubSpot.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       Registro.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Handler.
	 */
	public function run( $record, $ajax_handler ) {
		$result = \HEF\Form_Handler::process( $record, $ajax_handler, true );

		if ( ! $result ) {
			return; // El formulario no tiene el campo de configuración.
		}

		if ( empty( $result['success'] ) ) {
			// Se informa a quien puede editar; el visitante ve su mensaje normal.
			$ajax_handler->add_admin_error_message(
				sprintf(
					/* translators: %s: mensaje de error de HubSpot */
					esc_html__( 'HubSpot: %s', 'hubspot-elementor-form' ),
					$result['message']
				)
			);
		}
	}
}
