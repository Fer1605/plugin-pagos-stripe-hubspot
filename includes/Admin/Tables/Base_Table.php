<?php
/**
 * Base de los listados del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin\Tables;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Listado con el aspecto de WordPress, no uno inventado.
 *
 * Las tablas propias obligan a aprender una pantalla nueva. Esta hereda lo que
 * ya trae el panel: ordenación, paginación, Opciones de pantalla, búsqueda y
 * enlaces de estado arriba. Lo único propio son las etiquetas de color.
 */
abstract class Base_Table extends \WP_List_Table {

	/**
	 * Identificador de la pantalla que la contiene.
	 *
	 * @var string
	 */
	protected string $slug = '';

	/**
	 * Filtros activos.
	 *
	 * @var array<string,string>
	 */
	protected array $filtros = array();

	/**
	 * Constructor.
	 *
	 * @param string $slug     Pantalla.
	 * @param string $singular Nombre en singular.
	 * @param string $plural   Nombre en plural.
	 */
	public function __construct( string $slug, string $singular, string $plural ) {
		$this->slug = $slug;

		parent::__construct(
			array(
				'singular' => $singular,
				'plural'   => $plural,
				'ajax'     => false,
			)
		);
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
	 * Cuántas filas por página, respetando Opciones de pantalla.
	 *
	 * @return int
	 */
	protected function por_pagina(): int {
		$opcion = $this->get_items_per_page( 'swpagos_' . str_replace( '-', '_', $this->slug ) . '_por_pagina', 30 );

		return max( 1, min( 200, (int) $opcion ) );
	}

	/**
	 * Enlaza a esta misma pantalla con unos argumentos.
	 *
	 * @param array<string,string> $args Argumentos.
	 * @return string
	 */
	protected function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => $this->slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Etiqueta de color para un estado.
	 *
	 * @param string $tono  ok|error|aviso|neutro.
	 * @param string $texto Texto visible.
	 * @return string
	 */
	protected function etiqueta( string $tono, string $texto ): string {
		return sprintf(
			'<span class="swpagos-marca swpagos-marca--%s">%s</span>',
			esc_attr( $tono ),
			esc_html( $texto )
		);
	}

	/**
	 * Fecha local legible, con la hora.
	 *
	 * @param string $valor Fecha en formato MySQL.
	 * @return string
	 */
	protected function fecha( string $valor ): string {
		$marca = '' !== $valor ? strtotime( $valor ) : false;

		if ( false === $marca ) {
			return '—';
		}

		return wp_date( get_option( 'date_format' ), $marca )
			. '<br><span class="swpagos-pista">' . wp_date( get_option( 'time_format' ), $marca ) . '</span>';
	}

	/**
	 * Enlaces de estado de la cabecera.
	 *
	 * @param array<string,string> $nombres  Estado => etiqueta.
	 * @param array<string,int>    $cuentas  Estado => número.
	 * @param int                  $total    Total sin filtrar por estado.
	 * @return array<string,string>
	 */
	protected function enlaces_estado( array $nombres, array $cuentas, int $total ): array {
		$actual  = $this->filtro( 'estado' );
		$enlaces = array();

		$enlaces['todos'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $this->url( array_diff_key( $this->filtros, array( 'estado' => '' ) ) ) ),
			'' === $actual ? ' class="current"' : '',
			esc_html__( 'Todos', 'sw-pagos' ),
			$total
		);

		foreach ( $nombres as $estado => $etiqueta ) {
			$n = (int) ( $cuentas[ $estado ] ?? 0 );

			if ( 0 === $n && $actual !== $estado ) {
				continue;
			}

			$enlaces[ $estado ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->url( array_merge( $this->filtros, array( 'estado' => $estado ) ) ) ),
				$actual === $estado ? ' class="current"' : '',
				esc_html( $etiqueta ),
				$n
			);
		}

		return $enlaces;
	}

	/**
	 * Campos ocultos para que la búsqueda no pierda los filtros.
	 *
	 * @return void
	 */
	protected function conservar_filtros(): void {
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( $this->slug ) );

		foreach ( $this->filtros as $clave => $valor ) {
			if ( '' === $valor || 's' === $clave ) {
				continue;
			}

			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $clave ), esc_attr( (string) $valor ) );
		}
	}
}
