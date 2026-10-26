<?php
/**
 * Vista: escáner de formularios de HubSpot.
 *
 * Permite comprobar qué propiedades expone un formulario de HubSpot antes de
 * mapearlo en Elementor (el mapeo automático se hace desde el propio editor).
 *
 * @package HubSpot_Elementor_Form
 */

defined( 'ABSPATH' ) || exit;

$hef_input  = '';
$hef_result = null;
$hef_error  = '';

if ( isset( $_POST['hef_scan_submit'] ) && check_admin_referer( 'hef_scan_admin' ) ) {
	$hef_input = isset( $_POST['hef_url'] ) ? sanitize_text_field( wp_unslash( $_POST['hef_url'] ) ) : '';
	$hef_force = ! empty( $_POST['hef_force'] );

	$hef_scan = \HEF\Scanner::scan(
		$hef_input,
		array(
			'portal_id' => isset( $_POST['hef_portal'] ) ? sanitize_text_field( wp_unslash( $_POST['hef_portal'] ) ) : '',
			'force'     => $hef_force,
		)
	);

	if ( is_wp_error( $hef_scan ) ) {
		$hef_error = $hef_scan->get_error_message();
	} else {
		$hef_result = $hef_scan;
	}
}
?>
<div class="wrap hef-wrap">
	<h1><?php esc_html_e( 'Escáner de formularios de HubSpot', 'hubspot-elementor-form' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Pega la URL de un formulario de HubSpot para ver sus propiedades (keyid), su tipo y cuáles son obligatorias. El mapeo automático con los campos de Elementor se lanza desde el editor, en el campo "HubSpot (configuración)".', 'hubspot-elementor-form' ); ?>
	</p>

	<form method="post" class="hef-scan-form">
		<?php wp_nonce_field( 'hef_scan_admin' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hef-url"><?php esc_html_e( 'URL del formulario', 'hubspot-elementor-form' ); ?></label></th>
				<td>
					<input type="text" id="hef-url" class="large-text" name="hef_url"
						value="<?php echo esc_attr( $hef_input ); ?>"
						placeholder="https://share.hsforms.com/xxxxxxxxxxxxxxxxxxxxx">
					<p class="description">
						<?php esc_html_e( 'Vale el enlace de "Compartir", la URL del editor del formulario (app.hubspot.com/forms/…), una página donde esté embebido, o directamente "portalId/formId".', 'hubspot-elementor-form' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hef-portal"><?php esc_html_e( 'Portal ID', 'hubspot-elementor-form' ); ?></label></th>
				<td>
					<input type="text" id="hef-portal" class="regular-text" name="hef_portal" placeholder="12345678">
					<p class="description"><?php esc_html_e( 'Solo si la URL no lo incluye.', 'hubspot-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Caché', 'hubspot-elementor-form' ); ?></th>
				<td>
					<label><input type="checkbox" name="hef_force" value="1"> <?php esc_html_e( 'Ignorar la caché de 15 minutos y volver a consultar a HubSpot.', 'hubspot-elementor-form' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Escanear formulario', 'hubspot-elementor-form' ), 'primary', 'hef_scan_submit' ); ?>
	</form>

	<?php if ( $hef_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $hef_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $hef_result ) : ?>
		<h2><?php echo esc_html( $hef_result['form_name'] ? $hef_result['form_name'] : __( 'Formulario', 'hubspot-elementor-form' ) ); ?></h2>

		<table class="widefat striped hef-scan-summary">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Portal ID', 'hubspot-elementor-form' ); ?></th>
					<td><code><?php echo esc_html( $hef_result['portal_id'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Form ID', 'hubspot-elementor-form' ); ?></th>
					<td><code><?php echo esc_html( $hef_result['form_id'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Región', 'hubspot-elementor-form' ); ?></th>
					<td><code><?php echo esc_html( $hef_result['region'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'CAPTCHA en HubSpot', 'hubspot-elementor-form' ); ?></th>
					<td>
						<?php
						echo empty( $hef_result['captcha'] )
							? esc_html__( 'Desactivado', 'hubspot-elementor-form' )
							: esc_html__( 'Activado — no afecta: el envío por la API v3 no pasa por el CAPTCHA.', 'hubspot-elementor-form' );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Origen de los datos', 'hubspot-elementor-form' ); ?></th>
					<td>
						<?php
						echo esc_html(
							'api' === $hef_result['source']
								? __( 'API de HubSpot (token de app privada)', 'hubspot-elementor-form' )
								: __( 'Definición pública del formulario embebido', 'hubspot-elementor-form' )
						);
						echo ! empty( $hef_result['cached'] ) ? ' · ' . esc_html__( 'desde caché', 'hubspot-elementor-form' ) : '';
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php echo esc_html( sprintf( /* translators: %d: número de propiedades */ __( 'Propiedades del formulario (%d)', 'hubspot-elementor-form' ), count( $hef_result['fields'] ) ) ); ?></h3>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Etiqueta en HubSpot', 'hubspot-elementor-form' ); ?></th>
					<th><?php esc_html_e( 'Propiedad (keyid)', 'hubspot-elementor-form' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'hubspot-elementor-form' ); ?></th>
					<th><?php esc_html_e( 'Objeto', 'hubspot-elementor-form' ); ?></th>
					<th><?php esc_html_e( 'Obligatoria', 'hubspot-elementor-form' ); ?></th>
					<th><?php esc_html_e( 'Opciones', 'hubspot-elementor-form' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $hef_result['fields'] as $hef_field ) : ?>
				<tr>
					<td><?php echo esc_html( $hef_field['label'] ); ?></td>
					<td><code><?php echo esc_html( $hef_field['name'] ); ?></code></td>
					<td><?php echo esc_html( $hef_field['type'] ); ?></td>
					<td><code><?php echo esc_html( $hef_field['object'] ); ?></code></td>
					<td><?php echo $hef_field['required'] ? '<span class="hef-badge hef-badge--error">' . esc_html__( 'Sí', 'hubspot-elementor-form' ) . '</span>' : '—'; ?></td>
					<td>
						<?php
						if ( $hef_field['options'] ) {
							$hef_values = wp_list_pluck( $hef_field['options'], 'value' );
							echo '<code class="hef-options">' . esc_html( implode( ' · ', array_slice( $hef_values, 0, 12 ) ) ) . ( count( $hef_values ) > 12 ? '…' : '' ) . '</code>';
						} else {
							echo '—';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'Los valores de las listas desplegables deben coincidir exactamente con los que envíe Elementor; si no, HubSpot los rechaza. Para selección múltiple, el plugin los une con ";".', 'hubspot-elementor-form' ); ?>
		</p>
	<?php endif; ?>
</div>
