<?php
/**
 * Envíos que guarda Elementor.
 *
 * @package SWPagos
 */

namespace SWPagos\Elementor;

use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaza cada cobro con el envío que Elementor guardó de ese mismo formulario.
 *
 * Elementor no le pasa el identificador del envío a las acciones, así que hay
 * que buscarlo: el envío ya está guardado cuando corre nuestra acción, porque
 * «Collect Submissions» se registra antes que todo lo demás. Se busca por
 * elemento y por hora, que es lo mismo que hace el módulo de HubSpot; si no
 * cuadra, se prefiere no enlazar nada antes que enlazar el envío de otra
 * persona.
 */
final class Submissions {

	/**
	 * Margen de tiempo, en segundos, para dar por bueno un envío.
	 *
	 * @var int
	 */
	private const MARGEN = 300;

	/**
	 * Envío de ese formulario más cercano a esa hora.
	 *
	 * @param string $element_id  Elemento de Elementor, no el identificador lógico.
	 * @param string $momento_utc Hora en UTC, formato MySQL. Por omisión, ahora.
	 * @param int    $margen      Segundos de tolerancia.
	 * @return int Cero si no hay ninguno que cuadre.
	 */
	public static function cercano( string $element_id, string $momento_utc = '', int $margen = self::MARGEN ): int {
		global $wpdb;

		$element_id = trim( $element_id );

		if ( '' === $element_id || ! self::hay_tabla() ) {
			return 0;
		}

		$momento = '' !== $momento_utc ? $momento_utc : current_time( 'mysql', true );
		$marca   = strtotime( $momento . ' UTC' );

		if ( false === $marca ) {
			return 0;
		}

		$tabla = $wpdb->prefix . 'e_submissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tabla}
				  WHERE element_id = %s AND created_at BETWEEN %s AND %s
			   ORDER BY ABS( TIMESTAMPDIFF( SECOND, created_at, %s ) ) ASC, id DESC
				  LIMIT 1",
				$element_id,
				gmdate( 'Y-m-d H:i:s', $marca - $margen ),
				gmdate( 'Y-m-d H:i:s', $marca + $margen ),
				$momento
			)
		);
	}

	/**
	 * Envío de un cobro, buscándolo y guardándolo si todavía no lo tiene.
	 *
	 * Los cobros anteriores a esta función no lo llevan, y un fallo puntual al
	 * registrarlo tampoco debería dejarlo perdido para siempre: el envío sigue
	 * en su sitio y se puede volver a encontrar. Se guarda al encontrarlo para
	 * no repetir la búsqueda en cada visita al panel.
	 *
	 * @param Transaction $transaccion Cobro.
	 * @return int Cero si no hay envío que casar.
	 */
	public static function asegurar( Transaction $transaccion ): int {
		$guardado = (int) $transaccion->valor( 'submission_id', 0 );

		if ( $guardado > 0 ) {
			return $guardado;
		}

		$element_id = Form_Locator::element_id( (string) $transaccion->form_id() );

		if ( '' === $element_id ) {
			return 0;
		}

		$envio = self::cercano( $element_id, (string) $transaccion->valor( 'created_at', '' ) );

		if ( $envio > 0 ) {
			( new Transaction_Repository() )->actualizar( $transaccion, array( 'submission_id' => $envio ) );
		}

		return $envio;
	}

	/**
	 * Pantalla de Elementor donde se lee ese envío.
	 *
	 * @param int $id Envío.
	 * @return string
	 */
	public static function url( int $id ): string {
		return $id > 0 ? admin_url( 'admin.php?page=e-form-submissions' ) . '#/' . $id : '';
	}

	/**
	 * Si Elementor tiene su tabla de envíos creada.
	 *
	 * @return bool
	 */
	private static function hay_tabla(): bool {
		global $wpdb;

		static $hay = null;

		if ( null === $hay ) {
			$tabla = $wpdb->prefix . 'e_submissions';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$hay = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla ) );
		}

		return $hay;
	}
}
