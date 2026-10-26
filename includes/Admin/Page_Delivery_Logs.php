<?php
/**
 * Pantalla «Logs de envíos».
 *
 * @package SWPagos
 */

namespace SWPagos\Admin;

use SWPagos\Admin\Tables\Logs_Table;
use SWPagos\Core\Settings;
use SWPagos\Delivery\Email_Service;
use SWPagos\Delivery\Queue;
use SWPagos\Domain\Delivery_Log;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\HubSpot\Hef_Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Qué salió de cada cobro, por correo y hacia HubSpot, con reenvío a mano.
 *
 * «Entregas» no decía nada: hay que leer la pantalla entera para entender de qué
 * va. Son los registros de lo que el módulo intentó enviar, así que se llaman
 * por su nombre. Y los correos a los que avisar cuando algo falla se configuran
 * aquí mismo, no en otra pantalla: es donde se mira cuando algo ha fallado.
 */
final class Page_Delivery_Logs extends Page_Base {

	/**
	 * Identificador.
	 *
	 * @var string
	 */
	public const SLUG = 'swpagos-entregas';

	/**
	 * Identificador de la pantalla.
	 *
	 * @return string
	 */
	protected function slug(): string {
		return self::SLUG;
	}

	/**
	 * Engancha las acciones.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'admin_post_swpagos_reenviar_correo', array( $this, 'reenviar_correo' ) );
		add_action( 'admin_post_swpagos_reenviar_hubspot', array( $this, 'reenviar_hubspot' ) );
		add_action( 'admin_post_swpagos_guardar_alertas', array( $this, 'guardar_alertas' ) );
	}

	/**
	 * Pinta la pantalla.
	 *
	 * @return void
	 */
	public function renderizar(): void {
		$this->exigir_permisos();

		echo '<div class="wrap swpagos-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Logs de envíos', 'sw-pagos' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<p class="description">' . esc_html__( 'Una fila por cobro, con lo que pasó en los dos envíos que salen de él: el correo a quien donó y el envío del formulario a HubSpot. Si alguno falló, se puede reenviar desde aquí.', 'sw-pagos' ) . '</p>';

		$this->avisos();
		$this->caja_alertas();

		$tabla = new Logs_Table( self::SLUG );
		$tabla->prepare_items();
		$tabla->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );

