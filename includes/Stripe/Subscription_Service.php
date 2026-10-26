<?php
/**
 * Operaciones sobre suscripciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Cancelación y sincronización desde el panel de administración.
 */
final class Subscription_Service {

	/**
	 * Repositorio.
	 *
	 * @var Subscription_Repository
	 */
	private Subscription_Repository $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo = new Subscription_Repository();
	}

	/**
	 * Cancela una suscripción.
	 *
	 * @param Subscription $suscripcion Suscripción.
	 * @param bool         $al_final    Si surte efecto al terminar el período pagado.
	 * @return array{ok:bool,mensaje:string}
	 */
	public function cancelar( Subscription $suscripcion, bool $al_final = true ): array {
		if ( ! $suscripcion->esta_viva() ) {
			return array(
				'ok'      => false,
				'mensaje' => __( 'Esta suscripción ya estaba cancelada.', 'sw-pagos' ),
			);
		}

		$stripe = ( new Client() )->para( $suscripcion->modo() );

		if ( null === $stripe ) {
			return array(
				'ok'      => false,
				'mensaje' => __( 'No hay conexión con Stripe. Revisa las credenciales.', 'sw-pagos' ),
			);
		}

		try {
			if ( $al_final ) {
				$stripe->subscriptions->update(
					$suscripcion->stripe_id(),
					array( 'cancel_at_period_end' => true )
				);
			} else {
				$stripe->subscriptions->cancel( $suscripcion->stripe_id() );
			}
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->error(
				'No se pudo cancelar la suscripción en Stripe.',
				array(
					'suscripcion' => $suscripcion->uuid(),
					'error'       => $e->getMessage(),
				)
			);

			return array(
				'ok'      => false,
				'mensaje' => __( 'Stripe rechazó la cancelación. Revisa el registro para el detalle.', 'sw-pagos' ),
			);
		}

		if ( $al_final ) {
			// Sigue activa hasta el final del período: se marca la intención y el
			// evento de Stripe la cancelará cuando toque.
			$this->repo->actualizar( $suscripcion, array( 'cancel_at_period_end' => 1 ), 'cancelacion_programada' );

			return array(
				'ok'      => true,
				'mensaje' => __( 'La suscripción se cancelará al terminar el período ya pagado.', 'sw-pagos' ),
			);
		}

		$this->repo->transicionar( $suscripcion, Subscription::CANCELADA, array(), 'cancelacion_administrador' );

		/**
		 * Se dispara al cancelar desde el panel.
		 *
		 * @param Subscription $suscripcion Suscripción.
		 * @param string       $origen      Quién la canceló.
		 */
		do_action( 'swpagos_suscripcion_cancelada', $this->repo->por_id( $suscripcion->id() ), 'administrador' );

		return array(
			'ok'      => true,
			'mensaje' => __( 'Suscripción cancelada.', 'sw-pagos' ),
		);
	}

	/**
	 * Vuelve a leer la suscripción desde Stripe y actualiza lo local.
	 *
	 * @param Subscription $suscripcion Suscripción.
	 * @return bool
	 */
	public function sincronizar( Subscription $suscripcion ): bool {
		$stripe = ( new Client() )->para( $suscripcion->modo() );

		if ( null === $stripe ) {
			return false;
		}

		try {
			$remota = $stripe->subscriptions->retrieve( $suscripcion->stripe_id() );
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->warning(
				'No se pudo sincronizar la suscripción.',
				array(
					'suscripcion' => $suscripcion->uuid(),
					'error'       => $e->getMessage(),
				)
			);

			return false;
		}

		$item = $remota->items->data[0] ?? null;

		$cambios = array(
			'current_period_end'   => isset( $remota->current_period_end )
				? gmdate( 'Y-m-d H:i:s', (int) $remota->current_period_end )
				: null,
			'cancel_at_period_end' => ! empty( $remota->cancel_at_period_end ) ? 1 : 0,
		);

		$importe = (int) ( $item->price->unit_amount ?? 0 );

		if ( $importe > 0 ) {
			$cambios['amount_minor'] = $importe;
		}

		return $this->repo->actualizar( $suscripcion, $cambios, 'sincronizacion_manual' );
	}
}
