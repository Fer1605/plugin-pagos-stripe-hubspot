<?php
/**
 * Pantalla de configuración.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;
use SWPagos\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración con pestañas General / Stripe / HubSpot.
 */
final class Page_Settings {

	/**
	 * Credenciales gestionadas, por pestaña.
	 *
	 * @var array<string,array<int,array{clave:string,etiqueta:string,modo:string}>>
	 */
	private const CREDENCIALES = array(
		'stripe' => array(
			array(
				'clave'    => 'stripe_publishable',
				'etiqueta' => 'Clave publicable (prueba)',
				'modo'     => 'test',
			),
			array(
				'clave'    => 'stripe_secret',
				'etiqueta' => 'Clave secreta (prueba)',
				'modo'     => 'test',
			),
			array(
				'clave'    => 'stripe_webhook_secret',
				'etiqueta' => 'Secreto del webhook (prueba)',
				'modo'     => 'test',
			),
			array(
				'clave'    => 'stripe_publishable',
				'etiqueta' => 'Clave publicable (producción)',
				'modo'     => 'live',
			),
			array(
				'clave'    => 'stripe_secret',
				'etiqueta' => 'Clave secreta (producción)',
				'modo'     => 'live',
			),
			array(
				'clave'    => 'stripe_webhook_secret',
				'etiqueta' => 'Secreto del webhook (producción)',
				'modo'     => 'live',
			),
		),
	);

