<?php
/**
 * Bitácora de entregas externas.
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

use SWPagos\Core\Activator;
use SWPagos\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Cada intento de envío externo deja una fila.
 *
 * Un reintento **inserta** una fila nueva; el historial no se sobrescribe nunca
 *. Es lo que permite reconstruir después qué pasó y en qué orden, que es
 * justo lo que se necesita cuando alguien reclama un correo que no le llegó.
 */
final class Delivery_Log {

	public const CANAL_EMAIL         = 'email';
	public const CANAL_HUBSPOT_FORMS = 'hubspot_forms';
	public const CANAL_HUBSPOT_CRM   = 'hubspot_crm';
	public const CANAL_REPORTE       = 'report';
	public const CANAL_ALERTA        = 'alert';

	public const EXITO     = 'success';
	public const FALLO     = 'failed';
	public const PENDIENTE = 'pending';

	/**
	 * Canales admitidos.
	 *
	 * @var string[]
	 */
	private const CANALES = array(
		self::CANAL_EMAIL,
		self::CANAL_HUBSPOT_FORMS,
		self::CANAL_HUBSPOT_CRM,
		self::CANAL_REPORTE,
		self::CANAL_ALERTA,
	);

	/**
	 * Estados admitidos.
	 *
	 * @var string[]
	 */
	private const ESTADOS = array( self::EXITO, self::FALLO, self::PENDIENTE );

	/**
	 * Tamaño máximo del cuerpo guardado.
	 *
	 * @var int
	 */
	private const MAX_PAYLOAD = 8000;

	/**
	 * Registra un intento de entrega.
	 *
	 * @param array<string,mixed> $datos Datos del intento.
	 * @return int Identificador de la fila, o cero si no se pudo insertar.
	 */
	public static function registrar( array $datos ): int {
		global $wpdb;

		$canal  = (string) ( $datos['canal'] ?? self::CANAL_EMAIL );
		$estado = (string) ( $datos['estado'] ?? self::FALLO );

		$fila = array(
			'transaction_id'  => self::id_o_null( $datos['transaccion'] ?? null ),
			'subscription_id' => self::id_o_null( $datos['suscripcion'] ?? null ),
			'submission_id'   => self::id_o_null( $datos['envio'] ?? null ),
			'form_id'         => sanitize_text_field( (string) ( $datos['form_id'] ?? '' ) ),
			'channel'         => in_array( $canal, self::CANALES, true ) ? $canal : self::CANAL_EMAIL,
			'email_kind'      => isset( $datos['tipo_correo'] ) ? sanitize_text_field( (string) $datos['tipo_correo'] ) : null,
			'target'          => mb_substr( sanitize_text_field( (string) ( $datos['destino'] ?? '' ) ), 0, 191 ),
			'status'          => in_array( $estado, self::ESTADOS, true ) ? $estado : self::FALLO,
			'attempt'         => max( 1, (int) ( $datos['intento'] ?? 1 ) ),
			'response_code'   => isset( $datos['codigo'] ) ? sanitize_text_field( (string) $datos['codigo'] ) : null,
			'message'         => Logger::redactar_texto( (string) ( $datos['mensaje'] ?? '' ) ),
			'payload'         => self::cuerpo( $datos['cuerpo'] ?? null ),
			'created_at'      => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$insertado = $wpdb->insert( Activator::tabla( 'delivery_logs' ), $fila );

		return $insertado ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Número de intentos ya registrados para una entrega concreta.
	 *
	 * @param string   $canal       Canal.
	 * @param int|null $transaccion Transacción.
	 * @param int|null $envio       Envío de Elementor.
	 * @return int
	 */
	public static function intentos( string $canal, ?int $transaccion = null, ?int $envio = null ): int {
		global $wpdb;

		$tabla = Activator::tabla( 'delivery_logs' );

		if ( null !== $transaccion && $transaccion > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tabla} WHERE channel = %s AND transaction_id = %d",
					$canal,
					$transaccion
				)
			);
		}

		if ( null !== $envio && $envio > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tabla} WHERE channel = %s AND submission_id = %d",
					$canal,
					$envio
				)
			);
		}

		return 0;
	}

	/**
	 * Últimas entregas, para la pantalla de bitácoras.
	 *
	 * @param array<string,mixed> $filtros Filtros admitidos: canal, estado, form_id.
	 * @param int                 $limite  Máximo de filas.
	 * @return array<int,object>
	 */
	public static function ultimas( array $filtros = array(), int $limite = 50, int $desplazamiento = 0 ): array {
		global $wpdb;

		list( $donde, $params ) = self::condiciones( $filtros );

		$params[] = max( 1, min( 500, $limite ) );
		$params[] = max( 0, $desplazamiento );

		$sql = 'SELECT * FROM ' . Activator::tabla( 'delivery_logs' ) . ' WHERE ' . $donde
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Cuántas entregas casan con el filtro.
	 *
	 * @param array<string,mixed> $filtros Filtros.
	 * @return int
	 */
	public static function contar( array $filtros = array() ): int {
		global $wpdb;

		list( $donde, $params ) = self::condiciones( $filtros );

		$sql = 'SELECT COUNT(*) FROM ' . Activator::tabla( 'delivery_logs' ) . ' WHERE ' . $donde;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (int) ( array() === $params ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Recuento por estado, para los enlaces del listado.
	 *
	 * @param array<string,mixed> $filtros Filtros sin el estado.
	 * @return array<string,int>
	 */
	public static function cuentas_por_estado( array $filtros = array() ): array {
		global $wpdb;

		unset( $filtros['estado'] );

		list( $donde, $params ) = self::condiciones( $filtros );

		$sql = 'SELECT status, COUNT(*) AS n FROM ' . Activator::tabla( 'delivery_logs' ) . ' WHERE ' . $donde . ' GROUP BY status';

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
	 * WHERE compartido por el listado y los recuentos.
	 *
	 * @param array<string,mixed> $filtros Filtros.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function condiciones( array $filtros ): array {
		$donde  = array( '1=1' );
		$params = array();

		foreach ( array( 'canal' => 'channel', 'estado' => 'status', 'form_id' => 'form_id' ) as $clave => $columna ) {
			if ( ! empty( $filtros[ $clave ] ) ) {
				$donde[]  = "{$columna} = %s";
				$params[] = (string) $filtros[ $clave ];
			}
		}

		return array( implode( ' AND ', $donde ), $params );
	}

	/**
	 * Normaliza un identificador opcional.
	 *
	 * @param mixed $valor Valor.
	 * @return int|null
	 */
	private static function id_o_null( $valor ): ?int {
		$id = (int) $valor;
		return $id > 0 ? $id : null;
	}

	/**
	 * Prepara el cuerpo guardado: redactado y acotado.
	 *
	 * @param mixed $cuerpo Cuerpo.
	 * @return string|null
	 */
	private static function cuerpo( $cuerpo ): ?string {
		if ( null === $cuerpo || '' === $cuerpo ) {
			return null;
		}

		$texto = is_scalar( $cuerpo )
			? (string) $cuerpo
			: (string) wp_json_encode( Logger::redactar( $cuerpo ) );

		$texto = Logger::redactar_texto( $texto );

		return mb_strlen( $texto ) > self::MAX_PAYLOAD
			? mb_substr( $texto, 0, self::MAX_PAYLOAD ) . '…[truncado]'
			: $texto;
	}
}
