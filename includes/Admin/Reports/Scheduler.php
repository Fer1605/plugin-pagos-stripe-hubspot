<?php
/**
 * Informes periódicos por plantilla.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin\Reports;

use SWPagos\Core\Activator;
use SWPagos\Core\Compat;
use SWPagos\Core\Currency;
use SWPagos\Core\Plugin;
use SWPagos\Domain\Delivery_Log;
use SWPagos\Domain\Subscription_Repository;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Manda a cada responsable el resumen de su campaña.
 *
 * Corre una vez al día y decide qué plantillas toca enviar según su propia
 * configuración, en vez de tener un cron por plantilla.
 */
final class Scheduler {

	/**
	 * Engancha la tarea y el envío inmediato.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( Activator::CRON_DIARIO, array( $this, 'repasar' ) );
		add_action( 'admin_post_swpagos_enviar_informe', array( $this, 'enviar_ahora' ) );
	}

	/**
	 * Repasa las plantillas y envía las que toquen hoy.
	 *
	 * @return void
	 */
	public function repasar(): void {
		$plantillas = get_posts(
			array(
				'post_type'   => Compat::CPT_PLANTILLA,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $plantillas as $id ) {
			$plantilla = Plantilla::por_id( (int) $id );

			if ( null === $plantilla || ! $this->toca_hoy( $plantilla ) ) {
				continue;
			}

			$this->enviar( $plantilla );
		}
	}

	/**
	 * Envío inmediato desde la pantalla de la plantilla.
	 *
	 * @return void
	 */
	public function enviar_ahora(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para esta acción.', 'sw-pagos' ) );
		}

		$id = isset( $_GET['plantilla'] ) ? (int) $_GET['plantilla'] : 0;

		check_admin_referer( 'swpagos_informe_' . $id );

		$plantilla = Plantilla::por_id( $id );

		if ( null === $plantilla ) {
			wp_die( esc_html__( 'No existe esa plantilla.', 'sw-pagos' ) );
		}

		$ok = $this->enviar( $plantilla );

		wp_safe_redirect(
			add_query_arg(
				array( 'swpagos_informe' => $ok ? 'enviado' : 'fallido' ),
				(string) get_edit_post_link( $id, 'raw' )
			)
		);
		exit;
	}

	/**
	 * Decide si hoy toca enviar.
	 *
	 * @param Plantilla $plantilla Plantilla.
	 * @return bool
	 */
	private function toca_hoy( Plantilla $plantilla ): bool {
		$frecuencia = $plantilla->report_freq();

		if ( 'none' === $frecuencia || empty( $plantilla->report_to() ) ) {
			return false;
		}

		if ( 'weekly' === $frecuencia ) {
			$dias = $plantilla->report_days();
			$hoy  = strtolower( substr( wp_date( 'D' ), 0, 3 ) );

			$equivalencias = array(
				'mon' => 'mon',
				'tue' => 'tue',
				'wed' => 'wed',
				'thu' => 'thu',
				'fri' => 'fri',
				'sat' => 'sat',
				'sun' => 'sun',
			);

			return in_array( $equivalencias[ $hoy ] ?? '', $dias, true );
		}

		if ( 'monthly' === $frecuencia ) {
			$dia = (int) get_post_meta( $plantilla->id(), '_swpagos_report_dom', true );

			if ( $dia < 1 ) {
				return false;
			}

			$hoy          = (int) wp_date( 'j' );
			$ultimo_delmes = (int) wp_date( 't' );

			// Un informe del día 31 en febrero no debe perderse: se manda el último
			// día del mes.
			return $hoy === $dia || ( $dia > $ultimo_delmes && $hoy === $ultimo_delmes );
		}

		return false;
	}

