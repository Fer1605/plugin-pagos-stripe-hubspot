<?php
/**
 * Definición de las variantes de formulario de la biblioteca.
 *
 * @package SWPagos
 */

namespace SWPagos\Library;

use SWPagos\Core\Compat;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Construye el árbol de elementos de Elementor de cada variante.
 *
 * Esto es lo que resuelve el problema que motivó la biblioteca: armar a mano una
 * decena de campos con identificadores exactos, donde un solo error hace que el
 * cobro falle en el momento del envío. Lo que sale de aquí viene con todos los
 * identificadores correctos, la acción tras el envío ya elegida y en modo prueba,
 * y el campo de configuración de HubSpot puesto, con una fila de mapeo por cada
 * campo que el formulario tiene de verdad.
 *
 * Lo único que se deja en blanco a propósito es la URL del formulario de HubSpot:
 * al pegarla y escanear, el propio módulo de HubSpot rellena portal, formulario y
 * el nombre de cada propiedad.
 */
final class Variants {

	public const CONTACTO     = 'contacto';
	public const PAGO_LIBRE   = 'pago-libre';
	public const PAGO_FIJO    = 'pago-fijo';
	public const SUSCRIPCION  = 'suscripcion';
	public const MIXTO        = 'mixto';

	/**
	 * Catálogo de variantes, con su descripción para la pantalla.
	 *
	 * @return array<string,array{titulo:string,descripcion:string,para:string,pago:bool,frecuencias:string[]}>
	 */
	public static function catalogo(): array {
		return array(
			self::CONTACTO    => array(
				'titulo'      => __( 'Contacto', 'sw-pagos' ),
				'descripcion' => __( 'Nombre, correo, teléfono, asunto y mensaje. No cobra nada.', 'sw-pagos' ),
				'para'        => __( 'Un «Contáctanos» o «Quiero información».', 'sw-pagos' ),
				'pago'        => false,
				'frecuencias' => array(),
			),
			self::PAGO_LIBRE  => array(
				'titulo'      => __( 'Donación, la persona decide cuánto', 'sw-pagos' ),
				'descripcion' => __( 'Nombre, correo y una casilla donde escribe el importe. Se cobra una sola vez.', 'sw-pagos' ),
				'para'        => __( 'Una donación abierta: «aporta lo que puedas».', 'sw-pagos' ),
				'pago'        => true,
				'frecuencias' => array( 'single' ),
			),
			self::PAGO_FIJO   => array(
				'titulo'      => __( 'Pago de importe fijo', 'sw-pagos' ),
				'descripcion' => __( 'Nombre y correo. El importe no se pregunta: lo pone la plantilla de pago.', 'sw-pagos' ),
				'para'        => __( 'Una inscripción, una cuota o una entrada con precio cerrado.', 'sw-pagos' ),
				'pago'        => true,
				'frecuencias' => array( 'single' ),
			),
			self::SUSCRIPCION => array(
				'titulo'      => __( 'Donación mensual', 'sw-pagos' ),
				'descripcion' => __( 'Como la donación abierta, pero se cobra cada mes hasta que la persona la cancele.', 'sw-pagos' ),
				'para'        => __( 'Padrinos o socios que aportan todos los meses.', 'sw-pagos' ),
				'pago'        => true,
				'frecuencias' => array( 'month' ),
			),
			self::MIXTO       => array(
				'titulo'      => __( 'Donación: una vez o cada mes', 'sw-pagos' ),
				'descripcion' => __( 'Añade dos botones para que la persona elija si aporta una sola vez o cada mes.', 'sw-pagos' ),
				'para'        => __( 'La página de donar principal, si no quieres dos formularios.', 'sw-pagos' ),
				'pago'        => true,
				'frecuencias' => array( 'single', 'month' ),
			),
		);
	}

	/**
	 * Indica si una clave de variante existe.
	 *
	 * @param string $clave Clave.
	 * @return bool
	 */
	public static function existe( string $clave ): bool {
		return isset( self::catalogo()[ $clave ] );
	}

