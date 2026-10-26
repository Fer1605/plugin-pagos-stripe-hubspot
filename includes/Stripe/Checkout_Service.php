<?php
/**
 * Creación del cobro en Stripe.
 *
 * @package SWPagos
 */

namespace SWPagos\Stripe;

use SWPagos\Core\Compat;
use SWPagos\Core\Currency;
use SWPagos\Core\Plugin;
use SWPagos\Domain\Amount_Policy;
use SWPagos\Domain\Transaction;
use SWPagos\Domain\Transaction_Repository;
use SWPagos\Elementor\Form_Validator;
use SWPagos\Templates\Plantilla;

defined( 'ABSPATH' ) || exit;

/**
 * Crea la transacción y la sesión de pago.
 *
 * Ningún dato de tarjeta pasa por aquí: el cobro ocurre en la pantalla alojada de
 * Stripe. Lo que se decide en este servidor es **cuánto** se cobra
 * y con qué clave de idempotencia.
 */
final class Checkout_Service {

	/**
	 * Inicia un cobro.
	 *
	 * @param array<string,mixed> $form_settings Ajustes del formulario.
	 * @param array<string,mixed> $campos        Valores del envío.
	 * @param array<string,mixed> $contexto      modo, envio, utm, locale.
	 * @return array{ok:bool,url:string,mensaje:string,campo:string,transaccion:?Transaction}
	 */
	public function iniciar( array $form_settings, array $campos, array $contexto = array() ): array {
		$form_id   = (string) ( $form_settings['form_id'] ?? '' );
		$plantilla = '' !== $form_id ? Plantilla::por_formulario( $form_id ) : null;

		$problemas = ( new Form_Validator() )->validar_cobro( $form_settings, $campos, $plantilla );

		if ( ! empty( $problemas ) ) {
			Plugin::instancia()->logger()->warning(
				'Cobro bloqueado por configuración incompleta del formulario.',
				array(
					'form_id'    => $form_id,
					'problemas'  => array_column( $problemas, 'codigo' ),
				)
			);

			return $this->error(
				$problemas[0]['mensaje'],
				$problemas[0]['campo']
			);
		}

		/** @var Plantilla $plantilla Ya validado arriba. */
		$valores = $this->valores( $campos );

		$politica = ( new Amount_Policy() )->resolver(
			$plantilla,
			$valores[ Compat::CAMPO_IMPORTE ] ?? '',
			\SWPagos\Core\Campos::frecuencia( $valores )
		);

		if ( ! $politica->aceptable() ) {
			Plugin::instancia()->logger()->warning(
				'Importe rechazado por la política de la plantilla.',
				array(
					'form_id'   => $form_id,
					'plantilla' => $plantilla->id(),
					'motivo'    => $politica->motivo,
					'pedido'    => (string) ( $valores[ Compat::CAMPO_IMPORTE ] ?? '' ),
				)
			);

			return $this->error( $politica->mensaje, $politica->campo );
		}

		$modo        = 'live' === ( $contexto['modo'] ?? 'test' ) ? 'live' : 'test';
		$idempotencia = $this->clave_idempotencia( $form_id, $valores, $modo );
		$repo        = new Transaction_Repository();

		$existente = $repo->por_idempotencia( $idempotencia );

		if ( null !== $existente ) {
			// Doble envío: se devuelve la sesión ya creada en lugar de cobrar otra vez.
			return $this->reutilizar( $existente );
		}

		$recurrente = Plantilla::FREQ_MENSUAL === $politica->frecuencia;

		$transaccion = $repo->crear(
			array(
				'form_id'         => $form_id,
				'template_id'     => $plantilla->id(),
				'mode'            => $modo,
				'kind'            => $recurrente ? Transaction::TIPO_ALTA_SUSC : Transaction::TIPO_UNICO,
				'amount_minor'    => $politica->importe,
				'currency'        => $politica->moneda,
				'idempotency_key' => $idempotencia,
				'customer_email'  => \SWPagos\Core\Campos::valor( $valores, 'email' ),
				'customer_name'   => \SWPagos\Core\Campos::nombre_completo( $valores ),
				'submission_id'   => (int) ( $contexto['envio'] ?? 0 ),
				'locale'          => (string) ( $contexto['locale'] ?? get_locale() ),
				'utm'             => $this->utm( $valores ),
				'metadata'        => $this->metadata( $valores, $plantilla, $form_settings ),
			)
		);

		if ( null === $transaccion ) {
			return $this->error( __( 'No se pudo iniciar el cobro. Vuelve a intentarlo.', 'sw-pagos' ) );
		}

		$stripe = ( new Client() )->para( $modo );

		if ( null === $stripe ) {
			$repo->transicionar(
				$transaccion,
				Transaction::RECHAZADA,
				array( 'failure_code' => 'sin_credenciales' )
			);

			return $this->error( __( 'El cobro no está disponible en este momento. Avisa a quien administra el sitio.', 'sw-pagos' ) );
		}

		try {
			$sesion = $stripe->checkout->sessions->create(
				$this->parametros( $transaccion, $plantilla, $politica->frecuencia ),
				array( 'idempotency_key' => $idempotencia )
			);
		} catch ( \Throwable $e ) {
			Plugin::instancia()->logger()->error(
				'Stripe rechazó la creación de la sesión de pago.',
				array(
					'transaccion' => $transaccion->uuid(),
					'error'       => $e->getMessage(),
				)
			);

			$repo->transicionar(
				$transaccion,
				Transaction::RECHAZADA,
				array(
					'failure_code'    => 'error_pasarela',
					'failure_message' => mb_substr( $e->getMessage(), 0, 500 ),
				)
			);

			return $this->error( __( 'No se pudo iniciar el cobro. Vuelve a intentarlo en unos minutos.', 'sw-pagos' ) );
		}

		$repo->transicionar(
			$transaccion,
			Transaction::PENDIENTE,
			array(
				'stripe_session_id'  => (string) $sesion->id,
				'stripe_customer_id' => is_string( $sesion->customer ) ? $sesion->customer : null,
			)
		);

		Plugin::instancia()->logger()->info(
			'Sesión de pago creada.',
			array(
				'transaccion' => $transaccion->uuid(),
				'modo'        => $modo,
				'importe'     => $politica->importe,
				'moneda'      => $politica->moneda,
				'frecuencia'  => $politica->frecuencia,
			)
		);

		return array(
			'ok'          => true,
			'url'         => (string) $sesion->url,
			'mensaje'     => '',
			'campo'       => '',
			'transaccion' => $repo->por_id( $transaccion->id() ),
		);
	}

