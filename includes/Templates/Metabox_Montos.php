<?php
/**
 * Metabox: política de montos y frecuencias.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

use SWPagos\Core\Currency;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Define qué importes se aceptan y con qué frecuencia.
 *
 * Es la pieza que sostiene la validación del importe: lo que se declare aquí es lo que el
 * servidor permitirá cobrar, pase lo que pase en el navegador. Los importes se
 * escriben en unidad mayor y se guardan en unidad mínima.
 */
final class Metabox_Montos extends Metabox_Base {

	/**
	 * Identificador.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'montos';
	}

	/**
	 * Título.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return __( 'Montos y frecuencia', 'sw-pagos' );
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$this->intro(
			__( 'Cuánto se cobra y cada cuánto. Estos límites los aplica el servidor en cada envío, no el navegador, y son los que el formulario enseña debajo del campo de importe.', 'sw-pagos' )
		);

		$plantilla = Plantilla::por_id( $post->ID );
		$moneda    = null !== $plantilla ? $plantilla->moneda() : Plugin::instancia()->settings()->moneda_predeterminada();
		$modo      = null !== $plantilla ? $plantilla->modo_monto() : Plantilla::MODO_RANGO;
		$frecs     = null !== $plantilla ? $plantilla->frecuencias() : array( Plantilla::FREQ_UNICA );

		$this->campo_texto(
			'swpagos_currency',
			__( 'Moneda', 'sw-pagos' ),
			$moneda,
			__( 'Tres letras, como USD, GTQ o EUR. Debe estar habilitada en la cuenta de Stripe. Es la que el formulario muestra junto al importe.', 'sw-pagos' ),
			'USD'
		);

		$this->campo_select(
			'swpagos_amount_mode',
			__( 'Cómo se decide el importe', 'sw-pagos' ),
			$modo,
			array(
				Plantilla::MODO_FIJO  => __( 'Importe fijo: siempre el mismo', 'sw-pagos' ),
				Plantilla::MODO_LISTA => __( 'Lista cerrada: la persona elige entre varios', 'sw-pagos' ),
				Plantilla::MODO_RANGO => __( 'Rango: la persona escribe un importe entre un mínimo y un máximo', 'sw-pagos' ),
			),
			__( 'Debajo solo aparece lo que hace falta para la opción elegida. Lo que llegue del formulario se comprueba contra esto <strong>en el servidor</strong>: lo que quede fuera se rechaza antes de llegar a Stripe, aunque alguien manipule el campo desde su navegador.', 'sw-pagos' )
		);

		$this->solo_si( 'swpagos_amount_mode', Plantilla::MODO_FIJO );
		$this->campo_importe(
			'swpagos_amount_fixed',
			__( 'Importe fijo', 'sw-pagos' ),
			null !== $plantilla && $plantilla->monto_fijo() > 0 ? Currency::a_unidad_mayor( $plantilla->monto_fijo(), $moneda ) : '',
			__( 'Lo que se cobra siempre. El formulario no pregunta el importe.', 'sw-pagos' ),
			'250.00'
		);
		$this->fin_solo_si();

		$lista = '';
		if ( null !== $plantilla ) {
			$lista = implode(
				', ',
				array_map(
					static fn( int $m ): string => Currency::a_unidad_mayor( $m, $moneda ),
					$plantilla->montos_lista()
				)
			);
		}

		$this->solo_si( 'swpagos_amount_mode', Plantilla::MODO_LISTA );
		$this->campo_texto(
			'swpagos_amount_list',
			__( 'Importes permitidos', 'sw-pagos' ),
			$lista,
			__( 'Separados por comas. La persona solo puede elegir uno de estos.', 'sw-pagos' ),
			'100, 250, 500, 1000'
		);
		$this->fin_solo_si();

		$this->solo_si( 'swpagos_amount_mode', Plantilla::MODO_RANGO );

		echo '<div style="display:flex;gap:20px;flex-wrap:wrap">';

		echo '<div style="flex:1;min-width:220px">';
		$this->campo_importe(
			'swpagos_amount_min',
			__( 'Importe mínimo', 'sw-pagos' ),
			null !== $plantilla && $plantilla->monto_min() > 0 ? Currency::a_unidad_mayor( $plantilla->monto_min(), $moneda ) : '',
			'',
			'25.00'
		);
		echo '</div>';

		echo '<div style="flex:1;min-width:220px">';
		$this->campo_importe(
			'swpagos_amount_max',
			__( 'Importe máximo', 'sw-pagos' ),
			null !== $plantilla && $plantilla->monto_max() > 0 ? Currency::a_unidad_mayor( $plantilla->monto_max(), $moneda ) : '',
			'',
			'5000.00'
		);
		echo '</div>';

		echo '</div>';

		$this->ayuda( __( 'Dejar el máximo vacío significa sin tope, lo que no es recomendable: un tope protege de errores de tecleo.', 'sw-pagos' ) );

		$this->fin_solo_si();

		echo '<hr>';
		echo '<p><strong>' . esc_html__( 'Frecuencias que ofrece este formulario', 'sw-pagos' ) . '</strong></p>';

		printf(
			'<p><label><input type="checkbox" name="swpagos_freq[]" value="%s" %s> %s</label></p>',
			esc_attr( Plantilla::FREQ_UNICA ),
			checked( in_array( Plantilla::FREQ_UNICA, $frecs, true ), true, false ),
			esc_html__( 'Pago único', 'sw-pagos' )
		);

		printf(
			'<p><label><input type="checkbox" name="swpagos_freq[]" value="%s" %s> %s</label></p>',
			esc_attr( Plantilla::FREQ_MENSUAL ),
			checked( in_array( Plantilla::FREQ_MENSUAL, $frecs, true ), true, false ),
			esc_html__( 'Suscripción mensual', 'sw-pagos' )
		);

		$this->ayuda( __( 'Si marcas las dos, el formulario debe tener el campo de frecuencia para que la persona elija. Si no marcas ninguna, se asume pago único.', 'sw-pagos' ) );
	}

	/**
	 * Guarda.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos.
	 * @return void
	 */
	public function guardar( int $post_id, array $datos ): void {
		$moneda = Currency::normalizar( sanitize_text_field( (string) ( $datos['swpagos_currency'] ?? '' ) ) );

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $moneda ) ) {
			$moneda = Plugin::instancia()->settings()->moneda_predeterminada();
		}

		$this->escribir( $post_id, 'currency', $moneda );

		$modo       = sanitize_text_field( (string) ( $datos['swpagos_amount_mode'] ?? '' ) );
		$permitidos = array( Plantilla::MODO_FIJO, Plantilla::MODO_LISTA, Plantilla::MODO_RANGO );
		$this->escribir( $post_id, 'amount_mode', in_array( $modo, $permitidos, true ) ? $modo : Plantilla::MODO_RANGO );

		foreach ( array( 'amount_fixed', 'amount_min', 'amount_max' ) as $clave ) {
			$crudo = trim( (string) ( $datos[ 'swpagos_' . $clave ] ?? '' ) );
			$menor = '' === $crudo ? 0 : Currency::a_unidad_minima( $crudo, $moneda );
			$this->escribir( $post_id, $clave, $menor > 0 ? (string) $menor : '' );
		}

		$lista_crudo = (string) ( $datos['swpagos_amount_list'] ?? '' );
		$menores     = array();

		foreach ( explode( ',', $lista_crudo ) as $trozo ) {
			$trozo = trim( $trozo );
			if ( '' === $trozo ) {
				continue;
			}
			$menor = Currency::a_unidad_minima( $trozo, $moneda );
			if ( $menor > 0 ) {
				$menores[] = $menor;
			}
		}

		$menores = array_values( array_unique( $menores ) );
		sort( $menores );

		$this->escribir( $post_id, 'amount_list', implode( ',', $menores ) );

		$frecs      = (array) ( $datos['swpagos_freq'] ?? array() );
		$validas    = array_values(
			array_intersect(
				array_map( 'sanitize_text_field', $frecs ),
				array( Plantilla::FREQ_UNICA, Plantilla::FREQ_MENSUAL )
			)
		);
		$this->escribir( $post_id, 'frecuencias', implode( ',', empty( $validas ) ? array( Plantilla::FREQ_UNICA ) : $validas ) );
	}
}