	/**
	 * Árbol de elementos de Elementor de una variante.
	 *
	 * @param string $clave Clave de la variante.
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function elementos( string $clave ): ?array {
		if ( ! self::existe( $clave ) ) {
			return null;
		}

		$catalogo = self::catalogo();
		$info     = $catalogo[ $clave ];

		$widget = array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'form',
			'settings'   => self::ajustes_formulario( $clave, $info ),
			'elements'   => array(),
		);

		return array(
			array(
				'id'       => self::id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'boxed',
					'flex_gap'      => array(
						'unit'     => 'px',
						'size'     => 20,
						'column'   => '20',
						'row'      => '20',
						'isLinked' => true,
					),
				),
				'elements' => array( $widget ),
			),
		);
	}

	/**
	 * Ajustes del widget de formulario.
	 *
	 * @param string                                                                   $clave Clave de la variante.
	 * @param array{titulo:string,descripcion:string,para:string,pago:bool,frecuencias:string[]} $info  Información.
	 * @return array<string,mixed>
	 */
	private static function ajustes_formulario( string $clave, array $info ): array {
		$form_id = 'swpagos-' . $clave;

		$ajustes = array(
			'form_name'      => $info['titulo'],
			'form_id'        => $form_id,
			'form_fields'    => self::campos( $clave, $info, $form_id ),
			'button_text'    => $info['pago'] ? __( 'Continuar al pago', 'sw-pagos' ) : __( 'Enviar', 'sw-pagos' ),
			'button_size'    => 'md',
			// «save-to-database» es la acción de Elementor que guarda el envío en
			// Envíos. Sin ella no queda rastro del formulario aunque el cobro
			// salga bien, y el módulo de HubSpot tampoco puede enlazar su
			// registro con el envío.
			// En los formularios con cobro NO va la acción «hubspot»: el módulo de
			// HubSpot registra el envío entero y lo manda este módulo cuando Stripe
			// confirma el cobro. En los que no cobran, se manda al enviarse.
			'submit_actions' => $info['pago']
				? array( 'save-to-database', Compat::ACCIONES_ELEMENTOR['stripe'] )
				: array( 'save-to-database', 'hubspot' ),
		);

		if ( $info['pago'] ) {
			// Modo prueba: nunca se entrega una variante lista para cobrar de verdad.
			$ajustes[ Compat::CONTROL_MODO ]     = '';
			$ajustes[ Compat::CONTROL_PASARELA ] = 'stripe';
		}

		return $ajustes;
	}

	/**
	 * Campos del formulario de una variante.
	 *
	 * @param string                                                                   $clave   Clave.
	 * @param array{titulo:string,descripcion:string,para:string,pago:bool,frecuencias:string[]} $info    Información.
	 * @param string                                                                   $form_id Identificador del formulario.
	 * @return array<int,array<string,mixed>>
	 */
	private static function campos( string $clave, array $info, string $form_id ): array {
		$campos = array(
			self::campo( 'nombre', 'text', __( 'Nombre completo', 'sw-pagos' ), array( 'required' => 'true' ) ),
			self::campo( 'email', 'email', __( 'Correo electrónico', 'sw-pagos' ), array( 'required' => 'true' ) ),
		);

		if ( self::CONTACTO === $clave ) {
			$campos[] = self::campo( 'telefono', 'tel', __( 'Teléfono', 'sw-pagos' ), array( 'width' => '50' ) );
			$campos[] = self::campo( 'asunto', 'text', __( 'Asunto', 'sw-pagos' ), array( 'width' => '50' ) );
			$campos[] = self::campo( 'mensaje', 'textarea', __( 'Mensaje', 'sw-pagos' ), array( 'required' => 'true' ) );
		}

		if ( $info['pago'] ) {
			$campos = array_merge( $campos, self::campos_de_pago( $clave, $info ) );
		}

		// Campos técnicos: los rellena el script del módulo, la persona no los ve.
		$campos[] = self::campo( Compat::CAMPOS_TECNICOS['transaccion'], 'hidden', __( 'Transacción', 'sw-pagos' ) );
		$campos[] = self::campo( Compat::CAMPOS_TECNICOS['formulario'], 'hidden', __( 'Formulario', 'sw-pagos' ), array( 'field_value' => $form_id ) );

		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $utm ) {
			$campos[] = self::campo( $utm, 'hidden', strtoupper( str_replace( '_', ' ', $utm ) ) );
		}

