<?php
/**
 * Lo que ocurre cuando un cobro se confirma.
 *
 * @package SWPagos
 */

namespace SWPagos\Delivery;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Delivery_Log;
use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\HubSpot\Contact_Service;
use SWPagos\HubSpot\Deal_Service;
use SWPagos\HubSpot\Crm_Settings;
use SWPagos\HubSpot\Subscription_Sync;
use SWPagos\Stripe\Billing_Portal;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Orquesta correo y CRM tras un cobro confirmado.
 *
 * Todo cuelga de la transición a `pagada`, nunca de la creación del cobro: un
 * cobro rechazado, abandonado o sin confirmar no manda correo ni toca HubSpot
 *. Y nada de esto corre dentro del receptor de eventos: se
 * encola, para que Stripe reciba su respuesta deprisa.
 */
final class Post_Payment {

	/**
	 * Cola.
	 *
	 * @var Queue
	 */
	private Queue $cola;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cola = new Queue();
	}

	/**
	 * Engancha todos los disparadores.
	 *
	 * @return void
	 */
	public function registrar(): void {
		$this->cola->registrar();

		add_action( 'swpagos_cobro_confirmado', array( $this, 'al_confirmar' ), 10, 2 );
		add_action( 'swpagos_cobro_mensual_fallido', array( $this, 'al_fallar_cobro' ), 10, 2 );
		add_action( 'swpagos_suscripcion_cancelada', array( $this, 'al_cancelar' ), 10, 2 );
		add_action( 'swpagos_suscripcion_recuperada', array( $this, 'al_recuperar' ), 10, 1 );
		add_action( 'swpagos_transaccion_reembolsada', array( $this, 'al_reembolsar' ), 10, 2 );

		add_action( 'swpagos_ejecutar_email', array( $this, 'trabajo_email' ), 10, 1 );
		add_action( 'swpagos_ejecutar_hubspot_forms', array( $this, 'trabajo_hubspot_forms' ), 10, 1 );
		add_action( 'swpagos_ejecutar_hubspot_crm', array( $this, 'trabajo_hubspot_crm' ), 10, 1 );
	}

	/**
	 * Al confirmarse un cobro: se encolan correo y HubSpot.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @param mixed            $prueba      Prueba del cobro.
	 * @return void
	 */
	public function al_confirmar( $transaccion, $prueba = null ): void {
		if ( ! $transaccion instanceof Transaction ) {
			return;
		}

		$base = array(
			'transaccion' => $transaccion->id(),
			'intento'     => 1,
		);

		$tipo = Transaction::TIPO_RENOVACION === $transaccion->tipo()
			? Email_Service::TIPO_RECIBO
			: Email_Service::TIPO_CONFIRMACION;

		$this->cola->encolar( array_merge( $base, array( 'tipo' => 'email', 'correo' => $tipo ) ) );
		$this->cola->encolar( array_merge( $base, array( 'tipo' => 'hubspot_forms' ) ) );
		$this->cola->encolar( array_merge( $base, array( 'tipo' => 'hubspot_crm' ) ) );
	}

	/**
	 * Al fallar un cobro mensual.
	 *
	 * @param Subscription|null $suscripcion Suscripción.
	 * @param int               $intentos    Intentos.
	 * @return void
	 */
	public function al_fallar_cobro( $suscripcion, $intentos = 1 ): void {
		if ( ! $suscripcion instanceof Subscription ) {
			return;
		}

		$this->cola->encolar(
			array(
				'tipo'        => 'email',
				'correo'      => Email_Service::TIPO_FALLO,
				'suscripcion' => $suscripcion->id(),
				'intento'     => 1,
			)
		);

		// No se crea negocio en el CRM: no hubo cobro. Solo se refleja el estado
		// en el contacto, que ya existía por el primer cobro exitoso.
		( new Subscription_Sync() )->actualizar( $suscripcion );

		$this->avisar_al_equipo( $suscripcion, $intentos );
	}

	/**
	 * Al cancelarse una suscripción.
	 *
	 * @param Subscription|null $suscripcion Suscripción.
	 * @param string            $origen      Quién la canceló.
	 * @return void
	 */
	public function al_cancelar( $suscripcion, $origen = '' ): void {
		if ( ! $suscripcion instanceof Subscription ) {
			return;
		}

		$this->cola->encolar(
			array(
				'tipo'        => 'email',
				'correo'      => Email_Service::TIPO_CANCELACION,
				'suscripcion' => $suscripcion->id(),
				'intento'     => 1,
			)
		);

		( new Subscription_Sync() )->actualizar( $suscripcion );
	}

	/**
	 * Al recuperarse una suscripción que estaba en impago.
	 *
	 * @param Subscription|null $suscripcion Suscripción.
	 * @return void
	 */
	public function al_recuperar( $suscripcion ): void {
		if ( $suscripcion instanceof Subscription ) {
			( new Subscription_Sync() )->actualizar( $suscripcion );
		}
	}

	/**
	 * Al reembolsarse una transacción.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @param int              $devuelto    Importe devuelto.
	 * @return void
	 */
	public function al_reembolsar( $transaccion, $devuelto = 0 ): void {
		if ( ! $transaccion instanceof Transaction ) {
			return;
		}

		Plugin::instancia()->logger()->info(
			'Reembolso registrado.',
			array(
				'transaccion' => $transaccion->uuid(),
				'devuelto'    => $devuelto,
			)
		);

		$negocio = (string) $transaccion->valor( 'hubspot_deal_id', '' );

		if ( '' === $negocio ) {
			return;
		}

		$crm = new \SWPagos\HubSpot\Crm_Client();

		if ( ! $crm->configurado() ) {
			return;
		}

		// El negocio no se borra: el ingreso existió. Se marca lo devuelto para que
		// la lectura comercial sea honesta.
		$resultado = $crm->peticion(
			'PATCH',
			'/crm/v3/objects/deals/' . rawurlencode( $negocio ),
			array(
				'properties' => array(
					'swpagos_refunded_amount' => \SWPagos\Core\Currency::a_unidad_mayor( $devuelto, $transaccion->moneda() ),
					'swpagos_refunded_at'     => (string) ( time() * 1000 ),
				),
			)
		);

		Delivery_Log::registrar(
			array(
				'canal'       => Delivery_Log::CANAL_HUBSPOT_CRM,
				'estado'      => $resultado['ok'] ? Delivery_Log::EXITO : Delivery_Log::FALLO,
				'form_id'     => $transaccion->form_id(),
				'transaccion' => $transaccion->id(),
				'destino'     => 'crm.hubapi.com',
				'mensaje'     => $resultado['ok']
					? __( 'Reembolso reflejado en el negocio de HubSpot.', 'sw-pagos' )
					: $resultado['mensaje'],
			)
		);
	}

	// ------------------------------------------------------------------
	// Trabajos de la cola
	// ------------------------------------------------------------------

	/**
	 * Envía un correo encolado.
	 *
	 * @param array<string,mixed> $trabajo Trabajo.
	 * @return void
	 */
	public function trabajo_email( $trabajo ): void {
		if ( ! is_array( $trabajo ) ) {
			return;
		}

		$tipo        = (string) ( $trabajo['correo'] ?? Email_Service::TIPO_CONFIRMACION );
		$transaccion = $this->transaccion( $trabajo );
		$suscripcion = $this->suscripcion( $trabajo, $transaccion );
		$plantilla   = $this->plantilla( $transaccion, $suscripcion );

		if ( null === $plantilla ) {
			return;
		}

		// El correo sale de la cola, y la cola corre en el idioma del sitio, no en
		// el de quien donó. Sin esto, a quien usa el formulario en inglés le
		// llegan los textos de su plantilla en inglés pero los rótulos que pone
		// el módulo —importe, fecha, referencia— en español.
		$cambiado = $this->cambiar_idioma( $transaccion );

		$render = $this->marcadores( $transaccion, $suscripcion );

		$ok = ( new Email_Service() )->enviar(
			$tipo,
			$plantilla,
			$render,
			array(
				'form_id'     => null !== $transaccion ? $transaccion->form_id() : ( null !== $suscripcion ? $suscripcion->form_id() : '' ),
				'transaccion' => null !== $transaccion ? $transaccion->id() : 0,
				'suscripcion' => null !== $suscripcion ? $suscripcion->id() : 0,
			)
		);

		if ( $cambiado ) {
			restore_previous_locale();
		}

		if ( ! $ok ) {
			$this->cola->reintentar(
				$trabajo,
				Delivery_Log::CANAL_EMAIL,
				$this->contexto_alerta( $transaccion, $suscripcion, __( 'No se pudo enviar el correo.', 'sw-pagos' ) )
			);
		}
	}

	/**
	 * Pone el idioma de quien pagó mientras se compone el correo.
	 *
	 * @param Transaction|null $transaccion Transacción.
	 * @return bool Si hubo cambio y luego hay que deshacerlo.
	 */
	private function cambiar_idioma( ?Transaction $transaccion ): bool {
		if ( null === $transaccion ) {
			return false;
		}

		$locale = trim( (string) $transaccion->valor( 'locale', '' ) );

		if ( '' === $locale || $locale === get_locale() ) {
			return false;
		}

		return switch_to_locale( $locale );
	}

	/**
	 * Envía el formulario a HubSpot tras el cobro.
	 *
	 * @param array<string,mixed> $trabajo Trabajo.
	 * @return void
	 */
	public function trabajo_hubspot_forms( $trabajo ): void {
		$transaccion = $this->transaccion( $trabajo );

		if ( null === $transaccion ) {
			return;
		}

		$registro = (int) ( $transaccion->metadata()['_hef_log'] ?? 0 );

		if ( $registro <= 0 ) {
			// Este formulario no envía a HubSpot. No es un error.
			return;
		}

		// El envío ya está guardado entero desde que la persona pulsó el botón;
		// lo único que faltaba era la confirmación del cobro. Lo manda el módulo
		// de HubSpot con su propio reintento y su propio registro.
		$ok = \SWPagos\HubSpot\Hef_Bridge::reenviar( $registro );

		Plugin::instancia()->logger()->info(
			$ok
				? 'Envío a HubSpot despachado tras confirmarse el cobro.'
				: 'El envío a HubSpot falló tras confirmarse el cobro. Queda en sus registros para reintentar.',
			array(
				'transaccion' => $transaccion->id(),
				'registro'    => $registro,
			)
		);

		if ( ! $ok ) {
			$this->cola->reintentar(
				$trabajo,
				Delivery_Log::CANAL_HUBSPOT_FORMS,
				$this->contexto_alerta( $transaccion, null, __( 'Fallo al enviar el formulario a HubSpot.', 'sw-pagos' ) )
			);
		}
	}

	/**
	 * Crea contacto y negocio en el CRM.
	 *
	 * @param array<string,mixed> $trabajo Trabajo.
	 * @return void
	 */
	public function trabajo_hubspot_crm( $trabajo ): void {
		$transaccion = $this->transaccion( $trabajo );

		if ( null === $transaccion ) {
			return;
		}

		if ( ! Crm_Settings::activo() ) {
			// Apagado a propósito en «Ajustes de HubSpot»: el sitio quiere los
			// formularios de HubSpot pero no los negocios.
			return;
		}

		$crm = new \SWPagos\HubSpot\Crm_Client();

		if ( ! $crm->configurado() ) {
			// Sin token no hay nivel 2. Es una configuración válida: el sitio puede
			// querer solo los formularios de HubSpot.
			return;
		}

		$repo        = new Transaction_Repository();
		$suscripcion = $this->suscripcion( $trabajo, $transaccion );

		$contacto = ( new Contact_Service( $crm ) )->upsert(
			$transaccion->email(),
			$this->propiedades_contacto( $transaccion )
		);

		if ( ! $contacto['ok'] ) {
			$this->registrar_crm( $transaccion, false, $contacto['mensaje'], (int) ( $trabajo['intento'] ?? 1 ) );

			if ( $contacto['reintentable'] ) {
				$this->cola->reintentar(
					$trabajo,
					Delivery_Log::CANAL_HUBSPOT_CRM,
					$this->contexto_alerta( $transaccion, null, $contacto['mensaje'] )
				);
			}

			return;
		}

		$repo->actualizar( $transaccion, array( 'hubspot_contact_id' => $contacto['id'] ), 'hubspot' );

		if ( null !== $suscripcion && '' === $suscripcion->hubspot_contact_id() ) {
			( new Subscription_Repository() )->actualizar(
				$suscripcion,
				array( 'hubspot_contact_id' => $contacto['id'] ),
				'hubspot'
			);
			$suscripcion = ( new Subscription_Repository() )->por_id( $suscripcion->id() );
		}

		$negocio = ( new Deal_Service( $crm ) )->crear(
			$transaccion,
			$contacto['id'],
			null !== $suscripcion ? $suscripcion->uuid() : ''
		);

		if ( ! $negocio['ok'] ) {
			$this->registrar_crm( $transaccion, false, $negocio['mensaje'], (int) ( $trabajo['intento'] ?? 1 ) );

			if ( $negocio['reintentable'] ) {
				$this->cola->reintentar(
					$trabajo,
					Delivery_Log::CANAL_HUBSPOT_CRM,
					$this->contexto_alerta( $transaccion, null, $negocio['mensaje'] )
				);
			}

			return;
		}

		$repo->actualizar( $transaccion, array( 'hubspot_deal_id' => $negocio['id'] ), 'hubspot' );

		if ( null !== $suscripcion ) {
			( new Subscription_Sync( $crm ) )->actualizar( $suscripcion );
		}

		$this->registrar_crm(
			$transaccion,
			true,
			sprintf(
				/* translators: 1: identificador del contacto, 2: identificador del negocio. */
				__( 'Contacto %1$s y negocio %2$s creados en HubSpot.', 'sw-pagos' ),
				$contacto['id'],
				$negocio['id']
			),
			(int) ( $trabajo['intento'] ?? 1 )
		);
	}

	// ------------------------------------------------------------------
	// Auxiliares
	// ------------------------------------------------------------------

	/**
	 * Propiedades del contacto.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return array<string,string>
	 */
	private function propiedades_contacto( Transaction $transaccion ): array {
		$propiedades = array();
		$nombre      = trim( $transaccion->nombre() );

		if ( '' !== $nombre ) {
			$partes = preg_split( '/\s+/', $nombre, 2 );
			$propiedades['firstname'] = (string) ( $partes[0] ?? '' );

			if ( ! empty( $partes[1] ) ) {
				$propiedades['lastname'] = $partes[1];
			}
		}

		$metadata = $transaccion->metadata();

		foreach ( array( 'telefono' => 'phone', 'empresa' => 'company' ) as $nuestro => $suyo ) {
			$valor = \SWPagos\Core\Campos::valor( $metadata, $nuestro );

			if ( '' !== $valor ) {
				$propiedades[ $suyo ] = $valor;
			}
		}

		return $propiedades;
	}

	/**
	 * Marcadores disponibles para los correos.
	 *
	 * @param Transaction|null  $transaccion Transacción.
	 * @param Subscription|null $suscripcion Suscripción.
	 * @return Placeholder_Renderer
	 */
	private function marcadores( ?Transaction $transaccion, ?Subscription $suscripcion ): Placeholder_Renderer {
		$valores = null !== $transaccion ? $transaccion->metadata() : array();

		// Claves internas, no marcadores del correo.
		unset( $valores['_hubspot'], $valores['_hef_log'] );

		$render = new Placeholder_Renderer( $valores );

		if ( null !== $transaccion ) {
			$render->con( 'nombre', $transaccion->nombre() );
			$render->con( 'email', $transaccion->email() );
			$render->con( 'importe', $transaccion->importe_legible() );
			$render->con( 'moneda', $transaccion->moneda() );
			$render->con( 'referencia', $transaccion->uuid() );
			$render->con( 'fecha', wp_date( get_option( 'date_format' ) ) );

			$periodo = (string) $transaccion->valor( 'billing_period', '' );
			if ( '' !== $periodo ) {
				$render->con( 'periodo', $periodo );
			}
		}

		if ( null !== $suscripcion ) {
			$render->con( 'nombre', $suscripcion->nombre() );
			$render->con( 'email', $suscripcion->email() );
			$render->con( 'importe_mensual', $suscripcion->importe_legible() );
			$render->con( 'proximo_cobro', $this->fecha_legible( $suscripcion->proximo_cobro() ) );
			$render->con( 'enlace_actualizar_pago', ( new Billing_Portal() )->url_para( $suscripcion ) );
		}

		return $render;
	}

	/**
	 * Fecha legible.
	 *
	 * @param string $fecha Fecha de base de datos.
	 * @return string
	 */
	private function fecha_legible( string $fecha ): string {
		$marca = '' !== $fecha ? strtotime( $fecha ) : false;

		return false !== $marca ? wp_date( get_option( 'date_format' ), $marca ) : '';
	}

	/**
	 * Avisa al equipo de un cobro mensual fallido.
	 *
	 * @param Subscription $suscripcion Suscripción.
	 * @param int          $intentos    Intentos.
	 * @return void
	 */
	private function avisar_al_equipo( Subscription $suscripcion, int $intentos ): void {
		$destinatarios = Plugin::instancia()->settings()->destinatarios_alerta();

		if ( empty( $destinatarios ) ) {
			return;
		}

		$asunto = sprintf(
			/* translators: %s: nombre del sitio. */
			__( '[%s] Cobro mensual fallido', 'sw-pagos' ),
			wp_specialchars_decode( get_bloginfo( 'name' ) )
		);

		$lineas = array(
			__( 'Un cobro de suscripción no se pudo completar.', 'sw-pagos' ),
			'',
			sprintf( '%s: %s', __( 'Persona', 'sw-pagos' ), $suscripcion->nombre() ?: $suscripcion->email() ),
			sprintf( '%s: %s', __( 'Correo', 'sw-pagos' ), $suscripcion->email() ),
			sprintf( '%s: %s', __( 'Importe mensual', 'sw-pagos' ), $suscripcion->importe_legible() ),
			sprintf( '%s: %d', __( 'Intento', 'sw-pagos' ), $intentos ),
			'',
			__( 'Ya se le envió un aviso con el enlace para actualizar su medio de pago.', 'sw-pagos' ),
			admin_url( 'admin.php?page=swpagos-suscripciones' ),
		);

		wp_mail( $destinatarios, $asunto, implode( "\n", $lineas ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Registra el resultado del CRM.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @param bool        $ok          Resultado.
	 * @param string      $mensaje     Mensaje.
	 * @param int         $intento     Intento.
	 * @return void
	 */
	private function registrar_crm( Transaction $transaccion, bool $ok, string $mensaje, int $intento ): void {
		Delivery_Log::registrar(
			array(
				'canal'       => Delivery_Log::CANAL_HUBSPOT_CRM,
				'estado'      => $ok ? Delivery_Log::EXITO : Delivery_Log::FALLO,
				'form_id'     => $transaccion->form_id(),
				'transaccion' => $transaccion->id(),
				'destino'     => 'crm.hubapi.com',
				'mensaje'     => $mensaje,
				'intento'     => $intento,
			)
		);
	}

	/**
	 * Contexto para la alerta de reintentos agotados.
	 *
	 * @param Transaction|null  $transaccion Transacción.
	 * @param Subscription|null $suscripcion Suscripción.
	 * @param string            $mensaje     Mensaje.
	 * @return array<string,mixed>
	 */
	private function contexto_alerta( ?Transaction $transaccion, ?Subscription $suscripcion, string $mensaje ): array {
		return array(
			'form_id'     => null !== $transaccion ? $transaccion->form_id() : ( null !== $suscripcion ? $suscripcion->form_id() : '' ),
			'uuid'        => null !== $transaccion ? $transaccion->uuid() : ( null !== $suscripcion ? $suscripcion->uuid() : '' ),
			'transaccion' => null !== $transaccion ? $transaccion->id() : 0,
			'mensaje'     => $mensaje,
		);
	}

	/**
	 * Transacción del trabajo.
	 *
	 * @param mixed $trabajo Trabajo.
	 * @return Transaction|null
	 */
	private function transaccion( $trabajo ): ?Transaction {
		$id = is_array( $trabajo ) ? (int) ( $trabajo['transaccion'] ?? 0 ) : 0;

		return $id > 0 ? ( new Transaction_Repository() )->por_id( $id ) : null;
	}

	/**
	 * Suscripción del trabajo, o la de la transacción.
	 *
	 * @param mixed            $trabajo     Trabajo.
	 * @param Transaction|null $transaccion Transacción.
	 * @return Subscription|null
	 */
	private function suscripcion( $trabajo, ?Transaction $transaccion ): ?Subscription {
		$repo = new Subscription_Repository();
		$id   = is_array( $trabajo ) ? (int) ( $trabajo['suscripcion'] ?? 0 ) : 0;

		if ( $id > 0 ) {
			return $repo->por_id( $id );
		}

		if ( null !== $transaccion && $transaccion->subscription_id() > 0 ) {
			return $repo->por_id( $transaccion->subscription_id() );
		}

		return null;
	}

	/**
	 * Plantilla aplicable.
	 *
	 * @param Transaction|null  $transaccion Transacción.
	 * @param Subscription|null $suscripcion Suscripción.
	 * @return Plantilla|null
	 */
	private function plantilla( ?Transaction $transaccion, ?Subscription $suscripcion ): ?Plantilla {
		if ( null !== $transaccion && $transaccion->template_id() > 0 ) {
			return Plantilla::por_id( $transaccion->template_id() );
		}

		if ( null !== $suscripcion && $suscripcion->template_id() > 0 ) {
			return Plantilla::por_id( $suscripcion->template_id() );
		}

		return null;
	}
}
