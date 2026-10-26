<?php
/**
 * Activación, desactivación y esquema de base de datos.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Crea y mantiene las tablas propias del módulo.
 *
 * el registro autoritativo es nuestro. El módulo no escribe en las
 * tablas internas de Elementor ni en las de ningún otro plugin.
 */
final class Activator {

	/**
	 * Gancho de la tarea programada diaria.
	 *
	 * @var string
	 */
	public const CRON_DIARIO = 'swpagos_evento_diario';

	/**
	 * Nombre de tabla completo, con el prefijo del sitio.
	 *
	 * @param string $nombre Nombre corto: transactions, subscriptions, gateway_events, delivery_logs.
	 * @return string
	 */
	public static function tabla( string $nombre ): string {
		global $wpdb;
		return $wpdb->prefix . 'swpagos_' . $nombre;
	}

	/**
	 * Se ejecuta al activar el plugin.
	 *
	 * @return void
	 */
	public static function activar(): void {
		// El módulo de HubSpot incorporado tiene su propia tabla de registros.
		\SWPagos\HubSpot\Hef_Bridge::activar();

		self::crear_tablas();
		update_option( 'swpagos_db_version', SWPAGOS_DB_VERSION, false );

		if ( ! wp_next_scheduled( self::CRON_DIARIO ) ) {
			wp_schedule_event( self::proxima_ejecucion_diaria(), 'daily', self::CRON_DIARIO );
		}

		// El panel de diagnóstico es lo primero que debe ver quien instala.
		set_transient( 'swpagos_recien_activado', 1, 60 );
	}

	/**
	 * Se ejecuta al desactivar el plugin. No borra datos.
	 *
	 * @return void
	 */
	public static function desactivar(): void {
		$marca = wp_next_scheduled( self::CRON_DIARIO );
		if ( $marca ) {
			wp_unschedule_event( $marca, self::CRON_DIARIO );
		}
	}

	/**
	 * Próxima ejecución a las 08:00 de la hora del sitio.
	 *
	 * @return int
	 */
	private static function proxima_ejecucion_diaria(): int {
		$ahora   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$hoy_8   = strtotime( gmdate( 'Y-m-d', $ahora ) . ' 08:00:00' );
		$destino = ( $hoy_8 > $ahora ) ? $hoy_8 : $hoy_8 + DAY_IN_SECONDS;

		// wp_schedule_event espera UTC; current_time devuelve hora del sitio.
		return $destino - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	}

	/**
	 * Crea o actualiza las cuatro tablas del módulo.
	 *
	 * Los campos de estado se declaran VARCHAR y no ENUM a propósito: dbDelta
	 * compara ENUM de forma poco fiable y reintenta el ALTER en cada carga, y
	 * añadir un estado nuevo obligaría a una migración. La validación de valores
	 * vive en las entidades de dominio, donde se puede probar.
	 *
	 * @return void
	 */
	public static function crear_tablas(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$transacciones = self::tabla( 'transactions' );
		$suscripciones = self::tabla( 'subscriptions' );
		$eventos       = self::tabla( 'gateway_events' );
		$entregas      = self::tabla( 'delivery_logs' );

		$sql = array();

		$sql[] = "CREATE TABLE {$transacciones} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			billing_period varchar(7) DEFAULT NULL,
			submission_id bigint(20) unsigned DEFAULT NULL,
			form_id varchar(191) NOT NULL DEFAULT '',
			template_id bigint(20) unsigned DEFAULT NULL,
			gateway varchar(30) NOT NULL DEFAULT 'stripe',
			mode varchar(10) NOT NULL DEFAULT 'test',
			kind varchar(30) NOT NULL DEFAULT 'single',
			status varchar(20) NOT NULL DEFAULT 'iniciada',
			amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'USD',
			amount_refunded_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			stripe_session_id varchar(191) DEFAULT NULL,
			stripe_payment_intent_id varchar(191) DEFAULT NULL,
			stripe_invoice_id varchar(191) DEFAULT NULL,
			stripe_charge_id varchar(191) DEFAULT NULL,
			stripe_customer_id varchar(191) DEFAULT NULL,
			idempotency_key varchar(64) NOT NULL,
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) DEFAULT NULL,
			hubspot_contact_id varchar(50) DEFAULT NULL,
			hubspot_deal_id varchar(50) DEFAULT NULL,
			locale varchar(10) DEFAULT NULL,
			utm longtext DEFAULT NULL,
			metadata longtext DEFAULT NULL,
			failure_code varchar(60) DEFAULT NULL,
			failure_message text DEFAULT NULL,
			status_history longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			paid_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY idempotency_key (idempotency_key),
			UNIQUE KEY stripe_session_id (stripe_session_id),
			UNIQUE KEY stripe_invoice_id (stripe_invoice_id),
			UNIQUE KEY suscripcion_periodo (subscription_id,billing_period),
			KEY submission_id (submission_id),
			KEY form_id (form_id),
			KEY customer_email (customer_email),
			KEY stripe_payment_intent_id (stripe_payment_intent_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY panel (status,mode,created_at)
		) {$collate};";

