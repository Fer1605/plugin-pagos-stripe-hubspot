<?php
/**
 * Escáner de formularios de HubSpot.
 *
 * A partir de una URL (share.hsforms.com, app.hubspot.com/forms/... o una página
 * con el formulario embebido) obtiene el Portal ID, el Form ID, la región y la
 * definición de campos del formulario.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Scanner {

	const CACHE_TTL = 900; // 15 minutos.

	/**
	 * Regiones admitidas por HubSpot.
	 *
	 * @var string[]
	 */
	public static $regions = array( 'na1', 'na2', 'eu1', 'ap1' );

	/**
	 * Escaneo completo: resuelve IDs y descarga la definición de campos.
	 *
	 * @param string $input URL o "portalId/formId".
	 * @param array  $args  force (bool), portal_id (fallback), region (fallback).
	 * @return array|\WP_Error
	 */
	public static function scan( $input, array $args = array() ) {
		$ids = self::resolve_ids( $input, $args );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$cache_key = 'hef_form_' . md5( $ids['portal_id'] . '|' . $ids['form_id'] );

		if ( empty( $args['force'] ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$definition = self::fetch_definition( $ids['portal_id'], $ids['form_id'], $ids['region'] );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$result = array_merge( $ids, $definition, array( 'cached' => false ) );

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Extrae Portal ID / Form ID / región de lo que pegue el usuario.
	 *
	 * @param string $input Entrada.
	 * @param array  $args  Valores de respaldo.
	 * @return array|\WP_Error
	 */
	public static function resolve_ids( $input, array $args = array() ) {
		$input  = trim( (string) $input );
		$portal = isset( $args['portal_id'] ) ? preg_replace( '/\D/', '', (string) $args['portal_id'] ) : '';
		$region = isset( $args['region'] ) && in_array( $args['region'], self::$regions, true ) ? $args['region'] : '';

		if ( '' === $input ) {
			return new \WP_Error( 'hef_empty', __( 'Introduce la URL del formulario de HubSpot.', 'hubspot-elementor-form' ) );
		}

		// "12345678/guid" o "12345678 guid".
		if ( preg_match( '#^(\d{5,12})\s*[/\s]\s*(' . self::guid_pattern() . ')$#i', $input, $m ) ) {
			return self::ids( $m[1], $m[2], $region );
		}

		// Solo el GUID: se usa el Portal ID ya configurado.
		if ( preg_match( '#^' . self::guid_pattern() . '$#i', $input ) ) {
			if ( ! $portal ) {
				return new \WP_Error( 'hef_no_portal', __( 'Has indicado solo el Form ID: rellena también el Portal ID.', 'hubspot-elementor-form' ) );
			}
			return self::ids( $portal, $input, $region );
		}

		if ( ! preg_match( '#^https?://#i', $input ) ) {
			return new \WP_Error( 'hef_bad_url', __( 'No reconozco ese valor. Pega la URL del formulario (share.hsforms.com/...) o "portalId/formId".', 'hubspot-elementor-form' ) );
		}

		// Enlace de "Compartir": share.hsforms.com/<token>. El token lleva
		// codificados el Form ID y el Portal ID, no hace falta descargar nada.
		if ( preg_match( '#^https?://share(?:-([a-z0-9]+))?\.hsforms\.com/([A-Za-z0-9_\-]{20,})#i', $input, $m ) ) {
			$decoded = self::decode_share_token( $m[2] );

			if ( $decoded ) {
				return self::ids(
					$decoded['portal_id'],
					$decoded['form_id'],
					! empty( $m[1] ) ? strtolower( $m[1] ) : $region
				);
			}
		}

		// URL del editor: app.hubspot.com/forms/12345678/guid/edit
		$from_url = self::ids_from_string( $input );
		if ( $from_url ) {
			return self::ids( $from_url['portal_id'], $from_url['form_id'], $from_url['region'] ? $from_url['region'] : $region );
		}

		// Última opción: descargar la página y buscar la configuración del embed.
		$response = wp_remote_get(
			$input,
			array(
				'timeout'     => (int) Settings::get( 'timeout', 20 ),
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (compatible; HubSpot-Elementor-Form/' . HEF_VERSION . '; +' . home_url( '/' ) . ')',
				'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'hef_http', sprintf( /* translators: %s: error */ __( 'No se pudo abrir la URL: %s', 'hubspot-elementor-form' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 400 ) {
			return new \WP_Error( 'hef_http', sprintf( /* translators: %d: código HTTP */ __( 'La URL respondió HTTP %d.', 'hubspot-elementor-form' ), $code ) );
		}

		$found = self::ids_from_string( $body );

		if ( ! $found ) {
			return new \WP_Error(
				'hef_not_found',
				__( 'No he encontrado el Portal ID / Form ID en esa página. Cópialos desde HubSpot: Marketing → Formularios → tu formulario → Compartir → Insertar código.', 'hubspot-elementor-form' )
			);
		}

		return self::ids(
			$found['portal_id'] ? $found['portal_id'] : $portal,
			$found['form_id'],
			$found['region'] ? $found['region'] : $region
		);
	}

	/**
	 * Busca IDs dentro de una cadena (URL o HTML).
	 *
	 * @param string $text Texto.
	 * @return array|null
	 */
	protected static function ids_from_string( $text ) {
		$guid   = self::guid_pattern();
		$portal = '';
		$form   = '';
		$region = '';

		// Formato de URL del editor o del endpoint: /12345678/00000000-0000-...
		if ( preg_match( '#(?:forms|form)/(\d{5,12})/(' . $guid . ')#i', $text, $m ) ) {
			$portal = $m[1];
			$form   = $m[2];
		}

		if ( ! $form && preg_match( '#["\']?(?:formId|formGuid|form_id)["\']?\s*[:=]\s*["\'](' . $guid . ')["\']#i', $text, $m ) ) {
			$form = $m[1];
		}

		if ( ! $form && preg_match( '#data-form-id=["\'](' . $guid . ')["\']#i', $text, $m ) ) {
			$form = $m[1];
		}

		if ( ! $portal && preg_match( '#["\']?(?:portalId|portal_id|hubId|data-portal-id)["\']?\s*[:=]\s*["\']?(\d{5,12})#i', $text, $m ) ) {
			$portal = $m[1];
		}

		if ( preg_match( '#["\']?region["\']?\s*[:=]\s*["\'](na1|na2|eu1|ap1)["\']#i', $text, $m ) ) {
			$region = strtolower( $m[1] );
		} elseif ( preg_match( '#(?:share|js|forms|app|api)-(eu1|na2|ap1)\.#i', $text, $m ) ) {
			$region = strtolower( $m[1] );
		}

		if ( ! $form ) {
			return null;
		}

		return array(
			'portal_id' => $portal,
			'form_id'   => $form,
			'region'    => $region,
		);
	}

	/**
	 * Decodifica el token de un enlace de compartir de HubSpot.
	 *
	 * Formato (el mismo que usa el JS de la página de share): un símbolo de
	 * versión, 22 caracteres de base64 url-safe con los 16 bytes del GUID del
	 * formulario, y el Portal ID en base 36. Si el primer carácter es "-" el
	 * GUID es del formato antiguo (sin guiones) y el símbolo ocupa 2.
	 *
	 * @param string $token Token de la URL.
	 * @return array|null
	 */
	public static function decode_share_token( $token ) {
		$token = trim( (string) $token );

		if ( strlen( $token ) < 24 ) {
			return null;
		}

		$legacy = ( '-' === substr( $token, 0, 1 ) );
		$offset = $legacy ? 2 : 1;

		$b64  = substr( $token, $offset, 22 );
		$rest = substr( $token, $offset + 22 );

		if ( 22 !== strlen( $b64 ) || '' === $rest ) {
			return null;
		}

		$bin = base64_decode( strtr( $b64, '-_', '+/' ) . '==', true );

		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return null;
		}

		$hex  = bin2hex( $bin );
		$uuid = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );

		$portal = intval( $rest, 36 );

		if ( $portal <= 0 ) {
			return null;
		}

		return array(
			'form_id'   => $legacy ? str_replace( '-', '', $uuid ) : $uuid,
			'portal_id' => (string) $portal,
		);
	}

	/**
	 * Normaliza el trío de identificadores.
	 *
	 * @param string $portal Portal.
	 * @param string $form   Form.
	 * @param string $region Región.
	 * @return array|\WP_Error
	 */
	protected static function ids( $portal, $form, $region ) {
		$portal = preg_replace( '/\D/', '', (string) $portal );
		$form   = strtolower( trim( (string) $form ) );
		$region = in_array( $region, self::$regions, true ) ? $region : '';

		if ( ! $portal ) {
			return new \WP_Error( 'hef_no_portal', __( 'No he podido determinar el Portal ID. Escríbelo a mano y vuelve a escanear.', 'hubspot-elementor-form' ) );
		}

		return array(
			'portal_id' => $portal,
			'form_id'   => $form,
			'region'    => $region ? $region : 'na1',
		);
	}

	/**
	 * Patrón de GUID de HubSpot.
	 *
	 * @return string
	 */
	protected static function guid_pattern() {
		return '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
	}

	/**
	 * Descarga la definición del formulario.
	 *
	 * 1) Definición pública del embed (no necesita token).
	 * 2) Marketing Forms API v3 con token de app privada.
	 *
	 * @param string $portal_id Portal.
	 * @param string $form_id   Form GUID.
	 * @param string $region    Región.
	 * @return array|\WP_Error
	 */
	public static function fetch_definition( $portal_id, $form_id, $region = 'na1' ) {
		$errors = array();

		$hosts = array( 'https://forms.hsforms.com' );
		if ( 'na1' !== $region ) {
			array_unshift( $hosts, 'https://forms-' . $region . '.hsforms.com' );
		}

		foreach ( $hosts as $host ) {
			$url  = $host . '/embed/v3/form/' . rawurlencode( $portal_id ) . '/' . rawurlencode( $form_id ) . '/json';
			$data = self::get_json( $url );

			if ( ! is_wp_error( $data ) ) {
				$fields = self::normalize_fields( $data );
				if ( $fields ) {
					return array_merge(
						self::meta( $data ),
						array(
							'fields' => $fields,
							'source' => 'embed',
						)
					);
				}
				$errors[] = ! empty( $data['errorCode'] )
					? sprintf( /* translators: %s: código de error de HubSpot */ __( 'HubSpot respondió %s.', 'hubspot-elementor-form' ), (string) $data['errorCode'] )
					: __( 'La definición pública no incluía campos.', 'hubspot-elementor-form' );
			} else {
				$errors[] = $data->get_error_message();
			}
		}

		// Fallback autenticado.
		$token = Settings::access_token();

		if ( $token ) {
			$url  = 'https://api.hubapi.com/marketing/v3/forms/' . rawurlencode( $form_id );
			$data = self::get_json( $url, $token );

			if ( ! is_wp_error( $data ) ) {
				$fields = self::normalize_fields( $data );
				if ( $fields ) {
					return array_merge(
						self::meta( $data ),
						array(
							'fields' => $fields,
							'source' => 'api',
						)
					);
				}
				$errors[] = __( 'La API devolvió el formulario sin campos.', 'hubspot-elementor-form' );
			} else {
				$errors[] = $data->get_error_message();
			}
		} else {
			$errors[] = __( 'Sin token de app privada no se puede usar la API como alternativa.', 'hubspot-elementor-form' );
		}

		return new \WP_Error(
			'hef_definition',
			sprintf(
				/* translators: %s: lista de errores */
				__( 'No se pudo leer la definición del formulario. %s', 'hubspot-elementor-form' ),
				implode( ' ', array_unique( $errors ) )
			)
		);
	}

	/**
	 * GET + json_decode.
	 *
	 * @param string $url   URL.
	 * @param string $token Token opcional.
	 * @return array|\WP_Error
	 */
	protected static function get_json( $url, $token = '' ) {
		$args = array(
			'timeout'     => (int) Settings::get( 'timeout', 20 ),
			'redirection' => 3,
			'headers'     => array( 'Accept' => 'application/json' ),
			'user-agent'  => 'HubSpot-Elementor-Form/' . HEF_VERSION,
		);

		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'hef_http_' . $code, Api::extract_message( $body, $code ) );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'hef_json', __( 'Respuesta no válida de HubSpot.', 'hubspot-elementor-form' ) );
		}

		return $data;
	}

	/**
	 * Nombre del formulario y si tiene el CAPTCHA activado.
	 *
	 * @param array $data Respuesta de HubSpot.
	 * @return array
	 */
	protected static function meta( array $data ) {
		$root = ( ! empty( $data['form'] ) && is_array( $data['form'] ) ) ? $data['form'] : $data;

		$name = '';
		foreach ( array( 'name', 'formName' ) as $key ) {
			if ( ! empty( $root[ $key ] ) && is_string( $root[ $key ] ) ) {
				$name = $root[ $key ];
				break;
			}
		}

		return array(
			'form_name' => $name,
			'captcha'   => ! empty( $root['captchaEnabled'] ),
		);
	}

	/**
	 * Aplana la definición (embed v3 o API v3) a una lista uniforme de campos.
	 *
	 * @param array $data Respuesta.
	 * @return array
	 */
	public static function normalize_fields( array $data ) {
		// El endpoint del embed devuelve { form: { formFieldGroups: [...] } }.
		if ( ! empty( $data['form'] ) && is_array( $data['form'] ) ) {
			$data = $data['form'];
		}

		$groups = array();

		if ( ! empty( $data['formFieldGroups'] ) && is_array( $data['formFieldGroups'] ) ) {
			$groups = $data['formFieldGroups']; // Embed v3.
		} elseif ( ! empty( $data['fieldGroups'] ) && is_array( $data['fieldGroups'] ) ) {
			$groups = $data['fieldGroups'];     // Marketing API v3.
		} elseif ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$groups = array( array( 'fields' => $data['fields'] ) );
		}

		$out = array();

		foreach ( $groups as $group ) {
			$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();

			foreach ( $fields as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}

				$type = '';
				foreach ( array( 'fieldType', 'type' ) as $key ) {
					if ( ! empty( $field[ $key ] ) && is_string( $field[ $key ] ) ) {
						$type = strtolower( $field[ $key ] );
						break;
					}
				}

				$options = array();
				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					foreach ( $field['options'] as $option ) {
						if ( is_array( $option ) && isset( $option['value'] ) ) {
							$options[] = array(
								'label' => isset( $option['label'] ) ? (string) $option['label'] : (string) $option['value'],
								'value' => (string) $option['value'],
							);
						}
					}
				}

				$out[] = array(
					'name'     => (string) $field['name'],
					'label'    => isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : (string) $field['name'],
					'type'     => $type,
					'required' => ! empty( $field['required'] ),
					'object'   => isset( $field['objectTypeId'] ) && $field['objectTypeId'] ? (string) $field['objectTypeId'] : '0-1',
					'options'  => $options,
				);
			}
		}

		return $out;
	}
}
