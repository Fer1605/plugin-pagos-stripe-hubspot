<?php
/**
 * Listado de suscripciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin\Tables;

use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Cobros recurrentes, uno por fila.
 */
final class Subscriptions_Table extends Base_Table {

	/**
	 * Constructor.
	 *
	 * @param string $slug Pantalla.
	 */
	public function __construct( string $slug ) {
		parent::__construct( $slug, __( 'suscripción', 'sw-pagos' ), __( 'suscripciones', 'sw-pagos' ) );
	}

	/**
	 * Columnas.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Alta', 'sw-pagos' ),
			'status'     => __( 'Estado', 'sw-pagos' ),
			'amount'     => __( 'Importe', 'sw-pagos' ),
			'persona'    => __( 'Persona', 'sw-pagos' ),
			'proximo'    => __( 'Próximo cobro', 'sw-pagos' ),
			'mode'       => __( 'Modo', 'sw-pagos' ),
		);
	}

	/**
	 * Listado vacío.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Todavía no hay cobros recurrentes.', 'sw-pagos' );
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
				'buscar' => $this->filtro( 's' ),
			),
			static fn( string $v ): bool => '' !== $v
		);

		$repo       = new Subscription_Repository();
		$por_pagina = $this->por_pagina();
		$pagina     = $this->get_pagenum();
		$total      = $repo->contar( $this->filtros );

		$this->items = $repo->buscar( $this->filtros, $por_pagina, ( $pagina - 1 ) * $por_pagina );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $por_pagina,
				'total_pages' => (int) ceil( $total / $por_pagina ),
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
		$repo = new Subscription_Repository();

		return $this->enlaces_estado(
			self::nombres_estado(),
			$repo->cuentas_por_estado( $this->filtros ),
			$repo->contar( array_diff_key( $this->filtros, array( 'estado' => '' ) ) )
		);
	}

	/**
	 * Filtro de modo.
	 *
	 * @param string $which Posición.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$actual = $this->filtro( 'modo' );

		echo '<div class="alignleft actions">';
		printf( '<label class="screen-reader-text" for="f-modo">%s</label>', esc_html__( 'Todos los modos', 'sw-pagos' ) );
		echo '<select name="modo" id="f-modo">';
		printf( '<option value="">%s</option>', esc_html__( 'Todos los modos', 'sw-pagos' ) );

		foreach ( array( 'test' => __( 'Prueba', 'sw-pagos' ), 'live' => __( 'Producción', 'sw-pagos' ) ) as $valor => $texto ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $valor ), selected( $actual, $valor, false ), esc_html( $texto ) );
		}

		echo '</select> ';
		submit_button( __( 'Filtrar', 'sw-pagos' ), '', 'filtrar', false );
		echo '</div>';
	}

	/**
	 * Celda por omisión.
	 *
	 * @param Subscription $item    Suscripción.
	 * @param string       $columna Columna.
	 * @return string
	 */
	public function column_default( $item, $columna ) {
		switch ( $columna ) {
			case 'created_at':
				return $this->fecha( (string) $item->valor( 'created_at', '' ) );

			case 'status':
				$html = $this->etiqueta( self::tono( $item->estado() ), self::nombres_estado()[ $item->estado() ] ?? $item->estado() );

				if ( $item->cancela_al_final() ) {
					$html .= '<br><span class="swpagos-pista">' . esc_html__( 'se cancela al final del período', 'sw-pagos' ) . '</span>';
				}

				return $html;

			case 'amount':
				return '<strong>' . esc_html( $item->importe_legible() ) . '</strong>'
					. '<br><span class="swpagos-pista">' . esc_html__( 'al mes', 'sw-pagos' ) . '</span>';

			case 'proximo':
				return $this->fecha( (string) $item->proximo_cobro() );

			case 'mode':
				return 'live' === $item->modo()
					? esc_html__( 'producción', 'sw-pagos' )
					: $this->etiqueta( 'neutro', __( 'prueba', 'sw-pagos' ) );
		}

		return '';
	}

	/**
	 * Persona y acciones.
	 *
	 * @param Subscription $item Suscripción.
	 * @return string
	 */
	public function column_persona( $item ) {
		$nombre = $item->nombre();

		$html = '<strong>' . esc_html( '' !== $nombre ? $nombre : __( '(sin nombre)', 'sw-pagos' ) ) . '</strong>';
		$html .= '<br><span class="swpagos-pista">' . esc_html( $item->email() ) . '</span>';

		$acciones = array(
			'sincronizar' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=swpagos_sincronizar_suscripcion&s=' . rawurlencode( $item->uuid() ) ),
						'swpagos_suscripcion_' . $item->uuid()
					)
				),
				esc_html__( 'Sincronizar con Stripe', 'sw-pagos' )
			),
		);

		if ( $item->esta_viva() ) {
			$acciones['cancelar'] = sprintf(
				'<a href="%s" class="swpagos-rojo" onclick="return confirm(%s)">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=swpagos_cancelar_suscripcion&s=' . rawurlencode( $item->uuid() ) ),
						'swpagos_suscripcion_' . $item->uuid()
					)
				),
				esc_attr( wp_json_encode( __( '¿Cancelar esta suscripción al terminar el período ya pagado?', 'sw-pagos' ) ) ),
				esc_html__( 'Cancelar', 'sw-pagos' )
			);
		}

		return $html . $this->row_actions( $acciones );
	}

	/**
	 * Nombres visibles de los estados.
	 *
	 * @return array<string,string>
	 */
	public static function nombres_estado(): array {
		return array(
			Subscription::ACTIVA    => __( 'Activa', 'sw-pagos' ),
			Subscription::IMPAGO    => __( 'En impago', 'sw-pagos' ),
			Subscription::PAUSADA   => __( 'Pausada', 'sw-pagos' ),
			Subscription::CANCELADA => __( 'Cancelada', 'sw-pagos' ),
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
			case Subscription::ACTIVA:
				return 'ok';

			case Subscription::IMPAGO:
				return 'error';

			case Subscription::PAUSADA:
				return 'aviso';
		}

		return 'neutro';
	}
}
