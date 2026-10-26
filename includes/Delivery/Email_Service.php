<?php
/**
 * Envío de los correos del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Delivery;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Delivery_Log;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Toma la plantilla, sustituye los marcadores y envía.
 *
 * Los correos de confirmación solo se disparan cuando el cobro está confirmado
 *; esta clase no decide cuándo, solo cómo.
 */
final class Email_Service {

	public const TIPO_CONFIRMACION = 'confirmacion';
	public const TIPO_NOTIFICACION = 'notificacion';
	public const TIPO_RECIBO       = 'recibo';
	public const TIPO_FALLO        = 'fallo';
	public const TIPO_CANCELACION  = 'cancelacion';

	/**
	 * Titular de la cabecera del correo, por tipo.
	 *
	 * @param string $tipo   Tipo de correo.
	 * @param string $asunto Asunto ya resuelto, como respaldo.
	 * @return string
	 */
	private function titular( string $tipo, string $asunto ): string {
		$titulares = array(
			self::TIPO_CONFIRMACION => __( 'Gracias por tu aporte', 'sw-pagos' ),
			self::TIPO_RECIBO       => __( 'Tu aporte de este mes', 'sw-pagos' ),
			self::TIPO_FALLO        => __( 'No pudimos cobrar tu aporte', 'sw-pagos' ),
			self::TIPO_CANCELACION  => __( 'Tu aporte mensual quedó cancelado', 'sw-pagos' ),
		);

		return $titulares[ $tipo ] ?? $asunto;
	}

	/**
	 * Detalles del cobro, sacados de los marcadores ya cargados.
	 *
	 * Se arman desde el render y no desde la transacción para que un correo sin
	 * transacción asociada no se rompa: lo que no exista, no se pinta.
	 *
	 * @param Placeholder_Renderer $render Marcadores.
	 * @return array<string,string>
	 */
	private function detalles( Placeholder_Renderer $render ): array {
		$mensual = $this->marcador( $render, 'importe_mensual' );

		// Primero qué clase de aporte es y después cuánto: una cifra suelta no
		// dice si se va a repetir el mes que viene, y esa es la primera duda de
		// quien abre el correo.
		$detalles = array(
			__( 'Tipo de importe', 'sw-pagos' ) => '' !== $mensual
				? __( 'Donación mensual', 'sw-pagos' )
				: __( 'Donación única', 'sw-pagos' ),
		);

		// El importe se llama igual en los dos casos: la fila de arriba ya dice si
		// se repite, así que «Aporte mensual» aquí solo repetía lo mismo.
		$detalles += '' !== $mensual
			? array(
				__( 'Importe', 'sw-pagos' )       => $mensual,
				__( 'Próximo cobro', 'sw-pagos' ) => $this->marcador( $render, 'proximo_cobro' ),
			)
			: array( __( 'Importe', 'sw-pagos' ) => $this->marcador( $render, 'importe' ) );

		$detalles[ __( 'Fecha', 'sw-pagos' ) ]      = $this->marcador( $render, 'fecha' );
		$detalles[ __( 'Referencia', 'sw-pagos' ) ] = $this->marcador( $render, 'referencia' );

		return $detalles;
	}

	/**
	 * Valor de un marcador, o cadena vacía si no existe.
	 *
	 * El renderizador devuelve el marcador tal cual cuando no lo conoce, que es
	 * lo correcto dentro de un texto escrito a mano. Aquí no: un detalle que
	 * dijera «[proximo_cobro]» sería peor que no enseñar esa fila.
	 *
	 * @param Placeholder_Renderer $render Marcadores.
	 * @param string               $clave  Nombre del marcador.
	 * @return string
	 */
	private function marcador( Placeholder_Renderer $render, string $clave ): string {
		$valor = trim( $render->render_plano( '[' . $clave . ']' ) );

		return '[' . $clave . ']' === $valor ? '' : $valor;
	}

	/**
	 * Botón del correo, si hay algo útil a donde mandar.
	 *
	 * @param Placeholder_Renderer $render Marcadores.
	 * @return array{texto:string,url:string}|null
	 */
	private function accion( Placeholder_Renderer $render ): ?array {
		$portal = $this->marcador( $render, 'enlace_actualizar_pago' );

		if ( '' !== $portal && false !== wp_http_validate_url( $portal ) ) {
			return array(
				'texto' => __( 'Gestionar mi aporte', 'sw-pagos' ),
				'url'   => $portal,
			);
		}

		return array(
			'texto' => __( 'Visitar el sitio', 'sw-pagos' ),
			'url'   => home_url( '/' ),
		);
	}

