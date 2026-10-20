<?php
/**
 * Pro Elements form-widget integration and frontend rendering adapter.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the independent condition engine into the verified classic Form widget.
 */
final class Form_Integration {

	const WIDGET_CONTROL_HOOK = 'elementor/element/form/section_form_fields/before_section_end';
	const PRE_RENDER_HOOK     = 'elementor-pro/forms/pre_render';
	const RENDER_ITEM_FILTER  = 'elementor_pro/forms/render/item';
	const VALIDATION_HOOK     = 'elementor_pro/forms/validation';

	/**
	 * @var Assets
	 */
	private $assets;

	/**
	 * @var Form_Validation
	 */
	private $validation;

	/**
	 * @var Condition_Controls
	 */
	private $controls;

	/** @var array Widget object hash => field index => first-render state. */
	private $initial_states = [];

	/** @var bool */
	private $verified = false;

	/** @var callable|null */
	private $failure_callback;

	/**
	 * @param Assets         $assets Assets controller.
	 * @param Form_Validation $validation Server-side form validation adapter.
	 * @param callable       $failure_callback Receives an administrator notice string.
	 */
	public function __construct( Assets $assets, Form_Validation $validation, $failure_callback ) {
		$this->assets = $assets;
		$this->validation = $validation;
		$this->failure_callback = $failure_callback;
	}

	/**
	 * Register after Pro Elements has initialized its modules.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'elementor/widgets/register', [ $this, 'verify_registered_form_widget' ], PHP_INT_MAX, 1 );
	}

	/**
	 * Check whether the widget, editor controls, and processing hooks passed verification.
	 *
	 * @return bool
	 */
	public function is_verified() {
		return $this->verified;
	}

