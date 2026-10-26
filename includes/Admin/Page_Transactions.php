<?php
/**
 * Pantalla de transacciones.
 *
 * @package SWPagos
 */

namespace SWPagos\Admin;

use SWPagos\Admin\Tables\Transactions_Table;
use SWPagos\Core\Currency;
use SWPagos\Domain\Delivery_Log;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Listado de cobros, con filtros, detalle y exportación.
 */
final class Page_Transactions extends Page_Base {

	/**
	 * Identificador.
	 *
	 * @var string
	 */
	public const SLUG = 'swpagos-transacciones';

	/**
	 * Identificador de la pantalla.
	 *
	 * @return string
	 */
	protected function slug(): string {
		return self::SLUG;
	}

	/**
	 * Engancha la exportación.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'admin_post_swpagos_exportar_transacciones', array( $this, 'exportar' ) );
	}

	/**
	 * Pinta la pantalla.
	 *
	 * @return void
	 */
	public function renderizar(): void {
		$this->exigir_permisos();

		$uuid = $this->filtro( 'ver' );

		echo '<div class="wrap swpagos-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Transacciones', 'sw-pagos' ) . '</h1>';

		if ( '' !== $uuid ) {
			echo '<hr class="wp-header-end">';
			$this->detalle( $uuid );
			echo '</div>';
			return;
		}

		$tabla = new Transactions_Table( self::SLUG );
		$tabla->prepare_items();