		$sql[] = "CREATE TABLE {$suscripciones} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			form_id varchar(191) NOT NULL DEFAULT '',
			template_id bigint(20) unsigned DEFAULT NULL,
			mode varchar(10) NOT NULL DEFAULT 'test',
			status varchar(20) NOT NULL DEFAULT 'activa',
			billing_interval varchar(20) NOT NULL DEFAULT 'month',
			amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'USD',
			stripe_subscription_id varchar(191) NOT NULL,
			stripe_customer_id varchar(191) NOT NULL DEFAULT '',
			stripe_price_id varchar(191) NOT NULL DEFAULT '',
			current_period_end datetime DEFAULT NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			failed_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) DEFAULT NULL,
			hubspot_contact_id varchar(50) DEFAULT NULL,
			locale varchar(10) DEFAULT NULL,
			utm longtext DEFAULT NULL,
			metadata longtext DEFAULT NULL,
			status_history longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			canceled_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY stripe_subscription_id (stripe_subscription_id),
			KEY form_id (form_id),
			KEY customer_email (customer_email),
			KEY stripe_customer_id (stripe_customer_id),
			KEY panel (status,mode,created_at)
		) {$collate};";

		$sql[] = "CREATE TABLE {$eventos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(100) NOT NULL,
			type varchar(80) NOT NULL DEFAULT '',
			transaction_id bigint(20) unsigned DEFAULT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			payload longtext DEFAULT NULL,
			received_at datetime NOT NULL,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY type (type),
			KEY transaction_id (transaction_id),
			KEY subscription_id (subscription_id),
			KEY processed_at (processed_at)
		) {$collate};";

		$sql[] = "CREATE TABLE {$entregas} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			transaction_id bigint(20) unsigned DEFAULT NULL,
			subscription_id bigint(20) unsigned DEFAULT NULL,
			submission_id bigint(20) unsigned DEFAULT NULL,
			form_id varchar(191) NOT NULL DEFAULT '',
			channel varchar(30) NOT NULL DEFAULT 'email',
			email_kind varchar(40) DEFAULT NULL,
			target varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
			response_code varchar(20) DEFAULT NULL,
			message text DEFAULT NULL,
			payload longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY transaction_id (transaction_id),
			KEY subscription_id (subscription_id),
			KEY submission_id (submission_id),
			KEY form_id (form_id),
			KEY channel (channel),
			KEY status (status),
			KEY created_at (created_at)
		) {$collate};";

		foreach ( $sql as $consulta ) {
			dbDelta( $consulta );
		}
	}

	/**
	 * Comprueba que las cuatro tablas existen. Lo usa el panel de diagnóstico.
	 *
	 * @return array<string,bool> Nombre corto => existe.
	 */
	public static function estado_tablas(): array {
		global $wpdb;

		$estado = array();

		foreach ( array( 'transactions', 'subscriptions', 'gateway_events', 'delivery_logs' ) as $nombre ) {
			$tabla = self::tabla( $nombre );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$encontrada     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabla ) );
			$estado[ $nombre ] = ( $encontrada === $tabla );
		}

		return $estado;
	}
}
