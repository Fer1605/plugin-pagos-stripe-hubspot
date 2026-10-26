<?php
/**
 * Comprobación de que un envío registrado aquí existe de verdad en HubSpot.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Verifier {

	/**
	 * Margen de tiempo para dar por bueno un envío sin correo coincidente.
	 */
	const WINDOW = 900; // 15 minutos.

	/**
	 * Comprueba un registro contra los últimos envíos del formulario.
	 *
	 * @param array|int $row Registro o ID.
	 * @return array {found:bool, message:string, submission:array|null}
	 */
	public static function verify( $row ) {
		if ( ! is_array( $row ) ) {
			$row = Logger::get( (int) $row );
		}

		if ( ! $row ) {
			return self::result( false, __( 'Registro no encontrado.', 'hubspot-elementor-form' ) );
		}

		$results = Api::get_submissions( $row['hs_form_id'], Settings::access_token() );

		if ( is_wp_error( $results ) ) {
			return self::result( false, $results->get_error_message() );
		}

		if ( ! $results ) {
			return self::result(
				false,
				__( 'HubSpot no devuelve ningún envío para este formulario. Comprueba que el Form ID es el correcto.', 'hubspot-elementor-form' )
			);
		}

		$payload = json_decode( (string) $row['payload'], true );
		$email   = self::email_from_payload( $payload );

		// created_at está en la hora del sitio; se pasa a epoch UTC.
		$sent_at = (int) get_gmt_from_date( $row['created_at'], 'U' );

		$best = null;

		foreach ( $results as $submission ) {
			$when  = isset( $submission['submittedAt'] ) ? (int) round( $submission['submittedAt'] / 1000 ) : 0;
			$delta = abs( $when - $sent_at );
			$mail  = self::email_from_submission( $submission );

			$match = ( $email && $mail && strtolower( $email ) === strtolower( $mail ) && $delta < DAY_IN_SECONDS )
				|| ( $delta <= self::WINDOW );

			if ( $match && ( ! $best || $delta < $best['delta'] ) ) {
				$best = array(
					'delta'      => $delta,
					'when'       => $when,
					'submission' => $submission,
				);
			}
		}

		if ( $best ) {
			return self::result(
				true,
				sprintf(
					/* translators: 1: fecha del envío en HubSpot, 2: diferencia en segundos */
					__( 'Encontrado en HubSpot: registrado el %1$s (%2$d s de diferencia con el envío local).', 'hubspot-elementor-form' ),
					get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $best['when'] ), 'd/m/Y H:i:s' ),
					$best['delta']
				),
				$best['submission']
			);
		}

		$last = isset( $results[0]['submittedAt'] ) ? (int) round( $results[0]['submittedAt'] / 1000 ) : 0;

		return self::result(
			false,
			sprintf(
				/* translators: 1: número de envíos revisados, 2: fecha del último */
				__( 'No aparece entre los %1$d últimos envíos del formulario (el más reciente es del %2$s). HubSpot tarda unos minutos en indexar: vuelve a comprobarlo en un rato antes de darlo por perdido.', 'hubspot-elementor-form' ),
				count( $results ),
				$last ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $last ), 'd/m/Y H:i:s' ) : '—'
			)
		);
	}

	/**
	 * Correo enviado en el payload, si lo hubo.
	 *
	 * @param mixed $payload Payload.
	 * @return string
	 */
	protected static function email_from_payload( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['fields'] ) ) {
			return '';
		}

		foreach ( $payload['fields'] as $field ) {
			if ( isset( $field['name'] ) && 'email' === strtolower( $field['name'] ) ) {
				return isset( $field['value'] ) ? (string) $field['value'] : '';
			}
		}

		return '';
	}

	/**
	 * Correo de un envío devuelto por HubSpot.
	 *
	 * @param array $submission Envío.
	 * @return string
	 */
	protected static function email_from_submission( array $submission ) {
		if ( empty( $submission['values'] ) || ! is_array( $submission['values'] ) ) {
			return '';
		}

		foreach ( $submission['values'] as $value ) {
			if ( isset( $value['name'] ) && 'email' === strtolower( $value['name'] ) ) {
				return isset( $value['value'] ) ? (string) $value['value'] : '';
			}
		}

		return '';
	}

	/**
	 * @param bool       $found      Encontrado.
	 * @param string     $message    Mensaje.
	 * @param array|null $submission Envío coincidente.
	 * @return array
	 */
	protected static function result( $found, $message, $submission = null ) {
		return array(
			'found'      => (bool) $found,
			'message'    => $message,
			'submission' => $submission,
		);
	}
}
