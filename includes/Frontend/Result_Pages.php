<?php
/**
 * Páginas de resultado genéricas del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Frontend;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Garantiza que siempre haya a dónde volver tras pagar.
 *
 * Dejar en blanco la página de resultado en la plantilla tiene que ser una
 * opción válida, no una trampa: quien monta una campaña rápida no debería estar
 * obligado a crear dos páginas antes de poder cobrar. Si no hay página propia,
 * el módulo usa la suya, y la crea la primera vez que hace falta.
 *
 * Son páginas normales de WordPress: se pueden editar, maquetar con Elementor y
 * traducir como cualquier otra. Lo único que las distingue es una marca que
 * permite reencontrarlas aunque alguien borre la opción.
 */
final class Result_Pages {

	/**
	 * Opción con los identificadores, por resultado e idioma.
	 *
	 * @var string
	 */
	public const OPCION = 'swpagos_paginas_resultado';

	/**
	 * Marca que llevan estas páginas.
	 *
	 * @var string
	 */
	private const MARCA = '_swpagos_pagina_resultado';

	/**
	 * URL de la página genérica de un resultado.
	 *
	 * @param string $cual   exito|fallo.
	 * @param string $locale Idioma de la transacción, por ejemplo «en_US».
	 * @return string Cadena vacía si no se pudo crear.
	 */
	public static function url( string $cual, string $locale = '' ): string {
		$id = self::asegurar( $cual, self::idioma( $locale ) );

		return $id > 0 ? (string) get_permalink( $id ) : home_url( '/' );
	}

	/**
	 * Identificadores ya creados, para poder enlazarlos desde el panel.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function existentes(): array {
		$guardado = get_option( self::OPCION, array() );

		return is_array( $guardado ) ? $guardado : array();
	}

	/**
	 * Devuelve la página de un resultado en un idioma, creándola si no está.
	 *
	 * @param string $cual   exito|fallo.
	 * @param string $idioma Código corto de idioma, o cadena vacía.
	 * @return int
	 */
	public static function asegurar( string $cual, string $idioma = '' ): int {
		$cual     = 'fallo' === $cual ? 'fallo' : 'exito';
		$guardado = self::existentes();
		$clave    = '' !== $idioma ? $idioma : 'x';

		$id = (int) ( $guardado[ $cual ][ $clave ] ?? 0 );

		if ( $id > 0 && self::sirve( $id ) ) {
			return $id;
		}

		// La opción puede haberse perdido; la marca en la propia página no.
		$id = self::buscar_por_marca( $cual, $idioma );

		if ( 0 === $id ) {
			$id = self::crear( $cual, $idioma );
		}

		if ( $id > 0 ) {
			$guardado[ $cual ][ $clave ] = $id;
			update_option( self::OPCION, $guardado, false );
		}

		return $id;
	}

	/**
	 * Crea las cuatro páginas posibles de una vez.
	 *
	 * @return void
	 */
	public static function asegurar_todas(): void {
		foreach ( array( 'exito', 'fallo' ) as $cual ) {
			foreach ( self::idiomas() as $idioma ) {
				self::asegurar( $cual, $idioma );
			}
		}
	}

	/**
	 * Indica si una página sigue existiendo y es usable.
	 *
	 * @param int $id Página.
	 * @return bool
	 */
	private static function sirve( int $id ): bool {
		$p = get_post( $id );

		return $p instanceof \WP_Post && 'page' === $p->post_type && 'publish' === $p->post_status;
	}

