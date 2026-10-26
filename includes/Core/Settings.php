<?php
/**
 * Lectura de credenciales y ajustes del módulo.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Acceso a la configuración, con las credenciales fuera de la base de datos.
 *
 * la constante de wp-config.php manda sobre la opción. La opción
 * existe solo para sitios donde no se puede editar wp-config.php, y en pantalla
 * nunca se muestra el valor completo. Cierra el hallazgo H-03.
 */
final class Settings {

	/**
	 * Constantes reconocidas para cada credencial, por modo.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const CONSTANTES = array(
		'stripe_secret'         => array(
			'test' => 'SWPAGOS_STRIPE_SECRET_TEST',
			'live' => 'SWPAGOS_STRIPE_SECRET_LIVE',
		),
		'stripe_publishable'    => array(
			'test' => 'SWPAGOS_STRIPE_PUBLISHABLE_TEST',
			'live' => 'SWPAGOS_STRIPE_PUBLISHABLE_LIVE',
		),
		'stripe_webhook_secret' => array(
			'test' => 'SWPAGOS_STRIPE_WEBHOOK_SECRET_TEST',
			'live' => 'SWPAGOS_STRIPE_WEBHOOK_SECRET_LIVE',
		),
	);

	/**
	 * Constante del token de HubSpot, que no depende del modo.
	 *
	 * @var string
	 */
	private const CONSTANTE_HUBSPOT = 'SWPAGOS_HUBSPOT_TOKEN';

	/**
	 * Normaliza un modo a 'test' o 'live'.
	 *
	 * @param string $modo Modo recibido.
	 * @return string
	 */
	public static function normalizar_modo( string $modo ): string {
		return 'live' === strtolower( trim( $modo ) ) ? 'live' : 'test';
	}

	/**
	 * Clave secreta de Stripe.
	 *
	 * @param string $modo test|live.
	 * @return string
	 */
	public function stripe_secret( string $modo ): string {
		return $this->credencial( 'stripe_secret', $modo );
	}

	/**
	 * Clave publicable de Stripe.
	 *
	 * @param string $modo test|live.
	 * @return string
	 */
	public function stripe_publishable( string $modo ): string {
		return $this->credencial( 'stripe_publishable', $modo );
	}

	/**
	 * Secreto de firma del receptor de eventos.
	 *
	 * @param string $modo test|live.
	 * @return string
	 */
	public function stripe_webhook_secret( string $modo ): string {
		return $this->credencial( 'stripe_webhook_secret', $modo );
	}

	/**
	 * Token de la aplicación privada de HubSpot.
	 *
	 * @return string
	 */
	public function hubspot_token(): string {
		if ( defined( self::CONSTANTE_HUBSPOT ) ) {
			$valor = (string) constant( self::CONSTANTE_HUBSPOT );
			if ( '' !== trim( $valor ) ) {
				return trim( $valor );
			}
		}

		return trim( (string) get_option( 'swpagos_hubspot_token', '' ) );
	}

	/**
	 * Lee una credencial dependiente del modo.
	 *
	 * @param string $nombre Nombre lógico de la credencial.
	 * @param string $modo   test|live.
	 * @return string
	 */
	private function credencial( string $nombre, string $modo ): string {
		$modo = self::normalizar_modo( $modo );

		if ( isset( self::CONSTANTES[ $nombre ][ $modo ] ) ) {
			$constante = self::CONSTANTES[ $nombre ][ $modo ];
			if ( defined( $constante ) ) {
				$valor = (string) constant( $constante );
				if ( '' !== trim( $valor ) ) {
					return trim( $valor );
				}
			}
		}

		return trim( (string) get_option( $this->nombre_opcion( $nombre, $modo ), '' ) );
	}

	/**
	 * Nombre de la opción que respalda una credencial.
	 *
	 * @param string $nombre Nombre lógico.
	 * @param string $modo   test|live.
	 * @return string
	 */
	public function nombre_opcion( string $nombre, string $modo ): string {
		return 'swpagos_' . $nombre . '_' . self::normalizar_modo( $modo );
	}

	/**
	 * De dónde procede una credencial. Lo usa el panel de diagnóstico para que
	 * quien instala sepa si su wp-config.php está surtiendo efecto.
	 *
	 * @param string $nombre Nombre lógico. 'hubspot_token' incluido.
	 * @param string $modo   test|live. Se ignora para HubSpot.
	 * @return string constante|opcion|ausente
	 */
	public function origen( string $nombre, string $modo = 'test' ): string {
		if ( 'hubspot_token' === $nombre ) {
			if ( defined( self::CONSTANTE_HUBSPOT ) && '' !== trim( (string) constant( self::CONSTANTE_HUBSPOT ) ) ) {
				return 'constante';
			}
			return '' !== trim( (string) get_option( 'swpagos_hubspot_token', '' ) ) ? 'opcion' : 'ausente';
		}

		$modo = self::normalizar_modo( $modo );

		if ( isset( self::CONSTANTES[ $nombre ][ $modo ] ) ) {
			$constante = self::CONSTANTES[ $nombre ][ $modo ];
			if ( defined( $constante ) && '' !== trim( (string) constant( $constante ) ) ) {
				return 'constante';
			}
		}

		return '' !== trim( (string) get_option( $this->nombre_opcion( $nombre, $modo ), '' ) ) ? 'opcion' : 'ausente';
	}

