<?php
/**
 * Persistencia de transacciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

use SWPagos\Core\Activator;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Acceso a la tabla de transacciones.
 *
 * Es el único sitio que escribe estados, y el que impone la regla principal: la
 * transición a `pagada` exige una prueba de evento verificado. Sin ella el método
 * se niega y deja constancia.
 */
final class Transaction_Repository {

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function tabla(): string {
		return Activator::tabla( 'transactions' );
	}

	/**
	 * Crea una transacción en estado inicial.
	 *
	 * @param array<string,mixed> $datos Datos.
	 * @return Transaction|null
	 */
	public function crear( array $datos ): ?Transaction {
		global $wpdb;

		$ahora = current_time( 'mysql' );

		$fila = array(
			'uuid'            => wp_generate_uuid4(),
			'subscription_id' => $this->opcional( $datos['subscription_id'] ?? null ),
			'billing_period'  => isset( $datos['billing_period'] ) ? substr( (string) $datos['billing_period'], 0, 7 ) : null,
			'submission_id'   => $this->opcional( $datos['submission_id'] ?? null ),
			'form_id'         => (string) ( $datos['form_id'] ?? '' ),
			'template_id'     => $this->opcional( $datos['template_id'] ?? null ),
			'gateway'         => (string) ( $datos['gateway'] ?? 'stripe' ),
			'mode'            => 'live' === ( $datos['mode'] ?? 'test' ) ? 'live' : 'test',
			'kind'            => (string) ( $datos['kind'] ?? Transaction::TIPO_UNICO ),
			'status'          => Transaction::INICIADA,
			'amount_minor'    => max( 0, (int) ( $datos['amount_minor'] ?? 0 ) ),
			'currency'        => strtoupper( substr( (string) ( $datos['currency'] ?? 'USD' ), 0, 3 ) ),
			'idempotency_key' => (string) ( $datos['idempotency_key'] ?? wp_generate_uuid4() ),
			'customer_email'  => sanitize_email( (string) ( $datos['customer_email'] ?? '' ) ),
			'customer_name'   => sanitize_text_field( (string) ( $datos['customer_name'] ?? '' ) ),
			'locale'          => (string) ( $datos['locale'] ?? get_locale() ),
			'utm'             => $this->json( $datos['utm'] ?? array() ),
			'metadata'        => $this->json( $datos['metadata'] ?? array() ),
			'status_history'  => $this->json(
				array(
					array(
						'estado' => Transaction::INICIADA,
						'cuando' => $ahora,
						'origen' => (string) ( $datos['origen'] ?? 'formulario' ),
					),
				)
			),
			'created_at'      => $ahora,
			'updated_at'      => $ahora,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$insertado = $wpdb->insert( $this->tabla(), $fila );

		if ( ! $insertado ) {
			Plugin::instancia()->logger()->error(
				'No se pudo crear la transacción.',
				array(
					'form_id' => $fila['form_id'],
					'error'   => $wpdb->last_error,
				)
			);

			return null;
		}

		return $this->por_id( (int) $wpdb->insert_id );
	}

	/**
	 * Busca por identificador interno.
	 *
	 * @param int $id Identificador.
	 * @return Transaction|null
	 */
	public function por_id( int $id ): ?Transaction {
		return $this->uno( 'id', $id, '%d' );
	}

	/**
	 * Busca por identificador público.
	 *
	 * @param string $uuid Identificador público.
	 * @return Transaction|null
	 */
	public function por_uuid( string $uuid ): ?Transaction {
		return $this->uno( 'uuid', $uuid, '%s' );
	}

	/**
	 * Busca por sesión de pago.
	 *
	 * @param string $session_id Sesión.
	 * @return Transaction|null
	 */
	public function por_session( string $session_id ): ?Transaction {
		return $this->uno( 'stripe_session_id', $session_id, '%s' );
	}

	/**
	 * Busca por intención de pago.
	 *
	 * @param string $pi Intención de pago.
	 * @return Transaction|null
	 */
	public function por_payment_intent( string $pi ): ?Transaction {
		return $this->uno( 'stripe_payment_intent_id', $pi, '%s' );
	}

	/**
	 * Busca por factura.
	 *
	 * @param string $invoice Factura.
	 * @return Transaction|null
	 */
	public function por_invoice( string $invoice ): ?Transaction {
		return $this->uno( 'stripe_invoice_id', $invoice, '%s' );
	}

	/**
	 * Busca por clave de idempotencia.
	 *
	 * @param string $clave Clave.
	 * @return Transaction|null
	 */
	public function por_idempotencia( string $clave ): ?Transaction {
		return $this->uno( 'idempotency_key', $clave, '%s' );
	}

	/**
	 * Busca el cobro de un período concreto de una suscripción.
	 *
	 * @param int    $suscripcion Suscripción.
	 * @param string $periodo     Período, formato AAAA-MM.
	 * @return Transaction|null
	 */
	public function por_periodo( int $suscripcion, string $periodo ): ?Transaction {
		global $wpdb;

		$tabla = $this->tabla();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$fila = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE subscription_id = %d AND billing_period = %s LIMIT 1",
				$suscripcion,
				$periodo
			),
			ARRAY_A
		);