	/**
	 * Busca la página por su marca.
	 *
	 * @param string $cual   exito|fallo.
	 * @param string $idioma Idioma.
	 * @return int
	 */
	private static function buscar_por_marca( string $cual, string $idioma ): int {
		$encontradas = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 20,
				'meta_key'         => self::MARCA,
				'meta_value'       => $cual,
				'suppress_filters' => true,
				'fields'           => 'ids',
			)
		);

		foreach ( (array) $encontradas as $id ) {
			if ( '' === $idioma || ! function_exists( 'pll_get_post_language' ) ) {
				return (int) $id;
			}

			if ( $idioma === pll_get_post_language( (int) $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Crea la página.
	 *
	 * @param string $cual   exito|fallo.
	 * @param string $idioma Idioma.
	 * @return int
	 */
	private static function crear( string $cual, string $idioma ): int {
		$cambiado = '' !== $idioma ? self::cambiar_idioma( $idioma ) : false;

		$textos = 'exito' === $cual
			? array(
				'titulo'    => __( 'Pago recibido', 'sw-pagos' ),
				'slug'      => __( 'pago-recibido', 'sw-pagos' ),
				'shortcode' => Compat::SHORTCODES[0],
			)
			: array(
				'titulo'    => __( 'No se completó el pago', 'sw-pagos' ),
				'slug'      => __( 'pago-no-completado', 'sw-pagos' ),
				'shortcode' => Compat::SHORTCODES[1],
			);

		if ( $cambiado ) {
			restore_previous_locale();
		}

		$id = wp_insert_post(
			array(
				'post_title'   => $textos['titulo'],
				'post_name'    => wp_unique_post_slug( $textos['slug'], 0, 'publish', 'page', 0 ),
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[' . $textos['shortcode'] . ']',
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			Plugin::instancia()->logger()->warning(
				'No se pudo crear la página de resultado del módulo.',
				array(
					'resultado' => $cual,
					'idioma'    => $idioma,
					'error'     => $id->get_error_message(),
				)
			);

			return 0;
		}

		$id = (int) $id;

		update_post_meta( $id, self::MARCA, $cual );

		if ( '' !== $idioma && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $id, $idioma );
			self::enlazar_traducciones( $cual );
		}

		Plugin::instancia()->logger()->info(
			'Creada la página de resultado del módulo porque la plantilla no indicaba ninguna.',
			array(
				'resultado' => $cual,
				'idioma'    => $idioma,
				'pagina'    => $id,
			)
		);

		return $id;
	}

	/**
	 * Enlaza entre sí las páginas de un mismo resultado.
	 *
	 * @param string $cual exito|fallo.
	 * @return void
	 */
	private static function enlazar_traducciones( string $cual ): void {
		if ( ! function_exists( 'pll_save_post_translations' ) ) {
			return;
		}

		$pares = array();

		foreach ( self::idiomas() as $idioma ) {
			$id = self::buscar_por_marca( $cual, $idioma );

			if ( $id > 0 ) {
				$pares[ $idioma ] = $id;
			}
		}

		if ( count( $pares ) > 1 ) {
			pll_save_post_translations( $pares );
		}
	}

	/**
	 * Idiomas del sitio, o uno solo si no hay traductor.
	 *
	 * @return string[]
	 */
	private static function idiomas(): array {
		if ( function_exists( 'pll_languages_list' ) ) {
			$lista = (array) pll_languages_list( array( 'fields' => 'slug' ) );

			if ( array() !== $lista ) {
				return $lista;
			}
		}

		return array( '' );
	}

	/**
	 * Código corto de idioma a partir del locale de la transacción.
	 *
	 * @param string $locale Locale, por ejemplo «es_GT».
	 * @return string
	 */
	private static function idioma( string $locale ): string {
		$idiomas = self::idiomas();

		if ( array( '' ) === $idiomas ) {
			return '';
		}

		$corto = strtolower( substr( $locale, 0, 2 ) );

		if ( '' !== $corto && in_array( $corto, $idiomas, true ) ) {
			return $corto;
		}

		if ( function_exists( 'pll_default_language' ) ) {
			$defecto = (string) pll_default_language( 'slug' );

			if ( '' !== $defecto ) {
				return $defecto;
			}
		}

		return (string) $idiomas[0];
	}

	/**
	 * Cambia el idioma en curso para que los textos salgan traducidos.
	 *
	 * @param string $idioma Código corto.
	 * @return bool Si hubo cambio.
	 */
	private static function cambiar_idioma( string $idioma ): bool {
		if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'switch_to_locale' ) ) {
			return false;
		}

		foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $lengua ) {
			if ( is_object( $lengua ) && $idioma === $lengua->slug && ! empty( $lengua->locale ) ) {
				return switch_to_locale( $lengua->locale );
			}
		}

		return false;
	}
}
