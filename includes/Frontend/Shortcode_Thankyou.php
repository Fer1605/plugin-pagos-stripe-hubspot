<?php
/**
 * Shortcode de la página de agradecimiento.
 *
 * @package SWPagos
 */

namespace SWPagos\Frontend;

use SWPagos\Core\Compat;
use SWPagos\Domain\Transaction;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Página que ve quien acaba de pagar.
 */
final class Shortcode_Thankyou extends Shortcode_Base {

	/**
	 * Etiqueta.
	 *
	 * @return string
	 */
	protected function etiqueta(): string {
		return Compat::SHORTCODES[0];
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
			// Alguien llegó a la página sin referencia: una visita directa, o un
			// enlace compartido. No es un error, pero tampoco hay nada que contar.
			return $this->aviso(
				__( 'Esta página muestra el resultado de un pago. Si acabas de hacer uno, abre el enlace que te llevó aquí desde el formulario.', 'sw-pagos' ),
				Result_View::PENDIENTE,
				__( 'Gracias por tu visita', 'sw-pagos' )
			);
		}

		$render = $this->marcadores( $transaccion );

		if ( ! $transaccion->esta_pagada() ) {
			// Quien llega aquí con el cobro sin confirmar merece la verdad, no un
			// agradecimiento por algo que todavía no ha ocurrido.
			$en_curso = in_array( $transaccion->estado(), array( Transaction::PENDIENTE, Transaction::PROCESANDO ), true );

			return $this->aviso(
				$en_curso
					? __( 'Estamos confirmando tu pago. En cuanto se complete recibirás un correo.', 'sw-pagos' )
					: __( 'Este pago no se completó.', 'sw-pagos' ),
				$en_curso ? Result_View::PENDIENTE : Result_View::FALLO,
				$en_curso
					? __( 'Confirmando tu pago', 'sw-pagos' )
					: __( 'El pago no se completó', 'sw-pagos' )
			);
		}

		$texto = null !== $plantilla ? $plantilla->texto_exito() : '';

		if ( '' === trim( $texto ) ) {
			$texto = $this->texto_por_omision( $transaccion );
		}

		$suscripcion = $this->suscripcion( $transaccion );

		return Result_View::tarjeta(
			Result_View::EXITO,
			null !== $suscripcion
				? __( '¡Tu aporte mensual quedó activo!', 'sw-pagos' )
				: __( '¡Gracias por tu aporte!', 'sw-pagos' ),
			$this->formatear( $texto, $render ),
			$this->detalles( $transaccion, $suscripcion ),
			$this->volver_al_inicio()
		);
	}

	/**
	 * Texto genérico cuando la plantilla no define el suyo.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return string
	 */
	private function texto_por_omision( Transaction $transaccion ): string {
		$suscripcion = $this->suscripcion( $transaccion );

		if ( null !== $suscripcion ) {
			return __( "Gracias, [nombre]. Tu acompañamiento mensual ya está en marcha.\n\nTe hemos enviado un correo con los detalles y con el enlace para gestionar tu aporte cuando lo necesites.", 'sw-pagos' );
		}

		return __( "Gracias, [nombre]. Hemos recibido tu aporte.\n\nTe hemos enviado un correo de confirmación con los detalles.", 'sw-pagos' );
	}
}
