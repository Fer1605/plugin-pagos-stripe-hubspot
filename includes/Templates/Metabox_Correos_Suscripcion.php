<?php
/**
 * Metabox: correos del ciclo de vida de una suscripción.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Recibo mensual, aviso de cobro fallido y confirmación de cancelación.
 */
final class Metabox_Correos_Suscripcion extends Metabox_Base {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'correos_suscripcion';
	}

	/**
	 * Título.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return __( 'Correos de la suscripción mensual', 'sw-pagos' );
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$this->intro(
			__( 'Correos propios del cobro mensual: los que avisan de una renovación, de un cobro fallido o de una cancelación.', 'sw-pagos' )
		);

		$plantilla = Plantilla::por_id( $post->ID );

		// Si la plantilla no cobra cada mes, estos tres editores no hacen nada:
		// se ocultan en vez de pedir que se rellenen «por si acaso». Lo guardado
		// no se borra, porque el guardado solo toca los campos que se enviaron.
		if ( null !== $plantilla && ! $plantilla->admite_frecuencia( Plantilla::FREQ_MENSUAL ) ) {
			echo '<p class="description">';
			esc_html_e( 'Esta plantilla cobra una sola vez, así que no hay correos mensuales que configurar. Si marcas «suscripción mensual» en «Montos y frecuencia» y guardas, aparecerán aquí.', 'sw-pagos' );
			echo '</p>';
			return;
		}

		$this->campo_editor(
			'swpagos_email_recibo',
			__( 'Recibo de cada cobro mensual', 'sw-pagos' ),
			$this->leer( $post->ID, 'email_recibo' ),
			__( 'Se envía en cada renovación cobrada. Si lo dejas vacío se usa el cuerpo del correo de confirmación.', 'sw-pagos' )
		);

		$this->campo_editor(
			'swpagos_email_fallo',
			__( 'Aviso de cobro fallido', 'sw-pagos' ),
			$this->leer( $post->ID, 'email_fallo' ),
			__( 'Se envía cuando un cobro mensual no se puede completar. Incluye <code>[enlace_actualizar_pago]</code> para que la persona pueda cambiar su tarjeta: sin ese enlace la suscripción se pierde.', 'sw-pagos' )
		);

		$this->campo_editor(
			'swpagos_email_cancelacion',
			__( 'Confirmación de cancelación', 'sw-pagos' ),
			$this->leer( $post->ID, 'email_cancelacion' ),
			__( 'Se envía al cancelarse la suscripción, la cancele quien la cancele.', 'sw-pagos' )
		);
	}

	/**
	 * Guarda.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos.
	 * @return void
	 */
	public function guardar( int $post_id, array $datos ): void {
		foreach ( array( 'email_recibo', 'email_fallo', 'email_cancelacion' ) as $clave ) {
			// Si el campo no venía en el envío es que la caja estaba oculta por no
			// ser mensual: no se interpreta como «lo ha vaciado».
			if ( ! array_key_exists( 'swpagos_' . $clave, $datos ) ) {
				continue;
			}

			$this->escribir( $post_id, $clave, wp_kses_post( (string) $datos[ 'swpagos_' . $clave ] ) );
		}
	}
}
