<?php
/**
 * Metabox: páginas de resultado.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * A dónde va la persona y qué lee tras pagar o tras un rechazo.
 */
final class Metabox_Resultado extends Metabox_Base {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'resultado';
	}

	/**
	 * Título.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return __( 'Páginas de resultado', 'sw-pagos' );
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$this->intro(
			__( 'A dónde va quien paga al volver de Stripe, y qué lee al llegar. Son dos caminos distintos: uno para el cobro confirmado y otro para el rechazado.', 'sw-pagos' )
		);

		$this->aviso_paginas_genericas();

		$this->seccion(
			__( 'Cuando el cobro se confirma', 'sw-pagos' ),
			__( 'Stripe devuelve a quien pagó a esta dirección.', 'sw-pagos' )
		);

		$this->campo_texto(
			'swpagos_url_exito',
			__( 'URL tras un cobro confirmado', 'sw-pagos' ),
			$this->leer( $post->ID, 'url_exito' ),
			__( 'Déjala vacía y se usa la página genérica del módulo.', 'sw-pagos' ),
			home_url( '/gracias/' ),
			'url'
		);

		$this->campo_editor(
			'swpagos_texto_exito',
			__( 'Texto de la página de agradecimiento', 'sw-pagos' ),
			$this->leer( $post->ID, 'texto_exito' ),
			__( 'Lo pinta el shortcode <code>[swpagos_pago_exitoso]</code>. Admite marcadores con los datos de quien pagó.', 'sw-pagos' )
		);

		$this->seccion(
			__( 'Cuando el cobro no se completa', 'sw-pagos' ),
			__( 'Rechazo, abandono o cierre de la ventana de pago.', 'sw-pagos' )
		);

		$this->campo_texto(
			'swpagos_url_fallo',
			__( 'URL tras un cobro rechazado', 'sw-pagos' ),
			$this->leer( $post->ID, 'url_fallo' ),
			__( 'Déjala vacía y se usa la página genérica del módulo.', 'sw-pagos' ),
			home_url( '/pago-rechazado/' ),
			'url'
		);

		$this->campo_editor(
			'swpagos_texto_fallo',
			__( 'Texto de la página de rechazo', 'sw-pagos' ),
			$this->leer( $post->ID, 'texto_fallo' ),
			__( 'Lo pinta el shortcode <code>[swpagos_pago_rechazado]</code>.', 'sw-pagos' )
		);

		$this->marcadores_disponibles( $post );
	}

	/**
	 * Explica qué ocurre cuando no se indica ninguna página.
	 *
	 * @return void
	 */
	private function aviso_paginas_genericas(): void {
		echo '<div class="notice notice-info inline"><p>';
		esc_html_e( 'No hace falta crear estas dos páginas. Si dejas las direcciones en blanco, el módulo usa las suyas —«Pago recibido» y «No se completó el pago»— y las crea solo la primera vez que alguien paga, en el idioma de quien pagó. El texto de abajo se muestra igual en ellas.', 'sw-pagos' );
		echo '</p>';

		$existentes = \SWPagos\Frontend\Result_Pages::existentes();
		$enlaces    = array();

		foreach ( $existentes as $cual => $por_idioma ) {
			foreach ( (array) $por_idioma as $idioma => $id ) {
				if ( ! get_post( (int) $id ) ) {
					continue;
				}

				$enlaces[] = sprintf(
					'<a href="%s">%s%s</a>',
					esc_url( (string) get_edit_post_link( (int) $id ) ),
					esc_html( get_the_title( (int) $id ) ),
					'x' === $idioma ? '' : ' (' . esc_html( (string) $idioma ) . ')'
				);
			}
		}

		if ( array() !== $enlaces ) {
			echo '<p>' . esc_html__( 'Las genéricas ya creadas, por si quieres maquetarlas:', 'sw-pagos' ) . ' ';
			echo wp_kses_post( implode( ' · ', $enlaces ) );
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Guarda.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos.
	 * @return void
	 */
	public function guardar( int $post_id, array $datos ): void {
		foreach ( array( 'url_exito', 'url_fallo' ) as $clave ) {
			$this->escribir( $post_id, $clave, esc_url_raw( (string) ( $datos[ 'swpagos_' . $clave ] ?? '' ) ) );
		}

		foreach ( array( 'texto_exito', 'texto_fallo' ) as $clave ) {
			$this->escribir( $post_id, $clave, wp_kses_post( (string) ( $datos[ 'swpagos_' . $clave ] ?? '' ) ) );
		}
	}
}
