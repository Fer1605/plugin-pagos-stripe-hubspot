<?php
/**
 * Confirmación de cobro obtenida consultando la API de Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Payment_Proof;

defined( 'ABSPATH' ) || exit;

/**
 * Prueba de cobro obtenida preguntándole a Stripe directamente.
 *
 * **Por qué existe.** La regla es que el estado `pagada` solo lo escribe
 * un evento verificado. Su propósito es que el navegador no pueda inventarse un
 * cobro. Una consulta autenticada a la API de Stripe, hecha desde el servidor con
 * la clave secreta, cumple ese propósito igual de bien que una firma de webhook:
 * la respuesta viene de Stripe y nadie por medio puede falsificarla.
 *
 * **Por qué hace falta.** Sin esto, un punto de enlace de webhooks mal dado de
 * alta —el error de configuración más común al empezar— dejaría todos los cobros
 * en «confirmando» para siempre, sin correos y sin registro en el CRM, y el
 * dinero sí cobrado. La reconciliación contra la API es la red de seguridad.
 *
 * Como el evento firmado, esta clase solo puede construirse tras obtener la
 * confirmación: la fábrica consulta a Stripe y devuelve null si el cobro no está
 * efectivamente pagado.
 */
final class Api_Verified_Payment implements Payment_Proof {

	/**
	 * Identificador del objeto consultado.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Objeto devuelto por Stripe.
	 *
	 * @var object
	 */
	private object $objeto;

	/**
	 * Constructor privado.
	 *
	 * @param string $id     Identificador.
	 * @param object $objeto Objeto de Stripe.
	 */
	private function __construct( string $id, object $objeto ) {
		$this->id     = $id;
		$this->objeto = $objeto;
	}

	/**
	 * Consulta una sesión de pago y devuelve la prueba si está pagada.
	 *
	 * @param string $session_id Sesión.
	 * @param string $modo       Modo.
	 * @return Api_Verified_Payment|null
	 */
	public static function desde_sesion( string $session_id, string $modo ): ?Api_Verified_Payment {
		if ( '' === $session_id ) {
			return null;
		}

		$stripe = ( new Client() )->para( $modo );

		if ( null === $stripe ) {
			return null;
		}

		try {
			$sesion = $stripe->checkout->sessions->retrieve( $session_id );
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->warning(
				'No se pudo consultar la sesión de pago en Stripe.',
				array(
					'sesion' => $session_id,
					'error'  => $e->getMessage(),
				)
			);

			return null;
		}

		if ( 'paid' !== ( $sesion->payment_status ?? '' ) ) {
			return null;
		}

		return new self( (string) $sesion->id, $sesion );
	}

	/**
	 * Identificador sintético del «evento».
	 *
	 * Lleva un prefijo propio para que en la bitácora se distinga a simple vista
	 * de un evento real reenviado por Stripe.
	 *
	 * @return string
	 */
	public function event_id(): string {
		return 'api_' . $this->id;
	}

	/**
	 * Tipo.
	 *
	 * @return string
	 */
	public function tipo(): string {
		return 'api.checkout.session.paid';
	}

	/**
	 * Objeto consultado.
	 *
	 * @return object
	 */
	public function objeto(): object {
		return $this->objeto;
	}
}
