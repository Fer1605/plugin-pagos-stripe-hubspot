<?php
/**
 * Entidad «suscripción».
 *
 * @package SWPagos
 */

namespace SWPagos\Domain;

use SWPagos\Core\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Cobro recurrente y su ciclo de vida.
 *
 * Quien entra al CRM es el primer cobro exitoso. Lo que le pase después a esa
 * suscripción —un impago, una cancelación— sí actualiza al contacto, porque ya
 * entró legítimamente.
 */
final class Subscription {

	public const ACTIVA    = 'activa';
	public const IMPAGO    = 'impago';
	public const PAUSADA   = 'pausada';
	public const CANCELADA = 'cancelada';

	/**
	 * Transiciones permitidas.
	 *
	 * @var array<string,string[]>
	 */
	private const TRANSICIONES = array(
		self::ACTIVA    => array( self::IMPAGO, self::PAUSADA, self::CANCELADA ),
		self::IMPAGO    => array( self::ACTIVA, self::CANCELADA ),
		self::PAUSADA   => array( self::ACTIVA, self::CANCELADA ),
		self::CANCELADA => array(),
	);

	/**
	 * Datos de la fila.
	 *
	 * @var array<string,mixed>
	 */
	private array $datos;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $datos Datos.
	 */
	public function __construct( array $datos = array() ) {
		$this->datos = $datos;
	}

	/**
	 * Indica si una transición está permitida.
	 *
	 * @param string $desde Origen.
	 * @param string $hacia Destino.
	 * @return bool
	 */
	public static function transicion_valida( string $desde, string $hacia ): bool {
		if ( $desde === $hacia ) {
			return false;
		}

		return in_array( $hacia, self::TRANSICIONES[ $desde ] ?? array(), true );
	}

	/**
	 * Estados existentes.
	 *
	 * @return string[]
	 */
	public static function estados(): array {
		return array_keys( self::TRANSICIONES );
	}

	/**
	 * Identificador interno.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) ( $this->datos['id'] ?? 0 );
	}

	/**
	 * Identificador público.
	 *
	 * @return string
	 */
	public function uuid(): string {
		return (string) ( $this->datos['uuid'] ?? '' );
	}

	/**
	 * Estado actual.
	 *
	 * @return string
	 */
	public function estado(): string {
		return (string) ( $this->datos['status'] ?? self::ACTIVA );
	}

	/**
	 * Indica si sigue viva.
	 *
	 * @return bool
	 */
	public function esta_viva(): bool {
		return self::CANCELADA !== $this->estado();
	}

	/**
	 * Importe de cada cobro, en unidad mínima.
	 *
	 * @return int
	 */
	public function importe(): int {
		return (int) ( $this->datos['amount_minor'] ?? 0 );
	}

	/**
	 * Moneda.
	 *
	 * @return string
	 */
	public function moneda(): string {
		return (string) ( $this->datos['currency'] ?? 'USD' );
	}

	/**
	 * Importe legible.
	 *
	 * @return string
	 */
	public function importe_legible(): string {
		return Currency::formatear( $this->importe(), $this->moneda() );
	}

	/**
	 * Modo.
	 *
	 * @return string
	 */
	public function modo(): string {
		return (string) ( $this->datos['mode'] ?? 'test' );
	}

	/**
	 * Formulario de origen.
	 *
	 * @return string
	 */
	public function form_id(): string {
		return (string) ( $this->datos['form_id'] ?? '' );
	}

	/**
	 * Plantilla asociada.
	 *
	 * @return int
	 */
	public function template_id(): int {
		return (int) ( $this->datos['template_id'] ?? 0 );
	}

	/**
	 * Identificador de la suscripción en Stripe.
	 *
	 * @return string
	 */
	public function stripe_id(): string {
		return (string) ( $this->datos['stripe_subscription_id'] ?? '' );
	}

	/**
	 * Identificador del cliente en Stripe.
	 *
	 * @return string
	 */
	public function stripe_customer_id(): string {
		return (string) ( $this->datos['stripe_customer_id'] ?? '' );
	}

	/**
	 * Correo de la persona suscrita.
	 *
	 * @return string
	 */
	public function email(): string {
		return (string) ( $this->datos['customer_email'] ?? '' );
	}

	/**
	 * Nombre de la persona suscrita.
	 *
	 * @return string
	 */
	public function nombre(): string {
		return (string) ( $this->datos['customer_name'] ?? '' );
	}

	/**
	 * Fecha del próximo cobro.
	 *
	 * @return string
	 */
	public function proximo_cobro(): string {
		return (string) ( $this->datos['current_period_end'] ?? '' );
	}

	/**
	 * Indica si se cancelará al terminar el período pagado.
	 *
	 * @return bool
	 */
	public function cancela_al_final(): bool {
		return (bool) ( $this->datos['cancel_at_period_end'] ?? false );
	}

	/**
	 * Cobros fallidos consecutivos.
	 *
	 * @return int
	 */
	public function fallos(): int {
		return (int) ( $this->datos['failed_attempts'] ?? 0 );
	}

	/**
	 * Contacto en HubSpot.
	 *
	 * @return string
	 */
	public function hubspot_contact_id(): string {
		return (string) ( $this->datos['hubspot_contact_id'] ?? '' );
	}

	/**
	 * Un valor cualquiera.
	 *
	 * @param string $clave       Columna.
	 * @param mixed  $por_omision Valor por omisión.
	 * @return mixed
	 */
	public function valor( string $clave, $por_omision = null ) {
		return $this->datos[ $clave ] ?? $por_omision;
	}

	/**
	 * Metadatos.
	 *
	 * @return array<string,mixed>
	 */
	public function metadata(): array {
		$json = json_decode( (string) ( $this->datos['metadata'] ?? '' ), true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Historial de estados.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function historial(): array {
		$json = json_decode( (string) ( $this->datos['status_history'] ?? '' ), true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Todos los datos.
	 *
	 * @return array<string,mixed>
	 */
	public function datos(): array {
		return $this->datos;
	}
}
