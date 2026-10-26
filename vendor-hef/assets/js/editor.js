/**
 * Escáner y sincronizador de formularios de HubSpot dentro del editor de Elementor.
 *
 * Al pulsar "Escanear / actualizar campos":
 *  1. Resuelve Portal ID, Form ID y región desde la URL del formulario de HubSpot.
 *  2. Mapea cada campo de Elementor con su propiedad (keyid) más probable.
 *  3. Crea los campos que faltan en Elementor (por defecto, los obligatorios).
 *  4. Sincroniza la obligatoriedad y avisa de las propiedades que ya no existen.
 *
 * @package HubSpot_Elementor_Form
 */
( function ( $ ) {
	'use strict';

	var i18n = ( window.HEFEditor && window.HEFEditor.i18n ) || {};
	var busy = false;
	var scanState = {};
	var lastTrigger = 0;

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function esc( text ) {
		return $( '<div/>' ).text( text == null ? '' : String( text ) ).html();
	}

	/* ------------------------------------------------------------------ *
	 * Contenedores de Elementor
	 * ------------------------------------------------------------------ */

	function getRowContainer( view ) {
		if ( view && view.container ) {
			return view.container;
		}
		if ( view && view.options && view.options.container ) {
			return view.options.container;
		}
		return null;
	}

	/**
	 * Contenedor del widget Formulario que se está editando.
	 *
	 * Se resuelve por varias vías porque la vista del control no siempre
	 * expone el contenedor (según la versión de Elementor).
	 */
	function currentWidgetContainer() {
		var candidates = [];

		try {
			var edited = elementor.getPanelView().getCurrentPageView().getOption( 'editedElementView' );
			if ( edited && edited.getContainer ) {
				candidates.push( edited.getContainer() );
			}
		} catch ( e ) {}

		try {
			if ( elementor.getCurrentElement ) {
				var current = elementor.getCurrentElement();
				if ( current && current.getContainer ) {
					candidates.push( current.getContainer() );
				}
			}
		} catch ( e ) {}

		for ( var i = 0; i < candidates.length; i++ ) {
			var container = candidates[ i ];
			if ( container && container.settings && container.settings.get( 'form_fields' ) ) {
				return container;
			}
		}

		return null;
	}

	function getWidgetContainer( rowContainer ) {
		if ( rowContainer && rowContainer.parent && rowContainer.parent.settings && rowContainer.parent.settings.get( 'form_fields' ) ) {
			return rowContainer.parent;
		}
		return currentWidgetContainer();
	}

	/**
	 * Localiza la fila del campo "HubSpot (configuración)" dentro del widget.
	 *
	 * @param {Object|null} view Vista del control que lanzó la acción (opcional).
	 * @return {Object|null} { widget, rowContainer, rowModel, models }
	 */
	function resolveConfig( view ) {
		var rowContainer = getRowContainer( view );
		var widget = getWidgetContainer( rowContainer );

		if ( ! widget ) {
			return null;
		}

		var collection = widget.settings && widget.settings.get( 'form_fields' );
		var models = collection && collection.models ? collection.models : [];
		var index = -1;

		models.forEach( function ( model, i ) {
			if ( index < 0 && 'hubspot_config' === model.get( 'field_type' ) ) {
				index = i;
			}
		} );

		if ( index < 0 ) {
			return null;
		}

		var containers = getRowContainers( widget );

		// Si la vista no traía contenedor de fila, se toma el del repetidor.
		if ( ! rowContainer || ! rowContainer.settings || 'hubspot_config' !== rowContainer.settings.get( 'field_type' ) ) {
			rowContainer = containers[ index ] || null;
		}

		return {
			widget: widget,
			rowContainer: rowContainer,
			rowModel: models[ index ],
			models: models,
			index: index
		};
	}

	function getRowContainers( widget ) {
		if ( widget && widget.repeaters && widget.repeaters.form_fields && widget.repeaters.form_fields.children ) {
			return widget.repeaters.form_fields.children;
		}
		return [];
	}

	/**
	 * Escribe ajustes usando la API de comandos ($e) para que quede en el
	 * historial de deshacer; si no está disponible, escribe en el modelo.
	 */
	function setSettings( container, settings, model ) {
		try {
			if ( window.$e && container ) {
				$e.run( 'document/elements/settings', {
					container: container,
					settings: settings,
					options: { external: true }
				} );
				return true;
			}
		} catch ( e ) {}

		var target = model || ( container && container.settings );

		if ( target && target.set ) {
			target.set( settings );

			// Sin la API de comandos hay que marcar el documento como modificado
			// para que Elementor active el botón de guardar.
			try {
				$e.internal( 'document/save/set-is-modified', { status: true } );
			} catch ( e ) {
				try {
					elementor.saver.setFlagEditorChange( true );
				} catch ( e2 ) {}
			}

			return true;
		}

		return false;
	}

	/**
	 * Inserta un campo nuevo en el repetidor form_fields.
	 */
	function insertField( widget, model, at ) {
		if ( ! window.$e ) {
			return false;
		}

		try {
			$e.run( 'document/repeater/insert', {
				container: widget,
				name: 'form_fields',
				model: model,
				options: { at: at }
			} );
			return true;
		} catch ( e ) {
			try {
				var collection = widget.settings.get( 'form_fields' );
				collection.add( model, { at: at } );
				return true;
			} catch ( e2 ) {
				return false;
			}
		}
	}

	function uniqueId() {
		if ( window.elementorCommon && elementorCommon.helpers && elementorCommon.helpers.getUniqueId ) {
			return elementorCommon.helpers.getUniqueId();
		}
		return Math.random().toString( 16 ).substr( 2, 7 );
	}

	/* ------------------------------------------------------------------ *
	 * Grupo de mapeos (control hef_mapping)
	 * ------------------------------------------------------------------ */

	// Tipos de campo de Elementor que no aportan un valor enviable.
	var NON_MAPPABLE = [ 'hubspot_config', 'html', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot', 'hcaptcha' ];

	var OBJECTS = [
		[ '0-1', 'Contacto' ],
		[ '0-2', 'Empresa' ],
		[ '0-3', 'Negocio' ],
		[ '0-5', 'Ticket' ]
	];

	function parseJSON( raw, fallback ) {
		if ( ! raw ) {
			return fallback;
		}
		if ( typeof raw === 'object' ) {
			return raw;
		}
		try {
			var parsed = JSON.parse( raw );
			return parsed || fallback;
		} catch ( e ) {
			return fallback;
		}
	}

	/**
	 * Campos mapeables del formulario, en su orden real.
	 */
	function formFieldList( widget ) {
		var collection = widget && widget.settings && widget.settings.get( 'form_fields' );
		var models = collection && collection.models ? collection.models : [];
		var list = [];

		models.forEach( function ( model ) {
			var type = model.get( 'field_type' );

			if ( NON_MAPPABLE.indexOf( type ) !== -1 ) {
				return;
			}

			var id = model.get( 'custom_id' ) || model.get( '_id' );

			list.push( {
				id: id,
				label: model.get( 'field_label' ) || id,
				type: type,
				required: 'true' === model.get( 'required' )
			} );
		} );

		return list;
	}

	/* ------------------------------------------------------------------ *
	 * Traducción de tipos HubSpot -> Elementor
	 * ------------------------------------------------------------------ */

	var TYPE_MAP = {
		email: 'email',
		phonenumber: 'tel',
		phone: 'tel',
		textarea: 'textarea',
		multi_line_text: 'textarea',
		select: 'select',
		dropdown: 'select',
		radio: 'radio',
		booleanradio: 'radio',
		checkbox: 'checkbox',
		multiple_checkboxes: 'checkbox',
		booleancheckbox: 'acceptance',
		single_checkbox: 'acceptance',
		number: 'number',
		date: 'date',
		datepicker: 'date',
		file: 'upload',
		text: 'text',
		single_line_text: 'text'
	};

	function elementorType( hsField ) {
		var type = TYPE_MAP[ hsField.type ] || 'text';
		var name = ( hsField.name || '' ).toLowerCase();

		// HubSpot devuelve muchas propiedades como "text": afinamos por nombre.
		if ( 'text' === type ) {
			if ( name.indexOf( 'email' ) !== -1 ) {
				return 'email';
			}
			if ( name.indexOf( 'phone' ) !== -1 || name.indexOf( 'mobile' ) !== -1 ) {
				return 'tel';
			}
			if ( name.indexOf( 'website' ) !== -1 || name === 'company_website' ) {
				return 'url';
			}
		}

		return type;
	}

	/**
	 * Opciones de HubSpot -> textarea "Etiqueta|valor" de Elementor.
	 */
	function fieldOptions( hsField ) {
		if ( ! hsField.options || ! hsField.options.length ) {
			return '';
		}

		return hsField.options.map( function ( option ) {
			return option.label === option.value ? option.value : option.label + '|' + option.value;
		} ).join( '\n' );
	}

	/**
	 * Construye el modelo del campo de Elementor a partir de la propiedad.
	 */
	function buildFieldModel( hsField, usedIds ) {
		var id = ( hsField.name || '' ).toLowerCase().replace( /[^a-z0-9_\-]/g, '_' ).replace( /^_+|_+$/g, '' );

		if ( ! id ) {
			id = 'hs_' + uniqueId();
		}

		var base = id;
		var n = 2;
		while ( usedIds[ id ] ) {
			id = base + '_' + n;
			n++;
		}
		usedIds[ id ] = true;

		var type = elementorType( hsField );

		var model = {
			_id: uniqueId(),
			custom_id: id,
			field_type: type,
			field_label: hsField.label || hsField.name,
			placeholder: '',
			required: hsField.required ? 'true' : '',
			width: '100'
		};

		var options = fieldOptions( hsField );

		if ( options && ( 'select' === type || 'radio' === type || 'checkbox' === type ) ) {
			model.field_options = options;
		}

		if ( 'acceptance' === type ) {
			model.acceptance_text = hsField.label || hsField.name;
		}

		return model;
	}

	/* ------------------------------------------------------------------ *
	 * Informe
	 * ------------------------------------------------------------------ */

	function renderBox( html, state ) {
		var $box = $( '[data-hef-scan-result]' ).first();

		// Si el control de resultados no está en el panel, se crea junto al botón.
		if ( ! $box.length ) {
			var $anchor = $( '.elementor-control-hef_scan_button' ).first();

			if ( $anchor.length ) {
				$box = $( '<div class="hef-scan-result" data-hef-scan-result="1"></div>' );
				$anchor.after( $box );
			}
		}

		if ( $box.length ) {
			$box.attr( 'data-state', state || 'info' ).html( html );
		}

		var text = $( '<div/>' ).html( html ).text();

		// Los errores no pueden pasar desapercibidos.
		if ( 'error' === state ) {
			if ( ! showReport( html ) && window.elementor && elementor.notifications && elementor.notifications.showToast ) {
				elementor.notifications.showToast( { message: text } );
			}
		} else if ( ! $box.length ) {
			window.console && console.log( '[HubSpot] ' + text );
		}
	}

	function showReport( html ) {
		try {
			if ( window.elementorCommon && elementorCommon.dialogsManager ) {
				elementorCommon.dialogsManager.createWidget( 'alert', {
					id: 'hef-scan-report',
					headerMessage: t( 'reportTitle', 'HubSpot · resultado del escaneo' ),
					message: '<div class="hef-report">' + html + '</div>',
					strings: { confirm: t( 'close', 'Cerrar' ) }
				} ).show();
				return true;
			}
		} catch ( e ) {}

		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Escaneo
	 * ------------------------------------------------------------------ */

	function scan( view ) {
		var now = new Date().getTime();

		// El botón puede dispararse por el canal de Elementor y por el clic
		// delegado: se ignora el segundo disparo.
		if ( busy || ( now - lastTrigger ) < 800 ) {
			return;
		}

		lastTrigger = now;

		try {
			doScan( view );
		} catch ( error ) {
			renderBox( '<p class="hef-scan-error">' + esc( ( error && error.message ) ? error.message : String( error ) ) + '</p>', 'error' );
			window.console && console.error( '[HubSpot Elementor Form]', error );
			busy = false;
		}
	}

	function doScan( view ) {
		renderBox( '<p class="hef-scan-loading">' + esc( t( 'loading', 'Consultando HubSpot…' ) ) + '</p>', 'loading' );

		var config = resolveConfig( view );

		if ( ! config ) {
			renderBox( '<p class="hef-scan-error">' + esc( t( 'noContainer', 'No he podido leer el formulario. Guarda la página y recarga el editor.' ) ) + '</p>', 'error' );
			return;
		}

		var widget = config.widget;
		var rowContainer = config.rowContainer;
		var rowSettings = config.rowModel;
		var url = ( rowSettings && rowSettings.get( 'hef_form_url' ) ) || '';
		var portal = ( rowSettings && rowSettings.get( 'hef_portal_id' ) ) || '';
		var region = ( rowSettings && rowSettings.get( 'hef_region' ) ) || '';
		var overwrite = ( rowSettings && rowSettings.get( 'hef_scan_overwrite' ) ) === 'yes';

		if ( ! url ) {
			url = ( rowSettings && rowSettings.get( 'hef_form_id' ) ) || '';
		}

		if ( ! url ) {
			renderBox( '<p class="hef-scan-error">' + esc( t( 'noUrl', 'Pega antes la URL del formulario de HubSpot.' ) ) + '</p>', 'error' );
			return;
		}

		var models = config.models;
		var fields = [];

		// Mapeo actual: el grupo manda; si está vacío se recoge el mapeo por
		// campo de versiones anteriores del plugin, que así queda migrado.
		var current = {};

		parseJSON( rowSettings && rowSettings.get( 'hef_mapping' ), [] ).forEach( function ( row ) {
			if ( row && row.field && ! current[ row.field ] ) {
				current[ row.field ] = { property: row.property || '', object: row.object || '0-1' };
			}
		} );

		models.forEach( function ( model ) {
			if ( NON_MAPPABLE.indexOf( model.get( 'field_type' ) ) !== -1 ) {
				return;
			}

			var id = model.get( 'custom_id' ) || model.get( '_id' );

			if ( ! current[ id ] && model.get( 'hef_hubspot_property' ) ) {
				current[ id ] = {
					property: model.get( 'hef_hubspot_property' ),
					object: model.get( 'hef_object_type' ) || '0-1'
				};
			}

			fields.push( {
				id: id,
				label: model.get( 'field_label' ) || '',
				type: model.get( 'field_type' ) || '',
				required: 'true' === model.get( 'required' ),
				current: current[ id ] ? current[ id ].property : ''
			} );
		} );

		scanState = { current: current };

		var settings = window.HEFEditor || {};
		var ajaxUrl = settings.ajaxUrl || window.ajaxurl || '';

		if ( ! ajaxUrl || ! settings.nonce ) {
			renderBox( '<p class="hef-scan-error">' + esc( t( 'noConfig', 'No se cargaron los datos del plugin. Recarga el editor.' ) ) + '</p>', 'error' );
			return;
		}

		busy = true;

		$.post( ajaxUrl, {
			action: 'hef_scan',
			nonce: settings.nonce,
			url: url,
			portal_id: portal,
			region: region,
			overwrite: overwrite ? 1 : 0,
			force: 1, // Siempre datos frescos: el objetivo es detectar cambios.
			fields: JSON.stringify( fields )
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					var message = response && response.data && response.data.message ? response.data.message : t( 'genericError', 'No se pudo escanear el formulario.' );
					renderBox( '<p class="hef-scan-error">' + esc( message ) + '</p>', 'error' );
					return;
				}
				try {
					apply( response.data, rowContainer, rowSettings, widget, models );
				} catch ( error ) {
					renderBox( '<p class="hef-scan-error">' + esc( ( error && error.message ) ? error.message : String( error ) ) + '</p>', 'error' );
					window.console && console.error( '[HubSpot Elementor Form]', error );
				}
			} )
			.fail( function () {
				renderBox( '<p class="hef-scan-error">' + esc( t( 'ajaxError', 'Error de conexión con el servidor.' ) ) + '</p>', 'error' );
			} )
			.always( function () {
				busy = false;
			} );
	}

	function apply( data, rowContainer, rowSettings, widget, models ) {
		var addMode = ( rowSettings && rowSettings.get( 'hef_scan_add' ) ) || 'required';
		var syncRequired = ( rowSettings && rowSettings.get( 'hef_scan_sync_required' ) ) !== '';
		var current = scanState.current || {};

		var containers = getRowContainers( widget );
		var mapped = [];
		var usedIds = {};
		var configIndex = models.length;
		var rows = [];

		// 1. Filas del grupo para los campos que ya existen, en su orden.
		models.forEach( function ( model, index ) {
			var id = model.get( 'custom_id' ) || model.get( '_id' );

			usedIds[ id ] = true;

			if ( 'hubspot_config' === model.get( 'field_type' ) ) {
				configIndex = Math.min( configIndex, index );
				return;
			}

			if ( NON_MAPPABLE.indexOf( model.get( 'field_type' ) ) !== -1 ) {
				return;
			}

			var match = data.mapping && data.mapping[ id ];
			var property = '';
			var object = '0-1';

			if ( match ) {
				property = match.property;
				object = match.object || '0-1';
				mapped.push( {
					label: model.get( 'field_label' ) || id,
					property: property,
					score: match.score,
					isNew: true
				} );
			} else if ( current[ id ] && current[ id ].property ) {
				property = current[ id ].property;
				object = current[ id ].object || '0-1';
			}

			if ( property ) {
				rows.push( { field: id, property: property, object: object } );
			}
		} );

		// 2. Sincronizar obligatoriedad.
		var requiredFixed = [];

		if ( syncRequired && data.required_updates && data.required_updates.length ) {
			data.required_updates.forEach( function ( item ) {
				models.forEach( function ( model, index ) {
					if ( ( model.get( 'custom_id' ) || model.get( '_id' ) ) === item.id ) {
						setSettings( containers[ index ] || null, { required: 'true' }, model );
						requiredFixed.push( item );
					}
				} );
			} );
		}

		// 3. Crear los campos que faltan, antes del campo de configuración.
		var created = [];
		var pending = [];
		var review = [];
		var autoAssigned = [];

		function markRequired( id ) {
			models.forEach( function ( model, index ) {
				if ( ( model.get( 'custom_id' ) || model.get( '_id' ) ) === id && 'true' !== model.get( 'required' ) ) {
					setSettings( containers[ index ] || null, { required: 'true' }, model );
				}
			} );
		}

		( data.unmatched || [] ).forEach( function ( hsField ) {
			// Ya existe un campo que probablemente le corresponde: no se crea
			// un duplicado. Si la propiedad es obligatoria se asigna (no puede
			// quedarse sin mapear o HubSpot rechazaría el envío); si es
			// opcional, se deja como sugerencia para que decidas.
			if ( hsField.suggestion ) {
				if ( hsField.required ) {
					rows.push( {
						field: hsField.suggestion.id,
						property: hsField.name,
						object: hsField.object || '0-1'
					} );

					if ( syncRequired ) {
						markRequired( hsField.suggestion.id );
					}

					autoAssigned.push( {
						label: hsField.suggestion.label,
						property: hsField.name,
						reason: hsField.suggestion.reason
					} );
				} else {
					review.push( hsField );
				}
				return;
			}

			var wanted = ( 'all' === addMode ) || ( 'required' === addMode && hsField.required );

			if ( ! wanted ) {
				pending.push( hsField );
				return;
			}

			var model = buildFieldModel( hsField, usedIds );

			if ( insertField( widget, model, configIndex ) ) {
				configIndex++;
				rows.push( { field: model.custom_id, property: hsField.name, object: hsField.object || '0-1' } );
				created.push( {
					label: model.field_label,
					property: hsField.name,
					type: model.field_type,
					required: !! hsField.required
				} );
			} else {
				pending.push( hsField );
			}
		} );

		// 4. Guardar conexión, grupo de mapeos y propiedades disponibles.
		var properties = ( data.properties || [] ).map( function ( item ) {
			return {
				name: item.name,
				label: item.label,
				required: !! item.required,
				object: item.object || '0-1'
			};
		} );

		setSettings(
			rowContainer,
			{
				hef_portal_id: data.portal_id,
				hef_form_id: data.form_id,
				hef_region: data.region || 'na1',
				hef_form_name: data.form_name || '',
				hef_last_scan: new Date().toLocaleString(),
				hef_properties: JSON.stringify( properties ),
				hef_mapping: rows.length ? JSON.stringify( rows ) : ''
			},
			rowSettings
		);

		report( data, mapped, created, requiredFixed, pending, rows, review, autoAssigned );
	}

	function report( data, mapped, created, requiredFixed, pending, rows, review, autoAssigned ) {
		var html = '';
		var problems = ( data.obsolete && data.obsolete.length )
			|| pending.filter( function ( f ) { return f.required; } ).length
			|| ( review && review.length )
			|| ( autoAssigned && autoAssigned.length );

		html += '<p class="hef-scan-ok"><strong>' + esc( data.form_name || t( 'formOk', 'Formulario encontrado' ) ) + '</strong><br>';
		html += 'Portal <code>' + esc( data.portal_id ) + '</code> · Form <code>' + esc( data.form_id ) + '</code>';
		html += ' · ' + esc( data.total ) + ' ' + esc( t( 'properties', 'propiedades' ) ) + '</p>';

		if ( data.obsolete && data.obsolete.length ) {
			html += '<p class="hef-scan-subtitle hef-danger">⚠ ' + esc( t( 'obsolete', 'Propiedades que ya NO existen en HubSpot' ) ) + '</p>';
			html += '<p class="hef-scan-error">' + esc( t( 'obsoleteWarn', 'HubSpot rechazará el envío completo mientras estén mapeadas. Borra la propiedad de estos campos o vuelve a crearla en HubSpot:' ) ) + '</p>';
			html += '<ul class="hef-scan-list">';
			data.obsolete.forEach( function ( item ) {
				html += '<li class="is-danger">' + esc( item.label ) + ' → <code>' + esc( item.property ) + '</code></li>';
			} );
			html += '</ul>';
		}

		if ( created.length ) {
			html += '<p class="hef-scan-subtitle">' + esc( t( 'created', 'Campos creados en el formulario' ) ) + ' (' + created.length + ')</p><ul class="hef-scan-list">';
			created.forEach( function ( item ) {
				html += '<li class="is-new">+ ' + esc( item.label ) + ' <span>(' + esc( item.type ) + ')</span> → <code>' + esc( item.property ) + '</code>' + ( item.required ? ' <span class="hef-req">' + esc( t( 'required', 'obligatoria' ) ) + '</span>' : '' ) + '</li>';
			} );
			html += '</ul>';
		}

		if ( requiredFixed.length ) {
			html += '<p class="hef-scan-subtitle">' + esc( t( 'requiredSynced', 'Marcados como obligatorios (lo son en HubSpot)' ) ) + '</p><ul class="hef-scan-list">';
			requiredFixed.forEach( function ( item ) {
				html += '<li>' + esc( item.label ) + ' → <code>' + esc( item.property ) + '</code></li>';
			} );
			html += '</ul>';
		}

		if ( mapped.length ) {
			html += '<p class="hef-scan-subtitle">' + esc( t( 'mapped', 'Campos mapeados' ) ) + ' (' + mapped.length + ')</p><ul class="hef-scan-list">';
			mapped.forEach( function ( item ) {
				html += '<li class="' + ( item.score >= 88 ? 'is-high' : 'is-low' ) + '">' + esc( item.label ) + ' → <code>' + esc( item.property ) + '</code> <span>' + esc( item.score ) + '%</span></li>';
			} );
			html += '</ul>';
		}

		if ( data.kept && data.kept.length ) {
			html += '<p class="hef-scan-note">' + esc( t( 'kept', 'Mapeos que ya tenías y se han respetado' ) ) + ': ' + data.kept.length + '</p>';
		}

		html += '<p class="hef-scan-note">' + esc( t( 'groupTotal', 'Líneas en el grupo de mapeo' ) ) + ': ' + ( rows ? rows.length : 0 ) + '</p>';

		if ( autoAssigned && autoAssigned.length ) {
			html += '<p class="hef-scan-subtitle">' + esc( t( 'autoAssigned', 'Asignados a un campo que ya tenías (obligatorios en HubSpot)' ) ) + '</p>';
			html += '<p class="hef-scan-note">' + esc( t( 'autoAssignedNote', 'Se han asignado para no dejarlos sin enviar. Compruébalos.' ) ) + '</p>';
			html += '<ul class="hef-scan-list">';
			autoAssigned.forEach( function ( item ) {
				html += '<li class="is-low">' + esc( item.label ) + ' → <code>' + esc( item.property ) + '</code></li>';
			} );
			html += '</ul>';
		}

		if ( review && review.length ) {
			html += '<p class="hef-scan-subtitle">' + esc( t( 'review2', 'Revisa: puede que ya tengas el campo' ) ) + '</p>';
			html += '<p class="hef-scan-note">' + esc( t( 'reviewNote', 'No se han creado para no duplicar. Asigna la propiedad en el grupo de mapeo si corresponde.' ) ) + '</p>';
			html += '<ul class="hef-scan-list">';
			review.forEach( function ( item ) {
				html += '<li class="is-low"><code>' + esc( item.name ) + '</code> ' + esc( item.label )
					+ ( item.required ? ' <span class="hef-req">' + esc( t( 'required', 'obligatoria' ) ) + '</span>' : '' )
					+ '<br><span>↳ ' + esc( t( 'maybe', '¿es tu campo' ) ) + ' "' + esc( item.suggestion.label ) + '"?</span></li>';
			} );
			html += '</ul>';
		}

		if ( pending.length ) {
			html += '<p class="hef-scan-subtitle">' + esc( t( 'unmatched', 'Propiedades de HubSpot sin campo en Elementor' ) ) + '</p><ul class="hef-scan-list is-muted">';
			pending.forEach( function ( item ) {
				html += '<li' + ( item.required ? ' class="is-danger"' : '' ) + '><code>' + esc( item.name ) + '</code> ' + esc( item.label ) + ( item.required ? ' <span class="hef-req">' + esc( t( 'required', 'obligatoria' ) ) + '</span>' : '' ) + '</li>';
			} );
			html += '</ul>';
		}

		if ( ! mapped.length && ! created.length ) {
			html += '<p class="hef-scan-warn">' + esc( t( 'noneMapped', 'No se ha podido emparejar ningún campo automáticamente. Asígnalos a mano.' ) ) + '</p>';
		}

		html += '<p class="hef-scan-note">' + esc( t( 'review', 'Revisa el grupo "Mapeo de campos": puedes cambiar la propiedad, quitar líneas o añadir más.' ) ) + '</p>';

		renderBox( html, problems ? 'warn' : 'ok' );

		// El panel se vuelve a dibujar al crear campos: el informe completo va
		// además en una ventana, que sobrevive al repintado.
		if ( created.length || problems ) {
			showReport( html );
		}

		if ( window.elementor && elementor.notifications && elementor.notifications.showToast ) {
			elementor.notifications.showToast( {
				message: mapped.length + ' ' + t( 'toast', 'campos mapeados con HubSpot' ) + ( created.length ? ' · ' + created.length + ' ' + t( 'toastCreated', 'creados' ) : '' )
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Vista del control hef_mapping: tabla Campo del sitio -> Propiedad
	 * ------------------------------------------------------------------ */

	function objectLabel( id ) {
		for ( var i = 0; i < OBJECTS.length; i++ ) {
			if ( OBJECTS[ i ][ 0 ] === ( id || '0-1' ) ) {
				return OBJECTS[ i ][ 1 ];
			}
		}
		return id;
	}

	function rowHtml( row, fields, properties, index ) {
		var html = '<div class="hef-map-row" data-index="' + index + '">';

		// Dos columnas: campo del sitio | propiedad de HubSpot.
		html += '<div class="hef-map-cols">';

		html += '<select class="hef-map-input hef-map-field">';
		html += '<option value="">' + esc( t( 'chooseField', '— elige un campo —' ) ) + '</option>';

		var known = false;

		fields.forEach( function ( field ) {
			var selected = field.id === row.field;
			known = known || selected;
			html += '<option value="' + esc( field.id ) + '"' + ( selected ? ' selected' : '' ) + '>'
				+ esc( field.label ) + ( field.required ? ' *' : '' ) + '</option>';
		} );

		// El campo mapeado ya no existe en el formulario: se conserva y se avisa.
		if ( row.field && ! known ) {
			html += '<option value="' + esc( row.field ) + '" selected>' + esc( row.field ) + ' — ' + esc( t( 'missingField', 'campo eliminado' ) ) + '</option>';
		}

		html += '</select>';

		html += '<input type="text" class="hef-map-input hef-map-prop" list="' + esc( properties.listId ) + '" placeholder="' + esc( t( 'propertyPlaceholder', 'propiedad (keyid)' ) ) + '" value="' + esc( row.property || '' ) + '">';

		html += '</div>';

		// Pie: el objeto lo define HubSpot (no se elige aquí) + quitar la línea.
		var object = row.object || '0-1';

		html += '<div class="hef-map-foot">';
		html += '<span class="hef-map-drag" title="' + esc( t( 'drag', 'Arrastra para reordenar' ) ) + '">&#9776;</span>';
		html += '<input type="hidden" class="hef-map-object" value="' + esc( object ) + '">';
		html += '<span class="hef-map-object-label' + ( '0-1' === object ? '' : ' is-other' ) + '">' + esc( objectLabel( object ) ) + '</span>';
		html += '<button type="button" class="hef-map-remove" title="' + esc( t( 'remove', 'Quitar' ) ) + '">&times;</button>';
		html += '</div>';

		// Aviso si la propiedad no está entre las del último escaneo.
		if ( row.property && properties.names.length && properties.names.indexOf( row.property ) === -1 ) {
			html += '<div class="hef-map-warn">' + esc( t( 'propNotInForm', 'No está en el formulario de HubSpot: rechazaría el envío.' ) ) + '</div>';
		}

		html += '</div>';

		return html;
	}

	var controlRegistered = false;

	function registerMappingControl() {
		if ( controlRegistered || ! window.elementor || ! elementor.modules || ! elementor.modules.controls || ! elementor.modules.controls.BaseData || ! elementor.addControlView ) {
			return;
		}

		var BaseData = elementor.modules.controls.BaseData;

		var MappingView = BaseData.extend( {

			getWidget: function () {
				return getWidgetContainer( this.container || ( this.options && this.options.container ) );
			},

			getRows: function () {
				var value = parseJSON( this.getControlValue(), [] );
				return Array.isArray( value ) ? value : [];
			},

			saveRows: function ( rows ) {
				this.setValue( rows.length ? JSON.stringify( rows ) : '' );
			},

			getProperties: function () {
				var container = this.container || ( this.options && this.options.container );
				var stored = container && container.settings ? container.settings.get( 'hef_properties' ) : '';
				var list = parseJSON( stored, [] );

				return {
					listId: 'hef-props-' + this.cid,
					names: Array.isArray( list ) ? list.map( function ( item ) { return item.name; } ) : [],
					items: Array.isArray( list ) ? list : []
				};
			},

			renderRows: function () {
				var $wrap = this.$el.find( '.hef-map' );

				if ( ! $wrap.length ) {
					return;
				}

				var rows = this.getRows();
				var fields = formFieldList( this.getWidget() );
				var properties = this.getProperties();
				var html = '';

				rows.forEach( function ( row, index ) {
					html += rowHtml( row, fields, properties, index );
				} );

				$wrap.find( '.hef-map-rows' ).html( html );
				$wrap.find( '.hef-map-empty' ).toggle( ! rows.length );

				// Lista de sugerencias con las propiedades del último escaneo.
				var datalist = '<datalist id="' + properties.listId + '">';
				properties.items.forEach( function ( item ) {
					datalist += '<option value="' + esc( item.name ) + '">' + esc( item.label || '' ) + ( item.required ? ' *' : '' ) + '</option>';
				} );
				datalist += '</datalist>';

				$wrap.find( 'datalist' ).remove();
				$wrap.append( datalist );

				this.initSortable();
			},

			/**
			 * Reordenar filas arrastrando por el tirador.
			 */
			initSortable: function () {
				var $rows = this.$el.find( '.hef-map-rows' );

				if ( ! $rows.length || ! $.fn || ! $.fn.sortable ) {
					return;
				}

				if ( $rows.hasClass( 'ui-sortable' ) ) {
					try {
						$rows.sortable( 'destroy' );
					} catch ( e ) {}
				}

				var view = this;

				$rows.sortable( {
					axis: 'y',
					items: '> .hef-map-row',
					handle: '.hef-map-drag',
					containment: 'parent',
					tolerance: 'pointer',
					placeholder: 'hef-map-placeholder',
					forcePlaceholderSize: true,
					update: function () {
						// serialize() lee las filas en el orden del DOM.
						view.saveRows( view.serialize() );

						// Se repinta fuera del callback para no interferir
						// con el propio arrastre.
						setTimeout( function () {
							view.renderRows();
						}, 0 );
					}
				} );
			},

			serialize: function () {
				var rows = [];
				var byName = {};

				this.getProperties().items.forEach( function ( item ) {
					byName[ item.name ] = item.object || '0-1';
				} );

				// Se conservan también las filas a medio rellenar: son las que
				// el usuario acaba de añadir. Al enviar se ignoran las
				// incompletas (ver Form_Handler::parse_mapping()).
				this.$el.find( '.hef-map-row' ).each( function () {
					var $row = $( this );
					var property = $.trim( $row.find( '.hef-map-prop' ).val() || '' );

					// El objeto (Contacto, Empresa…) lo define la propiedad en
					// HubSpot; no es algo que se configure aquí.
					var object = byName[ property ] || $row.find( '.hef-map-object' ).val() || '0-1';

					rows.push( {
						field: $row.find( '.hef-map-field' ).val() || '',
						property: property,
						object: object
					} );
				} );

				return rows;
			},

			onReady: function () {
				this.renderRows();
			},

			onAfterExternalChange: function () {
				if ( BaseData.prototype.onAfterExternalChange ) {
					BaseData.prototype.onAfterExternalChange.apply( this, arguments );
				}
				this.renderRows();
			},

			events: function () {
				var parent = BaseData.prototype.events ? BaseData.prototype.events.apply( this, arguments ) : {};

				return $.extend( {}, parent, {
					'click .hef-map-add': 'onAddRow',
					'click .hef-map-remove': 'onRemoveRow',
					'change .hef-map-field': 'onRowChange',
					'change .hef-map-prop': 'onRowChange',
					'blur .hef-map-prop': 'onRowChange',
					'mousedown .hef-map-drag': 'onDragStart'
				} );
			},

			onDragStart: function ( event ) {
				// Evita que Elementor interprete el arrastre como mover la fila
				// del repetidor que contiene este control.
				event.stopPropagation();
			},

			onAddRow: function ( event ) {
				event.preventDefault();
				var rows = this.serialize();
				rows.push( { field: '', property: '', object: '0-1' } );
				this.saveRows( rows );
				this.renderRows();
			},

			onRemoveRow: function ( event ) {
				event.preventDefault();

				var $row = $( event.currentTarget ).closest( '.hef-map-row' );
				var index = $row.length && $row.index ? $row.index() : parseInt( $row.data( 'index' ), 10 );
				var rows = this.serialize();

				if ( index >= 0 && index < rows.length ) {
					rows.splice( index, 1 );
				}

				this.saveRows( rows );
				this.renderRows();
			},

			onRowChange: function ( event ) {
				this.saveRows( this.serialize() );

				// Solo se repinta al cambiar de campo o de objeto: repintar
				// mientras se escribe la propiedad haría perder el foco.
				if ( ! event || ! $( event.currentTarget ).hasClass( 'hef-map-prop' ) || 'blur' === event.type ) {
					this.renderRows();
				}
			}
		} );

		elementor.addControlView( 'hef_mapping', MappingView );
		controlRegistered = true;
	}

	// Expuesto para depuración y pruebas.
	window.HEFScanner = {
		elementorType: elementorType,
		fieldOptions: fieldOptions,
		buildFieldModel: buildFieldModel,
		rowHtml: rowHtml,
		resolveConfig: resolveConfig,
		scan: scan
	};

	/**
	 * Engancha el botón "Escanear".
	 *
	 * Se usan dos vías porque el canal de eventos del control BUTTON no está
	 * disponible en todas las versiones de Elementor: el clic delegado
	 * funciona siempre y scan() ignora el disparo duplicado.
	 */
	function bindScanTrigger() {
		try {
			if ( window.elementor && elementor.channels && elementor.channels.editor ) {
				elementor.channels.editor.off( 'hef:scan', scan ).on( 'hef:scan', scan );
			}
		} catch ( e ) {}

		$( document )
			.off( 'click.hefScan' )
			.on( 'click.hefScan', '.hef-scan-button, .elementor-control-hef_scan_button button', function ( event ) {
				event.preventDefault();
				scan( null );
			} );
	}

	function boot() {
		registerMappingControl();
		bindScanTrigger();
	}

	$( window ).on( 'elementor:init', boot );

	// Por si el script se carga después de que Elementor haya arrancado.
	if ( window.elementor ) {
		boot();
	}

	$( window ).on( 'load', boot );
} )( jQuery );
