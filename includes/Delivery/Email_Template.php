<?php
/**
 * Envoltorio HTML de los correos del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Delivery;

use SWPagos\Core\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Da al cuerpo escrito en la plantilla la forma de un correo presentable.
 *
 * Los clientes de correo no entienden hojas de estilo externas ni flexbox: esto
 * va con tablas y estilos en línea a propósito, que es lo único que se ve igual
 * en Gmail, Outlook y Apple Mail. El ancho fijo de 600 px es el que cabe en
 * todos sin scroll horizontal.
 */
final class Email_Template {

	/**
	 * Envuelve el cuerpo.
	 *
	 * @param string               $cuerpo    HTML del mensaje.
	 * @param string               $titular   Titular de la cabecera.
	 * @param array<string,string> $detalles  Pares etiqueta => valor.
	 * @param array{texto:string,url:string}|null $accion Botón.
	 * @return string
	 */
	public static function envolver( string $cuerpo, string $titular = '', array $detalles = array(), ?array $accion = null ): string {
		$c       = Brand::colores();
		$suave   = Brand::aclarar( $c['primario'], 0.94 );
		$linea   = Brand::aclarar( $c['primario'], 0.84 );
		$nombre  = Brand::nombre();
		$logo    = Brand::logo();

		$cabecera = '' !== $logo
			? sprintf(
				'<img src="%s" alt="%s" width="150" style="display:block;margin:0 auto;max-width:150px;height:auto;border:0">',
				esc_url( $logo ),
				esc_attr( $nombre )
			)
			: sprintf(
				'<span style="font-size:20px;font-weight:700;color:%s">%s</span>',
				esc_attr( $c['primario'] ),
				esc_html( $nombre )
			);

		$filas = '';

		foreach ( array_filter( $detalles, static fn( $v ): bool => '' !== trim( (string) $v ) ) as $etiqueta => $valor ) {
			$filas .= sprintf(
				'<tr>
					<td style="padding:9px 0;border-bottom:1px solid %1$s;color:%2$s;font-size:14px">%3$s</td>
					<td style="padding:9px 0;border-bottom:1px solid %1$s;color:%4$s;font-size:14px;font-weight:600;text-align:right">%5$s</td>
				</tr>',
				esc_attr( $linea ),
				esc_attr( $c['texto'] ),
				esc_html( (string) $etiqueta ),
				esc_attr( $c['primario'] ),
				esc_html( (string) $valor )
			);
		}

		$bloque_detalles = '' !== $filas
			? sprintf(
				'<table role="presentation" width="100%%" cellpadding="0" cellspacing="0"
					style="margin:26px 0 0;background:%s;border-radius:12px;padding:6px 18px">
					%s
				</table>',
				esc_attr( $suave ),
				$filas
			)
			: '';

		$bloque_accion = null !== $accion && '' !== $accion['url']
			? sprintf(
				'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:30px auto 0">
					<tr><td style="border-radius:999px;background:%s">
						<a href="%s" style="display:inline-block;padding:14px 34px;color:#ffffff;
							text-decoration:none;font-weight:600;font-size:15px;border-radius:999px">%s</a>
					</td></tr>
				</table>',
				esc_attr( $c['acento'] ),
				esc_url( $accion['url'] ),
				esc_html( $accion['texto'] )
			)
			: '';

		$bloque_titular = '' !== $titular
			? sprintf(
				'<h1 style="margin:0 0 14px;color:%s;font-size:24px;line-height:1.25;font-weight:700">%s</h1>',
				esc_attr( $c['primario'] ),
				esc_html( $titular )
			)
			: '';

		return sprintf(
			'<!DOCTYPE html>
<html lang="%1$s"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>%2$s</title>
</head>
<body style="margin:0;padding:0;background:%3$s;-webkit-font-smoothing:antialiased">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background:%3$s">
<tr><td align="center" style="padding:32px 16px">

	<table role="presentation" width="600" cellpadding="0" cellspacing="0"
		style="width:100%%;max-width:600px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif">

		<tr><td align="center" style="padding:0 0 22px">%4$s</td></tr>

		<tr><td style="background:#ffffff;border:1px solid %5$s;border-radius:18px;padding:38px 34px">
			%6$s
			<div style="color:%7$s;font-size:15px;line-height:1.65">%8$s</div>
			%9$s
			%10$s
		</td></tr>

		<tr><td align="center" style="padding:22px 10px 0;color:%7$s;font-size:12px;line-height:1.6">
			%11$s<br>
			<a href="%12$s" style="color:%7$s;text-decoration:underline">%13$s</a>
		</td></tr>

	</table>

</td></tr>
</table>
</body></html>',
			esc_attr( str_replace( '_', '-', (string) get_locale() ) ),
			esc_html( '' !== $titular ? $titular : $nombre ),
			esc_attr( $suave ),
			$cabecera,
			esc_attr( $linea ),
			$bloque_titular,
			esc_attr( $c['texto'] ),
			$cuerpo,
			$bloque_detalles,
			$bloque_accion,
			esc_html( $nombre ),
			esc_url( home_url( '/' ) ),
			esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
		);
	}
}