	/**
	 * Confirm the actual registered widget and required hooks before activation.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function verify_registered_form_widget( $widgets_manager ) {
		if ( $this->verified ) {
			return;
		}

		$widget = $this->find_registered_form_widget( $widgets_manager );
		if ( ! $widget ) {
			$this->fail( __( 'Pro Elements is active, but its classic Form widget is not registered. Conditional logic has been disabled for this request.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		$required_widget_methods = [
			'get_name',
			'get_controls',
			'get_unique_name',
			'update_control',
			'add_render_attribute',
			'remove_render_attribute',
		];
		foreach ( $required_widget_methods as $method ) {
			if ( ! method_exists( $widget, $method ) ) {
				$this->fail( __( 'The registered Pro Elements Form widget does not expose the Elementor APIs required by Smart Form Conditions. Please update Pro Elements and Elementor.', 'smart-form-conditions-pro-elements' ) );
				return;
			}
		}

		if ( ! $this->is_form_processing_compatible() ) {
			$this->fail( __( 'The installed Pro Elements Form widget does not expose the verified render and server-validation hooks required by this version of Smart Form Conditions. The integration was safely disabled.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		$this->controls = new Condition_Controls();
		add_action( self::WIDGET_CONTROL_HOOK, [ $this->controls, 'register_controls' ], 10, 1 );

		// Force the widget to build its control stack now, after our extension hook
		// is registered. This both verifies the extension API and prevents a false
		// positive when the widget exists but lacks the expected form_fields data.
		$widget_controls = $widget->get_controls();
		if ( ! $this->has_condition_controls( $widget_controls ) ) {
			$this->fail( __( 'The Pro Elements Form widget registered, but its form_fields repeater could not accept the conditional-logic controls. This Pro Elements version or configuration is not supported.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		if ( ! has_action( self::WIDGET_CONTROL_HOOK, [ $this->controls, 'register_controls' ] ) ) {
			$this->fail( __( 'The Elementor form control-registration hook is unavailable. Conditional logic has been disabled rather than guessing at an internal API.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		add_action( self::PRE_RENDER_HOOK, [ $this, 'add_frontend_attributes' ], 10, 2 );
		add_filter( self::RENDER_ITEM_FILTER, [ $this, 'filter_rendered_item' ], 999, 3 );
		add_action( self::VALIDATION_HOOK, [ $this->validation, 'validate_conditional_fields' ], PHP_INT_MAX, 2 );

		$this->verified = true;
	}

	/**
	 * Find a registered widget by its runtime widget name, not by manager key.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return object|null
	 */
	private function find_registered_form_widget( $widgets_manager ) {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'get_widget_types' ) ) {
			return null;
		}

		$widgets = $widgets_manager->get_widget_types();
		if ( ! is_array( $widgets ) ) {
			return null;
		}

		foreach ( $widgets as $widget ) {
			if ( is_object( $widget ) && method_exists( $widget, 'get_name' ) && 'form' === $widget->get_name() ) {
				return $widget;
			}
		}

		return null;
	}

	/**
	 * Verify widget control shape and that our names were injected.
	 *
	 * @param mixed $widget_controls Widget controls.
	 * @return bool
	 */
	private function has_condition_controls( $widget_controls ) {
		if ( ! is_array( $widget_controls ) || empty( $widget_controls['form_fields']['fields'] ) || ! is_array( $widget_controls['form_fields']['fields'] ) ) {
			return false;
		}

		$names = [];
		foreach ( $widget_controls['form_fields']['fields'] as $control ) {
			if ( isset( $control['name'] ) ) {
				$names[ $control['name'] ] = isset( $control['type'] ) ? $control['type'] : '';
			}
		}

		return isset( $names['sfcpe_enabled'] )
			&& isset( $names['sfcpe_action'] )
			&& isset( $names['sfcpe_match'] )
			&& isset( $names['sfcpe_conditions'] )
			&& isset( $names['sfcpe_hidden_behavior'] )
			&& 'repeater' === $names['sfcpe_conditions'];
	}

	/**
	 * Inspect implementation methods for the lifecycle hooks observed in the
	 * reference repository. If a future version moves these hooks, fail closed
	 * and show a compatibility notice instead of silently using another hook.
	 *
	 * @return bool
	 */
	private function is_form_processing_compatible() {
		$form_class = '\\ElementorPro\\Modules\\Forms\\Widgets\\Form';
		$record_class = '\\ElementorPro\\Modules\\Forms\\Classes\\Form_Record';
		$ajax_class = '\\ElementorPro\\Modules\\Forms\\Classes\\Ajax_Handler';

		if ( ! class_exists( $form_class ) || ! class_exists( $record_class ) || ! class_exists( $ajax_class ) ) {
			return false;
		}

		$form_methods = [ 'render' ];
		foreach ( $form_methods as $method ) {
			if ( ! method_exists( $form_class, $method ) ) {
				return false;
			}
		}

		$record_methods = [ 'validate', 'get', 'get_form_settings', 'remove_field' ];
		foreach ( $record_methods as $method ) {
			if ( ! method_exists( $record_class, $method ) ) {
				return false;
			}
		}

		$ajax_methods = [ 'get_default_message', 'set_success' ];
		foreach ( $ajax_methods as $method ) {
			if ( ! method_exists( $ajax_class, $method ) ) {
				return false;
			}
		}

		try {
			$errors_property = new \ReflectionProperty( $ajax_class, 'errors' );
			if ( ! $errors_property->isPublic() ) {
				return false;
			}
		} catch ( \ReflectionException $exception ) {
			return false;
		}

		return $this->method_source_contains( $form_class, 'render', self::PRE_RENDER_HOOK )
			&& $this->method_source_contains( $form_class, 'render', self::RENDER_ITEM_FILTER )
			&& $this->method_source_contains( $record_class, 'validate', self::VALIDATION_HOOK );
	}

	/**
	 * Check that a lifecycle method actually invokes the expected hook.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @param string $hook       Hook slug.
	 * @return bool
	 */
	private function method_source_contains( $class_name, $method, $hook ) {
		try {
			$reflection = new \ReflectionMethod( $class_name, $method );
			$file = $reflection->getFileName();
			if ( ! $file || ! is_readable( $file ) ) {
				return false;
			}

			$lines = file( $file );
			if ( ! is_array( $lines ) ) {
				return false;
			}

			$source = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
			return false !== strpos( $source, $hook );
		} catch ( \ReflectionException $exception ) {
			return false;
		}
	}

	/**
	 * Attach serialized conditions to the actual Pro Elements field wrappers.
	 *
	 * The reference Form widget uses the `field-group{index}` render-attribute
	 * key and wraps each field in `.elementor-field-group`. This hook runs before
	 * those wrappers are printed.
	 *
	 * @param array  $instance Current widget settings.
	 * @param object $widget   Form widget instance.
	 * @return void
	 */
	public function add_frontend_attributes( $instance, $widget ) {
		if ( ! is_object( $widget ) ) {
			return;
		}

		$widget_key = spl_object_hash( $widget );
		unset( $this->initial_states[ $widget_key ] );
		if ( ! is_array( $instance ) || empty( $instance['form_fields'] ) || ! is_array( $instance['form_fields'] ) ) {
			return;
		}

		$form_fields = $instance['form_fields'];
		$defaults = Condition_Engine::get_default_values( $form_fields );
		$result = Condition_Engine::calculate_visibility( $form_fields, $defaults );
		$visibility = isset( $result['visibility'] ) && is_array( $result['visibility'] ) ? $result['visibility'] : [];
		$initial_states = [];
		$has_conditions = false;

		foreach ( $form_fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$id = $this->normalize_field_id( isset( $field['custom_id'] ) ? $field['custom_id'] : '' );
			if ( '' === $id ) {
				continue;
			}

			$field_type = isset( $field['field_type'] ) && is_string( $field['field_type'] ) ? $field['field_type'] : '';
			$attribute = 'field-group' . $index;
			$widget->add_render_attribute( $attribute, 'data-sfcpe-field-id', $id );
			$widget->add_render_attribute( $attribute, 'data-sfcpe-field-type', $field_type );

			$config = Condition_Engine::get_frontend_config( $field );
			if ( null === $config ) {
				continue;
			}

			$has_conditions = true;
			$encoded_config = wp_json_encode( $config );
			if ( ! is_string( $encoded_config ) ) {
				continue;
			}

			$hidden = array_key_exists( $id, $visibility ) && false === $visibility[ $id ];
			$required = isset( $field['required'] ) && in_array( strtolower( (string) $field['required'] ), [ '1', 'true', 'yes', 'on' ], true );

			$widget->add_render_attribute( $attribute, 'data-sfcpe-logic', $encoded_config );
			$widget->add_render_attribute( $attribute, 'data-sfcpe-required', $required ? '1' : '0' );
			$widget->add_render_attribute( $attribute, 'class', 'sfcpe-conditional-field' );

			if ( $hidden ) {
				$widget->add_render_attribute( $attribute, 'hidden', 'hidden' );
				$widget->add_render_attribute( $attribute, 'aria-hidden', 'true' );
				$widget->add_render_attribute( $attribute, 'class', 'sfcpe-field-hidden' );
			}

			$initial_states[ $index ] = [
				'hidden'   => $hidden,
				'required' => $required,
			];
		}

		if ( ! $has_conditions ) {
			return;
		}

		$this->initial_states[ $widget_key ] = $initial_states;
		$this->assets->enqueue_frontend();
	}

	/**
	 * Remove an initially hidden field's native required attribute before the
	 * field-specific renderer (select, textarea, radio, acceptance) runs.
	 *
	 * @param array  $item       Form field settings.
	 * @param int    $item_index Field index.
	 * @param object $widget     Form widget instance.
	 * @return array
	 */
	public function filter_rendered_item( $item, $item_index, $widget ) {
		if ( ! is_array( $item ) || ! is_object( $widget ) ) {
			return $item;
		}

		$widget_key = spl_object_hash( $widget );
		if ( empty( $this->initial_states[ $widget_key ][ $item_index ]['hidden'] ) ) {
			return $item;
		}

		$item['required'] = false;
		if ( method_exists( $widget, 'remove_render_attribute' ) ) {
			$widget->remove_render_attribute( 'input' . $item_index, 'required' );
		}

		return $item;
	}

	/**
	 * Normalize a field ID as defined by Pro Elements' form editor.
	 *
	 * @param mixed $value Candidate field ID.
	 * @return string
	 */
	private function normalize_field_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		return preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ? $value : '';
	}

	/**
	 * Fail safely and defer the message to the plugin's admin-notice handler.
	 *
	 * @param string $message Administrator-facing message.
	 * @return void
	 */
	private function fail( $message ) {
		if ( is_callable( $this->failure_callback ) ) {
			call_user_func( $this->failure_callback, $message );
		}
	}
}
