<?php
/**
 * Maquetación de las páginas de resultado de un cobro.
 *
 * @package SWPagos
 */

namespace SWPagos\Frontend;

use SWPagos\Core\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Tarjeta de resultado: estado, mensaje, detalles y una acción.
 *
 * Quien llega aquí acaba de pagar y necesita tres cosas en este orden: saber si
 * salió bien, ver su referencia por si tiene que reclamar, y poder irse a algún
 * sitio. Lo demás sobra.
 */
final class Result_View {

	public const EXITO     = 'exito';
	public const PENDIENTE = 'pendiente';
	public const FALLO     = 'fallo';

	/**
	 * Pinta la tarjeta.
	 *
	 * @param string                    $estado   exito|pendiente|fallo.
	 * @param string                    $titulo   Titular.
	 * @param string                    $cuerpo   HTML ya renderizado.
	 * @param array<string,string>      $detalles Pares etiqueta => valor.
	 * @param array{texto:string,url:string}|null $accion Botón.
	 * @return string
	 */
	public static function tarjeta( string $estado, string $titulo, string $cuerpo, array $detalles = array(), ?array $accion = null ): string {
		$html = '<div class="swpagos-tarjeta-resultado swpagos-tarjeta-resultado--' . esc_attr( $estado ) . '">';

		$html .= '<div class="swpagos-sello" aria-hidden="true">' . self::icono( $estado ) . '</div>';

		if ( '' !== $titulo ) {
			$html .= '<h2 class="swpagos-titulo">' . esc_html( $titulo ) . '</h2>';
		}

		$html .= '<div class="swpagos-cuerpo">' . $cuerpo . '</div>';

		$detalles = array_filter( $detalles, static fn( $v ): bool => '' !== trim( (string) $v ) );

		if ( array() !== $detalles ) {
			$html .= '<dl class="swpagos-detalles">';

			foreach ( $detalles as $etiqueta => $valor ) {
				$html .= '<div class="swpagos-detalle">';
				$html .= '<dt>' . esc_html( (string) $etiqueta ) . '</dt>';
				$html .= '<dd>' . esc_html( (string) $valor ) . '</dd>';
				$html .= '</div>';
			}

			$html .= '</dl>';
		}

		if ( null !== $accion && '' !== $accion['url'] ) {
			$html .= sprintf(
				'<p class="swpagos-acciones"><a class="swpagos-boton" href="%s">%s</a></p>',
				esc_url( $accion['url'] ),
				esc_html( $accion['texto'] )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Marca visual del estado.
	 *
	 * Va en SVG y no en una imagen para que no dependa de ninguna descarga: esta
	 * página se abre una sola vez y tiene que verse bien a la primera.
	 *
	 * @param string $estado Estado.
	 * @return string
	 */
	private static function icono( string $estado ): string {
		$trazos = array(
			self::EXITO     => '<path d="M6 12.6 10.2 16.8 18 9" />',
			self::PENDIENTE => '<circle cx="12" cy="12" r="7.2" /><path d="M12 8v4.4l2.8 1.8" />',
			self::FALLO     => '<path d="M12 7v6" /><circle cx="12" cy="16.6" r="0.4" fill="currentColor" />',
		);

		return '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor"'
			. ' stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">'
			. ( $trazos[ $estado ] ?? $trazos[ self::PENDIENTE ] )
			. '</svg>';
	}

	/**
	 * Hoja de estilos, con los colores del sitio.
	 *
	 * @return string
	 */
	public static function css(): string {
		$c     = Brand::colores();
		$suave = Brand::aclarar( $c['primario'], 0.94 );
		$linea = Brand::aclarar( $c['primario'], 0.86 );

		return '
.swpagos-resultado{--swpagos-primario:' . $c['primario'] . ';--swpagos-acento:' . $c['acento'] . ';--swpagos-texto:' . $c['texto'] . ';--swpagos-suave:' . $suave . ';--swpagos-linea:' . $linea . ';margin:40px auto;max-width:620px}
body.swpagos-pagina-resultado .page-header,body.swpagos-pagina-resultado .entry-header,body.swpagos-pagina-resultado main .entry-title{display:none}
.swpagos-tarjeta-resultado{background:#fff;border:1px solid var(--swpagos-linea);border-radius:18px;padding:40px 36px 36px;text-align:center;box-shadow:0 10px 36px rgba(0,0,0,.06)}
.swpagos-sello{display:inline-flex;align-items:center;justify-content:center;width:62px;height:62px;border-radius:50%;margin-bottom:18px;background:var(--swpagos-suave);color:var(--swpagos-primario)}
.swpagos-tarjeta-resultado--fallo .swpagos-sello{background:#fdecea;color:#b3261e}
.swpagos-tarjeta-resultado--pendiente .swpagos-sello{background:#fff5e5;color:#9a6700}
.swpagos-titulo{margin:0 0 10px;color:var(--swpagos-primario);font-size:1.7em;line-height:1.2}
.swpagos-cuerpo{color:var(--swpagos-texto);font-size:1.02em;line-height:1.6}
.swpagos-cuerpo p{margin:0 0 10px}
.swpagos-cuerpo p:last-child{margin-bottom:0}
.swpagos-detalles{margin:26px 0 0;padding:18px 20px;background:var(--swpagos-suave);border-radius:12px;text-align:left}
.swpagos-detalle{display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-bottom:1px solid var(--swpagos-linea)}
.swpagos-detalle:last-child{border-bottom:0}
.swpagos-detalles dt{margin:0;color:var(--swpagos-texto);font-size:.9em}
.swpagos-detalles dd{margin:0;color:var(--swpagos-primario);font-weight:600;font-size:.92em;text-align:right;word-break:break-word}
.swpagos-acciones{margin:28px 0 0}
.swpagos-resultado .swpagos-boton{display:inline-block;padding:14px 34px;border:0;border-radius:999px;background:var(--swpagos-acento);background-image:none;box-shadow:none;color:#fff;text-decoration:none;font-weight:600;letter-spacing:.02em}
.swpagos-resultado .swpagos-boton:hover,.swpagos-resultado .swpagos-boton:focus{opacity:.9;color:#fff;text-decoration:none}
@media(max-width:560px){.swpagos-tarjeta-resultado{padding:30px 22px 26px}.swpagos-detalle{flex-direction:column;gap:2px}.swpagos-detalles dd{text-align:left}}
';
	}
}
