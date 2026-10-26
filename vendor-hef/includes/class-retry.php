<?php
/**
 * Reintentos automáticos y manuales de los envíos fallidos.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Retry {

	const HOOK_RETRY = 'hef_retry_event';
	const HOOK_PURGE = 'hef_purge_event';
	const SCHEDULE   = 'hef_five_minutes';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK_RETRY, array( __CLASS__, 'run' ) );
		add_action( self::HOOK_PURGE, array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
	}

	/**
	 * @param array $schedules Programaciones.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Cada 5 minutos (HubSpot Elementor Form)', 'hubspot-elementor-form' ),
			);
		}
		return $schedules;
	}

	public static function schedule_events() {
		if ( ! wp_next_scheduled( self::HOOK_RETRY ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK_RETRY );
		}
		if ( ! wp_next_scheduled( self::HOOK_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_PURGE );
		}
	}

	public static function unschedule_events() {
		wp_clear_scheduled_hook( self::HOOK_RETRY );
		wp_clear_scheduled_hook( self::HOOK_PURGE );
	}

	/**
	 * Fecha del próximo reintento con backoff exponencial (2, 4, 8… min, máx 1 h).
	 *
	 * @param int $attempts Intentos realizados.
	 * @return string Fecha MySQL.
	 */
	public static function next_datetime( $attempts ) {
		$delay = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * pow( 2, max( 1, (int) $attempts ) ) );
		return gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $delay );
	}

	/**
	 * Cron: reintenta los envíos fallidos pendientes.
	 */
	public static function run() {
		if ( ! Settings::get( 'auto_retry' ) ) {
			return;
		}

		$max  = (int) Settings::get( 'max_attempts', 5 );
		$rows = Logger::get_retryable( $max, 20 );

		foreach ( $rows as $row ) {
			self::resend( $row, true );
		}
	}

	/**
	 * Reenvía un registro concreto.
	 *
	 * @param array|int $row  Registro o ID.
	 * @param bool      $auto Si viene del cron.
	 * @return array {success:bool, message:string}
	 */
	public static function resend( $row, $auto = false ) {
		if ( ! is_array( $row ) ) {
			$row = Logger::get( (int) $row );
		}

		if ( ! $row ) {
			return array(
				'success' => false,
				'message' => __( 'Registro no encontrado.', 'hubspot-elementor-form' ),
			);
		}

		$payload = json_decode( (string) $row['payload'], true );

		if ( ! is_array( $payload ) || empty( $payload['fields'] ) ) {
			$message = __( 'No se puede reintentar: el registro no conserva los datos enviados.', 'hubspot-elementor-form' );
			Logger::update(
				(int) $row['id'],
				array(
					'status'        => Logger::STATUS_ERROR,
					'message'       => $message,
					'next_retry_at' => null,
				)
			);
			return array(
				'success' => false,
				'message' => $message,
			);
		}

		$endpoint = $row['endpoint'];

		if ( ! $endpoint ) {
			$endpoint = Api::endpoint( $row['portal_id'], $row['hs_form_id'], false );
		}

		$secure = ( false !== strpos( $endpoint, '/secure/' ) );
		$token  = $secure ? Settings::access_token() : '';

		$result   = Api::submit( $endpoint, $payload, $token );
		$attempts = (int) $row['attempts'] + 1;

		$data = array(
			'status'        => $result['success'] ? Logger::STATUS_OK : Logger::STATUS_ERROR,
			'response_code' => (int) $result['code'],
			'response'      => mb_substr( (string) $result['body'], 0, 60000 ),
			'message'       => mb_substr( (string) $result['message'], 0, 1000 ),
			'attempts'      => $attempts,
			'endpoint'      => $endpoint,
			'next_retry_at' => null,
		);

		$max = (int) Settings::get( 'max_attempts', 5 );

		if ( ! $result['success'] && $result['retryable'] && Settings::get( 'auto_retry' ) && $attempts < $max ) {
			$data['next_retry_at'] = self::next_datetime( $attempts );
		}

		Logger::update( (int) $row['id'], $data );

		/**
		 * Se dispara tras un reintento.
		 *
		 * @param array $result Resultado.
		 * @param array $row    Registro original.
		 * @param bool  $auto   Si fue automático.
		 */
		do_action( 'hef/after_retry', $result, $row, $auto );

		return array(
			'success' => $result['success'],
			'message' => $result['message'],
		);
	}

	/**
	 * Cron diario: elimina registros correctos antiguos.
	 */
	public static function purge() {
		$days = (int) Settings::get( 'retention_days', 60 );
		if ( $days > 0 ) {
			Logger::purge( $days );
		}
	}
}
