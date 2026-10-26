<?php
/**
 * Base de los shortcodes de resultado.
 *
 * @package SWPagos
 */

namespace SWPagos\Frontend;

use SWPagos\Delivery\Placeholder_Renderer;
use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Stripe\Billing_Portal;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Resolución común de la transacción y de sus marcadores.
 */
abstract class Shortcode_Base {

	/**
	 * Etiqueta del shortcode.
	 *
	 * @return string
	 */
	abstract protected function etiqueta(): string;

	/**
	 * Pinta el contenido.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @param Plantilla|null   $plantilla   Plantilla.
	 * @return string
	 */
	abstract protected function contenido( ?Transaction $transaccion, ?Plantilla $plantilla ): string;

	/**
	 * Registra el shortcode.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_shortcode( $this->etiqueta(), array( $this, 'render' ) );
	}

	/**
	 * Renderiza.
	 *
	 * @param array<string,mixed>|string $atributos Atributos.
	 * @return string
	 */
	public function render( $atributos = array() ): string {
		// Estas páginas muestran el resultado de un cobro concreto: cachearlas
		// serviría a una persona la página de otra.
		nocache_headers();

		$transaccion = $this->transaccion();
		$plantilla   = null !== $transaccion ? Plantilla::por_id( $transaccion->template_id() ) : null;

		return '<style>' . Result_View::css() . '</style>'
			. '<div class="swpagos-resultado">' . $this->contenido( $transaccion, $plantilla ) . '</div>';
	}

	/**
	 * Transacción a la que se refiere la página.
	 *
	 * @return Transaction|null
	 */
	protected function transaccion(): ?Transaction {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uuid = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

		if ( '' === $uuid ) {
			return null;
		}

		return ( new Transaction_Repository() )->por_uuid( $uuid );
	}

	/**
	 * Suscripción asociada, si la hay.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @return Subscription|null
	 */
	protected function suscripcion( ?Transaction $transaccion ): ?Subscription {
		if ( null === $transaccion || $transaccion->subscription_id() <= 0 ) {
			return null;
		}

		return ( new Subscription_Repository() )->por_id( $transaccion->subscription_id() );
	}

	/**
	 * Marcadores disponibles en los textos de la plantilla.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @return Placeholder_Renderer
	 */
	protected function marcadores( ?Transaction $transaccion ): Placeholder_Renderer {
		$valores = null !== $transaccion ? $transaccion->metadata() : array();
		unset( $valores['_hubspot'] );

		$render = new Placeholder_Renderer( $valores );

		if ( null === $transaccion ) {
			return $render;
		}

		$render->con( 'nombre', $transaccion->nombre() );
		$render->con( 'email', $transaccion->email() );
		$render->con( 'importe', $transaccion->importe_legible() );
		$render->con( 'moneda', $transaccion->moneda() );
		$render->con( 'referencia', $transaccion->uuid() );
		$render->con( 'fecha', wp_date( get_option( 'date_format' ) ) );

		$motivo = (string) $transaccion->valor( 'failure_message', '' );
		$render->con( 'motivo', '' !== $motivo ? $motivo : $this->motivo_legible( $transaccion ) );

		$suscripcion = $this->suscripcion( $transaccion );

		if ( null !== $suscripcion ) {
			$render->con( 'importe_mensual', $suscripcion->importe_legible() );
			$render->con( 'enlace_actualizar_pago', ( new Billing_Portal() )->url_para( $suscripcion ) );

			$proximo = $suscripcion->proximo_cobro();
			$marca   = '' !== $proximo ? strtotime( $proximo ) : false;
			$render->con( 'proximo_cobro', false !== $marca ? wp_date( get_option( 'date_format' ), $marca ) : '' );
		}

		return $render;
	}

	/**
	 * Motivo legible de un fallo.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return string
	 */
	protected function motivo_legible( Transaction $transaccion ): string {
		$motivos = array(
			'cancelado_por_usuario' => __( 'El pago se canceló antes de completarse.', 'sw-pagos' ),
			'sesion_expirada'       => __( 'La sesión de pago caducó.', 'sw-pagos' ),
			'card_declined'         => __( 'El banco rechazó la tarjeta.', 'sw-pagos' ),
			'insufficient_funds'    => __( 'La tarjeta no tenía fondos suficientes.', 'sw-pagos' ),
			'expired_card'          => __( 'La tarjeta está caducada.', 'sw-pagos' ),
			'incorrect_cvc'         => __( 'El código de seguridad no era correcto.', 'sw-pagos' ),
			'error_pasarela'        => __( 'Hubo un problema técnico al iniciar el cobro.', 'sw-pagos' ),
		);

		$codigo = (string) $transaccion->valor( 'failure_code', '' );

		return $motivos[ $codigo ] ?? __( 'El pago no se pudo completar.', 'sw-pagos' );
	}

	/**
	 * Mensaje sencillo cuando no hay nada mejor que mostrar.
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	protected function aviso( string $texto, string $estado = Result_View::PENDIENTE, string $titulo = '' ): string {
		return Result_View::tarjeta(
			$estado,
			$titulo,
			'<p>' . esc_html( $texto ) . '</p>',
			array(),
			$this->volver_al_inicio()
		);
	}

	/**
	 * Botón de salida. Nadie debe quedarse encerrado en esta página.
	 *
	 * @return array{texto:string,url:string}
	 */
	protected function volver_al_inicio(): array {
		return array(
			'texto' => __( 'Volver al inicio', 'sw-pagos' ),
			'url'   => home_url( '/' ),
		);
	}

	/**
	 * Detalles del cobro que la persona puede necesitar más tarde.
	 *
	 * @param Transaction       $transaccion Transacción.
	 * @param Subscription|null $suscripcion Suscripción.
	 * @return array<string,string>
	 */
	protected function detalles( Transaction $transaccion, ?Subscription $suscripcion = null ): array {
		$detalles = array();

		if ( null !== $suscripcion ) {
			$detalles[ __( 'Aporte mensual', 'sw-pagos' ) ] = $suscripcion->importe_legible();

			$proximo = $suscripcion->proximo_cobro();
			$marca   = '' !== $proximo ? strtotime( $proximo ) : false;

			if ( false !== $marca ) {
				$detalles[ __( 'Próximo cobro', 'sw-pagos' ) ] = wp_date( get_option( 'date_format' ), $marca );
			}
		} else {
			$detalles[ __( 'Importe', 'sw-pagos' ) ] = $transaccion->importe_legible();
		}

		$pagado = $transaccion->valor( 'paid_at', '' );
		$marca  = '' !== $pagado ? strtotime( (string) $pagado ) : false;

		if ( false !== $marca ) {
			$detalles[ __( 'Fecha', 'sw-pagos' ) ] = wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				$marca
			);
		}

		$detalles[ __( 'Referencia', 'sw-pagos' ) ] = $transaccion->uuid();

		return $detalles;
	}

	/**
	 * Aplica los filtros de contenido a un texto de la plantilla.
	 *
	 * @param string               $texto  Texto.
	 * @param Placeholder_Renderer $render Marcadores.
	 * @return string
	 */
	protected function formatear( string $texto, Placeholder_Renderer $render ): string {
		return wpautop( $render->render( $texto ) );
	}
}