	/**
	 * Parámetros de la sesión de Stripe.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @param Plantilla   $plantilla   Plantilla.
	 * @param string      $frecuencia  single|month.
	 * @return array<string,mixed>
	 */
	private function parametros( Transaction $transaccion, Plantilla $plantilla, string $frecuencia ): array {
		$recurrente = Plantilla::FREQ_MENSUAL === $frecuencia;

		$precio = array(
			'currency'     => strtolower( $transaccion->moneda() ),
			'unit_amount'  => $transaccion->importe(),
			'product_data' => array(
				'name' => $this->nombre_producto( $plantilla, $recurrente ),
			),
		);

		if ( $recurrente ) {
			$precio['recurring'] = array( 'interval' => 'month' );
		}

		$metadatos = $this->metadatos_stripe( $transaccion );

		$parametros = array(
			'mode'                 => $recurrente ? 'subscription' : 'payment',
			'line_items'           => array(
				array(
					'price_data' => $precio,
					'quantity'   => 1,
				),
			),
			'client_reference_id'  => $transaccion->uuid(),
			'success_url'          => $this->url_retorno( $transaccion, false ),
			'cancel_url'           => $this->url_retorno( $transaccion, true ),
			'metadata'             => $metadatos,
			'locale'               => $this->locale_stripe( $transaccion->valor( 'locale', '' ) ),
		);

		$email = $transaccion->email();

		if ( '' !== $email ) {
			$parametros['customer_email'] = $email;
		}

		if ( $recurrente ) {
			// Los metadatos del pago único viven en la intención; los de una
			// suscripción, en la suscripción. Sin esto, las renovaciones llegarían
			// sin saber a qué formulario ni a qué plantilla pertenecen.
			$parametros['subscription_data'] = array( 'metadata' => $metadatos );
		} else {
			$parametros['payment_intent_data'] = array( 'metadata' => $metadatos );
		}

		return $parametros;
	}

	/**
	 * Nombre del producto que ve quien paga.
	 *
	 * @param Plantilla $plantilla  Plantilla.
	 * @param bool      $recurrente Si es suscripción.
	 * @return string
	 */
	private function nombre_producto( Plantilla $plantilla, bool $recurrente ): string {
		$titulo = $plantilla->titulo();

		if ( '' === trim( $titulo ) ) {
			$titulo = get_bloginfo( 'name' );
		}

		return $recurrente
			? sprintf(
				/* translators: %s: nombre de la campaña. */
				__( '%s (mensual)', 'sw-pagos' ),
				$titulo
			)
			: $titulo;
	}

