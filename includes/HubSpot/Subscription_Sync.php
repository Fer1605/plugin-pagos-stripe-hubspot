<?php
/**
 * Estado de la suscripción en el contacto de HubSpot.
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

use SWPagos\Core\Currency;
use SWPagos\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Refleja en el contacto lo que le pasa a su suscripción.
 *
 * Esto es lo único que el módulo escribe en el CRM sin que haya un cobro detrás,
 * y está permitido a propósito: el contacto ya entró por un primer cobro exitoso.
 * Lo prohibido es **entrar** sin cobro, no reflejar después lo que le ocurra a
 * quien ya pagó. Un donante que dejó de pagar y figura como activo es
 * información falsa en el CRM.
 */
final class Subscription_Sync {

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
	 * Actualiza las propiedades de suscripción del contacto.
	 *
	 * @param Subscription $suscripcion Suscripción.
	 * @return bool
	 */
	public function actualizar( Subscription $suscripcion ): bool {
		if ( ! $this->cliente->configurado() ) {
			return false;
		}

		$contacto = $suscripcion->hubspot_contact_id();

		if ( '' === $contacto ) {
			// Sin contacto conocido no hay nada que actualizar: todavía no entró
			// al CRM porque su primer cobro no se ha confirmado.
			return false;
		}

		$propiedades = array(
			'swpagos_subscription_status' => $suscripcion->estado(),
			'swpagos_subscription_amount' => Currency::a_unidad_mayor( $suscripcion->importe(), $suscripcion->moneda() ),
			'swpagos_subscription_since'  => $this->fecha( (string) $suscripcion->valor( 'created_at', '' ) ),
		);

		$resultado = ( new Contact_Service( $this->cliente ) )->upsert( $suscripcion->email(), $propiedades );

		return $resultado['ok'];
	}

	/**
	 * Convierte una fecha de base de datos al formato de HubSpot.
	 *
	 * @param string $fecha Fecha.
	 * @return string
	 */
	private function fecha( string $fecha ): string {
		$marca = '' !== $fecha ? strtotime( $fecha ) : false;

		if ( false === $marca ) {
			return '';
		}

		// HubSpot espera las fechas a medianoche UTC, en milisegundos.
		return (string) ( strtotime( gmdate( 'Y-m-d', $marca ) . ' 00:00:00 UTC' ) * 1000 );
	}
}
