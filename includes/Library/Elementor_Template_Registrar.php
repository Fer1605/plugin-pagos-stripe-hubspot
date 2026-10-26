<?php
/**
 * Alta de las variantes en la biblioteca de plantillas de Elementor.
 *
 * @package SWPagos
 */

namespace SWPagos\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Publica cada variante como plantilla insertable desde «Mis plantillas».
 *
 * Es la vía soportada por Elementor y el respaldo permanente del botón de copiar
 * (decisión D-08): el portapapeles del editor es cómodo pero depende de un
 * formato interno que puede cambiar entre versiones; esto no.
 */
final class Elementor_Template_Registrar {

	/**
	 * Tipo de contenido de la biblioteca de Elementor.
	 *
	 * @var string
	 */
	private const CPT = 'elementor_library';

	/**
	 * Meta que marca una plantilla como creada por este módulo.
	 *
	 * @var string
	 */
	private const META_VARIANTE = '_swpagos_variante';

	/**
	 * Da de alta o actualiza la plantilla de una variante.
	 *
	 * @param string $clave Clave de la variante.
	 * @return int|\WP_Error Identificador del post.
	 */
	public function registrar( string $clave ) {
		if ( ! post_type_exists( self::CPT ) ) {
			return new \WP_Error(
				'swpagos_sin_elementor',
				__( 'La biblioteca de plantillas de Elementor no está disponible. ¿Está Elementor activo?', 'sw-pagos' )
			);
		}

		$elementos = Variants::elementos( $clave );

		if ( null === $elementos ) {
			return new \WP_Error( 'swpagos_variante_desconocida', __( 'Variante desconocida.', 'sw-pagos' ) );
		}

		$catalogo = Variants::catalogo();
		$titulo   = sprintf(
			/* translators: %s: nombre de la variante. */
			__( 'Pagos Stripe · %s', 'sw-pagos' ),
			$catalogo[ $clave ]['titulo']
		);

		$existente = $this->existente( $clave );

		$datos = array(
			'post_title'  => $titulo,
			'post_type'   => self::CPT,
			'post_status' => 'publish',
		);

		if ( $existente > 0 ) {
			$datos['ID'] = $existente;
			$id          = wp_update_post( $datos, true );
		} else {
			$id = wp_insert_post( $datos, true );
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$id = (int) $id;

		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_template_type', 'container' );
		update_post_meta( $id, self::META_VARIANTE, $clave );

		// Elementor espera el árbol con las barras escapadas como las guarda él.
		update_post_meta( $id, '_elementor_data', wp_slash( (string) wp_json_encode( $elementos ) ) );

		wp_set_object_terms( $id, 'container', 'elementor_library_type' );

		return $id;
	}

	/**
	 * Identificador de la plantilla ya registrada de una variante.
	 *
	 * @param string $clave Clave.
	 * @return int Cero si no existe.
	 */
	public function existente( string $clave ): int {
		if ( ! post_type_exists( self::CPT ) ) {
			return 0;
		}

		$encontrados = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => self::META_VARIANTE,
				'meta_value'  => $clave,
			)
		);

		return empty( $encontrados ) ? 0 : (int) $encontrados[0];
	}

	/**
	 * Estado de registro de todas las variantes.
	 *
	 * @return array<string,int>
	 */
	public function estado(): array {
		$estado = array();

		foreach ( array_keys( Variants::catalogo() ) as $clave ) {
			$estado[ $clave ] = $this->existente( $clave );
		}

		return $estado;
	}
}
