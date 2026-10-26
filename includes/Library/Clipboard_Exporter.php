<?php
/**
 * Exportación de una variante al portapapeles del editor de Elementor.
 *
 * @package SWPagos
 */

namespace SWPagos\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Prepara el contenido que el navegador deja en el portapapeles de Elementor.
 *
 * Elementor guarda lo copiado en el almacenamiento local del navegador bajo la
 * clave `clipboard`, en el mismo origen que el panel de administración. Eso es lo
 * que permite copiar desde aquí y pegar en el editor con Ctrl/Cmd+V.
 *
 * El valor NO es la lista de elementos a secas, sino un sobre
 * `{ type, siteurl, elements }`. Al pegar, Elementor comprueba que `type` sea
 * «elementor» y que `elements` traiga algo; si no, no pega y tampoco avisa.
 * Y si `siteurl` no coincide con el de este sitio, da un rodeo por el servidor
 * para importar los elementos, que es más lento y puede fallar.
 *
 * Es un formato interno de Elementor, no una API pública: por eso cada variante
 * está además registrada como plantilla de la biblioteca, que es la vía soportada
 * y sigue funcionando si este formato cambia (decisión D-08).
 */
final class Clipboard_Exporter {

	/**
	 * Clave del almacenamiento local que usa Elementor.
	 *
	 * Centralizada aquí para que ajustarla ante un cambio de Elementor sea tocar
	 * una línea y no buscar por todo el módulo.
	 *
	 * @var string
	 */
	public const CLAVE_ALMACEN = 'elementor';

	/**
	 * Subclave del portapapeles dentro de ese almacén.
	 *
	 * @var string
	 */
	public const CLAVE_PORTAPAPELES = 'clipboard';

	/**
	 * Marca de origen que Elementor exige en lo copiado.
	 *
	 * @var string
	 */
	public const TIPO = 'elementor';

	/**
	 * Árbol de elementos de una variante, listo para el portapapeles.
	 *
	 * @param string $clave Clave de la variante.
	 * @return array<int,array<string,mixed>>|null
	 */
	public function elementos( string $clave ): ?array {
		return Variants::elementos( $clave );
	}

	/**
	 * Datos que la pantalla entrega al navegador.
	 *
	 * @return array<string,mixed>
	 */
	public function datos_para_pantalla(): array {
		$variantes = array();

		foreach ( array_keys( Variants::catalogo() ) as $clave ) {
			$variantes[ $clave ] = Variants::elementos( $clave );
		}

		return array(
			'almacen'      => self::CLAVE_ALMACEN,
			'portapapeles' => self::CLAVE_PORTAPAPELES,
			'tipo'         => self::TIPO,
			// Elementor compara esto con elementorCommon.config.urls.rest.
			'siteurl'      => get_rest_url(),
			'variantes'    => $variantes,
			'textos'      => array(
				'copiado' => __( 'Formulario copiado. Abre una página con Elementor y pega con Ctrl/Cmd + V. Si el editor ya estaba abierto, recárgalo antes de pegar.', 'sw-pagos' ),
				'fallo'   => __( 'No se pudo usar el portapapeles del editor. Usa «Insertar desde Mis plantillas», que no depende del navegador.', 'sw-pagos' ),
			),
		);
	}
}