	/**
	 * Metadatos que viajan a Stripe.
	 *
	 * Stripe limita a 50 claves y 500 caracteres por valor, así que solo va lo que
	 * sirve para conciliar, nunca datos personales de más.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @return array<string,string>
	 */
	private function metadatos_stripe( Transaction $transaccion ): array {
		$metadatos = array(
			'swpagos_uuid'      => $transaccion->uuid(),
			'swpagos_form'      => $transaccion->form_id(),
			'swpagos_plantilla' => (string) $transaccion->template_id(),
			'swpagos_modo'      => $transaccion->modo(),
			'swpagos_sitio'     => wp_parse_url( home_url(), PHP_URL_HOST ) ?: '',
		);

		foreach ( $transaccion->utm() as $clave => $valor ) {
			$metadatos[ mb_substr( (string) $clave, 0, 40 ) ] = mb_substr( (string) $valor, 0, 500 );
		}

		return $metadatos;
	}

	/**
	 * URL de retorno al sitio.
	 *
	 * @param Transaction $transaccion Transacción.
	 * @param bool        $cancelado   Si es la URL de cancelación.
	 * @return string
	 */
	private function url_retorno( Transaction $transaccion, bool $cancelado ): string {
		$base = rest_url( Compat::REST_NAMESPACE . '/retorno' );

		$args = array( 't' => $transaccion->uuid() );

		if ( $cancelado ) {
			$args['cancelado'] = '1';
		}

		$url = add_query_arg( $args, $base );

		if ( $cancelado ) {
			return $url;
		}

		// Stripe sustituye esta plantilla al redirigir. No se puede pasar por
		// add_query_arg porque escaparía las llaves.
		return $url . '&session_id={CHECKOUT_SESSION_ID}';
	}

	/**
	 * Traduce el idioma de WordPress al que entiende Stripe.
	 *
	 * @param mixed $locale Idioma de WordPress.
	 * @return string
	 */
	private function locale_stripe( $locale ): string {
		$locale = (string) $locale;

		if ( '' === $locale ) {
			return 'auto';
		}

		$corto = strtolower( substr( str_replace( '_', '-', $locale ), 0, 2 ) );

		$admitidos = array( 'es', 'en', 'pt', 'fr', 'it', 'de', 'nl', 'pl', 'sv', 'da', 'fi', 'nb', 'ja', 'zh', 'ko', 'ru', 'tr', 'cs', 'el', 'hu', 'ro', 'th', 'vi', 'id', 'ms', 'bg', 'et', 'hr', 'lt', 'lv', 'sk', 'sl', 'fil' );

		return in_array( $corto, $admitidos, true ) ? $corto : 'auto';
	}

	/**
	 * Clave de idempotencia derivada del envío.
	 *
	 * El campo técnico que rellena el script del módulo es único por carga de
	 * página, de modo que un doble clic produce la misma clave y Stripe devuelve
	 * la sesión ya creada en lugar de cobrar dos veces (hallazgo H-06).
	 *
	 * @param string              $form_id Formulario.
	 * @param array<string,mixed> $valores Valores.
	 * @param string              $modo    Modo.
	 * @return string
	 */
	private function clave_idempotencia( string $form_id, array $valores, string $modo ): string {
		$semilla = (string) ( $valores[ Compat::CAMPOS_TECNICOS['transaccion'] ] ?? '' );

		if ( '' === $semilla ) {
			// Sin el campo técnico no hay forma de reconocer un reenvío, así que se
			// usa una clave única: peor que lo anterior, pero nunca reutiliza una.
			$semilla = wp_generate_uuid4();
		}

		return substr( hash( 'sha256', $modo . '|' . $form_id . '|' . $semilla ), 0, 64 );
	}

	/**
	 * Devuelve la sesión ya creada para un envío repetido.
	 *
	 * @param Transaction $transaccion Transacción existente.
	 * @return array{ok:bool,url:string,mensaje:string,campo:string,transaccion:?Transaction}
	 */
	private function reutilizar( Transaction $transaccion ): array {
		Plugin::instancia()->logger()->info(
			'Envío repetido: se reutiliza la sesión de pago existente.',
			array( 'transaccion' => $transaccion->uuid() )
		);

		if ( $transaccion->esta_pagada() ) {
			return array(
				'ok'          => true,
				'url'         => add_query_arg( 't', $transaccion->uuid(), rest_url( Compat::REST_NAMESPACE . '/retorno' ) ),
				'mensaje'     => '',
				'campo'       => '',
				'transaccion' => $transaccion,
			);
		}

		$sesion = (string) $transaccion->valor( 'stripe_session_id', '' );

		if ( '' === $sesion ) {
			return $this->error( __( 'Este envío ya se está procesando. Espera un momento antes de volver a intentarlo.', 'sw-pagos' ) );
		}

		$stripe = ( new Client() )->para( $transaccion->modo() );

		if ( null === $stripe ) {
			return $this->error( __( 'El cobro no está disponible en este momento.', 'sw-pagos' ) );
		}

		try {
			$recuperada = $stripe->checkout->sessions->retrieve( $sesion );

			return array(
				'ok'          => true,
				'url'         => (string) $recuperada->url,
				'mensaje'     => '',
				'campo'       => '',
				'transaccion' => $transaccion,
			);
		} catch ( \Throwable $e ) {
			return $this->error( __( 'Este envío ya se está procesando. Espera un momento antes de volver a intentarlo.', 'sw-pagos' ) );
		}
	}

