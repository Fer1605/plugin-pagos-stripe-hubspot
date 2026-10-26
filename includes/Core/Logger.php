<?php
/**
 * Registro del módulo, con redacción de secretos y datos personales.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registro con niveles y redacción automática.
 *
 * Cierra el hallazgo H-10 del módulo de referencia, que volcaba los datos
 * completos del envío con print_r. Aquí nada sensible llega al registro: lo que
 * se filtra no es una convención que haya que recordar en cada llamada, es el
 * comportamiento por omisión de la clase.
 */
final class Logger {

	/**
	 * Niveles admitidos, de menor a mayor gravedad.
	 *
	 * @var array<string,int>
	 */
	private const NIVELES = array(
		'debug'   => 10,
		'info'    => 20,
		'warning' => 30,
		'error'   => 40,
	);

	/**
	 * Claves cuyo valor nunca se registra, en cualquier profundidad del contexto.
	 *
	 * @var string[]
	 */
	private const CLAVES_PROHIBIDAS = array(
		'password',
		'passwd',
		'pwd',
		'secret',
		'secret_key',
		'client_secret',
		'token',
		'access_token',
		'refresh_token',
		'api_key',
		'apikey',
		'authorization',
		'auth',
		'signature',
		'card',
		'card_number',
		'cardnumber',
		'number',
		'cvc',
		'cvv',
		'exp_month',
		'exp_year',
		'expiration',
		'card_month',
		'card_year',
	);

	/**
	 * Tamaño máximo del contexto serializado. Un registro no es un volcado.
	 *
	 * @var int
	 */
	private const MAX_CONTEXTO = 2000;

	/**
	 * Mensaje de nivel depuración.
	 *
	 * @param string              $mensaje  Mensaje.
	 * @param array<string,mixed> $contexto Contexto.
	 * @return void
	 */
	public function debug( string $mensaje, array $contexto = array() ): void {
		$this->registrar( 'debug', $mensaje, $contexto );
	}

	/**
	 * Mensaje informativo.
	 *
	 * @param string              $mensaje  Mensaje.
	 * @param array<string,mixed> $contexto Contexto.
	 * @return void
	 */
	public function info( string $mensaje, array $contexto = array() ): void {
		$this->registrar( 'info', $mensaje, $contexto );
	}

	/**
	 * Advertencia.
	 *
	 * @param string              $mensaje  Mensaje.
	 * @param array<string,mixed> $contexto Contexto.
	 * @return void
	 */
	public function warning( string $mensaje, array $contexto = array() ): void {
		$this->registrar( 'warning', $mensaje, $contexto );
	}

	/**
	 * Error.
	 *
	 * @param string              $mensaje  Mensaje.
	 * @param array<string,mixed> $contexto Contexto.
	 * @return void
	 */
	public function error( string $mensaje, array $contexto = array() ): void {
		$this->registrar( 'error', $mensaje, $contexto );
	}

