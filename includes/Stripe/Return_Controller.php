<?php
/**
 * Retorno desde la pantalla de pago.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Lleva a quien vuelve de Stripe a la página que le corresponde.
 *
 * Esta ruta **lee** estado; nunca lo inventa. Si el cobro todavía no está
 * confirmado, espera y reconsulta antes de decir nada, y como último recurso le
 * pregunta a Stripe. Lo que no hace jamás es enseñar una página de rechazo a
 * quien acaba de pagar, que es el fallo del módulo de referencia (hallazgo H-05:
 * dos intentos de sondeo y a la página de fallo).
 */
final class Return_Controller {

	/**
	 * Segundos máximos que la pantalla de espera sigue consultando.
	 *
	 * @var int
	 */
	private const ESPERA_MAXIMA = 40;

	/**
	 * Engancha las rutas.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
	}

	/**
	 * Registra las rutas REST.
	 *
	 * @return void
	 */
	public function registrar_rutas(): void {
		register_rest_route(
			Compat::REST_NAMESPACE,
			'/retorno',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'retorno' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Compat::REST_NAMESPACE,
			'/estado',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'estado' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					't' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Atiende el retorno desde Stripe.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return void
	 */
	public function retorno( \WP_REST_Request $peticion ): void {
		$uuid       = sanitize_text_field( (string) $peticion->get_param( 't' ) );
		$sesion     = sanitize_text_field( (string) $peticion->get_param( 'session_id' ) );
		$cancelado  = '1' === (string) $peticion->get_param( 'cancelado' );

		$repo        = new Transaction_Repository();
		$transaccion = '' !== $uuid ? $repo->por_uuid( $uuid ) : null;

		if ( null === $transaccion ) {
			$this->ir_a( home_url( '/' ) );
			return;
		}

		if ( $cancelado && ! $transaccion->esta_pagada() ) {
			// La persona pulsó «volver» en la pantalla de Stripe. No es un fallo de
			// cobro, pero la transacción no va a completarse.
			$repo->transicionar(
				$transaccion,
				Transaction::CANCELADA,
				array( 'failure_code' => 'cancelado_por_usuario' )
			);

			$this->ir_a( $this->url_fallo( $transaccion ) );
			return;
		}

		$transaccion = $this->reconciliar( $transaccion, $sesion );

		if ( $transaccion->esta_pagada() ) {
			$this->ir_a( $this->url_exito( $transaccion ) );
			return;
		}

		if ( in_array( $transaccion->estado(), array( Transaction::FALLIDA, Transaction::CANCELADA, Transaction::RECHAZADA ), true ) ) {
			$this->ir_a( $this->url_fallo( $transaccion ) );
			return;
		}

		$this->pantalla_de_espera( $transaccion );
	}

	/**
	 * Devuelve el estado de una transacción para el sondeo.
	 *
	 * @param \WP_REST_Request $peticion Petición.
	 * @return \WP_REST_Response
	 */
	public function estado( \WP_REST_Request $peticion ): \WP_REST_Response {
		$uuid   = sanitize_text_field( (string) $peticion->get_param( 't' ) );
		$sesion = sanitize_text_field( (string) $peticion->get_param( 'session_id' ) );

		$repo        = new Transaction_Repository();
		$transaccion = '' !== $uuid ? $repo->por_uuid( $uuid ) : null;

		if ( null === $transaccion ) {
			return new \WP_REST_Response( array( 'estado' => 'desconocido' ), 404 );
		}

		$transaccion = $this->reconciliar( $transaccion, $sesion );

		$estado = $transaccion->estado();

		return new \WP_REST_Response(
			array(
				'estado'   => $estado,
				'resuelto' => $transaccion->esta_pagada()
					|| in_array( $estado, array( Transaction::FALLIDA, Transaction::CANCELADA, Transaction::RECHAZADA ), true ),
				'destino'  => $transaccion->esta_pagada()
					? $this->url_exito( $transaccion )
					: $this->url_fallo( $transaccion ),
			),
			200
		);
	}

	/**
	 * Si el cobro sigue sin confirmar, le pregunta a Stripe.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @param string      $sesion      Sesión de pago.
	 * @return Transaction
	 */
	private function reconciliar( Transaction $transaccion, string $sesion ): Transaction {
		if ( $transaccion->esta_pagada() || Transaction::PENDIENTE !== $transaccion->estado() ) {
			return $transaccion;
		}

		if ( '' === $sesion ) {
			$sesion = (string) $transaccion->valor( 'stripe_session_id', '' );
		}

		if ( '' === $sesion ) {
			return $transaccion;
		}

		$prueba = Api_Verified_Payment::desde_sesion( $sesion, $transaccion->modo() );

		if ( null === $prueba ) {
			return $transaccion;
		}

		$objeto = $prueba->objeto();

		( new Event_Handler() )->confirmar_reconciliado(
			$transaccion,
			$prueba,
			array_filter(
				array(
					'stripe_payment_intent_id' => is_string( $objeto->payment_intent ?? null ) ? $objeto->payment_intent : null,
					'stripe_customer_id'       => is_string( $objeto->customer ?? null ) ? $objeto->customer : null,
				)
			)
		);

		return ( new Transaction_Repository() )->por_id( $transaccion->id() ) ?? $transaccion;
	}

	/**
	 * Página de espera mientras llega la confirmación.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return void
	 */
	private function pantalla_de_espera( Transaction $transaccion ): void {
		$estado_url = add_query_arg(
			array(
				't'          => $transaccion->uuid(),
				'session_id' => (string) $transaccion->valor( 'stripe_session_id', '' ),
			),
			rest_url( Compat::REST_NAMESPACE . '/estado' )
		);

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );

		$titulo    = __( 'Confirmando tu pago…', 'sw-pagos' );
		$mensaje   = __( 'Estamos confirmando tu pago con el banco. No cierres esta ventana.', 'sw-pagos' );
		$paciencia = __( 'Está tardando más de lo normal. Tu pago sigue en proceso y recibirás un correo en cuanto se confirme. Puedes cerrar esta ventana sin problema.', 'sw-pagos' );
		$fallo     = __( 'No pudimos confirmar el pago en este momento.', 'sw-pagos' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $titulo ); ?></title>
	<style>
		body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
			font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f6f7f7; color:#1d2327; }
		.caja { max-width:460px; padding:40px 28px; text-align:center; }
		.giro { width:38px; height:38px; margin:0 auto 22px; border:3px solid #dcdcde; border-top-color:#2271b1;
			border-radius:50%; animation:gira 1s linear infinite; }
		@keyframes gira { to { transform: rotate(360deg); } }
		@media (prefers-reduced-motion: reduce) { .giro { animation-duration: 3s; } }
		h1 { font-size:19px; margin:0 0 10px; }
		p { font-size:15px; line-height:1.55; color:#50575e; margin:0; }
	</style>
</head>
<body>
	<div class="caja">
		<div class="giro" role="status" aria-live="polite"></div>
		<h1><?php echo esc_html( $titulo ); ?></h1>
		<p id="swpagos-mensaje"><?php echo esc_html( $mensaje ); ?></p>
	</div>
	<script>
	(function () {
		var url = <?php echo wp_json_encode( $estado_url ); ?>;
		var fallo = <?php echo wp_json_encode( $this->url_fallo( $transaccion ) ); ?>;
		var textoPaciencia = <?php echo wp_json_encode( $paciencia ); ?>;
		var textoFallo = <?php echo wp_json_encode( $fallo ); ?>;
		var limite = <?php echo (int) self::ESPERA_MAXIMA; ?> * 1000;
		var inicio = Date.now();
		var espera = 1000;

		function mensaje( texto ) {
			var p = document.getElementById( 'swpagos-mensaje' );
			if ( p ) { p.textContent = texto; }
		}

		function consultar() {
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					if ( res && res.resuelto && res.destino ) {
						window.location.replace( res.destino );
						return;
					}
					siguiente();
				} )
				.catch( siguiente );
		}

		function siguiente() {
			if ( Date.now() - inicio > limite ) {
				// Nunca se manda a la página de rechazo a quien pudo haber pagado:
				// se dice la verdad, que es que aún no hay confirmación.
				mensaje( textoPaciencia );
				return;
			}
			// Espera creciente: el webhook suele llegar en el primer intento, y si
			// tarda, insistir cada segundo solo añade carga.
			espera = Math.min( espera * 1.6, 6000 );
			window.setTimeout( consultar, espera );
		}

		consultar();
	})();
	</script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * URL de agradecimiento.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return string
	 */
	private function url_exito( Transaction $transaccion ): string {
		$plantilla = Plantilla::por_id( $transaccion->template_id() );
		$url       = null !== $plantilla ? $plantilla->url_exito() : '';

		if ( '' === $url ) {
			// Sin página propia, la del módulo: dejar el campo en blanco es una
			// opción válida, no un callejón sin salida.
			$url = \SWPagos\Frontend\Result_Pages::url( 'exito', (string) $transaccion->valor( 'locale', '' ) );
		}

		return add_query_arg( 't', $transaccion->uuid(), $url );
	}

	/**
	 * URL de rechazo.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return string
	 */
	private function url_fallo( Transaction $transaccion ): string {
		$plantilla = Plantilla::por_id( $transaccion->template_id() );
		$url       = null !== $plantilla ? $plantilla->url_fallo() : '';

		if ( '' === $url ) {
			$url = \SWPagos\Frontend\Result_Pages::url( 'fallo', (string) $transaccion->valor( 'locale', '' ) );
		}

		return add_query_arg( 't', $transaccion->uuid(), $url );
	}

	/**
	 * Redirige y termina.
	 *
	 * @param string $url Destino.
	 * @return void
	 */
	private function ir_a( string $url ): void {
		nocache_headers();
		wp_safe_redirect( $url, 303 );
		exit;
	}
}
