<?php
/**
 * Convivencia con otros módulos de pago instalados en el mismo sitio.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Guardas de convivencia: convive, no desplaza.
 *
 * El módulo de referencia declara su capa común en el ámbito global y se engancha
 * a los mismos puntos de Elementor que este módulo. Los nombres que reclamamos
 * están centralizados aquí para que haya una única fuente de verdad, y se
 * verifican en ejecución: si algún día otro plugin reclama uno, el panel lo dice
 * en lugar de dejar un fallo silencioso que dependa del orden de carga.
 */
final class Compat {

	/**
	 * Nombres internos de las acciones de Elementor que registra el módulo.
	 *
	 * Se usan nombres con prefijo, no 'Stripe' y 'HubSpot' como proponía el plan:
	 * el módulo de referencia registra 'Pagos' y 'Hubspot', y 'HubSpot' se
	 * diferenciaría de 'Hubspot' solo en una mayúscula. Elementor indexa las
	 * acciones por este nombre; una diferencia de caja es demasiado poco margen
	 * para algo cuyo fallo es que una acción sustituya a la otra. La etiqueta que
	 * ve el administrador sí es legible.
	 *
	 * @var array<string,string>
	 */
	public const ACCIONES_ELEMENTOR = array(
		'stripe' => 'swpagos_stripe',
	);

	/**
	 * Campo de configuración de HubSpot del módulo incorporado.
	 *
	 * El nombre es suyo, no nuestro: se declara aquí porque las variantes de la
	 * biblioteca lo traen puesto y, si divergiera, el formulario copiado
	 * parecería correcto sin mapear nada.
	 *
	 * @var string
	 */
	public const CAMPO_HUBSPOT = 'hubspot_config';

	/**
	 * Control del conmutador producción/prueba de la acción de cobro.
	 *
	 * @var string
	 */
	public const CONTROL_MODO = 'swpagos_live';

	/**
	 * Control del selector de pasarela.
	 *
	 * @var string
	 */
	public const CONTROL_PASARELA = 'swpagos_pasarela';

	/**
	 * Identificador del campo de frecuencia en el formulario.
	 *
	 * @var string
	 */
	public const CAMPO_FRECUENCIA = 'swpagos_frecuencia';

	/**
	 * Identificador del campo de importe.
	 *
	 * Sin prefijo a propósito: es un campo que la persona rellena y cuyo nombre
	 * se ve en el editor. No lo reclama ningún otro módulo del sitio.
	 *
	 * @var string
	 */
	public const CAMPO_IMPORTE = 'amount';

	/**
	 * Tipo de contenido de las plantillas de pago.
	 *
	 * @var string
	 */
	public const CPT_PLANTILLA = 'swpagos_plantilla';

	/**
	 * Shortcodes de las páginas de resultado.
	 *
	 * @var string[]
	 */
	public const SHORTCODES = array(
		'swpagos_pago_exitoso',
		'swpagos_pago_rechazado',
	);

	/**
	 * Espacio de nombres de las rutas de API.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'swpagos/v1';

	/**
	 * Identificadores de los campos técnicos del formulario.
	 *
	 * Distintos de los del módulo de referencia (timestamp_pe, form_id,
	 * estadodepago) para que los scripts de los dos no se peleen por el mismo
	 * campo. Cierra el riesgo de convivencia más probable de todos.
	 *
	 * @var array<string,string>
	 */
	public const CAMPOS_TECNICOS = array(
		'transaccion' => 'swpagos_txn',
		'formulario'  => 'swpagos_form',
	);

	/**
	 * Carpetas de plugins de pago conocidos.
	 *
	 * Se compara por carpeta y no por ruta completa del archivo principal: el
	 * mismo módulo de referencia está instalado como `sw-integral-payments` en un
	 * entorno y como `pagos-elementor` en otro, con archivos principales distintos.
	 * Detectarlo por ruta exacta fallaba justo en el entorno donde se verifica la
	 * convivencia.
	 *
	 * @var array<string,string>
	 */
	private const CARPETAS_CONOCIDAS = array(
		'sw-integral-payments' => 'Integración de pagos para Elementor',
		'pagos-elementor'      => 'Integración de pagos para Elementor',
		'ebi-pay'              => 'Ebi Pay',
		'credomatic_elementor' => 'Credomatic para Elementor',
		'woocommerce'          => 'WooCommerce',
	);