	/**
	 * Compone y envía el informe de una plantilla.
	 *
	 * @param Plantilla $plantilla Plantilla.
	 * @return bool
	 */
	public function enviar( Plantilla $plantilla ): bool {
		$destinatarios = $plantilla->report_to();

		if ( empty( $destinatarios ) ) {
			return false;
		}

		$frecuencia = $plantilla->report_freq();
		$desde      = 'monthly' === $frecuencia
			? gmdate( 'Y-m-d 00:00:00', strtotime( '-1 month' ) )
			: gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
		$hasta      = current_time( 'mysql' );

		$cuerpo  = $this->componer( $plantilla, $desde, $hasta );
		$asunto  = sprintf(
			/* translators: 1: nombre del sitio, 2: nombre de la campaña. */
			__( '[%1$s] Informe de %2$s', 'sw-pagos' ),
			wp_specialchars_decode( get_bloginfo( 'name' ) ),
			$plantilla->titulo()
		);

		$enviado = wp_mail(
			$destinatarios,
			$asunto,
			$cuerpo,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		Delivery_Log::registrar(
			array(
				'canal'   => Delivery_Log::CANAL_REPORTE,
				'estado'  => $enviado ? Delivery_Log::EXITO : Delivery_Log::FALLO,
				'form_id' => $plantilla->form_id(),
				'destino' => implode( ',', $destinatarios ),
				'mensaje' => $enviado
					? __( 'Informe enviado.', 'sw-pagos' )
					: __( 'No se pudo enviar el informe.', 'sw-pagos' ),
			)
		);

		if ( ! $enviado ) {
			Plugin::instancia()->logger()->warning(
				'No se pudo enviar el informe de una plantilla.',
				array( 'plantilla' => $plantilla->id() )
			);
		}

		return (bool) $enviado;
	}

	/**
	 * Compone el cuerpo del informe.
	 *
	 * @param Plantilla $plantilla Plantilla.
	 * @param string    $desde     Inicio.
	 * @param string    $hasta     Fin.
	 * @return string
	 */
	private function componer( Plantilla $plantilla, string $desde, string $hasta ): string {
		$txns   = new Transaction_Repository();
		$filas  = $txns->buscar(
			array(
				'form_id' => $plantilla->form_id(),
				'modo'    => 'live',
				'desde'   => $desde,
				'hasta'   => $hasta,
			),
			2000,
			0
		);

		$pagadas = array_filter( $filas, static fn( Transaction $t ): bool => $t->esta_pagada() );
		$total   = array_sum( array_map( static fn( Transaction $t ): int => $t->importe(), $pagadas ) );
		$moneda  = $plantilla->moneda();

		$fallidas = array_filter(
			$filas,
			static fn( Transaction $t ): bool => in_array(
				$t->estado(),
				array( Transaction::FALLIDA, Transaction::RECHAZADA ),
				true
			)
		);

		$metricas = ( new Subscription_Repository() )->metricas( $desde, $hasta, 'live' );

		$html  = '<h2>' . esc_html( $plantilla->titulo() ) . '</h2>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: fecha inicial, 2: fecha final. */
				__( 'Período del %1$s al %2$s', 'sw-pagos' ),
				wp_date( 'd/m/Y', strtotime( $desde ) ),
				wp_date( 'd/m/Y', strtotime( $hasta ) )
			)
		) . '</p>';

		$html .= '<h3>' . esc_html__( 'Cobros del período', 'sw-pagos' ) . '</h3><ul>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %d: número. */ __( 'Cobros confirmados: %d', 'sw-pagos' ), count( $pagadas ) ) ) . '</li>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %s: importe. */ __( 'Total recaudado: %s', 'sw-pagos' ), Currency::formatear( $total, $moneda ) ) ) . '</li>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %d: número. */ __( 'Cobros fallidos: %d', 'sw-pagos' ), count( $fallidas ) ) ) . '</li>';
		$html .= '</ul>';

		$html .= '<h3>' . esc_html__( 'Suscripciones', 'sw-pagos' ) . '</h3><ul>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %d: número. */ __( 'Activas: %d', 'sw-pagos' ), $metricas['activas'] ) ) . '</li>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %s: importe. */ __( 'Ingreso recurrente mensual: %s', 'sw-pagos' ), Currency::formatear( $metricas['recurrente_mensual'], $metricas['moneda'] ) ) ) . '</li>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %d: número. */ __( 'Altas del período: %d', 'sw-pagos' ), $metricas['altas_periodo'] ) ) . '</li>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %d: número. */ __( 'Cobros en impago: %d', 'sw-pagos' ), $metricas['impago'] ) ) . '</li>';
		$html .= '<li>' . esc_html( sprintf( /* translators: %d: número. */ __( 'Bajas del período: %d', 'sw-pagos' ), $metricas['canceladas_periodo'] ) ) . '</li>';
		$html .= '</ul>';

		if ( ! empty( $pagadas ) ) {
			$html .= '<h3>' . esc_html__( 'Detalle', 'sw-pagos' ) . '</h3>';
			$html .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse"><tr>';
			$html .= '<th>' . esc_html__( 'Fecha', 'sw-pagos' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Persona', 'sw-pagos' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Importe', 'sw-pagos' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Tipo', 'sw-pagos' ) . '</th></tr>';

			foreach ( array_slice( $pagadas, 0, 100 ) as $t ) {
				$html .= '<tr>';
				$html .= '<td>' . esc_html( wp_date( 'd/m/Y', strtotime( (string) $t->valor( 'paid_at', $t->valor( 'created_at', '' ) ) ) ) ) . '</td>';
				$html .= '<td>' . esc_html( $t->nombre() ?: $t->email() ) . '</td>';
				$html .= '<td>' . esc_html( $t->importe_legible() ) . '</td>';
				$html .= '<td>' . esc_html( Transaction::TIPO_RENOVACION === $t->tipo() ? __( 'renovación', 'sw-pagos' ) : __( 'único', 'sw-pagos' ) ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</table>';

			if ( count( $pagadas ) > 100 ) {
				$html .= '<p>' . esc_html__( 'Se muestran los primeros 100. El listado completo está en el panel.', 'sw-pagos' ) . '</p>';
			}
		}

		$html .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=swpagos-transacciones' ) ) . '">' . esc_html__( 'Ver todas las transacciones', 'sw-pagos' ) . '</a></p>';

		return $html;
	}
}
