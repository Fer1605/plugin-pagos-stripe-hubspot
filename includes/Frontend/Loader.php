<?php
/**
 * Registro de las piezas del frontal.
 *
 * @package SWPagos
 */

namespace SWPagos\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Engancha los shortcodes de resultado.
 */
final class Loader {

	/**
	 * Registra.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'init', array( $this, 'registrar_shortcodes' ) );
		add_action( 'wp', array( $this, 'marcar_pagina_de_resultado' ) );
	}

	/**
	 * Marca la página de resultado para que el tema no pinte su título.
	 *
	 * La tarjeta ya lleva su propio titular —«¡Gracias por tu aporte!»— y encima
	 * de ella el título de la página repetía lo mismo con otro tamaño. El tema no
	 * puede saberlo solo, así que se le avisa con una clase en el body y, si es
	 * Hello Elementor, con su propio filtro.
	 *
	 * @return void
	 */
	public function marcar_pagina_de_resultado(): void {
		if ( ! is_singular() || ! $this->es_pagina_de_resultado() ) {
			return;
		}

		add_filter( 'hello_elementor_page_title', '__return_false' );

		add_filter(
			'body_class',
			static function ( array $clases ): array {
				$clases[] = 'swpagos-pagina-resultado';

				return $clases;
			}
		);
	}

	/**
	 * Indica si la entrada actual pinta un resultado de pago.
	 *
	 * @return bool
	 */
	private function es_pagina_de_resultado(): bool {
		$entrada = get_post();

		if ( ! $entrada instanceof \WP_Post ) {
			return false;
		}

		foreach ( \SWPagos\Core\Compat::SHORTCODES as $shortcode ) {
			if ( has_shortcode( (string) $entrada->post_content, $shortcode ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Registra los shortcodes.
	 *
	 * @return void
	 */
	public function registrar_shortcodes(): void {
		( new Shortcode_Thankyou() )->registrar();
		( new Shortcode_Failure() )->registrar();
	}
}