	/**
	 * Escribe una línea en el registro si el nivel lo permite.
	 *
	 * @param string              $nivel    Nivel.
	 * @param string              $mensaje  Mensaje.
	 * @param array<string,mixed> $contexto Contexto.
	 * @return void
	 */
	private function registrar( string $nivel, string $mensaje, array $contexto = array() ): void {
		if ( self::NIVELES[ $nivel ] < $this->nivel_minimo() ) {
			return;
		}

		$linea = sprintf( '[sw-pagos][%s] %s', strtoupper( $nivel ), self::redactar_texto( $mensaje ) );

		if ( ! empty( $contexto ) ) {
			$seguro = wp_json_encode( self::redactar( $contexto ) );
			if ( is_string( $seguro ) ) {
				if ( strlen( $seguro ) > self::MAX_CONTEXTO ) {
					$seguro = substr( $seguro, 0, self::MAX_CONTEXTO ) . '…[truncado]';
				}
				$linea .= ' ' . $seguro;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $linea );
	}

	/**
	 * Nivel mínimo que se escribe.
	 *
	 * Las advertencias y los errores se registran siempre: son lo que se necesita
	 * para diagnosticar un cobro, y perderlos por no tener la depuración activa
	 * sería peor que el ruido que generan. Lo informativo y la depuración solo
	 * cuando se piden explícitamente.
	 *
	 * @return int
	 */
	private function nivel_minimo(): int {
		$configurado = get_option( 'swpagos_log_level' );

		if ( is_string( $configurado ) && isset( self::NIVELES[ $configurado ] ) ) {
			return self::NIVELES[ $configurado ];
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return self::NIVELES['debug'];
		}

		return self::NIVELES['warning'];
	}

	/**
	 * Redacta recursivamente un valor de contexto.
	 *
	 * @param mixed $valor Valor a redactar.
	 * @return mixed
	 */
	public static function redactar( $valor ) {
		if ( is_array( $valor ) ) {
			$salida = array();
			foreach ( $valor as $clave => $sub ) {
				if ( is_string( $clave ) && self::clave_prohibida( $clave ) ) {
					$salida[ $clave ] = '[redactado]';
					continue;
				}
				$salida[ $clave ] = self::redactar( $sub );
			}
			return $salida;
		}

		if ( is_object( $valor ) ) {
			return self::redactar( get_object_vars( $valor ) );
		}

		if ( is_string( $valor ) ) {
			return self::redactar_texto( $valor );
		}

		return $valor;
	}

	/**
	 * Indica si una clave de contexto nunca debe registrarse.
	 *
	 * @param string $clave Clave.
	 * @return bool
	 */
	private static function clave_prohibida( string $clave ): bool {
		$normalizada = strtolower( str_replace( array( '-', ' ' ), '_', $clave ) );

		foreach ( self::CLAVES_PROHIBIDAS as $prohibida ) {
			if ( $normalizada === $prohibida || str_contains( $normalizada, $prohibida ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redacta secretos y datos personales dentro de una cadena.
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	public static function redactar_texto( string $texto ): string {
		$patrones = array(
			// Claves de Stripe: secretas, restringidas y secreto de webhook.
			'/\b(sk|rk)_(live|test)_[A-Za-z0-9]+/' => '$1_$2_[redactado]',
			'/\bwhsec_[A-Za-z0-9+\/=]+/'           => 'whsec_[redactado]',
			// Token de aplicación privada de HubSpot.
			'/\bpat-[a-z0-9]{2,5}\d-[A-Za-z0-9-]+/i' => 'pat-[redactado]',
			// Cabeceras de autorización.
			'/\bBearer\s+[A-Za-z0-9._\-]+/i'       => 'Bearer [redactado]',
			// Números con pinta de tarjeta: 13 a 19 dígitos, con o sin separadores.
			'/\b(?:\d[ -]?){12,18}\d\b/'           => '[posible-tarjeta-redactada]',
		);

		$texto = (string) preg_replace( array_keys( $patrones ), array_values( $patrones ), $texto );

		// Correos: se conserva la forma para poder correlacionar, no la identidad.
		$texto = (string) preg_replace_callback(
			'/\b([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/',
			static fn( array $m ): string => $m[1] . '***@' . $m[2],
			$texto
		);

		return $texto;
	}

	/**
	 * Enmascara un valor dejando visibles solo los últimos caracteres.
	 *
	 * Lo usa la interfaz de ajustes para mostrar una credencial sin revelarla.
	 *
	 * @param string $valor    Valor completo.
	 * @param int    $visibles Caracteres finales visibles.
	 * @return string
	 */
	public static function enmascarar( string $valor, int $visibles = 4 ): string {
		$valor = trim( $valor );

		if ( '' === $valor ) {
			return '';
		}

		if ( strlen( $valor ) <= $visibles ) {
			return str_repeat( '•', strlen( $valor ) );
		}

		return str_repeat( '•', 8 ) . substr( $valor, -$visibles );
	}
}
