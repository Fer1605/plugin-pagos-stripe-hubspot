/**
 * Campos técnicos, UTM y protección contra doble envío.
 *
 * Un único script encolado. El módulo de referencia inyectaba dos bloques casi
 * idénticos en el pie de página, que había que mantener por duplicado y que
 * escribían en los mismos identificadores de campo.
 */
( function () {
	'use strict';

	var cfg = window.swpagosForms || {};
	var UTMS = cfg.utms || [];

	/**
	 * Identificador único para una transacción.
	 *
	 * @return {string} Identificador.
	 */
	function nuevoId() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return 'swp_' + window.crypto.randomUUID();
		}

		var azar = '';
		if ( window.crypto && window.crypto.getRandomValues ) {
			var bytes = window.crypto.getRandomValues( new Uint8Array( 9 ) );
			azar = Array.prototype.map
				.call( bytes, function ( b ) {
					return ( '0' + b.toString( 16 ) ).slice( -2 );
				} )
				.join( '' );
		} else {
			azar = Math.random().toString( 16 ).slice( 2, 20 );
		}

		return 'swp_' + Date.now().toString( 36 ) + '_' + azar;
	}

	/**
	 * Lee un valor del almacenamiento local sin romperse si está bloqueado.
	 *
	 * @param {string} clave Clave.
	 * @return {string} Valor o cadena vacía.
	 */
	function leer( clave ) {
		try {
			return window.localStorage.getItem( clave ) || '';
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Guarda un valor en el almacenamiento local, si se puede.
	 *
	 * @param {string} clave Clave.
	 * @param {string} valor Valor.
	 * @return {void}
	 */
	function guardar( clave, valor ) {
		try {
			window.localStorage.setItem( clave, valor );
		} catch ( e ) {
			// Ventana privada o almacenamiento bloqueado: se sigue sin persistir.
		}
	}

	/**
	 * Campo de un formulario por su identificador de Elementor.
	 *
	 * @param {HTMLFormElement} form Formulario.
	 * @param {string}          id   Identificador del campo.
	 * @return {HTMLElement|null} Campo.
	 */
	function campo( form, id ) {
		return form.querySelector( '[name="form_fields[' + id + ']"]' );
	}

	/**
	 * Captura las UTM de la URL y las recuerda entre páginas.
	 *
	 * @return {Object} Pares utm => valor.
	 */
	function utmsActuales() {
		var params = new URLSearchParams( window.location.search );
		var salida = {};

		UTMS.forEach( function ( utm ) {
			var deLaUrl = params.get( utm );

			if ( deLaUrl ) {
				guardar( 'swpagos_' + utm, deLaUrl );
				salida[ utm ] = deLaUrl;
				return;
			}

			var recordado = leer( 'swpagos_' + utm );
			if ( recordado ) {
				salida[ utm ] = recordado;
			}
		} );

		return salida;
	}

	/**
	 * Prepara un formulario del módulo.
	 *
	 * @param {HTMLFormElement} form Formulario.
	 * @param {Object}          utms UTM detectadas.
	 * @return {void}
	 */
	function preparar( form, utms ) {
		if ( form.dataset.swpagosListo ) {
			return;
		}

		var transaccion = campo( form, cfg.campoTransaccion );

		// Sin el campo técnico, este formulario no es del módulo.
		if ( ! transaccion ) {
			return;
		}

		form.dataset.swpagosListo = '1';

		transaccion.value = nuevoId();
		transaccion.readOnly = true;

		var formulario = campo( form, cfg.campoFormulario );
		if ( formulario && ! formulario.value ) {
			var id = form.querySelector( '[name="form_id"]' );
			formulario.value = id ? id.value : '';
		}

		Object.keys( utms ).forEach( function ( utm ) {
			var destino = campo( form, utm );
			if ( destino && ! destino.value ) {
				destino.value = utms[ utm ];
			}
		} );

		form.addEventListener( 'submit', function () {
			var boton = form.querySelector( 'button[type="submit"]' );

			if ( ! boton ) {
				return;
			}

			if ( boton.dataset.swpagosEnviando ) {
				return;
			}

			boton.dataset.swpagosEnviando = '1';
			boton.setAttribute( 'disabled', 'disabled' );

			// Elementor vuelve a habilitar el botón cuando la respuesta trae un
			// error de validación. Si a los 20 s no pasó nada, se devuelve el
			// control: dejar a alguien con el botón muerto es peor que un doble
			// envío, que de todos modos la clave de idempotencia detiene.
			window.setTimeout( function () {
				delete boton.dataset.swpagosEnviando;
				boton.removeAttribute( 'disabled' );
			}, 20000 );
		} );
	}

	/**
	 * Impide escribir importes negativos.
	 *
	 * El servidor ya los rechaza, así que esto no es la defensa: es evitar que
	 * alguien escriba un número que nunca va a poder enviar y no entienda por qué.
	 * El atributo min no basta: el navegador deja teclear el signo igualmente.
	 *
	 * @param {HTMLFormElement} form Formulario.
	 * @return {void}
	 */
	function soloPositivos( form ) {
		var campo = cfg.campoImporte || 'amount';
		var input = form.querySelector( '[name="form_fields[' + campo + ']"]' );

		if ( ! input || 'number' !== input.type || input.dataset.swpagosPositivo ) {
			return;
		}

		input.dataset.swpagosPositivo = '1';

		if ( ! input.hasAttribute( 'min' ) ) {
			input.setAttribute( 'min', '0' );
		}

		input.addEventListener( 'keydown', function ( evento ) {
			if ( '-' === evento.key || '+' === evento.key || 'e' === evento.key || 'E' === evento.key ) {
				evento.preventDefault();
			}
		} );

		var limpiar = function () {
			if ( input.value.indexOf( '-' ) !== -1 ) {
				input.value = input.value.replace( /-/g, '' );
			}
		};

		input.addEventListener( 'input', limpiar );
		input.addEventListener( 'paste', function () {
			window.setTimeout( limpiar, 0 );
		} );
	}

	/**
	 * Recorre los formularios de la página.
	 *
	 * @return {void}
	 */
	function recorrer() {
		var utms = utmsActuales();

		Array.prototype.forEach.call(
			document.querySelectorAll( 'form.elementor-form' ),
			function ( form ) {
				preparar( form, utms );
				soloPositivos( form );
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', recorrer );
	} else {
		recorrer();
	}

	// Los popups y los contenidos cargados después también traen formularios.
	document.addEventListener( 'elementor/popup/show', recorrer );
	window.addEventListener( 'load', recorrer );
} )();
