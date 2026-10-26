<?php
/**
 * Vista previa del correo de una plantilla.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

use SWPagos\Core\Compat;
use SWPagos\Delivery\Email_Service;
use SWPagos\Delivery\Post_Payment;
use SWPagos\Domain\Transaction_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Enseña el correo ya montado, sin enviarlo.
 *
 * El diseño del correo no vive en el editor de la plantilla: ahí se escribe el
 * mensaje y nada más. Eso mantiene la plantilla simple, pero deja a quien la
 * edita sin saber cómo va a quedar. Esto lo resuelve: mismo código de envío,
 * misma envoltura, pero el resultado va a la pantalla en vez de al buzón.
 */
final class Email_Preview {

	public const ACCION = 'swpagos_previsualizar_correo';

	/**
	 * Engancha la acción.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'admin_post_' . self::ACCION, array( $this, 'mostrar' ) );
	}

	/**
	 * Enlace de vista previa de una plantilla.
	 *
	 * @param int $plantilla Identificador.
	 * @return string
	 */
	public static function url( int $plantilla ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACCION . '&p=' . $plantilla ),
			self::ACCION . '_' . $plantilla
		);
	}

	/**
	 * Pinta el correo.
	 *
	 * @return void
	 */
	public function mostrar(): void {
		$id = isset( $_GET['p'] ) ? (int) $_GET['p'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::ACCION . '_' . $id );

		if ( ! current_user_can( 'manage_options' ) || Compat::CPT_PLANTILLA !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta vista previa.', 'sw-pagos' ) );
		}

		$html = '';

		// Se captura lo que el envío real produciría, sin dejar que salga.
		$capturar = static function ( array $args ) use ( &$html ): array {
			$html       = (string) $args['message'];
			$args['to'] = '';
			return $args;
		};

		add_filter( 'wp_mail', $capturar, 9999 );

		$plantilla = Plantilla::por_id( $id );
		$ultima    = null !== $plantilla
			? ( new Transaction_Repository() )->buscar(
				array(
					'form_id' => $plantilla->form_id(),
					'estado'  => 'pagada',
				),
				1
			)
			: array();

		$trabajo = array(
			'correo'      => Email_Service::TIPO_CONFIRMACION,
			'transaccion' => isset( $ultima[0] ) ? $ultima[0]->id() : 0,
		);

		( new Post_Payment() )->trabajo_email( $trabajo );

		remove_filter( 'wp_mail', $capturar, 9999 );

		if ( '' === trim( $html ) ) {
			wp_die(
				esc_html__( 'No se pudo montar la vista previa. Revisa que la plantilla tenga destinatario y cuerpo.', 'sw-pagos' ),
				esc_html__( 'Vista previa', 'sw-pagos' ),
				array( 'back_link' => true )
			);
		}

		$aviso = isset( $ultima[0] )
			? __( 'Vista previa con los datos de la última transacción pagada de esta plantilla. No se ha enviado nada.', 'sw-pagos' )
			: __( 'Vista previa sin datos reales: los marcadores aparecen tal cual porque esta plantilla todavía no tiene ningún cobro. No se ha enviado nada.', 'sw-pagos' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		printf(
			'<div style="position:sticky;top:0;z-index:2;padding:10px 16px;background:#1f2933;color:#fff;
				font:14px -apple-system,BlinkMacSystemFont,sans-serif">%s</div>',
			esc_html( $aviso )
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML del correo ya construido y escapado.
		exit;
	}
}
