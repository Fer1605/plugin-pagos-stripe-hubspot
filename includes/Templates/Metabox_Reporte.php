<?php
/**
 * Metabox: informe periódico.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Cada cuánto y a quién se envía el informe de esta campaña.
 */
final class Metabox_Reporte extends Metabox_Base {

	/**
	 * Días de la semana admitidos.
	 *
	 * @return array<string,string>
	 */
	private function dias(): array {
		return array(
			'mon' => __( 'Lunes', 'sw-pagos' ),
			'tue' => __( 'Martes', 'sw-pagos' ),
			'wed' => __( 'Miércoles', 'sw-pagos' ),
			'thu' => __( 'Jueves', 'sw-pagos' ),
			'fri' => __( 'Viernes', 'sw-pagos' ),
			'sat' => __( 'Sábado', 'sw-pagos' ),
			'sun' => __( 'Domingo', 'sw-pagos' ),
		);
	}

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'reporte';
	}

	/**
	 * Título.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return __( 'Informe periódico', 'sw-pagos' );
	}

	/**
	 * Posición: columna lateral.
	 *
	 * @return string
	 */
	public function contexto(): string {
		return 'side';
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$this->intro(
			__( 'Resumen periódico de lo cobrado con esta plantilla, enviado por correo. Déjalo en «no enviar» si no lo quieres.', 'sw-pagos' )
		);

		$freq = $this->leer( $post->ID, 'report_freq' );
		$dias = array_filter( explode( ',', $this->leer( $post->ID, 'report_days' ) ) );

		$this->campo_select(
			'swpagos_report_freq',
			__( 'Frecuencia', 'sw-pagos' ),
			'' !== $freq ? $freq : 'none',
			array(
				'none'    => __( 'No enviar', 'sw-pagos' ),
				'weekly'  => __( 'Semanal', 'sw-pagos' ),
				'monthly' => __( 'Mensual', 'sw-pagos' ),
			)
		);

		$this->solo_si( 'swpagos_report_freq', 'weekly' );
		echo '<p><strong>' . esc_html__( 'Qué días', 'sw-pagos' ) . '</strong></p>';

		foreach ( $this->dias() as $clave => $nombre ) {
			printf(
				'<label style="display:inline-block;margin:0 10px 6px 0"><input type="checkbox" name="swpagos_report_days[]" value="%s" %s> %s</label>',
				esc_attr( $clave ),
				checked( in_array( $clave, $dias, true ), true, false ),
				esc_html( $nombre )
			);
		}

		$this->fin_solo_si();

		$this->solo_si( 'swpagos_report_freq', 'monthly' );
		echo '<p><strong>' . esc_html__( 'Qué día del mes', 'sw-pagos' ) . '</strong></p>';
		printf(
			'<input type="number" name="swpagos_report_dom" min="1" max="31" value="%s" style="width:90px">',
			esc_attr( $this->leer( $post->ID, 'report_dom' ) )
		);
		$this->fin_solo_si();

		$this->solo_si( 'swpagos_report_freq', 'weekly', 'monthly' );

		$this->campo_texto(
			'swpagos_report_to',
			__( 'Enviar el informe a', 'sw-pagos' ),
			$this->leer( $post->ID, 'report_to' ),
			__( 'Separa varias direcciones con comas.', 'sw-pagos' ),
			'director@ejemplo.org'
		);

		if ( 'publish' === $post->post_status && '' !== $this->leer( $post->ID, 'report_to' ) ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p><p class="description">%s</p>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=swpagos_enviar_informe&plantilla=' . $post->ID ),
						'swpagos_informe_' . $post->ID
					)
				),
				esc_html__( 'Enviar informe ahora', 'sw-pagos' ),
				esc_html__( 'Usa la configuración ya guardada. Si acabas de cambiar algo, guarda primero.', 'sw-pagos' )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resultado = isset( $_GET['swpagos_informe'] ) ? sanitize_key( wp_unslash( $_GET['swpagos_informe'] ) ) : '';

		if ( 'enviado' === $resultado ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Informe enviado.', 'sw-pagos' ) . '</p></div>';
		} elseif ( 'fallido' === $resultado ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No se pudo enviar. Revisa el historial de entregas.', 'sw-pagos' ) . '</p></div>';
		}

		$this->fin_solo_si();
	}

	/**
	 * Guarda.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos.
	 * @return void
	 */
	public function guardar( int $post_id, array $datos ): void {
		$freq       = sanitize_text_field( (string) ( $datos['swpagos_report_freq'] ?? 'none' ) );
		$permitidas = array( 'none', 'weekly', 'monthly' );
		$freq       = in_array( $freq, $permitidas, true ) ? $freq : 'none';
		$this->escribir( $post_id, 'report_freq', 'none' === $freq ? '' : $freq );

		// Los días solo tienen sentido con su frecuencia: guardarlos siempre deja
		// configuraciones contradictorias que luego nadie sabe interpretar.
		if ( 'weekly' === $freq ) {
			$enviados = array_map( 'sanitize_text_field', (array) ( $datos['swpagos_report_days'] ?? array() ) );
			$validos  = array_values( array_intersect( $enviados, array_keys( $this->dias() ) ) );
			$this->escribir( $post_id, 'report_days', implode( ',', $validos ) );
		} else {
			$this->escribir( $post_id, 'report_days', '' );
		}

		if ( 'monthly' === $freq ) {
			$dia = (int) ( $datos['swpagos_report_dom'] ?? 0 );
			$this->escribir( $post_id, 'report_dom', ( $dia >= 1 && $dia <= 31 ) ? (string) $dia : '' );
		} else {
			$this->escribir( $post_id, 'report_dom', '' );
		}

		$this->escribir( $post_id, 'report_to', $this->correos_validos( (string) ( $datos['swpagos_report_to'] ?? '' ) ) );
	}
}