	/**
	 * Indica si una credencial está definida por constante, en cuyo caso el campo
	 * de la interfaz debe mostrarse bloqueado: guardar la opción no tendría efecto
	 * y daría una falsa sensación de haber cambiado algo.
	 *
	 * @param string $nombre Nombre lógico.
	 * @param string $modo   test|live.
	 * @return bool
	 */
	public function fijada_por_constante( string $nombre, string $modo = 'test' ): bool {
		return 'constante' === $this->origen( $nombre, $modo );
	}

	/**
	 * Nombre de la constante asociada a una credencial, para mostrarlo en la ayuda.
	 *
	 * @param string $nombre Nombre lógico.
	 * @param string $modo   test|live.
	 * @return string
	 */
	public function nombre_constante( string $nombre, string $modo = 'test' ): string {
		if ( 'hubspot_token' === $nombre ) {
			return self::CONSTANTE_HUBSPOT;
		}

		return self::CONSTANTES[ $nombre ][ self::normalizar_modo( $modo ) ] ?? '';
	}

	/**
	 * Valor enmascarado para mostrar en pantalla.
	 *
	 * @param string $nombre Nombre lógico.
	 * @param string $modo   test|live.
	 * @return string
	 */
	public function enmascarada( string $nombre, string $modo = 'test' ): string {
		$valor = 'hubspot_token' === $nombre ? $this->hubspot_token() : $this->credencial( $nombre, $modo );
		return Logger::enmascarar( $valor );
	}

	/**
	 * Moneda por omisión del sitio.
	 *
	 * @return string
	 */
	public function moneda_predeterminada(): string {
		$moneda = strtoupper( trim( (string) get_option( 'swpagos_moneda', 'USD' ) ) );
		return 3 === strlen( $moneda ) ? $moneda : 'USD';
	}

	/**
	 * Destinatarios de las alertas de fallo de entrega.
	 *
	 * @return string[]
	 */
	public function destinatarios_alerta(): array {
		return self::lista_correos( (string) get_option( 'swpagos_alerta_to', (string) get_option( 'admin_email', '' ) ) );
	}

	/**
	 * Direcciones en copia oculta de las alertas.
	 *
	 * @return string[]
	 */
	public function copias_alerta(): array {
		return self::lista_correos( (string) get_option( 'swpagos_alerta_bcc', '' ) );
	}

	/**
	 * Quién firma los correos que manda el módulo.
	 *
	 * WordPress los firma como «WordPress <wordpress@dominio>» mientras nadie
	 * diga otra cosa, y eso es exactamente lo que ve quien dona. El nombre se
	 * puede cambiar sin tocar la dirección: cambiar la dirección puede estropear
	 * la entrega si el dominio no la autoriza, así que por omisión se deja la que
	 * ya funciona y solo se corrige el nombre.
	 *
	 * @return array{nombre:string,correo:string}
	 */
	public function remitente(): array {
		$nombre = trim( (string) get_option( 'swpagos_remitente_nombre', '' ) );
		$correo = trim( (string) get_option( 'swpagos_remitente_correo', '' ) );

		return array(
			'nombre' => self::nombre_limpio( '' !== $nombre ? $nombre : (string) get_bloginfo( 'name' ) ),
			'correo' => is_email( $correo ) ? $correo : self::correo_por_omision(),
		);
	}

	/**
	 * Deja un nombre que se pueda poner en una cabecera de correo.
	 *
	 * El nombre del sitio se guarda tal como se escribió, con sus entidades:
	 * «Ana &amp;amp; Asociados». En una página eso se ve bien, pero en la cabecera
	 * de un correo se lee literal. Y un salto de línea ahí dentro permitiría
	 * añadir cabeceras ajenas, así que tampoco pasan.
	 *
	 * @param string $nombre Nombre.
	 * @return string
	 */
	private static function nombre_limpio( string $nombre ): string {
		$nombre = html_entity_decode( $nombre, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/[\r\n<>"]+/u', ' ', $nombre ) );
	}

	/**
	 * Cabecera «From» lista para wp_mail.
	 *
	 * @return string
	 */
	public function cabecera_remitente(): string {
		$de = $this->remitente();

		return sprintf( 'From: %s <%s>', $de['nombre'], $de['correo'] );
	}

	/**
	 * La dirección que WordPress usaría por su cuenta.
	 *
	 * Se reproduce su mismo criterio para que dejar el campo vacío no cambie
	 * nada de lo que ya estaba saliendo.
	 *
	 * @return string
	 */
	public static function correo_por_omision(): string {
		$dominio = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( str_starts_with( $dominio, 'www.' ) ) {
			$dominio = substr( $dominio, 4 );
		}

		return 'wordpress@' . $dominio;
	}

	/**
	 * Limpia una lista de correos separados por comas.
	 *
	 * @param string $crudo Cadena.
	 * @return string[]
	 */
	public static function lista_correos( string $crudo ): array {
		$lista = array_map( 'trim', explode( ',', $crudo ) );

		return array_values( array_unique( array_filter( $lista, 'is_email' ) ) );
	}
}
