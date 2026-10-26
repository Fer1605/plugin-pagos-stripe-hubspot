<?php
/**
 * Sección «Negocios de los cobros» dentro de los ajustes de HubSpot.
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

defined( 'ABSPATH' ) || exit;

/**
 * Pinta y guarda los ajustes del CRM en la pantalla de HubSpot.
 *
 * No se crea una pantalla nueva a propósito: quien configura HubSpot espera
 * encontrar aquí todo lo de HubSpot. Esta sección se añade debajo de la que ya
 * existe, sin tocar ni una línea del módulo incorporado.
 */
final class Crm_Panel {

	/**
	 * Acción del nonce.
	 *
	 * @var string
	 */
	private const NONCE = 'swpagos_crm_guardar';

	/**
	 * Procesa el envío y pinta la sección.
	 *
	 * @return void
	 */
	public static function renderizar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$aviso = self::procesar_envio();

		$ajustes  = Crm_Settings::todo();
		$cliente  = new Crm_Client();
		$con_token = $cliente->configurado();
		$embudos  = $con_token ? Crm_Settings::embudos() : array();

		echo '<div class="wrap swpagos-wrap swpagos-crm">';
		echo '<h2>' . esc_html__( 'Negocios de los cobros (CRM)', 'sw-pagos' ) . '</h2>';

		echo '<p class="description">';
		echo esc_html__( 'Cuando un cobro de Stripe se confirma, el módulo crea en el CRM el contacto y un negocio con el importe real cobrado, y los asocia. Esto es aparte del envío del formulario: el formulario entra en HubSpot como un envío de formulario; el dinero entra como un negocio.', 'sw-pagos' );
		echo '</p>';

