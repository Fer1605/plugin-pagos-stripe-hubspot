<?php
/**
 * Vista: ajustes.
 *
 * @package HubSpot_Elementor_Form
 */

defined( 'ABSPATH' ) || exit;

$hef_opts   = \HEF\Settings::all();
$hef_option = \HEF\Settings::OPTION;
$hef_locked = defined( 'HEF_HUBSPOT_TOKEN' ) && HEF_HUBSPOT_TOKEN;
?>
<div class="wrap hef-wrap">
	<h1><?php esc_html_e( 'Ajustes · HubSpot Elementor Form', 'hubspot-elementor-form' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'hef_settings_group' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hef-token"><?php esc_html_e( 'Token de app privada', 'hubspot-elementor-form' ); ?></label></th>
				<td>
					<input type="password" id="hef-token" class="regular-text" autocomplete="off" placeholder="pat-na1-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
						name="<?php echo esc_attr( $hef_option ); ?>[access_token]"
						value="<?php echo esc_attr( $hef_opts['access_token'] ); ?>" <?php disabled( $hef_locked ); ?>>

					<?php if ( $hef_locked ) : ?>
						<p class="description"><strong><?php esc_html_e( 'El token está definido en wp-config.php mediante la constante HEF_HUBSPOT_TOKEN, por lo que este campo está bloqueado.', 'hubspot-elementor-form' ); ?></strong></p>
					<?php endif; ?>

					<p class="description">
						<?php esc_html_e( 'Opcional. Se necesita para el "endpoint seguro" y como alternativa del escáner cuando la definición pública del formulario no está disponible.', 'hubspot-elementor-form' ); ?>
					</p>

					<details class="hef-howto" <?php echo empty( $hef_opts['access_token'] ) && ! $hef_locked ? 'open' : ''; ?>>
						<summary><strong><?php esc_html_e( '¿Cómo y dónde consigo este token?', 'hubspot-elementor-form' ); ?></strong></summary>
						<ol>
							<li>
								<?php esc_html_e( 'Entra en HubSpot con un usuario que sea', 'hubspot-elementor-form' ); ?>
								<strong><?php esc_html_e( 'superadministrador', 'hubspot-elementor-form' ); ?></strong>
								<?php esc_html_e( '(es el único perfil que puede crear aplicaciones privadas).', 'hubspot-elementor-form' ); ?>
							</li>
							<li>
								<?php esc_html_e( 'Arriba a la derecha pulsa el icono del engranaje', 'hubspot-elementor-form' ); ?> <strong>⚙ <?php esc_html_e( 'Configuración', 'hubspot-elementor-form' ); ?></strong>.
							</li>
							<li>
								<?php esc_html_e( 'En el menú lateral ve a', 'hubspot-elementor-form' ); ?>
								<strong><?php esc_html_e( 'Integraciones → Aplicaciones privadas', 'hubspot-elementor-form' ); ?></strong>
								<?php esc_html_e( '(en inglés: Integrations → Private apps). Atajo directo:', 'hubspot-elementor-form' ); ?>
								<a href="https://app.hubspot.com/private-apps" target="_blank" rel="noopener">app.hubspot.com/private-apps</a>
								<?php esc_html_e( '· cuentas europeas:', 'hubspot-elementor-form' ); ?>
								<a href="https://app-eu1.hubspot.com/private-apps" target="_blank" rel="noopener">app-eu1.hubspot.com/private-apps</a>.
							</li>
							<li>
								<?php esc_html_e( 'Pulsa', 'hubspot-elementor-form' ); ?> <strong><?php esc_html_e( 'Crear una aplicación privada', 'hubspot-elementor-form' ); ?></strong>
								<?php esc_html_e( 'y en la pestaña "Información básica" ponle un nombre, por ejemplo', 'hubspot-elementor-form' ); ?>
								<code>WordPress – Elementor Forms</code>.
							</li>
							<li>
								<?php esc_html_e( 'Abre la pestaña', 'hubspot-elementor-form' ); ?> <strong><?php esc_html_e( 'Alcances (Scopes)', 'hubspot-elementor-form' ); ?></strong>
								<?php esc_html_e( 'y marca:', 'hubspot-elementor-form' ); ?>
								<ul class="hef-scopes">
									<li><code>forms</code> — <?php esc_html_e( 'imprescindible: enviar formularios y leer su definición para el escáner.', 'hubspot-elementor-form' ); ?></li>
									<li><code>crm.objects.contacts.read</code> / <code>crm.objects.contacts.write</code> — <?php esc_html_e( 'opcionales, solo si vas a mapear propiedades de contacto poco habituales.', 'hubspot-elementor-form' ); ?></li>
								</ul>
							</li>
							<li>
								<?php esc_html_e( 'Arriba a la derecha pulsa', 'hubspot-elementor-form' ); ?> <strong><?php esc_html_e( 'Crear aplicación', 'hubspot-elementor-form' ); ?></strong>
								<?php esc_html_e( 'y confirma en', 'hubspot-elementor-form' ); ?> <strong><?php esc_html_e( 'Continuar creando', 'hubspot-elementor-form' ); ?></strong>.
							</li>
							<li>
								<?php esc_html_e( 'En la pantalla que aparece pulsa', 'hubspot-elementor-form' ); ?> <strong><?php esc_html_e( 'Mostrar token', 'hubspot-elementor-form' ); ?></strong>
								<?php esc_html_e( 'y cópialo. Empieza por', 'hubspot-elementor-form' ); ?> <code>pat-na1-…</code> <?php esc_html_e( 'o', 'hubspot-elementor-form' ); ?> <code>pat-eu1-…</code>.
								<em><?php esc_html_e( 'Guárdalo bien: HubSpot solo lo muestra completo en ese momento.', 'hubspot-elementor-form' ); ?></em>
							</li>
							<li>
								<?php esc_html_e( 'Pégalo en el campo de arriba y guarda; después pulsa "Probar token" para comprobarlo.', 'hubspot-elementor-form' ); ?>
							</li>
						</ol>
						<p class="hef-tip">
							<strong><?php esc_html_e( 'Más seguro', 'hubspot-elementor-form' ); ?>:</strong>
							<?php esc_html_e( 'en vez de guardarlo en la base de datos, añádelo a wp-config.php:', 'hubspot-elementor-form' ); ?>
							<code>define( 'HEF_HUBSPOT_TOKEN', 'pat-na1-…' );</code>
						</p>
						<p class="hef-tip">
							<?php esc_html_e( 'Si el token se ve comprometido, en la misma pantalla de la aplicación privada puedes rotarlo o eliminar la app.', 'hubspot-elementor-form' ); ?>
						</p>
					</details>

					<?php
					$hef_test = get_transient( 'hef_token_test_' . get_current_user_id() );
					if ( is_array( $hef_test ) ) {
						delete_transient( 'hef_token_test_' . get_current_user_id() );
						printf(
							'<div class="notice inline %1$s"><p>%2$s</p></div>',
							$hef_test['success'] ? 'notice-success' : 'notice-error',
							esc_html( $hef_test['message'] )
						);
					}
					?>

					<p>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'hef_test_token', admin_url( 'admin-post.php' ) ), 'hef_test_token' ) ); ?>">
							<?php esc_html_e( 'Probar token', 'hubspot-elementor-form' ); ?>
						</a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Reintentos automáticos', 'hubspot-elementor-form' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1" name="<?php echo esc_attr( $hef_option ); ?>[auto_retry]" <?php checked( $hef_opts['auto_retry'], 1 ); ?>>
						<?php esc_html_e( 'Reintentar automáticamente los envíos fallidos (cada 5 minutos, con espera progresiva).', 'hubspot-elementor-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hef-max"><?php esc_html_e( 'Intentos máximos', 'hubspot-elementor-form' ); ?></label></th>
				<td>
					<input type="number" id="hef-max" min="1" max="20" class="small-text"
						name="<?php echo esc_attr( $hef_option ); ?>[max_attempts]"
						value="<?php echo esc_attr( $hef_opts['max_attempts'] ); ?>">
					<p class="description"><?php esc_html_e( 'Alcanzado el límite, el registro queda como error y solo se puede reintentar a mano.', 'hubspot-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Datos del envío', 'hubspot-elementor-form' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1" name="<?php echo esc_attr( $hef_option ); ?>[store_payload]" <?php checked( $hef_opts['store_payload'], 1 ); ?>>
						<?php esc_html_e( 'Guardar en el registro el JSON enviado a HubSpot (necesario para poder reintentar).', 'hubspot-elementor-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hef-retention"><?php esc_html_e( 'Conservar registros', 'hubspot-elementor-form' ); ?></label></th>
				<td>
					<input type="number" id="hef-retention" min="0" class="small-text"
						name="<?php echo esc_attr( $hef_option ); ?>[retention_days]"
						value="<?php echo esc_attr( $hef_opts['retention_days'] ); ?>">
					<?php esc_html_e( 'días', 'hubspot-elementor-form' ); ?>
					<p class="description"><?php esc_html_e( 'Solo se borran los registros correctos. 0 = no borrar nunca.', 'hubspot-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hef-timeout"><?php esc_html_e( 'Timeout HTTP', 'hubspot-elementor-form' ); ?></label></th>
				<td>
					<input type="number" id="hef-timeout" min="5" max="60" class="small-text"
						name="<?php echo esc_attr( $hef_option ); ?>[timeout]"
						value="<?php echo esc_attr( $hef_opts['timeout'] ); ?>">
					<?php esc_html_e( 'segundos', 'hubspot-elementor-form' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Envíos de Elementor', 'hubspot-elementor-form' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1" name="<?php echo esc_attr( $hef_option ); ?>[annotate_submission]" <?php checked( $hef_opts['annotate_submission'], 1 ); ?>>
						<?php esc_html_e( 'Anotar en cada envío guardado por Elementor la referencia al registro del plugin (fila "HubSpot" con el número de registro y el resultado).', 'hubspot-elementor-form' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Requiere "Collect Submissions" en el formulario. Reutiliza la fila vacía que deja el campo de configuración.', 'hubspot-elementor-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Avisos en el formulario', 'hubspot-elementor-form' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1" name="<?php echo esc_attr( $hef_option ); ?>[show_error]" <?php checked( $hef_opts['show_error'], 1 ); ?>>
						<?php esc_html_e( 'Mostrar el error de HubSpot a los usuarios con permisos de edición (no afecta a los visitantes).', 'hubspot-elementor-form' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e( 'Cómo se usa', 'hubspot-elementor-form' ); ?></h2>
	<ol class="hef-help">
		<li><?php esc_html_e( 'Edita tu formulario de Elementor y añade un campo del tipo "HubSpot (configuración)".', 'hubspot-elementor-form' ); ?></li>
		<li><?php esc_html_e( 'Pega la URL del formulario de HubSpot (share.hsforms.com/…) y pulsa "Escanear y mapear campos": se rellenan solos el Portal ID, el Form ID, la región y la propiedad más probable de cada campo.', 'hubspot-elementor-form' ); ?></li>
		<li><?php esc_html_e( 'Si prefieres hacerlo a mano, introduce el Portal ID y el Form ID (GUID) una sola vez.', 'hubspot-elementor-form' ); ?></li>
		<li><?php esc_html_e( 'En cada campo del formulario rellena "Propiedad de HubSpot (keyid)" con el nombre interno de la propiedad equivalente.', 'hubspot-elementor-form' ); ?></li>
		<li><?php esc_html_e( 'Revisa el resultado: puedes cambiar la propiedad propuesta, borrarla o añadir otras. Los campos sin propiedad no se envían.', 'hubspot-elementor-form' ); ?></li>
		<li><?php esc_html_e( 'El envío se realiza solo si el formulario supera todas las validaciones de Elementor.', 'hubspot-elementor-form' ); ?></li>
	</ol>
</div>
