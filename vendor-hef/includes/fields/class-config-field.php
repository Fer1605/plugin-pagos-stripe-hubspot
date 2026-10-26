<?php
/**
 * Campo "HubSpot" para el widget Formulario de Elementor Pro.
 *
 * Se añade una sola vez al formulario y contiene el Portal ID y el Form ID.
 * Además inyecta en TODOS los campos del formulario el control
 * "Propiedad de HubSpot" para mapear cada campo con su keyid equivalente.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF\Fields;

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Fields\Field_Base;

defined( 'ABSPATH' ) || exit;

// Elementor Pro provee la clase base; sin ella este archivo no debe declararse.
if ( ! class_exists( '\ElementorPro\Modules\Forms\Fields\Field_Base' ) ) {
	return;
}

class Config_Field extends Field_Base {

	const TYPE = 'hubspot_config';

	/**
	 * Tipos de campo que no pueden mapearse (no aportan un valor enviable).
	 *
	 * @var string[]
	 */
	public static $excluded_types = array(
		self::TYPE,
		'html',
		'step',
		'recaptcha',
		'recaptcha_v3',
		'honeypot',
		'hcaptcha',
		'submit',
	);

	public function get_type() {
		return self::TYPE;
	}

	public function get_name() {
		return esc_html__( 'HubSpot (configuración)', 'hubspot-elementor-form' );
	}

	/**
	 * Render del campo. En el frontend no pinta nada (se oculta por CSS);
	 * en el editor muestra un resumen de la configuración.
	 *
	 * @param array                                     $item       Datos del campo.
	 * @param int                                       $item_index Índice.
	 * @param \ElementorPro\Modules\Forms\Widgets\Form   $form       Widget.
	 */
	public function render( $item, $item_index, $form ) {
		if ( ! \Elementor\Plugin::$instance->preview->is_preview_mode() && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return;
		}

		$portal  = isset( $item['hef_portal_id'] ) ? trim( $item['hef_portal_id'] ) : '';
		$form_id = isset( $item['hef_form_id'] ) ? trim( $item['hef_form_id'] ) : '';
		$name    = isset( $item['hef_form_name'] ) ? trim( $item['hef_form_name'] ) : '';
		$scanned = isset( $item['hef_last_scan'] ) ? trim( $item['hef_last_scan'] ) : '';
		$url     = isset( $item['hef_form_url'] ) ? trim( $item['hef_form_url'] ) : '';
		// El envío lo activa la acción "HubSpot" de "Acciones después de enviar":
		// es el único interruptor, para no tener dos que se contradigan.
		$actions = array();

		if ( is_object( $form ) && method_exists( $form, 'get_settings' ) ) {
			$actions = (array) $form->get_settings( 'submit_actions' );
		}

		$has_action = in_array( 'hubspot', $actions, true );

		$ok = ( $portal && $form_id && $has_action );

		printf(
			'<div class="hef-editor-notice" style="width:100%%;border:1px dashed %1$s;background:%2$s;color:#111;padding:10px 12px;font:13px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;border-radius:4px;">',
			$ok ? '#0b8043' : '#d93025',
			$ok ? '#eef7ef' : '#fdeceb'
		);

		echo '<strong>HubSpot &rarr; ' . esc_html__( 'este campo no se muestra en el sitio', 'hubspot-elementor-form' ) . '</strong><br>';

		if ( ! $has_action ) {
			echo '<strong>' . esc_html__( 'Falta activar el envío.', 'hubspot-elementor-form' ) . '</strong> ';
			echo esc_html__( 'Añade «HubSpot» en «Acciones después de enviar» (Ajustes del formulario). Mientras tanto los envíos se registran en el plugin, pero no se mandan a HubSpot.', 'hubspot-elementor-form' );
		} elseif ( $ok ) {
			if ( $name ) {
				echo '<strong>' . esc_html( $name ) . '</strong><br>';
			}

			printf(
				/* translators: 1: portal id, 2: form id */
				esc_html__( 'Portal ID: %1$s · Form ID: %2$s', 'hubspot-elementor-form' ),
				'<code>' . esc_html( $portal ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput
				'<code>' . esc_html( $form_id ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput
			);

			if ( $url ) {
				echo '<br><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver formulario en HubSpot', 'hubspot-elementor-form' ) . '</a>';
			}

			$rows = \HEF\Form_Handler::parse_mapping( isset( $item['hef_mapping'] ) ? $item['hef_mapping'] : '' );

			echo '<br>' . esc_html(
				sprintf(
					/* translators: %d: número de campos mapeados */
					_n( '%d campo mapeado', '%d campos mapeados', count( $rows ), 'hubspot-elementor-form' ),
					count( $rows )
				)
			);

			if ( $scanned ) {
				echo '<br><em>' . esc_html(
					sprintf(
						/* translators: %s: fecha del último escaneo */
						__( 'Último escaneo: %s', 'hubspot-elementor-form' ),
						$scanned
					)
				) . '</em>';
			}
		} else {
			echo esc_html__( 'Falta el Portal ID o el Form ID: no se enviará nada a HubSpot.', 'hubspot-elementor-form' );
		}

		echo '</div>';
	}

	/**
	 * Inyecta los controles en el repetidor de campos del widget Formulario.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget Widget.
	 */
	public function update_controls( $widget ) {
		$elementor = \ElementorPro\Plugin::elementor();

		$control_data = $elementor->controls_manager->get_control_from_stack( $widget->get_unique_name(), 'form_fields' );

		if ( is_wp_error( $control_data ) || empty( $control_data['fields'] ) ) {
			return;
		}

		$new_controls = $this->config_controls();

		$fields = $control_data['fields'];
		foreach ( $new_controls as $name => $control ) {
			if ( ! isset( $fields[ $name ] ) ) {
				$fields[ $name ] = $control;
			}
		}

		$control_data['fields'] = $fields;

		$widget->update_control( 'form_fields', $control_data );
	}

	/**
	 * Controles propios del campo de configuración.
	 *
	 * @return array
	 */
	protected function config_controls() {
		$only_config = array( 'field_type' => self::TYPE );

		$controls = array(
			// 1. Lo primero: la URL del formulario de HubSpot. De ahí sale todo lo demás.
			'hef_intro'            => array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => '<div style="font-size:12px;line-height:1.6;">'
					. esc_html__( 'Añade este campo UNA sola vez. Pega abajo la URL del formulario de HubSpot y pulsa "Escanear": se rellenan la conexión y el mapeo. El envío se activa con la acción «HubSpot» en «Acciones después de enviar».', 'hubspot-elementor-form' )
					. '</div>',
				'content_classes' => 'elementor-descriptor',
				'condition'       => $only_config,
			),
			'hef_form_url'         => array(
				'label'       => esc_html__( 'URL del formulario de HubSpot', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'ai'          => array( 'active' => false ),
				'placeholder' => 'https://share.hsforms.com/xxxxxxxxxxxxxxxxxxxxx',
				'label_block' => true,
				'description' => esc_html__( 'Enlace de "Compartir", URL del editor del formulario o "portalId/formId". Queda guardada como referencia para futuras ediciones.', 'hubspot-elementor-form' ),
				'condition'   => $only_config,
			),
			'hef_scan_button'      => array(
				'type'        => Controls_Manager::BUTTON,
				'label'       => esc_html__( 'Sincronizar', 'hubspot-elementor-form' ),
				'text'        => esc_html__( 'Escanear / actualizar campos', 'hubspot-elementor-form' ),
				'button_type' => 'success hef-scan-button',
				'event'       => 'hef:scan',
				'separator'   => 'none',
				'description' => esc_html__( 'Vuelve a pulsarlo cuando cambie el formulario en HubSpot: detecta propiedades nuevas, eliminadas y las que pasaron a obligatorias.', 'hubspot-elementor-form' ),
				'condition'   => $only_config,
			),
			'hef_scan_result'      => array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => '<div class="hef-scan-result" data-hef-scan-result="1"></div>',
				'content_classes' => 'hef-scan-result-wrap',
				'condition'       => $only_config,
			),

			// 2. El grupo de mapeos: campo del sitio -> propiedad de HubSpot.
			'hef_mapping_heading'  => array(
				'label'     => esc_html__( 'Mapeo de campos', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $only_config,
			),
			'hef_mapping'          => array(
				'label'       => esc_html__( 'Sitio → HubSpot', 'hubspot-elementor-form' ),
				'type'        => 'hef_mapping',
				'default'     => '',
				'description' => esc_html__( 'Cada línea empareja un campo del formulario con su propiedad (keyid) en HubSpot. Lo rellena el escaneo; puedes cambiarlo, quitar líneas o añadir más. El objeto (Contacto, Empresa…) lo define la propiedad en HubSpot, no se elige aquí.', 'hubspot-elementor-form' ),
				'condition'   => $only_config,
			),
			'hef_properties'       => array(
				'type'      => Controls_Manager::HIDDEN,
				'default'   => '',
				'condition' => $only_config,
			),

			// 3. Qué hace el escaneo.
			'hef_scan_heading'     => array(
				'label'     => esc_html__( 'Opciones del escaneo', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $only_config,
			),
			'hef_scan_add'         => array(
				'label'       => esc_html__( 'Crear los campos que faltan', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'required',
				'options'     => array(
					'required' => esc_html__( 'Solo las propiedades obligatorias', 'hubspot-elementor-form' ),
					'all'      => esc_html__( 'Todas las propiedades del formulario', 'hubspot-elementor-form' ),
					'none'     => esc_html__( 'No crear campos (solo avisar)', 'hubspot-elementor-form' ),
				),
				'description' => esc_html__( 'Añade al formulario de Elementor los campos de HubSpot que no existen aquí, con su tipo, sus opciones y ya mapeados.', 'hubspot-elementor-form' ),
				'condition'   => $only_config,
			),
			'hef_scan_sync_required' => array(
				'label'        => esc_html__( 'Sincronizar obligatoriedad', 'hubspot-elementor-form' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Sí', 'hubspot-elementor-form' ),
				'label_off'    => esc_html__( 'No', 'hubspot-elementor-form' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Marca como obligatorios en Elementor los campos que lo son en HubSpot.', 'hubspot-elementor-form' ),
				'condition'    => $only_config,
			),
			'hef_scan_overwrite'   => array(
				'label'        => esc_html__( 'Sobrescribir mapeos existentes', 'hubspot-elementor-form' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Sí', 'hubspot-elementor-form' ),
				'label_off'    => esc_html__( 'No', 'hubspot-elementor-form' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Desactivado, el escaneo solo rellena los campos que aún no tienen propiedad.', 'hubspot-elementor-form' ),
				'condition'    => $only_config,
			),

			// 4. Conexión (la rellena el escaneo, editable a mano).
			'hef_connection_heading' => array(
				'label'     => esc_html__( 'Conexión', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $only_config,
			),
			'hef_portal_id'        => array(
				'label'       => esc_html__( 'Portal ID (Hub ID)', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'ai'          => array( 'active' => false ),
				'placeholder' => '12345678',
				'label_block' => true,
				'condition'   => $only_config,
			),
			'hef_form_id'          => array(
				'label'       => esc_html__( 'Form ID (GUID del formulario de HubSpot)', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'ai'          => array( 'active' => false ),
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
				'label_block' => true,
				'condition'   => $only_config,
			),
			'hef_region'           => array(
				'label'     => esc_html__( 'Región de la cuenta', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'na1',
				'options'   => array(
					'na1' => esc_html__( 'NA1 (por defecto)', 'hubspot-elementor-form' ),
					'na2' => 'NA2',
					'eu1' => esc_html__( 'EU1 (Europa)', 'hubspot-elementor-form' ),
					'ap1' => 'AP1',
				),
				'condition' => $only_config,
			),

			// Datos informativos que escribe el escaneo.
			'hef_form_name'        => array(
				'type'      => Controls_Manager::HIDDEN,
				'default'   => '',
				'condition' => $only_config,
			),
			'hef_last_scan'        => array(
				'type'      => Controls_Manager::HIDDEN,
				'default'   => '',
				'condition' => $only_config,
			),

			// 5. Envío.
			'hef_send_heading'     => array(
				'label'     => esc_html__( 'Envío', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $only_config,
			),
			'hef_secure'           => array(
				'label'        => esc_html__( 'Usar endpoint seguro', 'hubspot-elementor-form' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Sí', 'hubspot-elementor-form' ),
				'label_off'    => esc_html__( 'No', 'hubspot-elementor-form' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Requiere el token de app privada en Ajustes del plugin.', 'hubspot-elementor-form' ),
				'condition'    => $only_config,
			),
			'hef_send_empty'       => array(
				'label'        => esc_html__( 'Enviar campos vacíos', 'hubspot-elementor-form' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Sí', 'hubspot-elementor-form' ),
				'label_off'    => esc_html__( 'No', 'hubspot-elementor-form' ),
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => $only_config,
			),
			'hef_extra'            => array(
				'label'       => esc_html__( 'Valores fijos adicionales', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 4,
				'ai'          => array( 'active' => false ),
				'placeholder' => "hs_lead_status|NEW\nutm_source|web",
				'description' => esc_html__( 'Una línea por propiedad con el formato propiedad|valor.', 'hubspot-elementor-form' ),
				'condition'   => $only_config,
			),

			// 6. Huella de origen.
			'hef_source_heading'   => array(
				'label'     => esc_html__( 'Huella de origen', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => $only_config,
			),
			'hef_source_note'      => array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => '<div style="font-size:12px;line-height:1.6;">'
					. esc_html__( 'Marca los envíos hechos desde esta web para distinguirlos de los que llegan por el enlace del formulario de HubSpot. No requiere crear ningún campo en HubSpot: la marca viaja en la URL/nombre de página del envío.', 'hubspot-elementor-form' )
					. '</div>',
				'content_classes' => 'elementor-descriptor',
				'condition'       => $only_config,
			),
			'hef_source_tag'       => array(
				'label'       => esc_html__( 'Etiqueta de origen', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'wp-elementor',
				'ai'          => array( 'active' => false ),
				'placeholder' => 'wp-elementor',
				'label_block' => true,
				'description' => esc_html__( 'Vacío = no marcar. Solo letras, números, guiones y puntos.', 'hubspot-elementor-form' ),
				'condition'   => $only_config,
			),
			'hef_source_mode'      => array(
				'label'     => esc_html__( 'Dónde se marca', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'both',
				'options'   => array(
					'both' => esc_html__( 'URL y nombre de la página (recomendado)', 'hubspot-elementor-form' ),
					'url'  => esc_html__( 'Solo la URL de la página', 'hubspot-elementor-form' ),
					'name' => esc_html__( 'Solo el nombre de la página', 'hubspot-elementor-form' ),
				),
				'condition' => array(
					'field_type'      => self::TYPE,
					'hef_source_tag!' => '',
				),
			),

			// 7. Consentimiento y estado.
			'hef_consent'          => array(
				'label'        => esc_html__( 'Enviar consentimiento (RGPD)', 'hubspot-elementor-form' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Sí', 'hubspot-elementor-form' ),
				'label_off'    => esc_html__( 'No', 'hubspot-elementor-form' ),
				'return_value' => 'yes',
				'default'      => '',
				'separator'    => 'before',
				'condition'    => $only_config,
			),
			'hef_consent_text'     => array(
				'label'     => esc_html__( 'Texto del consentimiento', 'hubspot-elementor-form' ),
				'type'      => Controls_Manager::TEXTAREA,
				'rows'      => 3,
				'ai'        => array( 'active' => false ),
				'default'   => esc_html__( 'Acepto el tratamiento de mis datos personales.', 'hubspot-elementor-form' ),
				'condition' => array(
					'field_type'  => self::TYPE,
					'hef_consent' => 'yes',
				),
			),
			'hef_subscription_ids' => array(
				'label'       => esc_html__( 'IDs de tipos de suscripción', 'hubspot-elementor-form' ),
				'type'        => Controls_Manager::TEXT,
				'ai'          => array( 'active' => false ),
				'placeholder' => '1234567, 7654321',
				'label_block' => true,
				'description' => esc_html__( 'Separados por comas. Opcional.', 'hubspot-elementor-form' ),
				'condition'   => array(
					'field_type'  => self::TYPE,
					'hef_consent' => 'yes',
				),
			),
		);

		$out = array();
		foreach ( $controls as $name => $args ) {
			$out[ $name ] = $this->control( $name, $args );
		}

		return $out;
	}

	/**
	 * Normaliza un control para el repetidor de campos del formulario.
	 *
	 * @param string $name Nombre.
	 * @param array  $args Argumentos.
	 * @return array
	 */
	protected function control( $name, array $args ) {
		return array_merge(
			$args,
			array(
				'name'         => $name,
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			)
		);
	}
}
