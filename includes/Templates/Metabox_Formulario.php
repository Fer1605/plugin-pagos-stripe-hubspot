<?php
/**
 * Metabox: formulario al que aplica la plantilla.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Vincula la plantilla con un formulario de Elementor.
 */
final class Metabox_Formulario extends Metabox_Base {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'formulario';
	}

	/**
	 * Título.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return __( 'Formulario asociado', 'sw-pagos' );
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$this->intro(
			__( 'Qué formulario de Elementor atiende esta plantilla. Es el enlace entre los dos: sin él, el formulario cobra sin saber cuánto ni a dónde mandar a quien paga.', 'sw-pagos' )
		);

		// El nonce de toda la pantalla se imprime una sola vez, aquí.
		Cpt_Plantilla_Pago::imprimir_nonce();

		$form_id = $this->leer( $post->ID, 'form_id' );

		$this->campo_texto(
			'swpagos_form_id',
			__( 'Identificador del formulario', 'sw-pagos' ),
			$form_id,
			__( 'Es el <strong>Form ID</strong> del widget de formulario en Elementor, en Avanzado → Form ID. Si copiaste el formulario desde la biblioteca del módulo, ya lo trae puesto.', 'sw-pagos' ),
			'donacion-mensual'
		);

		if ( '' === $form_id ) {
			echo '<p class="description" style="color:#b32d2e">';
			esc_html_e( 'Sin este dato la plantilla no se aplica a ningún formulario y el cobro se bloqueará.', 'sw-pagos' );
			echo '</p>';
			return;
		}

		$duplicados = Form_Index::duplicados();

		if ( isset( $duplicados[ $form_id ] ) ) {
			$otras = array_diff( $duplicados[ $form_id ], array( $post->ID ) );

			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: lista de enlaces a las otras plantillas. */
				esc_html__( 'Hay más de una plantilla para este formulario: %s. Se usará la modificada más recientemente, lo que hace el comportamiento imprevisible. Conviene dejar solo una.', 'sw-pagos' ),
				wp_kses_post(
					implode(
						', ',
						array_map(
							static fn( int $id ): string => '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>',
							$otras
						)
					)
				)
			);
			echo '</p></div>';
		}
	}

	/**
	 * Guarda.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos.
	 * @return void
	 */
	public function guardar( int $post_id, array $datos ): void {
		if ( ! isset( $datos['swpagos_form_id'] ) ) {
			return;
		}

		$this->escribir( $post_id, 'form_id', sanitize_text_field( (string) $datos['swpagos_form_id'] ) );
	}
}
