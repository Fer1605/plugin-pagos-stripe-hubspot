<?php
/**
 * Listado de logs de envío, un cobro por fila.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin\Tables;

use SWPagos\Core\Activator;
use SWPagos\Domain\Delivery_Log;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Elementor\Form_Locator;
use SWPagos\Elementor\Submissions;

defined( 'ABSPATH' ) || exit;

/**
 * Qué pasó con cada envío, en las dos direcciones a la vez.
 *
 * El listado anterior tenía una fila por intento y por canal, así que un mismo
 * cobro aparecía repartido en tres o cuatro filas y había que reconstruirlo
 * mentalmente. Aquí la unidad es el envío: una fila por cobro, con el estado
 * del correo y el de HubSpot uno al lado del otro, que es como se lee.
 */
final class Logs_Table extends Base_Table {

	/**
	 * Estado del correo y de HubSpot de cada fila, por transacción.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $detalle = array();

	/**
	 * Constructor.
	 *
	 * @param string $slug Pantalla.
	 */
	public function __construct( string $slug ) {
		parent::__construct( $slug, __( 'envío', 'sw-pagos' ), __( 'envíos', 'sw-pagos' ) );
	}

	/**
	 * Columnas, en el orden en que se leen.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'id'         => __( 'ID', 'sw-pagos' ),
			'created_at' => __( 'Fecha', 'sw-pagos' ),
			'hubspot'    => __( 'HubSpot', 'sw-pagos' ),
			'correo'     => __( 'Correo', 'sw-pagos' ),
			'form_id'    => __( 'Form ID', 'sw-pagos' ),
			'acciones'   => __( 'Acción', 'sw-pagos' ),
		);
	}

	/**
	 * Listado vacío.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Todavía no hay envíos registrados.', 'sw-pagos' );
	}

	/**
	 * Carga las filas y el estado de cada canal.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$repo = new Transaction_Repository();

		$this->filtros = array_filter(
			array(
				'form_id' => $this->filtro( 'form_id' ),
				'modo'    => $this->filtro( 'modo' ),
				'buscar'  => $this->filtro( 's' ),
			),
			static fn( string $v ): bool => '' !== $v
		);

		$por_pagina = $this->por_pagina();
		$pagina     = $this->get_pagenum();
		$total      = $repo->contar( $this->filtros );

		$this->items = $repo->buscar( $this->filtros, $por_pagina, ( $pagina - 1 ) * $por_pagina );

		$this->cargar_detalle();

		// El filtro por canal se aplica sobre lo ya cargado, porque el estado de
		// cada canal no vive en la tabla de transacciones.
		$este = $this->filtro( 'canal_estado' );

		if ( '' !== $este ) {
			$this->items = array_values(
				array_filter(
					$this->items,
					fn( $t ): bool => $this->coincide( $t, $este )
				)
			);
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $por_pagina,
				'total_pages' => (int) ceil( $total / $por_pagina ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'id' );
	}

	/**
	 * Indica si una transacción encaja con el filtro de problemas.
	 *
	 * @param mixed  $transaccion Transacción.
	 * @param string $cual        correo_error|hubspot_error.
	 * @return bool
	 */
	private function coincide( $transaccion, string $cual ): bool {
		$d = $this->detalle[ $transaccion->id() ] ?? array();

		if ( 'correo_error' === $cual ) {
			return Delivery_Log::FALLO === ( $d['correo_estado'] ?? '' );
		}

		return 'error' === ( $d['hubspot_estado'] ?? '' );
	}

	/**
	 * Trae de una vez el último correo y el registro de HubSpot de cada fila.
	 *
	 * Una consulta por canal para toda la página, no una por fila: con cincuenta
	 * filas serían cien consultas y la pantalla se notaría.
	 *
	 * @return void
	 */
	private function cargar_detalle(): void {
		global $wpdb;

		$this->detalle = array();

		if ( array() === $this->items ) {
			return;
		}

		$ids      = array_map( static fn( $t ): int => $t->id(), $this->items );
		$lista    = implode( ',', array_map( 'absint', $ids ) );
		$entregas = Activator::tabla( 'delivery_logs' );

		// Último intento de correo de cada transacción.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$correos = $wpdb->get_results(
			"SELECT d.transaction_id, d.status, d.message, d.target, d.attempt, d.created_at
			   FROM {$entregas} d
			   JOIN ( SELECT transaction_id, MAX(id) AS ultimo
			            FROM {$entregas}
			           WHERE channel = 'email' AND transaction_id IN ({$lista})
			        GROUP BY transaction_id ) u
			     ON u.ultimo = d.id"
		);

