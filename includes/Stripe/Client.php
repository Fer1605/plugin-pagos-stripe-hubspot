<?php
/**
 * Fábrica del cliente de Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Plugin;
use SWPagos\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Construye el cliente del SDK con la credencial del modo que toque.
 *
 * El modo se recibe, nunca se adivina: lo fija quien crea la transacción y queda
 * grabado en ella.
 */
final class Client {

	/**
	 * Segundos de espera de la conexión.
	 *
	 * @var int
	 */
	private const TIMEOUT = 30;

	/**
	 * Indica si el SDK está disponible.
	 *
	 * @return bool
	 */
	public static function sdk_disponible(): bool {
		return class_exists( '\\Stripe\\StripeClient' );
	}

	/**
	 * Construye el cliente para un modo.
	 *
	 * @param string $modo test|live.
	 * @return \Stripe\StripeClient|null
	 */
	public function para( string $modo ): ?\Stripe\StripeClient {
		if ( ! self::sdk_disponible() ) {
			Plugin::instancia()->logger()->error(
				'El SDK de Stripe no está instalado. Ejecuta composer install en el plugin.'
			);

			return null;
		}

		$modo   = Settings::normalizar_modo( $modo );
		$secreta = Plugin::instancia()->settings()->stripe_secret( $modo );

		if ( '' === $secreta ) {
			Plugin::instancia()->logger()->error(
				'Falta la clave secreta de Stripe.',
				array( 'modo' => $modo )
			);

			return null;
		}

		if ( ! $this->clave_coincide_con_modo( $secreta, $modo ) ) {
			// Cobrar de verdad creyendo que se está en pruebas es el peor fallo
			// posible de este módulo, así que se detiene antes de intentarlo.
			Plugin::instancia()->logger()->error(
				'La clave secreta de Stripe no corresponde al modo configurado. Se cancela la operación.',
				array( 'modo' => $modo )
			);

			return null;
		}

		return new \Stripe\StripeClient(
			array(
				'api_key'            => $secreta,
				'stripe_version'     => '2024-06-20',
				'max_network_retries' => 2,
			)
		);
	}

	/**
	 * Comprueba que el prefijo de la clave corresponde al modo.
	 *
	 * @param string $clave Clave secreta.
	 * @param string $modo  Modo.
	 * @return bool
	 */
	private function clave_coincide_con_modo( string $clave, string $modo ): bool {
		if ( str_starts_with( $clave, 'sk_test_' ) || str_starts_with( $clave, 'rk_test_' ) ) {
			return 'test' === $modo;
		}

		if ( str_starts_with( $clave, 'sk_live_' ) || str_starts_with( $clave, 'rk_live_' ) ) {
			return 'live' === $modo;
		}

		// Formato desconocido: se deja pasar, pero queda constancia.
		Plugin::instancia()->logger()->warning(
			'La clave secreta de Stripe no tiene un prefijo reconocible.',
			array( 'modo' => $modo )
		);

		return true;
	}
}
