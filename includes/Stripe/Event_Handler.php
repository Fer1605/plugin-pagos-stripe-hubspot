<?php
/**
 * Traducción de eventos de Stripe a cambios de estado.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Payment_Proof;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Único componente autorizado a confirmar un cobro.
 *
 * Recibe eventos ya verificados y los convierte en transiciones. Nada de lo que
 * ocurre aquí depende de que la persona haya vuelto al sitio: si cierra la
 * pestaña, el cobro se confirma igual.
 */
final class Event_Handler {

	/**
	 * Repositorio de transacciones.
	 *
	 * @var Transaction_Repository
	 */
	private Transaction_Repository $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo = new Transaction_Repository();
	}

	/**
	 * Eventos que este módulo atiende.
	 *
	 * @return string[]
	 */
	public static function eventos_atendidos(): array {
		return array(
			'checkout.session.completed',
			'checkout.session.async_payment_succeeded',
			'checkout.session.async_payment_failed',
			'checkout.session.expired',
			'payment_intent.payment_failed',
			'charge.refunded',
			'invoice.paid',
			'invoice.payment_failed',
			'customer.subscription.updated',
			'customer.subscription.deleted',
		);
	}

	/**
	 * Procesa un evento verificado.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return array{transaccion:int,suscripcion:int}
	 */
	public function procesar( Verified_Event $evento ): array {
		$resultado = array(
			'transaccion' => 0,
			'suscripcion' => 0,
		);

		switch ( $evento->tipo() ) {
			case 'checkout.session.completed':
				$resultado = $this->sesion_completada( $evento );
				break;

			case 'checkout.session.async_payment_succeeded':
				$resultado['transaccion'] = $this->confirmar_por_sesion( $evento );
				break;

			case 'checkout.session.async_payment_failed':
				$resultado['transaccion'] = $this->fallar_por_sesion( $evento, 'pago_asincrono_fallido' );
				break;

			case 'checkout.session.expired':
				$resultado['transaccion'] = $this->cancelar_por_sesion( $evento );
				break;

			case 'payment_intent.payment_failed':
				$resultado['transaccion'] = $this->fallar_por_intencion( $evento );
				break;

			case 'charge.refunded':
				$resultado['transaccion'] = $this->reembolsar( $evento );
				break;

			default:
				/**
				 * Permite atender eventos que este módulo todavía no traduce.
				 *
				 * Las suscripciones se enganchan aquí.
				 *
				 * @param Verified_Event $evento Evento verificado.
				 */
				$extra = apply_filters( 'swpagos_evento_no_atendido', null, $evento );

				if ( is_array( $extra ) ) {
					$resultado = array_merge( $resultado, $extra );
				}
		}

		return $resultado;
	}

	/**
	 * Sesión de pago completada.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return array{transaccion:int,suscripcion:int}
	 */
	private function sesion_completada( Verified_Event $evento ): array {
		$sesion      = $evento->objeto();
		$transaccion = $this->transaccion_de_sesion( $sesion );
		$salida      = array(
			'transaccion' => 0,
			'suscripcion' => 0,
		);

		if ( null === $transaccion ) {
			return $salida;
		}

		$salida['transaccion'] = $transaccion->id();

		$cambios = array_filter(
			array(
				'stripe_payment_intent_id' => $this->id_de( $sesion->payment_intent ?? null ),
				'stripe_customer_id'       => $this->id_de( $sesion->customer ?? null ),
			)
		);

		if ( 'subscription' === ( $sesion->mode ?? '' ) ) {
			/**
			 * Alta de una suscripción tras el primer cobro.
			 *
			 * @param Transaction    $transaccion Transacción inicial.
			 * @param object         $sesion      Sesión de Stripe.
			 * @param Verified_Event $evento      Evento verificado.
			 */
			$suscripcion = apply_filters( 'swpagos_alta_suscripcion', 0, $transaccion, $sesion, $evento );
			$salida['suscripcion'] = (int) $suscripcion;

			if ( $salida['suscripcion'] > 0 ) {
				$cambios['subscription_id'] = $salida['suscripcion'];
			}
		}

		$pagado = 'paid' === ( $sesion->payment_status ?? '' );

		if ( $pagado ) {
			$this->confirmar( $transaccion, $cambios, $evento );
		} else {
			// Pago asíncrono: los fondos aún no están. El correo y el CRM esperan.
			$this->repo->transicionar( $transaccion, Transaction::PROCESANDO, $cambios );
		}

		return $salida;
	}

	/**
	 * Confirma el cobro de una sesión.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return int Identificador de la transacción.
	 */
	private function confirmar_por_sesion( Verified_Event $evento ): int {
		$transaccion = $this->transaccion_de_sesion( $evento->objeto() );

		if ( null === $transaccion ) {
			return 0;
		}

		$this->confirmar( $transaccion, array(), $evento );

		return $transaccion->id();
	}

	/**
	 * Marca como fallida la transacción de una sesión.
	 *
	 * @param Verified_Event $evento Evento.
	 * @param string         $codigo Código del fallo.
	 * @return int
	 */
	private function fallar_por_sesion( Verified_Event $evento, string $codigo ): int {
		$transaccion = $this->transaccion_de_sesion( $evento->objeto() );

		if ( null === $transaccion ) {
			return 0;
		}

		$this->repo->transicionar(
			$transaccion,
			Transaction::FALLIDA,
			array( 'failure_code' => $codigo ),
			$evento
		);

		return $transaccion->id();
	}

	/**
	 * Cancela la transacción de una sesión expirada.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return int
	 */
	private function cancelar_por_sesion( Verified_Event $evento ): int {
		$transaccion = $this->transaccion_de_sesion( $evento->objeto() );

		if ( null === $transaccion ) {
			return 0;
		}

		$this->repo->transicionar(
			$transaccion,
			Transaction::CANCELADA,
			array( 'failure_code' => 'sesion_expirada' ),
			$evento
		);

		return $transaccion->id();
	}

	/**
	 * Marca como fallida la transacción de una intención de pago.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return int
	 */
	private function fallar_por_intencion( Verified_Event $evento ): int {
		$intencion = $evento->objeto();
		$id        = $this->id_de( $intencion );

		if ( '' === $id ) {
			return 0;
		}

		$transaccion = $this->repo->por_payment_intent( $id );

		if ( null === $transaccion ) {
			$transaccion = $this->por_metadato( $intencion );
		}

		if ( null === $transaccion ) {
			return 0;
		}

		$error = $intencion->last_payment_error ?? null;

		$this->repo->transicionar(
			$transaccion,
			Transaction::FALLIDA,
			array(
				'failure_code'    => (string) ( $error->code ?? 'pago_rechazado' ),
				'failure_message' => mb_substr( (string) ( $error->message ?? '' ), 0, 500 ),
			),
			$evento
		);

		return $transaccion->id();
	}

	/**
	 * Registra un reembolso.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return int
	 */
	private function reembolsar( Verified_Event $evento ): int {
		$cargo     = $evento->objeto();
		$intencion = $this->id_de( $cargo->payment_intent ?? null );

		$transaccion = '' !== $intencion ? $this->repo->por_payment_intent( $intencion ) : null;

		if ( null === $transaccion ) {
			$transaccion = $this->por_metadato( $cargo );
		}

		if ( null === $transaccion ) {
			return 0;
		}

		$devuelto = (int) ( $cargo->amount_refunded ?? 0 );
		$total    = (int) ( $cargo->amount ?? 0 );

		$cambios = array(
			'amount_refunded_minor' => $devuelto,
			'stripe_charge_id'      => $this->id_de( $cargo ),
		);

		// Un reembolso parcial no es el final del cobro: la transacción sigue
		// pagada y solo cambia el importe devuelto.
		if ( $devuelto > 0 && $devuelto >= $total ) {
			$this->repo->transicionar( $transaccion, Transaction::REEMBOLSADA, $cambios, $evento );
		} else {
			$this->repo->actualizar( $transaccion, $cambios, 'reembolso_parcial' );
		}

		/**
		 * Permite reflejar el reembolso fuera del módulo, por ejemplo en el CRM.
		 *
		 * @param Transaction $transaccion Transacción.
		 * @param int         $devuelto    Importe devuelto en unidad mínima.
		 */
		do_action( 'swpagos_transaccion_reembolsada', $this->repo->por_id( $transaccion->id() ), $devuelto );

		return $transaccion->id();
	}

	/**
	 * Confirma un cobro y avisa al resto del módulo.
	 *
	 * @param Transaction         $transaccion Transacción.
	 * @param array<string,mixed> $cambios     Columnas adicionales.
	 * @param Payment_Proof       $evento      Prueba del cobro.
	 * @return void
	 */
	private function confirmar( Transaction $transaccion, array $cambios, Payment_Proof $evento ): void {
		if ( $transaccion->esta_pagada() ) {
			return;
		}

		$ok = $this->repo->transicionar( $transaccion, Transaction::PAGADA, $cambios, $evento );

		if ( ! $ok ) {
			return;
		}

		$actualizada = $this->repo->por_id( $transaccion->id() );

		Plugin::instancia()->logger()->info(
			'Cobro confirmado.',
			array(
				'transaccion' => $transaccion->uuid(),
				'evento'      => $evento->event_id(),
			)
		);

		/**
		 * Se dispara una sola vez por transacción, al confirmarse el cobro.
		 *
		 * Es el punto del que cuelgan el correo y HubSpot. Que sea una acción y no
		 * una llamada directa permite que el trabajo se haga en segundo plano sin
		 * que el receptor de eventos tenga que esperarlo.
		 *
		 * @param Transaction    $transaccion Transacción confirmada.
		 * @param Verified_Event $evento      Evento que la confirmó.
		 */
		do_action( 'swpagos_cobro_confirmado', $actualizada, $evento );
	}

	/**
	 * Confirma un cobro a partir de una reconciliación contra la API.
	 *
	 * Es la misma confirmación que la del webhook, con otra prueba igual de
	 * fiable. Existe para que un punto de enlace mal configurado no deje cobros
	 * sin confirmar indefinidamente.
	 *
	 * @param Transaction   $transaccion Transacción.
	 * @param Payment_Proof $prueba      Prueba obtenida de la API.
	 * @param array<string,mixed> $cambios Columnas adicionales.
	 * @return void
	 */
	public function confirmar_reconciliado( Transaction $transaccion, Payment_Proof $prueba, array $cambios = array() ): void {
		Plugin::instancia()->logger()->warning(
			'Cobro confirmado por reconciliación contra la API, no por webhook. Revisa que el punto de enlace de eventos esté bien dado de alta en Stripe.',
			array( 'transaccion' => $transaccion->uuid() )
		);

		$this->confirmar( $transaccion, $cambios, $prueba );
	}

	/**
	 * Localiza la transacción de una sesión de pago.
	 *
	 * @param object|null $sesion Sesión.
	 * @return Transaction|null
	 */
	private function transaccion_de_sesion( $sesion ): ?Transaction {
		if ( ! is_object( $sesion ) ) {
			return null;
		}

		$referencia = (string) ( $sesion->client_reference_id ?? '' );

		if ( '' !== $referencia ) {
			$transaccion = $this->repo->por_uuid( $referencia );

			if ( null !== $transaccion ) {
				return $transaccion;
			}
		}

		$id = $this->id_de( $sesion );

		if ( '' !== $id ) {
			$transaccion = $this->repo->por_session( $id );

			if ( null !== $transaccion ) {
				return $transaccion;
			}
		}

		return $this->por_metadato( $sesion );
	}

	/**
	 * Localiza la transacción por el metadato que viaja con el objeto.
	 *
	 * @param object|null $objeto Objeto de Stripe.
	 * @return Transaction|null
	 */
	private function por_metadato( $objeto ): ?Transaction {
		if ( ! is_object( $objeto ) ) {
			return null;
		}

		$uuid = (string) ( $objeto->metadata->swpagos_uuid ?? '' );

		return '' !== $uuid ? $this->repo->por_uuid( $uuid ) : null;
	}

	/**
	 * Identificador de un objeto de Stripe que puede venir expandido o no.
	 *
	 * @param mixed $valor Valor.
	 * @return string
	 */
	private function id_de( $valor ): string {
		if ( is_string( $valor ) ) {
			return $valor;
		}

		if ( is_object( $valor ) && isset( $valor->id ) ) {
			return (string) $valor->id;
		}

		return '';
	}
}
