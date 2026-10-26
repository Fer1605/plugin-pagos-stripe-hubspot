<?php
/**
 * Validación del formulario antes de intentar cobrar.
 *
 * @package SWPagos
 */

namespace SWPagos\Elementor;

use SWPagos\Core\Compat;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Comprueba que el formulario puede cobrar antes de llamar a la pasarela.
 *
 * El módulo de referencia descubría los campos que faltaban en el momento del
 * envío, con un error genérico para quien rellenaba el formulario. Aquí
 * los problemas se nombran uno a uno y la pantalla de diagnóstico los lista
 * **antes** de que nadie intente pagar.
 */
final class Form_Validator {

	/**
	 * Comprueba un formulario con cobro.
	 *
	 * @param array<string,mixed> $form_settings Ajustes del formulario.
	 * @param array<string,mixed> $campos        Campos recibidos.
	 * @param Plantilla|null      $plantilla     Plantilla asociada.
	 * @return array<int,array{codigo:string,mensaje:string,campo:string}> Problemas encontrados.
	 */
	public function validar_cobro( array $form_settings, array $campos, ?Plantilla $plantilla ): array {
		$problemas = array();
		$form_id   = (string) ( $form_settings['form_id'] ?? '' );

		if ( '' === $form_id ) {
			$problemas[] = $this->problema(
				'sin_form_id',
				__( 'El formulario no tiene identificador. Ponle uno en Avanzado → Form ID del widget.', 'sw-pagos' )
			);
		}

		if ( null === $plantilla ) {
			$problemas[] = $this->problema(
				'sin_plantilla',
				sprintf(
					/* translators: %s: identificador del formulario. */
					__( 'No hay ninguna plantilla de pago cuyo formulario sea «%s».', 'sw-pagos' ),
					$form_id
				)
			);
		}

		foreach ( $this->campos_requeridos( $plantilla ) as $id => $etiqueta ) {
			// El correo puede llamarse «correo», «mail»… en un formulario que ya
			// existía: se acepta cualquiera de sus equivalencias.
			$presente = isset( \SWPagos\Core\Campos::ALIAS[ $id ] )
				? '' !== \SWPagos\Core\Campos::identificador( $campos, $id )
				: array_key_exists( $id, $campos );

			if ( ! $presente ) {
				$problemas[] = $this->problema(
					'campo_faltante',
					sprintf(
						/* translators: 1: etiqueta del campo, 2: identificador. */
						__( 'Falta el campo «%1$s» con el identificador %2$s.', 'sw-pagos' ),
						$etiqueta,
						$id
					),
					$id
				);
			}
		}

		// Vale cualquier campo que diga la frecuencia, también un «Tipo de
		// donación» que el formulario ya tuviera.
		if ( null !== $plantilla && $plantilla->es_mixto()
			&& '' === \SWPagos\Core\Campos::identificador( $campos, 'frecuencia' ) ) {
			$problemas[] = $this->problema(
				'sin_campo_frecuencia',
				sprintf(
					/* translators: %s: identificador del campo. */
					__( 'La plantilla ofrece pago único y mensual, pero el formulario no tiene ningún campo donde elegirlo. Añade el campo %s, o usa un desplegable con una opción que diga «mensual».', 'sw-pagos' ),
					Compat::CAMPO_FRECUENCIA
				),
				Compat::CAMPO_FRECUENCIA
			);
		}

		return $problemas;
	}

	/**
	 * Campos que debe tener un formulario con cobro.
	 *
	 * @param Plantilla|null $plantilla Plantilla.
	 * @return array<string,string>
	 */
	private function campos_requeridos( ?Plantilla $plantilla ): array {
		$requeridos = array(
			Compat::CAMPOS_TECNICOS['transaccion'] => __( 'Transacción', 'sw-pagos' ),
			'email'                                => __( 'Correo electrónico', 'sw-pagos' ),
		);

		// Con importe fijo lo decide la plantilla, así que el campo puede no estar.
		if ( null === $plantilla || Plantilla::MODO_FIJO !== $plantilla->modo_monto() ) {
			$requeridos[ Compat::CAMPO_IMPORTE ] = __( 'Importe', 'sw-pagos' );
		}

		return $requeridos;
	}

	/**
	 * Construye un problema.
	 *
	 * @param string $codigo  Código.
	 * @param string $mensaje Mensaje.
	 * @param string $campo   Campo afectado.
	 * @return array{codigo:string,mensaje:string,campo:string}
	 */
	private function problema( string $codigo, string $mensaje, string $campo = '' ): array {
		return array(
			'codigo'  => $codigo,
			'mensaje' => $mensaje,
			'campo'   => $campo,
		);
	}
}