	/**
	 * Valores planos del envío.
	 *
	 * @param array<string,mixed> $campos Campos.
	 * @return array<string,mixed>
	 */
	private function valores( array $campos ): array {
		$valores = array();

		foreach ( $campos as $id => $campo ) {
			$valores[ (string) $id ] = is_array( $campo ) && array_key_exists( 'value', $campo )
				? $campo['value']
				: $campo;
		}

		return $valores;
	}

	/**
	 * Parámetros UTM presentes en el envío.
	 *
	 * @param array<string,mixed> $valores Valores.
	 * @return array<string,string>
	 */
	private function utm( array $valores ): array {
		$utm = array();

		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $clave ) {
			$valor = trim( (string) ( $valores[ $clave ] ?? '' ) );

			if ( '' !== $valor ) {
				$utm[ $clave ] = mb_substr( $valor, 0, 200 );
			}
		}

		return $utm;
	}

	/**
	 * Identificadores de los campos que no aportan un dato de la persona.
	 *
	 * @param array<string,mixed> $form_settings Ajustes del formulario.
	 * @return string[]
	 */
	private function campos_sin_valor( array $form_settings ): array {
		$tipos = array( 'html', 'hubspot_config', 'swpagos-hubspot-keys', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot', 'hcaptcha' );
		$fuera = array();

		foreach ( (array) ( $form_settings['form_fields'] ?? array() ) as $campo ) {
			if ( is_array( $campo ) && in_array( (string) ( $campo['field_type'] ?? '' ), $tipos, true ) ) {
				$fuera[] = (string) ( $campo['custom_id'] ?? '' );
			}
		}

		return array_values( array_filter( $fuera ) );
	}

	/**
	 * Metadatos propios que se guardan con la transacción.
	 *
	 * @param array<string,mixed> $valores       Valores del envío.
	 * @param Plantilla           $plantilla     Plantilla.
	 * @param array<string,mixed> $form_settings Ajustes del formulario.
	 * @return array<string,mixed>
	 */
	private function metadata( array $valores, Plantilla $plantilla, array $form_settings = array() ): array {
		$excluidos = array_merge(
			array_values( Compat::CAMPOS_TECNICOS ),
			array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ),
			// Campos que no son un dato de la persona: el de configuración de
			// HubSpot, los rótulos de texto suelto y los antibots. Guardarlos
			// dejaría marcadores vacíos sin ningún sentido.
			$this->campos_sin_valor( $form_settings )
		);

		$metadata = array( 'plantilla' => $plantilla->titulo() );

		foreach ( $valores as $clave => $valor ) {
			if ( in_array( $clave, $excluidos, true ) || ! is_scalar( $valor ) ) {
				continue;
			}

			$metadata[ $clave ] = mb_substr( (string) $valor, 0, 500 );
		}

		// El módulo de HubSpot ya registró este envío en su propia tabla, con el
		// payload completo y sin mandarlo (el formulario lleva su campo pero no
		// su acción). Guardamos ese identificador para mandarlo cuando Stripe
		// confirme el cobro: un pago rechazado nunca debe entrar a HubSpot.
		$registro = \SWPagos\HubSpot\Hef_Bridge::registro_del_envio();

		if ( $registro > 0 ) {
			$metadata['_hef_log'] = $registro;
		}

		return $metadata;
	}

	/**
	 * Resultado de error.
	 *
	 * @param string $mensaje Mensaje.
	 * @param string $campo   Campo afectado.
	 * @return array{ok:bool,url:string,mensaje:string,campo:string,transaccion:?Transaction}
	 */
	private function error( string $mensaje, string $campo = '' ): array {
		return array(
			'ok'          => false,
			'url'         => '',
			'mensaje'     => $mensaje,
			'campo'       => $campo,
			'transaccion' => null,
		);
	}
}
