<?php
/**
 * Entidad «transacción».
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

use SWPagos\Core\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Registro autoritativo de un cobro y su máquina de estados.
 *
 * El estado `pagada` solo puede escribirlo el manejador de eventos verificados
 *. Esta clase define qué transiciones existen; quién puede
 * provocarlas lo impone el repositorio, que exige una prueba de evento firmado.
 */
final class Transaction {

	public const INICIADA    = 'iniciada';
	public const RECHAZADA   = 'rechazada';
	public const PENDIENTE   = 'pendiente';
	public const PROCESANDO  = 'procesando';
	public const PAGADA      = 'pagada';
	public const FALLIDA     = 'fallida';
	public const CANCELADA   = 'cancelada';
	public const REEMBOLSADA = 'reembolsada';

	public const TIPO_UNICO       = 'single';
	public const TIPO_ALTA_SUSC   = 'subscription_initial';
	public const TIPO_RENOVACION  = 'subscription_renewal';

	/**
	 * Transiciones permitidas. Solo se avanza; nunca se retrocede.
	 *
	 * @var array<string,string[]>
	 */
	private const TRANSICIONES = array(
		self::INICIADA    => array( self::RECHAZADA, self::PENDIENTE ),
		self::PENDIENTE   => array( self::PROCESANDO, self::PAGADA, self::FALLIDA, self::CANCELADA ),
		self::PROCESANDO  => array( self::PAGADA, self::FALLIDA ),
		self::PAGADA      => array( self::REEMBOLSADA ),
		// Estados terminales.
		self::RECHAZADA   => array(),
		self::FALLIDA     => array(),
		self::CANCELADA   => array(),
		self::REEMBOLSADA => array(),
	);

	/**
	 * Datos de la fila.
	 *
	 * @var array<string,mixed>
	 */
	private array $datos;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $datos Datos de la fila.
	 */
	public function __construct( array $datos = array() ) {
		$this->datos = $datos;
	}

	/**
	 * Estados que existen.
	 *
	 * @return string[]
	 */
	public static function estados(): array {
		return array_keys( self::TRANSICIONES );
	}

	/**
	 * Indica si una transición está permitida.
	 *
	 * @param string $desde Estado de origen.
	 * @param string $hacia Estado de destino.
	 * @return bool
	 */
	public static function transicion_valida( string $desde, string $hacia ): bool {
		if ( $desde === $hacia ) {
			return false;
		}

		return in_array( $hacia, self::TRANSICIONES[ $desde ] ?? array(), true );
	}

	/**
	 * Indica si un estado es final.
	 *
	 * @param string $estado Estado.
	 * @return bool
	 */
	public static function es_terminal( string $estado ): bool {
		return empty( self::TRANSICIONES[ $estado ] ?? array() );
	}

	/**
	 * Identificador interno.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) ( $this->datos['id'] ?? 0 );
	}

	/**
	 * Identificador público, el que viaja en las URL.
	 *
	 * @return string
	 */
	public function uuid(): string {
		return (string) ( $this->datos['uuid'] ?? '' );
	}

	/**
	 * Estado actual.
	 *
	 * @return string
	 */
	public function estado(): string {
		return (string) ( $this->datos['status'] ?? self::INICIADA );
	}

	/**
	 * Indica si el cobro está confirmado.
	 *
	 * @return bool
	 */
	public function esta_pagada(): bool {
		return self::PAGADA === $this->estado() || self::REEMBOLSADA === $this->estado();
	}

	/**
	 * Importe en unidad mínima.
	 *
	 * @return int
	 */
	public function importe(): int {
		return (int) ( $this->datos['amount_minor'] ?? 0 );
	}

	/**
	 * Moneda.
	 *
	 * @return string
	 */
	public function moneda(): string {
		return (string) ( $this->datos['currency'] ?? 'USD' );
	}

	/**
	 * Importe legible.
	 *
	 * @return string
	 */
	public function importe_legible(): string {
		return Currency::formatear( $this->importe(), $this->moneda() );
	}

	/**
	 * Modo: test o live.
	 *
	 * @return string
	 */
	public function modo(): string {
		return (string) ( $this->datos['mode'] ?? 'test' );
	}

	/**
	 * Tipo de cobro.
	 *
	 * @return string
	 */
	public function tipo(): string {
		return (string) ( $this->datos['kind'] ?? self::TIPO_UNICO );
	}

	/**
	 * Identificador lógico del formulario.
	 *
	 * @return string
	 */
	public function form_id(): string {
		return (string) ( $this->datos['form_id'] ?? '' );
	}

	/**
	 * Plantilla asociada.
	 *
	 * @return int
	 */
	public function template_id(): int {
		return (int) ( $this->datos['template_id'] ?? 0 );
	}

	/**
	 * Suscripción de la que proviene este cobro, si la hay.
	 *
	 * @return int
	 */
	public function subscription_id(): int {
		return (int) ( $this->datos['subscription_id'] ?? 0 );
	}

	/**
	 * Correo de quien paga.
	 *
	 * @return string
	 */
	public function email(): string {
		return (string) ( $this->datos['customer_email'] ?? '' );
	}

	/**
	 * Nombre de quien paga.
	 *
	 * @return string
	 */
	public function nombre(): string {
		return (string) ( $this->datos['customer_name'] ?? '' );
	}

	/**
	 * Un valor cualquiera de la fila.
	 *
	 * @param string $clave        Columna.
	 * @param mixed  $por_omision  Valor por omisión.
	 * @return mixed
	 */
	public function valor( string $clave, $por_omision = null ) {
		return $this->datos[ $clave ] ?? $por_omision;
	}

	/**
	 * Metadatos guardados con la transacción.
	 *
	 * @return array<string,mixed>
	 */
	public function metadata(): array {
		$crudo = (string) ( $this->datos['metadata'] ?? '' );
		$json  = json_decode( $crudo, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Parámetros UTM.
	 *
	 * @return array<string,string>
	 */
	public function utm(): array {
		$crudo = (string) ( $this->datos['utm'] ?? '' );
		$json  = json_decode( $crudo, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Historial de estados.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function historial(): array {
		$crudo = (string) ( $this->datos['status_history'] ?? '' );
		$json  = json_decode( $crudo, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Todos los datos de la fila.
	 *
	 * @return array<string,mixed>
	 */
	public function datos(): array {
		return $this->datos;
	}
}
