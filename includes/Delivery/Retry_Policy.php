<?php
/**
 * Política de reintentos de las entregas.
 *
 * @package SWPagos
 */

namespace SWPagos\Delivery;

use SWPagos\Core\Plugin;
use SWPagos\Domain\Delivery_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Cuánto esperar antes de volver a intentar, y cuándo rendirse.
 *
 * El módulo de referencia solo reintentaba a mano desde el panel (hallazgo H-09),
 * así que un fallo transitorio de HubSpot a las tres de la mañana se quedaba sin
 * entregar hasta que alguien lo viera.
 */
final class Retry_Policy {

	/**
	 * Esperas entre intentos, en segundos.
	 *
	 * Creciente a propósito: casi todos los fallos son transitorios y se arreglan
	 * en el primer minuto; insistir cada pocos segundos solo añade carga a un
	 * servicio que ya está teniendo problemas.
	 *
	 * @var int[]
	 */
	private const ESPERAS = array( 60, 300, 1800, 7200, 21600 );

	/**
	 * Indica si queda algún intento.
	 *
	 * @param int $intento Número del intento que acaba de fallar.
	 * @return bool
	 */
	public function quedan_intentos( int $intento ): bool {
		return $intento < count( self::ESPERAS );
	}

	/**
	 * Segundos a esperar antes del siguiente intento.
	 *
	 * @param int $intento Número del intento que acaba de fallar.
	 * @return int
	 */
	public function espera( int $intento ): int {
		$indice = max( 0, min( $intento - 1, count( self::ESPERAS ) - 1 ) );

		return self::ESPERAS[ $indice ];
	}

	/**
	 * Número máximo de intentos.
	 *
	 * @return int
	 */
	public function maximo(): int {
		return count( self::ESPERAS );
	}

	/**
	 * Avisa de que una entrega agotó sus intentos.
	 *
	 * @param string              $canal    Canal.
	 * @param array<string,mixed> $contexto Contexto de la entrega.
	 * @return void
	 */
	public function alertar( string $canal, array $contexto ): void {
		$destinatarios = Plugin::instancia()->settings()->destinatarios_alerta();

		if ( empty( $destinatarios ) ) {
			return;
		}

		$sitio = wp_specialchars_decode( get_bloginfo( 'name' ) );

		$asunto = sprintf(
			/* translators: 1: nombre del sitio, 2: canal de entrega. */
			__( '[%1$s] Una entrega de %2$s agotó sus reintentos', 'sw-pagos' ),
			$sitio,
			$this->nombre_canal( $canal )
		);

		$lineas = array(
			__( 'Una entrega del módulo de pagos no se pudo completar tras todos los reintentos.', 'sw-pagos' ),
			'',
			sprintf( '%s: %s', __( 'Sitio', 'sw-pagos' ), home_url() ),
			sprintf( '%s: %s', __( 'Canal', 'sw-pagos' ), $this->nombre_canal( $canal ) ),
			sprintf( '%s: %s', __( 'Formulario', 'sw-pagos' ), (string) ( $contexto['form_id'] ?? '—' ) ),
			sprintf( '%s: %s', __( 'Transacción', 'sw-pagos' ), (string) ( $contexto['uuid'] ?? '—' ) ),
			sprintf( '%s: %s', __( 'Último error', 'sw-pagos' ), (string) ( $contexto['mensaje'] ?? '—' ) ),
			'',
			__( 'El cobro no se ve afectado: lo que falló es la entrega posterior.', 'sw-pagos' ),
			__( 'Puedes reintentarla a mano desde:', 'sw-pagos' ),
			admin_url( 'admin.php?page=swpagos-entregas' ),
		);

		$cabeceras = array(
			'Content-Type: text/plain; charset=UTF-8',
			Plugin::instancia()->settings()->cabecera_remitente(),
		);

		foreach ( Plugin::instancia()->settings()->copias_alerta() as $copia ) {
			$cabeceras[] = 'Bcc: ' . $copia;
		}

		$enviado = wp_mail(
			$destinatarios,
			$asunto,
			implode( "\n", $lineas ),
			$cabeceras
		);

		Delivery_Log::registrar(
			array(
				'canal'       => Delivery_Log::CANAL_ALERTA,
				'estado'      => $enviado ? Delivery_Log::EXITO : Delivery_Log::FALLO,
				'form_id'     => (string) ( $contexto['form_id'] ?? '' ),
				'transaccion' => (int) ( $contexto['transaccion'] ?? 0 ),
				'destino'     => implode( ',', $destinatarios ),
				'mensaje'     => sprintf(
					/* translators: %s: canal de entrega. */
					__( 'Alerta por reintentos agotados en %s.', 'sw-pagos' ),
					$canal
				),
			)
		);
	}

	/**
	 * Nombre legible de un canal.
	 *
	 * @param string $canal Canal.
	 * @return string
	 */
	private function nombre_canal( string $canal ): string {
		$nombres = array(
			Delivery_Log::CANAL_EMAIL         => __( 'correo', 'sw-pagos' ),
			Delivery_Log::CANAL_HUBSPOT_FORMS => __( 'formularios de HubSpot', 'sw-pagos' ),
			Delivery_Log::CANAL_HUBSPOT_CRM   => __( 'CRM de HubSpot', 'sw-pagos' ),
			Delivery_Log::CANAL_REPORTE       => __( 'informe', 'sw-pagos' ),
		);

		return $nombres[ $canal ] ?? $canal;
	}
}
