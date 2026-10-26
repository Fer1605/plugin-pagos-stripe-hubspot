<?php
/**
 * Eventos de Stripe relativos a suscripciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Traduce el ciclo de vida de una suscripción de Stripe al del módulo.
 *
 * Cada cobro mensual genera **su propia transacción** (decisión D-04). Una sola
 * fila por suscripción haría imposible sumar los ingresos de un período, que es
 * precisamente lo que piden los informes, y perdería qué mes se cobró y cuál no.
 */
final class Subscription_Events {

	/**
	 * Repositorio de suscripciones.
	 *
	 * @var Subscription_Repository
	 */
	private Subscription_Repository $subs;

	/**
	 * Repositorio de transacciones.
	 *
	 * @var Transaction_Repository
	 */
	private Transaction_Repository $txns;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->subs = new Subscription_Repository();
		$this->txns = new Transaction_Repository();
	}

	/**
	 * Engancha los filtros del manejador de eventos.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_filter( 'swpagos_alta_suscripcion', array( $this, 'alta' ), 10, 4 );
		add_filter( 'swpagos_evento_no_atendido', array( $this, 'atender' ), 10, 2 );
	}

	/**
	 * Da de alta la suscripción tras el primer cobro.
	 *
	 * @param int            $resultado   Valor previo del filtro.
	 * @param Transaction    $transaccion Transacción inicial.
	 * @param object         $sesion      Sesión de Stripe.
	 * @param Verified_Event $evento      Evento verificado.
	 * @return int Identificador de la suscripción.
	 */
	public function alta( $resultado, Transaction $transaccion, $sesion, Verified_Event $evento ): int {
		$stripe_id = $this->id_de( $sesion->subscription ?? null );

		if ( '' === $stripe_id ) {
			return 0;
		}

		$remota = $this->recuperar_suscripcion( $stripe_id, $transaccion->modo() );

		$suscripcion = $this->subs->crear(
			array(
				'form_id'                => $transaccion->form_id(),
				'template_id'            => $transaccion->template_id(),
				'mode'                   => $transaccion->modo(),
				'amount_minor'           => $transaccion->importe(),
				'currency'               => $transaccion->moneda(),
				'stripe_subscription_id' => $stripe_id,
				'stripe_customer_id'     => $this->id_de( $sesion->customer ?? null ),
				'stripe_price_id'        => $this->precio_de( $remota ),
				'current_period_end'     => $this->fecha( $remota->current_period_end ?? null ),
				'customer_email'         => $transaccion->email(),
				'customer_name'          => $transaccion->nombre(),
				'locale'                 => (string) $transaccion->valor( 'locale', '' ),
				'utm'                    => $transaccion->utm(),
				'metadata'               => $transaccion->metadata(),
			)
		);

		if ( null === $suscripcion ) {
			return 0;
		}

		// El primer cobro pertenece al período en curso: así la renovación del mes
		// siguiente no choca con él ni lo duplica.
		$this->txns->actualizar(
			$transaccion,
			array(
				'subscription_id' => $suscripcion->id(),
				'billing_period'  => gmdate( 'Y-m' ),
				'kind'            => Transaction::TIPO_ALTA_SUSC,
			),
			'alta_suscripcion'
		);

		Plugin::instancia()->logger()->info(
			'Suscripción dada de alta.',
			array(
				'suscripcion' => $suscripcion->uuid(),
				'stripe'      => $stripe_id,
			)
		);

		/**
		 * Se dispara al activarse una suscripción por primera vez.
		 *
		 * @param Subscription $suscripcion Suscripción.
		 * @param Transaction  $transaccion Transacción del primer cobro.
		 */
		do_action( 'swpagos_suscripcion_activada', $suscripcion, $transaccion );

		return $suscripcion->id();
	}

	/**
	 * Atiende los eventos que el manejador principal no traduce.
	 *
	 * @param mixed          $resultado Valor previo.
	 * @param Verified_Event $evento    Evento.
	 * @return array{transaccion:int,suscripcion:int}|null
	 */
	public function atender( $resultado, Verified_Event $evento ) {
		switch ( $evento->tipo() ) {
			case 'invoice.paid':
				return $this->factura_pagada( $evento );

			case 'invoice.payment_failed':
				return $this->cobro_fallido( $evento );

			case 'customer.subscription.updated':
				return $this->actualizada( $evento );

			case 'customer.subscription.deleted':
				return $this->eliminada( $evento );
		}

		return $resultado;
	}

	/**
	 * Renovación cobrada.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return array{transaccion:int,suscripcion:int}
	 */
	private function factura_pagada( Verified_Event $evento ): array {
		$factura = $evento->objeto();
		$vacio   = array(
			'transaccion' => 0,
			'suscripcion' => 0,
		);

		$stripe_id = $this->id_de( $factura->subscription ?? null );

		if ( '' === $stripe_id ) {
			return $vacio;
		}

		$suscripcion = $this->subs->por_stripe_id( $stripe_id );

		if ( null === $suscripcion ) {
			return $vacio;
		}

		$vacio['suscripcion'] = $suscripcion->id();

		// El primer cobro ya lo registró la sesión de pago. Si lo tratáramos aquí
		// otra vez, el mes de alta aparecería cobrado dos veces en los informes.
		if ( 'subscription_create' === ( $factura->billing_reason ?? '' ) ) {
			$this->sincronizar_periodo( $suscripcion, $factura );
			return $vacio;
		}

		$periodo  = $this->periodo( $factura );
		$anterior = $this->txns->por_periodo( $suscripcion->id(), $periodo );

		if ( null !== $anterior ) {
			// Ya existe el cobro de este período: evento repetido o solapado.
			$vacio['transaccion'] = $anterior->id();
			return $vacio;
		}

		$transaccion = $this->txns->crear(
			array(
				'form_id'         => $suscripcion->form_id(),
				'template_id'     => $suscripcion->template_id(),
				'subscription_id' => $suscripcion->id(),
				'billing_period'  => $periodo,
				'mode'            => $suscripcion->modo(),
				'kind'            => Transaction::TIPO_RENOVACION,
				'amount_minor'    => (int) ( $factura->amount_paid ?? $suscripcion->importe() ),
				'currency'        => strtoupper( (string) ( $factura->currency ?? $suscripcion->moneda() ) ),
				'idempotency_key' => 'inv_' . (string) ( $factura->id ?? wp_generate_uuid4() ),
				'customer_email'  => $suscripcion->email(),
				'customer_name'   => $suscripcion->nombre(),
				'locale'          => (string) $suscripcion->valor( 'locale', '' ),
				'utm'             => $suscripcion->metadata(),
				'metadata'        => $suscripcion->metadata(),
				'origen'          => 'renovacion',
			)
		);

		if ( null === $transaccion ) {
			return $vacio;
		}

		$this->txns->transicionar(
			$transaccion,
			Transaction::PENDIENTE,
			array( 'stripe_invoice_id' => (string) ( $factura->id ?? '' ) )
		);

		$transaccion = $this->txns->por_id( $transaccion->id() );

		$this->txns->transicionar(
			$transaccion,
			Transaction::PAGADA,
			array(
				'stripe_payment_intent_id' => $this->id_de( $factura->payment_intent ?? null ),
				'stripe_customer_id'       => $this->id_de( $factura->customer ?? null ),
			),
			$evento
		);

		$transaccion = $this->txns->por_id( $transaccion->id() );
		$vacio['transaccion'] = $transaccion->id();

		// Si venía de un impago, el cobro conseguido la devuelve a la vida.
		if ( Subscription::IMPAGO === $suscripcion->estado() ) {
			$this->subs->transicionar(
				$suscripcion,
				Subscription::ACTIVA,
				array( 'failed_attempts' => 0 ),
				'cobro_recuperado'
			);

			/**
			 * Se dispara cuando una suscripción en impago vuelve a cobrarse.
			 *
			 * @param Subscription $suscripcion Suscripción.
			 */
			do_action( 'swpagos_suscripcion_recuperada', $this->subs->por_id( $suscripcion->id() ) );
		}

		$this->sincronizar_periodo( $this->subs->por_id( $suscripcion->id() ), $factura );

		/**
		 * Se dispara en cada renovación cobrada.
		 *
		 * @param Transaction    $transaccion Transacción del período.
		 * @param Subscription   $suscripcion Suscripción.
		 * @param Verified_Event $evento      Evento.
		 */
		do_action( 'swpagos_renovacion_cobrada', $transaccion, $this->subs->por_id( $suscripcion->id() ), $evento );

		// El cobro confirmado dispara correo y CRM como cualquier otro.
		do_action( 'swpagos_cobro_confirmado', $transaccion, $evento );

		return $vacio;
	}

	/**
	 * Cobro mensual fallido.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return array{transaccion:int,suscripcion:int}
	 */
	private function cobro_fallido( Verified_Event $evento ): array {
		$factura = $evento->objeto();
		$salida  = array(
			'transaccion' => 0,
			'suscripcion' => 0,
		);

		$stripe_id = $this->id_de( $factura->subscription ?? null );

		if ( '' === $stripe_id ) {
			return $salida;
		}

		$suscripcion = $this->subs->por_stripe_id( $stripe_id );

		if ( null === $suscripcion ) {
			return $salida;
		}

		$salida['suscripcion'] = $suscripcion->id();
		$intentos              = (int) ( $factura->attempt_count ?? ( $suscripcion->fallos() + 1 ) );

		if ( Subscription::IMPAGO !== $suscripcion->estado() ) {
			$this->subs->transicionar(
				$suscripcion,
				Subscription::IMPAGO,
				array( 'failed_attempts' => $intentos ),
				'cobro_fallido'
			);
		} else {
			$this->subs->actualizar( $suscripcion, array( 'failed_attempts' => $intentos ), 'cobro_fallido' );
		}

		$actualizada = $this->subs->por_id( $suscripcion->id() );

		Plugin::instancia()->logger()->warning(
			'Cobro mensual fallido.',
			array(
				'suscripcion' => $suscripcion->uuid(),
				'intento'     => $intentos,
			)
		);

		/**
		 * Se dispara cuando falla un cobro mensual.
		 *
		 * Aquí cuelgan el aviso a quien paga, con el enlace para actualizar su
		 * medio de pago, y el aviso al equipo. **No** se crea negocio en el CRM:
		 * no hubo cobro.
		 *
		 * @param Subscription $suscripcion Suscripción.
		 * @param int          $intentos    Intentos acumulados.
		 */
		do_action( 'swpagos_cobro_mensual_fallido', $actualizada, $intentos );

		return $salida;
	}

	/**
	 * La suscripción cambió en Stripe.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return array{transaccion:int,suscripcion:int}
	 */
	private function actualizada( Verified_Event $evento ): array {
		$remota = $evento->objeto();
		$salida = array(
			'transaccion' => 0,
			'suscripcion' => 0,
		);

		$suscripcion = $this->subs->por_stripe_id( $this->id_de( $remota ) );

		if ( null === $suscripcion ) {
			return $salida;
		}

		$salida['suscripcion'] = $suscripcion->id();

		$cambios = array(
			'current_period_end'   => $this->fecha( $remota->current_period_end ?? null ),
			'cancel_at_period_end' => ! empty( $remota->cancel_at_period_end ) ? 1 : 0,
		);

		$importe = $this->importe_de( $remota );

		if ( $importe > 0 ) {
			$cambios['amount_minor'] = $importe;
		}

		$estado = $this->traducir_estado( (string) ( $remota->status ?? '' ) );

		if ( '' !== $estado && $estado !== $suscripcion->estado() ) {
			$this->subs->transicionar( $suscripcion, $estado, $cambios, 'sincronizacion' );
		} else {
			$this->subs->actualizar( $suscripcion, $cambios, 'sincronizacion' );
		}

		return $salida;
	}

	/**
	 * La suscripción terminó en Stripe.
	 *
	 * @param Verified_Event $evento Evento.
	 * @return array{transaccion:int,suscripcion:int}
	 */
	private function eliminada( Verified_Event $evento ): array {
		$salida      = array(
			'transaccion' => 0,
			'suscripcion' => 0,
		);
		$suscripcion = $this->subs->por_stripe_id( $this->id_de( $evento->objeto() ) );

		if ( null === $suscripcion ) {
			return $salida;
		}

		$salida['suscripcion'] = $suscripcion->id();

		if ( ! $suscripcion->esta_viva() ) {
			return $salida;
		}

		$this->subs->transicionar( $suscripcion, Subscription::CANCELADA, array(), 'cancelada_en_pasarela' );

		$actualizada = $this->subs->por_id( $suscripcion->id() );

		/**
		 * Se dispara al cancelarse una suscripción, la cancele quien la cancele.
		 *
		 * @param Subscription $suscripcion Suscripción.
		 * @param string       $origen      Quién la canceló.
		 */
		do_action( 'swpagos_suscripcion_cancelada', $actualizada, 'pasarela' );

		return $salida;
	}

	/**
	 * Guarda la fecha del próximo cobro que viene en la factura.
	 *
	 * @param Subscription|null $suscripcion Suscripción.
	 * @param object            $factura     Factura.
	 * @return void
	 */
	private function sincronizar_periodo( ?Subscription $suscripcion, $factura ): void {
		if ( null === $suscripcion ) {
			return;
		}

		$linea = $factura->lines->data[0] ?? null;
		$fin   = $linea->period->end ?? null;

		if ( null === $fin ) {
			return;
		}

		$this->subs->actualizar( $suscripcion, array( 'current_period_end' => $this->fecha( $fin ) ), 'sincronizacion' );
	}

	/**
	 * Período de facturación de una factura, en formato AAAA-MM.
	 *
	 * @param object $factura Factura.
	 * @return string
	 */
	private function periodo( $factura ): string {
		$linea   = $factura->lines->data[0] ?? null;
		$inicio  = $linea->period->start ?? ( $factura->period_start ?? null );
		$momento = is_numeric( $inicio ) ? (int) $inicio : time();

		return gmdate( 'Y-m', $momento );
	}

	/**
	 * Traduce el estado de Stripe al del módulo.
	 *
	 * @param string $estado Estado de Stripe.
	 * @return string Cadena vacía si no hay equivalencia clara.
	 */
	private function traducir_estado( string $estado ): string {
		$mapa = array(
			'active'             => Subscription::ACTIVA,
			'trialing'           => Subscription::ACTIVA,
			'past_due'           => Subscription::IMPAGO,
			'unpaid'             => Subscription::IMPAGO,
			'incomplete'         => Subscription::IMPAGO,
			'paused'             => Subscription::PAUSADA,
			'canceled'           => Subscription::CANCELADA,
			'incomplete_expired' => Subscription::CANCELADA,
		);

		return $mapa[ $estado ] ?? '';
	}

	/**
	 * Recupera la suscripción desde Stripe.
	 *
	 * @param string $stripe_id Identificador.
	 * @param string $modo      Modo.
	 * @return object|null
	 */
	private function recuperar_suscripcion( string $stripe_id, string $modo ) {
		$stripe = ( new Client() )->para( $modo );

		if ( null === $stripe ) {
			return null;
		}

		try {
			return $stripe->subscriptions->retrieve( $stripe_id );
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->warning(
				'No se pudo recuperar la suscripción desde Stripe.',
				array(
					'stripe' => $stripe_id,
					'error'  => $e->getMessage(),
				)
			);

			return null;
		}
	}

	/**
	 * Precio asociado a una suscripción remota.
	 *
	 * @param object|null $remota Suscripción de Stripe.
	 * @return string
	 */
	private function precio_de( $remota ): string {
		$item = $remota->items->data[0] ?? null;

		return $this->id_de( $item->price ?? null );
	}

	/**
	 * Importe de una suscripción remota, en unidad mínima.
	 *
	 * @param object|null $remota Suscripción de Stripe.
	 * @return int
	 */
	private function importe_de( $remota ): int {
		$item = $remota->items->data[0] ?? null;

		return (int) ( $item->price->unit_amount ?? 0 );
	}

	/**
	 * Convierte una marca de tiempo de Stripe a fecha de base de datos.
	 *
	 * @param mixed $marca Marca de tiempo.
	 * @return string|null
	 */
	private function fecha( $marca ): ?string {
		return is_numeric( $marca ) ? gmdate( 'Y-m-d H:i:s', (int) $marca ) : null;
	}

	/**
	 * Identificador de un objeto que puede venir expandido.
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
