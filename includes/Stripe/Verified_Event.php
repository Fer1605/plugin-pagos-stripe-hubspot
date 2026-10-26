<?php
/**
 * Evento de Stripe con la firma ya verificada.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Payment_Proof;

defined( 'ABSPATH' ) || exit;

/**
 * Prueba de que un evento viene realmente de Stripe.
 *
 * No hay forma de construir esta clase sin pasar por la verificación de firma: el
 * constructor es privado y la única fábrica llama a la comprobación del SDK, que
 * lanza excepción si la firma no cuadra. Eso convierte esa regla en algo que
 * el compilador ayuda a sostener, y no en una convención que haya que recordar.
 *
 * Cierra el hallazgo H-02: el receptor del módulo de referencia no verificaba
 * nada y aceptaba cualquier petición.
 */
final class Verified_Event implements Payment_Proof {

	/**
	 * Evento del SDK.
	 *
	 * @var \Stripe\Event
	 */
	private \Stripe\Event $evento;

	/**
	 * Modo con el que se verificó.
	 *
	 * @var string
	 */
	private string $modo;

	/**
	 * Constructor privado: solo se llega aquí tras verificar la firma.
	 *
	 * @param \Stripe\Event $evento Evento.
	 * @param string        $modo   Modo.
	 */
	private function __construct( \Stripe\Event $evento, string $modo ) {
		$this->evento = $evento;
		$this->modo   = $modo;
	}

	/**
	 * Verifica una petición entrante y devuelve el evento, o null.
	 *
	 * Un mismo punto de enlace recibe los eventos de prueba y los de producción,
	 * que llevan secretos de firma distintos. Se prueban los dos y, además, se
	 * comprueba que el modo del evento coincida con el secreto que lo validó: un
	 * evento de producción firmado con el secreto de pruebas sería señal de que
	 * algo está muy mal configurado.
	 *
	 * @param string $cuerpo Cuerpo crudo de la petición.
	 * @param string $firma  Cabecera Stripe-Signature.
	 * @return Verified_Event|null
	 */
	public static function verificar( string $cuerpo, string $firma ): ?Verified_Event {
		if ( ! class_exists( '\\Stripe\\Webhook' ) ) {
			Plugin::instancia()->logger()->error( 'El SDK de Stripe no está instalado; no se puede verificar el evento.' );
			return null;
		}

		if ( '' === $cuerpo || '' === $firma ) {
			return null;
		}

		$settings = Plugin::instancia()->settings();

		foreach ( array( 'live', 'test' ) as $modo ) {
			$secreto = $settings->stripe_webhook_secret( $modo );

			if ( '' === $secreto ) {
				continue;
			}

			try {
				$evento = \Stripe\Webhook::constructEvent( $cuerpo, $firma, $secreto );
			} catch ( \Throwable $e ) {
				// Firma inválida con este secreto: puede ser del otro modo.
				continue;
			}

			$esperado = $evento->livemode ? 'live' : 'test';

			if ( $esperado !== $modo ) {
				Plugin::instancia()->logger()->error(
					'Un evento de Stripe se firmó con el secreto del modo equivocado. Se rechaza.',
					array(
						'evento'         => $evento->id,
						'modo_del_evento' => $esperado,
						'modo_del_secreto' => $modo,
					)
				);

				return null;
			}

			return new self( $evento, $modo );
		}

		return null;
	}

	/**
	 * Identificador del evento.
	 *
	 * @return string
	 */
	public function event_id(): string {
		return (string) $this->evento->id;
	}

	/**
	 * Tipo del evento.
	 *
	 * @return string
	 */
	public function tipo(): string {
		return (string) $this->evento->type;
	}

	/**
	 * Modo del evento.
	 *
	 * @return string
	 */
	public function modo(): string {
		return $this->modo;
	}

	/**
	 * Objeto que acompaña al evento.
	 *
	 * @return object|null
	 */
	public function objeto() {
		return $this->evento->data->object ?? null;
	}

	/**
	 * Evento completo del SDK.
	 *
	 * @return \Stripe\Event
	 */
	public function evento(): \Stripe\Event {
		return $this->evento;
	}

	/**
	 * Representación serializable para la bitácora, sin datos sensibles.
	 *
	 * @return array<string,mixed>
	 */
	public function para_bitacora(): array {
		$objeto = $this->objeto();

		return array(
			'id'       => $this->event_id(),
			'type'     => $this->tipo(),
			'livemode' => $this->evento->livemode,
			'created'  => $this->evento->created,
			'object'   => is_object( $objeto ) && method_exists( $objeto, 'toArray' )
				? $this->podar( $objeto->toArray() )
				: array(),
		);
	}

	/**
	 * Recorta el objeto a lo que sirve para diagnosticar.
	 *
	 * @param array<string,mixed> $datos Datos.
	 * @return array<string,mixed>
	 */
	private function podar( array $datos ): array {
		$interesan = array(
			'id',
			'object',
			'amount',
			'amount_total',
			'amount_paid',
			'amount_refunded',
			'currency',
			'status',
			'payment_status',
			'client_reference_id',
			'customer',
			'payment_intent',
			'subscription',
			'invoice',
			'mode',
			'metadata',
			'cancel_at_period_end',
			'current_period_end',
			'attempt_count',
			'last_payment_error',
		);

		return array_intersect_key( $datos, array_flip( $interesan ) );
	}
}
