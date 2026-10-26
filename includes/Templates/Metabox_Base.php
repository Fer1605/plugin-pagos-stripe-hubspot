<?php
/**
 * Base común de los metabox de la plantilla de pago.
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Pintado y guardado de campos, con el prefijo de meta en un solo sitio.
 */
abstract class Metabox_Base {

	/**
	 * Prefijo de todas las claves de meta.
	 *
	 * @var string
	 */
	protected const PREFIJO = '_swpagos_';

	/**
	 * Identificador del metabox.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Título visible.
	 *
	 * @return string
	 */
	abstract public function titulo(): string;

	/**
	 * Pinta el contenido.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	abstract public function render( \WP_Post $post ): void;

	/**
	 * Guarda los campos.
	 *
	 * El array recibido ya viene sin barras invertidas; la validación de nonce y
	 * de capacidad la hizo quien llama.
	 *
	 * @param int                 $post_id Plantilla.
	 * @param array<string,mixed> $datos   Datos enviados.
	 * @return void
	 */
	abstract public function guardar( int $post_id, array $datos ): void;

	/**
	 * Posición del metabox.
	 *
	 * @return string
	 */
	public function contexto(): string {
		return 'normal';
	}

	/**
	 * Lee una meta.
	 *
	 * @param int    $post_id Plantilla.
	 * @param string $clave   Clave sin prefijo.
	 * @return string
	 */
	protected function leer( int $post_id, string $clave ): string {
		return (string) get_post_meta( $post_id, self::PREFIJO . $clave, true );
	}

	/**
	 * Escribe una meta, o la borra si el valor queda vacío.
	 *
	 * @param int    $post_id Plantilla.
	 * @param string $clave   Clave sin prefijo.
	 * @param string $valor   Valor.
	 * @return void
	 */
	protected function escribir( int $post_id, string $clave, string $valor ): void {
		if ( '' === $valor ) {
			delete_post_meta( $post_id, self::PREFIJO . $clave );
			return;
		}

		update_post_meta( $post_id, self::PREFIJO . $clave, $valor );
	}

	/**
	 * Filtra una cadena de correos separados por comas.
	 *
	 * @param string $crudo Cadena.
	 * @return string
	 */
	protected function correos_validos( string $crudo ): string {
		$lista = array_filter( array_map( 'trim', explode( ',', $crudo ) ), 'is_email' );
		return implode( ',', array_unique( $lista ) );
	}

	/**
	 * Campo de texto de una línea.
	 *
	 * @param string $nombre      Nombre del campo.
	 * @param string $etiqueta    Etiqueta.
	 * @param string $valor       Valor actual.
	 * @param string $ayuda       Texto de ayuda.
	 * @param string $marcador    Texto de ejemplo.
	 * @param string $tipo        Tipo de input.
	 * @return void
	 */
	protected function campo_texto( string $nombre, string $etiqueta, string $valor, string $ayuda = '', string $marcador = '', string $tipo = 'text' ): void {
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br>'
			. '<input type="%3$s" id="%1$s" name="%1$s" value="%4$s" placeholder="%5$s" style="width:100%%;max-width:620px"></p>',
			esc_attr( $nombre ),
			esc_html( $etiqueta ),
			esc_attr( $tipo ),
			esc_attr( $valor ),
			esc_attr( $marcador )
		);

