<?php
/**
 * Pure condition normalization and evaluation for Pro Elements forms.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates the saved, per-field condition configuration.
 *
 * This class deliberately has no dependency on Elementor or Pro Elements. It is
 * used by both frontend rendering and the server-side validation adapter.
 */
final class Condition_Engine {

	const SUPPORTED_FIELD_TYPES = [
		'text',
		'email',
		'textarea',
		'url',
		'tel',
		'number',
		'select',
		'radio',
		'checkbox',
		'acceptance',
		'date',
		'time',
	];

	const TEXT_OPERATORS = [
		'equals',
		'not_equals',
		'contains',
		'not_contains',
		'starts_with',
		'ends_with',
		'is_empty',
		'is_not_empty',
	];

	const NUMBER_OPERATORS = [
		'equals',
		'not_equals',
		'greater_than',
		'less_than',
		'greater_than_or_equal',
		'less_than_or_equal',
		'is_empty',
		'is_not_empty',
	];

	const CHOICE_OPERATORS = [
		'equals',
		'not_equals',
		'contains',
		'not_contains',
		'is_empty',
		'is_not_empty',
		'is_selected',
		'is_not_selected',
	];

	const CHECKBOX_OPERATORS = [
		'equals',
		'not_equals',
		'contains',
		'not_contains',
		'is_empty',
		'is_not_empty',
		'is_checked',
		'is_unchecked',
		'is_selected',
		'is_not_selected',
	];

	const ACCEPTANCE_OPERATORS = [
		'is_empty',
		'is_not_empty',
		'is_checked',
		'is_unchecked',
	];

	/**
	 * Return operator slugs by source field type.
	 *
	 * @param string $field_type Pro Elements field type.
	 * @return string[]
	 */
	public static function get_operators_for_type( $field_type ) {
		switch ( $field_type ) {
			case 'number':
				return self::NUMBER_OPERATORS;
			case 'select':
			case 'radio':
				return self::CHOICE_OPERATORS;
			case 'checkbox':
				return self::CHECKBOX_OPERATORS;
			case 'acceptance':
				return self::ACCEPTANCE_OPERATORS;
			case 'text':
			case 'email':
			case 'textarea':
			case 'url':
			case 'tel':
			case 'date':
			case 'time':
				return self::TEXT_OPERATORS;
		}

		return [];
	}

	/**
	 * Check whether a field type is supported by this integration.
	 *
	 * @param string $field_type Pro Elements field type.
	 * @return bool
	 */
	public static function is_supported_field_type( $field_type ) {
		return is_string( $field_type ) && in_array( $field_type, self::SUPPORTED_FIELD_TYPES, true );
	}

	/**
	 * Convert a Pro Elements field's saved settings to the safe frontend shape.
	 *
	 * Invalid or missing references are retained in the payload so the runtime
	 * can fail open (visible) rather than silently applying partial logic.
	 *
	 * @param array $field A Pro Elements form_fields repeater row.
	 * @return array|null
	 */
	public static function get_frontend_config( array $field ) {
		if ( ! self::is_enabled( $field ) || ! self::is_supported_field_type( self::get_field_type( $field ) ) ) {
			return null;
		}

		$rules = self::get_raw_rules( $field );
		if ( empty( $rules ) ) {
			return null;
		}

		$normalized_rules = [];
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$normalized_rules[] = [
				'source'   => self::normalize_id( isset( $rule['sfcpe_source'] ) ? $rule['sfcpe_source'] : '' ),
				'operator' => self::normalize_operator( isset( $rule['sfcpe_operator'] ) ? $rule['sfcpe_operator'] : '' ),
				'value'    => self::normalize_expected_value( isset( $rule['sfcpe_value'] ) ? $rule['sfcpe_value'] : '' ),
			];
		}

		if ( empty( $normalized_rules ) ) {
			return null;
		}

