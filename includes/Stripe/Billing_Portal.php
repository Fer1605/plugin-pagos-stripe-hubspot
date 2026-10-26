<?php
/**
 * Portal de facturación de Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;
use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Enlace para que quien dona gestione su propia suscripción.
 *
 * Las sesiones del portal de Stripe caducan en minutos, así que un correo no
 * puede llevar una directamente: cuando alguien lo abra tres días después estará
 * muerta. Lo que viaja en el correo es una URL de este sitio, firmada, que crea
 * la sesión en el momento de abrirse y redirige. Así el enlace no caduca y sigue
 * sin poder falsificarse.
 */
final class Billing_Portal {

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
			'/portal',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'abrir' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					's' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'k' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * URL firmada que se incluye en los correos.
	 *
	 * @param Subscription $suscripcion Suscripción.
	 * @return string
	 */
	public function url_para( Subscription $suscripcion ): string {
		return add_query_arg(
			array(
				's' => $suscripcion->uuid(),
				'k' => $this->firma( $suscripcion->uuid() ),
			),
			rest_url( Compat::REST_NAMESPACE . '/portal' )
		);
	}

	/**
	 * Abre el portal y redirige.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return \WP_REST_Response|void
	 */
	public function abrir( \WP_REST_Request $peticion ) {
		$uuid  = (string) $peticion->get_param( 's' );
		$firma = (string) $peticion->get_param( 'k' );

		if ( ! hash_equals( $this->firma( $uuid ), $firma ) ) {
			return new \WP_REST_Response( array( 'error' => 'enlace_invalido' ), 403 );
		}

		$suscripcion = ( new Subscription_Repository() )->por_uuid( $uuid );

		if ( null === $suscripcion ) {
			return new \WP_REST_Response( array( 'error' => 'no_encontrada' ), 404 );
		}

		$url = $this->crear_sesion( $suscripcion );

		if ( '' === $url ) {
			return new \WP_REST_Response(
				array( 'error' => 'portal_no_disponible' ),
				503
			);
		}

		nocache_headers();
		wp_redirect( $url, 303 );
		exit;
	}

	/**
	 * Crea la sesión del portal en Stripe.
	 *
	 * @param Subscription $suscripcion Suscripción.
	 * @return string URL, o cadena vacía si no se pudo.
	 */
	private function crear_sesion( Subscription $suscripcion ): string {
		$cliente = $suscripcion->stripe_customer_id();

		if ( '' === $cliente ) {
			return '';
		}

		$stripe = ( new Client() )->para( $suscripcion->modo() );

		if ( null === $stripe ) {
			return '';
		}

		try {
			$sesion = $stripe->billingPortal->sessions->create(
				array(
					'customer'   => $cliente,
					'return_url' => home_url( '/' ),
				)
			);

			return (string) $sesion->url;
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->warning(
				'No se pudo abrir el portal de facturación. ¿Está configurado en el panel de Stripe?',
				array(
					'suscripcion' => $suscripcion->uuid(),
					'error'       => $e->getMessage(),
				)
			);

			return '';
		}
	}

	/**
	 * Firma del enlace.
	 *
	 * @param string $uuid Identificador de la suscripción.
	 * @return string
	 */
	private function firma( string $uuid ): string {
		return hash_hmac( 'sha256', 'swpagos_portal|' . $uuid, wp_salt( 'auth' ) );
	}
}
