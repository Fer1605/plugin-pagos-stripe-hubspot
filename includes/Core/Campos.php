<?php
/**
 * Equivalencias entre los campos del formulario y los que el módulo necesita.
 *
 * @package SWPagos
 */

namespace SWPagos\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Traduce los identificadores reales de un formulario a los lógicos.
 *
 * Un formulario que ya existe no tiene por qué llamar «email» a su campo de
 * correo: en un sitio en español lo normal es «correo». Obligar a renombrarlo
 * para poder cobrar rompe lo que ya funcionaba —el aviso por correo al equipo,
 * las integraciones, lo que haya— y es el módulo el que debe adaptarse al
 * formulario, no al revés.
 */
final class Campos {

	/**
	 * Identificadores aceptados para cada campo lógico, en orden de preferencia.
	 *
	 * @var array<string,string[]>
	 */
	public const ALIAS = array(
		'email'    => array( 'email', 'correo', 'correo_electronico', 'correo-electronico', 'e_mail', 'mail', 'your-email' ),
		'nombre'   => array( 'nombre', 'nombres', 'name', 'first_name', 'firstname', 'nombre_completo', 'full_name', 'your-name' ),
		'apellido' => array( 'apellido', 'apellidos', 'last_name', 'lastname', 'surname' ),
		'telefono' => array( 'telefono', 'teléfono', 'phone', 'telephone', 'celular', 'movil', 'whatsapp' ),
		'empresa'  => array( 'empresa', 'company', 'organizacion', 'organization' ),
		// La frecuencia puede venir del campo propio del módulo o de un «tipo de
		// donación» que el formulario ya tenía, con sus propias palabras.
		'frecuencia' => array( 'swpagos_frecuencia', 'frecuencia', 'frequency', 'tipo', 'tipo_donacion', 'donation_type', 'periodicidad' ),
	);

	/**
	 * Palabras que, en el valor elegido, significan «cada mes».
	 *
	 * @var string[]
	 */
	private const PALABRAS_MENSUAL = array( 'month', 'mensual', 'monthly', 'recurrente', 'recurring', 'cada mes', 'every month', 'suscrip', 'subscription' );

	/**
	 * Identificador real que un formulario usa para un campo lógico.
	 *
	 * @param array<string,mixed> $campos  Campos del envío, indexados por identificador.
	 * @param string              $logico  Campo lógico: email, nombre, apellido…
	 * @return string Identificador encontrado, o cadena vacía.
	 */
	public static function identificador( array $campos, string $logico ): string {
		foreach ( self::ALIAS[ $logico ] ?? array( $logico ) as $alias ) {
			if ( array_key_exists( $alias, $campos ) ) {
				return $alias;
			}
		}

		return '';
	}

	/**
	 * Valor de un campo lógico.
	 *
	 * @param array<string,mixed> $valores Valores del envío.
	 * @param string              $logico  Campo lógico.
	 * @return string
	 */
	public static function valor( array $valores, string $logico ): string {
		$id = self::identificador( $valores, $logico );

		return '' !== $id ? trim( (string) $valores[ $id ] ) : '';
	}

	/**
	 * Frecuencia pedida: «month» o «single».
	 *
	 * El formulario no tiene por qué hablar en los términos del módulo. Un
	 * «Tipo de donación» que ofrece «Donación mensual» ya está diciendo la
	 * frecuencia, y obligar a añadir un segundo campo que pregunte lo mismo
	 * sería pedirle dos veces lo mismo a quien dona.
	 *
	 * Solo es mensual lo que lo dice con todas las letras; cualquier otra
	 * opción se cobra una sola vez, que es lo menos comprometido.
	 *
	 * @param array<string,mixed> $valores Valores del envío.
	 * @return string «month», «single», o cadena vacía si no hay campo.
	 */
	public static function frecuencia( array $valores ): string {
		$id = self::identificador( $valores, 'frecuencia' );

		if ( '' === $id ) {
			return '';
		}

		$elegido = strtolower( trim( (string) $valores[ $id ] ) );

		if ( '' === $elegido ) {
			return '';
		}

		foreach ( self::PALABRAS_MENSUAL as $palabra ) {
			if ( str_contains( $elegido, $palabra ) ) {
				return 'month';
			}
		}

		return 'single';
	}

	/**
	 * Nombre de la persona, uniendo nombre y apellido si vienen separados.
	 *
	 * @param array<string,mixed> $valores Valores del envío.
	 * @return string
	 */
	public static function nombre_completo( array $valores ): string {
		$nombre   = self::valor( $valores, 'nombre' );
		$apellido = self::valor( $valores, 'apellido' );

		return trim( $nombre . ( '' !== $apellido ? ' ' . $apellido : '' ) );
	}
}
