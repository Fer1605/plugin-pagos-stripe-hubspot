<?php
/**
 * Entidad «Plantilla de pago».
 *
 * @package SWPagos
 */

namespace SWPagos\Templates;

use SWPagos\Core\Compat;
use SWPagos\Core\Currency;
use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura tipada de la configuración de un formulario.
 *
 * Los metabox son interfaz; esta clase es el dominio. La política de montos, las
 * frecuencias admitidas y el destino de HubSpot se consultan siempre por aquí, de
 * modo que la validación del servidor no dependa de cómo esté
 * pintado un formulario de administración.
 *
 * Los importes se guardan **en unidad mínima**. Se convierten una sola vez, al
 * guardar, y nunca se vuelve a interpretar texto escrito por una persona.
 */
final class Plantilla {

	public const MODO_FIJO  = 'fixed';
	public const MODO_LISTA = 'list';
	public const MODO_RANGO = 'range';

	public const FREQ_UNICA   = 'single';
	public const FREQ_MENSUAL = 'month';

	/**
	 * Identificador del post.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Constructor.
	 *
	 * @param int $id Identificador del post.
	 */
	private function __construct( int $id ) {
		$this->id = $id;
	}

	/**
	 * Carga por identificador de post.
	 *
	 * @param int $id Identificador.
	 * @return Plantilla|null
	 */
	public static function por_id( int $id ): ?Plantilla {
		if ( $id <= 0 || Compat::CPT_PLANTILLA !== get_post_type( $id ) ) {
			return null;
		}
		return new self( $id );
	}

	/**
	 * Carga la plantilla asociada a un formulario de Elementor.
	 *
	 * @param string $form_id Identificador lógico del formulario.
	 * @return Plantilla|null
	 */
	public static function por_formulario( string $form_id ): ?Plantilla {
		$id = Form_Index::plantilla_de( $form_id );
		return $id > 0 ? self::por_id( $id ) : null;
	}

	/**
	 * Identificador del post.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Título de la plantilla.
	 *
	 * @return string
	 */
	public function titulo(): string {
		return (string) get_the_title( $this->id );
	}

	/**
	 * Formulario de Elementor al que aplica.
	 *
	 * @return string
	 */
	public function form_id(): string {
		return $this->meta( 'form_id' );
	}

	// ------------------------------------------------------------------
	// Correo
	// ------------------------------------------------------------------

	/**
	 * Destinatario del correo de confirmación. Admite marcadores `[campo]`.
	 *
	 * @return string
	 */
	public function email_to(): string {
		return $this->meta( 'email_to' );
	}

	/**
	 * Asunto del correo. Admite marcadores.
	 *
	 * @return string
	 */
	public function email_subject(): string {
		return $this->meta( 'email_subject' );
	}

	/**
	 * Copias ocultas, ya validadas como direcciones.
	 *
	 * @return string[]
	 */
	public function email_bcc(): array {
		return $this->lista_correos( $this->meta( 'email_bcc' ) );
	}

	/**
	 * Cuerpo del correo de confirmación: es el contenido del post.
	 *
	 * @return string
	 */
	public function cuerpo(): string {
		$post = get_post( $this->id );
		return $post instanceof \WP_Post ? (string) $post->post_content : '';
	}

	/**
	 * Cuerpo de un correo de suscripción.
	 *
	 * @param string $tipo recibo|fallo|cancelacion.
	 * @return string
	 */
	public function email_suscripcion( string $tipo ): string {
		$permitidos = array( 'recibo', 'fallo', 'cancelacion' );
		return in_array( $tipo, $permitidos, true ) ? $this->meta( 'email_' . $tipo ) : '';
	}

	// ------------------------------------------------------------------
	// Páginas de resultado
	// ------------------------------------------------------------------

	/**
	 * URL a la que redirigir tras un cobro confirmado.
	 *
	 * @return string
	 */
	public function url_exito(): string {
		return $this->meta( 'url_exito' );
	}

	/**
	 * URL a la que redirigir tras un cobro fallido.
	 *
	 * @return string
	 */
	public function url_fallo(): string {
		return $this->meta( 'url_fallo' );
	}

	/**
	 * Texto de la página de agradecimiento.
	 *
	 * @return string
	 */
	public function texto_exito(): string {
		return $this->meta( 'texto_exito' );
	}

	/**
	 * Texto de la página de rechazo.
	 *
	 * @return string
	 */
	public function texto_fallo(): string {
		return $this->meta( 'texto_fallo' );
	}

