<?php
/**
 * Receptor de eventos de Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de enlace público, pero no confiado.
 *
 * Es público porque Stripe tiene que poder llamarlo sin credenciales de
 * WordPress; no es confiado porque **toda** petición pasa por la verificación de
 * firma antes de tocar nada. Una firma que no cuadra se responde con 400 y no
 * mueve ningún estado.
 */
final class Webhook_Controller {

	/**
	 * Engancha la ruta.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'rest_api_init', array( $this, 'registrar_ruta' ) );
	}

	/**
	 * Registra la ruta REST.
	 *
	 * @return void
	 */
	public function registrar_ruta(): void {
		register_rest_route(
			Compat::REST_NAMESPACE,
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recibir' ),
				// Público a propósito: quien autoriza es la firma de Stripe, no
				// WordPress. Se declara explícitamente para no dejar el aviso que
				// el módulo de referencia deja en cada carga (hallazgo H-02).
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Atiende un evento.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return \WP_REST_Response
	 */
	public function recibir( \WP_REST_Request $peticion ): \WP_REST_Response {
		$cuerpo = $peticion->get_body();
		$firma  = (string) $peticion->get_header( 'stripe_signature' );

		$evento = Verified_Event::verificar( $cuerpo, $firma );

		if ( null === $evento ) {
			Plugin::instancia()->logger()->warning(
				'Evento de Stripe rechazado: la firma no se pudo verificar.',
				array( 'longitud' => strlen( $cuerpo ) )
			);

			return new \WP_REST_Response( array( 'error' => 'firma_invalida' ), 400 );
		}

		$almacen = new Event_Store();

		if ( ! $almacen->reclamar( $evento ) ) {
			// Ya lo procesamos. Se responde bien para que Stripe deje de reenviarlo.
			return new \WP_REST_Response(
				array(
					'estado' => 'duplicado',
					'evento' => $evento->event_id(),
				),
				200
			);
		}

		try {
			$resultado = ( new Event_Handler() )->procesar( $evento );
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->error(
				'Error al procesar un evento de Stripe.',
				array(
					'evento' => $evento->event_id(),
					'tipo'   => $evento->tipo(),
					'error'  => $e->getMessage(),
				)
			);

			// Se responde 500 a propósito: así Stripe reintenta. El evento queda
			// reclamado pero sin marcar como procesado, y el diagnóstico lo lista.
			return new \WP_REST_Response( array( 'error' => 'error_interno' ), 500 );
		}

		$almacen->marcar_procesado(
			$evento->event_id(),
			$resultado['transaccion'] ?? 0,
			$resultado['suscripcion'] ?? 0
		);

		return new \WP_REST_Response(
			array(
				'estado' => 'procesado',
				'evento' => $evento->event_id(),
			),
			200
		);
	}

	/**
	 * URL del punto de enlace, para mostrarla en los ajustes.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( Compat::REST_NAMESPACE . '/stripe/webhook' );
	}
}
