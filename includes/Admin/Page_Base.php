<?php
/**
 * Base de las pantallas de listado.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Filtros, paginación y utilidades comunes de las pantallas del panel.
 */
abstract class Page_Base {

	/**
	 * Filas por página.
	 *
	 * @var int
	 */
	protected const POR_PAGINA = 30;

	/**
	 * Identificador de la pantalla.
	 *
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * Comprueba permisos o termina.
	 *
	 * @return void
	 */
	protected function exigir_permisos(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'sw-pagos' ) );
		}
	}

	/**
	 * Lee un filtro de la URL.
	 *
	 * @param string $nombre Nombre.
	 * @return string
	 */
	protected function filtro( string $nombre ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $nombre ] ) ? sanitize_text_field( wp_unslash( $_GET[ $nombre ] ) ) : '';
	}

	/**
	 * Página actual.
	 *
	 * @return int
	 */
	protected function pagina(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	}

	/**
	 * Desplazamiento de la consulta.
	 *
	 * @return int
	 */
	protected function desplazamiento(): int {
		return ( $this->pagina() - 1 ) * self::POR_PAGINA;
	}

	/**
	 * Pinta un formulario de filtros.
	 *
	 * @param array<string,array{etiqueta:string,opciones:array<string,string>}> $selectores Selectores.
	 * @param bool                                                              $con_busqueda Si incluye caja de búsqueda.
	 * @return void
	 */
	protected function filtros( array $selectores, bool $con_busqueda = true ): void {
		echo '<form method="get" class="swpagos-filtros">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( $this->slug() ) );

		foreach ( $selectores as $nombre => $config ) {
			$actual = $this->filtro( $nombre );

			printf( '<label class="screen-reader-text" for="f-%1$s">%2$s</label><select id="f-%1$s" name="%1$s">', esc_attr( $nombre ), esc_html( $config['etiqueta'] ) );
			printf( '<option value="">%s</option>', esc_html( $config['etiqueta'] ) );

			foreach ( $config['opciones'] as $valor => $texto ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $valor ),
					selected( $actual, $valor, false ),
					esc_html( $texto )
				);
			}

			echo '</select> ';
		}

		if ( $con_busqueda ) {
			printf(
				'<input type="search" name="buscar" value="%s" placeholder="%s"> ',
				esc_attr( $this->filtro( 'buscar' ) ),
				esc_attr__( 'Correo, nombre o referencia', 'sw-pagos' )
			);
		}

		printf( '<button type="submit" class="button">%s</button> ', esc_html__( 'Filtrar', 'sw-pagos' ) );
		printf(
			'<a class="button-link" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . $this->slug() ) ),
			esc_html__( 'Limpiar', 'sw-pagos' )
		);

		echo '</form>';
	}

	/**
	 * Pinta la paginación.
	 *
	 * @param int $cuantas Filas devueltas en esta página.
	 * @return void
	 */
	protected function paginacion( int $cuantas ): void {
		$pagina = $this->pagina();

		if ( 1 === $pagina && $cuantas < self::POR_PAGINA ) {
			return;
		}

		echo '<p class="swpagos-paginacion">';

		if ( $pagina > 1 ) {
			printf(
				'<a class="button" href="%s">&laquo; %s</a> ',
				esc_url( add_query_arg( 'paged', $pagina - 1 ) ),
				esc_html__( 'Anteriores', 'sw-pagos' )
			);
		}

		printf(
			'<span class="swpagos-pista">%s</span> ',
			esc_html( sprintf( /* translators: %d: número de página. */ __( 'Página %d', 'sw-pagos' ), $pagina ) )
		);

		if ( $cuantas >= self::POR_PAGINA ) {
			printf(
				'<a class="button" href="%s">%s &raquo;</a>',
				esc_url( add_query_arg( 'paged', $pagina + 1 ) ),
				esc_html__( 'Siguientes', 'sw-pagos' )
			);
		}

		echo '</p>';
	}

	/**
	 * Mensaje de listado vacío.
	 *
	 * @param string $texto Texto.
	 * @return void
	 */
	protected function vacio( string $texto ): void {
		echo '<div class="swpagos-vacio"><p>' . esc_html( $texto ) . '</p></div>';
	}

	/**
	 * Fecha legible.
	 *
	 * @param string $fecha Fecha de base de datos.
	 * @return string
	 */
	protected function fecha( string $fecha ): string {
		$marca = '' !== $fecha ? strtotime( $fecha ) : false;

		return false !== $marca ? wp_date( 'd/m/Y H:i', $marca ) : '—';
	}

	/**
	 * Etiqueta coloreada de un estado.
	 *
	 * @param string $estado Estado.
	 * @param string $texto  Texto a mostrar.
	 * @return string
	 */
	protected function chip( string $estado, string $texto ): string {
		return sprintf(
			'<span class="swpagos-estado-chip swpagos-estado-chip--%s">%s</span>',
			esc_attr( $estado ),
			esc_html( $texto )
		);
	}

	/**
	 * Envía una respuesta CSV y termina.
	 *
	 * @param string              $nombre   Nombre del archivo.
	 * @param array<int,string>   $cabecera Cabecera.
	 * @param array<int,string[]> $filas    Filas.
	 * @return void
	 */
	protected function descargar_csv( string $nombre, array $cabecera, array $filas ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $nombre . '"' );

		$salida = fopen( 'php://output', 'w' );

		// Marca de orden de bytes para que Excel reconozca el UTF-8 y no destroce
		// los acentos, que es lo primero que pasa al abrir el archivo.
		fwrite( $salida, "\xEF\xBB\xBF" );

		fputcsv( $salida, $cabecera );

		foreach ( $filas as $fila ) {
			fputcsv( $salida, $fila );
		}

		fclose( $salida );
		exit;
	}
}