	// ------------------------------------------------------------------
	// Moneda, montos y frecuencias
	// ------------------------------------------------------------------

	/**
	 * Moneda de este formulario.
	 *
	 * @return string
	 */
	public function moneda(): string {
		$moneda = Currency::normalizar( $this->meta( 'currency' ) );
		return 3 === strlen( $moneda ) ? $moneda : Plugin::instancia()->settings()->moneda_predeterminada();
	}

	/**
	 * Modo de la política de montos.
	 *
	 * @return string fixed|list|range
	 */
	public function modo_monto(): string {
		$modo = $this->meta( 'amount_mode' );
		return in_array( $modo, array( self::MODO_FIJO, self::MODO_LISTA, self::MODO_RANGO ), true )
			? $modo
			: self::MODO_RANGO;
	}

	/**
	 * Importe fijo, en unidad mínima.
	 *
	 * @return int
	 */
	public function monto_fijo(): int {
		return (int) $this->meta( 'amount_fixed' );
	}

	/**
	 * Importes permitidos, en unidad mínima.
	 *
	 * @return int[]
	 */
	public function montos_lista(): array {
		$crudo = $this->meta( 'amount_list' );

		if ( '' === $crudo ) {
			return array();
		}

		$valores = array_map( 'intval', array_filter( explode( ',', $crudo ), 'strlen' ) );

		return array_values( array_unique( array_filter( $valores, static fn( int $v ): bool => $v > 0 ) ) );
	}

	/**
	 * Importe mínimo del rango, en unidad mínima.
	 *
	 * @return int
	 */
	public function monto_min(): int {
		return (int) $this->meta( 'amount_min' );
	}

	/**
	 * Importe máximo del rango, en unidad mínima. Cero significa sin tope.
	 *
	 * @return int
	 */
	public function monto_max(): int {
		return (int) $this->meta( 'amount_max' );
	}

	/**
	 * Frecuencias habilitadas en este formulario.
	 *
	 * @return string[]
	 */
	public function frecuencias(): array {
		$crudo = $this->meta( 'frecuencias' );

		if ( '' === $crudo ) {
			return array( self::FREQ_UNICA );
		}

		$valores = array_intersect(
			array_map( 'trim', explode( ',', $crudo ) ),
			array( self::FREQ_UNICA, self::FREQ_MENSUAL )
		);

		return empty( $valores ) ? array( self::FREQ_UNICA ) : array_values( $valores );
	}

	/**
	 * Indica si el formulario admite una frecuencia.
	 *
	 * @param string $frecuencia single|month.
	 * @return bool
	 */
	public function admite_frecuencia( string $frecuencia ): bool {
		return in_array( $frecuencia, $this->frecuencias(), true );
	}

	/**
	 * Indica si el formulario ofrece a la persona elegir entre única y mensual.
	 *
	 * @return bool
	 */
	public function es_mixto(): bool {
		return count( $this->frecuencias() ) > 1;
	}

	// ------------------------------------------------------------------
	// Informes
	// ------------------------------------------------------------------

	/**
	 * Frecuencia del informe.
	 *
	 * @return string none|weekly|monthly
	 */
	public function report_freq(): string {
		$freq = $this->meta( 'report_freq' );
		return in_array( $freq, array( 'weekly', 'monthly' ), true ) ? $freq : 'none';
	}

	/**
	 * Días configurados del informe.
	 *
	 * @return string[]
	 */
	public function report_days(): array {
		$crudo = $this->meta( 'report_days' );
		return '' === $crudo ? array() : array_map( 'trim', explode( ',', $crudo ) );
	}

	/**
	 * Destinatarios del informe.
	 *
	 * @return string[]
	 */
	public function report_to(): array {
		return $this->lista_correos( $this->meta( 'report_to' ) );
	}

	// ------------------------------------------------------------------
	// Utilidades
	// ------------------------------------------------------------------

	/**
	 * Lee un valor de meta con el prefijo del módulo.
	 *
	 * @param string $clave Clave sin prefijo.
	 * @return string
	 */
	private function meta( string $clave ): string {
		return trim( (string) get_post_meta( $this->id, '_swpagos_' . $clave, true ) );
	}

	/**
	 * Convierte una cadena separada por comas en direcciones válidas.
	 *
	 * @param string $crudo Cadena.
	 * @return string[]
	 */
	private function lista_correos( string $crudo ): array {
		if ( '' === $crudo ) {
			return array();
		}

		$lista = array_map( 'trim', explode( ',', $crudo ) );

		return array_values( array_unique( array_filter( $lista, 'is_email' ) ) );
	}
}