	/**
	 * Ajustes del módulo.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = Plugin::instancia()->settings();
	}

	/**
	 * Pinta la pantalla.
	 *
	 * @return void
	 */
	public function renderizar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'sw-pagos' ) );
		}

		$pestana = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pestanas = array(
			'general' => __( 'General', 'sw-pagos' ),
			'stripe'  => __( 'Stripe', 'sw-pagos' ),
		);

		if ( ! isset( $pestanas[ $pestana ] ) ) {
			$pestana = 'general';
		}

		$this->procesar_envio( $pestana );

		echo '<div class="wrap swpagos-wrap">';
		echo '<h1>' . esc_html__( 'Pagos Stripe + HubSpot', 'sw-pagos' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $pestanas as $slug => $titulo ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( add_query_arg( array( 'page' => Menu::SLUG_AJUSTES, 'tab' => $slug ), admin_url( 'admin.php' ) ) ),
				$pestana === $slug ? 'nav-tab-active' : '',
				esc_html( $titulo )
			);
		}
		echo '</h2>';

		echo '<div class="swpagos-panel">';
		echo '<form method="post" action="">';
		wp_nonce_field( 'swpagos_guardar_' . $pestana );
		echo '<input type="hidden" name="swpagos_tab" value="' . esc_attr( $pestana ) . '">';

		switch ( $pestana ) {
			case 'stripe':
				$this->pestana_stripe();
				break;
			default:
				$this->pestana_general();
		}

		submit_button( __( 'Guardar cambios', 'sw-pagos' ) );
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Pestaña General.
	 *
	 * @return void
	 */
	private function pestana_general(): void {
		$moneda  = $this->settings->moneda_predeterminada();
		$nivel   = (string) get_option( 'swpagos_log_level', '' );
		$borrar  = 'yes' === get_option( 'swpagos_borrar_datos_al_desinstalar' );
		$de      = $this->settings->remitente();

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="swpagos_remitente_nombre">' . esc_html__( 'Remitente de los correos', 'sw-pagos' ) . '</label></th><td>';
		printf(
			'<input type="text" id="swpagos_remitente_nombre" name="swpagos_remitente_nombre" class="regular-text" value="%s" placeholder="%s">',
			esc_attr( (string) get_option( 'swpagos_remitente_nombre', '' ) ),
			esc_attr( (string) get_bloginfo( 'name' ) )
		);
		echo '<p class="swpagos-pista">' . esc_html__( 'El nombre que ve quien recibe el correo. Vacío usa el nombre del sitio.', 'sw-pagos' ) . '</p>';
		printf(
			'<input type="email" id="swpagos_remitente_correo" name="swpagos_remitente_correo" class="regular-text" value="%s" placeholder="%s" style="margin-top:8px">',
			esc_attr( (string) get_option( 'swpagos_remitente_correo', '' ) ),
			esc_attr( \SWPagos\Core\Settings::correo_por_omision() )
		);
		echo '<p class="swpagos-pista">'
			. esc_html__( 'Dirección desde la que sale. Déjala vacía salvo que el dominio autorice otra: una dirección que el servidor de correo no reconozca acaba en la carpeta de spam.', 'sw-pagos' )
			. '</p>';
		printf(
			'<p class="swpagos-pista">%s <code>%s &lt;%s&gt;</code></p>',
			esc_html__( 'Ahora mismo los correos salen como:', 'sw-pagos' ),
			esc_html( $de['nombre'] ),
			esc_html( $de['correo'] )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="swpagos_moneda">' . esc_html__( 'Moneda por omisión', 'sw-pagos' ) . '</label></th><td>';
		echo '<input type="text" id="swpagos_moneda" name="swpagos_moneda" value="' . esc_attr( $moneda ) . '" maxlength="3" style="max-width:120px;text-transform:uppercase">';
		echo '<p class="swpagos-pista">' . esc_html__( 'Código ISO de tres letras, por ejemplo USD, GTQ o EUR. Cada plantilla de pago puede usar otra.', 'sw-pagos' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="swpagos_log_level">' . esc_html__( 'Nivel de registro', 'sw-pagos' ) . '</label></th><td>';
		echo '<select id="swpagos_log_level" name="swpagos_log_level">';
		$niveles = array(
			''        => __( 'Automático (advertencias y errores)', 'sw-pagos' ),
			'debug'   => __( 'Depuración (todo)', 'sw-pagos' ),
			'info'    => __( 'Información', 'sw-pagos' ),
			'warning' => __( 'Advertencias', 'sw-pagos' ),
			'error'   => __( 'Solo errores', 'sw-pagos' ),
		);
		foreach ( $niveles as $valor => $etiqueta ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $valor ),
				selected( $nivel, $valor, false ),
				esc_html( $etiqueta )
			);
		}
		echo '</select>';
		echo '<p class="swpagos-pista">' . esc_html__( 'Las claves secretas y los datos personales se redactan siempre, en cualquier nivel.', 'sw-pagos' ) . '</p>';
		echo '</td></tr>';

		// Los correos de aviso se configuran en «Logs de envíos», que es donde se
		// mira cuando algo ha fallado. Aquí solo se indica dónde están.
		echo '<tr><th scope="row">' . esc_html__( 'Avisos de fallo', 'sw-pagos' ) . '</th><td>';
		printf(
			'<p class="swpagos-pista">%s <a href="%s">%s</a>.</p>',
			esc_html__( 'Las direcciones que reciben aviso cuando un envío falla se configuran en', 'sw-pagos' ),
			esc_url( admin_url( 'admin.php?page=' . Page_Delivery_Logs::SLUG ) ),
			esc_html__( 'Logs de envíos', 'sw-pagos' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Al desinstalar', 'sw-pagos' ) . '</th><td>';
		echo '<label><input type="checkbox" name="swpagos_borrar_datos" value="1" ' . checked( $borrar, true, false ) . '> ';
		echo esc_html__( 'Borrar transacciones, suscripciones, bitácoras y plantillas', 'sw-pagos' ) . '</label>';
		echo '<p class="swpagos-pista">' . esc_html__( 'Desactivado por omisión: el historial de cobros no se borra porque alguien desinstale el plugin.', 'sw-pagos' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * Pestaña Stripe.
	 *
	 * @return void
	 */
	private function pestana_stripe(): void {
		echo '<p>' . esc_html__( 'Pega aquí las claves de Stripe. Se guardan en la base de datos de este sitio, así que cada sitio puede tener las suyas y se cambian desde esta pantalla, sin tocar archivos. Rellena las de prueba para probar y las de producción para cobrar de verdad.', 'sw-pagos' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::CREDENCIALES['stripe'] as $campo ) {
			$this->fila_credencial( $campo['clave'], $campo['etiqueta'], $campo['modo'] );
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Receptor de eventos', 'sw-pagos' ) . '</h3>';
		echo '<p>' . esc_html__( 'Da de alta esta dirección en el panel de Stripe como destino de webhooks:', 'sw-pagos' ) . '</p>';
		echo '<p><code>' . esc_url( rest_url( Compat::REST_NAMESPACE . '/stripe/webhook' ) ) . '</code></p>';
		echo '<p class="swpagos-pista">' . esc_html__( 'Prueba y producción usan secretos de firma distintos. Un evento cuya firma no se pueda verificar se rechaza y no mueve ningún estado.', 'sw-pagos' ) . '</p>';
	}

	/**
	 * Fila de una credencial, con su origen y su bloqueo si viene de constante.
	 *
	 * @param string $clave    Nombre lógico.
	 * @param string $etiqueta Etiqueta visible.
	 * @param string $modo     test|live.
	 * @return void
	 */
	private function fila_credencial( string $clave, string $etiqueta, string $modo ): void {
		$origen     = $this->settings->origen( $clave, $modo );
		$enmascarada = $this->settings->enmascarada( $clave, $modo );
		$constante  = $this->settings->nombre_constante( $clave, $modo );
		$fijada     = 'constante' === $origen;
		$id         = 'swpagos_cred_' . $clave . '_' . $modo;

		$etiquetas_origen = array(
			'constante' => __( 'wp-config.php', 'sw-pagos' ),
			'opcion'    => __( 'base de datos', 'sw-pagos' ),
			'ausente'   => __( 'sin configurar', 'sw-pagos' ),
		);

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $etiqueta ) . '</label>';
		printf(
			'<span class="swpagos-origen swpagos-origen--%s">%s</span>',
			esc_attr( $origen ),
			esc_html( $etiquetas_origen[ $origen ] )
		);
		echo '</th><td>';

		if ( $fijada ) {
			echo '<input type="text" id="' . esc_attr( $id ) . '" value="' . esc_attr( $enmascarada ) . '" disabled>';
			echo '<p class="swpagos-pista">';
			printf(
				/* translators: %s: nombre de la constante de PHP. */
				esc_html__( 'Definida por la constante %s. Para cambiarla, edita wp-config.php.', 'sw-pagos' ),
				'<code class="swpagos-constante">' . esc_html( $constante ) . '</code>'
			);
			echo '</p>';
		} else {
			echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="" autocomplete="off" placeholder="' . esc_attr( '' !== $enmascarada ? $enmascarada : __( 'sin configurar', 'sw-pagos' ) ) . '">';
			echo '<p class="swpagos-pista">';
			if ( '' !== $enmascarada ) {
				echo esc_html__( 'Déjalo vacío para conservar el valor actual.', 'sw-pagos' ) . ' ';
				echo '<label><input type="checkbox" name="' . esc_attr( $id ) . '_borrar" value="1"> ' . esc_html__( 'Borrar', 'sw-pagos' ) . '</label>. ';
			}
			printf(
				/* translators: %s: nombre de la constante de PHP. */
				esc_html__( 'Alternativa para quien prefiera no guardarla en la base de datos: definir %s en wp-config.php. No es necesario.', 'sw-pagos' ),
				'<code class="swpagos-constante">' . esc_html( $constante ) . '</code>'
			);
			echo '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Procesa el envío del formulario.
	 *
	 * @param string $pestana Pestaña activa.
	 * @return void
	 */
	private function procesar_envio( string $pestana ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! isset( $_POST['swpagos_tab'] ) || sanitize_key( wp_unslash( $_POST['swpagos_tab'] ) ) !== $pestana ) {
			return;
		}

		check_admin_referer( 'swpagos_guardar_' . $pestana );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para guardar esta configuración.', 'sw-pagos' ) );
		}

		switch ( $pestana ) {
			case 'general':
				$this->guardar_general();
				break;
			case 'stripe':
				$this->guardar_credenciales( self::CREDENCIALES['stripe'] );
				break;
		}

		Plugin::instancia()->logger()->info( 'Configuración guardada.', array( 'pestana' => $pestana ) );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configuración guardada.', 'sw-pagos' ) . '</p></div>';
	}

	/**
	 * Guarda la pestaña General.
	 *
	 * @return void
	 */
	private function guardar_general(): void {
		$moneda = strtoupper( sanitize_text_field( wp_unslash( $_POST['swpagos_moneda'] ?? 'USD' ) ) );
		if ( 1 === preg_match( '/^[A-Z]{3}$/', $moneda ) ) {
			update_option( 'swpagos_moneda', $moneda );
		}

		update_option( 'swpagos_remitente_nombre', sanitize_text_field( wp_unslash( $_POST['swpagos_remitente_nombre'] ?? '' ) ) );

		$remitente = sanitize_email( wp_unslash( $_POST['swpagos_remitente_correo'] ?? '' ) );
		update_option( 'swpagos_remitente_correo', is_email( $remitente ) ? $remitente : '' );

		$nivel     = sanitize_key( wp_unslash( $_POST['swpagos_log_level'] ?? '' ) );
		$permitidos = array( '', 'debug', 'info', 'warning', 'error' );
		update_option( 'swpagos_log_level', in_array( $nivel, $permitidos, true ) ? $nivel : '' );

		update_option( 'swpagos_borrar_datos_al_desinstalar', empty( $_POST['swpagos_borrar_datos'] ) ? 'no' : 'yes' );
	}

	/**
	 * Guarda un conjunto de credenciales.
	 *
	 * Un campo vacío conserva el valor actual: así la interfaz puede mostrar el
	 * valor enmascarado sin riesgo de sobrescribir la credencial real con los
	 * puntos de la máscara. Borrar exige marcarlo.
	 *
	 * @param array<int,array{clave:string,etiqueta:string,modo:string}> $campos Campos.
	 * @return void
	 */
	private function guardar_credenciales( array $campos ): void {
		foreach ( $campos as $campo ) {
			$clave = $campo['clave'];
			$modo  = $campo['modo'];

			if ( $this->settings->fijada_por_constante( $clave, $modo ) ) {
				continue;
			}

			$opcion = 'hubspot_token' === $clave
				? 'swpagos_hubspot_token'
				: $this->settings->nombre_opcion( $clave, $modo );

			$input = 'swpagos_cred_' . $clave . '_' . $modo;

			if ( ! empty( $_POST[ $input . '_borrar' ] ) ) {
				delete_option( $opcion );
				continue;
			}

			$valor = trim( sanitize_text_field( wp_unslash( $_POST[ $input ] ?? '' ) ) );

			if ( '' !== $valor ) {
				update_option( $opcion, $valor, false );
			}
		}
	}
}
