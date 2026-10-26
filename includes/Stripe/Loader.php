<?php
/**
 * Registro de las rutas de Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Engancha el receptor de eventos y el retorno.
 */
final class Loader {

	/**
	 * Registra las rutas.
	 *
	 * @return void
	 */
	public function registrar(): void {
		( new Webhook_Controller() )->registrar();
		( new Return_Controller() )->registrar();
		( new Billing_Portal() )->registrar();
		( new Subscription_Events() )->registrar();
	}
}
