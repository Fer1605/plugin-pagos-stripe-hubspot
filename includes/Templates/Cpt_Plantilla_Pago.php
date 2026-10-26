<?php
/**
 * Tipo de contenido «Plantilla de pago».
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

use SWPagos\Core\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Registra el tipo de contenido y orquesta sus metabox.
 *
 * El guardado se centraliza aquí: un único nonce y una única comprobación de
 * capacidad para todos los metabox, en lugar de repetirlas en cada uno y
 * arriesgarse a olvidar una (RNF-006).
 */
final class Cpt_Plantilla_Pago {

	/**
	 * Nombre del campo de nonce.
	 *
	 * @var string
	 */
	private const NONCE = 'swpagos_plantilla_nonce';

	/**
	 * Acción del nonce.
	 *
	 * @var string
	 */
	private const ACCION_NONCE = 'swpagos_guardar_plantilla';

	/**
	 * Metabox registrados.
	 *
	 * @var Metabox_Base[]
	 */
	private array $metaboxes = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->metaboxes = array(
			// El editor grande de arriba es el cuerpo del correo, y WordPress lo
			// fija ahí: no se puede mover. Así que lo que configura ese correo va
			// pegado debajo, y solo después el resto. Separar el cuerpo de su
			// destinatario por tres cajas obliga a buscar.
			//
			// Después, el recorrido de un cobro: qué formulario atiende, cuánto
			// cobra, a dónde va quien paga, y qué se informa luego. HubSpot no
			// está aquí: se configura entero en el formulario, porque hay
			// formularios sin cobro que también envían y no tienen plantilla.
			new Metabox_Correo(),
			new Metabox_Correos_Suscripcion(),
			new Metabox_Formulario(),
			new Metabox_Montos(),
			new Metabox_Resultado(),
			new Metabox_Reporte(),
		);
	}

	/**
	 * Engancha el tipo de contenido.
	 *
	 * @return void
	 */
	public function registrar(): void {
		add_action( 'init', array( $this, 'registrar_cpt' ) );
		add_action( 'add_meta_boxes_' . Compat::CPT_PLANTILLA, array( $this, 'registrar_metaboxes' ) );
		add_action( 'edit_form_after_title', array( $this, 'ayuda_cuerpo' ) );

		( new Email_Preview() )->registrar();

		// El orden de las cajas lo marca el módulo, no lo que cada persona haya
		// arrastrado alguna vez: la secuencia cuenta el recorrido de un cobro y
		// desordenada deja de explicarse sola.
		add_filter( 'get_user_option_meta-box-order_' . Compat::CPT_PLANTILLA, array( $this, 'orden_metaboxes' ) );
		add_action( 'save_post_' . Compat::CPT_PLANTILLA, array( $this, 'guardar' ), 10, 2 );
		add_filter( 'manage_' . Compat::CPT_PLANTILLA . '_posts_columns', array( $this, 'columnas' ) );
		add_action( 'manage_' . Compat::CPT_PLANTILLA . '_posts_custom_column', array( $this, 'columna' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'texto_titulo' ), 10, 2 );

		Form_Index::arrancar();
	}

	/**
	 * Registra el tipo de contenido.
	 *
	 * @return void
	 */
	public function registrar_cpt(): void {
		$etiquetas = array(
			'name'               => __( 'Plantillas de pago', 'sw-pagos' ),
			'singular_name'      => __( 'Plantilla de pago', 'sw-pagos' ),
			'menu_name'          => __( 'Plantillas de pago', 'sw-pagos' ),
			'add_new'            => __( 'Añadir nueva', 'sw-pagos' ),
			'add_new_item'       => __( 'Añadir plantilla de pago', 'sw-pagos' ),
			'new_item'           => __( 'Nueva plantilla', 'sw-pagos' ),
			'edit_item'          => __( 'Editar plantilla', 'sw-pagos' ),
			'view_item'          => __( 'Ver plantilla', 'sw-pagos' ),
			'all_items'          => __( 'Plantillas de pago', 'sw-pagos' ),
			'search_items'       => __( 'Buscar plantillas', 'sw-pagos' ),
			'not_found'          => __( 'No hay plantillas todavía.', 'sw-pagos' ),
			'not_found_in_trash' => __( 'No hay plantillas en la papelera.', 'sw-pagos' ),
		);

		register_post_type(
			Compat::CPT_PLANTILLA,
			array(
				'labels'          => $etiquetas,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'swpagos-ajustes',
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title', 'editor', 'revisions' ),
				// Marca que permite a Core\Compat reconocer que el CPT es nuestro.
				Compat::MARCA_CPT => true,
			)
		);
	}

	/**
	 * Texto del campo de título.
	 *
	 * @param string   $texto Texto actual.
	 * @param \WP_Post $post  Post.
	 * @return string
	 */
	public function texto_titulo( $texto, $post ) {
		if ( $post instanceof \WP_Post && Compat::CPT_PLANTILLA === $post->post_type ) {
			return __( 'Nombre de la campaña, por ejemplo: Donación mensual 2026', 'sw-pagos' );
		}

		return $texto;
	}

	/**
	 * Orden fijo de las cajas de la plantilla.
	 *
	 * @param mixed $orden Orden guardado por la persona.
	 * @return array<string,string>
	 */
	public function orden_metaboxes( $orden ): array {
		$ids = array();

		foreach ( $this->metaboxes as $metabox ) {
			$ids[] = 'swpagos_' . $metabox->id();
		}

		return array(
			'normal'   => implode( ',', $ids ),
			'side'     => '',
			'advanced' => '',
		);
	}

	/**
	 * Registra los metabox.
	 *
	 * @return void
	 */
	public function registrar_metaboxes(): void {
		foreach ( $this->metaboxes as $metabox ) {
			add_meta_box(
				'swpagos_' . $metabox->id(),
				$metabox->titulo(),
				function ( $post ) use ( $metabox ) {
					$metabox->render( $post );
				},
				Compat::CPT_PLANTILLA,
				$metabox->contexto(),
				'default'
			);
		}

	}

	/**
	 * Rotula el editor principal, justo encima de él.
	 *
	 * Suelto y sin título, ese editor no dice qué es. La explicación tiene que
	 * estar donde se escribe, no en una caja más abajo.
	 *
	 * @param \WP_Post $post Entrada.
	 * @return void
	 */
	public function ayuda_cuerpo( $post ): void {
		if ( ! $post instanceof \WP_Post || Compat::CPT_PLANTILLA !== $post->post_type ) {
			return;
		}

		echo '<div style="margin:18px 0 6px;padding:12px 14px;background:#fff;border:1px solid #e6ebef;border-left:3px solid #0f6cbf;border-radius:6px">';
		echo '<p style="margin:0 0 4px;font-weight:600">' . esc_html__( 'Cuerpo del correo de confirmación', 'sw-pagos' ) . '</p>';
		echo '<p class="description" style="margin:0">' . wp_kses_post(
			__( 'Lo que escribas abajo es el correo que recibe quien paga, una vez confirmado el cobro. Admite marcadores como <code>[nombre]</code> o <code>[email]</code>, que se sustituyen por lo que la persona escribió en el formulario. Los datos de tarjeta nunca se sustituyen, aunque se nombren.', 'sw-pagos' )
		) . '</p>';
		echo '<p class="description" style="margin:6px 0 0">' . esc_html__(
			'Justo debajo del editor tienes a quién se envía, el asunto y el botón para ver cómo queda.',
			'sw-pagos'
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Guarda todos los metabox.
	 *
	 * @param int      $post_id Identificador.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function guardar( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) );

		if ( ! wp_verify_nonce( $nonce, self::ACCION_NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado arriba.
		$datos = wp_unslash( $_POST );

		foreach ( $this->metaboxes as $metabox ) {
			$metabox->guardar( (int) $post_id, (array) $datos );
		}
	}

	/**
	 * Imprime el nonce. Lo llama el primer metabox.
	 *
	 * @return void
	 */
	public static function imprimir_nonce(): void {
		wp_nonce_field( self::ACCION_NONCE, self::NONCE );
	}

	/**
	 * Columnas del listado.
	 *
	 * @param array<string,string> $columnas Columnas.
	 * @return array<string,string>
	 */
	public function columnas( $columnas ): array {
		$nuevas = array();

		foreach ( (array) $columnas as $clave => $titulo ) {
			$nuevas[ $clave ] = $titulo;

			if ( 'title' === $clave ) {
				$nuevas['swpagos_form']    = __( 'Formulario', 'sw-pagos' );
				$nuevas['swpagos_montos']  = __( 'Montos', 'sw-pagos' );
				$nuevas['swpagos_frec']    = __( 'Frecuencias', 'sw-pagos' );
				$nuevas['swpagos_hubspot'] = __( 'HubSpot', 'sw-pagos' );
			}
		}

		return $nuevas;
	}

	/**
	 * Contenido de las columnas propias.
	 *
	 * @param string $columna Columna.
	 * @param int    $post_id Identificador.
	 * @return void
	 */
	public function columna( $columna, $post_id ): void {
		$plantilla = Plantilla::por_id( (int) $post_id );

		if ( null === $plantilla ) {
			return;
		}

		switch ( $columna ) {
			case 'swpagos_form':
				$form = $plantilla->form_id();
				echo '' !== $form
					? '<code>' . esc_html( $form ) . '</code>'
					: '<span style="color:#b32d2e">' . esc_html__( 'sin asignar', 'sw-pagos' ) . '</span>';
				break;

			case 'swpagos_montos':
				echo esc_html( $this->resumen_montos( $plantilla ) );
				break;

			case 'swpagos_frec':
				$nombres = array(
					Plantilla::FREQ_UNICA   => __( 'única', 'sw-pagos' ),
					Plantilla::FREQ_MENSUAL => __( 'mensual', 'sw-pagos' ),
				);
				$lista   = array_map(
					static fn( string $f ): string => $nombres[ $f ] ?? $f,
					$plantilla->frecuencias()
				);
				echo esc_html( implode( ' + ', $lista ) );
				break;

			case 'swpagos_hubspot':
				// HubSpot se configura en el propio formulario, nunca aquí: hay
				// formularios sin cobro que también envían, y no tienen plantilla.
				echo self::envia_a_hubspot( $plantilla->form_id() )
					? esc_html__( 'configurado en el formulario', 'sw-pagos' )
					: '<span style="color:#646970">' . esc_html__( 'no envía', 'sw-pagos' ) . '</span>';
				break;
		}
	}

	/**
	 * Indica si el formulario envía a HubSpot, leyéndolo del propio formulario.
	 *
	 * @param string $form_id Identificador lógico del formulario.
	 * @return bool
	 */
	public static function envia_a_hubspot( string $form_id ): bool {
		$ajustes = \SWPagos\Elementor\Form_Locator::ajustes( $form_id );

		if ( null === $ajustes ) {
			return false;
		}

		// Lo decide el campo «HubSpot (configuración)» del propio formulario: si
		// tiene portal y formulario, el envío a HubSpot está puesto.
		foreach ( (array) ( $ajustes['form_fields'] ?? array() ) as $campo ) {
			if ( ! is_array( $campo ) || 'hubspot_config' !== ( $campo['field_type'] ?? '' ) ) {
				continue;
			}

			if ( '' !== trim( (string) ( $campo['hef_portal_id'] ?? '' ) )
				&& '' !== trim( (string) ( $campo['hef_form_id'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resumen legible de la política de montos.
	 *
	 * @param Plantilla $plantilla Plantilla.
	 * @return string
	 */
	private function resumen_montos( Plantilla $plantilla ): string {
		$moneda = $plantilla->moneda();

		switch ( $plantilla->modo_monto() ) {
			case Plantilla::MODO_FIJO:
				return \SWPagos\Core\Currency::formatear( $plantilla->monto_fijo(), $moneda );

			case Plantilla::MODO_LISTA:
				$lista = array_map(
					static fn( int $m ): string => \SWPagos\Core\Currency::a_unidad_mayor( $m, $moneda ),
					$plantilla->montos_lista()
				);
				return empty( $lista )
					? __( 'lista vacía', 'sw-pagos' )
					: implode( ' · ', $lista ) . ' ' . $moneda;

			default:
				$min = \SWPagos\Core\Currency::a_unidad_mayor( $plantilla->monto_min(), $moneda );
				$max = $plantilla->monto_max() > 0
					? \SWPagos\Core\Currency::a_unidad_mayor( $plantilla->monto_max(), $moneda )
					: '∞';
				return $min . ' – ' . $max . ' ' . $moneda;
		}
	}
}
