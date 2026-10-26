<?php
/**
 * Creación de una página con el formulario ya colocado.
 *
 * @package SWPagos
 */

namespace SWPagos\Library;

use SWPagos\Core\Compat;
use SWPagos\Templates\Form_Index;

defined( 'ABSPATH' ) || exit;

/**
 * Crea un borrador de página con el formulario y su plantilla de pago asociada.
 *
 * El identificador del formulario se hace único por página. Si dos páginas
 * creadas desde la misma variante compartieran identificador, las dos plantillas
 * apuntarían al mismo formulario y el módulo elegiría una de las dos, que es
 * justo la ambigüedad que el índice de formularios detecta y avisa.
 */
final class Page_Builder {

	/**
	 * Crea la página y su plantilla.
	 *
	 * @param string $clave  Clave de la variante.
	 * @param string $titulo Título deseado para la página.
	 * @return array{pagina:int,plantilla:int}|\WP_Error
	 */
	public function crear( string $clave, string $titulo = '' ) {
		$elementos = Variants::elementos( $clave );

		if ( null === $elementos ) {
			return new \WP_Error( 'swpagos_variante_desconocida', __( 'Variante desconocida.', 'sw-pagos' ) );
		}

		$catalogo = Variants::catalogo();
		$info     = $catalogo[ $clave ];
		$titulo   = '' !== trim( $titulo ) ? trim( $titulo ) : $info['titulo'];

		$pagina = wp_insert_post(
			array(
				'post_title'  => $titulo,
				'post_type'   => 'page',
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $pagina ) ) {
			return $pagina;
		}

		$pagina = (int) $pagina;

		// Identificador único por página, para que no haya dos plantillas compitiendo.
		$form_id   = 'swpagos-' . $clave . '-' . $pagina;
		$elementos = $this->reescribir_form_id( $elementos, $form_id );

		update_post_meta( $pagina, '_elementor_edit_mode', 'builder' );
		update_post_meta( $pagina, '_elementor_template_type', 'wp-page' );
		update_post_meta( $pagina, '_elementor_data', wp_slash( (string) wp_json_encode( $elementos ) ) );

		$plantilla = $this->crear_plantilla( $clave, $titulo, $form_id, $info );

		if ( is_wp_error( $plantilla ) ) {
			return $plantilla;
		}

		Form_Index::reconstruir();

		return array(
			'pagina'    => $pagina,
			'plantilla' => (int) $plantilla,
		);
	}

	/**
	 * Crea la plantilla de pago asociada, en borrador.
	 *
	 * @param string                                                                   $clave   Variante.
	 * @param string                                                                   $titulo  Título.
	 * @param string                                                                   $form_id Formulario.
	 * @param array{titulo:string,descripcion:string,pago:bool,frecuencias:string[]} $info    Información.
	 * @return int|\WP_Error
	 */
	private function crear_plantilla( string $clave, string $titulo, string $form_id, array $info ) {
		$plantilla = wp_insert_post(
			array(
				'post_title'   => $titulo,
				'post_type'    => Compat::CPT_PLANTILLA,
				// Publicada: una plantilla en borrador no la encuentra el índice, y
				// el formulario quedaría sin plantilla sin que se vea por qué.
				'post_status'  => 'publish',
				'post_content' => $this->cuerpo_correo( $info ),
			),
			true
		);

		if ( is_wp_error( $plantilla ) ) {
			return $plantilla;
		}

		$plantilla = (int) $plantilla;

		update_post_meta( $plantilla, '_swpagos_form_id', $form_id );
		update_post_meta( $plantilla, '_swpagos_email_to', '[email]' );
		update_post_meta(
			$plantilla,
			'_swpagos_email_subject',
			$info['pago']
				? __( 'Confirmación de tu aporte', 'sw-pagos' )
				: __( 'Hemos recibido tu mensaje', 'sw-pagos' )
		);

		if ( $info['pago'] ) {
			update_post_meta( $plantilla, '_swpagos_frecuencias', implode( ',', $info['frecuencias'] ) );
			update_post_meta(
				$plantilla,
				'_swpagos_amount_mode',
				Variants::PAGO_FIJO === $clave ? 'fixed' : 'range'
			);
		}

		return $plantilla;
	}

	/**
	 * Cuerpo inicial del correo.
	 *
	 * @param array{titulo:string,descripcion:string,pago:bool,frecuencias:string[]} $info Información.
	 * @return string
	 */
	private function cuerpo_correo( array $info ): string {
		if ( ! $info['pago'] ) {
			return __( "Hola [nombre],\n\nHemos recibido tu mensaje y te responderemos pronto.\n\nGracias por escribirnos.", 'sw-pagos' );
		}

		return __( "Hola [nombre],\n\nGracias por tu aporte. Hemos confirmado tu pago correctamente.\n\nUn saludo.", 'sw-pagos' );
	}

	/**
	 * Sustituye el identificador del formulario en el árbol de elementos.
	 *
	 * @param array<int,array<string,mixed>> $elementos Árbol.
	 * @param string                         $form_id   Nuevo identificador.
	 * @return array<int,array<string,mixed>>
	 */
	private function reescribir_form_id( array $elementos, string $form_id ): array {
		foreach ( $elementos as &$elemento ) {
			if ( isset( $elemento['widgetType'] ) && 'form' === $elemento['widgetType'] ) {
				$elemento['settings']['form_id'] = $form_id;

				if ( isset( $elemento['settings']['form_fields'] ) && is_array( $elemento['settings']['form_fields'] ) ) {
					foreach ( $elemento['settings']['form_fields'] as &$campo ) {
						if ( ( $campo['custom_id'] ?? '' ) === Compat::CAMPOS_TECNICOS['formulario'] ) {
							$campo['field_value'] = $form_id;
						}
					}
					unset( $campo );
				}
			}

			if ( ! empty( $elemento['elements'] ) && is_array( $elemento['elements'] ) ) {
				$elemento['elements'] = $this->reescribir_form_id( $elemento['elements'], $form_id );
			}
		}
		unset( $elemento );

		return $elementos;
	}
}
