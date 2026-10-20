( function ( root, factory ) {
	'use strict';

	var api = factory( root );

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}

	if ( root ) {
		root.SFCPEConditions = api;
	}
}( typeof globalThis !== 'undefined' ? globalThis : this, function ( root ) {
	'use strict';

	var SUPPORTED_TYPES = [
		'text', 'email', 'textarea', 'url', 'tel', 'number', 'select', 'radio',
		'checkbox', 'acceptance', 'date', 'time'
	];

	var TEXT_OPERATORS = [
		'equals', 'not_equals', 'contains', 'not_contains', 'starts_with',
		'ends_with', 'is_empty', 'is_not_empty'
	];

	var NUMBER_OPERATORS = [
		'equals', 'not_equals', 'greater_than', 'less_than',
		'greater_than_or_equal', 'less_than_or_equal', 'is_empty', 'is_not_empty'
	];

	var CHOICE_OPERATORS = [
		'equals', 'not_equals', 'contains', 'not_contains', 'is_empty',
		'is_not_empty', 'is_selected', 'is_not_selected'
	];

	var CHECKBOX_OPERATORS = [
		'equals', 'not_equals', 'contains', 'not_contains', 'is_empty',
		'is_not_empty', 'is_checked', 'is_unchecked', 'is_selected',
		'is_not_selected'
	];

	var ACCEPTANCE_OPERATORS = [
		'is_empty', 'is_not_empty', 'is_checked', 'is_unchecked'
	];

	function getOperatorsForType( type ) {
		switch ( type ) {
			case 'number':
				return NUMBER_OPERATORS;
			case 'select':
			case 'radio':
				return CHOICE_OPERATORS;
			case 'checkbox':
				return CHECKBOX_OPERATORS;
			case 'acceptance':
				return ACCEPTANCE_OPERATORS;
			case 'text':
			case 'email':
			case 'textarea':
			case 'url':
			case 'tel':
			case 'date':
			case 'time':
				return TEXT_OPERATORS;
		}

		return [];
	}

	function isSupportedType( type ) {
		return typeof type === 'string' && SUPPORTED_TYPES.indexOf( type ) !== -1;
	}

	function normalizeString( value ) {
		if ( value === false || value === null || typeof value === 'undefined' ) {
			return '';
		}
		if ( value === true ) {
			return '1';
		}
		if ( typeof value === 'object' ) {
			return '';
		}
		return String( value ).trim();
	}

	function normalizeValues( value ) {
		if ( Array.isArray( value ) ) {
			var values = [];
			value.forEach( function ( item ) {
				if ( Array.isArray( item ) ) {
					values = values.concat( normalizeValues( item ) );
				} else if ( item !== null && typeof item !== 'undefined' && typeof item !== 'object' ) {
					values.push( normalizeString( item ) );
				}
			} );
			return values;
		}

		if ( value === null || typeof value === 'undefined' || typeof value === 'object' ) {
			return [];
		}

		return [ normalizeString( value ) ];
	}

	function getNonEmptyValues( values ) {
		return values.filter( function ( value ) {
			return value !== '';
		} );
	}

	function valuesEqual( values, expected, isChoice ) {
		if ( isChoice ) {
			return expected !== '' && values.indexOf( expected ) !== -1;
		}

		return ( values.length ? values[ 0 ] : '' ) === expected;
	}

	function numericCompare( values, expected, operator ) {
		var decimalPattern = /^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i;
		if ( ! values.length || values[ 0 ] === '' || expected === '' || ! decimalPattern.test( values[ 0 ] ) || ! decimalPattern.test( expected ) ) {
			return false;
		}

		var value = Number( values[ 0 ] );
		var comparison = Number( expected );
		if ( ! Number.isFinite( value ) || ! Number.isFinite( comparison ) ) {
			return false;
		}

		switch ( operator ) {
			case 'equals':
				return value === comparison;
			case 'not_equals':
				return value !== comparison;
			case 'greater_than':
				return value > comparison;
			case 'less_than':
				return value < comparison;
			case 'greater_than_or_equal':
				return value >= comparison;
			case 'less_than_or_equal':
				return value <= comparison;
		}

		return false;
	}

	/**
	 * Evaluate one condition. Values are trimmed and comparisons are case-sensitive.
	 * Choice-field operators compare submitted option values, not labels.
	 *
	 * @param {*} value Current field value.
	 * @param {string} fieldType Pro Elements source field type.
	 * @param {string} operator Operator slug.
	 * @param {*} expected Comparison value.
	 * @return {boolean}
	 */
	function evaluateRule( value, fieldType, operator, expected ) {
		var values = normalizeValues( value );
		var nonEmpty = getNonEmptyValues( values );
		var needle = normalizeString( expected );
		var empty = nonEmpty.length === 0;
		var isChoice = [ 'select', 'radio', 'checkbox' ].indexOf( fieldType ) !== -1;
		var isNumber = fieldType === 'number';

		switch ( operator ) {
			case 'is_empty':
				return empty;
			case 'is_not_empty':
				return ! empty;
			case 'is_checked':
				return [ 'checkbox', 'acceptance' ].indexOf( fieldType ) !== -1 && ! empty;
			case 'is_unchecked':
				return [ 'checkbox', 'acceptance' ].indexOf( fieldType ) !== -1 && empty;
			case 'is_selected':
				return isChoice && needle !== '' && nonEmpty.indexOf( needle ) !== -1;
			case 'is_not_selected':
				return isChoice && needle !== '' && nonEmpty.indexOf( needle ) === -1;
			case 'equals':
				return isNumber ? numericCompare( values, needle, 'equals' ) : valuesEqual( values, needle, isChoice );
			case 'not_equals':
				return isNumber ? numericCompare( values, needle, 'not_equals' ) : ! valuesEqual( values, needle, isChoice );
			case 'contains':
				if ( needle === '' ) {
					return false;
				}
				if ( isChoice ) {
					return nonEmpty.indexOf( needle ) !== -1;
				}
				return nonEmpty.some( function ( item ) {
					return item.indexOf( needle ) !== -1;
				} );
			case 'not_contains':
				if ( needle === '' ) {
					return false;
				}
				if ( isChoice ) {
					return nonEmpty.indexOf( needle ) === -1;
				}
				return ! nonEmpty.some( function ( item ) {
					return item.indexOf( needle ) !== -1;
				} );
			case 'starts_with':
				return needle !== '' && nonEmpty.length > 0 && nonEmpty[ 0 ].indexOf( needle ) === 0;
			case 'ends_with':
				if ( needle === '' || ! nonEmpty.length ) {
					return false;
				}
				return nonEmpty[ 0 ].slice( -needle.length ) === needle;
			case 'greater_than':
			case 'less_than':
			case 'greater_than_or_equal':
			case 'less_than_or_equal':
				return fieldType === 'number' && numericCompare( values, needle, operator );
		}

		return false;
	}

	function normalizeId( value ) {
		if ( value === null || typeof value === 'undefined' ) {
			return '';
		}
		var id = String( value ).trim();
		return /^[A-Za-z0-9_]+$/.test( id ) ? id : '';
	}

	function normalizeLogic( field ) {
		var logic = field.conditions || field.logic;
		if ( ! logic || typeof logic !== 'object' || logic.enabled === false ) {
			return null;
		}

		var rules = Array.isArray( logic.rules ) ? logic.rules : [];
		if ( ! rules.length ) {
			return null;
		}

		return {
			action: logic.action === 'hide' ? 'hide' : 'show',
			match: logic.match === 'any' ? 'any' : 'all',
			rules: rules.map( function ( rule ) {
				rule = rule && typeof rule === 'object' ? rule : {};
				return {
					source: normalizeId( rule.source ),
					operator: typeof rule.operator === 'string' ? rule.operator.trim().toLowerCase() : '',
					value: normalizeString( rule.value )
				};
			} )
		};
	}

	function findCycleNodes( ids, edges ) {
		var state = new Map();
		var stack = [];
		var stackIndex = new Map();
		var cycles = new Set();

		function visit( id ) {
			state.set( id, 1 );
			stackIndex.set( id, stack.length );
			stack.push( id );

			( edges.get( id ) || [] ).forEach( function ( source ) {
				if ( ! state.has( source ) ) {
					visit( source );
				} else if ( state.get( source ) === 1 ) {
					var start = stackIndex.get( source );
					for ( var index = start; index < stack.length; index++ ) {
						cycles.add( stack[ index ] );
					}
				}
			} );

			stack.pop();
			stackIndex.delete( id );
			state.set( id, 2 );
		}

		ids.forEach( function ( id ) {
			if ( ! state.has( id ) ) {
				visit( id );
			}
		} );

		return cycles;
	}

	/**
	 * Calculate visibility in dependency order and fail open for cycles or
	 * configurations that contain no usable source references.
	 *
	 * @param {Array} fields [{id,type,conditions:{enabled,action,match,rules}}].
	 * @param {Object} values Field ID => scalar or array value.
	 * @return {{visibility:Object,cycles:Array}}
	 */
	function calculateVisibility( fields, values ) {
		fields = Array.isArray( fields ) ? fields : [];
		values = values && typeof values === 'object' ? values : {};

		var fieldMap = new Map();
		var counts = new Map();
		fields.forEach( function ( field ) {
			if ( ! field || typeof field !== 'object' ) {
				return;
			}
			var id = normalizeId( field.id );
			if ( ! id ) {
				return;
			}
			counts.set( id, ( counts.get( id ) || 0 ) + 1 );
			fieldMap.set( id, field );
		} );

		var configs = new Map();
		var edges = new Map();
		fieldMap.forEach( function ( field, id ) {
			edges.set( id, [] );
			if ( counts.get( id ) !== 1 || ! isSupportedType( field.type ) ) {
				return;
			}

			var config = normalizeLogic( field );
			if ( ! config ) {
				return;
			}

			var validCount = 0;
			var hasInvalidRule = false;
			config.rules.forEach( function ( rule ) {
				var sourceField = fieldMap.get( rule.source );
				var sourceExists = !! sourceField && counts.get( rule.source ) === 1;
				var valid = sourceExists
					&& rule.source !== id
					&& isSupportedType( sourceField.type )
					&& getOperatorsForType( sourceField.type ).indexOf( rule.operator ) !== -1;

				rule.valid = valid;
				if ( valid ) {
					validCount++;
					edges.get( id ).push( rule.source );
				} else {
					hasInvalidRule = true;
				}
			} );

			config.validRuleCount = validCount;
			config.hasInvalidRule = hasInvalidRule;
			configs.set( id, config );
		} );

		var cycles = findCycleNodes( Array.from( fieldMap.keys() ), edges );
		var visibility = Object.create( null );
		var resolving = new Set();

		function resolve( id ) {
			if ( Object.prototype.hasOwnProperty.call( visibility, id ) ) {
				return visibility[ id ];
			}

			if ( cycles.has( id ) || resolving.has( id ) ) {
				visibility[ id ] = true;
				return true;
			}

			var config = configs.get( id );
			if ( ! config || ! config.validRuleCount || config.hasInvalidRule ) {
				visibility[ id ] = true;
				return true;
			}

			resolving.add( id );
			var results = config.rules.map( function ( rule ) {
				if ( ! rule.valid || ! resolve( rule.source ) ) {
					return false;
				}

				var sourceField = fieldMap.get( rule.source );
				var sourceValue = Object.prototype.hasOwnProperty.call( values, rule.source ) ? values[ rule.source ] : '';
				return evaluateRule( sourceValue, sourceField.type, rule.operator, rule.value );
			} );

			var matches = config.match === 'any'
				? results.indexOf( true ) !== -1
				: results.length > 0 && results.indexOf( false ) === -1;

			visibility[ id ] = config.action === 'hide' ? ! matches : matches;
			resolving.delete( id );
			return visibility[ id ];
		}

		Array.from( fieldMap.keys() ).forEach( resolve );

		return {
			visibility: visibility,
			cycles: Array.from( cycles )
		};
	}

	function parseLogic( wrapper ) {
		var raw = wrapper.getAttribute( 'data-sfcpe-logic' );
		if ( ! raw ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( error ) {
			return {
				enabled: true,
				action: 'show',
				match: 'all',
				rules: []
			};
		}
	}

	function readFieldValue( wrapper ) {
		var type = wrapper.getAttribute( 'data-sfcpe-field-type' ) || '';
		var select = wrapper.querySelector( 'select' );
		if ( select ) {
			if ( select.multiple ) {
				return Array.prototype.filter.call( select.options, function ( option ) {
					return option.selected;
				} ).map( function ( option ) {
					return option.value;
				} );
			}
			return select.value;
		}

		if ( type === 'radio' || type === 'checkbox' || type === 'acceptance' ) {
			return Array.prototype.slice.call( wrapper.querySelectorAll( 'input[type="radio"], input[type="checkbox"]' ) )
				.filter( function ( input ) {
					return input.checked;
				} )
				.map( function ( input ) {
					return input.value;
				} );
		}

		var input = wrapper.querySelector( 'textarea, input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"])' );
		return input ? input.value : '';
	}

	function clearFieldValue( wrapper ) {
		Array.prototype.forEach.call( wrapper.querySelectorAll( 'input, select, textarea' ), function ( control ) {
			if ( control.disabled || control.type === 'hidden' || control.type === 'submit' || control.type === 'button' || control.type === 'reset' ) {
				return;
			}

			if ( control.type === 'checkbox' || control.type === 'radio' ) {
				control.checked = false;
			} else if ( control.tagName === 'SELECT' ) {
				Array.prototype.forEach.call( control.options, function ( option ) {
					option.selected = false;
				} );
				control.selectedIndex = -1;
			} else {
				control.value = '';
			}
		} );
	}

	function setRequiredState( wrapper, required ) {
		var fieldType = wrapper.getAttribute( 'data-sfcpe-field-type' ) || '';
		// Pro Elements validates checkbox groups on the server and deliberately
		// does not mark every option required (which would require all options).
		var shouldRequire = !! required && fieldType !== 'checkbox';
		Array.prototype.forEach.call( wrapper.querySelectorAll( 'input, select, textarea' ), function ( control ) {
			if ( control.type === 'hidden' || control.type === 'submit' || control.type === 'button' || control.type === 'reset' ) {
				return;
			}
			control.required = shouldRequire;
		} );
	}

	function setVisibility( wrapper, visible ) {
		var policy = 'retain';
		var config = parseLogic( wrapper );
		if ( config && config.hidden_behavior === 'clear' ) {
			policy = 'clear';
		}

		if ( ! visible ) {
			if ( policy === 'clear' ) {
				clearFieldValue( wrapper );
			}
			wrapper.hidden = true;
			wrapper.setAttribute( 'aria-hidden', 'true' );
			wrapper.setAttribute( 'inert', '' );
			wrapper.classList.add( 'sfcpe-field-hidden' );
			setRequiredState( wrapper, false );
			return;
		}

		wrapper.hidden = false;
		wrapper.removeAttribute( 'aria-hidden' );
		wrapper.removeAttribute( 'inert' );
		wrapper.classList.remove( 'sfcpe-field-hidden' );
		setRequiredState( wrapper, wrapper.getAttribute( 'data-sfcpe-required' ) === '1' );
	}

	function recalculateForm( form ) {
		var wrappers = Array.prototype.slice.call( form.querySelectorAll( '[data-sfcpe-field-id]' ) );
		if ( ! wrappers.length ) {
			return;
		}

		var fields = [];
		var values = Object.create( null );
		wrappers.forEach( function ( wrapper ) {
			var id = wrapper.getAttribute( 'data-sfcpe-field-id' ) || '';
			var logic = parseLogic( wrapper );
			fields.push( {
				id: id,
				type: wrapper.getAttribute( 'data-sfcpe-field-type' ) || '',
				conditions: logic
			} );
			if ( id ) {
				values[ id ] = readFieldValue( wrapper );
			}
		} );

		var result = calculateVisibility( fields, values );
		wrappers.forEach( function ( wrapper ) {
			if ( ! wrapper.hasAttribute( 'data-sfcpe-logic' ) ) {
				return;
			}
			var id = wrapper.getAttribute( 'data-sfcpe-field-id' ) || '';
			var visible = ! Object.prototype.hasOwnProperty.call( result.visibility, id ) || result.visibility[ id ] !== false;
			setVisibility( wrapper, visible );
		} );
	}

	var initializedForms = new WeakSet();
	var recalculateCallbacks = new WeakMap();
	var listenersBound = false;
	var observer = null;

	function initForm( form ) {
		if ( ! form || ! form.querySelectorAll ) {
			return;
		}

		if ( initializedForms.has( form ) ) {
			var existingCallback = recalculateCallbacks.get( form );
			if ( existingCallback ) {
				existingCallback();
			}
			return;
		}

		var hasLogic = form.querySelector( '[data-sfcpe-logic]' );
		if ( ! hasLogic ) {
			return;
		}

		initializedForms.add( form );
		var recalculate = function () {
			recalculateForm( form );
		};
		recalculateCallbacks.set( form, recalculate );
		recalculate();
	}

	function initInNode( node ) {
		if ( ! node || node.nodeType !== 1 ) {
			return;
		}

		if ( node.matches && node.matches( 'form.elementor-form' ) ) {
			initForm( node );
		}

		if ( node.querySelectorAll ) {
			Array.prototype.forEach.call( node.querySelectorAll( 'form.elementor-form' ), initForm );
		}

		if ( node.closest ) {
			var parentForm = node.closest( 'form.elementor-form' );
			if ( parentForm ) {
				initForm( parentForm );
			}
		}
	}

	function onFormChange( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var form = target.closest( 'form.elementor-form' );
		if ( ! form ) {
			return;
		}
		var recalculate = recalculateCallbacks.get( form );
		if ( recalculate ) {
			recalculate();
		} else {
			initForm( form );
		}
	}

	function onFormReset( event ) {
		var form = event.target;
		if ( ! form || ! form.matches || ! form.matches( 'form.elementor-form' ) ) {
			return;
		}
		var recalculate = recalculateCallbacks.get( form );
		if ( recalculate ) {
			setTimeout( recalculate, 0 );
		}
	}

	function attachElementorHook() {
		if ( ! root.elementorFrontend || ! root.elementorFrontend.hooks || typeof root.elementorFrontend.hooks.addAction !== 'function' ) {
			return;
		}
		root.elementorFrontend.hooks.addAction( 'frontend/element_ready/form.default', function ( scope ) {
			var element = scope && scope[ 0 ] ? scope[ 0 ] : scope;
			initInNode( element );
		} );
	}

	function start() {
		if ( typeof document === 'undefined' ) {
			return;
		}

		if ( ! listenersBound ) {
			document.addEventListener( 'input', onFormChange, true );
			document.addEventListener( 'change', onFormChange, true );
			document.addEventListener( 'reset', onFormReset, true );
			listenersBound = true;
		}

		if ( document.documentElement && typeof MutationObserver !== 'undefined' && ! observer ) {
			observer = new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					Array.prototype.forEach.call( mutation.addedNodes, initInNode );
				} );
			} );
			observer.observe( document.documentElement, { childList: true, subtree: true } );
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () {
				initInNode( document.documentElement );
				attachElementorHook();
			} );
		} else {
			initInNode( document.documentElement );
			attachElementorHook();
		}

		root.addEventListener && root.addEventListener( 'elementor/frontend/init', attachElementorHook );
	}

	if ( typeof document !== 'undefined' ) {
		start();
	}

	return {
		SUPPORTED_TYPES: SUPPORTED_TYPES.slice(),
		getOperatorsForType: getOperatorsForType,
		evaluateRule: evaluateRule,
		calculateVisibility: calculateVisibility,
		initInNode: initInNode
	};
} ) );
