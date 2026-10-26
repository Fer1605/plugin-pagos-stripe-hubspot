<?php
/**
 * Política de montos: qué importe se puede cobrar.
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

use SWPagos\Core\Currency;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve en el servidor cuánto se cobra.
 *
 * Es la pieza que sostiene la validación del importe en el servidor. El importe que llega del formulario
 * es una **solicitud**, no el importe: un campo numérico o un campo oculto son
 * editables desde el navegador, y el módulo de referencia los enviaba tal cual a
 * la pasarela (hallazgo H-01). Aquí nada se cobra sin pasar por esta clase.
 */
final class Amount_Policy {

	/**
	 * Importe mínimo absoluto, en unidad mínima.
	 *
	 * Por debajo de esto las pasarelas rechazan el cobro de todos modos, y lo
	 * hacen con un error mucho menos claro que el nuestro.
	 *
	 * @var int
	 */
	private const MINIMO_ABSOLUTO = 1;

	/**
	 * Resuelve el importe a cobrar.
	 *
	 * @param Plantilla        $plantilla  Plantilla del formulario.
	 * @param string|int|float $solicitado Importe pedido desde el formulario.
	 * @param string           $frecuencia Frecuencia pedida.
	 * @return Amount_Result
	 */
	public function resolver( Plantilla $plantilla, $solicitado, string $frecuencia ): Amount_Result {
		$moneda = $plantilla->moneda();

		$resultado_frecuencia = $this->resolver_frecuencia( $plantilla, $frecuencia );

		if ( '' === $resultado_frecuencia ) {
			return Amount_Result::rechazado(
				'frecuencia_no_admitida',
				__( 'La forma de pago elegida no está disponible en este formulario.', 'sw-pagos' ),
				\SWPagos\Core\Compat::CAMPO_FRECUENCIA
			);
		}

		switch ( $plantilla->modo_monto() ) {
			case Plantilla::MODO_FIJO:
				return $this->resolver_fijo( $plantilla, $moneda, $resultado_frecuencia );

			case Plantilla::MODO_LISTA:
				return $this->resolver_lista( $plantilla, $solicitado, $moneda, $resultado_frecuencia );

			default:
				return $this->resolver_rango( $plantilla, $solicitado, $moneda, $resultado_frecuencia );
		}
	}

	/**
	 * Importe fijo: lo que pida el formulario se ignora por completo.
	 *
	 * @param Plantilla $plantilla  Plantilla.
	 * @param string    $moneda     Moneda.
	 * @param string    $frecuencia Frecuencia.
	 * @return Amount_Result
	 */
	private function resolver_fijo( Plantilla $plantilla, string $moneda, string $frecuencia ): Amount_Result {
		$importe = $plantilla->monto_fijo();

		if ( $importe < self::MINIMO_ABSOLUTO ) {
			return Amount_Result::rechazado(
				'fijo_sin_configurar',
				__( 'Este formulario todavía no tiene configurado su importe. Avisa a quien administra el sitio.', 'sw-pagos' )
			);
		}

		return Amount_Result::aceptado( $importe, $moneda, $frecuencia );
	}

	/**
	 * Lista cerrada: el importe debe coincidir exactamente con uno de la lista.
	 *
	 * @param Plantilla        $plantilla  Plantilla.
	 * @param string|int|float $solicitado Importe pedido.
	 * @param string           $moneda     Moneda.
	 * @param string           $frecuencia Frecuencia.
	 * @return Amount_Result
	 */
	private function resolver_lista( Plantilla $plantilla, $solicitado, string $moneda, string $frecuencia ): Amount_Result {
		$permitidos = $plantilla->montos_lista();

		if ( empty( $permitidos ) ) {
			return Amount_Result::rechazado(
				'lista_vacia',
				__( 'Este formulario todavía no tiene configurados sus importes. Avisa a quien administra el sitio.', 'sw-pagos' )
			);
		}

		$pedido = Currency::a_unidad_minima( (string) $solicitado, $moneda );

		if ( ! in_array( $pedido, $permitidos, true ) ) {
			return Amount_Result::rechazado(
				'fuera_de_lista',
				sprintf(
					/* translators: %s: lista de importes permitidos. */
					__( 'Elige uno de los importes disponibles: %s.', 'sw-pagos' ),
					implode(
						', ',
						array_map(
							static fn( int $m ): string => Currency::formatear( $m, $moneda ),
							$permitidos
						)
					)
				),
				\SWPagos\Core\Compat::CAMPO_IMPORTE
			);
		}

		return Amount_Result::aceptado( $pedido, $moneda, $frecuencia );
	}

	/**
	 * Rango: el importe debe caer entre el mínimo y el máximo.
	 *
	 * @param Plantilla        $plantilla  Plantilla.
	 * @param string|int|float $solicitado Importe pedido.
	 * @param string           $moneda     Moneda.
	 * @param string           $frecuencia Frecuencia.
	 * @return Amount_Result
	 */
	private function resolver_rango( Plantilla $plantilla, $solicitado, string $moneda, string $frecuencia ): Amount_Result {
		$pedido = Currency::a_unidad_minima( (string) $solicitado, $moneda );
		$minimo = max( self::MINIMO_ABSOLUTO, $plantilla->monto_min() );
		$maximo = $plantilla->monto_max();

		if ( $pedido < self::MINIMO_ABSOLUTO ) {
			return Amount_Result::rechazado(
				'importe_invalido',
				__( 'Escribe un importe válido mayor que cero.', 'sw-pagos' ),
				\SWPagos\Core\Compat::CAMPO_IMPORTE
			);
		}

		if ( $pedido < $minimo ) {
			return Amount_Result::rechazado(
				'por_debajo_del_minimo',
				sprintf(
					/* translators: %s: importe mínimo con su moneda. */
					__( 'El importe mínimo es %s.', 'sw-pagos' ),
					Currency::formatear( $minimo, $moneda )
				),
				\SWPagos\Core\Compat::CAMPO_IMPORTE
			);
		}

		if ( $maximo > 0 && $pedido > $maximo ) {
			return Amount_Result::rechazado(
				'por_encima_del_maximo',
				sprintf(
					/* translators: %s: importe máximo con su moneda. */
					__( 'El importe máximo es %s.', 'sw-pagos' ),
					Currency::formatear( $maximo, $moneda )
				),
				\SWPagos\Core\Compat::CAMPO_IMPORTE
			);
		}

		return Amount_Result::aceptado( $pedido, $moneda, $frecuencia );
	}

	/**
	 * Decide la frecuencia efectiva.
	 *
	 * Si el formulario ofrece una sola, se usa esa aunque el navegador pida otra:
	 * no tiene sentido rechazar un envío por un campo que ni siquiera se mostró.
	 * Si ofrece varias, la pedida tiene que estar entre ellas.
	 *
	 * @param Plantilla $plantilla Plantilla.
	 * @param string    $pedida    Frecuencia pedida.
	 * @return string Frecuencia resuelta, o cadena vacía si no es admisible.
	 */
	private function resolver_frecuencia( Plantilla $plantilla, string $pedida ): string {
		$admitidas = $plantilla->frecuencias();
		$pedida    = trim( $pedida );

		if ( 1 === count( $admitidas ) ) {
			return $admitidas[0];
		}

		if ( '' === $pedida ) {
			// Formulario mixto sin elección: se asume la opción menos comprometida.
			return in_array( Plantilla::FREQ_UNICA, $admitidas, true ) ? Plantilla::FREQ_UNICA : '';
		}

		return in_array( $pedida, $admitidas, true ) ? $pedida : '';
	}
}
