<?php
/**
 * Native Elementor controls added to Pro Elements' form-field repeater.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the condition builder inside the verified Pro Elements Form widget.
 */
final class Condition_Controls {

	const CONTROL_PREFIX = 'sfcpe_';

	/**
	 * Return translated operator labels for Elementor controls and editor JS.
	 *
	 * @return array
	 */
	public static function get_operator_labels() {
		return [
			'equals'                    => __( 'Equals', 'smart-form-conditions-pro-elements' ),
			'not_equals'                => __( 'Does Not Equal', 'smart-form-conditions-pro-elements' ),
			'contains'                  => __( 'Contains', 'smart-form-conditions-pro-elements' ),
			'not_contains'              => __( 'Does Not Contain', 'smart-form-conditions-pro-elements' ),
			'starts_with'               => __( 'Starts With', 'smart-form-conditions-pro-elements' ),
			'ends_with'                 => __( 'Ends With', 'smart-form-conditions-pro-elements' ),
			'is_empty'                  => __( 'Is Empty', 'smart-form-conditions-pro-elements' ),
			'is_not_empty'              => __( 'Is Not Empty', 'smart-form-conditions-pro-elements' ),
			'greater_than'              => __( 'Greater Than', 'smart-form-conditions-pro-elements' ),
			'less_than'                 => __( 'Less Than', 'smart-form-conditions-pro-elements' ),
			'greater_than_or_equal'     => __( 'Greater Than or Equal', 'smart-form-conditions-pro-elements' ),
			'less_than_or_equal'        => __( 'Less Than or Equal', 'smart-form-conditions-pro-elements' ),
			'is_checked'                => __( 'Is Checked', 'smart-form-conditions-pro-elements' ),
			'is_unchecked'              => __( 'Is Unchecked', 'smart-form-conditions-pro-elements' ),
			'is_selected'               => __( 'Is Selected', 'smart-form-conditions-pro-elements' ),
			'is_not_selected'           => __( 'Is Not Selected', 'smart-form-conditions-pro-elements' ),
		];
	}

