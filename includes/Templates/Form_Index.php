<?php
/**
 * Índice de formularios con plantilla asociada.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

use SWPagos\Core\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve «qué plantilla corresponde a este formulario» en una sola lectura.
 *
 * El módulo de referencia resolvía esto recorriendo el contenido serializado de
 * Elementor con comparaciones de texto en cada envío (hallazgo H-11). Aquí el
 * índice se alimenta al guardar la plantilla, que es cuando el dato cambia, y la
 * consulta en el momento del cobro es una lectura de una opción cacheada.
 */
final class Form_Index {

	/**
	 * Opción que guarda el índice.
	 *
	 * @var string
	 */
	private const OPCION = 'swpagos_form_index';

	/**
	 * Engancha el mantenimiento del índice.
	 *
	 * @return void
	 */
	public static function arrancar(): void {
		add_action( 'save_post_' . Compat::CPT_PLANTILLA, array( self::class, 'al_guardar' ), 20 );
		add_action( 'trashed_post', array( self::class, 'al_cambiar_estado' ) );
		add_action( 'untrashed_post', array( self::class, 'al_cambiar_estado' ) );
		add_action( 'deleted_post', array( self::class, 'al_cambiar_estado' ) );
	}

	/**
	 * Devuelve el identificador de la plantilla de un formulario.
	 *
	 * @param string $form_id Identificador lógico del formulario.
	 * @return int Cero si no hay plantilla.
	 */
	public static function plantilla_de( string $form_id ): int {
		$form_id = trim( $form_id );

		if ( '' === $form_id ) {
			return 0;
		}

		$indice = self::indice();

		return isset( $indice[ $form_id ] ) ? (int) $indice[ $form_id ] : 0;
	}

	/**
	 * Índice completo: formulario => plantilla.
	 *
	 * @return array<string,int>
	 */
	public static function indice(): array {
		$indice = get_option( self::OPCION );

		if ( ! is_array( $indice ) ) {
			$indice = self::reconstruir();
		}

		return $indice;
	}

	/**
	 * Formularios que tienen más de una plantilla apuntándoles.
	 *
	 * Es una configuración ambigua: el módulo de referencia la toleraba en
	 * silencio y acababa eligiendo una al azar. Aquí se expone en el diagnóstico.
	 *
	 * @return array<string,int[]> Formulario => plantillas.
	 */
	public static function duplicados(): array {
		$por_formulario = array();

		foreach ( self::plantillas() as $id ) {
			$form_id = trim( (string) get_post_meta( $id, '_swpagos_form_id', true ) );
			if ( '' === $form_id ) {
				continue;
			}
			$por_formulario[ $form_id ][] = (int) $id;
		}

		return array_filter( $por_formulario, static fn( array $ids ): bool => count( $ids ) > 1 );
	}

	/**
	 * Reconstruye el índice desde las plantillas publicadas.
	 *
	 * Ante varias plantillas para un mismo formulario gana la más reciente, y el
	 * diagnóstico avisa de la ambigüedad.
	 *
	 * @return array<string,int>
	 */
	public static function reconstruir(): array {
		$indice = array();

		foreach ( self::plantillas() as $id ) {
			$form_id = trim( (string) get_post_meta( $id, '_swpagos_form_id', true ) );
			if ( '' !== $form_id ) {
				$indice[ $form_id ] = (int) $id;
			}
		}

		update_option( self::OPCION, $indice, false );

		return $indice;
	}

	/**
	 * Identificadores de las plantillas publicadas, de la más antigua a la más
	 * reciente, para que la última sobrescriba en caso de duplicado.
	 *
	 * @return int[]
	 */
	private static function plantillas(): array {
		return get_posts(
			array(
				'post_type'        => Compat::CPT_PLANTILLA,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'modified',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Reconstruye el índice al guardar una plantilla.
	 *
	 * @param int $post_id Identificador.
	 * @return void
	 */
	public static function al_guardar( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::reconstruir();
	}

	/**
	 * Reconstruye el índice si cambia el estado de una plantilla.
	 *
	 * @param int $post_id Identificador.
	 * @return void
	 */
	public static function al_cambiar_estado( $post_id ): void {
		if ( Compat::CPT_PLANTILLA !== get_post_type( $post_id ) ) {
			return;
		}

		self::reconstruir();
	}
}