		foreach ( (array) $correos as $fila ) {
			$id = (int) $fila->transaction_id;

			$this->detalle[ $id ]['correo_estado']  = (string) $fila->status;
			$this->detalle[ $id ]['correo_mensaje'] = (string) $fila->message;
			$this->detalle[ $id ]['correo_destino'] = (string) $fila->target;
			$this->detalle[ $id ]['correo_intento'] = (int) $fila->attempt;
		}

		// Registro de HubSpot, que vive en la tabla del módulo incorporado.
		$hef     = $wpdb->prefix . 'hef_logs';
		$por_hef = array();

		foreach ( $this->items as $t ) {
			$registro = (int) ( $t->metadata()['_hef_log'] ?? 0 );

			if ( $registro > 0 ) {
				$por_hef[ $registro ] = $t->id();
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hef ) );

		if ( $existe && array() !== $por_hef ) {
			$claves = implode( ',', array_map( 'absint', array_keys( $por_hef ) ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$filas = $wpdb->get_results( "SELECT id, status, response_code, message, submission_id FROM {$hef} WHERE id IN ({$claves})" );

			foreach ( (array) $filas as $fila ) {
				$id = $por_hef[ (int) $fila->id ] ?? 0;

				if ( 0 === $id ) {
					continue;
				}

				$this->detalle[ $id ]['hubspot_registro'] = (int) $fila->id;
				$this->detalle[ $id ]['hubspot_estado']   = (string) $fila->status;
				$this->detalle[ $id ]['hubspot_codigo']   = (string) $fila->response_code;
				$this->detalle[ $id ]['hubspot_mensaje']  = (string) $fila->message;
				$this->detalle[ $id ]['submission_id']    = (int) $fila->submission_id;
			}
		}
	}

	/**
	 * Enlaces de la parte de arriba.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$actual = $this->filtro( 'canal_estado' );

		$vistas = array(
			''              => __( 'Todos', 'sw-pagos' ),
			'correo_error'  => __( 'Correo con error', 'sw-pagos' ),
			'hubspot_error' => __( 'HubSpot con error', 'sw-pagos' ),
		);

		$salida = array();

		foreach ( $vistas as $clave => $texto ) {
			$salida[ $clave ?: 'todos' ] = sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $this->url( '' === $clave ? array() : array( 'canal_estado' => $clave ) ) ),
				$actual === $clave ? 'current' : '',
				esc_html( $texto )
			);
		}

		return $salida;
	}

	/**
	 * Buscador y filtro de formulario.
	 *
	 * @param string $which Posición.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		printf(
			'<label class="screen-reader-text" for="f-form">%s</label>'
			. '<input type="text" id="f-form" name="form_id" value="%s" placeholder="%s">',
			esc_html__( 'Form ID', 'sw-pagos' ),
			esc_attr( $this->filtro( 'form_id' ) ),
			esc_attr__( 'Form ID', 'sw-pagos' )
		);

		echo ' ';
		submit_button( __( 'Filtrar', 'sw-pagos' ), '', 'filtrar', false );
		echo '</div>';
	}

	/**
	 * Celdas.
	 *
	 * @param mixed  $item    Transacción.
	 * @param string $columna Columna.
	 * @return string
	 */
	public function column_default( $item, $columna ) {
		$d = $this->detalle[ $item->id() ] ?? array();

		switch ( $columna ) {
			case 'id':
				return sprintf(
					'<strong>%d</strong><br><span class="swpagos-pista">%s</span>',
					$item->id(),
					esc_html( mb_substr( $item->uuid(), 0, 8 ) )
				);

			case 'created_at':
				return $this->fecha( (string) $item->valor( 'created_at', '' ) );

			case 'hubspot':
				return $this->celda_hubspot( $d, $item->form_id() );

			case 'correo':
				return $this->celda_correo( $item, $d );

			case 'form_id':
				return $this->celda_formulario( $item, $d );

			case 'acciones':
				return $this->celda_acciones( $item, $d );
		}

		return '';
	}

	/**
	 * Qué formulario fue, dónde está y qué envío le corresponde.
	 *
	 * Tres líneas y no tres columnas, porque las tres contestan a la misma
	 * pregunta: de dónde salió este cobro. Es la forma que ya tiene el panel de
	 * envíos a HubSpot, y conviene que las dos pantallas se lean igual.
	 *
	 * @param mixed               $item Transacción.
	 * @param array<string,mixed> $d    Detalle.
	 * @return string
	 */
	private function celda_formulario( $item, array $d ): string {
		$form_id = (string) $item->form_id();

		$html = '' !== $form_id
			? '<strong>' . esc_html( $form_id ) . '</strong>'
			: '<span class="swpagos-pista">' . esc_html__( '(sin identificador)', 'sw-pagos' ) . '</span>';

		$pagina = '' !== $form_id ? Form_Locator::pagina( $form_id ) : 0;
		$url    = $pagina > 0 ? (string) get_permalink( $pagina ) : '';

		if ( '' !== $url ) {
			$html .= sprintf(
				'<br><a href="%s" target="_blank" rel="noopener"><span class="swpagos-pista">%s</span></a>',
				esc_url( $url ),
				esc_html( (string) wp_parse_url( $url, PHP_URL_PATH ) )
			);
		}

		$envio = (int) ( $d['submission_id'] ?? 0 );

		if ( 0 === $envio ) {
			$envio = Submissions::asegurar( $item );
		}

		if ( $envio > 0 ) {
			$html .= sprintf(
				'<br><a href="%s"><span class="swpagos-pista">%s</span></a>',
				esc_url( Submissions::url( $envio ) ),
				esc_html( sprintf( /* translators: %d: identificador del envío de Elementor. */ __( 'Submission ID #%d', 'sw-pagos' ), $envio ) )
			);

			return $html;
		}

		// Sin «Collect Submissions» no hay envío que enlazar, y conviene decirlo:
		// es un ajuste del formulario, no un fallo del cobro.
		return $html . '<br><span class="swpagos-pista">' . esc_html__( 'Sin Submission ID', 'sw-pagos' ) . '</span>';
	}

	/**
	 * Estado de HubSpot.
	 *
	 * @param array<string,mixed> $d Detalle.
	 * @return string
	 */
	private function celda_hubspot( array $d, string $form_id ): string {
		if ( ! isset( $d['hubspot_estado'] ) ) {
			// Sin registro de HubSpot hay tres motivos posibles, y conviene
			// distinguirlos: lo que se hace en cada caso es distinto.
			switch ( $this->hubspot_del_formulario( $form_id ) ) {
				case 'configurado':
					return '<span class="swpagos-pista">' . esc_html__( 'Sin registro de este envío', 'sw-pagos' ) . '</span>';

				case 'incompleto':
					return $this->etiqueta( 'aviso', __( 'Falta configurarlo', 'sw-pagos' ) )
						. '<br><span class="swpagos-pista">'
						. esc_html__( 'El formulario lleva el campo de HubSpot, pero sin la URL escaneada.', 'sw-pagos' )
						. '</span>';
			}

			return '<span class="swpagos-pista">' . esc_html__( 'Este formulario no envía a HubSpot', 'sw-pagos' ) . '</span>';
		}

		$estado = (string) $d['hubspot_estado'];

		$nombres = array(
			'success' => __( 'Enviado', 'sw-pagos' ),
			'error'   => __( 'Con error', 'sw-pagos' ),
			'pending' => __( 'Pendiente', 'sw-pagos' ),
			'skipped' => __( 'Esperando el cobro', 'sw-pagos' ),
		);

		$tonos = array(
			'success' => 'ok',
			'error'   => 'error',
			'pending' => 'aviso',
			'skipped' => 'neutro',
		);

		$html = $this->etiqueta( $tonos[ $estado ] ?? 'neutro', $nombres[ $estado ] ?? $estado );

		if ( '' !== (string) ( $d['hubspot_codigo'] ?? '' ) ) {
			$html .= ' <code>' . esc_html( (string) $d['hubspot_codigo'] ) . '</code>';
		}

		if ( '' !== (string) ( $d['hubspot_mensaje'] ?? '' ) ) {
			$html .= '<br><span class="swpagos-pista">' . esc_html( mb_substr( (string) $d['hubspot_mensaje'], 0, 120 ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * Cómo está HubSpot en un formulario: no, incompleto o configurado.
	 *
	 * @param string $form_id Formulario.
	 * @return string
	 */
	private function hubspot_del_formulario( string $form_id ): string {
		static $cache = array();

		if ( isset( $cache[ $form_id ] ) ) {
			return $cache[ $form_id ];
		}

		$ajustes = '' !== $form_id ? Form_Locator::ajustes( $form_id ) : null;
		$estado  = 'no';

		foreach ( (array) ( $ajustes['form_fields'] ?? array() ) as $campo ) {
			if ( ! is_array( $campo ) || \SWPagos\Core\Compat::CAMPO_HUBSPOT !== ( $campo['field_type'] ?? '' ) ) {
				continue;
			}

			$estado = ( '' !== trim( (string) ( $campo['hef_portal_id'] ?? '' ) ) && '' !== trim( (string) ( $campo['hef_form_id'] ?? '' ) ) )
				? 'configurado'
				: 'incompleto';
			break;
		}

		$cache[ $form_id ] = $estado;

		return $estado;
	}

	/**
	 * Estado del correo.
	 *
	 * @param mixed               $item Transacción.
	 * @param array<string,mixed> $d    Detalle.
	 * @return string
	 */
	private function celda_correo( $item, array $d ): string {
		if ( ! isset( $d['correo_estado'] ) ) {
			return '<span class="swpagos-pista">' . esc_html__( 'Sin correo todavía', 'sw-pagos' ) . '</span>';
		}

		$estado = (string) $d['correo_estado'];

		$nombres = array(
			Delivery_Log::EXITO     => __( 'Entregado', 'sw-pagos' ),
			Delivery_Log::FALLO     => __( 'Con error', 'sw-pagos' ),
			Delivery_Log::PENDIENTE => __( 'Pendiente', 'sw-pagos' ),
		);

		$tonos = array(
			Delivery_Log::EXITO     => 'ok',
			Delivery_Log::FALLO     => 'error',
			Delivery_Log::PENDIENTE => 'aviso',
		);

		$html = $this->etiqueta( $tonos[ $estado ] ?? 'neutro', $nombres[ $estado ] ?? $estado );

		$destino = (string) ( $d['correo_destino'] ?? $item->email() );

		if ( '' !== $destino ) {
			$html .= ' ' . esc_html( mb_substr( $destino, 0, 42 ) );
		}

		$intento = (int) ( $d['correo_intento'] ?? 0 );

		if ( $intento > 1 ) {
			$html .= sprintf(
				' <span class="swpagos-pista">%s</span>',
				esc_html( sprintf( /* translators: %d: número de intento. */ __( 'intento %d', 'sw-pagos' ), $intento ) )
			);
		}

		if ( '' !== (string) ( $d['correo_mensaje'] ?? '' ) ) {
			$html .= '<br><span class="swpagos-pista">' . esc_html( mb_substr( (string) $d['correo_mensaje'], 0, 120 ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * Los dos botones de reenvío.
	 *
	 * @param mixed               $item Transacción.
	 * @param array<string,mixed> $d    Detalle.
	 * @return string
	 */
	private function celda_acciones( $item, array $d ): string {
		$botones = array();

		$botones[] = sprintf(
			'<a class="button button-small" href="%s">%s</a>',
			esc_url( $this->enlace( 'swpagos_reenviar_correo', $item->id() ) ),
			esc_html__( 'Reenviar correo', 'sw-pagos' )
		);

		if ( isset( $d['hubspot_registro'] ) ) {
			$botones[] = sprintf(
				'<a class="button button-small" href="%s">%s</a>',
				esc_url( $this->enlace( 'swpagos_reenviar_hubspot', $item->id() ) ),
				esc_html__( 'Reenviar HubSpot', 'sw-pagos' )
			);
		}

		return '<div class="swpagos-botones">' . implode( '', $botones ) . '</div>';
	}

	/**
	 * Enlace firmado de una acción.
	 *
	 * @param string $accion Acción.
	 * @param int    $id     Transacción.
	 * @return string
	 */
	private function enlace( string $accion, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => $accion,
					'transaccion' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			$accion . '_' . $id
		);
	}
}
