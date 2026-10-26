/**
 * Biblioteca de formularios: copiado al portapapeles del editor de Elementor.
 *
 * Elementor guarda lo copiado en el almacenamiento local del navegador, en el
 * mismo origen que esta pantalla. Por eso copiar aquí y pegar en el editor
 * funciona sin pasar por el servidor.
 */
( function () {
	'use strict';

	var datos = window.swpagosBiblioteca || {};

	function aviso( texto, ok ) {
		var caja = document.getElementById( 'swpagos-aviso-copia' );
		if ( ! caja ) {
			window.alert( texto );
			return;
		}
		caja.className = 'notice notice-' + ( ok ? 'success' : 'warning' );
		caja.style.display = 'block';
		caja.querySelector( 'p' ).textContent = texto;
		caja.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	/**
	 * Sobre que Elementor espera encontrar en el portapapeles.
	 *
	 * Al pegar comprueba que «type» sea «elementor» y que «elements» traiga
	 * algo; con la lista de elementos a secas no pega nada y no avisa de nada.
	 *
	 * @param {Array} elementos Árbol de elementos.
	 * @return {Object} Sobre completo.
	 */
	function sobre( elementos ) {
		return {
			type: datos.tipo || 'elementor',
			siteurl: datos.siteurl || '',
			elements: elementos
		};
	}

	/**
	 * Escribe los elementos en el portapapeles de Elementor.
	 *
	 * @param {Array} elementos Árbol de elementos.
	 * @return {boolean} Si se pudo escribir.
	 */
	function alPortapapelesDeElementor( elementos ) {
		try {
			var crudo = window.localStorage.getItem( datos.almacen );
			var almacen = crudo ? JSON.parse( crudo ) : {};

			if ( typeof almacen !== 'object' || almacen === null ) {
				almacen = {};
			}

			almacen[ datos.portapapeles ] = sobre( elementos );

			// Elementor caduca sus entradas por esta clave; si se perdiera,
			// tomaría por caducado lo que acabamos de escribir.
			if ( typeof almacen.__expiration !== 'object' || almacen.__expiration === null ) {
				almacen.__expiration = {};
			}

			delete almacen.__expiration[ datos.portapapeles ];

			window.localStorage.setItem( datos.almacen, JSON.stringify( almacen ) );
			return true;
		} catch ( e ) {
			// Ventana privada, almacenamiento bloqueado o formato inesperado.
			return false;
		}
	}

	/**
	 * Copia también el mismo sobre al portapapeles del sistema: es lo que lee
	 * Elementor cuando se pega desde otro sitio, y sirve de respaldo si el
	 * almacenamiento local está bloqueado.
	 *
	 * @param {Array} elementos Árbol de elementos.
	 * @return {void}
	 */
	function alPortapapelesDelSistema( elementos ) {
		if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
			return;
		}
		navigator.clipboard.writeText( JSON.stringify( sobre( elementos ) ) ).catch( function () {} );
	}

	document.addEventListener( 'click', function ( evento ) {
		var boton = evento.target.closest( '.swpagos-copiar' );

		if ( ! boton ) {
			return;
		}

		evento.preventDefault();

		var clave = boton.getAttribute( 'data-variante' );
		var elementos = datos.variantes && datos.variantes[ clave ];

		if ( ! elementos ) {
			aviso( datos.textos.fallo, false );
			return;
		}

		var ok = alPortapapelesDeElementor( elementos );
		alPortapapelesDelSistema( elementos );

		aviso( ok ? datos.textos.copiado : datos.textos.fallo, ok );
	} );
} )();