	/**
	 * Rastros del módulo de referencia que no dependen de cómo se llame su carpeta.
	 *
	 * Se comprueban después de que los demás plugins hayan cargado.
	 *
	 * @var array<string,string[]>
	 */
	private const FIRMAS_REFERENCIA = array(
		'funciones' => array( 'bipay_settings_page', 'register_new_form_fields' ),
		'cpt'       => array( 'corres_ebi' ),
	);

	/**
	 * Marca que el módulo pone en su propio tipo de contenido para reconocerlo.
	 *
	 * Quien registre el CPT en la fase de plantillas debe incluir
	 * `'swpagos' => true` en los argumentos, o la verificación de conflictos dará
	 * un falso positivo.
	 *
	 * @var string
	 */
	public const MARCA_CPT = 'swpagos';

	/**
	 * Engancha la verificación de convivencia.
	 *
	 * @return void
	 */
	public static function arrancar(): void {
		add_action( 'admin_notices', array( self::class, 'avisar_conflictos' ) );
	}

	/**
	 * Plugins de pago conocidos que están activos ahora mismo.
	 *
	 * @return array<string,string> Carpeta => nombre legible.
	 */
	public static function modulos_presentes(): array {
		$presentes = array();

		foreach ( self::carpetas_activas() as $carpeta ) {
			if ( isset( self::CARPETAS_CONOCIDAS[ $carpeta ] ) ) {
				$presentes[ $carpeta ] = self::CARPETAS_CONOCIDAS[ $carpeta ];
			}
		}

		return $presentes;
	}

	/**
	 * Indica si el módulo de referencia está activo, se llame como se llame su
	 * carpeta.
	 *
	 * @return bool
	 */
	public static function modulo_referencia_activo(): bool {
		foreach ( self::carpetas_activas() as $carpeta ) {
			if ( in_array( $carpeta, array( 'sw-integral-payments', 'pagos-elementor' ), true ) ) {
				return true;
			}
		}

		return ! empty( self::firmas_referencia_detectadas() );
	}

	/**
	 * Rastros del módulo de referencia encontrados en este sitio.
	 *
	 * @return string[]
	 */
	public static function firmas_referencia_detectadas(): array {
		$encontradas = array();

		foreach ( self::FIRMAS_REFERENCIA['funciones'] as $funcion ) {
			if ( function_exists( $funcion ) ) {
				$encontradas[] = $funcion . '()';
			}
		}

		foreach ( self::FIRMAS_REFERENCIA['cpt'] as $cpt ) {
			if ( post_type_exists( $cpt ) ) {
				$encontradas[] = $cpt;
			}
		}

		return $encontradas;
	}

