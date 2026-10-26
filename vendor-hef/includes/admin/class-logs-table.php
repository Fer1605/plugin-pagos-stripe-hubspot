<?php
/**
 * Listado de registros de envío.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF\Admin;

use HEF\Logger;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Logs_Table extends \WP_List_Table {

	/**
	 * @var array
	 */
	protected $counts = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'hef_log',
				'plural'   => 'hef_logs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'id'        => __( 'ID', 'hubspot-elementor-form' ),
			'created_at' => __( 'Fecha', 'hubspot-elementor-form' ),
			'form_name' => __( 'Formulario', 'hubspot-elementor-form' ),
			'target'    => __( 'Destino HubSpot', 'hubspot-elementor-form' ),
			'status'    => __( 'Estado', 'hubspot-elementor-form' ),
			'attempts'  => __( 'Intentos', 'hubspot-elementor-form' ),
			'detail'    => __( 'Detalle', 'hubspot-elementor-form' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'id'         => array( 'id', true ),
			'created_at' => array( 'created_at', false ),
			'status'     => array( 'status', false ),
			'attempts'   => array( 'attempts', false ),
			'form_name'  => array( 'form_name', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'retry'  => __( 'Reintentar envío', 'hubspot-elementor-form' ),
			'delete' => __( 'Eliminar', 'hubspot-elementor-form' ),
		);
	}

	/**
	 * Selector de acciones en lote.
	 *
	 * Se sobreescribe para no usar el parámetro "action" (reservado por
	 * admin-post.php) y renderizarlo solo en la parte superior.
	 *
	 * @param string $which top|bottom.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions bulkactions">';
		echo '<label class="screen-reader-text" for="hef-bulk-action">' . esc_html__( 'Acción en lote', 'hubspot-elementor-form' ) . '</label>';
		echo '<select name="bulk_action" id="hef-bulk-action">';
		echo '<option value="">' . esc_html__( 'Acciones en lote', 'hubspot-elementor-form' ) . '</option>';

		foreach ( $this->get_bulk_actions() as $value => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $value ), esc_html( $label ) );
		}

		echo '</select>';
		submit_button( __( 'Aplicar', 'hubspot-elementor-form' ), 'action', '', false );
		echo '</div>';
	}

	/**
	 * Vistas por estado.
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$base = admin_url( 'admin.php?page=' . Admin::PAGE_LOGS );

		$items = array(
			''                 => __( 'Todos', 'hubspot-elementor-form' ),
			Logger::STATUS_OK  => __( 'Correctos', 'hubspot-elementor-form' ),
			Logger::STATUS_ERROR => __( 'Con error', 'hubspot-elementor-form' ),
			Logger::STATUS_SKIP => __( 'Sin enviar', 'hubspot-elementor-form' ),
			Logger::STATUS_PEND => __( 'Pendientes', 'hubspot-elementor-form' ),
		);

		$views = array();

		foreach ( $items as $key => $label ) {
			$count = '' === $key ? $this->counts['all'] : ( isset( $this->counts[ $key ] ) ? $this->counts[ $key ] : 0 );
			$url   = $key ? add_query_arg( 'status', $key, $base ) : $base;

			$views[ $key ? $key : 'all' ] = sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $url ),
				$current === $key ? 'current' : '',
				esc_html( $label ),
				(int) $count
			);
		}

		return $views;
	}

	public function prepare_items() {
		$per_page = max( 1, (int) $this->get_items_per_page( 'hef_logs_per_page', 20 ) );

		// phpcs:disable WordPress.Security.NonceVerification
		$args = array(
			'id'        => isset( $_REQUEST['hef_id'] ) ? absint( $_REQUEST['hef_id'] ) : 0,
			'status'    => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'form_name' => isset( $_REQUEST['hef_form'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hef_form'] ) ) : '',
			'page_url'  => isset( $_REQUEST['hef_page'] ) ? esc_url_raw( wp_unslash( $_REQUEST['hef_page'] ) ) : '',
			'date_from' => isset( $_REQUEST['hef_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hef_from'] ) ) : '',
			'date_to'   => isset( $_REQUEST['hef_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hef_to'] ) ) : '',
			'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id',
			'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC',
			'paged'    => $this->get_pagenum(),
			'per_page' => $per_page,
		);
		// phpcs:enable

		$this->counts = Logger::counts();

		$result = Logger::query( $args );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'id' );
	}

	public function no_items() {
		esc_html_e( 'Todavía no hay envíos registrados.', 'hubspot-elementor-form' );
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	public function column_id( $item ) {
		$id = (int) $item['id'];

		$retry_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'hef_retry',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'hef_retry_' . $id
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'hef_delete',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'hef_delete_' . $id
		);

		$verify_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'hef_verify',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'hef_verify_' . $id
		);

		$actions = array(
			'retry'  => '<a href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Reintentar', 'hubspot-elementor-form' ) . '</a>',
			'verify' => '<a href="' . esc_url( $verify_url ) . '">' . esc_html__( 'Verificar en HubSpot', 'hubspot-elementor-form' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(\'' . esc_js( __( '¿Eliminar este registro?', 'hubspot-elementor-form' ) ) . '\');">' . esc_html__( 'Eliminar', 'hubspot-elementor-form' ) . '</a>',
		);

		return '<strong>#' . $id . '</strong>' . $this->row_actions( $actions );
	}

	public function column_created_at( $item ) {
		$out = esc_html( mysql2date( 'd/m/Y H:i:s', $item['created_at'] ) );

		// HubSpot muestra los envíos en la zona horaria del portal: se añade
		// la hora UTC para poder comparar sin equivocarse de día.
		$utc = get_gmt_from_date( $item['created_at'], 'd/m/Y H:i' );

		if ( $utc ) {
			$out .= '<br><small class="hef-muted" title="' . esc_attr__( 'Hora universal, para comparar con HubSpot', 'hubspot-elementor-form' ) . '">' . esc_html( $utc ) . ' UTC</small>';
		}

		if ( ! empty( $item['next_retry_at'] ) && Logger::STATUS_ERROR === $item['status'] ) {
			$out .= '<br><small class="hef-muted">' . esc_html(
				sprintf(
					/* translators: %s: fecha */
					__( 'Reintento: %s', 'hubspot-elementor-form' ),
					mysql2date( 'd/m/Y H:i', $item['next_retry_at'] )
				)
			) . '</small>';
		}

		return $out;
	}

	public function column_form_name( $item ) {
		$name = $item['form_name'] ? $item['form_name'] : __( '(sin nombre)', 'hubspot-elementor-form' );
		$out  = '<strong>' . esc_html( $name ) . '</strong>';

		if ( $item['page_url'] ) {
			$out .= '<br><a href="' . esc_url( $item['page_url'] ) . '" target="_blank" rel="noopener"><small>' . esc_html( wp_parse_url( $item['page_url'], PHP_URL_PATH ) ) . '</small></a>';
		}

		// Envío guardado por Elementor (solo si "Collect Submissions" está activo).
		if ( ! empty( $item['submission_id'] ) ) {
			$url = admin_url( 'admin.php?page=e-form-submissions' ) . '#/' . (int) $item['submission_id'];

			$out .= '<br><a href="' . esc_url( $url ) . '"><small>' . esc_html(
				sprintf(
					/* translators: %d: id del envío de Elementor */
					__( 'Envío de Elementor #%d', 'hubspot-elementor-form' ),
					(int) $item['submission_id']
				)
			) . '</small></a>';
		}

		return $out;
	}

	public function column_target( $item ) {
		return '<code>' . esc_html( $item['portal_id'] ) . '</code><br><code class="hef-muted">' . esc_html( $item['hs_form_id'] ) . '</code>';
	}

	public function column_status( $item ) {
		$labels = array(
			Logger::STATUS_OK    => __( 'Correcto', 'hubspot-elementor-form' ),
			Logger::STATUS_ERROR => __( 'Error', 'hubspot-elementor-form' ),
			Logger::STATUS_PEND  => __( 'Pendiente', 'hubspot-elementor-form' ),
			Logger::STATUS_SKIP  => __( 'Sin enviar', 'hubspot-elementor-form' ),
		);

		$label = isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : $item['status'];

		$out = '<span class="hef-badge hef-badge--' . esc_attr( $item['status'] ) . '">' . esc_html( $label ) . '</span>';

		if ( $item['response_code'] ) {
			$out .= ' <small class="hef-muted">HTTP ' . (int) $item['response_code'] . '</small>';
		}

		if ( $item['message'] && Logger::STATUS_OK !== $item['status'] ) {
			$out .= '<br><small>' . esc_html( $item['message'] ) . '</small>';
		}

		return $out;
	}

	public function column_attempts( $item ) {
		return (int) $item['attempts'];
	}

	public function column_detail( $item ) {
		$payload  = $this->pretty( $item['payload'] );
		$response = $this->pretty( $item['response'] );

		$out  = '<details class="hef-details"><summary>' . esc_html__( 'Datos enviados', 'hubspot-elementor-form' ) . '</summary>';
		$out .= '<pre class="hef-pre">' . esc_html( $payload ) . '</pre></details>';

		$out .= '<details class="hef-details"><summary>' . esc_html__( 'Respuesta de HubSpot', 'hubspot-elementor-form' ) . '</summary>';
		$out .= '<pre class="hef-pre">' . esc_html( '' !== $response ? $response : __( '(sin respuesta)', 'hubspot-elementor-form' ) ) . '</pre>';
		$out .= '<p class="hef-muted"><small>' . esc_html( $item['endpoint'] ) . '</small></p></details>';

		return $out;
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Formatea JSON para lectura.
	 *
	 * @param string $raw Texto.
	 * @return string
	 */
	protected function pretty( $raw ) {
		$raw = (string) $raw;

		if ( '' === $raw ) {
			return '';
		}

		$data = json_decode( $raw, true );

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return $raw;
		}

		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
