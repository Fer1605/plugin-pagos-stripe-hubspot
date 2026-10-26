<?php
/**
 * Metabox: correo de confirmación.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Destinatario, asunto y copias del correo de confirmación.
 */
final class Metabox_Correo extends Metabox_Base {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'correo';
	}

	/**
	 * Título.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return __( 'Correo de confirmación', 'sw-pagos' );
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$this->intro(
			__( 'El correo que recibe quien paga cuando el cobro queda confirmado. No se envía antes: un cobro pendiente o rechazado no genera correo.', 'sw-pagos' )
		);

		// El botón se enseña siempre. Esconderlo en una plantilla recién creada
		// deja a quien la escribe sin saber que la vista previa existe, que es
		// justo cuando más falta hace.
		$guardada = 'auto-draft' !== $post->post_status;

		echo '<p style="margin:0 0 18px">';

		if ( $guardada ) {
			printf(
				'<a class="button" href="%s" target="_blank" rel="noopener">%s</a>'
				. '<span class="description" style="margin-left:10px">%s</span>',
				esc_url( Email_Preview::url( $post->ID ) ),
				esc_html__( 'Ver cómo queda el correo', 'sw-pagos' ),
				esc_html__( 'Abre en otra pestaña el correo ya montado, sin enviar nada.', 'sw-pagos' )
			);
		} else {
			printf(
				'<button type="button" class="button" disabled>%s</button>'
				. '<span class="description" style="margin-left:10px">%s</span>',
				esc_html__( 'Ver cómo queda el correo', 'sw-pagos' ),
				esc_html__( 'Guarda la plantilla una primera vez y este botón te abrirá el correo ya montado, sin enviar nada.', 'sw-pagos' )
			);
		}

		echo '</p>';

		$this->campo_texto(
			'swpagos_email_to',
			__( 'Enviar a', 'sw-pagos' ),
			$this->leer( $post->ID, 'email_to' ),
			__( 'Normalmente un marcador con el campo de correo del formulario, por ejemplo <code>[email]</code>. También admite una dirección fija.', 'sw-pagos' ),
			'[email]'
		);

		$this->campo_texto(
			'swpagos_email_subject',
			__( 'Asunto', 'sw-pagos' ),
			$this->leer( $post->ID, 'email_subject' ),
			__( 'Admite marcadores.', 'sw-pagos' ),
			__( 'Gracias por tu donación, [nombre]', 'sw-pagos' )
		);

		$this->campo_texto(
			'swpagos_email_bcc',
			__( 'Copia oculta a', 'sw-pagos' ),
			$this->leer( $post->ID, 'email_bcc' ),
			__( 'Aquí se pone el equipo: es la forma de que reciban aviso de cada donación <strong>ya cobrada</strong>. Separa varias direcciones con comas; las que no sean válidas se descartan al guardar.', 'sw-pagos' ),
			'contabilidad@ejemplo.org, donaciones@ejemplo.org'
		);

		echo '<p class="description">';
		esc_html_e( 'Este correo se envía solo cuando el cobro está confirmado, nunca al iniciarlo.', 'sw-pagos' );
		echo '</p>';

		$this->marcadores_disponibles( $post );
	}

	/**
	 * Guarda.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos.
	 * @return void
	 */
	public function guardar( int $post_id, array $datos ): void {
		$this->escribir( $post_id, 'email_to', sanitize_text_field( (string) ( $datos['swpagos_email_to'] ?? '' ) ) );
		$this->escribir( $post_id, 'email_subject', sanitize_text_field( (string) ( $datos['swpagos_email_subject'] ?? '' ) ) );
		$this->escribir( $post_id, 'email_bcc', $this->correos_validos( (string) ( $datos['swpagos_email_bcc'] ?? '' ) ) );
	}
}
