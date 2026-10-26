<?php
/**
 * Shortcode de la página de rechazo.
 *
 * @package SWPagos
 */

namespace SWPagos\Frontend;

use SWPagos\Core\Compat;
use SWPagos\Domain\Transaction;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Página que ve quien no pudo completar el pago.
 */
final class Shortcode_Failure extends Shortcode_Base {

	/**
	 * Etiqueta.
	 *
	 * @return string
	 */
	protected function etiqueta(): string {
		return Compat::SHORTCODES[1];
	}

	/**
	 * Contenido.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @param Plantilla|null   $plantilla   Plantilla.
	 * @return string
	 */
	protected function contenido( ?Transaction $transaccion, ?Plantilla $plantilla ): string {
		if ( null === $transaccion ) {
			return $this->aviso(
				__( 'No encontramos ningún pago asociado a este enlace.', 'sw-pagos' ),
				Result_View::PENDIENTE,
				__( 'Enlace sin pago', 'sw-pagos' )
			);
		}

		$render = $this->marcadores( $transaccion );

		// Puede haberse confirmado entre la redirección y esta carga. Mandar a
		// alguien que sí pagó a una página de rechazo es el peor desenlace posible.
		if ( $transaccion->esta_pagada() ) {
			return $this->aviso(
				__( 'Tu pago sí se completó. Recibirás un correo de confirmación.', 'sw-pagos' ),
				Result_View::EXITO,
				__( '¡Tu pago sí se completó!', 'sw-pagos' )
			);
		}

		if ( in_array( $transaccion->estado(), array( Transaction::PENDIENTE, Transaction::PROCESANDO ), true ) ) {
			return $this->aviso(
				__( 'Tu pago sigue en proceso. Te avisaremos por correo en cuanto se confirme.', 'sw-pagos' ),
				Result_View::PENDIENTE,
				__( 'Tu pago sigue en proceso', 'sw-pagos' )
			);
		}

		$texto = null !== $plantilla ? $plantilla->texto_fallo() : '';

		if ( '' === trim( $texto ) ) {
			$texto = __( "[motivo]\n\nNo se te ha cobrado nada. Puedes intentarlo otra vez; si el problema sigue, escríbenos y te ayudamos.", 'sw-pagos' );
		}

		return Result_View::tarjeta(
			Result_View::FALLO,
			__( 'No pudimos completar tu pago', 'sw-pagos' ),
			$this->formatear( $texto, $render ),
			array( __( 'Referencia', 'sw-pagos' ) => $transaccion->uuid() ),
			array(
				'texto' => __( 'Intentar de nuevo', 'sw-pagos' ),
				'url'   => wp_get_referer() ? wp_get_referer() : home_url( '/' ),
			)
		);
	}
}