		$this->ayuda( $ayuda );
	}

	/**
	 * Campo numérico que no admite valores negativos.
	 *
	 * El guardado ya descarta lo que no sea mayor que cero, pero dejar teclear un
	 * importe negativo que luego desaparece en silencio no se explica solo.
	 *
	 * @param string $nombre   Nombre del campo.
	 * @param string $etiqueta Etiqueta.
	 * @param string $valor    Valor actual.
	 * @param string $ayuda    Texto de ayuda.
	 * @param string $marcador Texto de ejemplo.
	 * @return void
	 */
	protected function campo_importe( string $nombre, string $etiqueta, string $valor, string $ayuda = '', string $marcador = '' ): void {
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br>'
			. '<input type="number" min="0" step="any" inputmode="decimal" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" style="width:100%%;max-width:620px"></p>',
			esc_attr( $nombre ),
			esc_html( $etiqueta ),
			esc_attr( $valor ),
			esc_attr( $marcador )
		);

		$this->ayuda( $ayuda );
	}

	/**
	 * Campo de selección.
	 *
	 * @param string                $nombre   Nombre del campo.
	 * @param string                $etiqueta Etiqueta.
	 * @param string                $valor    Valor actual.
	 * @param array<string,string>  $opciones Opciones.
	 * @param string                $ayuda    Texto de ayuda.
	 * @return void
	 */
	protected function campo_select( string $nombre, string $etiqueta, string $valor, array $opciones, string $ayuda = '' ): void {
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br><select id="%1$s" name="%1$s">',
			esc_attr( $nombre ),
			esc_html( $etiqueta )
		);

		foreach ( $opciones as $clave => $texto ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $clave ),
				selected( $valor, $clave, false ),
				esc_html( $texto )
			);
		}

		echo '</select></p>';

		$this->ayuda( $ayuda );
	}

	/**
	 * Casilla de verificación.
	 *
	 * @param string $nombre   Nombre del campo.
	 * @param string $etiqueta Etiqueta.
	 * @param bool   $marcada  Si está marcada.
	 * @param string $ayuda    Texto de ayuda.
	 * @return void
	 */
	protected function campo_checkbox( string $nombre, string $etiqueta, bool $marcada, string $ayuda = '' ): void {
		printf(
			'<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>',
			esc_attr( $nombre ),
			checked( $marcada, true, false ),
			esc_html( $etiqueta )
		);

		$this->ayuda( $ayuda );
	}

	/**
	 * Editor enriquecido.
	 *
	 * @param string $nombre   Nombre del campo.
	 * @param string $etiqueta Etiqueta.
	 * @param string $valor    Contenido.
	 * @param string $ayuda    Texto de ayuda.
	 * @return void
	 */
	protected function campo_editor( string $nombre, string $etiqueta, string $valor, string $ayuda = '' ): void {
		printf( '<p style="margin-bottom:4px"><strong>%s</strong></p>', esc_html( $etiqueta ) );

		// La ayuda va ANTES del editor: debajo se montaba encima del propio
		// editor, porque wp_editor trae su propia caja y su asa de redimensión.
		if ( '' !== $ayuda ) {
			printf( '<p class="description" style="margin:0 0 8px">%s</p>', wp_kses_post( $ayuda ) );
		}

		wp_editor(
			$valor,
			str_replace( array( '[', ']', '_' ), '', $nombre ) . 'ed',
			array(
				'textarea_name' => $nombre,
				'textarea_rows' => 8,
				'media_buttons' => true,
				'teeny'         => false,
				'editor_height' => 180,
			)
		);

		echo '<div style="margin-bottom:20px"></div>';
	}

	/**
	 * Encabezado de una sección dentro de un metabox.
	 *
	 * @param string $titulo Título.
	 * @param string $texto  Explicación de una línea.
	 * @return void
	 */
	protected function seccion( string $titulo, string $texto = '' ): void {
		printf(
			'<p style="margin:24px 0 2px;font-size:13px;font-weight:600;color:#1f2933">%s</p>',
			esc_html( $titulo )
		);

		if ( '' !== $texto ) {
			printf( '<p class="description" style="margin:0 0 14px">%s</p>', wp_kses_post( $texto ) );
		}
	}

	/**
	 * Explicación de una línea al principio de un metabox.
	 *
	 * @param string $texto Texto.
	 * @return void
	 */
	protected function intro( string $texto ): void {
		printf(
			'<p class="description" style="margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid #e6ebef">%s</p>',
			wp_kses_post( $texto )
		);
	}

	/**
	 * Lista de marcadores que se pueden usar en los textos de esta plantilla.
	 *
	 * Se arma leyendo los campos reales del formulario asociado, no una lista
	 * fija: la pregunta «¿puedo poner aquí el campo tal de mi formulario?» se
	 * responde sola si la pantalla enseña cuáles hay.
	 *
	 * @param \WP_Post $post Plantilla.
	 * @return void
	 */
	protected function marcadores_disponibles( \WP_Post $post ): void {
		$plantilla = Plantilla::por_id( $post->ID );
		$propios   = array(
			'nombre'     => __( 'nombre de quien pagó', 'sw-pagos' ),
			'email'      => __( 'su correo', 'sw-pagos' ),
			'importe'    => __( 'importe cobrado, ya formateado', 'sw-pagos' ),
			'moneda'     => __( 'moneda', 'sw-pagos' ),
			'referencia' => __( 'referencia de la transacción', 'sw-pagos' ),
			'fecha'      => __( 'fecha', 'sw-pagos' ),
		);

		if ( null !== $plantilla && $plantilla->admite_frecuencia( Plantilla::FREQ_MENSUAL ) ) {
			$propios['importe_mensual']        = __( 'importe de la suscripción', 'sw-pagos' );
			$propios['proximo_cobro']          = __( 'fecha del próximo cobro', 'sw-pagos' );
			$propios['enlace_actualizar_pago'] = __( 'enlace para cambiar la tarjeta', 'sw-pagos' );
		}

		$del_formulario = array();

		if ( null !== $plantilla && '' !== $plantilla->form_id() ) {
			$ajustes = \SWPagos\Elementor\Form_Locator::ajustes( $plantilla->form_id() );

			foreach ( (array) ( $ajustes['form_fields'] ?? array() ) as $campo ) {
				$id   = (string) ( $campo['custom_id'] ?? '' );
				$tipo = (string) ( $campo['field_type'] ?? '' );

				if ( '' === $id || in_array( $tipo, array( 'hidden', 'html', 'hubspot_config', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot' ), true ) ) {
					continue;
				}

				if ( isset( $propios[ $id ] ) ) {
					continue;
				}

				$etiqueta = trim( (string) ( $campo['field_label'] ?? '' ) );
				$del_formulario[ $id ] = '' !== $etiqueta ? $etiqueta : $id;
			}
		}

		echo '<details class="swpagos-marcadores"><summary><strong>';
		esc_html_e( '¿Qué puedo escribir entre corchetes?', 'sw-pagos' );
		echo '</strong></summary>';

		echo '<p class="description">';
		esc_html_e( 'En el asunto, en el cuerpo del correo y en los textos de las páginas de resultado, lo que escribas entre corchetes se sustituye por el dato real.', 'sw-pagos' );
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Del cobro', 'sw-pagos' ) . '</strong></p><ul class="swpagos-lista-props">';
		foreach ( $propios as $clave => $que ) {
			printf( '<li><code>[%s]</code> — %s</li>', esc_html( $clave ), esc_html( $que ) );
		}
		echo '</ul>';

		echo '<p><strong>' . esc_html__( 'De tu formulario', 'sw-pagos' ) . '</strong></p>';

		if ( array() === $del_formulario ) {
			echo '<p class="description">';
			esc_html_e( 'Enlaza un formulario en «Formulario asociado» y aquí aparecerán sus campos, uno a uno.', 'sw-pagos' );
			echo '</p>';
		} else {
			echo '<ul class="swpagos-lista-props">';
			foreach ( $del_formulario as $clave => $etiqueta ) {
				printf( '<li><code>[%s]</code> — %s</li>', esc_html( $clave ), esc_html( $etiqueta ) );
			}
			echo '</ul>';
			echo '<p class="description">';
			esc_html_e( 'Sirve cualquier campo del formulario, no solo estos: si añades uno nuevo, su identificador funciona igual. Los campos ocultos y los de UTM no se guardan como marcador.', 'sw-pagos' );
			echo '</p>';
		}

		echo '<p class="description">';
		esc_html_e( 'Un marcador que no exista se queda escrito tal cual, para que se vea que algo falta en lugar de dejar un hueco mudo. Y los nombres de tarjeta, CVV o contraseña nunca se sustituyen, aunque el formulario los tuviera.', 'sw-pagos' );
		echo '</p>';

		echo '</details>';
	}

	/**
	 * Abre un bloque que solo se muestra para ciertos valores de otro campo.
	 *
	 * Enseñar los seis campos de importe a la vez cuando solo tres hacen algo es
	 * lo que vuelve ilegible una plantilla: la mitad de lo que se ve no aplica.
	 * El servidor sigue leyendo únicamente lo que corresponde al modo elegido,
	 * así que esto es ayuda visual, no una validación.
	 *
	 * @param string $campo   Identificador del control que decide.
	 * @param string ...$para Valores para los que el bloque se muestra.
	 * @return void
	 */
	protected function solo_si( string $campo, string ...$para ): void {
		$this->script_condicional();

		printf(
			'<div class="swpagos-cond" data-campo="%s" data-valores="%s">',
			esc_attr( $campo ),
			esc_attr( implode( '|', $para ) )
		);
	}

	/**
	 * Cierra el bloque condicional.
	 *
	 * @return void
	 */
	protected function fin_solo_si(): void {
		echo '</div>';
	}

	/**
	 * Indica si el script condicional ya se imprimió en esta pantalla.
	 *
	 * @var bool
	 */
	private static bool $script_puesto = false;

	/**
	 * Imprime una sola vez el script que muestra y oculta los bloques.
	 *
	 * @return void
	 */
	private function script_condicional(): void {
		if ( self::$script_puesto ) {
			return;
		}

		self::$script_puesto = true;
		?>
		<script>
		( function () {
			function aplicar() {
				document.querySelectorAll( '.swpagos-cond' ).forEach( function ( bloque ) {
					var campo = document.getElementById( bloque.dataset.campo );

					if ( ! campo ) {
						return;
					}

					var valores = ( bloque.dataset.valores || '' ).split( '|' );
					bloque.hidden = valores.indexOf( campo.value ) === -1;
				} );
			}

			document.addEventListener( 'change', aplicar );
			document.addEventListener( 'DOMContentLoaded', aplicar );
			aplicar();
		}() );
		</script>
		<?php
	}

	/**
	 * Texto de ayuda bajo un campo.
	 *
	 * @param string $texto Texto. Se omite si está vacío.
	 * @return void
	 */
	protected function ayuda( string $texto ): void {
		if ( '' === $texto ) {
			return;
		}

		printf( '<p class="description" style="margin-top:-8px;margin-bottom:16px">%s</p>', wp_kses_post( $texto ) );
	}
}
