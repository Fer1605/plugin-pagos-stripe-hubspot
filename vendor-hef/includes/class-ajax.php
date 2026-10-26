<?php
/**
 * Endpoint AJAX del escáner (editor de Elementor y pantalla de administración).
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Ajax {

	const ACTION = 'hef_scan';
	const NONCE  = 'hef_scan_nonce';

	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_scan' ) );
	}

	/**
	 * Escanea el formulario de HubSpot y devuelve el mapeo propuesto.
	 */
	public static function handle_scan() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para escanear formularios.', 'hubspot-elementor-form' ) ), 403 );
		}

		$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$raw_input = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$portal    = isset( $_POST['portal_id'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_id'] ) ) : '';
		$region    = isset( $_POST['region'] ) ? sanitize_key( wp_unslash( $_POST['region'] ) ) : '';
		$overwrite = ! empty( $_POST['overwrite'] ) && 'false' !== $_POST['overwrite'];
		$force     = ! empty( $_POST['force'] ) && 'false' !== $_POST['force'];

		// Si no es una URL válida puede ser "portalId/formId" o un GUID.
		$input = $url ? $url : $raw_input;

		$fields = array();
		if ( isset( $_POST['fields'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['fields'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $field ) {
					if ( ! is_array( $field ) || empty( $field['id'] ) ) {
						continue;
					}
					$fields[] = array(
						'id'       => sanitize_text_field( $field['id'] ),
						'label'    => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
						'type'     => isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '',
						'required' => ! empty( $field['required'] ),
						'current'  => isset( $field['current'] ) ? sanitize_text_field( $field['current'] ) : '',
					);
				}
			}
		}

		$result = Scanner::scan(
			$input,
			array(
				'portal_id' => $portal,
				'region'    => $region,
				'force'     => $force,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$match = Matcher::match( $fields, $result['fields'], $overwrite );

		wp_send_json_success(
			array(
				'portal_id' => $result['portal_id'],
				'form_id'   => $result['form_id'],
				'region'    => $result['region'],
				'form_name' => $result['form_name'],
				'source'    => $result['source'],
				'captcha'   => ! empty( $result['captcha'] ),
				'cached'    => ! empty( $result['cached'] ),
				'total'     => count( $result['fields'] ),
				'properties' => $result['fields'],
				'mapping'          => $match['mapping'],
				'unmatched'        => $match['unmatched'],
				'kept'             => $match['kept'],
				'obsolete'         => $match['obsolete'],
				'required_updates' => $match['required_updates'],
			)
		);
	}
}
