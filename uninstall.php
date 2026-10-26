<?php
/**
 * Desinstalación del módulo.
 *
 * Por omisión se conservan los datos: un módulo de cobros no borra su historial
 * de transacciones porque alguien desactive el plugin. El borrado completo exige
 * marcarlo explícitamente en los ajustes antes de desinstalar.
 *
 * @package SWPagos
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'swpagos_borrar_datos_al_desinstalar' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'transactions', 'subscriptions', 'gateway_events', 'delivery_logs' ) as $tabla ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'swpagos_' . $tabla );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'swpagos\_%'" );

$plantillas = get_posts(
	array(
		'post_type'   => 'swpagos_plantilla',
		'numberposts' => -1,
		'fields'      => 'ids',
		'post_status' => 'any',
	)
);
foreach ( $plantillas as $id ) {
	wp_delete_post( $id, true );
}

wp_clear_scheduled_hook( 'swpagos_evento_diario' );