	/**
	 * Add the controls to the Pro Elements `form_fields` repeater.
	 *
	 * This is attached to the same supported Elementor control hook used by
	 * Pro Elements' own form field extensions. No Pro Elements files are edited.
	 *
	 * @param object $widget Pro Elements form widget.
	 * @return bool
	 */
	public function register_controls( $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'form' !== $widget->get_name() ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;
		if ( ! isset( $elementor->controls_manager ) || ! is_object( $elementor->controls_manager ) || ! method_exists( $elementor->controls_manager, 'get_control_from_stack' ) ) {
			return false;
		}

		if ( ! method_exists( $widget, 'get_unique_name' ) || ! method_exists( $widget, 'update_control' ) ) {
			return false;
		}

		$control_data = $elementor->controls_manager->get_control_from_stack( $widget->get_unique_name(), 'form_fields' );
		if ( is_wp_error( $control_data ) || ! is_array( $control_data ) || empty( $control_data['fields'] ) || ! is_array( $control_data['fields'] ) ) {
			return false;
		}

		$existing_names = [];
		foreach ( $control_data['fields'] as $field_control ) {
			if ( isset( $field_control['name'] ) ) {
				$existing_names[] = $field_control['name'];
			}
		}

		// Do not duplicate controls if a widget's control stack is rebuilt.
		if ( in_array( 'sfcpe_enabled', $existing_names, true ) ) {
			return true;
		}

		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			'sfcpe_source',
			[
				'label'       => __( 'Source Field', 'smart-form-conditions-pro-elements' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => [
					'' => __( 'Select a field', 'smart-form-conditions-pro-elements' ),
				],
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Choose another supported field in this form. A field cannot depend on itself.', 'smart-form-conditions-pro-elements' ),
			]
		);

		$repeater->add_control(
			'sfcpe_operator',
			[
				'label'   => __( 'Comparison Operator', 'smart-form-conditions-pro-elements' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => self::get_operator_labels(),
				'default' => 'equals',
			]
		);

		$repeater->add_control(
			'sfcpe_value',
			[
				'label'       => __( 'Comparison Value', 'smart-form-conditions-pro-elements' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'description' => __( 'For select, radio, and checkbox fields, use the submitted option value (the text after “|”), not its label.', 'smart-form-conditions-pro-elements' ),
				'conditions'  => [
					'terms' => [
						[
							'name'     => 'sfcpe_operator',
							'operator' => '!in',
							'value'    => [ 'is_empty', 'is_not_empty', 'is_checked', 'is_unchecked' ],
						],
					],
				],
			]
		);

		$rules_control = [
			'name'         => 'sfcpe_conditions',
			'label'        => __( 'Conditions', 'smart-form-conditions-pro-elements' ),
			'type'         => \Elementor\Controls_Manager::REPEATER,
			'fields'       => $repeater->get_controls(),
			'default'      => [],
			'title_field'  => __( 'Condition Rule', 'smart-form-conditions-pro-elements' ),
			'button_text'  => __( 'Add Condition', 'smart-form-conditions-pro-elements' ),
			'prevent_empty' => false,
			'item_actions' => [
				'add'       => true,
				'duplicate' => true,
				'remove'    => true,
				'sort'      => true,
			],
			'conditions'   => self::get_enabled_condition(),
			'tab'          => 'content',
			'inner_tab'    => 'form_fields_content_tab',
			'tabs_wrapper' => 'form_fields_tabs',
		];

		$additions = [
			[
				'name'         => 'sfcpe_heading',
				'label'        => __( 'Conditional Logic', 'smart-form-conditions-pro-elements' ),
				'type'         => \Elementor\Controls_Manager::HEADING,
				'separator'    => 'before',
				'conditions'   => self::get_supported_field_condition(),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			],
			[
				'name'         => 'sfcpe_enabled',
				'label'        => __( 'Enable Conditions', 'smart-form-conditions-pro-elements' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'label_on'     => __( 'Yes', 'smart-form-conditions-pro-elements' ),
				'label_off'    => __( 'No', 'smart-form-conditions-pro-elements' ),
				'conditions'   => self::get_supported_field_condition(),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			],
			[
				'name'         => 'sfcpe_action',
				'label'        => __( 'Action', 'smart-form-conditions-pro-elements' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'options'      => [
					'show' => __( 'Show', 'smart-form-conditions-pro-elements' ),
					'hide' => __( 'Hide', 'smart-form-conditions-pro-elements' ),
				],
				'default'      => 'show',
				'conditions'   => self::get_enabled_condition(),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			],
			[
				'name'         => 'sfcpe_match',
				'label'        => __( 'Match Rules', 'smart-form-conditions-pro-elements' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'options'      => [
					'all' => __( 'ALL (AND)', 'smart-form-conditions-pro-elements' ),
					'any' => __( 'ANY (OR)', 'smart-form-conditions-pro-elements' ),
				],
				'default'      => 'all',
				'conditions'   => self::get_enabled_condition(),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			],
			$rules_control,
			[
				'name'         => 'sfcpe_hidden_behavior',
				'label'        => __( 'Behavior When Hidden', 'smart-form-conditions-pro-elements' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'options'      => [
					'clear'  => __( 'Clear Value', 'smart-form-conditions-pro-elements' ),
					'retain' => __( 'Retain Value', 'smart-form-conditions-pro-elements' ),
				],
				'default'      => 'clear',
				'description'  => __( 'Clear removes hidden values in the browser and from Pro Elements submissions. Retain keeps submitted values, but hidden fields are not required.', 'smart-form-conditions-pro-elements' ),
				'conditions'   => self::get_enabled_condition(),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			],
		];

		foreach ( $additions as $addition ) {
			if ( ! in_array( $addition['name'], $existing_names, true ) ) {
				$control_data['fields'][] = $addition;
			}
		}

		$widget->update_control( 'form_fields', $control_data );
		return true;
	}

	/**
	 * Condition terms for supported dependent field types.
	 *
	 * @return array
	 */
	private static function get_supported_field_condition() {
		return [
			'terms' => [
				[
					'name'     => 'field_type',
					'operator' => 'in',
					'value'    => Condition_Engine::SUPPORTED_FIELD_TYPES,
				],
			],
		];
	}

	/**
	 * Condition terms for controls shown after the toggle is enabled.
	 *
	 * @return array
	 */
	private static function get_enabled_condition() {
		$condition = self::get_supported_field_condition();
		$condition['terms'][] = [
			'name'  => 'sfcpe_enabled',
			'value' => 'yes',
		];
		return $condition;
	}
}