		return [
			'enabled'         => true,
			'action'          => self::get_action( $field ),
			'match'           => self::get_match_mode( $field ),
			'hidden_behavior' => self::get_hidden_behavior( $field ),
			'rules'           => $normalized_rules,
		];
	}

	/**
	 * Read default field values for a first-paint visibility calculation.
	 *
	 * @param array $form_fields Pro Elements form field settings.
	 * @return array Field ID => value.
	 */
	public static function get_default_values( array $form_fields ) {
		$values = [];

		foreach ( $form_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$id = self::normalize_id( isset( $field['custom_id'] ) ? $field['custom_id'] : '' );
			if ( '' === $id ) {
				continue;
			}

			$field_type = self::get_field_type( $field );
			if ( 'acceptance' === $field_type && self::is_truthy( isset( $field['checked_by_default'] ) ? $field['checked_by_default'] : '' ) ) {
				$values[ $id ] = [ 'on' ];
				continue;
			}

			$value = isset( $field['field_value'] ) ? $field['field_value'] : '';
			if ( in_array( $field_type, [ 'select', 'radio', 'checkbox' ], true ) ) {
				$options = self::get_choice_option_values( $field );
				$value_string = is_scalar( $value ) ? (string) $value : '';
				if ( 'select' === $field_type ) {
					$is_multiple = self::is_truthy( isset( $field['allow_multiple'] ) ? $field['allow_multiple'] : '' );
					$selected_values = [];
					if ( is_array( $value ) ) {
						$selected_values = $value;
					} elseif ( ! empty( $value_string ) ) {
						$selected_values = explode( ',', $value_string );
					}

					if ( $is_multiple ) {
					$values[ $id ] = array_values( array_filter( $options, static function ( $option_value ) use ( $selected_values ) {
						return in_array( $option_value, $selected_values, true );
					} ) );
					} else {
					$selected = '';
					foreach ( $options as $option_value ) {
						if ( in_array( $option_value, $selected_values, true ) ) {
							$selected = $option_value;
							break;
						}
					}
					// A single-select with no matching selected attribute uses its
					// first option in the browser's native HTML behavior.
					$values[ $id ] = '' !== $selected ? $selected : ( isset( $options[0] ) ? $options[0] : '' );
				}
				} elseif ( 'radio' === $field_type ) {
					$values[ $id ] = ! empty( $value_string ) && in_array( $value_string, $options, true ) ? $value_string : '';
				} else {
					$values[ $id ] = ! empty( $value_string ) && in_array( $value_string, $options, true ) ? [ $value_string ] : [];
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$values[ $id ] = $value;
				continue;
			}

			$values[ $id ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $values;
	}

	/**
	 * Calculate field visibility from saved settings and current values.
	 *
	 * A field in a dependency cycle, a duplicate-ID field, or a conditional field
	 * with no usable rules is kept visible. Any invalid rule causes its target
	 * to fail open rather than partially applying an ALL/ANY group.
	 *
	 * @param array $form_fields Pro Elements form field settings.
	 * @param array $values      Field ID => current value.
	 * @return array Visibility result and cycle IDs.
	 */
	public static function calculate_visibility( array $form_fields, array $values ) {
		$field_map  = [];
		$field_count = [];

		foreach ( $form_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$id = self::normalize_id( isset( $field['custom_id'] ) ? $field['custom_id'] : '' );
			if ( '' === $id ) {
				continue;
			}

			$field_count[ $id ] = isset( $field_count[ $id ] ) ? $field_count[ $id ] + 1 : 1;
			$field_map[ $id ] = $field;
		}

		$configs = [];
		$edges   = [];
		foreach ( $field_map as $id => $field ) {
			$edges[ $id ] = [];

			if ( 1 !== $field_count[ $id ] ) {
				continue;
			}

			$config = self::get_frontend_config( $field );
			if ( null === $config ) {
				continue;
			}

			$config['valid_rule_count'] = 0;
			$config['has_invalid_rule'] = false;
			foreach ( $config['rules'] as &$rule ) {
				$source = $rule['source'];
				$source_exists = '' !== $source && isset( $field_map[ $source ] ) && 1 === $field_count[ $source ];
				$source_type = $source_exists ? self::get_field_type( $field_map[ $source ] ) : '';
				$valid = $source_exists
					&& $source !== $id
					&& self::is_supported_field_type( $source_type )
					&& in_array( $rule['operator'], self::get_operators_for_type( $source_type ), true );

				$rule['valid'] = $valid;
				if ( $valid ) {
					$config['valid_rule_count']++;
					$edges[ $id ][] = $source;
				} else {
					$config['has_invalid_rule'] = true;
				}
			}
			unset( $rule );

			$configs[ $id ] = $config;
		}

		$cycle_nodes = self::find_cycle_nodes( array_keys( $field_map ), $edges );
		$visibility = [];
		$resolving  = [];
		$resolve     = null;

		$resolve = function ( $id ) use ( &$resolve, &$visibility, &$resolving, $cycle_nodes, $configs, $field_map, $values ) {
			if ( array_key_exists( $id, $visibility ) ) {
				return $visibility[ $id ];
			}

			if ( isset( $cycle_nodes[ $id ] ) || isset( $resolving[ $id ] ) ) {
				$visibility[ $id ] = true;
				return true;
			}

			if ( ! isset( $configs[ $id ] ) || empty( $configs[ $id ]['valid_rule_count'] ) || ! empty( $configs[ $id ]['has_invalid_rule'] ) ) {
				$visibility[ $id ] = true;
				return true;
			}

			$resolving[ $id ] = true;
			$config = $configs[ $id ];
			$results = [];

			foreach ( $config['rules'] as $rule ) {
				if ( empty( $rule['valid'] ) ) {
					$results[] = false;
					continue;
				}

				$source_id = $rule['source'];
				$source_visible = $resolve( $source_id );
				if ( ! $source_visible ) {
					$results[] = false;
					continue;
				}

				$source_type = self::get_field_type( $field_map[ $source_id ] );
				$source_value = array_key_exists( $source_id, $values ) ? $values[ $source_id ] : '';
				$results[] = self::evaluate_rule( $source_value, $source_type, $rule['operator'], $rule['value'] );
			}

			$matches = ( 'any' === $config['match'] )
				? in_array( true, $results, true )
				: ( ! empty( $results ) && ! in_array( false, $results, true ) );

			$visibility[ $id ] = ( 'hide' === $config['action'] ) ? ! $matches : $matches;
			unset( $resolving[ $id ] );

			return $visibility[ $id ];
		};

		foreach ( array_keys( $field_map ) as $id ) {
			$resolve( $id );
		}

		return [
			'visibility' => $visibility,
			'cycles'     => array_keys( $cycle_nodes ),
		];
	}

	/**
	 * Evaluate one comparison using trimmed, case-sensitive values.
	 *
	 * Choice-field operators compare selected option values (not option labels).
	 * Numeric comparisons require both operands to be numeric.
	 *
	 * @param mixed  $value      Current source value.
	 * @param string $field_type Source field type.
	 * @param string $operator   Operator slug.
	 * @param string $expected   Comparison value.
	 * @return bool
	 */
	public static function evaluate_rule( $value, $field_type, $operator, $expected ) {
		$values = self::normalize_values( $value );
		$needle = self::normalize_string( $expected );
		$non_empty_values = array_values(
			array_filter(
				$values,
				static function ( $item ) {
					return '' !== $item;
				}
			)
		);
		$is_empty = empty( $non_empty_values );
		$is_choice = in_array( $field_type, [ 'select', 'radio', 'checkbox' ], true );
		$is_number = 'number' === $field_type;

		switch ( $operator ) {
			case 'is_empty':
				return $is_empty;
			case 'is_not_empty':
				return ! $is_empty;
			case 'is_checked':
				return in_array( $field_type, [ 'checkbox', 'acceptance' ], true ) && ! $is_empty;
			case 'is_unchecked':
				return in_array( $field_type, [ 'checkbox', 'acceptance' ], true ) && $is_empty;
			case 'is_selected':
				return $is_choice && '' !== $needle && in_array( $needle, $non_empty_values, true );
			case 'is_not_selected':
				return $is_choice && '' !== $needle && ! in_array( $needle, $non_empty_values, true );
			case 'equals':
				return $is_number ? self::numeric_compare( $values, $needle, '==' ) : self::values_equal( $values, $needle, $is_choice );
			case 'not_equals':
				return $is_number ? self::numeric_compare( $values, $needle, '!=' ) : ! self::values_equal( $values, $needle, $is_choice );
			case 'contains':
				if ( '' === $needle ) {
					return false;
				}
				if ( $is_choice ) {
					return in_array( $needle, $non_empty_values, true );
				}
				foreach ( $non_empty_values as $item ) {
					if ( false !== strpos( $item, $needle ) ) {
						return true;
					}
				}
				return false;
			case 'not_contains':
				if ( '' === $needle ) {
					return false;
				}
				if ( $is_choice ) {
					return ! in_array( $needle, $non_empty_values, true );
				}
				foreach ( $non_empty_values as $item ) {
					if ( false !== strpos( $item, $needle ) ) {
						return false;
					}
				}
				return true;
			case 'starts_with':
				return '' !== $needle && ! empty( $non_empty_values ) && 0 === strpos( $non_empty_values[0], $needle );
			case 'ends_with':
				if ( '' === $needle || empty( $non_empty_values ) ) {
					return false;
				}
				$first = $non_empty_values[0];
				return substr( $first, -strlen( $needle ) ) === $needle;
			case 'greater_than':
				return $is_number && self::numeric_compare( $values, $needle, '>' );
			case 'less_than':
				return $is_number && self::numeric_compare( $values, $needle, '<' );
			case 'greater_than_or_equal':
				return $is_number && self::numeric_compare( $values, $needle, '>=' );
			case 'less_than_or_equal':
				return $is_number && self::numeric_compare( $values, $needle, '<=' );
		}

		return false;
	}

	/**
	 * Find every node that is part of a dependency cycle.
	 *
	 * @param string[] $nodes Node IDs.
	 * @param array    $edges Node ID => dependent source IDs.
	 * @return array Set of cycle IDs.
	 */
	private static function find_cycle_nodes( array $nodes, array $edges ) {
		$state       = [];
		$stack       = [];
		$stack_index = [];
		$cycles      = [];
		$visit       = null;

		$visit = function ( $node ) use ( &$visit, &$state, &$stack, &$stack_index, &$cycles, $edges ) {
			$state[ $node ] = 1;
			$stack_index[ $node ] = count( $stack );
			$stack[] = $node;

			foreach ( isset( $edges[ $node ] ) ? $edges[ $node ] : [] as $source ) {
				if ( ! isset( $state[ $source ] ) ) {
					$visit( $source );
				} elseif ( 1 === $state[ $source ] ) {
					$cycle_start = $stack_index[ $source ];
					for ( $index = $cycle_start; $index < count( $stack ); $index++ ) {
						$cycles[ $stack[ $index ] ] = true;
					}
				}
			}

			array_pop( $stack );
			unset( $stack_index[ $node ] );
			$state[ $node ] = 2;
		};

		foreach ( $nodes as $node ) {
			if ( ! isset( $state[ $node ] ) ) {
				$visit( $node );
			}
		}

		return $cycles;
	}

	/**
	 * Compare a scalar or choice set to the expected value.
	 *
	 * @param array  $values    Normalized values.
	 * @param string $needle    Expected value.
	 * @param bool   $is_choice Whether the source is a choice field.
	 * @return bool
	 */
	private static function values_equal( array $values, $needle, $is_choice ) {
		if ( $is_choice ) {
			return '' !== $needle && in_array( $needle, $values, true );
		}

		$value = empty( $values ) ? '' : $values[0];
		return $value === $needle;
	}

	/**
	 * Compare numeric values without treating an empty string as zero.
	 *
	 * @param array  $values    Normalized values.
	 * @param string $expected Expected number.
	 * @param string $operator Comparison operator.
	 * @return bool
	 */
	private static function numeric_compare( array $values, $expected, $operator ) {
		if ( empty( $values ) || '' === $values[0] || ! is_numeric( $values[0] ) || ! is_numeric( $expected ) ) {
			return false;
		}

		$value = (float) $values[0];
		$comparison = (float) $expected;
		if ( ! is_finite( $value ) || ! is_finite( $comparison ) ) {
			return false;
		}

		switch ( $operator ) {
			case '==':
				return $value === $comparison;
			case '!=':
				return $value !== $comparison;
			case '>':
				return $value > $comparison;
			case '<':
				return $value < $comparison;
			case '>=':
				return $value >= $comparison;
			case '<=':
				return $value <= $comparison;
		}

		return false;
	}

	/**
	 * Get submitted option values using Pro Elements' label|value syntax.
	 *
	 * @param array $field Field settings.
	 * @return string[]
	 */
	private static function get_choice_option_values( array $field ) {
		$options = isset( $field['field_options'] ) && is_string( $field['field_options'] )
			? preg_split( "/\\r\\n|\\r|\\n/", $field['field_options'] )
			: [];
		$values = [];

		foreach ( is_array( $options ) ? $options : [] as $option ) {
			$option_value = $option;
			if ( false !== strpos( $option, '|' ) ) {
				$parts = explode( '|', $option, 2 );
				$option_value = isset( $parts[1] ) ? $parts[1] : '';
			}
			$values[] = $option_value;
		}

		return $values;
	}

	/**
	 * Read raw conditions without assuming they have been validated.
	 *
	 * @param array $field Field settings.
	 * @return array
	 */
	private static function get_raw_rules( array $field ) {
		$rules = isset( $field['sfcpe_conditions'] ) ? $field['sfcpe_conditions'] : [];
		return is_array( $rules ) ? array_values( $rules ) : [];
	}

	/**
	 * Get a field type from a saved field row.
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function get_field_type( array $field ) {
		return isset( $field['field_type'] ) && is_string( $field['field_type'] ) ? strtolower( $field['field_type'] ) : '';
	}

	/**
	 * Determine whether conditions are enabled.
	 *
	 * @param array $field Field settings.
	 * @return bool
	 */
	private static function is_enabled( array $field ) {
		return isset( $field['sfcpe_enabled'] ) && self::is_truthy( $field['sfcpe_enabled'] );
	}

	/**
	 * Get the configured action, defaulting safely to show.
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function get_action( array $field ) {
		return isset( $field['sfcpe_action'] ) && 'hide' === $field['sfcpe_action'] ? 'hide' : 'show';
	}

	/**
	 * Get the configured ALL/ANY mode.
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function get_match_mode( array $field ) {
		return isset( $field['sfcpe_match'] ) && 'any' === $field['sfcpe_match'] ? 'any' : 'all';
	}

	/**
	 * Get the configured hidden-field behavior.
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function get_hidden_behavior( array $field ) {
		return isset( $field['sfcpe_hidden_behavior'] ) && 'retain' === $field['sfcpe_hidden_behavior'] ? 'retain' : 'clear';
	}

	/**
	 * Normalize a field ID to the IDs Pro Elements itself allows in its form UI.
	 *
	 * @param mixed $value Candidate ID.
	 * @return string
	 */
	private static function normalize_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ? $value : '';
	}

	/**
	 * Normalize an operator slug.
	 *
	 * @param mixed $value Candidate operator.
	 * @return string
	 */
	private static function normalize_operator( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Normalize a comparison value from Elementor settings.
	 *
	 * @param mixed $value Candidate comparison value.
	 * @return string
	 */
	private static function normalize_expected_value( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Convert one raw value to trimmed strings, preserving an explicit zero.
	 *
	 * @param mixed $value Raw field value.
	 * @return string[]
	 */
	private static function normalize_values( $value ) {
		if ( ! is_array( $value ) ) {
			if ( ! is_scalar( $value ) ) {
				return [];
			}
			return [ self::normalize_string( $value ) ];
		}

		$values = [];
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$values = array_merge( $values, self::normalize_values( $item ) );
			} elseif ( is_scalar( $item ) ) {
				$values[] = self::normalize_string( $item );
			}
		}

		return $values;
	}

	/**
	 * Convert a scalar to a trimmed string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalize_string( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Treat Elementor switcher truthy values consistently.
	 *
	 * @param mixed $value Switch value.
	 * @return bool
	 */
	private static function is_truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}
}