		printf(
			' <a class="page-title-action" href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array_merge( array( 'action' => 'swpagos_exportar_transacciones' ), $this->filtros_actuales() ),
						admin_url( 'admin-post.php' )
					),
					'swpagos_exportar'
				)
			),
			esc_html__( 'Exportar a CSV', 'sw-pagos' )
		);

		echo '<hr class="wp-header-end">';

		$tabla->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		$tabla->search_box( __( 'Buscar por correo, nombre o referencia', 'sw-pagos' ), 'swpagos-txn' );
		$tabla->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Ficha de una transacción.
	 *
	 * @param string $uuid Identificador público.
	 * @return void
	 */
	private function detalle( string $uuid ): void {
		$t = ( new Transaction_Repository() )->por_uuid( $uuid );

		printf(
			'<p><a href="%s">&laquo; %s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Volver al listado', 'sw-pagos' )
		);

		if ( null === $t ) {
			$this->vacio( __( 'No existe esa transacción.', 'sw-pagos' ) );
			return;
		}

		echo '<table class="widefat striped" style="max-width:760px"><tbody>';

		$campos = array(
			__( 'Referencia', 'sw-pagos' )   => $t->uuid(),
			__( 'Estado', 'sw-pagos' )       => $this->nombre_estado( $t->estado() ),
			__( 'Importe', 'sw-pagos' )      => $t->importe_legible(),
			__( 'Tipo', 'sw-pagos' )         => $this->nombre_tipo( $t->tipo() ),
			__( 'Modo', 'sw-pagos' )         => $t->modo(),
			__( 'Persona', 'sw-pagos' )      => trim( $t->nombre() . ' · ' . $t->email(), ' ·' ),
			__( 'Formulario', 'sw-pagos' )   => $t->form_id(),
			__( 'Creada', 'sw-pagos' )       => $this->fecha( (string) $t->valor( 'created_at', '' ) ),
			__( 'Pagada', 'sw-pagos' )       => $this->fecha( (string) $t->valor( 'paid_at', '' ) ),
			__( 'Sesión de pago', 'sw-pagos' ) => (string) $t->valor( 'stripe_session_id', '—' ),
			__( 'Intención de pago', 'sw-pagos' ) => (string) $t->valor( 'stripe_payment_intent_id', '—' ),
			__( 'Contacto en HubSpot', 'sw-pagos' ) => (string) $t->valor( 'hubspot_contact_id', '—' ),
			__( 'Negocio en HubSpot', 'sw-pagos' )  => (string) $t->valor( 'hubspot_deal_id', '—' ),
		);

		$motivo = (string) $t->valor( 'failure_message', '' );

		if ( '' !== $motivo ) {
			$campos[ __( 'Motivo del fallo', 'sw-pagos' ) ] = $motivo;
		}

		foreach ( $campos as $etiqueta => $valor ) {
			printf(
				'<tr><th scope="row" style="width:220px">%s</th><td>%s</td></tr>',
				esc_html( $etiqueta ),
				esc_html( '' !== (string) $valor ? (string) $valor : '—' )
			);
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Historial de estados', 'sw-pagos' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $t->historial() as $paso ) {
			printf(
				'<tr><td style="width:220px">%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $this->fecha( (string) ( $paso['cuando'] ?? '' ) ) ),
				esc_html( $this->nombre_estado( (string) ( $paso['estado'] ?? '' ) ) ),
				esc_html( (string) ( $paso['origen'] ?? '' ) )
			);
		}
		echo '</tbody></table>';

		$entregas = Delivery_Log::ultimas( array(), 200 );
		$suyas    = array_filter( $entregas, static fn( $f ): bool => (int) $f->transaction_id === $t->id() );

		echo '<h2>' . esc_html__( 'Entregas de esta transacción', 'sw-pagos' ) . '</h2>';

		if ( empty( $suyas ) ) {
			$this->vacio( __( 'Todavía no hay entregas registradas.', 'sw-pagos' ) );
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $suyas as $f ) {
			printf(
				'<tr><td style="width:150px">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $this->fecha( (string) $f->created_at ) ),
				esc_html( (string) $f->channel ),
				wp_kses_post( $this->chip( (string) $f->status, (string) $f->status ) ),
				esc_html( mb_substr( (string) $f->message, 0, 120 ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Exporta el listado filtrado.
	 *
	 * @return void
	 */
	public function exportar(): void {
		$this->exigir_permisos();
		check_admin_referer( 'swpagos_exportar' );

		$filas = ( new Transaction_Repository() )->buscar( $this->filtros_actuales(), 5000, 0 );

		$datos = array();

		foreach ( $filas as $t ) {
			$datos[] = array(
				(string) $t->valor( 'created_at', '' ),
				$t->uuid(),
				$t->estado(),
				$t->modo(),
				$t->tipo(),
				Currency::a_unidad_mayor( $t->importe(), $t->moneda() ),
				$t->moneda(),
				Currency::a_unidad_mayor( (int) $t->valor( 'amount_refunded_minor', 0 ), $t->moneda() ),
				$t->nombre(),
				$t->email(),
				$t->form_id(),
				(string) $t->valor( 'billing_period', '' ),
				(string) $t->valor( 'stripe_payment_intent_id', '' ),
				(string) $t->valor( 'hubspot_deal_id', '' ),
			);
		}

		$this->descargar_csv(
			'swpagos-transacciones-' . gmdate( 'Y-m-d' ) . '.csv',
			array(
				__( 'Fecha', 'sw-pagos' ),
				__( 'Referencia', 'sw-pagos' ),
				__( 'Estado', 'sw-pagos' ),
				__( 'Modo', 'sw-pagos' ),
				__( 'Tipo', 'sw-pagos' ),
				__( 'Importe', 'sw-pagos' ),
				__( 'Moneda', 'sw-pagos' ),
				__( 'Devuelto', 'sw-pagos' ),
				__( 'Nombre', 'sw-pagos' ),
				__( 'Correo', 'sw-pagos' ),
				__( 'Formulario', 'sw-pagos' ),
				__( 'Período', 'sw-pagos' ),
				__( 'Referencia de Stripe', 'sw-pagos' ),
				__( 'Negocio de HubSpot', 'sw-pagos' ),
			),
			$datos
		);
	}

	/**
	 * Filtros activos.
	 *
	 * @return array<string,string>
	 */
	private function filtros_actuales(): array {
		return array_filter(
			array(
				'estado'  => $this->filtro( 'estado' ),
				'modo'    => $this->filtro( 'modo' ),
				'tipo'    => $this->filtro( 'tipo' ),
				'form_id' => $this->filtro( 'form_id' ),
				'buscar'  => $this->filtro( 'buscar' ),
			)
		);
	}

	/**
	 * Estados disponibles.
	 *
	 * @return array<string,string>
	 */
	private function estados(): array {
		$opciones = array();

		foreach ( Transaction::estados() as $estado ) {
			$opciones[ $estado ] = $this->nombre_estado( $estado );
		}

		return $opciones;
	}

	/**
	 * Nombre legible de un estado.
	 *
	 * @param string $estado Estado.
	 * @return string
	 */
	private function nombre_estado( string $estado ): string {
		$nombres = array(
			Transaction::INICIADA    => __( 'iniciada', 'sw-pagos' ),
			Transaction::RECHAZADA   => __( 'rechazada', 'sw-pagos' ),
			Transaction::PENDIENTE   => __( 'pendiente', 'sw-pagos' ),
			Transaction::PROCESANDO  => __( 'procesando', 'sw-pagos' ),
			Transaction::PAGADA      => __( 'pagada', 'sw-pagos' ),
			Transaction::FALLIDA     => __( 'fallida', 'sw-pagos' ),
			Transaction::CANCELADA   => __( 'cancelada', 'sw-pagos' ),
			Transaction::REEMBOLSADA => __( 'reembolsada', 'sw-pagos' ),
		);

		return $nombres[ $estado ] ?? $estado;
	}

	/**
	 * Nombre legible de un tipo.
	 *
	 * @param string $tipo Tipo.
	 * @return string
	 */
	private function nombre_tipo( string $tipo ): string {
		$nombres = array(
			Transaction::TIPO_UNICO      => __( 'Pago único', 'sw-pagos' ),
			Transaction::TIPO_ALTA_SUSC  => __( 'Alta mensual', 'sw-pagos' ),
			Transaction::TIPO_RENOVACION => __( 'Renovación', 'sw-pagos' ),
		);

		return $nombres[ $tipo ] ?? $tipo;
	}
}