		$canal = isset( $_GET['canal_estado'] ) ? sanitize_key( wp_unslash( $_GET['canal_estado'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $canal ) {
			printf( '<input type="hidden" name="canal_estado" value="%s">', esc_attr( $canal ) );
		}

		$tabla->search_box( __( 'Buscar por correo o referencia', 'sw-pagos' ), 'swpagos-logs' );
		$tabla->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Caja de alertas, arriba del listado.
	 *
	 * @return void
	 */
	private function caja_alertas(): void {
		$ajustes = \SWPagos\Core\Plugin::instancia()->settings();
		$to      = implode( ', ', $ajustes->destinatarios_alerta() );
		$bcc     = implode( ', ', $ajustes->copias_alerta() );

		echo '<div class="swpagos-panel swpagos-alertas">';
		echo '<h2>' . esc_html__( 'Alertas de fallos (correo / HubSpot)', 'sw-pagos' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Cuando un envío falle y agote sus reintentos, se avisará a estas direcciones. Es el aviso al equipo, no el correo que recibe quien dona: ese se configura en la plantilla de pago.', 'sw-pagos' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'swpagos_guardar_alertas' );
		echo '<input type="hidden" name="action" value="swpagos_guardar_alertas">';

		printf(
			'<p><label for="swpagos-alerta-to"><strong>%s</strong></label><br>'
			. '<input type="text" id="swpagos-alerta-to" name="swpagos_alerta_to" class="regular-text" value="%s" placeholder="%s"></p>',
			esc_html__( 'Correo principal', 'sw-pagos' ),
			esc_attr( $to ),
			esc_attr__( 'alertas@tudominio.com', 'sw-pagos' )
		);

		printf(
			'<p><label for="swpagos-alerta-bcc"><strong>%s</strong></label><br>'
			. '<input type="text" id="swpagos-alerta-bcc" name="swpagos_alerta_bcc" class="regular-text" value="%s" placeholder="%s"></p>',
			esc_html__( 'Copia oculta, separadas por comas', 'sw-pagos' ),
			esc_attr( $bcc ),
			esc_attr__( 'dev1@tudominio.com, dev2@tudominio.com', 'sw-pagos' )
		);

		submit_button( __( 'Guardar alertas', 'sw-pagos' ), 'secondary', 'submit', false );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Guarda los correos de alerta.
	 *
	 * @return void
	 */
	public function guardar_alertas(): void {
		$this->exigir_permisos();
		check_admin_referer( 'swpagos_guardar_alertas' );

		foreach ( array( 'swpagos_alerta_to', 'swpagos_alerta_bcc' ) as $campo ) {
			$crudo = sanitize_text_field( wp_unslash( $_POST[ $campo ] ?? '' ) );
			update_option( $campo, implode( ',', Settings::lista_correos( $crudo ) ) );
		}

		$this->volver( 'ok', __( 'Direcciones de alerta guardadas.', 'sw-pagos' ) );
	}

	/**
	 * Vuelve a encolar el correo de un cobro.
	 *
	 * @return void
	 */
	public function reenviar_correo(): void {
		$transaccion = $this->transaccion_pedida( 'swpagos_reenviar_correo' );

		if ( null === $transaccion ) {
			$this->volver( 'error', __( 'No encuentro ese cobro.', 'sw-pagos' ) );
		}

		$tipo = \SWPagos\Domain\Transaction::TIPO_RENOVACION === $transaccion->tipo()
			? Email_Service::TIPO_RECIBO
			: Email_Service::TIPO_CONFIRMACION;

		( new Queue() )->encolar(
			array(
				'tipo'        => 'email',
				'correo'      => $tipo,
				'transaccion' => $transaccion->id(),
				'intento'     => 1,
			)
		);

		$this->volver( 'ok', __( 'Correo encolado. En cuanto salga, su resultado aparece en esta misma fila.', 'sw-pagos' ) );
	}

	/**
	 * Vuelve a mandar el envío a HubSpot.
	 *
	 * Este va directo, sin cola: el módulo de HubSpot guarda su propio registro
	 * y así el resultado se ve al instante.
	 *
	 * @return void
	 */
	public function reenviar_hubspot(): void {
		$transaccion = $this->transaccion_pedida( 'swpagos_reenviar_hubspot' );

		if ( null === $transaccion ) {
			$this->volver( 'error', __( 'No encuentro ese cobro.', 'sw-pagos' ) );
		}

		$registro = (int) ( $transaccion->metadata()['_hef_log'] ?? 0 );

		if ( $registro <= 0 ) {
			$this->volver( 'error', __( 'Este cobro no tiene ningún envío a HubSpot: el formulario no lo lleva configurado.', 'sw-pagos' ) );
		}

		$ok = Hef_Bridge::reenviar( $registro );

		Delivery_Log::registrar(
			array(
				'canal'       => Delivery_Log::CANAL_HUBSPOT_FORMS,
				'estado'      => $ok ? Delivery_Log::EXITO : Delivery_Log::FALLO,
				'form_id'     => $transaccion->form_id(),
				'transaccion' => $transaccion->id(),
				'destino'     => 'hubspot',
				'mensaje'     => $ok
					? __( 'Reenviado a mano desde los logs.', 'sw-pagos' )
					: __( 'El reenvío a mano volvió a fallar.', 'sw-pagos' ),
			)
		);

		$this->volver(
			$ok ? 'ok' : 'error',
			$ok
				? __( 'Enviado a HubSpot.', 'sw-pagos' )
				: __( 'HubSpot volvió a rechazarlo. El detalle está en la columna de HubSpot.', 'sw-pagos' )
		);
	}

	/**
	 * Transacción de la petición, con permisos y nonce comprobados.
	 *
	 * @param string $accion Acción del nonce.
	 * @return \SWPagos\Domain\Transaction|null
	 */
	private function transaccion_pedida( string $accion ) {
		$this->exigir_permisos();

		$id = isset( $_GET['transaccion'] ) ? (int) $_GET['transaccion'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( $accion . '_' . $id );

		return $id > 0 ? ( new Transaction_Repository() )->por_id( $id ) : null;
	}

	/**
	 * Vuelve al listado con un aviso.
	 *
	 * @param string $tipo    ok|error.
	 * @param string $mensaje Mensaje.
	 * @return void
	 */
	private function volver( string $tipo, string $mensaje ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::SLUG,
					'swpagos_aviso' => $tipo,
					'swpagos_texto' => rawurlencode( $mensaje ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pinta el aviso de la acción anterior.
	 *
	 * @return void
	 */
	private function avisos(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tipo = isset( $_GET['swpagos_aviso'] ) ? sanitize_key( wp_unslash( $_GET['swpagos_aviso'] ) ) : '';

		if ( '' === $tipo ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$texto = isset( $_GET['swpagos_texto'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['swpagos_texto'] ) ) ) : '';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'ok' === $tipo ? 'success' : 'error',
			esc_html( $texto )
		);
	}
}
