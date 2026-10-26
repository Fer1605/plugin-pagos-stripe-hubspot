<?php
/**
 * Conversión de importes a la unidad mínima de cada moneda.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manejo de importes en unidad mínima.
 *
 * Los importes se almacenan y se envían siempre como entero en la unidad mínima
 * de la moneda. Nunca como número con coma flotante: 0.1 + 0.2 no es 0.3 en
 * binario, y un cobro no es sitio para ese error.
 */
final class Currency {

	/**
	 * Monedas sin decimales. El importe en unidad mínima es el importe a secas.
	 *
	 * @var string[]
	 */
	private const CERO_DECIMALES = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'JPY',
		'KMF',
		'KRW',
		'MGA',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * Monedas de tres decimales.
	 *
	 * Stripe las cobra en unidades de diez de la unidad mínima, así que el valor
	 * final debe ser múltiplo de 10.
	 *
	 * @var string[]
	 */
	private const TRES_DECIMALES = array(
		'BHD',
		'IQD',
		'JOD',
		'KWD',
		'LYD',
		'OMR',
		'TND',
	);

	/**
	 * Normaliza un código de moneda.
	 *
	 * @param string $moneda Código ISO 4217.
	 * @return string
	 */
	public static function normalizar( string $moneda ): string {
		return strtoupper( trim( $moneda ) );
	}

	/**
	 * Decimales de una moneda.
	 *
	 * @param string $moneda Código ISO 4217.
	 * @return int
	 */
	public static function decimales( string $moneda ): int {
		$moneda = self::normalizar( $moneda );

		if ( in_array( $moneda, self::CERO_DECIMALES, true ) ) {
			return 0;
		}

		if ( in_array( $moneda, self::TRES_DECIMALES, true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Convierte un importe escrito por una persona a unidad mínima.
	 *
	 * Acepta coma o punto como separador decimal, y separadores de millar, porque
	 * es lo que la gente escribe de verdad en un campo de formulario: "1.234,50",
	 * "1,234.50" y "1234.5" deben dar el mismo resultado.
	 *
	 * @param string|int|float $monto  Importe en unidad mayor.
	 * @param string           $moneda Código ISO 4217.
	 * @return int Importe en unidad mínima.
	 */
	public static function a_unidad_minima( $monto, string $moneda ): int {
		$normalizado = self::normalizar_entrada( $monto );
		$decimales   = self::decimales( $moneda );

		// Se escala con bcmath cuando está disponible para no pasar por el flotante.
		if ( function_exists( 'bcmul' ) ) {
			$menor = (int) bcmul( $normalizado, (string) ( 10 ** $decimales ), 0 );
		} else {
			$menor = (int) round( ( (float) $normalizado ) * ( 10 ** $decimales ) );
		}

		if ( 3 === $decimales ) {
			// Stripe exige múltiplos de diez en las monedas de tres decimales.
			$menor = (int) ( round( $menor / 10 ) * 10 );
		}

		return max( 0, $menor );
	}

	/**
	 * Convierte un importe en unidad mínima a unidad mayor.
	 *
	 * Se usa para mostrar y para enviar a HubSpot, que espera el importe en
	 * unidad mayor, no en centavos.
	 *
	 * @param int    $menor  Importe en unidad mínima.
	 * @param string $moneda Código ISO 4217.
	 * @return string Importe en unidad mayor, con los decimales de la moneda.
	 */
	public static function a_unidad_mayor( int $menor, string $moneda ): string {
		$decimales = self::decimales( $moneda );

		if ( 0 === $decimales ) {
			return (string) $menor;
		}

		$divisor = 10 ** $decimales;

		if ( function_exists( 'bcdiv' ) ) {
			return bcdiv( (string) $menor, (string) $divisor, $decimales );
		}

		return number_format( $menor / $divisor, $decimales, '.', '' );
	}

	/**
	 * Importe legible, para pantallas y correos.
	 *
	 * @param int    $menor  Importe en unidad mínima.
	 * @param string $moneda Código ISO 4217.
	 * @return string
	 */
	public static function formatear( int $menor, string $moneda ): string {
		$moneda    = self::normalizar( $moneda );
		$decimales = self::decimales( $moneda );
		$mayor     = (float) self::a_unidad_mayor( $menor, $moneda );

		return number_format_i18n( $mayor, $decimales ) . ' ' . $moneda;
	}

	/**
	 * Limpia un importe escrito por una persona y lo deja como cadena decimal
	 * con punto, sin separadores de millar.
	 *
	 * @param string|int|float $monto Importe crudo.
	 * @return string
	 */
	private static function normalizar_entrada( $monto ): string {
		if ( is_int( $monto ) || is_float( $monto ) ) {
			return rtrim( rtrim( sprintf( '%.6F', $monto ), '0' ), '.' ) ?: '0';
		}

		$texto = trim( (string) $monto );
		$texto = preg_replace( '/[^0-9.,\-]/', '', $texto ) ?? '';

		if ( '' === $texto ) {
			return '0';
		}

		$ultima_coma  = strrpos( $texto, ',' );
		$ultimo_punto = strrpos( $texto, '.' );

		if ( false !== $ultima_coma && false !== $ultimo_punto ) {
			// El separador decimal es el que aparece más a la derecha.
			if ( $ultima_coma > $ultimo_punto ) {
				$texto = str_replace( '.', '', $texto );
				$texto = str_replace( ',', '.', $texto );
			} else {
				$texto = str_replace( ',', '', $texto );
			}
		} elseif ( false !== $ultima_coma ) {
			// Una sola coma: decimal si deja dos o menos dígitos detrás, millar si no.
			$decimales_tras_coma = strlen( $texto ) - $ultima_coma - 1;
			$texto               = $decimales_tras_coma > 0 && $decimales_tras_coma <= 2
				? str_replace( ',', '.', $texto )
				: str_replace( ',', '', $texto );
		}

		if ( ! is_numeric( $texto ) ) {
			return '0';
		}

		return $texto;
	}
}
