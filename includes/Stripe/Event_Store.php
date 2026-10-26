<?php
/**
 * Almacén de eventos de la pasarela.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Garantiza que cada evento se procese una sola vez.
 *
 * Stripe reenvía los eventos cuando no recibe una respuesta satisfactoria, así
 * que el mismo evento puede llegar varias veces. La deduplicación no se hace con
 * una consulta previa —entre la consulta y la inserción cabe otra petición—, sino
 * con el índice único de la columna del identificador: la base de datos decide,
 * y solo una petición gana.
 */
final class Event_Store {

	/**
	 * Intenta reservar el procesamiento de un evento.
	 *
	 * @param Verified_Event $evento Evento verificado.
	 * @return bool Verdadero si esta petición es la que debe procesarlo.
	 */
	public function reclamar( Verified_Event $evento ): bool {
		global $wpdb;

		$tabla = Activator::tabla( 'gateway_events' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$filas = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tabla} (event_id, type, payload, received_at) VALUES (%s, %s, %s, %s)",
				$evento->event_id(),
				$evento->tipo(),
				(string) wp_json_encode( $evento->para_bitacora() ),
				current_time( 'mysql' )
			)
		);

		return 1 === (int) $filas;
	}

	/**
	 * Marca un evento como procesado y lo liga a lo que tocó.
	 *
	 * @param string   $event_id    Identificador del evento.
	 * @param int|null $transaccion Transacción afectada.
	 * @param int|null $suscripcion Suscripción afectada.
	 * @return void
	 */
	public function marcar_procesado( string $event_id, ?int $transaccion = null, ?int $suscripcion = null ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Activator::tabla( 'gateway_events' ),
			array(
				'processed_at'    => current_time( 'mysql' ),
				'transaction_id'  => $transaccion > 0 ? $transaccion : null,
				'subscription_id' => $suscripcion > 0 ? $suscripcion : null,
			),
			array( 'event_id' => $event_id )
		);
	}

	/**
	 * Eventos recibidos pero nunca procesados.
	 *
	 * Lo usa el diagnóstico: un evento reclamado que nunca se marcó como procesado
	 * señala una petición que murió a mitad.
	 *
	 * @param int $limite Máximo.
	 * @return array<int,object>
	 */
	public function sin_procesar( int $limite = 20 ): array {
		global $wpdb;

		$tabla = Activator::tabla( 'gateway_events' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE processed_at IS NULL ORDER BY id DESC LIMIT %d",
				max( 1, min( 100, $limite ) )
			)
		);
	}

	/**
	 * Cuenta los eventos recibidos.
	 *
	 * @return int
	 */
	public function total(): int {
		global $wpdb;

		$tabla = Activator::tabla( 'gateway_events' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla}" );
	}
}
