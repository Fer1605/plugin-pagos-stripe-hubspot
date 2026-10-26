<?php
/**
 * Sustitución de marcadores [campo].
 *
 * @package SWPagos
 */

namespace SWPagos\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Sustituye `[campo]` por el valor que la persona escribió en el formulario.
 *
 * Los campos de instrumento de pago **nunca** se sustituyen, aunque alguien los
 * nombre en la plantilla. No se confía en que quien redacta el correo
 * sepa cuáles no debe poner: el marcador se deja intacto y se registra el intento.
 */
final class Placeholder_Renderer {

	/**
	 * Nombres de campo que nunca se sustituyen.
	 *
	 * @var string[]
	 */
	private const BLOQUEADOS = array(
		'card',
		'card_number',
		'cardnumber',
		'card_num',
		'number',
		'cvv',
		'cvc',
		'security_code',
		'codigo_seguridad',
		'expiration',
		'exp_month',
		'exp_year',
		'card_month',
		'card_year',
		'vencimiento',
		'password',
		'token',
		'secret',
	);

	/**
	 * Valores disponibles para sustituir.
	 *
	 * @var array<string,string>
	 */
	private array $valores;

	/**
	 * Marcadores bloqueados que aparecieron en el último renderizado.
	 *
	 * @var string[]
	 */
	private array $bloqueados_vistos = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $valores Pares campo => valor.
	 */
	public function __construct( array $valores = array() ) {
		$this->valores = array();

		foreach ( $valores as $clave => $valor ) {
			if ( ! is_scalar( $valor ) && null !== $valor ) {
				continue;
			}
			$this->valores[ (string) $clave ] = (string) $valor;
		}
	}

	/**
	 * Añade o sustituye un valor.
	 *
	 * Lo usan los correos de suscripción para aportar marcadores que no vienen del
	 * formulario, como el enlace para actualizar el medio de pago.
	 *
	 * @param string $clave Nombre del marcador, sin corchetes.
	 * @param string $valor Valor.
	 * @return void
	 */
	public function con( string $clave, string $valor ): void {
		$this->valores[ $clave ] = $valor;
	}

	/**
	 * Sustituye los marcadores de un texto.
	 *
	 * @param string $texto    Texto con marcadores.
	 * @param bool   $escapado Si el resultado va a HTML.
	 * @return string
	 */
	public function render( string $texto, bool $escapado = true ): string {
		if ( '' === $texto || ! str_contains( $texto, '[' ) ) {
			return $texto;
		}

		$this->bloqueados_vistos = array();

		return (string) preg_replace_callback(
			'/\[([a-zA-Z0-9_\-]+)\]/',
			function ( array $coincidencia ) use ( $escapado ): string {
				$clave = $coincidencia[1];

				if ( self::esta_bloqueado( $clave ) ) {
					$this->bloqueados_vistos[] = $clave;
					// Se devuelve el marcador tal cual: así es evidente en el correo
					// que algo no se sustituyó, en lugar de dejar un hueco mudo.
					return $coincidencia[0];
				}

				if ( ! array_key_exists( $clave, $this->valores ) ) {
					return $coincidencia[0];
				}

				$valor = $this->valores[ $clave ];

				return $escapado ? esc_html( $valor ) : $valor;
			},
			$texto
		);
	}

	/**
	 * Sustituye marcadores en un texto plano, como un asunto de correo.
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	public function render_plano( string $texto ): string {
		return wp_specialchars_decode( $this->render( $texto ), ENT_QUOTES );
	}

	/**
	 * Resuelve un destinatario que puede venir como marcador o como dirección.
	 *
	 * @param string $texto Texto.
	 * @return string Dirección válida, o cadena vacía.
	 */
	public function render_email( string $texto ): string {
		$resuelto = sanitize_email( trim( $this->render( $texto, false ) ) );

		return is_email( $resuelto ) ? $resuelto : '';
	}

	/**
	 * Marcadores bloqueados que aparecieron en el último renderizado.
	 *
	 * @return string[]
	 */
	public function bloqueados_vistos(): array {
		return array_values( array_unique( $this->bloqueados_vistos ) );
	}

	/**
	 * Indica si un nombre de campo está bloqueado.
	 *
	 * @param string $clave Nombre.
	 * @return bool
	 */
	private static function esta_bloqueado( string $clave ): bool {
		$normalizada = strtolower( str_replace( '-', '_', $clave ) );

		return in_array( $normalizada, self::BLOQUEADOS, true );
	}

	/**
	 * Valores disponibles, para diagnóstico.
	 *
	 * @return string[]
	 */
	public function claves(): array {
		return array_keys( $this->valores );
	}
}
