<?php
/**
 * Ajustes globales del plugin.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'hef_settings';

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'access_token'   => '',   // Token de app privada (solo si se usa el endpoint seguro).
			'auto_retry'     => 1,    // Reintentar automáticamente los envíos fallidos.
			'max_attempts'   => 5,    // Intentos máximos por registro.
			'retention_days' => 60,   // Días que se conservan los registros (0 = siempre).
			'store_payload'  => 1,    // Guardar el JSON enviado en el log.
			'show_error'     => 0,    // Mostrar el error al usuario en el formulario.
			'annotate_submission' => 1, // Escribir la referencia en el envío de Elementor.
			'timeout'        => 20,   // Timeout de la petición HTTP en segundos.
		);
	}

	/**
	 * Devuelve todos los ajustes normalizados.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Devuelve un ajuste concreto.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Token de acceso (permite sobrescribirlo por constante o filtro para no guardarlo en BD).
	 *
	 * @return string
	 */
	public static function access_token() {
		$token = '';
		if ( defined( 'HEF_HUBSPOT_TOKEN' ) && HEF_HUBSPOT_TOKEN ) {
			$token = HEF_HUBSPOT_TOKEN;
		} else {
			$token = (string) self::get( 'access_token', '' );
		}
		/**
		 * Filtra el token de acceso de HubSpot.
		 *
		 * @param string $token Token.
		 */
		return (string) apply_filters( 'hef/access_token', $token );
	}

	public static function install_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Saneado del formulario de ajustes.
	 *
	 * @param array $input Datos crudos.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = self::all();

		$out['access_token']   = isset( $input['access_token'] ) ? trim( sanitize_text_field( $input['access_token'] ) ) : '';
		$out['auto_retry']     = empty( $input['auto_retry'] ) ? 0 : 1;
		$out['store_payload']  = empty( $input['store_payload'] ) ? 0 : 1;
		$out['show_error']     = empty( $input['show_error'] ) ? 0 : 1;
		$out['annotate_submission'] = empty( $input['annotate_submission'] ) ? 0 : 1;
		$out['max_attempts']   = isset( $input['max_attempts'] ) ? max( 1, min( 20, (int) $input['max_attempts'] ) ) : $defaults['max_attempts'];
		$out['retention_days'] = isset( $input['retention_days'] ) ? max( 0, (int) $input['retention_days'] ) : $defaults['retention_days'];
		$out['timeout']        = isset( $input['timeout'] ) ? max( 5, min( 60, (int) $input['timeout'] ) ) : $defaults['timeout'];

		return $out;
	}
}
