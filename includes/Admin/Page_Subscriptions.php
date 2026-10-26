<?php
/**
 * Pantalla de suscripciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin;

use SWPagos\Admin\Tables\Subscriptions_Table;
use SWPagos\Domain\Subscription;
use SWPagos\Domain\Subscription_Repository;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Stripe\Subscription_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Listado de cobros recurrentes, con cancelación y sincronización.
 */
final class Page_Subscriptions extends Page_Base {

	/**
	 * Identificador.
	 *
	 * @var string
	 */
	public const SLUG = 'swpagos-suscripciones';

	/**
	 * Identificador de la pantalla.
	 *
	 * @return string
	 */
	protected function slug(): string {
		return self::SLUG;
	}

	/**
	 * Engancha las acciones.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'admin_post_swpagos_cancelar_suscripcion', array( $this, 'cancelar' ) );
		add_action( 'admin_post_swpagos_sincronizar_suscripcion', array( $this, 'sincronizar' ) );
	}

	/**
	 * Pinta la pantalla.
	 *
	 * @return void
	 */
	public function renderizar(): void {
		$this->exigir_permisos();

		echo '<div class="wrap swpagos-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Suscripciones', 'sw-pagos' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->avisos();

		$repo = new Subscription_Repository();

		// Las métricas se cuentan por modo: sumar prueba con producción daría un
		// ingreso recurrente falso. Pero enseñar producción en blanco cuando todo
		// lo que existe está en prueba parece un fallo, así que el resumen muestra
		// el modo que tiene datos, y siempre dice cuál está mostrando.
		$filtro = $this->filtro( 'modo' );
		$otros  = array();

		if ( in_array( $filtro, array( 'live', 'test' ), true ) ) {
			$modo = $filtro;
		} else {
			$modo = $repo->total_por_modo( 'live' ) > 0 || 0 === $repo->total_por_modo( 'test' )
				? 'live'
				: 'test';
		}

		$contrario = 'live' === $modo ? 'test' : 'live';
		$hay_otras = $repo->total_por_modo( $contrario );

		$metricas = $repo->metricas( gmdate( 'Y-m-01 00:00:00' ), current_time( 'mysql' ), $modo );

		$etiquetas = array(
			'live' => __( 'producción', 'sw-pagos' ),
			'test' => __( 'prueba', 'sw-pagos' ),
		);

		printf( '<p class="swpagos-pista" style="margin:0 0 6px">' );
		printf(
			/* translators: %s: modo, producción o prueba. */
			esc_html__( 'Resumen de las suscripciones en %s.', 'sw-pagos' ),
			'<strong>' . esc_html( $etiquetas[ $modo ] ) . '</strong>'
		);

		if ( $hay_otras > 0 ) {
			echo ' ';
			printf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => self::SLUG,
							'modo' => $contrario,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html(
					sprintf(
						/* translators: 1: número de suscripciones, 2: modo. */
						_n( 'Hay %1$d en %2$s: verla.', 'Hay %1$d en %2$s: verlas.', $hay_otras, 'sw-pagos' ),
						$hay_otras,
						$etiquetas[ $contrario ]
					)
				)
			);
		}

		echo '</p>';

		echo '<div class="swpagos-metricas">';
		$this->metrica( __( 'Activas', 'sw-pagos' ), (string) $metricas['activas'] );
		$this->metrica( __( 'En impago', 'sw-pagos' ), (string) $metricas['impago'] );
		$this->metrica(
			__( 'Ingreso recurrente', 'sw-pagos' ),
			\SWPagos\Core\Currency::formatear( $metricas['recurrente_mensual'], $metricas['moneda'] )
		);
		$this->metrica( __( 'Altas este mes', 'sw-pagos' ), (string) $metricas['altas_periodo'] );
		$this->metrica( __( 'Bajas este mes', 'sw-pagos' ), (string) $metricas['canceladas_periodo'] );
		echo '</div>';

		$tabla = new Subscriptions_Table( self::SLUG );
		$tabla->prepare_items();
		$tabla->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		$tabla->search_box( __( 'Buscar por correo, nombre o referencia', 'sw-pagos' ), 'swpagos-susc' );
		$tabla->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Cancela una suscripción.
	 *
	 * @return void
	 */
	public function cancelar(): void {
		$suscripcion = $this->suscripcion_de_la_peticion();

		$resultado = ( new Subscription_Service() )->cancelar( $suscripcion, true );

		$this->volver( $resultado['ok'] ? 'ok' : 'error', $resultado['mensaje'] );
	}

	/**
	 * Sincroniza una suscripción con Stripe.
	 *
	 * @return void
	 */
	public function sincronizar(): void {
		$suscripcion = $this->suscripcion_de_la_peticion();

		$ok = ( new Subscription_Service() )->sincronizar( $suscripcion );

		$this->volver(
			$ok ? 'ok' : 'error',
			$ok
				? __( 'Suscripción sincronizada con Stripe.', 'sw-pagos' )
				: __( 'No se pudo sincronizar. Revisa las credenciales y el registro.', 'sw-pagos' )
		);
	}

	/**
	 * Resuelve y valida la suscripción de la petición.
	 *
	 * @return Subscription
	 */
	private function suscripcion_de_la_peticion(): Subscription {
		$this->exigir_permisos();

		$uuid = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		check_admin_referer( 'swpagos_suscripcion_' . $uuid );

		$suscripcion = ( new Subscription_Repository() )->por_uuid( $uuid );

		if ( null === $suscripcion ) {
			wp_die( esc_html__( 'No existe esa suscripción.', 'sw-pagos' ) );
		}

		return $suscripcion;
	}

	/**
	 * Vuelve al listado con un aviso.
	 *
	 * @param string $tipo    ok|error.
	 * @param string $mensaje Mensaje.
	 * @return void
	 */
	private function volver( string $tipo, string $mensaje ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::SLUG,
					'swpagos_aviso'  => $tipo,
					'swpagos_texto'  => rawurlencode( $mensaje ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Pinta el aviso de la acción anterior.
	 *
	 * @return void
	 */
	private function avisos(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tipo = isset( $_GET['swpagos_aviso'] ) ? sanitize_key( wp_unslash( $_GET['swpagos_aviso'] ) ) : '';

		if ( '' === $tipo ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$texto = isset( $_GET['swpagos_texto'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['swpagos_texto'] ) ) ) : '';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'ok' === $tipo ? 'success' : 'error',
			esc_html( $texto )
		);
	}

	/**
	 * Pinta una métrica.
	 *
	 * @param string $etiqueta Etiqueta.
	 * @param string $valor    Valor.
	 * @return void
	 */
	private function metrica( string $etiqueta, string $valor ): void {
		printf(
			'<div class="swpagos-metrica"><span class="swpagos-metrica__valor">%s</span><span class="swpagos-metrica__etiqueta">%s</span></div>',
			esc_html( $valor ),
			esc_html( $etiqueta )
		);
	}

	/**
	 * Nombre legible de un estado.
	 *
	 * @param string $estado Estado.
	 * @return string
	 */
	private function nombre_estado( string $estado ): string {
		$nombres = array(
			Subscription::ACTIVA    => __( 'activa', 'sw-pagos' ),
			Subscription::IMPAGO    => __( 'en impago', 'sw-pagos' ),
			Subscription::PAUSADA   => __( 'pausada', 'sw-pagos' ),
			Subscription::CANCELADA => __( 'cancelada', 'sw-pagos' ),
		);

		return $nombres[ $estado ] ?? $estado;
	}
}
