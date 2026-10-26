<?php
/**
 * Registro de envíos a HubSpot.
 *
 * @package HubSpot_Elementor_Form
 */

namespace HEF;

defined( 'ABSPATH' ) || exit;

class Logger {

	const DB_VERSION    = '1.1.0';
	const DB_OPTION     = 'hef_db_version';
	const STATUS_OK     = 'success';
	const STATUS_ERROR  = 'error';
	const STATUS_PEND   = 'pending';
	const STATUS_SKIP   = 'skipped';

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'hef_logs';
	}

	/**
	 * Crea/actualiza la tabla.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			form_name VARCHAR(255) NOT NULL DEFAULT '',
			form_id VARCHAR(100) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			portal_id VARCHAR(50) NOT NULL DEFAULT '',
			hs_form_id VARCHAR(100) NOT NULL DEFAULT '',
			endpoint VARCHAR(255) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			response_code SMALLINT(6) NOT NULL DEFAULT 0,
			response LONGTEXT NULL,
			message TEXT NULL,
			attempts SMALLINT(6) NOT NULL DEFAULT 0,
			next_retry_at DATETIME NULL,
			page_url VARCHAR(255) NOT NULL DEFAULT '',
			submission_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(100) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY next_retry_at (next_retry_at),
			KEY form_name (form_name)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Crea la tabla si el plugin se actualizó sin pasar por la activación.
	 */
	public static function maybe_upgrade_table() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Inserta un registro.
	 *
	 * @param array $data Datos.
	 * @return int ID insertado (0 si falla).
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = wp_parse_args(
			$data,
			array(
				'created_at'    => $now,
				'updated_at'    => $now,
				'status'        => self::STATUS_PEND,
				'form_name'     => '',
				'form_id'       => '',
				'post_id'       => 0,
				'portal_id'     => '',
				'hs_form_id'    => '',
				'endpoint'      => '',
				'payload'       => '',
				'response_code' => 0,
				'response'      => '',
				'message'       => '',
				'attempts'      => 0,
				'next_retry_at' => null,
				'page_url'      => '',
				'ip'            => '',
				'submission_id' => 0,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Actualiza un registro.
	 *
	 * @param int   $id   ID.
	 * @param array $data Campos.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Obtiene un registro.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	/**
	 * Elimina registros.
	 *
	 * @param array $ids IDs.
	 * @return int Filas eliminadas.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Consulta paginada para la pantalla de registros.
	 *
	 * @param array $args status, search, orderby, order, per_page, paged.
	 * @return array {items:array, total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'id'        => 0,
				'status'    => '',
				'search'    => '',
				'form_name' => '',
				'page_url'  => '',
				'date_from' => '',
				'date_to'   => '',
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 20,
				'paged'    => 1,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] && in_array( $args['status'], array( self::STATUS_OK, self::STATUS_ERROR, self::STATUS_PEND, self::STATUS_SKIP ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['id'] ) ) {
			$where[]  = 'id = %d';
			$params[] = (int) $args['id'];
		}

		if ( '' !== $args['form_name'] ) {
			$where[]  = 'form_name = %s';
			$params[] = $args['form_name'];
		}

		if ( '' !== $args['page_url'] ) {
			$where[]  = 'page_url = %s';
			$params[] = $args['page_url'];
		}

		if ( '' !== $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}

		if ( '' !== $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( form_name LIKE %s OR payload LIKE %s OR response LIKE %s OR hs_form_id LIKE %s OR portal_id LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$allowed_orderby = array( 'id', 'created_at', 'updated_at', 'status', 'attempts', 'form_name', 'response_code' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$sql_count = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$sql_items = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) ) : $wpdb->get_var( $sql_count ) );
		$items = $wpdb->get_results( $wpdb->prepare( $sql_items, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Valores distintos de una columna, para los desplegables de filtro.
	 *
	 * @param string $column form_name|page_url.
	 * @return array
	 */
	public static function distinct( $column ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'form_name', 'page_url' ), true ) ) {
			return array();
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} <> '' ORDER BY {$column} ASC LIMIT 200" );

		return $rows ? $rows : array();
	}

	/**
	 * Conteo por estado.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$out = array(
			'all'              => 0,
			self::STATUS_OK    => 0,
			self::STATUS_ERROR => 0,
			self::STATUS_PEND  => 0,
			self::STATUS_SKIP  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['total'];
			$out['all']           += (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Registros pendientes de reintento automático.
	 *
	 * @param int $max_attempts Intentos máximos.
	 * @param int $limit        Máximo por ejecución.
	 * @return array
	 */
	public static function get_retryable( $max_attempts, $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			  AND attempts < %d
			  AND ( next_retry_at IS NULL OR next_retry_at <= %s )
			ORDER BY id ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, self::STATUS_ERROR, (int) $max_attempts, $now, (int) $limit ), ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * Borra registros correctos más antiguos que X días.
	 *
	 * @param int $days Días.
	 * @return int
	 */
	public static function purge( $days ) {
		global $wpdb;

		$days = (int) $days;
		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::table();
		$before = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = %s AND created_at < %s", self::STATUS_OK, $before ) );
	}
}