	/**
	 * Envía un correo a partir de una plantilla.
	 *
	 * @param string              $tipo      Uno de los TIPO_*.
	 * @param Plantilla           $plantilla Plantilla.
	 * @param Placeholder_Renderer $render   Marcadores ya cargados con los valores.
	 * @param array<string,mixed> $contexto  form_id, envio, transaccion, suscripcion.
	 * @return bool
	 */
	public function enviar( string $tipo, Plantilla $plantilla, Placeholder_Renderer $render, array $contexto = array() ): bool {
		$destinatario = $render->render_email( $plantilla->email_to() );

		if ( '' === $destinatario ) {
			// Sin destinatario resoluble no hay nada que enviar. Si la plantilla no
			// define ninguno, es que este formulario no manda correo: no es error.
			if ( '' !== trim( $plantilla->email_to() ) ) {
				$this->registrar(
					$tipo,
					$plantilla,
					$contexto,
					false,
					'',
					__( 'El destinatario no resolvió a una dirección válida. Revisa el marcador configurado en «Enviar a».', 'sw-pagos' )
				);
			}

			return false;
		}

		$cuerpo = $this->cuerpo( $tipo, $plantilla );

		if ( '' === trim( $cuerpo ) ) {
			return false;
		}

		$asunto   = $render->render_plano( $plantilla->email_subject() );
		$asunto   = '' !== $asunto ? $asunto : get_bloginfo( 'name' );
		$cuerpo   = Email_Template::envolver(
			wpautop( $render->render( $cuerpo ) ),
			$this->titular( $tipo, $asunto ),
			$this->detalles( $render ),
			$this->accion( $render )
		);
		$cabeceras = array(
			'Content-Type: text/html; charset=UTF-8',
			Plugin::instancia()->settings()->cabecera_remitente(),
		);

		foreach ( $plantilla->email_bcc() as $copia ) {
			$cabeceras[] = 'Bcc: ' . $copia;
		}

		$bloqueados = $render->bloqueados_vistos();

		if ( ! empty( $bloqueados ) ) {
			Plugin::instancia()->logger()->warning(
				'La plantilla usa marcadores de datos de pago que no se sustituyen.',
				array(
					'plantilla'  => $plantilla->id(),
					'marcadores' => $bloqueados,
				)
			);
		}

		$error    = '';
		$escucha  = static function ( $wp_error ) use ( &$error ): void {
			if ( $wp_error instanceof \WP_Error ) {
				$error = $wp_error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $escucha, 10, 1 );
		$enviado = wp_mail( $destinatario, $asunto, $cuerpo, $cabeceras );
		remove_action( 'wp_mail_failed', $escucha, 10 );

		$this->registrar(
			$tipo,
			$plantilla,
			$contexto,
			$enviado,
			$destinatario,
			$enviado
				// wp_mail devuelve true cuando el mailer acepta el mensaje, que no
				// es lo mismo que entregarlo. Se dice así en la bitácora para que
				// nadie lea «éxito» como «llegó a la bandeja de entrada».
				? __( 'Aceptado por el servidor de correo. No garantiza la entrega en bandeja.', 'sw-pagos' )
				: ( '' !== $error ? $error : __( 'wp_mail devolvió false sin detallar el motivo.', 'sw-pagos' ) )
		);

		return (bool) $enviado;
	}

	/**
	 * Cuerpo correspondiente al tipo de correo.
	 *
	 * @param string    $tipo      Tipo.
	 * @param Plantilla $plantilla Plantilla.
	 * @return string
	 */
	private function cuerpo( string $tipo, Plantilla $plantilla ): string {
		$especificos = array(
			self::TIPO_RECIBO      => 'recibo',
			self::TIPO_FALLO       => 'fallo',
			self::TIPO_CANCELACION => 'cancelacion',
		);

		if ( isset( $especificos[ $tipo ] ) ) {
			$cuerpo = $plantilla->email_suscripcion( $especificos[ $tipo ] );

			// Sin cuerpo propio se usa el de confirmación: es preferible un correo
			// genérico a ninguno cuando acaba de cobrarse una renovación.
			if ( '' !== trim( $cuerpo ) ) {
				return $cuerpo;
			}
		}

		return $plantilla->cuerpo();
	}

	/**
	 * Registra el intento en la bitácora.
	 *
	 * @param string              $tipo         Tipo de correo.
	 * @param Plantilla           $plantilla    Plantilla.
	 * @param array<string,mixed> $contexto     Contexto.
	 * @param bool                $ok           Resultado.
	 * @param string              $destinatario Destinatario.
	 * @param string              $mensaje      Mensaje.
	 * @return void
	 */
	private function registrar( string $tipo, Plantilla $plantilla, array $contexto, bool $ok, string $destinatario, string $mensaje ): void {
		$transaccion = (int) ( $contexto['transaccion'] ?? 0 );
		$envio       = (int) ( $contexto['envio'] ?? 0 );

		Delivery_Log::registrar(
			array(
				'canal'       => Delivery_Log::CANAL_EMAIL,
				'estado'      => $ok ? Delivery_Log::EXITO : Delivery_Log::FALLO,
				'tipo_correo' => $tipo,
				'form_id'     => (string) ( $contexto['form_id'] ?? $plantilla->form_id() ),
				'envio'       => $envio,
				'transaccion' => $transaccion,
				'suscripcion' => (int) ( $contexto['suscripcion'] ?? 0 ),
				'destino'     => $destinatario,
				'mensaje'     => $mensaje,
				'intento'     => Delivery_Log::intentos( Delivery_Log::CANAL_EMAIL, $transaccion ?: null, $envio ?: null ) + 1,
			)
		);
	}
}
