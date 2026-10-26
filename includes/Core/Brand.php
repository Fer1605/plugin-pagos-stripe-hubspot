<?php
/**
 * Identidad visual del sitio, leída de donde ya está definida.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Colores y logotipo con los que se pintan las páginas de resultado y los correos.
 *
 * No se fijan en el código: se leen del Kit de Elementor y del logotipo del tema,
 * que es donde el sitio ya los tiene. Así el módulo se ve propio de cada sitio
 * donde se instale, y si alguien cambia el color del Kit, esto cambia con él.
 */
final class Brand {

	/**
	 * Colores de respaldo, para un sitio sin Kit de Elementor.
	 *
	 * @var array<string,string>
	 */
	private const RESPALDO = array(
		'primario'  => '#1f2933',
		'secundario' => '#e6ebef',
		'texto'     => '#52606d',
		'acento'    => '#0f6cbf',
	);

	/**
	 * Colores del sitio.
	 *
	 * @return array{primario:string,secundario:string,texto:string,acento:string}
	 */
	public static function colores(): array {
		$cacheado = wp_cache_get( 'swpagos_marca_colores' );

		if ( is_array( $cacheado ) ) {
			return $cacheado;
		}

		$colores = self::RESPALDO;
		$kit     = (int) get_option( 'elementor_active_kit' );

		if ( $kit > 0 ) {
			$ajustes = get_post_meta( $kit, '_elementor_page_settings', true );
			$mapa    = array(
				'primary'   => 'primario',
				'secondary' => 'secundario',
				'text'      => 'texto',
				'accent'    => 'acento',
			);

			foreach ( (array) ( $ajustes['system_colors'] ?? array() ) as $color ) {
				$id = (string) ( $color['_id'] ?? '' );

				if ( isset( $mapa[ $id ] ) && self::es_color( (string) ( $color['color'] ?? '' ) ) ) {
					$colores[ $mapa[ $id ] ] = (string) $color['color'];
				}
			}
		}

		wp_cache_set( 'swpagos_marca_colores', $colores );

		return $colores;
	}

	/**
	 * Versión más clara de un color, para fondos suaves.
	 *
	 * @param string $hex     Color de origen.
	 * @param float  $mezcla  Proporción de blanco, de 0 a 1.
	 * @return string
	 */
	public static function aclarar( string $hex, float $mezcla = 0.9 ): string {
		if ( ! self::es_color( $hex ) ) {
			return '#f4f6f8';
		}

		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$salida = '#';

		for ( $i = 0; $i < 3; $i++ ) {
			$canal  = (int) hexdec( substr( $hex, $i * 2, 2 ) );
			$canal  = (int) round( $canal + ( 255 - $canal ) * $mezcla );
			$salida .= str_pad( dechex( max( 0, min( 255, $canal ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return $salida;
	}

	/**
	 * URL del logotipo del sitio, si lo hay.
	 *
	 * @return string
	 */
	public static function logo(): string {
		$id = (int) get_theme_mod( 'custom_logo' );

		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'medium' );

			if ( is_string( $url ) ) {
				return $url;
			}
		}

		$icono = get_site_icon_url( 180 );

		return is_string( $icono ) ? $icono : '';
	}

	/**
	 * Nombre del sitio.
	 *
	 * @return string
	 */
	public static function nombre(): string {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Comprueba que una cadena sea un color hexadecimal.
	 *
	 * @param string $valor Valor.
	 * @return bool
	 */
	private static function es_color( string $valor ): bool {
		return 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $valor ) );
	}
}
