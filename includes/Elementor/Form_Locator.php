<?php
/**
 * Localiza un formulario de Elementor por su identificador lógico.
 *
 * @package SWPagos
 */

namespace SWPagos\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Las pantallas de administración necesitan saber dónde vive un formulario y qué
 * tiene configurado sin que nadie lo esté enviando. Toda la configuración de
 * HubSpot vive en el propio formulario, así que hay que ir a buscarla a su
 * página; y para poder enlazarla hace falta además saber cuál es esa página.
 *
 * No se usa en el camino del cobro: ahí los ajustes llegan del envío.
 */
final class Form_Locator {

	/**
	 * Lo ya buscado en esta petición.
	 *
	 * Un listado de cincuenta filas pregunta por el mismo puñado de formularios
	 * una y otra vez, y cada búsqueda recorre todos los _elementor_data del sitio.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static array $cache = array();

	/**
	 * Dónde está el formulario y cómo está configurado.
	 *
	 * @param string $form_id Identificador lógico.
	 * @return array{post_id:int,element_id:string,ajustes:array<string,mixed>}|null
	 */
	public static function ubicacion( string $form_id ): ?array {
		$form_id = trim( $form_id );

		if ( '' === $form_id ) {
			return null;
		}

		if ( array_key_exists( $form_id, self::$cache ) ) {
			/** @var array{post_id:int,element_id:string,ajustes:array<string,mixed>}|null */
			return self::$cache[ $form_id ];
		}

		self::$cache[ $form_id ] = null;

		foreach ( self::paginas_con( $form_id ) as $post_id => $datos ) {
			$encontrado = self::buscar( $datos, $form_id );

			if ( null !== $encontrado ) {
				self::$cache[ $form_id ] = array(
					'post_id'    => (int) $post_id,
					'element_id' => (string) ( $encontrado['id'] ?? '' ),
					'ajustes'    => $encontrado['settings'],
				);
				break;
			}
		}

		/** @var array{post_id:int,element_id:string,ajustes:array<string,mixed>}|null */
		return self::$cache[ $form_id ];
	}

	/**
	 * Ajustes del widget de formulario con ese identificador.
	 *
	 * @param string $form_id Identificador lógico.
	 * @return array<string,mixed>|null
	 */
	public static function ajustes( string $form_id ): ?array {
		$donde = self::ubicacion( $form_id );

		return null !== $donde ? $donde['ajustes'] : null;
	}

	/**
	 * Entrada que contiene el formulario.
	 *
	 * @param string $form_id Identificador lógico.
	 * @return int Cero si no se encontró.
	 */
	public static function pagina( string $form_id ): int {
		$donde = self::ubicacion( $form_id );

		return null !== $donde ? $donde['post_id'] : 0;
	}

	/**
	 * Identificador del elemento de Elementor, que es con el que se guardan los
	 * envíos.
	 *
	 * @param string $form_id Identificador lógico.
	 * @return string
	 */
	public static function element_id( string $form_id ): string {
		$donde = self::ubicacion( $form_id );

		return null !== $donde ? $donde['element_id'] : '';
	}

	/**
	 * Contenidos de Elementor que mencionan ese identificador, por entrada.
	 *
	 * Las revisiones y la papelera también guardan su _elementor_data, y de ahí
	 * saldría una configuración vieja o un enlace a una página que ya no existe.
	 * Solo cuenta lo que está vivo, y lo publicado antes que un borrador.
	 *
	 * @param string $form_id Identificador.
	 * @return array<int,array<int,mixed>>
	 */
	private static function paginas_con( string $form_id ): array {
		global $wpdb;

		$filas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				   FROM {$wpdb->postmeta} pm
				   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = '_elementor_data'
				    AND pm.meta_value LIKE %s
				    AND p.post_type <> 'revision'
				    AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			   ORDER BY ( p.post_status = 'publish' ) DESC, p.ID ASC",
				'%' . $wpdb->esc_like( $form_id ) . '%'
			)
		);

		$salida = array();

		foreach ( (array) $filas as $fila ) {
			$datos = json_decode( (string) $fila->meta_value, true );

			if ( is_array( $datos ) ) {
				$salida[ (int) $fila->post_id ] = $datos;
			}
		}

		return $salida;
	}

	/**
	 * Recorre el árbol buscando el widget de formulario.
	 *
	 * @param array<int|string,mixed> $elementos Nodos.
	 * @param string                  $form_id   Identificador.
	 * @return array{id:string,settings:array<string,mixed>}|null
	 */
	private static function buscar( array $elementos, string $form_id ): ?array {
		foreach ( $elementos as $elemento ) {
			if ( ! is_array( $elemento ) ) {
				continue;
			}

			$ajustes = $elemento['settings'] ?? array();

			if ( 'form' === ( $elemento['widgetType'] ?? '' )
				&& is_array( $ajustes )
				&& $form_id === trim( (string) ( $ajustes['form_id'] ?? '' ) ) ) {
				return array(
					'id'       => (string) ( $elemento['id'] ?? '' ),
					'settings' => $ajustes,
				);
			}

			if ( ! empty( $elemento['elements'] ) && is_array( $elemento['elements'] ) ) {
				$hijo = self::buscar( $elemento['elements'], $form_id );

				if ( null !== $hijo ) {
					return $hijo;
				}
			}
		}

		return null;
	}
}