		return is_array( $fila ) ? new Transaction( $fila ) : null;
	}

	/**
	 * Cambia el estado de una transacción.
	 *
	 * @param Transaction        $transaccion Transacción.
	 * @param string             $nuevo       Estado destino.
	 * @param array<string,mixed> $cambios    Columnas adicionales a escribir.
	 * @param Payment_Proof|null $prueba      Prueba de evento verificado.
	 * @return bool
	 */
	public function transicionar( Transaction $transaccion, string $nuevo, array $cambios = array(), ?Payment_Proof $prueba = null ): bool {
		$actual = $transaccion->estado();

		if ( Transaction::PAGADA === $nuevo && null === $prueba ) {
			// Si esto se dispara es que alguien intentó confirmar un
			// cobro sin un evento firmado detrás.
			Plugin::instancia()->logger()->error(
				'Intento de marcar una transacción como pagada sin prueba de evento verificado. Rechazado.',
				array(
					'transaccion' => $transaccion->id(),
					'desde'       => $actual,
				)
			);

			return false;
		}

		if ( ! Transaction::transicion_valida( $actual, $nuevo ) ) {
			Plugin::instancia()->logger()->warning(
				'Transición de estado no permitida; se descarta.',
				array(
					'transaccion' => $transaccion->id(),
					'desde'       => $actual,
					'hacia'       => $nuevo,
					'evento'      => null !== $prueba ? $prueba->event_id() : '',
				)
			);

			return false;
		}

		return $this->actualizar(
			$transaccion,
			array_merge( $cambios, array( 'status' => $nuevo ) ),
			null !== $prueba ? $prueba->tipo() : 'sistema'
		);
	}

	/**
	 * Escribe columnas de una transacción sin cambiar su estado.
	 *
	 * @param Transaction        $transaccion Transacción.
	 * @param array<string,mixed> $cambios    Columnas.
	 * @param string             $origen      Quién provoca el cambio.
	 * @return bool
	 */
	public function actualizar( Transaction $transaccion, array $cambios, string $origen = 'sistema' ): bool {
		global $wpdb;

		if ( empty( $cambios ) ) {
			return true;
		}

		$cambios['updated_at'] = current_time( 'mysql' );

		if ( isset( $cambios['status'] ) ) {
			$historial   = $transaccion->historial();
			$historial[] = array(
				'estado' => $cambios['status'],
				'cuando' => $cambios['updated_at'],
				'origen' => $origen,
			);
			$cambios['status_history'] = $this->json( $historial );

			if ( Transaction::PAGADA === $cambios['status'] && empty( $cambios['paid_at'] ) ) {
				$cambios['paid_at'] = $cambios['updated_at'];
			}
		}

		foreach ( array( 'utm', 'metadata' ) as $clave ) {
			if ( isset( $cambios[ $clave ] ) && is_array( $cambios[ $clave ] ) ) {
				$cambios[ $clave ] = $this->json( $cambios[ $clave ] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$filas = $wpdb->update( $this->tabla(), $cambios, array( 'id' => $transaccion->id() ) );

		return false !== $filas;
	}

	/**
	 * Transacciones para el panel.
	 *
	 * @param array<string,mixed> $filtros Filtros: estado, modo, form_id, desde, hasta, buscar.
	 * @param int                 $limite  Máximo.
	 * @param int                 $desplazamiento Desplazamiento.
	 * @return Transaction[]
	 */
	public function buscar( array $filtros = array(), int $limite = 50, int $desplazamiento = 0 ): array {
		global $wpdb;

		list( $donde, $params ) = $this->condiciones( $filtros );

		$params[] = max( 1, min( 500, $limite ) );
		$params[] = max( 0, $desplazamiento );

		$sql = 'SELECT * FROM ' . $this->tabla() . ' WHERE ' . $donde
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$filas = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( static fn( array $f ): Transaction => new Transaction( $f ), $filas );
	}

	/**
	 * Cuántas transacciones casan con el filtro.
	 *
	 * Lo necesita la paginación: sin el total, el listado no sabe cuántas
	 * páginas hay y solo puede ofrecer «siguiente» a ciegas.
	 *
	 * @param array<string,mixed> $filtros Filtros, los mismos que buscar().
	 * @return int
	 */
	public function contar( array $filtros = array() ): int {
		global $wpdb;

		list( $donde, $params ) = $this->condiciones( $filtros );

		$sql = 'SELECT COUNT(*) FROM ' . $this->tabla() . ' WHERE ' . $donde;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) ( array() === $params
			? $wpdb->get_var( $sql )
			: $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Cuántas transacciones hay por estado, para los enlaces del listado.
	 *
	 * @param array<string,mixed> $filtros Filtros sin el estado.
	 * @return array<string,int>
	 */
	public function cuentas_por_estado( array $filtros = array() ): array {
		global $wpdb;

		unset( $filtros['estado'] );

		list( $donde, $params ) = $this->condiciones( $filtros );

		$sql = 'SELECT status, COUNT(*) AS n FROM ' . $this->tabla() . ' WHERE ' . $donde . ' GROUP BY status';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$filas = (array) ( array() === $params
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) );

		$cuentas = array();

		foreach ( $filas as $fila ) {
			$cuentas[ (string) $fila['status'] ] = (int) $fila['n'];
		}

		return $cuentas;
	}

	/**
	 * Construye el WHERE compartido por buscar(), contar() y los recuentos.
	 *
	 * @param array<string,mixed> $filtros Filtros.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function condiciones( array $filtros ): array {
		global $wpdb;

		$donde  = array( '1=1' );
		$params = array();

		$mapa = array(
			'estado'  => 'status',
			'modo'    => 'mode',
			'form_id' => 'form_id',
			'tipo'    => 'kind',
		);

		foreach ( $mapa as $clave => $columna ) {
			if ( ! empty( $filtros[ $clave ] ) ) {
				$donde[]  = "{$columna} = %s";
				$params[] = (string) $filtros[ $clave ];
			}
		}

		if ( ! empty( $filtros['desde'] ) ) {
			$donde[]  = 'created_at >= %s';
			$params[] = (string) $filtros['desde'];
		}

		if ( ! empty( $filtros['hasta'] ) ) {
			$donde[]  = 'created_at <= %s';
			$params[] = (string) $filtros['hasta'];
		}

		if ( ! empty( $filtros['buscar'] ) ) {
			$busqueda = '%' . $wpdb->esc_like( (string) $filtros['buscar'] ) . '%';
			$donde[]  = '(customer_email LIKE %s OR customer_name LIKE %s OR uuid LIKE %s)';
			$params[] = $busqueda;
			$params[] = $busqueda;
			$params[] = $busqueda;
		}

		return array( implode( ' AND ', $donde ), $params );
	}

	/**
	 * Totales por estado en un período, para los informes.
	 *
	 * @param string $desde Fecha inicial.
	 * @param string $hasta Fecha final.
	 * @param string $modo  Modo.
	 * @return array<string,array{cuenta:int,importe:int,moneda:string}>
	 */
	public function totales( string $desde, string $hasta, string $modo = 'live' ): array {
		global $wpdb;

		$tabla = $this->tabla();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$filas = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, currency, COUNT(*) AS cuenta, SUM(amount_minor) AS importe
				 FROM {$tabla}
				 WHERE created_at >= %s AND created_at <= %s AND mode = %s
				 GROUP BY status, currency",
				$desde,
				$hasta,
				$modo
			),
			ARRAY_A
		);

		$totales = array();

		foreach ( $filas as $fila ) {
			$totales[ (string) $fila['status'] ] = array(
				'cuenta'  => (int) $fila['cuenta'],
				'importe' => (int) $fila['importe'],
				'moneda'  => (string) $fila['currency'],
			);
		}

		return $totales;
	}

	/**
	 * Lectura de una sola fila por columna.
	 *
	 * @param string $columna Columna.
	 * @param mixed  $valor   Valor.
	 * @param string $formato Formato de wpdb.
	 * @return Transaction|null
	 */
	private function uno( string $columna, $valor, string $formato ): ?Transaction {
		global $wpdb;

		if ( '' === $valor || null === $valor ) {
			return null;
		}

		$tabla = $this->tabla();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$fila = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tabla} WHERE {$columna} = {$formato} LIMIT 1", $valor ),
			ARRAY_A
		);

		return is_array( $fila ) ? new Transaction( $fila ) : null;
	}

	/**
	 * Identificador opcional.
	 *
	 * @param mixed $valor Valor.
	 * @return int|null
	 */
	private function opcional( $valor ): ?int {
		$id = (int) $valor;
		return $id > 0 ? $id : null;
	}

	/**
	 * Serializa a JSON.
	 *
	 * @param mixed $valor Valor.
	 * @return string
	 */
	private function json( $valor ): string {
		return (string) wp_json_encode( $valor );
	}
}
