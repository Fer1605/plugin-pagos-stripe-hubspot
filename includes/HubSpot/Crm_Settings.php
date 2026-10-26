<?php
/**
 * Ajustes del registro de negocios en el CRM de HubSpot.
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que necesita saber el módulo para anotar cada cobro en el CRM.
 *
 * Vive en una sola opción y se configura en la pantalla «Ajustes de HubSpot»,
 * junto al resto de HubSpot: el embudo y la etapa son del portal, no del
 * formulario, así que repetirlos en cada formulario sería pedir cuatro veces
 * el mismo dato. Lo que sí cambia por formulario —Portal ID y
 * Form ID— sigue estando en el propio formulario.
 */
final class Crm_Settings {

	/**
	 * Opción que los guarda.
	 *
	 * @var string
	 */
	public const OPCION = 'swpagos_crm';

	/**
	 * Transitorio con los embudos leídos del portal.
	 *
	 * @var string
	 */
	private const CACHE_EMBUDOS = 'swpagos_crm_embudos';

	/**
	 * Valores por omisión.
	 *
	 * @return array{activo:int,pipeline:string,dealstage:string,nombre:string}
	 */
	public static function defectos(): array {
		return array(
			'activo'    => 1,
			'pipeline'  => '',
			'dealstage' => '',
			'nombre'    => __( 'Donación de [nombre]', 'sw-pagos' ),
		);
	}

	/**
	 * Todos los ajustes, normalizados.
	 *
	 * @return array{activo:int,pipeline:string,dealstage:string,nombre:string}
	 */
	public static function todo(): array {
		$guardado = get_option( self::OPCION, array() );

		if ( ! is_array( $guardado ) ) {
			$guardado = array();
		}

		/** @var array{activo:int,pipeline:string,dealstage:string,nombre:string} $valores */
		$valores = wp_parse_args( $guardado, self::defectos() );

		return $valores;
	}

	/**
	 * Indica si se deben crear negocios.
	 *
	 * @return bool
	 */
	public static function activo(): bool {
		return 1 === (int) self::todo()['activo'];
	}

	/**
	 * Embudo elegido.
	 *
	 * @return string
	 */
	public static function pipeline(): string {
		return (string) self::todo()['pipeline'];
	}

	/**
	 * Etapa elegida.
	 *
	 * @return string
	 */
	public static function etapa(): string {
		return (string) self::todo()['dealstage'];
	}

	/**
	 * Patrón del nombre del negocio.
	 *
	 * @return string
	 */
	public static function patron_nombre(): string {
		return (string) self::todo()['nombre'];
	}

	/**
	 * Guarda los ajustes saneados.
	 *
	 * @param array<string,mixed> $crudo Datos del formulario.
	 * @return void
	 */
	public static function guardar( array $crudo ): void {
		$limpio = array(
			'activo'    => empty( $crudo['activo'] ) ? 0 : 1,
			'pipeline'  => sanitize_text_field( (string) ( $crudo['pipeline'] ?? '' ) ),
			'dealstage' => sanitize_text_field( (string) ( $crudo['dealstage'] ?? '' ) ),
			'nombre'    => sanitize_text_field( (string) ( $crudo['nombre'] ?? '' ) ),
		);

		if ( '' === trim( $limpio['nombre'] ) ) {
			$limpio['nombre'] = self::defectos()['nombre'];
		}

		update_option( self::OPCION, $limpio, false );
	}

	/**
	 * Embudos y etapas del portal, para poder elegirlos de una lista.
	 *
	 * Si no hay token o HubSpot no responde se devuelve vacío y la pantalla
	 * ofrece campos de texto: elegir de una lista es una comodidad, no un
	 * requisito para que el módulo funcione.
	 *
	 * @param bool $forzar Ignora la copia guardada.
	 * @return array<string,array{nombre:string,etapas:array<string,string>}>
	 */
	public static function embudos( bool $forzar = false ): array {
		if ( ! $forzar ) {
			$guardado = get_transient( self::CACHE_EMBUDOS );

			if ( is_array( $guardado ) ) {
				return $guardado;
			}
		}

		$cliente = new Crm_Client();

		if ( ! $cliente->configurado() ) {
			return array();
		}

		$respuesta = $cliente->peticion( 'GET', '/crm/v3/pipelines/deals' );

		if ( ! $respuesta['ok'] ) {
			return array();
		}

		$salida = array();

		foreach ( (array) ( $respuesta['datos']['results'] ?? array() ) as $embudo ) {
			if ( ! is_array( $embudo ) || empty( $embudo['id'] ) ) {
				continue;
			}

			$etapas = array();

			foreach ( (array) ( $embudo['stages'] ?? array() ) as $etapa ) {
				if ( is_array( $etapa ) && ! empty( $etapa['id'] ) ) {
					$etapas[ (string) $etapa['id'] ] = (string) ( $etapa['label'] ?? $etapa['id'] );
				}
			}

			$salida[ (string) $embudo['id'] ] = array(
				'nombre' => (string) ( $embudo['label'] ?? $embudo['id'] ),
				'etapas' => $etapas,
			);
		}

		set_transient( self::CACHE_EMBUDOS, $salida, 12 * HOUR_IN_SECONDS );

		return $salida;
	}

	/**
	 * Olvida la copia guardada de los embudos.
	 *
	 * @return void
	 */
	public static function olvidar_embudos(): void {
		delete_transient( self::CACHE_EMBUDOS );
	}
}
