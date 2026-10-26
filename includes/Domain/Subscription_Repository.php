<?php
/**
 * Persistencia de suscripciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

use SWPagos\Core\Activator;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Acceso a la tabla de suscripciones.
 */
final class Subscription_Repository {

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function tabla(): string {
		return Activator::tabla( 'subscriptions' );
	}

	/**
	 * Crea una suscripción, o devuelve la existente si ya estaba.
	 *
	 * El identificador de Stripe es único en la tabla: si el evento de alta llega
	 * dos veces, la segunda devuelve la misma fila en vez de duplicarla.
	 *
	 * @param array<string,mixed> $datos Datos.
	 * @return Subscription|null
	 */
	public function crear( array $datos ): ?Subscription {
		global $wpdb;

		$stripe_id = (string) ( $datos['stripe_subscription_id'] ?? '' );

		if ( '' === $stripe_id ) {
			return null;
		}

		$existente = $this->por_stripe_id( $stripe_id );

		if ( null !== $existente ) {
			return $existente;
		}

		$ahora = current_time( 'mysql' );

		$fila = array(
			'uuid'                   => wp_generate_uuid4(),
			'form_id'                => (string) ( $datos['form_id'] ?? '' ),
			'template_id'            => (int) ( $datos['template_id'] ?? 0 ) ?: null,
			'mode'                   => 'live' === ( $datos['mode'] ?? 'test' ) ? 'live' : 'test',
			'status'                 => Subscription::ACTIVA,
			'billing_interval'       => (string) ( $datos['interval'] ?? 'month' ),
			'amount_minor'           => max( 0, (int) ( $datos['amount_minor'] ?? 0 ) ),
			'currency'               => strtoupper( substr( (string) ( $datos['currency'] ?? 'USD' ), 0, 3 ) ),
			'stripe_subscription_id' => $stripe_id,
			'stripe_customer_id'     => (string) ( $datos['stripe_customer_id'] ?? '' ),
			'stripe_price_id'        => (string) ( $datos['stripe_price_id'] ?? '' ),
			'current_period_end'     => $datos['current_period_end'] ?? null,
			'customer_email'         => sanitize_email( (string) ( $datos['customer_email'] ?? '' ) ),
			'customer_name'          => sanitize_text_field( (string) ( $datos['customer_name'] ?? '' ) ),
			'locale'                 => (string) ( $datos['locale'] ?? get_locale() ),
			'utm'                    => (string) wp_json_encode( $datos['utm'] ?? array() ),
			'metadata'               => (string) wp_json_encode( $datos['metadata'] ?? array() ),
			'status_history'         => (string) wp_json_encode(
				array(
					array(
						'estado' => Subscription::ACTIVA,
						'cuando' => $ahora,
						'origen' => 'alta',
					),
				)
			),
			'created_at'             => $ahora,
			'updated_at'             => $ahora,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$insertado = $wpdb->insert( $this->tabla(), $fila );

		if ( ! $insertado ) {
			// Carrera: otra petición la creó entre la consulta y la inserción.
			$existente = $this->por_stripe_id( $stripe_id );

			if ( null !== $existente ) {
				return $existente;
			}

			Plugin::instancia()->logger()->error(
				'No se pudo crear la suscripción.',
				array(
					'stripe_id' => $stripe_id,
					'error'     => $wpdb->last_error,
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
	 * @return Subscription|null
	 */
	public function por_id( int $id ): ?Subscription {
		return $this->uno( 'id', $id, '%d' );
	}

	/**
	 * Busca por identificador público.
	 *
	 * @param string $uuid Identificador.
	 * @return Subscription|null
	 */
	public function por_uuid( string $uuid ): ?Subscription {
		return $this->uno( 'uuid', $uuid, '%s' );
	}

	/**
	 * Busca por identificador de Stripe.
	 *
	 * @param string $stripe_id Identificador.
	 * @return Subscription|null
	 */
	public function por_stripe_id( string $stripe_id ): ?Subscription {
		return $this->uno( 'stripe_subscription_id', $stripe_id, '%s' );
	}

	/**
	 * Cambia el estado.
	 *
	 * @param Subscription        $suscripcion Suscripción.
	 * @param string              $nuevo       Estado destino.
	 * @param array<string,mixed> $cambios     Columnas adicionales.
	 * @param string              $origen      Quién lo provoca.
	 * @return bool
	 */
	public function transicionar( Subscription $suscripcion, string $nuevo, array $cambios = array(), string $origen = 'sistema' ): bool {
		$actual = $suscripcion->estado();

		if ( ! Subscription::transicion_valida( $actual, $nuevo ) ) {
			Plugin::instancia()->logger()->warning(
				'Transición de suscripción no permitida; se descarta.',
				array(
					'suscripcion' => $suscripcion->id(),
					'desde'       => $actual,
					'hacia'       => $nuevo,
				)
			);

			return false;
		}

		return $this->actualizar( $suscripcion, array_merge( $cambios, array( 'status' => $nuevo ) ), $origen );
	}

	/**
	 * Escribe columnas sin cambiar el estado.
	 *
	 * @param Subscription        $suscripcion Suscripción.
	 * @param array<string,mixed> $cambios     Columnas.
	 * @param string              $origen      Origen del cambio.
	 * @return bool
	 */
	public function actualizar( Subscription $suscripcion, array $cambios, string $origen = 'sistema' ): bool {
		global $wpdb;

		if ( empty( $cambios ) ) {
			return true;
		}

		$cambios['updated_at'] = current_time( 'mysql' );

		if ( isset( $cambios['status'] ) ) {
			$historial   = $suscripcion->historial();
			$historial[] = array(
				'estado' => $cambios['status'],
				'cuando' => $cambios['updated_at'],
				'origen' => $origen,
			);
			$cambios['status_history'] = (string) wp_json_encode( $historial );

			if ( Subscription::CANCELADA === $cambios['status'] && empty( $cambios['canceled_at'] ) ) {
				$cambios['canceled_at'] = $cambios['updated_at'];
			}
		}

		foreach ( array( 'utm', 'metadata' ) as $clave ) {
			if ( isset( $cambios[ $clave ] ) && is_array( $cambios[ $clave ] ) ) {
				$cambios[ $clave ] = (string) wp_json_encode( $cambios[ $clave ] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $this->tabla(), $cambios, array( 'id' => $suscripcion->id() ) );
	}

	/**
	 * Suscripciones para el panel.
	 *
	 * @param array<string,mixed> $filtros Filtros.
	 * @param int                 $limite  Máximo.
	 * @param int                 $desplazamiento Desplazamiento.
	 * @return Subscription[]
	 */
	public function buscar( array $filtros = array(), int $limite = 50, int $desplazamiento = 0 ): array {
		global $wpdb;

		list( $donde, $params ) = $this->condiciones( $filtros );

		$params[] = max( 1, min( 500, $limite ) );
		$params[] = max( 0, $desplazamiento );

		$sql = 'SELECT * FROM ' . $this->tabla() . ' WHERE ' . $donde . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$filas = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( static fn( array $f ): Subscription => new Subscription( $f ), $filas );
	}

	/**
	 * Cuántas suscripciones casan con el filtro.
	 *
	 * @param array<string,mixed> $filtros Filtros.
	 * @return int
	 */
	public function contar( array $filtros = array() ): int {
		global $wpdb;

		list( $donde, $params ) = $this->condiciones( $filtros );

		$sql = 'SELECT COUNT(*) FROM ' . $this->tabla() . ' WHERE ' . $donde;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) ( array() === $params ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Recuento por estado, para los enlaces del listado.
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
	 * WHERE compartido por buscar(), contar() y los recuentos.
	 *
	 * @param array<string,mixed> $filtros Filtros.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function condiciones( array $filtros ): array {
		global $wpdb;

		$donde  = array( '1=1' );
		$params = array();

		foreach ( array( 'estado' => 'status', 'modo' => 'mode', 'form_id' => 'form_id' ) as $clave => $columna ) {
			if ( ! empty( $filtros[ $clave ] ) ) {
				$donde[]  = "{$columna} = %s";
				$params[] = (string) $filtros[ $clave ];
			}
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
	 * Métricas de recurrentes para los informes.
	 *
	 * @param string $desde Fecha inicial.
	 * @param string $hasta Fecha final.
	 * @param string $modo  Modo.
	 * @return array{activas:int,impago:int,canceladas_periodo:int,altas_periodo:int,recurrente_mensual:int,moneda:string}
	 */
	/**
	 * Cuántas suscripciones hay en un modo, en cualquier estado.
	 *
	 * Sirve para no enseñar un resumen de producción en blanco cuando todo lo
	 * que existe está en prueba: un cero sin contexto parece un fallo.
	 *
	 * @param string $modo live|test.
	 * @return int
	 */
	public function total_por_modo( string $modo ): int {
		global $wpdb;

		$tabla = $this->tabla();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE mode = %s", $modo )
		);
	}

	/**
	 * Métricas del período para un modo.
	 *
	 * @param string $desde Desde.
	 * @param string $hasta Hasta.
	 * @param string $modo  live|test.
	 * @return array<string,mixed>
	 */
	public function metricas( string $desde, string $hasta, string $modo = 'live' ): array {
		global $wpdb;

		$tabla = $this->tabla();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$activas = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(amount_minor),0) AS mrr, MAX(currency) AS moneda
				 FROM {$tabla} WHERE status = %s AND mode = %s",
				Subscription::ACTIVA,
				$modo
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$impago = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE status = %s AND mode = %s", Subscription::IMPAGO, $modo )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$canceladas = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tabla} WHERE status = %s AND mode = %s AND canceled_at >= %s AND canceled_at <= %s",
				Subscription::CANCELADA,
				$modo,
				$desde,
				$hasta
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$altas = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tabla} WHERE mode = %s AND created_at >= %s AND created_at <= %s",
				$modo,
				$desde,
				$hasta
			)
		);

		return array(
			'activas'            => (int) ( $activas['n'] ?? 0 ),
			'impago'             => $impago,
			'canceladas_periodo' => $canceladas,
			'altas_periodo'      => $altas,
			'recurrente_mensual' => (int) ( $activas['mrr'] ?? 0 ),
			'moneda'             => (string) ( $activas['moneda'] ?? 'USD' ),
		);
	}

	/**
	 * Lectura de una fila.
	 *
	 * @param string $columna Columna.
	 * @param mixed  $valor   Valor.
	 * @param string $formato Formato.
	 * @return Subscription|null
	 */
	private function uno( string $columna, $valor, string $formato ): ?Subscription {
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

		return is_array( $fila ) ? new Subscription( $fila ) : null;
	}
}
