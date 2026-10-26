<?php
/**
 * Listado de transacciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin\Tables;

use SWPagos\Core\Currency;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Cobros, uno por fila.
 */
final class Transactions_Table extends Base_Table {

	/**
	 * Total de filas que casan con el filtro.
	 *
	 * @var int
	 */
	private int $total = 0;

	/**
	 * Constructor.
	 *
	 * @param string $slug Pantalla.
	 */
	public function __construct( string $slug ) {
		parent::__construct( $slug, __( 'transacción', 'sw-pagos' ), __( 'transacciones', 'sw-pagos' ) );
	}

	/**
	 * Columnas.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Fecha', 'sw-pagos' ),
			'status'     => __( 'Estado', 'sw-pagos' ),
			'amount'     => __( 'Importe', 'sw-pagos' ),
			'persona'    => __( 'Persona', 'sw-pagos' ),
			'kind'       => __( 'Tipo', 'sw-pagos' ),
			'form_id'    => __( 'Formulario', 'sw-pagos' ),
			'mode'       => __( 'Modo', 'sw-pagos' ),
		);
	}

	/**
	 * Mensaje de listado vacío.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Todavía no hay cobros que coincidan con el filtro.', 'sw-pagos' );
	}

	/**
	 * Carga las filas.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->filtros = array_filter(
			array(
				'estado' => $this->filtro( 'estado' ),
				'modo'   => $this->filtro( 'modo' ),
				'tipo'   => $this->filtro( 'tipo' ),
				'buscar' => $this->filtro( 's' ),
			),
			static fn( string $v ): bool => '' !== $v
		);

		$repo      = new Transaction_Repository();
		$por_pagina = $this->por_pagina();
		$pagina     = $this->get_pagenum();

		$this->total = $repo->contar( $this->filtros );
		$this->items = $repo->buscar( $this->filtros, $por_pagina, ( $pagina - 1 ) * $por_pagina );

		$this->set_pagination_args(
			array(
				'total_items' => $this->total,
				'per_page'    => $por_pagina,
				'total_pages' => (int) ceil( $this->total / $por_pagina ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );
	}

	/**
	 * Enlaces de estado.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$repo = new Transaction_Repository();

		return $this->enlaces_estado(
			self::nombres_estado(),
			$repo->cuentas_por_estado( $this->filtros ),
			$repo->contar( array_diff_key( $this->filtros, array( 'estado' => '' ) ) )
		);
	}

	/**
	 * Filtros propios encima de la tabla.
	 *
	 * @param string $which Posición.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		$this->selector(
			'modo',
			__( 'Todos los modos', 'sw-pagos' ),
			array(
				'test' => __( 'Prueba', 'sw-pagos' ),
				'live' => __( 'Producción', 'sw-pagos' ),
			)
		);

		$this->selector(
			'tipo',
			__( 'Todos los tipos', 'sw-pagos' ),
			array(
				Transaction::TIPO_UNICO      => __( 'Pago único', 'sw-pagos' ),
				Transaction::TIPO_ALTA_SUSC  => __( 'Alta de suscripción', 'sw-pagos' ),
				Transaction::TIPO_RENOVACION => __( 'Renovación', 'sw-pagos' ),
			)
		);

		submit_button( __( 'Filtrar', 'sw-pagos' ), '', 'filtrar', false );

		echo '</div>';
	}

	/**
	 * Desplegable de filtro.
	 *
	 * @param string                $nombre   Nombre.
	 * @param string                $vacio    Etiqueta de «sin filtro».
	 * @param array<string,string>  $opciones Opciones.
	 * @return void
	 */
	private function selector( string $nombre, string $vacio, array $opciones ): void {
		$actual = $this->filtro( $nombre );

		printf( '<label class="screen-reader-text" for="f-%1$s">%2$s</label><select name="%1$s" id="f-%1$s">', esc_attr( $nombre ), esc_html( $vacio ) );
		printf( '<option value="">%s</option>', esc_html( $vacio ) );

		foreach ( $opciones as $valor => $texto ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $valor ),
				selected( $actual, $valor, false ),
				esc_html( $texto )
			);
		}

		echo '</select> ';
	}

	/**
	 * Celda por omisión.
	 *
	 * @param Transaction $item    Transacción.
	 * @param string      $columna Columna.
	 * @return string
	 */
	public function column_default( $item, $columna ) {
		switch ( $columna ) {
			case 'created_at':
				return $this->fecha( (string) $item->valor( 'created_at', '' ) );

			case 'status':
				return $this->etiqueta( self::tono( $item->estado() ), self::nombres_estado()[ $item->estado() ] ?? $item->estado() );

			case 'kind':
				$tipos = array(
					Transaction::TIPO_UNICO      => __( 'Pago único', 'sw-pagos' ),
					Transaction::TIPO_ALTA_SUSC  => __( 'Alta de suscripción', 'sw-pagos' ),
					Transaction::TIPO_RENOVACION => __( 'Renovación', 'sw-pagos' ),
				);

				return esc_html( $tipos[ $item->tipo() ] ?? $item->tipo() );

			case 'form_id':
				return '<code>' . esc_html( $item->form_id() ) . '</code>';

			case 'mode':
				return 'live' === $item->modo()
					? esc_html__( 'producción', 'sw-pagos' )
					: $this->etiqueta( 'neutro', __( 'prueba', 'sw-pagos' ) );
		}

		return '';
	}

	/**
	 * Importe, con la devolución si la hubo.
	 *
	 * @param Transaction $item Transacción.
	 * @return string
	 */
	public function column_amount( $item ) {
		$html     = '<strong>' . esc_html( $item->importe_legible() ) . '</strong>';
		$devuelto = (int) $item->valor( 'amount_refunded_minor', 0 );

		if ( $devuelto > 0 ) {
			$html .= '<br><span class="swpagos-pista">' . esc_html(
				sprintf(
					/* translators: %s: importe devuelto. */
					__( 'devuelto: %s', 'sw-pagos' ),
					Currency::formatear( $devuelto, $item->moneda() )
				)
			) . '</span>';
		}

		return $html;
	}

	/**
	 * Persona, con el enlace al detalle.
	 *
	 * @param Transaction $item Transacción.
	 * @return string
	 */
	public function column_persona( $item ) {
		$nombre = $item->nombre();

		$html = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $this->url( array( 'ver' => $item->uuid() ) ) ),
			esc_html( '' !== $nombre ? $nombre : __( '(sin nombre)', 'sw-pagos' ) )
		);

		$html .= '<br><span class="swpagos-pista">' . esc_html( $item->email() ) . '</span>';

		$acciones = array(
			'ver' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->url( array( 'ver' => $item->uuid() ) ) ),
				esc_html__( 'Ver detalle', 'sw-pagos' )
			),
		);

		return $html . $this->row_actions( $acciones );
	}

	/**
	 * Nombres visibles de los estados.
	 *
	 * @return array<string,string>
	 */
	public static function nombres_estado(): array {
		return array(
			Transaction::PAGADA     => __( 'Pagada', 'sw-pagos' ),
			Transaction::PENDIENTE  => __( 'Pendiente', 'sw-pagos' ),
			Transaction::PROCESANDO => __( 'Procesando', 'sw-pagos' ),
			Transaction::INICIADA   => __( 'Iniciada', 'sw-pagos' ),
			Transaction::RECHAZADA  => __( 'Rechazada', 'sw-pagos' ),
			Transaction::FALLIDA    => __( 'Fallida', 'sw-pagos' ),
			Transaction::CANCELADA  => __( 'Cancelada', 'sw-pagos' ),
			Transaction::REEMBOLSADA => __( 'Reembolsada', 'sw-pagos' ),
		);
	}

	/**
	 * Color de cada estado.
	 *
	 * @param string $estado Estado.
	 * @return string
	 */
	public static function tono( string $estado ): string {
		switch ( $estado ) {
			case Transaction::PAGADA:
				return 'ok';

			case Transaction::RECHAZADA:
			case Transaction::FALLIDA:
				return 'error';

			case Transaction::PENDIENTE:
			case Transaction::PROCESANDO:
				return 'aviso';
		}

		return 'neutro';
	}
}
