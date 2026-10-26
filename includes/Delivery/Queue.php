<?php
/**
 * Cola de entregas en segundo plano.
 *
 * @package SWPagos
 */

namespace SWPagos\Delivery;

use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Encola el trabajo que no debe hacer esperar al receptor de eventos.
 *
 * Stripe espera una respuesta rápida: si tarda, marca el punto de enlace como
 * lento y reenvía el evento, duplicando trabajo. Por eso el receptor solo valida,
 * guarda y encola, y el correo y las llamadas a HubSpot ocurren después.
 *
 * Usa Action Scheduler si está disponible —viene con WooCommerce, que está en
 * todos los sitios donde se va a instalar esto— y si no, las tareas programadas
 * de WordPress. Las dos vías ejecutan exactamente el mismo gancho.
 */
final class Queue {

	/**
	 * Gancho que ejecuta un trabajo.
	 *
	 * @var string
	 */
	public const GANCHO = 'swpagos_trabajo';

	/**
	 * Grupo de Action Scheduler.
	 *
	 * @var string
	 */
	private const GRUPO = 'swpagos';

	/**
	 * Engancha el ejecutor.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( self::GANCHO, array( $this, 'ejecutar' ), 10, 1 );
	}

	/**
	 * Indica si Action Scheduler está disponible.
	 *
	 * @return bool
	 */
	public static function hay_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Encola un trabajo.
	 *
	 * @param array<string,mixed> $trabajo Datos del trabajo.
	 * @param int                 $espera  Segundos de espera.
	 * @return void
	 */
	public function encolar( array $trabajo, int $espera = 0 ): void {
		$momento = time() + max( 0, $espera );

		if ( self::hay_action_scheduler() ) {
			as_schedule_single_action( $momento, self::GANCHO, array( $trabajo ), self::GRUPO );
			return;
		}

		wp_schedule_single_event( $momento, self::GANCHO, array( $trabajo ) );
	}

	/**
	 * Ejecuta un trabajo encolado.
	 *
	 * @param array<string,mixed> $trabajo Datos.
	 * @return void
	 */
	public function ejecutar( $trabajo ): void {
		if ( ! is_array( $trabajo ) || empty( $trabajo['tipo'] ) ) {
			return;
		}

		/**
		 * Ejecuta un trabajo de la cola.
		 *
		 * Cada tipo de trabajo se engancha aquí. Que sea una acción permite añadir
		 * tipos nuevos sin tocar la cola.
		 *
		 * @param array<string,mixed> $trabajo Datos del trabajo.
		 */
		do_action( 'swpagos_ejecutar_' . sanitize_key( (string) $trabajo['tipo'] ), $trabajo );
	}

	/**
	 * Vuelve a encolar un trabajo que falló, si quedan intentos.
	 *
	 * @param array<string,mixed> $trabajo  Trabajo.
	 * @param string              $canal    Canal, para la alerta.
	 * @param array<string,mixed> $contexto Contexto de la alerta.
	 * @return void
	 */
	public function reintentar( array $trabajo, string $canal, array $contexto = array() ): void {
		$politica = new Retry_Policy();
		$intento  = max( 1, (int) ( $trabajo['intento'] ?? 1 ) );

		if ( ! $politica->quedan_intentos( $intento ) ) {
			Plugin::instancia()->logger()->error(
				'Entrega descartada tras agotar los reintentos.',
				array(
					'canal'   => $canal,
					'intento' => $intento,
				)
			);

			$politica->alertar( $canal, $contexto );

			return;
		}

		$trabajo['intento'] = $intento + 1;

		$this->encolar( $trabajo, $politica->espera( $intento ) );
	}
}