		if ( '' !== $aviso ) {
			printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $aviso ) );
		}

		if ( ! $con_token ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Esta parte necesita el token de app privada de arriba, con los permisos de contactos y negocios (crm.objects.contacts y crm.objects.deals). Sin token, los formularios siguen funcionando: lo único que no se crea es el negocio.', 'sw-pagos' );
			echo '</p></div>';
		}

		// Los formularios con cobro dependen de que el envío quede guardado: se
		// registra al pulsar el botón y se manda a HubSpot cuando Stripe
		// confirma el cobro. Sin el JSON guardado no hay nada que mandar.
		if ( class_exists( '\\HEF\\Settings' ) && ! \HEF\Settings::get( 'store_payload' ) ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'Atención: arriba está desactivado «Guardar en el registro el JSON enviado». Los formularios con cobro necesitan esa casilla: el envío se guarda cuando la persona pulsa el botón y se manda a HubSpot cuando Stripe confirma el cobro. Desactivada, esos envíos no llegarán nunca a HubSpot.', 'sw-pagos' );
			echo '</p></div>';
		}

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="swpagos_crm" value="1">';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Activo.
		echo '<tr><th scope="row">' . esc_html__( 'Crear negocios', 'sw-pagos' ) . '</th><td><label>';
		printf(
			'<input type="checkbox" name="swpagos_crm_activo" value="1" %s> ',
			checked( 1, (int) $ajustes['activo'], false )
		);
		echo esc_html__( 'Anotar en el CRM cada cobro confirmado.', 'sw-pagos' );
		echo '</label><p class="description">';
		echo esc_html__( 'Un negocio por cobro, no uno por suscripción: así los informes de ingresos de HubSpot suman cada mes de un donante recurrente, y no solo el primero.', 'sw-pagos' );
		echo '</p></td></tr>';

		// Embudo y etapa.
		echo '<tr><th scope="row"><label for="swpagos-crm-pipeline">' . esc_html__( 'Embudo', 'sw-pagos' ) . '</label></th><td>';

		if ( array() !== $embudos ) {
			echo '<select id="swpagos-crm-pipeline" name="swpagos_crm_pipeline">';
			printf( '<option value="">%s</option>', esc_html__( '— El predeterminado del portal —', 'sw-pagos' ) );
			foreach ( $embudos as $id => $embudo ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $id ),
					selected( $id, $ajustes['pipeline'], false ),
					esc_html( $embudo['nombre'] )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="text" id="swpagos-crm-pipeline" class="regular-text" name="swpagos_crm_pipeline" value="%s" placeholder="default">',
				esc_attr( $ajustes['pipeline'] )
			);
			echo '<p class="description">' . esc_html__( 'Vacío = el embudo predeterminado del portal. Con el token puesto, aquí aparece la lista de embudos y no hay que escribir nada.', 'sw-pagos' ) . '</p>';
		}

		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="swpagos-crm-etapa">' . esc_html__( 'Etapa del negocio', 'sw-pagos' ) . '</label></th><td>';

		$etapas = $embudos[ $ajustes['pipeline'] ]['etapas'] ?? array();

		if ( array() === $etapas && array() !== $embudos ) {
			$primero = reset( $embudos );
			$etapas  = is_array( $primero ) ? (array) $primero['etapas'] : array();
		}

		if ( array() !== $etapas ) {
			echo '<select id="swpagos-crm-etapa" name="swpagos_crm_dealstage">';
			printf( '<option value="">%s</option>', esc_html__( '— La primera del embudo —', 'sw-pagos' ) );
			foreach ( $etapas as $id => $nombre ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( (string) $id ),
					selected( (string) $id, $ajustes['dealstage'], false ),
					esc_html( (string) $nombre )
				);
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Como el cobro ya está hecho y verificado, lo normal es elegir la etapa de cerrado y ganado.', 'sw-pagos' ) . '</p>';
		} else {
			printf(
				'<input type="text" id="swpagos-crm-etapa" class="regular-text" name="swpagos_crm_dealstage" value="%s" placeholder="closedwon">',
				esc_attr( $ajustes['dealstage'] )
			);
			echo '<p class="description">' . esc_html__( 'El cobro ya está verificado cuando se crea el negocio, así que suele ser «closedwon».', 'sw-pagos' ) . '</p>';
		}

		echo '</td></tr>';

		// Nombre del negocio.
		echo '<tr><th scope="row"><label for="swpagos-crm-nombre">' . esc_html__( 'Nombre del negocio', 'sw-pagos' ) . '</label></th><td>';
		printf(
			'<input type="text" id="swpagos-crm-nombre" class="regular-text" name="swpagos_crm_nombre" value="%s">',
			esc_attr( $ajustes['nombre'] )
		);
		echo '<p class="description">';
		printf(
			/* translators: %s: lista de marcadores. */
			esc_html__( 'Así se llama cada negocio en HubSpot. Puedes usar %s. En los cobros de una suscripción se añade solo el periodo cobrado.', 'sw-pagos' ),
			'<code>[nombre]</code>, <code>[email]</code>, <code>[importe]</code>' // phpcs:ignore WordPress.Security.EscapeOutput
		);
		echo '</p></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Guardar los negocios', 'sw-pagos' ) );

		if ( $con_token ) {
			printf(
				' <button type="submit" name="swpagos_crm_releer" value="1" class="button">%s</button>',
				esc_html__( 'Volver a leer los embudos del portal', 'sw-pagos' )
			);
		}

		echo '</form>';

		echo '<h3>' . esc_html__( 'Qué se escribe en HubSpot', 'sw-pagos' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Propiedades estándar: importe, moneda y fecha de cierre. Y estas propiedades personalizadas, si existen en el portal (si no existen, el negocio se crea igual sin ellas y queda avisado en los registros):', 'sw-pagos' ) . '</p>';
		echo '<ul class="swpagos-lista-props">';
		foreach ( self::propiedades_personalizadas() as $nombre => $para_que ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( $nombre ), esc_html( $para_que ) );
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Cómo conviven las dos mitades', 'sw-pagos' ) . '</h3>';
		echo '<ul class="swpagos-lista-props">';
		echo '<li>' . esc_html__( 'Formulario sin cobro: lleva la acción «HubSpot» y se manda al enviarse, como siempre.', 'sw-pagos' ) . '</li>';
		echo '<li>' . esc_html__( 'Formulario con cobro: NO lleva la acción «HubSpot». El envío se guarda completo en «Envíos a HubSpot» con el estado «omitido», y se manda solo cuando Stripe confirma el cobro. Así en HubSpot no entran donaciones que nunca se pagaron.', 'sw-pagos' ) . '</li>';
		echo '<li>' . esc_html__( 'Si el envío a HubSpot falla después del cobro, queda como error en «Envíos a HubSpot» y se reintenta solo. También puedes reenviarlo a mano desde ahí.', 'sw-pagos' ) . '</li>';
		echo '</ul>';

		echo '</div>';
	}

	/**
	 * Propiedades personalizadas que el módulo intenta escribir.
	 *
	 * @return array<string,string>
	 */
	private static function propiedades_personalizadas(): array {
		return array(
			'swpagos_transaction_uuid'  => __( 'referencia de la transacción en este sitio', 'sw-pagos' ),
			'swpagos_stripe_reference'  => __( 'identificador del cobro en Stripe', 'sw-pagos' ),
			'swpagos_mode'              => __( 'si el cobro fue de pruebas o real', 'sw-pagos' ),
			'swpagos_billing_period'    => __( 'periodo cobrado, en las suscripciones', 'sw-pagos' ),
			'swpagos_subscription_uuid' => __( 'referencia de la suscripción', 'sw-pagos' ),
			'swpagos_refunded_amount'   => __( 'importe devuelto, si hay reembolso', 'sw-pagos' ),
			'swpagos_refunded_at'       => __( 'fecha del reembolso', 'sw-pagos' ),
		);
	}

	/**
	 * Guarda el envío, si lo hay.
	 *
	 * @return string Aviso para la pantalla, o cadena vacía.
	 */
	private static function procesar_envio(): string {
		if ( empty( $_POST['swpagos_crm'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE );

		if ( ! empty( $_POST['swpagos_crm_releer'] ) ) {
			Crm_Settings::olvidar_embudos();
			Crm_Settings::embudos( true );
		}

		Crm_Settings::guardar(
			array(
				'activo'    => $_POST['swpagos_crm_activo'] ?? 0,
				'pipeline'  => wp_unslash( (string) ( $_POST['swpagos_crm_pipeline'] ?? '' ) ),
				'dealstage' => wp_unslash( (string) ( $_POST['swpagos_crm_dealstage'] ?? '' ) ),
				'nombre'    => wp_unslash( (string) ( $_POST['swpagos_crm_nombre'] ?? '' ) ),
			)
		);

		return __( 'Guardado.', 'sw-pagos' );
	}
}
