<?php
/**
 * Cliente de la Forms Submissions API v3 de HubSpot.
 *
 * Docs: https://developers.hubspot.com/docs/api/marketing/forms
 *
 * Este endpoint es server-to-server: el CAPTCHA configurado en el formulario de
 * HubSpot solo aplica al formulario embebido de HubSpot, por lo que las
 * submissions enviadas por esta vía se aceptan igualmente.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Api {

	const ENDPOINT        = 'https://%s/submissions/v3/integration/submit/%s/%s';
	const SECURE_ENDPOINT = 'https://%s/submissions/v3/integration/secure/submit/%s/%s';

	/**
	 * Host de la API según la región de la cuenta.
	 *
	 * @param string $region na1|na2|eu1|ap1.
	 * @return string
	 */
	public static function host( $region = 'na1' ) {
		$region = strtolower( (string) $region );

		if ( in_array( $region, array( 'eu1', 'na2', 'ap1' ), true ) ) {
			return 'api-' . $region . '.hsforms.com';
		}

		return 'api.hsforms.com';
	}

	/**
	 * Construye la URL del endpoint.
	 *
	 * @param string $portal_id Portal ID (Hub ID).
	 * @param string $form_id   Form GUID de HubSpot.
	 * @param bool   $secure    Usar el endpoint seguro (requiere token).
	 * @param string $region    Región de la cuenta.
	 * @return string
	 */
	public static function endpoint( $portal_id, $form_id, $secure = false, $region = 'na1' ) {
		$url = sprintf(
			$secure ? self::SECURE_ENDPOINT : self::ENDPOINT,
			self::host( $region ),
			rawurlencode( $portal_id ),
			rawurlencode( $form_id )
		);

		/**
		 * Filtra el endpoint de envío.
		 *
		 * @param string $url       URL final.
		 * @param string $portal_id Portal ID.
		 * @param string $form_id   Form ID.
		 * @param bool   $secure    Endpoint seguro.
		 * @param string $region    Región.
		 */
		return apply_filters( 'hef/endpoint', $url, $portal_id, $form_id, $secure, $region );
	}

	/**
	 * Envía el payload a HubSpot.
	 *
	 * @param string $endpoint URL.
	 * @param array  $payload  Cuerpo de la petición.
	 * @param string $token    Token de app privada (opcional, endpoint seguro).
	 * @return array {success:bool, code:int, body:string, message:string, retryable:bool}
	 */
	public static function submit( $endpoint, array $payload, $token = '' ) {
		$args = array(
			'timeout'     => (int) Settings::get( 'timeout', 20 ),
			'redirection' => 0,
			'headers'     => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( $payload ),
			'user-agent'  => 'HubSpot-Elementor-Form/' . HEF_VERSION . '; ' . home_url( '/' ),
		);

		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		/**
		 * Filtra los argumentos de wp_remote_post.
		 *
		 * @param array  $args     Argumentos.
		 * @param string $endpoint URL.
		 * @param array  $payload  Payload.
		 */
		$args = apply_filters( 'hef/request_args', $args, $endpoint, $payload );

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success'   => false,
				'code'      => 0,
				'body'      => '',
				'message'   => $response->get_error_message(),
				'retryable' => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$success = ( $code >= 200 && $code < 300 );

		return array(
			'success'   => $success,
			'code'      => $code,
			'body'      => $body,
			'message'   => $success ? __( 'Envío correcto', 'hubspot-elementor-form' ) : self::extract_message( $body, $code ),
			'retryable' => self::is_retryable( $code ),
		);
	}

	/**
	 * Descarga los últimos envíos registrados por HubSpot para un formulario.
	 *
	 * Sirve para comprobar si un envío llegó de verdad, sin depender de la
	 * interfaz de HubSpot (que indexa los envíos con unos minutos de retraso).
	 *
	 * Necesita un token de app privada con el permiso "forms".
	 *
	 * @param string $form_id Form GUID.
	 * @param string $token   Token.
	 * @param int    $limit   Máximo de envíos a traer.
	 * @return array|\WP_Error
	 */
	public static function get_submissions( $form_id, $token, $limit = 50 ) {
		if ( ! $token ) {
			return new \WP_Error( 'hef_no_token', __( 'Para comprobarlo en HubSpot hace falta el token de app privada (Ajustes).', 'hubspot-elementor-form' ) );
		}

		$url = add_query_arg(
			array( 'limit' => max( 1, min( 50, (int) $limit ) ) ),
			'https://api.hubapi.com/form-integrations/v1/submissions/forms/' . rawurlencode( $form_id )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => (int) Settings::get( 'timeout', 20 ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new \WP_Error( 'hef_http_' . $code, self::extract_message( $body, $code ) );
		}

		$data = json_decode( $body, true );

		return isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
	}

	/**
	 * Comprueba que un token de app privada es válido y tiene el scope "forms".
	 *
	 * @param string $token Token.
	 * @return array {success:bool, message:string}
	 */
	public static function test_token( $token ) {
		$token = trim( (string) $token );

		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => __( 'No hay ningún token guardado.', 'hubspot-elementor-form' ),
			);
		}

		$response = wp_remote_get(
			'https://api.hubapi.com/marketing/v3/forms?limit=1',
			array(
				'timeout' => (int) Settings::get( 'timeout', 20 ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			$data  = json_decode( $body, true );
			$total = ( is_array( $data ) && isset( $data['results'] ) ) ? count( $data['results'] ) : 0;

			return array(
				'success' => true,
				'message' => $total
					? __( 'Token válido: HubSpot ha respondido correctamente y hay formularios accesibles.', 'hubspot-elementor-form' )
					: __( 'Token válido, pero la cuenta no devuelve formularios.', 'hubspot-elementor-form' ),
			);
		}

		if ( 401 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Token no válido o revocado (HTTP 401). Vuelve a copiarlo desde la aplicación privada.', 'hubspot-elementor-form' ),
			);
		}

		if ( 403 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'El token existe pero le falta el permiso (scope) "forms" (HTTP 403). Edítalo en HubSpot → Aplicaciones privadas → Alcances.', 'hubspot-elementor-form' ),
			);
		}

		return array(
			'success' => false,
			'message' => self::extract_message( $body, $code ),
		);
	}

	/**
	 * Determina si merece la pena reintentar automáticamente.
	 *
	 * 400 (payload inválido) o 404 (portal/form inexistente) requieren corregir
	 * la configuración, no se reintentan solos; sí se pueden reintentar a mano.
	 *
	 * @param int $code Código HTTP.
	 * @return bool
	 */
	public static function is_retryable( $code ) {
		if ( 0 === $code ) {
			return true; // Error de red / timeout.
		}
		if ( 429 === $code ) {
			return true; // Rate limit.
		}
		return ( $code >= 500 && $code < 600 );
	}

	/**
	 * Extrae un mensaje legible de la respuesta de error de HubSpot.
	 *
	 * @param string $body Cuerpo.
	 * @param int    $code Código.
	 * @return string
	 */
	public static function extract_message( $body, $code ) {
		$data = json_decode( $body, true );

		if ( is_array( $data ) ) {
			$parts = array();

			if ( ! empty( $data['message'] ) ) {
				$parts[] = (string) $data['message'];
			}

			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				foreach ( $data['errors'] as $error ) {
					if ( is_array( $error ) && ! empty( $error['message'] ) ) {
						$parts[] = (string) $error['message'];
					}
				}
			}

			if ( $parts ) {
				return sprintf( 'HTTP %d: %s', $code, implode( ' | ', array_unique( $parts ) ) );
			}
		}

		return sprintf( 'HTTP %d: %s', $code, wp_strip_all_tags( substr( $body, 0, 300 ) ) );
	}
}
