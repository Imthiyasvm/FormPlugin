( function ( window, document ) {
	'use strict';

	var strings = window.SFCPEEditorControls || {};
	var activeEditor = null;
	var editorHooksRegistered = false;

	function collectionToArray( collection ) {
		if ( ! collection ) {
			return [];
		}
		if ( Array.isArray( collection ) ) {
			return collection;
		}
		if ( Array.isArray( collection.models ) ) {
			return collection.models;
		}
		if ( typeof collection.each === 'function' ) {
			var items = [];
			collection.each( function ( item ) {
				items.push( item );
			} );
			return items;
		}
		return [];
	}

	function read( model, key ) {
		return model && typeof model.get === 'function' ? model.get( key ) : undefined;
	}

	function modelId( field ) {
		var value = read( field, 'custom_id' );
		if ( value === null || typeof value === 'undefined' ) {
			return '';
		}
		value = String( value ).trim();
		return /^[A-Za-z0-9_]+$/.test( value ) ? value : '';
	}

	function isEnabled( field ) {
		var value = read( field, 'sfcpe_enabled' );
		return value === true || [ '1', 'true', 'yes', 'on' ].indexOf( String( value || '' ).toLowerCase() ) !== -1;
	}

	function getRules( field ) {
		return collectionToArray( read( field, 'sfcpe_conditions' ) );
	}

	function isSupportedField( field ) {
		var type = read( field, 'field_type' );
		var operators = strings.operatorsByType || {};
		return typeof type === 'string' && Object.prototype.hasOwnProperty.call( operators, type );
	}

	function getOperatorMap() {
		return strings.operatorLabels || {};
	}

	function buildDependencyGraph( fields ) {
		var graph = new Map();
		fields.forEach( function ( field ) {
			var id = modelId( field );
			if ( id ) {
				graph.set( id, [] );
			}
		} );

		fields.forEach( function ( field ) {
			var target = modelId( field );
			if ( ! target || ! isEnabled( field ) || ! isSupportedField( field ) ) {
				return;
			}

			var sourceList = graph.get( target ) || [];
			getRules( field ).forEach( function ( rule ) {
				var source = read( rule, 'sfcpe_source' );
				var operator = read( rule, 'sfcpe_operator' );
				var sourceField = fields.find( function ( candidate ) {
					return modelId( candidate ) === source;
				} );
				if ( ! sourceField || source === target || ! isSupportedField( sourceField ) ) {
					return;
				}
				var allowed = ( strings.operatorsByType || {} )[ read( sourceField, 'field_type' ) ] || [];
				if ( allowed.indexOf( operator ) !== -1 && sourceList.indexOf( source ) === -1 ) {
					sourceList.push( source );
				}
			} );
			graph.set( target, sourceList );
		} );

		return graph;
	}

	function hasPath( graph, from, to, visited ) {
		if ( from === to ) {
			return true;
		}
		if ( visited.has( from ) ) {
			return false;
		}
		visited.add( from );
		var sources = graph.get( from ) || [];
		for ( var index = 0; index < sources.length; index++ ) {
			if ( hasPath( graph, sources[ index ], to, visited ) ) {
				return true;
			}
		}
		return false;
	}

	function optionLabel( field ) {
		var label = read( field, 'field_label' );
		var id = modelId( field );
		label = typeof label === 'string' ? label.trim() : '';
		return label ? label + ' (' + id + ')' : id;
	}

	function buildSourceOptions( fields, targetField, currentSource, graph ) {
		var targetId = modelId( targetField );
		var options = {};
		options[ '' ] = strings.selectField || 'Select a field';

		fields.forEach( function ( field ) {
			var id = modelId( field );
			if ( ! id || id === targetId || ! isSupportedField( field ) ) {
				return;
			}

			// Adding target -> candidate would create a cycle when candidate
			// already has a dependency path back to the target.
			if ( hasPath( graph, id, targetId, new Set() ) ) {
				return;
			}

			options[ id ] = optionLabel( field );
		} );

		if ( currentSource && ! Object.prototype.hasOwnProperty.call( options, currentSource ) ) {
			options[ currentSource ] = ( strings.missingField || 'Missing or invalid field' ) + ' (' + currentSource + ')';
		}

		return options;
	}

	function buildOperatorOptions( fieldType, currentOperator ) {
		var labels = getOperatorMap();
		var allowed = ( strings.operatorsByType || {} )[ fieldType ] || Object.keys( labels );
		var options = {};
		Object.keys( labels ).forEach( function ( operator ) {
			if ( allowed.indexOf( operator ) !== -1 ) {
				options[ operator ] = labels[ operator ];
			}
		} );

		if ( currentOperator && ! Object.prototype.hasOwnProperty.call( options, currentOperator ) ) {
			options[ currentOperator ] = ( strings.unsupportedOperator || 'Not supported for this field type' ) + ' — ' + ( labels[ currentOperator ] || currentOperator );
		}

		return options;
	}

	function controlsFrom( container ) {
		if ( ! container ) {
			return [];
		}
		if ( Array.isArray( container._views ) ) {
			return container._views;
		}
		if ( typeof container.each === 'function' ) {
			var views = [];
			container.each( function ( view ) {
				views.push( view );
			} );
			return views;
		}
		if ( container._views && typeof container._views === 'object' ) {
			return Object.keys( container._views ).map( function ( key ) {
				return container._views[ key ];
			} );
		}
		return [];
	}

	function findControlView( rowView, controlName ) {
		var children = rowView && rowView.children;
		var views = controlsFrom( children );
		for ( var index = 0; index < views.length; index++ ) {
			if ( read( views[ index ].model, 'name' ) === controlName ) {
				return views[ index ];
			}
		}
		return null;
	}

	function setControlOptions( view, options ) {
		if ( ! view || ! view.model || typeof view.model.get !== 'function' || typeof view.model.set !== 'function' ) {
			return;
		}
		var current = view.model.get( 'options' ) || {};
		if ( JSON.stringify( current ) === JSON.stringify( options ) ) {
			return;
		}
		view.model.set( 'options', options );
		if ( typeof view.render === 'function' ) {
			view.render();
		}
	}

	function getRulesControlView( fieldRowView ) {
		return findControlView( fieldRowView, 'sfcpe_conditions' );
	}

	function refreshControls( state ) {
		if ( ! state || ! state.formFields || ! state.panelView ) {
			return;
		}

		var fields = collectionToArray( state.formFields );
		var graph = buildDependencyGraph( fields );
		var outerRepeater = typeof state.panelView.getControlView === 'function'
			? state.panelView.getControlView( 'form_fields' )
			: null;

		if ( ! outerRepeater || ! outerRepeater.children || typeof outerRepeater.children.findByModel !== 'function' ) {
			return;
		}

		fields.forEach( function ( field ) {
			var targetId = modelId( field );
			if ( ! targetId ) {
				return;
			}

			var fieldRowView = outerRepeater.children.findByModel( field );
			if ( ! fieldRowView ) {
				return;
			}

			var rulesView = getRulesControlView( fieldRowView );
			if ( ! rulesView ) {
				return;
			}

			controlsFrom( rulesView.children ).forEach( function ( ruleRowView ) {
				var sourceControl = findControlView( ruleRowView, 'sfcpe_source' );
				var operatorControl = findControlView( ruleRowView, 'sfcpe_operator' );
				var currentSource = read( ruleRowView.model, 'sfcpe_source' );
				var currentOperator = read( ruleRowView.model, 'sfcpe_operator' );
				var sourceField = fields.find( function ( candidate ) {
					return modelId( candidate ) === currentSource;
				} );

				setControlOptions( sourceControl, buildSourceOptions( fields, field, currentSource, graph ) );
				setControlOptions( operatorControl, buildOperatorOptions( sourceField ? read( sourceField, 'field_type' ) : '', currentOperator ) );
			} );
		} );
	}

	function requestRefresh( state ) {
		if ( ! state || state.refreshPending ) {
			return;
		}
		state.refreshPending = true;
		var schedule = window.requestAnimationFrame
			? window.requestAnimationFrame.bind( window )
			: function ( callback ) {
				return window.setTimeout( callback, 0 );
			};
		schedule( function () {
			state.refreshPending = false;
			bindModels( state );
			refreshControls( state );
		} );
	}

	function addListener( state, object, eventName, callback ) {
		if ( ! object || typeof object.on !== 'function' ) {
			return;
		}
		object.on( eventName, callback );
		state.unbind.push( function () {
			if ( typeof object.off === 'function' ) {
				object.off( eventName, callback );
			}
		} );
	}

	function bindRuleModels( state, ruleCollection ) {
		if ( ! ruleCollection ) {
			return;
		}
		var rules = collectionToArray( ruleCollection );
		rules.forEach( function ( rule ) {
			if ( state.boundRules.has( rule ) ) {
				return;
			}
			state.boundRules.add( rule );
			addListener( state, rule, 'change', function () {
				requestRefresh( state );
			} );
		} );
	}

	function bindModels( state ) {
		var fields = collectionToArray( state.formFields );
		fields.forEach( function ( field ) {
			if ( ! state.boundFields.has( field ) ) {
				state.boundFields.add( field );
				addListener( state, field, 'change', function () {
					requestRefresh( state );
				} );
			}

			var rules = read( field, 'sfcpe_conditions' );
			if ( rules && ! state.boundRuleCollections.has( rules ) ) {
				state.boundRuleCollections.add( rules );
				addListener( state, rules, 'add', function ( rule ) {
					bindRuleModels( state, rules );
					requestRefresh( state );
				} );
				addListener( state, rules, 'remove reset change', function () {
					bindRuleModels( state, rules );
					requestRefresh( state );
				} );
			}
			bindRuleModels( state, rules );
		} );
	}

	function cleanupActiveEditor() {
		if ( ! activeEditor ) {
			return;
		}
		if ( activeEditor.observer ) {
			activeEditor.observer.disconnect();
		}
		activeEditor.unbind.forEach( function ( unbind ) {
			unbind();
		} );
		activeEditor = null;
	}

	function onFormPanelOpen( panel, widgetModel ) {
		cleanupActiveEditor();
		if ( ! widgetModel || read( widgetModel, 'widgetType' ) !== 'form' ) {
			return;
		}

		var panelView = panel && typeof panel.getCurrentPageView === 'function' ? panel.getCurrentPageView() : null;
		var settings = read( widgetModel, 'settings' );
		var formFields = read( settings, 'form_fields' );
		if ( ! panelView || ! formFields ) {
			return;
		}

		var state = {
			panelView: panelView,
			formFields: formFields,
			unbind: [],
			boundFields: new WeakSet(),
			boundRules: new WeakSet(),
			boundRuleCollections: new WeakSet(),
			refreshPending: false,
			observer: null
		};
		activeEditor = state;

		addListener( state, settings, 'change', function () {
			requestRefresh( state );
		} );
		addListener( state, formFields, 'add remove reset change', function () {
			bindModels( state );
			requestRefresh( state );
		} );
		bindModels( state );
		requestRefresh( state );

		var panelElement = panelView.el || ( panelView.$el && panelView.$el[ 0 ] );
		if ( panelElement && typeof MutationObserver !== 'undefined' ) {
			state.observer = new MutationObserver( function () {
				requestRefresh( state );
			} );
			state.observer.observe( panelElement, { childList: true, subtree: true } );
		}
	}

	function registerEditorHooks() {
		if ( editorHooksRegistered || ! window.elementor || ! window.elementor.hooks || typeof window.elementor.hooks.addAction !== 'function' ) {
			return;
		}
		window.elementor.hooks.addAction( 'panel/open_editor/widget', cleanupActiveEditor );
		window.elementor.hooks.addAction( 'panel/open_editor/widget/form', onFormPanelOpen );
		editorHooksRegistered = true;
	}

	if ( window.elementor && window.elementor.hooks ) {
		registerEditorHooks();
	}
	window.addEventListener( 'elementor/init', registerEditorHooks );
}( window, document ) );