	/**
	 * Carpetas de todos los plugins activos, en el sitio y en la red.
	 *
	 * @return string[]
	 */
	private static function carpetas_activas(): array {
		$rutas = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$rutas = array_merge( $rutas, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$carpetas = array();

		foreach ( $rutas as $ruta ) {
			$carpeta = dirname( (string) $ruta );
			if ( '.' !== $carpeta && '' !== $carpeta ) {
				$carpetas[] = $carpeta;
			}
		}

		return array_values( array_unique( $carpetas ) );
	}

	/**
	 * Busca conflictos reales de nombres con lo que ya hay registrado en el sitio.
	 *
	 * @return array<int,array{tipo:string,nombre:string,detalle:string}>
	 */
	public static function conflictos(): array {
		$conflictos = array();

		// Tipos de contenido.
		if ( post_type_exists( self::CPT_PLANTILLA ) && ! self::cpt_es_nuestro() ) {
			$conflictos[] = array(
				'tipo'    => 'post_type',
				'nombre'  => self::CPT_PLANTILLA,
				'detalle' => __( 'Otro plugin registró este tipo de contenido antes del módulo.', 'sw-pagos' ),
			);
		}

		// Shortcodes.
		global $shortcode_tags;
		foreach ( self::SHORTCODES as $etiqueta ) {
			if ( ! isset( $shortcode_tags[ $etiqueta ] ) ) {
				continue;
			}
			if ( ! self::callback_es_nuestro( $shortcode_tags[ $etiqueta ] ) ) {
				$conflictos[] = array(
					'tipo'    => 'shortcode',
					'nombre'  => '[' . $etiqueta . ']',
					'detalle' => __( 'Otro plugin registró este shortcode.', 'sw-pagos' ),
				);
			}
		}

		// Constantes con nuestro prefijo definidas por terceros.
		foreach ( array( 'SWPAGOS_VERSION', 'SWPAGOS_DIR', 'SWPAGOS_URL' ) as $constante ) {
			if ( ! defined( $constante ) ) {
				$conflictos[] = array(
					'tipo'    => 'constante',
					'nombre'  => $constante,
					'detalle' => __( 'La constante no está definida: el módulo no cargó por completo.', 'sw-pagos' ),
				);
			}
		}

		// Tablas: que existan es requisito, no conflicto, pero su ausencia se ve igual aquí.
		foreach ( Activator::estado_tablas() as $nombre => $existe ) {
			if ( ! $existe ) {
				$conflictos[] = array(
					'tipo'    => 'tabla',
					'nombre'  => Activator::tabla( $nombre ),
					'detalle' => __( 'La tabla no existe. Desactiva y vuelve a activar el módulo.', 'sw-pagos' ),
				);
			}
		}

		return $conflictos;
	}

	/**
	 * Comprueba si el CPT registrado es el nuestro.
	 *
	 * @return bool
	 */
	private static function cpt_es_nuestro(): bool {
		$objeto = get_post_type_object( self::CPT_PLANTILLA );

		$marca = self::MARCA_CPT;

		return $objeto instanceof \WP_Post_Type
			&& isset( $objeto->$marca )
			&& true === $objeto->$marca;
	}

	/**
	 * Comprueba si un callback pertenece al módulo.
	 *
	 * @param mixed $callback Callback registrado.
	 * @return bool
	 */
	private static function callback_es_nuestro( $callback ): bool {
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$clase = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return str_starts_with( $clase, 'SWPagos\\' );
		}

		if ( is_string( $callback ) ) {
			return str_starts_with( $callback, 'SWPagos\\' );
		}

		if ( $callback instanceof \Closure ) {
			$reflexion = new \ReflectionFunction( $callback );
			$clase     = $reflexion->getClosureScopeClass();
			return $clase && str_starts_with( $clase->getName(), 'SWPagos\\' );
		}

		return false;
	}

	/**
	 * Carga defensiva de una librería de terceros.
	 *
	 * Si otro plugin ya la cargó, se reutiliza en lugar de redeclararla, que es
	 * lo que provoca el error fatal de clase ya declarada.
	 *
	 * @param string $clase Clase que la librería debe proveer.
	 * @param string $ruta  Ruta absoluta al autoload de nuestra copia.
	 * @return bool Si la clase está disponible tras la llamada.
	 */
	public static function cargar_libreria( string $clase, string $ruta ): bool {
		if ( class_exists( $clase, false ) ) {
			return true;
		}

		if ( is_readable( $ruta ) ) {
			require_once $ruta;
		}

		return class_exists( $clase );
	}

	/**
	 * Muestra un aviso solo si hay un conflicto real.
	 *
	 * Que otro módulo de pago esté activo no es un conflicto: es el escenario
	 * previsto. Avisar de ello sería ruido.
	 *
	 * @return void
	 */
	public static function avisar_conflictos(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$conflictos = self::conflictos();

		if ( empty( $conflictos ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		esc_html_e( 'Pagos Stripe + HubSpot: conflictos detectados', 'sw-pagos' );
		echo '</strong></p><ul style="list-style:disc;margin-left:20px;">';

		foreach ( $conflictos as $conflicto ) {
			printf(
				'<li><code>%s</code> (%s) — %s</li>',
				esc_html( $conflicto['nombre'] ),
				esc_html( $conflicto['tipo'] ),
				esc_html( $conflicto['detalle'] )
			);
		}

		echo '</ul></div>';
	}
}
