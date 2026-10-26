<?php
/**
 * Barrido periódico.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Stripe\Api_Verified_Payment;
use SWPagos\Stripe\Event_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Cierra lo que quedó a medias y recorta lo que ya no sirve.
 *
 * Corre una vez al día con el resto de tareas del módulo.
 */
final class Cleanup {

	/**
	 * Horas tras las que una transacción pendiente se da por abandonada.
	 *
	 * Las sesiones de pago de Stripe caducan a las 24 horas; se deja margen para
	 * que su evento de expiración llegue antes de que el barrido intervenga.
	 *
	 * @var int
	 */
	private const HORAS_ABANDONO = 36;

	/**
	 * Días que se conservan los eventos y las bitácoras de éxito.
	 *
	 * Los fallos no se borran: son justo lo que hace falta para diagnosticar.
	 *
	 * @var int
	 */
	private const DIAS_RETENCION = 180;

	/**
	 * Engancha el barrido.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( Activator::CRON_DIARIO, array( $this, 'barrer' ) );
	}

	/**
	 * Ejecuta el barrido.
	 *
	 * @return void
	 */
	public function barrer(): void {
		$reconciliadas = $this->reconciliar_pendientes();
		$cerradas      = $this->cerrar_abandonadas();
		$podadas       = $this->podar_historial();

		Plugin::instancia()->logger()->info(
			'Barrido diario completado.',
			array(
				'reconciliadas' => $reconciliadas,
				'cerradas'      => $cerradas,
				'podadas'       => $podadas,
			)
		);
	}

	/**
	 * Pregunta a Stripe por las transacciones que llevan rato pendientes.
	 *
	 * Es la red de seguridad del punto de enlace de eventos: si no está dado de
	 * alta, los cobros se habrían hecho sin confirmarse nunca en el sitio.
	 *
	 * @return int Cuántas se confirmaron.
	 */
	private function reconciliar_pendientes(): int {
		$repo = new Transaction_Repository();

		$pendientes = $repo->buscar(
			array(
				'estado' => Transaction::PENDIENTE,
				'hasta'  => gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ),
			),
			50,
			0
		);

		$confirmadas = 0;
		$manejador   = new Event_Handler();

		foreach ( $pendientes as $transaccion ) {
			$sesion = (string) $transaccion->valor( 'stripe_session_id', '' );

			if ( '' === $sesion ) {
				continue;
			}

			$prueba = Api_Verified_Payment::desde_sesion( $sesion, $transaccion->modo() );

			if ( null === $prueba ) {
				continue;
			}

			$manejador->confirmar_reconciliado( $transaccion, $prueba );
			++$confirmadas;
		}

		return $confirmadas;
	}

	/**
	 * Cierra las transacciones que llevan demasiado tiempo sin resolverse.
	 *
	 * @return int
	 */
	private function cerrar_abandonadas(): int {
		$repo = new Transaction_Repository();

		$viejas = $repo->buscar(
			array(
				'estado' => Transaction::PENDIENTE,
				'hasta'  => gmdate( 'Y-m-d H:i:s', time() - self::HORAS_ABANDONO * HOUR_IN_SECONDS ),
			),
			200,
			0
		);

		$cerradas = 0;

		foreach ( $viejas as $transaccion ) {
			$ok = $repo->transicionar(
				$transaccion,
				Transaction::CANCELADA,
				array( 'failure_code' => 'abandonada' )
			);

			if ( $ok ) {
				++$cerradas;
			}
		}

		// Las iniciadas que nunca llegaron a crear sesión son envíos que fallaron
		// antes de la pasarela; se cierran igual para que no ensucien el panel.
		$fallidas = $repo->buscar(
			array(
				'estado' => Transaction::INICIADA,
				'hasta'  => gmdate( 'Y-m-d H:i:s', time() - self::HORAS_ABANDONO * HOUR_IN_SECONDS ),
			),
			200,
			0
		);

		foreach ( $fallidas as $transaccion ) {
			if ( $repo->transicionar( $transaccion, Transaction::RECHAZADA, array( 'failure_code' => 'nunca_iniciada' ) ) ) {
				++$cerradas;
			}
		}

		return $cerradas;
	}

	/**
	 * Recorta eventos y bitácoras antiguas.
	 *
	 * @return int Filas eliminadas.
	 */
	private function podar_historial(): int {
		global $wpdb;

		$corte = gmdate( 'Y-m-d H:i:s', time() - self::DIAS_RETENCION * DAY_IN_SECONDS );

		$eventos = Activator::tabla( 'gateway_events' );
		$logs    = Activator::tabla( 'delivery_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$a = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$eventos} WHERE processed_at IS NOT NULL AND received_at < %s", $corte )
		);

		// Solo se podan las entregas con éxito: el historial de fallos es lo que
		// permite entender qué pasó meses después.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$b = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$logs} WHERE status = %s AND created_at < %s", 'success', $corte )
		);

		return $a + $b;
	}
}
