<?php
/**
 * Construye el payload y lo envía a HubSpot cuando Elementor confirma el envío.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

use HEF\Fields\Config_Field;

defined( 'ABSPATH' ) || exit;

class Form_Handler {

	/**
	 * ID del envío de Elementor resuelto en esta petición.
	 *
	 * @var int
	 */
	protected static $submission_id = 0;

	public static function init() {
		// Elementor dispara "new_record" únicamente cuando todas las validaciones
		// del formulario (incluidos reCAPTCHA, honeypot y campos requeridos) pasan.
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_new_record' ), 99, 2 );
	}

	/**
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record   $record       Registro.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler  $ajax_handler Handler.
	 */
	public static function on_new_record( $record, $ajax_handler ) {
		self::process( $record, $ajax_handler, false );
	}

	/**
	 * Envía el registro a HubSpot.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record   $record       Registro.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler  $ajax_handler Handler.
	 * @param bool                                                $from_action  Si lo lanza la acción de Elementor.
	 * @return array|null {success:bool, message:string, log_id:int} o null si no aplica.
	 */
	public static function process( $record, $ajax_handler, $from_action = false ) {
		$form_settings = $record->get( 'form_settings' );

		// El envío lo gobierna la acción "HubSpot" de "Acciones después de
		// enviar": es el único interruptor. Si está, la ejecuta ella (y queda
		// en el Actions Log de Elementor). Si no está, aquí solo se registra
		// lo que se habría enviado, para poder mandarlo luego con "Reintentar".
		$action_enabled = self::action_enabled( $form_settings );

		if ( ! $from_action && $action_enabled ) {
			return null;
		}

		$only_log = ( ! $from_action && ! $action_enabled );

		$form_fields = isset( $form_settings['form_fields'] ) && is_array( $form_settings['form_fields'] ) ? $form_settings['form_fields'] : array();

		$config = self::find_config( $form_fields );

		if ( ! $config ) {
			return null; // El formulario no tiene el campo HubSpot: no hacemos nada.
		}

		$portal_id = isset( $config['hef_portal_id'] ) ? trim( $config['hef_portal_id'] ) : '';
		$hs_form   = isset( $config['hef_form_id'] ) ? trim( $config['hef_form_id'] ) : '';
		$form_name = isset( $form_settings['form_name'] ) ? $form_settings['form_name'] : '';

		$context = self::request_context();

		if ( '' === $portal_id || '' === $hs_form ) {
			Logger::insert(
				array(
					'status'    => Logger::STATUS_ERROR,
					'form_name' => $form_name,
					'form_id'   => isset( $form_settings['id'] ) ? $form_settings['id'] : '',
					'post_id'   => $context['post_id'],
					'portal_id' => $portal_id,
					'hs_form_id' => $hs_form,
					'message'   => __( 'Configuración incompleta: falta el Portal ID o el Form ID.', 'hubspot-elementor-form' ),
					'attempts'  => 0,
					'page_url'  => $context['page_uri'],
					'ip'        => $context['ip'],
					'submission_id' => self::find_submission_id( $form_settings, $context ),
				)
			);

			return array(
				'success' => false,
				'message' => __( 'Configuración incompleta: falta el Portal ID o el Form ID.', 'hubspot-elementor-form' ),
				'log_id'  => 0,
			);
		}

		$payload = self::build_payload( $record, $form_fields, $config, $context );

		if ( empty( $payload['fields'] ) ) {
			Logger::insert(
				array(
					'status'     => Logger::STATUS_ERROR,
					'form_name'  => $form_name,
					'form_id'    => isset( $form_settings['id'] ) ? $form_settings['id'] : '',
					'post_id'    => $context['post_id'],
					'portal_id'  => $portal_id,
					'hs_form_id' => $hs_form,
					'message'    => __( 'Ningún campo tiene asignada una propiedad de HubSpot.', 'hubspot-elementor-form' ),
					'page_url'   => $context['page_uri'],
					'ip'         => $context['ip'],
					'submission_id' => self::find_submission_id( $form_settings, $context ),
				)
			);

			return array(
				'success' => false,
				'message' => __( 'Ningún campo tiene asignada una propiedad de HubSpot.', 'hubspot-elementor-form' ),
				'log_id'  => 0,
			);
		}

		$secure   = ( ! empty( $config['hef_secure'] ) && 'yes' === $config['hef_secure'] );
		$region   = ! empty( $config['hef_region'] ) ? sanitize_key( $config['hef_region'] ) : 'na1';
		$endpoint = Api::endpoint( $portal_id, $hs_form, $secure, $region );

		$log_id = Logger::insert(
			array(
				'status'     => $only_log ? Logger::STATUS_SKIP : Logger::STATUS_PEND,
				'message'    => $only_log ? __( 'No se envió: falta añadir la acción «HubSpot» en «Acciones después de enviar» del formulario. Los datos quedan guardados: puedes mandarlos con «Reintentar».', 'hubspot-elementor-form' ) : '',
				'form_name'  => $form_name,
				'form_id'    => isset( $form_settings['id'] ) ? $form_settings['id'] : '',
				'post_id'    => $context['post_id'],
				'portal_id'  => $portal_id,
				'hs_form_id' => $hs_form,
				'endpoint'   => $endpoint,
				'payload'    => Settings::get( 'store_payload' ) ? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : wp_json_encode( array( 'fields' => count( $payload['fields'] ) ) ),
				'page_url'   => $context['page_uri'],
				'ip'         => $context['ip'],
				'submission_id' => self::find_submission_id( $form_settings, $context ),
			)
		);

		// Sin la acción no se envía nada: solo queda el registro.
		if ( $only_log ) {
			self::annotate_submission(
				$log_id,
				$config,
				array(
					'status'  => __( 'Sin enviar', 'hubspot-elementor-form' ),
					'message' => __( 'falta la acción HubSpot', 'hubspot-elementor-form' ),
				)
			);

			return null;
		}

		$token  = $secure ? Settings::access_token() : '';
		$result = Api::submit( $endpoint, $payload, $token );

		self::store_result( $log_id, $result, 1 );

		/**
		 * Se dispara tras intentar el envío a HubSpot.
		 *
		 * @param array $result  Resultado.
		 * @param array $payload Payload enviado.
		 * @param int   $log_id  ID del registro.
		 */
		do_action( 'hef/after_submit', $result, $payload, $log_id );

		if ( ! $from_action && ! $result['success'] && Settings::get( 'show_error' ) && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_admin_error_message' ) ) {
			$ajax_handler->add_admin_error_message(
				sprintf(
					/* translators: %s: mensaje de error */
					__( 'HubSpot: %s', 'hubspot-elementor-form' ),
					$result['message']
				)
			);
		}

		self::annotate_submission(
			$log_id,
			$config,
			array(
				'status'  => $result['success'] ? __( 'Correcto', 'hubspot-elementor-form' ) : __( 'Error', 'hubspot-elementor-form' ),
				'message' => $result['code'] ? 'HTTP ' . (int) $result['code'] : $result['message'],
			)
		);

		return array(
			'success' => (bool) $result['success'],
			'message' => $result['message'],
			'log_id'  => $log_id,
		);
	}

	/**
	 * Escribe la referencia al registro del plugin dentro del envío guardado
	 * por Elementor, para poder ir de un sitio a otro.
	 *
	 * Aprovecha la fila que el propio campo de configuración deja en el envío
	 * (vacía y con un nombre ilegible); si no existe, añade una.
	 *
	 * @param int   $log_id ID del registro del plugin.
	 * @param array $config Campo de configuración.
	 * @param array $info   status y message.
	 */
	public static function annotate_submission( $log_id, array $config, array $info ) {
		global $wpdb;

		if ( ! $log_id || ! Settings::get( 'annotate_submission' ) ) {
			return;
		}

		$submission_id = (int) self::$submission_id;

		if ( ! $submission_id ) {
			return;
		}

		$table = $wpdb->prefix . 'e_submissions_values';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page'   => 'hef-logs',
				'hef_id' => (int) $log_id,
			),
			admin_url( 'admin.php' )
		);

		$text = sprintf(
			/* translators: 1: id del registro, 2: estado, 3: detalle, 4: URL del registro */
			__( 'Registro #%1$d · %2$s · %3$s — %4$s', 'hubspot-elementor-form' ),
			(int) $log_id,
			$info['status'],
			$info['message'],
			$url
		);

		$text = mb_substr( $text, 0, 400 );
		$label = __( 'HubSpot', 'hubspot-elementor-form' );
		$key   = isset( $config['custom_id'] ) ? $config['custom_id'] : '';

		$existing = 0;

		if ( $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE submission_id = %d AND `key` = %s LIMIT 1", $submission_id, $key ) );
		}

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'key'   => $label,
					'value' => $text,
				),
				array( 'id' => $existing )
			);

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'submission_id' => $submission_id,
				'key'           => $label,
				'value'         => $text,
			)
		);
	}

	/**
	 * ¿Está la acción "HubSpot" entre las acciones tras enviar del formulario?
	 *
	 * @param array $form_settings Ajustes del formulario.
	 * @return bool
	 */
	protected static function action_enabled( $form_settings ) {
		if ( empty( $form_settings['submit_actions'] ) || ! is_array( $form_settings['submit_actions'] ) ) {
			return false;
		}

		return in_array( 'hubspot', $form_settings['submit_actions'], true );
	}

	/**
	 * ¿Tiene el formulario activada la acción "Collect Submissions"?
	 *
	 * Elementor la ha llamado de varias formas según la versión, así que se
	 * reconocen las conocidas y cualquier variante que hable de envíos o de
	 * base de datos.
	 *
	 * @param array $form_settings Ajustes del formulario.
	 * @return bool
	 */
	public static function submissions_enabled( $form_settings ) {
		if ( empty( $form_settings['submit_actions'] ) || ! is_array( $form_settings['submit_actions'] ) ) {
			return false;
		}

		$known = array( 'save-to-database', 'save_to_database', 'collect-submissions', 'collect_submissions', 'submissions' );

		foreach ( $form_settings['submit_actions'] as $action ) {
			$action = strtolower( (string) $action );

			if ( in_array( $action, $known, true ) ) {
				return true;
			}

			if ( false !== strpos( $action, 'submission' ) || false !== strpos( $action, 'database' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Localiza el envío que Elementor acaba de guardar, para poder enlazarlo.
	 *
	 * Solo existe si el formulario tiene activado "Collect Submissions".
	 *
	 * @param array $form_settings Ajustes del formulario.
	 * @param array $context       Contexto.
	 * @return int
	 */
	public static function find_submission_id( $form_settings, array $context ) {
		global $wpdb;

		self::$submission_id = 0;

		// Sin "Collect Submissions" no hay envío que enlazar.
		if ( ! self::submissions_enabled( $form_settings ) ) {
			return 0;
		}

		$element_id = isset( $form_settings['id'] ) ? $form_settings['id'] : '';

		if ( ! $element_id ) {
			return 0;
		}

		$table = $wpdb->prefix . 'e_submissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		// Solo envíos recentísimos: si no cuadra, se prefiere no enlazar nada
		// antes que enlazar el envío equivocado.
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 5 * MINUTE_IN_SECONDS ) );

		$sql = "SELECT id FROM {$table} WHERE element_id = %s AND created_at >= %s ORDER BY id DESC LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$submission_id = (int) $wpdb->get_var( $wpdb->prepare( $sql, $element_id, $since ) );

		return self::$submission_id;
	}

	/**
	 * Guarda el resultado de un intento en el log.
	 *
	 * @param int   $log_id    ID.
	 * @param array $result    Resultado de Api::submit().
	 * @param int   $attempts  Nº de intento acumulado.
	 */
	public static function store_result( $log_id, array $result, $attempts ) {
		if ( ! $log_id ) {
			return;
		}

		$data = array(
			'status'        => $result['success'] ? Logger::STATUS_OK : Logger::STATUS_ERROR,
			'response_code' => (int) $result['code'],
			'response'      => mb_substr( (string) $result['body'], 0, 60000 ),
			'message'       => mb_substr( (string) $result['message'], 0, 1000 ),
			'attempts'      => (int) $attempts,
			'next_retry_at' => null,
		);

		if ( ! $result['success'] && $result['retryable'] && Settings::get( 'auto_retry' ) ) {
			$data['next_retry_at'] = Retry::next_datetime( (int) $attempts );
		}

		Logger::update( $log_id, $data );
	}

	/**
	 * Localiza el campo de configuración dentro del formulario.
	 *
	 * @param array $form_fields Campos.
	 * @return array|null
	 */
	protected static function find_config( array $form_fields ) {
		foreach ( $form_fields as $field ) {
			if ( isset( $field['field_type'] ) && Config_Field::TYPE === $field['field_type'] ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Construye el cuerpo de la petición para la API v3.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record      Registro.
	 * @param array                                           $form_fields Definición de campos.
	 * @param array                                           $config      Campo de configuración.
	 * @param array                                           $context     Contexto de la petición.
	 * @return array
	 */
	public static function build_payload( $record, array $form_fields, array $config, array $context ) {
		$values     = $record->get( 'fields' );
		$send_empty = ( ! empty( $config['hef_send_empty'] ) && 'yes' === $config['hef_send_empty'] );
		$map        = self::parse_mapping( isset( $config['hef_mapping'] ) ? $config['hef_mapping'] : '' );
		$fields     = array();

		// El orden del grupo de mapeo manda: es el que se ve en el editor y el
		// que el usuario puede reordenar arrastrando. Después se añaden los
		// mapeos por campo de versiones anteriores que no estén en el grupo.
		$ordered = $map;

		foreach ( $form_fields as $field ) {
			$id = isset( $field['custom_id'] ) ? $field['custom_id'] : '';

			if ( '' === $id || isset( $ordered[ $id ] ) || empty( $field['hef_hubspot_property'] ) ) {
				continue;
			}

			$ordered[ $id ] = array(
				'property' => trim( $field['hef_hubspot_property'] ),
				'object'   => ! empty( $field['hef_object_type'] ) ? $field['hef_object_type'] : '0-1',
			);
		}

		foreach ( $ordered as $id => $row ) {
			if ( '' === $row['property'] || ! isset( $values[ $id ] ) ) {
				continue;
			}

			$value = self::normalize_value( $values[ $id ] );

			if ( '' === $value && ! $send_empty ) {
				continue;
			}

			$fields[] = array(
				'objectTypeId' => $row['object'],
				'name'         => $row['property'],
				'value'        => $value,
			);
		}

		// Valores fijos definidos en el campo de configuración.
		foreach ( self::parse_extra( isset( $config['hef_extra'] ) ? $config['hef_extra'] : '' ) as $property => $value ) {
			$fields[] = array(
				'objectTypeId' => '0-1',
				'name'         => $property,
				'value'        => $value,
			);
		}

		$context = self::apply_source_tag( $context, $config );

		$payload = array(
			'submittedAt' => (string) round( microtime( true ) * 1000 ),
			'fields'      => $fields,
			'context'     => array_filter(
				array(
					'hutk'      => $context['hutk'],
					'pageUri'   => $context['page_uri'],
					'pageName'  => $context['page_name'],
					'ipAddress' => $context['ip'],
				)
			),
		);

		$consent = self::build_consent( $config );
		if ( $consent ) {
			$payload['legalConsentOptions'] = $consent;
		}

		/**
		 * Filtra el payload antes de enviarlo a HubSpot.
		 *
		 * @param array  $payload Payload.
		 * @param object $record  Form_Record de Elementor.
		 * @param array  $config  Configuración del campo HubSpot.
		 */
		return apply_filters( 'hef/payload', $payload, $record, $config );
	}

	/**
	 * Marca el envío como procedente de esta web.
	 *
	 * HubSpot rechaza cualquier propiedad que no exista en el formulario de
	 * destino, así que la huella no puede ir en "fields": se escribe en el
	 * contexto del envío (URL y nombre de la página), que HubSpot guarda tal
	 * cual y permite filtrar en listas e informes sin configurar nada.
	 *
	 * @param array $context Contexto de la petición.
	 * @param array $config  Configuración del campo HubSpot.
	 * @return array
	 */
	public static function apply_source_tag( array $context, array $config ) {
		$tag = array_key_exists( 'hef_source_tag', $config ) ? (string) $config['hef_source_tag'] : 'wp-elementor';
		$tag = preg_replace( '/[^A-Za-z0-9_.\-]/', '', $tag );

		if ( '' === $tag ) {
			return $context;
		}

		$mode = ! empty( $config['hef_source_mode'] ) ? $config['hef_source_mode'] : 'both';

		/**
		 * Filtra el nombre del parámetro que marca el origen en la URL.
		 *
		 * @param string $param Parámetro.
		 */
		$param = apply_filters( 'hef/source_param', 'hs_form_source' );

		if ( in_array( $mode, array( 'both', 'url' ), true ) && $context['page_uri'] ) {
			$context['page_uri'] = add_query_arg( rawurlencode( $param ), rawurlencode( $tag ), $context['page_uri'] );
		}

		if ( in_array( $mode, array( 'both', 'name' ), true ) ) {
			$context['page_name'] = trim( $context['page_name'] . ' [' . $tag . ']' );
		}

		$context['source_tag'] = $tag;

		/**
		 * Filtra el contexto ya marcado.
		 *
		 * @param array  $context Contexto.
		 * @param string $tag     Etiqueta.
		 * @param array  $config  Configuración.
		 */
		return apply_filters( 'hef/source_context', $context, $tag, $config );
	}

	/**
	 * Convierte el valor de Elementor al formato aceptado por HubSpot.
	 *
	 * Los campos de selección múltiple / casillas se envían separados por ";".
	 *
	 * @param array $field Campo del Form_Record.
	 * @return string
	 */
	protected static function normalize_value( array $field ) {
		$raw = array_key_exists( 'raw_value', $field ) ? $field['raw_value'] : ( isset( $field['value'] ) ? $field['value'] : '' );

		if ( is_array( $raw ) ) {
			$raw = array_filter(
				array_map( 'strval', $raw ),
				function ( $item ) {
					return '' !== trim( $item );
				}
			);
			return implode( ';', $raw );
		}

		if ( is_bool( $raw ) ) {
			return $raw ? 'true' : 'false';
		}

		return trim( (string) $raw );
	}

	/**
	 * Normaliza el grupo de mapeos del campo de configuración.
	 *
	 * Formato guardado: JSON [ { field, property, object }, ... ].
	 *
	 * @param mixed $raw Valor del control.
	 * @return array [ custom_id => [ property, object ] ]
	 */
	public static function parse_mapping( $raw ) {
		$rows = $raw;

		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return array();
			}
			$rows = json_decode( $raw, true );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field    = isset( $row['field'] ) ? trim( (string) $row['field'] ) : '';
			$property = isset( $row['property'] ) ? trim( (string) $row['property'] ) : '';

			if ( '' === $field || '' === $property ) {
				continue;
			}

			$object = isset( $row['object'] ) && $row['object'] ? (string) $row['object'] : '0-1';

			// La primera línea de un campo repetido es la que manda.
			if ( ! isset( $out[ $field ] ) ) {
				$out[ $field ] = array(
					'property' => $property,
					'object'   => $object,
				);
			}
		}

		return $out;
	}

	/**
	 * Convierte "propiedad|valor" por línea en un array asociativo.
	 *
	 * @param string $text Texto.
	 * @return array
	 */
	protected static function parse_extra( $text ) {
		$out = array();

		if ( ! $text ) {
			return $out;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $key, $value ) = array_pad( explode( '|', $line, 2 ), 2, '' );
			$key                 = trim( $key );
			if ( '' !== $key ) {
				$out[ $key ] = trim( $value );
			}
		}

		return $out;
	}

	/**
	 * Bloque legalConsentOptions.
	 *
	 * @param array $config Configuración.
	 * @return array
	 */
	protected static function build_consent( array $config ) {
		if ( empty( $config['hef_consent'] ) || 'yes' !== $config['hef_consent'] ) {
			return array();
		}

		$text = isset( $config['hef_consent_text'] ) ? wp_strip_all_tags( $config['hef_consent_text'] ) : '';
		$text = $text ? $text : __( 'Acepto el tratamiento de mis datos personales.', 'hubspot-elementor-form' );

		$consent = array(
			'consentToProcess' => true,
			'text'             => $text,
		);

		$ids = array();
		if ( ! empty( $config['hef_subscription_ids'] ) ) {
			foreach ( explode( ',', (string) $config['hef_subscription_ids'] ) as $id ) {
				$id = (int) trim( $id );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		if ( $ids ) {
			$consent['communications'] = array();
			foreach ( $ids as $id ) {
				$consent['communications'][] = array(
					'value'                => true,
					'subscriptionTypeId'   => $id,
					'text'                 => $text,
				);
			}
		}

		return array( 'consent' => $consent );
	}

	/**
	 * Datos de contexto de la petición actual.
	 *
	 * @return array
	 */
	public static function request_context() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Elementor ya validó el nonce del formulario.
		$referrer  = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$queried   = isset( $_POST['queried_id'] ) ? absint( $_POST['queried_id'] ) : 0;
		// phpcs:enable

		$page_uri = $referrer ? $referrer : ( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		$page_id  = $queried ? $queried : $post_id;
		$page_nm  = $page_id ? get_the_title( $page_id ) : get_bloginfo( 'name' );

		return array(
			'hutk'      => isset( $_COOKIE['hubspotutk'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['hubspotutk'] ) ) : '',
			'page_uri'  => $page_uri,
			'page_name' => $page_nm ? wp_strip_all_tags( $page_nm ) : '',
			'post_id'   => $page_id,
			'ip'        => self::client_ip(),
		);
	}

	/**
	 * IP del visitante.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filtra la IP enviada a HubSpot (útil tras CDN/proxy).
		 *
		 * @param string $ip IP.
		 */
		$ip = apply_filters( 'hef/client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
