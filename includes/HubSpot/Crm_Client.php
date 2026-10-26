<?php
/**
 * Cliente autenticado del CRM de HubSpot.
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Llamadas al CRM con el token de la aplicación privada.
 *
 * Es el «nivel 2» de la integración y solo entra en juego cuando un cobro está
 * confirmado: un cobro rechazado o abandonado no crea nada aquí.
 */
final class Crm_Client {

	/**
	 * Base de la API.
	 *
	 * @var string
	 */
	private const BASE = 'https://api.hubapi.com';

	/**
	 * Segundos de espera.
	 *
	 * @var int
	 */
	private const TIMEOUT = 20;

	/**
	 * Token de la aplicación privada.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->token = self::token();
	}

	/**
	 * Token de la aplicación privada.
	 *
	 * Manda el que esté en «Ajustes de HubSpot», que es donde se configura todo
	 * lo de HubSpot. El nuestro queda solo como respaldo para los sitios que lo
	 * fijaron por constante antes de la fusión: dos campos para el mismo token
	 * en dos pantallas distintas es una trampa, no una opción.
	 *
	 * @return string
	 */
	public static function token(): string {
		if ( class_exists( '\\HEF\\Settings' ) ) {
			$suyo = trim( (string) \HEF\Settings::access_token() );

			if ( '' !== $suyo ) {
				return $suyo;
			}
		}

		return Plugin::instancia()->settings()->hubspot_token();
	}

	/**
	 * Indica si hay credencial para hablar con el CRM.
	 *
	 * @return bool
	 */
	public function configurado(): bool {
		return '' !== $this->token;
	}

	/**
	 * Petición al CRM.
	 *
	 * @param string              $metodo Método HTTP.
	 * @param string              $ruta   Ruta relativa.
	 * @param array<string,mixed> $cuerpo Cuerpo.
	 * @return array{ok:bool,codigo:int,datos:array<string,mixed>,mensaje:string,reintentable:bool}
	 */
	public function peticion( string $metodo, string $ruta, array $cuerpo = array() ): array {
		if ( ! $this->configurado() ) {
			return $this->fallo( 0, __( 'Falta el token de la aplicación privada de HubSpot.', 'sw-pagos' ), false );
		}

		$args = array(
			'method'  => $metodo,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => self::TIMEOUT,
		);

		if ( ! empty( $cuerpo ) ) {
			$args['body'] = (string) wp_json_encode( $cuerpo );
		}

		$respuesta = wp_remote_request( self::BASE . $ruta, $args );

		if ( is_wp_error( $respuesta ) ) {
			// Fallo de red: merece reintento.
			return $this->fallo( 0, $respuesta->get_error_message(), true );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $respuesta );
		$texto  = (string) wp_remote_retrieve_body( $respuesta );
		$datos  = json_decode( $texto, true );
		$datos  = is_array( $datos ) ? $datos : array();

		if ( $codigo >= 200 && $codigo < 300 ) {
			return array(
				'ok'           => true,
				'codigo'       => $codigo,
				'datos'        => $datos,
				'mensaje'      => '',
				'reintentable' => false,
			);
		}

		return array(
			'ok'           => false,
			'codigo'       => $codigo,
			'datos'        => $datos,
			'mensaje'      => $this->explicar( $codigo, $datos ),
			// 429 y 5xx son transitorios; 4xx es configuración y no mejora al repetir.
			'reintentable' => 429 === $codigo || $codigo >= 500 || 0 === $codigo,
		);
	}

	/**
	 * Indica si el error se debe a una propiedad que no existe en el portal.
	 *
	 * @param array{codigo:int,datos:array<string,mixed>,mensaje:string} $resultado Resultado.
	 * @return string Nombre de la propiedad, o cadena vacía.
	 */
	public function propiedad_desconocida( array $resultado ): string {
		if ( 400 !== ( $resultado['codigo'] ?? 0 ) ) {
			return '';
		}

		$mensaje = (string) ( $resultado['datos']['message'] ?? $resultado['mensaje'] ?? '' );

		if ( ! str_contains( strtolower( $mensaje ), 'property' ) ) {
			return '';
		}

		if ( 1 === preg_match( '/"([a-z0-9_]+)"/i', $mensaje, $coincidencia ) ) {
			return $coincidencia[1];
		}

		return '';
	}

	/**
	 * Traduce un código a algo accionable.
	 *
	 * @param int                 $codigo Código.
	 * @param array<string,mixed> $datos  Respuesta.
	 * @return string
	 */
	private function explicar( int $codigo, array $datos ): string {
		$detalle = (string) ( $datos['message'] ?? '' );

		$base = array(
			400 => __( 'HubSpot rechazó los datos. Suele ser una propiedad personalizada que todavía no existe en el portal.', 'sw-pagos' ),
			401 => __( 'El token de HubSpot no es válido o caducó.', 'sw-pagos' ),
			403 => __( 'Al token le faltan permisos. Revisa los ámbitos de contactos y negocios en la aplicación privada.', 'sw-pagos' ),
			404 => __( 'HubSpot no encontró el recurso.', 'sw-pagos' ),
			429 => __( 'HubSpot está limitando las llamadas. Se reintentará más tarde.', 'sw-pagos' ),
		);

		$texto = $base[ $codigo ] ?? sprintf(
			/* translators: %d: código HTTP. */
			__( 'HubSpot respondió con el código %d.', 'sw-pagos' ),
			$codigo
		);

		return '' !== $detalle ? $texto . ' ' . $detalle : $texto;
	}

	/**
	 * Resultado de fallo.
	 *
	 * @param int    $codigo       Código.
	 * @param string $mensaje      Mensaje.
	 * @param bool   $reintentable Si merece reintento.
	 * @return array{ok:bool,codigo:int,datos:array<string,mixed>,mensaje:string,reintentable:bool}
	 */
	private function fallo( int $codigo, string $mensaje, bool $reintentable ): array {
		return array(
			'ok'           => false,
			'codigo'       => $codigo,
			'datos'        => array(),
			'mensaje'      => $mensaje,
			'reintentable' => $reintentable,
		);
	}
}
