<?php
/**
 * Control personalizado: tabla de mapeo Campo del sitio → Propiedad de HubSpot.
 *
 * Elementor no admite repetidores anidados, así que el grupo de mapeos se
 * implementa como un control de datos propio cuyo valor es un JSON:
 * [ { "field": "custom_id", "property": "email", "object": "0-1" }, ... ]
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF\Controls;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Base_Data_Control' ) ) {
	return;
}

class Mapping_Control extends \Elementor\Base_Data_Control {

	const TYPE = 'hef_mapping';

	public function get_type() {
		return self::TYPE;
	}

	public function get_default_value() {
		return '';
	}

	protected function get_default_settings() {
		return array(
			'label_block' => true,
			'separator'   => 'before',
		);
	}

	/**
	 * Plantilla del control en el panel del editor.
	 *
	 * El <input hidden> guarda el JSON; la tabla la dibuja assets/js/editor.js.
	 */
	public function content_template() {
		?>
		<div class="elementor-control-field">
			<label class="elementor-control-title">{{{ data.label }}}</label>
		</div>
		<div class="elementor-control-input-wrapper hef-map">
			<input type="hidden" data-setting="{{ data.name }}" />

			<div class="hef-map-head">
				<span><?php echo esc_html__( 'Campo del sitio', 'hubspot-elementor-form' ); ?></span>
				<span><?php echo esc_html__( 'Propiedad de HubSpot', 'hubspot-elementor-form' ); ?></span>
			</div>

			<div class="hef-map-rows"></div>

			<div class="hef-map-empty">
				<?php echo esc_html__( 'Todavía no hay mapeos. Escanea el formulario de HubSpot o añade uno a mano.', 'hubspot-elementor-form' ); ?>
			</div>

			<button type="button" class="hef-map-add">
				+ <?php echo esc_html__( 'Añadir mapeo', 'hubspot-elementor-form' ); ?>
			</button>
		</div>
		<# if ( data.description ) { #>
			<div class="elementor-control-field-description">{{{ data.description }}}</div>
		<# } #>
		<?php
	}
}
