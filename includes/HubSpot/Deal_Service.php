<?php
/**
 * Negocios del CRM de HubSpot.
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

use SWPagos\Core\Currency;
use SWPagos\Core\Plugin;
use SWPagos\Domain\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Crea un negocio por cada cobro confirmado y lo asocia a su contacto.
 *
 * Un negocio por cobro, no uno por suscripción (decisión D-06): los informes de
 * ingresos de HubSpot suman negocios cerrados, así que con uno solo un donante de
 * dos años aparecería como un único ingreso del primer mes.
 */
final class Deal_Service {

	/**
	 * Cliente del CRM.
	 *
	 * @var Crm_Client
	 */
	private Crm_Client $cliente;

	/**
	 * Constructor.
	 *
	 * @param Crm_Client|null $cliente Cliente.
	 */
	public function __construct( ?Crm_Client $cliente = null ) {
		$this->cliente = $cliente ?? new Crm_Client();
	}

	/**
	 * Crea el negocio de una transacción.
	 *
	 * @param Transaction $transaccion Transacción pagada.
	 * @param string      $contacto_id Contacto al que asociarlo.
	 * @param string      $suscripcion Identificador público de la suscripción, si la hay.
	 * @return array{ok:bool,id:string,mensaje:string,reintentable:bool}
	 */
	public function crear( Transaction $transaccion, string $contacto_id, string $suscripcion = '' ): array {
		$propiedades = $this->propiedades( $transaccion, $suscripcion );

		$resultado = $this->escribir( $propiedades );

		if ( ! $resultado['ok'] ) {
			return $resultado;
		}

		if ( '' !== $contacto_id && '' !== $resultado['id'] ) {
			$this->asociar( $resultado['id'], $contacto_id );
		}

		return $resultado;
	}

	/**
	 * Propiedades del negocio.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @param string      $suscripcion Identificador público de la suscripción.
	 * @return array<string,string>
	 */
	private function propiedades( Transaction $transaccion, string $suscripcion = '' ): array {
		$nombre = $this->nombre( $transaccion );

		$propiedades = array(
			'dealname'           => $nombre,
			// HubSpot espera el importe en unidad mayor, no en centavos.
			'amount'             => Currency::a_unidad_mayor( $transaccion->importe(), $transaccion->moneda() ),
			'deal_currency_code' => $transaccion->moneda(),
			'closedate'          => $this->fecha_cierre( $transaccion ),
		);

		// El embudo y la etapa son del portal, no del formulario: se configuran
		// una vez en «Ajustes de HubSpot». Vacíos, manda el portal.
		$embudo = Crm_Settings::pipeline();
		$etapa  = Crm_Settings::etapa();

		if ( '' !== $embudo ) {
			$propiedades['pipeline'] = $embudo;
		}

		if ( '' !== $etapa ) {
			$propiedades['dealstage'] = $etapa;
		}

		// Trazabilidad. Son propiedades personalizadas: si el portal no las tiene,
		// se reintenta sin ellas en lugar de perder el negocio.
		$propiedades['swpagos_transaction_uuid']  = $transaccion->uuid();
		$propiedades['swpagos_stripe_reference']  = (string) $transaccion->valor( 'stripe_payment_intent_id', '' );
		$propiedades['swpagos_mode']              = $transaccion->modo();

		$periodo = (string) $transaccion->valor( 'billing_period', '' );

		if ( '' !== $periodo ) {
			$propiedades['swpagos_billing_period'] = $periodo;
		}

		if ( '' !== $suscripcion ) {
			$propiedades['swpagos_subscription_uuid'] = $suscripcion;
		}

		return $propiedades;
	}

	/**
	 * Nombre del negocio.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return string
	 */
	private function nombre( Transaction $transaccion ): string {
		$patron = Crm_Settings::patron_nombre();

		if ( '' === trim( $patron ) ) {
			$patron = get_bloginfo( 'name' );
		}

		$render = new \SWPagos\Delivery\Placeholder_Renderer( $transaccion->metadata() );
		$render->con( 'nombre', $transaccion->nombre() );
		$render->con( 'email', $transaccion->email() );
		$render->con( 'importe', $transaccion->importe_legible() );

		$nombre = trim( $render->render_plano( $patron ) );

		$periodo = (string) $transaccion->valor( 'billing_period', '' );

		if ( '' !== $periodo && Transaction::TIPO_RENOVACION === $transaccion->tipo() ) {
			$nombre .= ' · ' . $periodo;
		}

		return '' !== $nombre ? mb_substr( $nombre, 0, 250 ) : get_bloginfo( 'name' );
	}

	/**
	 * Fecha de cierre, en milisegundos como la espera HubSpot.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return string
	 */
	private function fecha_cierre( Transaction $transaccion ): string {
		$pagada = (string) $transaccion->valor( 'paid_at', '' );
		$marca  = '' !== $pagada ? strtotime( $pagada ) : time();

		return (string) ( ( $marca ?: time() ) * 1000 );
	}

	/**
	 * Escribe el negocio, quitando las propiedades que el portal no conozca.
	 *
	 * @param array<string,string> $propiedades Propiedades.
	 * @return array{ok:bool,id:string,mensaje:string,reintentable:bool}
	 */
	private function escribir( array $propiedades ): array {
		$intentos = 0;

		while ( $intentos < 6 ) {
			++$intentos;

			$resultado = $this->cliente->peticion( 'POST', '/crm/v3/objects/deals', array( 'properties' => $propiedades ) );

			if ( $resultado['ok'] ) {
				return array(
					'ok'           => true,
					'id'           => (string) ( $resultado['datos']['id'] ?? '' ),
					'mensaje'      => '',
					'reintentable' => false,
				);
			}

			$desconocida = $this->cliente->propiedad_desconocida( $resultado );

			if ( '' === $desconocida || ! isset( $propiedades[ $desconocida ] ) || 'dealname' === $desconocida ) {
				return array(
					'ok'           => false,
					'id'           => '',
					'mensaje'      => $resultado['mensaje'],
					'reintentable' => $resultado['reintentable'],
				);
			}

			Plugin::instancia()->logger()->warning(
				'HubSpot no reconoce una propiedad de negocio; se reintenta sin ella. Conviene crearla en el portal.',
				array( 'propiedad' => $desconocida )
			);

			unset( $propiedades[ $desconocida ] );
		}

		return array(
			'ok'           => false,
			'id'           => '',
			'mensaje'      => __( 'HubSpot siguió rechazando el negocio tras quitar las propiedades desconocidas.', 'sw-pagos' ),
			'reintentable' => false,
		);
	}

	/**
	 * Asocia el negocio a su contacto.
	 *
	 * @param string $negocio_id  Negocio.
	 * @param string $contacto_id Contacto.
	 * @return void
	 */
	private function asociar( string $negocio_id, string $contacto_id ): void {
		$resultado = $this->cliente->peticion(
			'PUT',
			sprintf(
				'/crm/v4/objects/deals/%s/associations/default/contacts/%s',
				rawurlencode( $negocio_id ),
				rawurlencode( $contacto_id )
			)
		);

		if ( ! $resultado['ok'] ) {
			// El negocio existe aunque la asociación falle: se avisa pero no se
			// trata como fallo de la entrega, porque el ingreso ya quedó registrado.
			Plugin::instancia()->logger()->warning(
				'No se pudo asociar el negocio con su contacto en HubSpot.',
				array(
					'negocio'  => $negocio_id,
					'contacto' => $contacto_id,
					'mensaje'  => $resultado['mensaje'],
				)
			);
		}
	}
}