		// Las filas de mapeo se construyen con los campos que esta variante tiene
		// de verdad: ofrecer una fila para un campo inexistente invita a mapearlo
		// y a que luego HubSpot reciba un valor vacío sin explicación.
		$visibles = array();
		foreach ( $campos as $campo_existente ) {
			if ( 'hidden' !== $campo_existente['field_type'] ) {
				$visibles[] = $campo_existente['custom_id'];
			}
		}
		if ( self::PAGO_FIJO === $clave ) {
			$visibles[] = Compat::CAMPO_IMPORTE;
		}

		$campos[] = self::campo_hubspot( $visibles );

		return $campos;
	}

	/**
	 * Campos propios de las variantes con cobro.
	 *
	 * @param string                                                                   $clave Clave.
	 * @param array{titulo:string,descripcion:string,para:string,pago:bool,frecuencias:string[]} $info  Información.
	 * @return array<int,array<string,mixed>>
	 */
	private static function campos_de_pago( string $clave, array $info ): array {
		$campos = array();

		if ( self::PAGO_FIJO === $clave ) {
			// El importe no es editable: lo decide la plantilla en el servidor.
			$campos[] = self::campo(
				Compat::CAMPO_IMPORTE,
				'hidden',
				__( 'Importe', 'sw-pagos' )
			);
		} else {
			$campos[] = self::campo(
				Compat::CAMPO_IMPORTE,
				'number',
				__( 'Importe de tu aporte', 'sw-pagos' ),
				array(
					'required'    => 'true',
					'placeholder' => '250',
					// Elementor lee el tope de field_min; 'min' lo ignora.
					'field_min'   => '1',
				)
			);
		}

		if ( count( $info['frecuencias'] ) > 1 ) {
			$campos[] = self::campo(
				Compat::CAMPO_FRECUENCIA,
				'radio',
				__( '¿Con qué frecuencia?', 'sw-pagos' ),
				array(
					'required'      => 'true',
					'field_options' => __( "Una vez|single\nCada mes|month", 'sw-pagos' ),
					'inline_list'   => 'yes',
				)
			);
		} elseif ( array( 'month' ) === $info['frecuencias'] ) {
			$campos[] = self::campo(
				Compat::CAMPO_FRECUENCIA,
				'hidden',
				__( 'Frecuencia', 'sw-pagos' ),
				array( 'field_value' => 'month' )
			);
		}

		return $campos;
	}

	/**
	 * Campo «HubSpot (configuración)», con una fila de mapeo por campo real.
	 *
	 * El lado de HubSpot se deja vacío a propósito: es lo único que
	 * cambia en cada portal. Se rellena solo al pegar la URL del formulario de
	 * HubSpot en este mismo campo y pulsar «Escanear».
	 *
	 * @param string[] $campos_form Identificadores de los campos de esta variante.
	 * @return array<string,mixed>
	 */
	private static function campo_hubspot( array $campos_form ): array {
		$filas = array();

		foreach ( $campos_form as $campo_form ) {
			$filas[] = array(
				'field'    => $campo_form,
				'property' => '',
				'object'   => '0-1',
			);
		}

		return self::campo(
			'hubspot',
			'hubspot_config',
			__( 'HubSpot (configuración)', 'sw-pagos' ),
			array(
				'hef_form_url'           => '',
				'hef_portal_id'          => '',
				'hef_form_id'            => '',
				'hef_region'             => 'na1',
				'hef_mapping'            => (string) wp_json_encode( $filas ),
				'hef_scan_add'           => 'required',
				'hef_scan_sync_required' => 'yes',
				'hef_source_tag'         => 'wp-elementor',
				'hef_source_mode'        => 'both',
			)
		);
	}

	/**
	 * Construye un campo de formulario de Elementor.
	 *
	 * @param string              $id       Identificador del campo.
	 * @param string              $tipo     Tipo de campo.
	 * @param string              $etiqueta Etiqueta.
	 * @param array<string,mixed> $extra    Ajustes adicionales.
	 * @return array<string,mixed>
	 */
	private static function campo( string $id, string $tipo, string $etiqueta, array $extra = array() ): array {
		return array_merge(
			array(
				'_id'         => self::id(),
				'custom_id'   => $id,
				'field_type'  => $tipo,
				'field_label' => $etiqueta,
				'width'       => '100',
			),
			$extra
		);
	}

	/**
	 * Identificador corto al estilo de Elementor.
	 *
	 * @return string
	 */
	private static function id(): string {
		return substr( str_replace( '.', '', uniqid( '', true ) ), -7 );
	}
}
