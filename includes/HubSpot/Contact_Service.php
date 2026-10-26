<?php
/**
 * Contactos del CRM de HubSpot.
 *
 * @package SWPagos
 */

namespace SWPagos\HubSpot;

use SWPagos\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Busca el contacto por correo y lo crea o lo actualiza.
 */
final class Contact_Service {

	/**
	 * Cliente del CRM.
	 *
	 * @var Crm_Client
	 */
	private Crm_Client $cliente;

	/**
	 * Constructor.
	 *
	 * @param Crm_Client|null $cliente Cliente.
	 */
	public function __construct( ?Crm_Client $cliente = null ) {
		$this->cliente = $cliente ?? new Crm_Client();
	}

	/**
	 * Crea o actualiza un contacto.
	 *
	 * @param string               $email       Correo.
	 * @param array<string,string> $propiedades Propiedades.
	 * @return array{ok:bool,id:string,mensaje:string,reintentable:bool}
	 */
	public function upsert( string $email, array $propiedades = array() ): array {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return array(
				'ok'           => false,
				'id'           => '',
				'mensaje'      => __( 'El contacto no tiene un correo válido; no se puede identificar en HubSpot.', 'sw-pagos' ),
				'reintentable' => false,
			);
		}

		$propiedades['email'] = $email;

		$existente = $this->buscar( $email );

		if ( '' !== $existente['error'] ) {
			return array(
				'ok'           => false,
				'id'           => '',
				'mensaje'      => $existente['error'],
				'reintentable' => $existente['reintentable'],
			);
		}

		if ( '' !== $existente['id'] ) {
			return $this->escribir( 'PATCH', '/crm/v3/objects/contacts/' . rawurlencode( $existente['id'] ), $propiedades );
		}

		return $this->escribir( 'POST', '/crm/v3/objects/contacts', $propiedades );
	}

	/**
	 * Busca un contacto por correo.
	 *
	 * @param string $email Correo.
	 * @return array{id:string,error:string,reintentable:bool}
	 */
	public function buscar( string $email ): array {
		$resultado = $this->cliente->peticion(
			'POST',
			'/crm/v3/objects/contacts/search',
			array(
				'filterGroups' => array(
					array(
						'filters' => array(
							array(
								'propertyName' => 'email',
								'operator'     => 'EQ',
								'value'        => $email,
							),
						),
					),
				),
				'properties'   => array( 'email' ),
				'limit'        => 1,
			)
		);

		if ( ! $resultado['ok'] ) {
			return array(
				'id'           => '',
				'error'        => $resultado['mensaje'],
				'reintentable' => $resultado['reintentable'],
			);
		}

		$primero = $resultado['datos']['results'][0] ?? null;

		return array(
			'id'           => is_array( $primero ) ? (string) ( $primero['id'] ?? '' ) : '',
			'error'        => '',
			'reintentable' => false,
		);
	}

	/**
	 * Escribe el contacto, reintentando sin las propiedades personalizadas que el
	 * portal no tenga.
	 *
	 * Que falte una propiedad personalizada es un descuido de configuración, no un
	 * motivo para perder el contacto de alguien que acaba de donar. Se vuelve a
	 * intentar sin ella y queda avisado en el registro.
	 *
	 * @param string               $metodo      Método.
	 * @param string               $ruta        Ruta.
	 * @param array<string,string> $propiedades Propiedades.
	 * @return array{ok:bool,id:string,mensaje:string,reintentable:bool}
	 */
	private function escribir( string $metodo, string $ruta, array $propiedades ): array {
		$intentos = 0;

		while ( $intentos < 4 ) {
			++$intentos;

			$resultado = $this->cliente->peticion( $metodo, $ruta, array( 'properties' => $propiedades ) );

			if ( $resultado['ok'] ) {
				return array(
					'ok'           => true,
					'id'           => (string) ( $resultado['datos']['id'] ?? '' ),
					'mensaje'      => '',
					'reintentable' => false,
				);
			}

			$desconocida = $this->cliente->propiedad_desconocida( $resultado );

			if ( '' === $desconocida || ! isset( $propiedades[ $desconocida ] ) || 'email' === $desconocida ) {
				return array(
					'ok'           => false,
					'id'           => '',
					'mensaje'      => $resultado['mensaje'],
					'reintentable' => $resultado['reintentable'],
				);
			}

			Plugin::instancia()->logger()->warning(
				'HubSpot no reconoce una propiedad de contacto; se reintenta sin ella. Conviene crearla en el portal.',
				array( 'propiedad' => $desconocida )
			);

			unset( $propiedades[ $desconocida ] );
		}

		return array(
			'ok'           => false,
			'id'           => '',
			'mensaje'      => __( 'HubSpot siguió rechazando el contacto tras quitar las propiedades desconocidas.', 'sw-pagos' ),
			'reintentable' => false,
		);
	}
}
