<?php
/**
 * Condition control-schema test with minimal Elementor API-shaped doubles.
 * Run with: php tests/php/condition-controls-test.php
 *
 * @package SmartFormConditionsProElements
 */

namespace Elementor {
	class Plugin {
		public static $instance;
	}

	class Controls_Manager {
		const SELECT = 'select';
		const TEXT = 'text';
		const REPEATER = 'repeater';
		const HEADING = 'heading';
		const SWITCHER = 'switcher';
	}

	class Repeater {
		private $controls = [];

		public function add_control( $name, array $settings ) {
			$settings['name'] = $name;
			$this->controls[] = $settings;
		}

		public function get_controls() {
			return $this->controls;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function __( $text, $domain = null ) {
		return $text;
	}

	function is_wp_error( $value ) {
		return false;
	}

	require_once __DIR__ . '/../../includes/class-condition-engine.php';
	require_once __DIR__ . '/../../includes/class-condition-controls.php';

	class SFCPE_Test_Controls_Manager {
		public $stack;

		public function get_control_from_stack( $stack_id, $control_id ) {
			return $this->stack;
		}
	}

	class SFCPE_Test_Control_Widget {
		public $controls;
		private $unique_name;

		public function __construct( $controls, $unique_name = 'test-form-widget' ) {
			$this->controls = $controls;
			$this->unique_name = $unique_name;
		}

		public function get_name() {
			return 'form';
		}

		public function get_unique_name() {
			return $this->unique_name;
		}

		public function update_control( $name, $data ) {
			$this->controls[ $name ] = $data;
			\Elementor\Plugin::$instance->controls_manager->stack = $data;
		}
	}

	$manager = new SFCPE_Test_Controls_Manager();
	$manager->stack = [
		'name' => 'form_fields',
		'fields' => [
			[ 'name' => 'field_label', 'type' => 'text' ],
			[ 'name' => 'field_type', 'type' => 'select' ],
			[ 'name' => 'custom_id', 'type' => 'text' ],
		],
	];
	\Elementor\Plugin::$instance = (object) [ 'controls_manager' => $manager ];

	$tests_run = 0;
	$failures = [];
	$assert = static function ( $condition, $message ) use ( &$tests_run, &$failures ) {
		$tests_run++;
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};
	$widget = new SFCPE_Test_Control_Widget( [] );
	$controls = new \SmartFormConditionsProElements\Condition_Controls();
	$assert( true === $controls->register_controls( $widget ), 'The supported form widget accepts the condition controls.' );

	$registered = [];
	foreach ( $manager->stack['fields'] as $field ) {
		$registered[ $field['name'] ] = $field;
	}
	$assert( isset( $registered['sfcpe_enabled'], $registered['sfcpe_action'], $registered['sfcpe_match'], $registered['sfcpe_conditions'], $registered['sfcpe_hidden_behavior'] ), 'Expected top-level controls are appended to the form-fields repeater.' );
	$assert( 'repeater' === $registered['sfcpe_conditions']['type'], 'Rules use a nested repeater.' );

	$rule_controls = [];
	foreach ( $registered['sfcpe_conditions']['fields'] as $field ) {
		$rule_controls[ $field['name'] ] = $field;
	}
	$assert( isset( $rule_controls['sfcpe_source'], $rule_controls['sfcpe_operator'], $rule_controls['sfcpe_value'] ), 'Each nested rule exposes source, operator, and comparison value controls.' );
	$assert( [ '' => 'Select a field' ] === $rule_controls['sfcpe_source']['options'], 'Source choices start empty until the editor helper supplies form fields.' );
	$assert( 16 === count( $rule_controls['sfcpe_operator']['options'] ), 'The operator select includes the full localized operator set.' );
	$assert( [ 'clear', 'retain' ] === array_keys( $registered['sfcpe_hidden_behavior']['options'] ), 'Hidden behavior offers Clear and Retain.' );

	$count_after_first = count( $manager->stack['fields'] );
	$assert( true === $controls->register_controls( $widget ), 'Repeated control-stack initialization is tolerated.' );
	$assert( $count_after_first === count( $manager->stack['fields'] ), 'Repeated initialization does not duplicate controls.' );

	$non_form_widget = new class( [] ) extends SFCPE_Test_Control_Widget {
		public function get_name() {
			return 'button';
		}
	};
	$assert( false === $controls->register_controls( $non_form_widget ), 'Unrelated Elementor widgets are not modified.' );

	if ( ! empty( $failures ) ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		fwrite( STDERR, sprintf( "\n%d control-schema assertions; %d failed.\n", $tests_run, count( $failures ) ) );
		exit( 1 );
	}

	echo sprintf( "PASS: %d condition-control assertions (Elementor-shaped stubs).\n", $tests_run );
}
