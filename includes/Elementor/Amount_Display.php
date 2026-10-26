<?php
/**
 * Muestra al visitante la moneda y los límites que el servidor va a aplicar.
 *
 * @package SWPagos
 */

namespace SWPagos\Elementor;

use SWPagos\Core\Compat;
use SWPagos\Core\Currency;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * El importe se valida siempre en el servidor contra la plantilla. Esta clase no
 * añade ninguna regla nueva: enseña en pantalla la que ya existe, para que nadie
 * escriba una cifra sin saber en qué moneda está ni entre qué límites.
 */
final class Amount_Display {

	/**
	 * Engancha el filtro de pintado de campos.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_filter( 'elementor_pro/forms/render/item', array( $this, 'decorar_importe' ), 10, 3 );
		add_action( 'save_post_' . Compat::CPT_PLANTILLA, array( $this, 'vaciar_cache_elementos' ) );
	}

	/**
	 * Vacía la caché de elementos de Elementor al guardar una plantilla.
	 *
	 * Elementor guarda el HTML ya pintado de cada widget en _elementor_element_cache
	 * y en ese caso ni siquiera llama a render(), así que un cambio de moneda o de
	 * límites no se vería hasta que algo más invalidara esa caché.
	 *
	 * @return void
	 */
	public function vaciar_cache_elementos(): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_elementor_element_cache' )
		);
	}

	/**
	 * Añade moneda y rango al campo de importe.
	 *
	 * @param array<string,mixed>                           $item  Campo.
	 * @param int                                           $index Índice.
	 * @param \ElementorPro\Modules\Forms\Widgets\Form_Base $form  Widget.
	 * @return array<string,mixed>
	 */
	public function decorar_importe( $item, $index, $form ) {
		if ( Compat::CAMPO_IMPORTE !== ( $item['custom_id'] ?? '' ) ) {
			return $item;
		}

		// Un importe oculto lo fija la plantilla: no hay nada que enseñar.
		if ( 'hidden' === ( $item['field_type'] ?? '' ) ) {
			return $item;
		}

		$plantilla = $this->plantilla_de( $form );

		if ( null === $plantilla ) {
			return $item;
		}

		$moneda = $plantilla->moneda();

		if ( '' !== ( $item['field_label'] ?? '' ) ) {
			$item['field_label'] = sprintf(
				/* translators: 1: etiqueta original, 2: código ISO de la moneda. */
				__( '%1$s (%2$s)', 'sw-pagos' ),
				$item['field_label'],
				$moneda
			);
		}

		if ( Plantilla::MODO_RANGO !== $plantilla->modo_monto() ) {
			return $item;
		}

		$min = $plantilla->monto_min();
		$max = $plantilla->monto_max();

		// El campo numérico de Elementor lee sus topes de field_min y field_max.
		// Las claves min y max sueltas vienen de formularios creados con una
		// versión anterior del módulo y acaban añadiendo un segundo valor al
		// mismo atributo ( min="1.00 1" ), que el navegador descarta entero.
		unset( $item['min'], $item['max'] );

		$item['field_min'] = Currency::a_unidad_mayor( max( 1, $min ), $moneda );

		if ( $max > 0 ) {
			$item['field_max'] = Currency::a_unidad_mayor( $max, $moneda );
		}

		// Elementor calcula los atributos del input antes de disparar este filtro,
		// así que el marcador de posición sí hay que sobrescribirlo aquí.
		$atributos = array();

		if ( $min > 0 && $max > 0 ) {
			$atributos['placeholder'] = sprintf(
				/* translators: 1: importe mínimo ya formateado, 2: importe máximo. */
				__( 'Entre %1$s y %2$s', 'sw-pagos' ),
				Currency::formatear( $min, $moneda ),
				Currency::formatear( $max, $moneda )
			);
		} elseif ( $min > 0 ) {
			// Sin tope arriba, pero la moneda tiene que verse igual: era el motivo
			// de este marcador, porque cambia de un sitio a otro.
			$atributos['placeholder'] = sprintf(
				/* translators: %s: importe mínimo ya formateado. */
				__( 'Desde %s', 'sw-pagos' ),
				Currency::formatear( $min, $moneda )
			);
		} elseif ( $max > 0 ) {
			$atributos['placeholder'] = sprintf(
				/* translators: %s: importe máximo ya formateado. */
				__( 'Hasta %s', 'sw-pagos' ),
				Currency::formatear( $max, $moneda )
			);
		}

		if ( array() !== $atributos && method_exists( $form, 'set_render_attribute' ) ) {
			foreach ( $atributos as $clave => $valor ) {
				$form->set_render_attribute( 'input' . $index, $clave, $valor, true );
			}
		}

		return $item;
	}

	/**
	 * Localiza la plantilla del formulario que se está pintando.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form_Base $form Widget.
	 * @return Plantilla|null
	 */
	private function plantilla_de( $form ): ?Plantilla {
		if ( ! is_object( $form ) ) {
			return null;
		}

		foreach ( $this->identificadores( $form ) as $form_id ) {
			$plantilla = Plantilla::por_formulario( $form_id );

			if ( null !== $plantilla ) {
				return $plantilla;
			}
		}

		return null;
	}

	/**
	 * Identificadores candidatos del formulario, del más fiable al menos.
	 *
	 * Elementor ha movido de sitio el ajuste del identificador entre versiones, y
	 * el campo técnico oculto lo lleva el propio formulario: si una vía falla,
	 * la siguiente sigue encontrando la plantilla.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form_Base $form Widget.
	 * @return string[]
	 */
	private function identificadores( $form ): array {
		$candidatos = array();

		foreach ( array( 'get_settings_for_display', 'get_settings' ) as $metodo ) {
			if ( ! method_exists( $form, $metodo ) ) {
				continue;
			}

			$valor = $form->{$metodo}( 'form_id' );

			if ( is_string( $valor ) && '' !== $valor ) {
				$candidatos[] = $valor;
			}
		}

		// El campo oculto que el módulo planta en cada formulario de la biblioteca.
		if ( method_exists( $form, 'get_settings' ) ) {
			$campos = $form->get_settings( 'form_fields' );

			if ( is_array( $campos ) ) {
				foreach ( $campos as $campo ) {
					if ( Compat::CAMPOS_TECNICOS['formulario'] === ( $campo['custom_id'] ?? '' ) ) {
						$valor = (string) ( $campo['field_value'] ?? '' );

						if ( '' !== $valor ) {
							$candidatos[] = $valor;
						}
					}
				}
			}
		}

		return array_unique( $candidatos );
	}
}
