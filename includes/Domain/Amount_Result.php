<?php
/**
 * Resultado de aplicar la política de montos.
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * O hay un importe que cobrar, o hay un motivo por el que no.
 *
 * Nunca las dos cosas, y nunca ninguna: quien recibe esto no puede «seguir por
 * si acaso».
 */
final class Amount_Result {

	/**
	 * Importe resuelto, en unidad mínima.
	 *
	 * @var int
	 */
	public readonly int $importe;

	/**
	 * Moneda.
	 *
	 * @var string
	 */
	public readonly string $moneda;

	/**
	 * Frecuencia resuelta.
	 *
	 * @var string
	 */
	public readonly string $frecuencia;

	/**
	 * Código del motivo del rechazo, para bitácoras.
	 *
	 * @var string
	 */
	public readonly string $motivo;

	/**
	 * Mensaje que ve quien rellenó el formulario.
	 *
	 * @var string
	 */
	public readonly string $mensaje;

	/**
	 * Campo del formulario al que señalar el error.
	 *
	 * @var string
	 */
	public readonly string $campo;

	/**
	 * Constructor.
	 *
	 * @param int    $importe    Importe en unidad mínima.
	 * @param string $moneda     Moneda.
	 * @param string $frecuencia Frecuencia.
	 * @param string $motivo     Código del motivo.
	 * @param string $mensaje    Mensaje público.
	 * @param string $campo      Campo señalado.
	 */
	private function __construct( int $importe, string $moneda, string $frecuencia, string $motivo, string $mensaje, string $campo ) {
		$this->importe    = $importe;
		$this->moneda     = $moneda;
		$this->frecuencia = $frecuencia;
		$this->motivo     = $motivo;
		$this->mensaje    = $mensaje;
		$this->campo      = $campo;
	}

	/**
	 * Importe aceptado.
	 *
	 * @param int    $importe    Importe en unidad mínima.
	 * @param string $moneda     Moneda.
	 * @param string $frecuencia Frecuencia.
	 * @return Amount_Result
	 */
	public static function aceptado( int $importe, string $moneda, string $frecuencia ): Amount_Result {
		return new self( $importe, $moneda, $frecuencia, '', '', '' );
	}

	/**
	 * Importe rechazado.
	 *
	 * @param string $motivo  Código del motivo.
	 * @param string $mensaje Mensaje público.
	 * @param string $campo   Campo señalado.
	 * @return Amount_Result
	 */
	public static function rechazado( string $motivo, string $mensaje, string $campo = '' ): Amount_Result {
		return new self( 0, '', '', $motivo, $mensaje, $campo );
	}

	/**
	 * Indica si se puede cobrar.
	 *
	 * @return bool
	 */
	public function aceptable(): bool {
		return '' === $this->motivo && $this->importe > 0;
	}
}
