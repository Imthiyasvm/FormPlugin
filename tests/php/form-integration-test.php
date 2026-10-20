<?php
/**
 * Standalone rendering-adapter tests with an Elementor-shaped widget double.
 * Run with: php tests/php/form-integration-test.php
 *
 * @package SmartFormConditionsProElements
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SFCPE_URL', 'https://example.test/wp-content/plugins/smart-form-conditions-pro-elements/' );
define( 'SFCPE_VERSION', '1.0.0' );

$script_registry = [];
$style_registry = [];
$enqueued_scripts = [];
$enqueued_styles = [];

function wp_script_is( $handle, $status ) {
	global $script_registry;
	return 'registered' === $status && isset( $script_registry[ $handle ] );
}
function wp_style_is( $handle, $status ) {
	global $style_registry;
	return 'registered' === $status && isset( $style_registry[ $handle ] );
}
function wp_register_script( $handle, $src, $deps, $version, $in_footer ) {
	global $script_registry;
	$script_registry[ $handle ] = compact( 'src', 'deps', 'version', 'in_footer' );
}
function wp_register_style( $handle, $src, $deps, $version ) {
	global $style_registry;
	$style_registry[ $handle ] = compact( 'src', 'deps', 'version' );
}
function wp_enqueue_script( $handle ) {
	global $enqueued_scripts;
	$enqueued_scripts[] = $handle;
}
function wp_enqueue_style( $handle ) {
	global $enqueued_styles;
	$enqueued_styles[] = $handle;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}

require_once __DIR__ . '/../../includes/class-condition-engine.php';
require_once __DIR__ . '/../../includes/class-condition-controls.php';
require_once __DIR__ . '/../../includes/class-assets.php';
require_once __DIR__ . '/../../includes/class-form-validation.php';
require_once __DIR__ . '/../../includes/class-form-integration.php';

use SmartFormConditionsProElements\Assets;
use SmartFormConditionsProElements\Form_Integration;
use SmartFormConditionsProElements\Form_Validation;

class SFCPE_Test_Widget {
	public $attributes = [];
	public $removed_attributes = [];

	public function add_render_attribute( $element, $key, $value = null ) {
		if ( is_array( $key ) ) {
			foreach ( $key as $attribute => $attribute_value ) {
				$this->add_render_attribute( $element, $attribute, $attribute_value );
			}
			return;
		}
		if ( ! isset( $this->attributes[ $element ] ) ) {
			$this->attributes[ $element ] = [];
		}
		if ( 'class' === $key && isset( $this->attributes[ $element ][ $key ] ) ) {
			$this->attributes[ $element ][ $key ] = array_merge( (array) $this->attributes[ $element ][ $key ], [ $value ] );
		} else {
			$this->attributes[ $element ][ $key ] = $value;
		}
	}

	public function remove_render_attribute( $element, $key ) {
		$this->removed_attributes[] = [ $element, $key ];
		unset( $this->attributes[ $element ][ $key ] );
	}
}

$tests_run = 0;
$failures = [];
$assert = static function ( $condition, $message ) use ( &$tests_run, &$failures ) {
	$tests_run++;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$integration = new Form_Integration( new Assets(), new Form_Validation(), static function () {} );
$widget = new SFCPE_Test_Widget();
$settings = [
	'form_fields' => [
		[
			'custom_id' => 'account',
			'field_type' => 'select',
			'field_options' => "Personal|personal\nBusiness|business",
			'field_value' => 'personal',
			'required' => '',
		],
		[
			'custom_id' => 'company',
			'field_type' => 'text',
			'field_value' => '',
			'required' => 'yes',
			'sfcpe_enabled' => 'yes',
			'sfcpe_action' => 'show',
			'sfcpe_match' => 'all',
			'sfcpe_hidden_behavior' => 'clear',
			'sfcpe_conditions' => [
				[
					'sfcpe_source' => 'account',
					'sfcpe_operator' => 'equals',
					'sfcpe_value' => 'business',
				],
			],
		],
		[
			'custom_id' => 'visible',
			'field_type' => 'text',
			'required' => 'yes',
		],
	],
];

$integration->add_frontend_attributes( $settings, $widget );
$company_attributes = isset( $widget->attributes['field-group1'] ) ? $widget->attributes['field-group1'] : [];
$assert( 'company' === $company_attributes['data-sfcpe-field-id'], 'Stable field ID is attached to its Pro Elements field group.' );
$assert( 'text' === $company_attributes['data-sfcpe-field-type'], 'Field type is included for browser value reading.' );
$assert( '1' === $company_attributes['data-sfcpe-required'], 'Original required state is exposed to the frontend.' );
$assert( 'hidden' === $company_attributes['hidden'], 'Initial server-side visibility sets the native hidden attribute.' );
$assert( 'true' === $company_attributes['aria-hidden'], 'Initially hidden field is announced as hidden.' );
$assert( in_array( 'sfcpe-conditional-field', $company_attributes['class'], true ), 'Conditional wrapper receives the plugin class.' );
$assert( in_array( 'sfcpe-field-hidden', $company_attributes['class'], true ), 'Initially hidden wrapper receives the hidden-state class.' );
$assert( 1 === count( $enqueued_scripts ), 'Frontend evaluator is enqueued only when a conditional form is rendered.' );

$encoded_logic = json_decode( $company_attributes['data-sfcpe-logic'], true );
$assert( is_array( $encoded_logic ) && 'clear' === $encoded_logic['hidden_behavior'], 'Safe normalized condition data is serialized for the browser.' );

$filtered_item = $integration->filter_rendered_item( [ 'required' => 'yes', 'field_type' => 'text' ], 1, $widget );
$assert( false === $filtered_item['required'], 'Initially hidden required field is made non-required before rendering.' );
$assert( in_array( [ 'input1', 'required' ], $widget->removed_attributes, true ), 'Native required attribute is removed for initially hidden text input.' );

$visible_item = [ 'required' => 'yes', 'field_type' => 'text' ];
$assert( $visible_item === $integration->filter_rendered_item( $visible_item, 2, $widget ), 'Visible required fields are not modified.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	fwrite( STDERR, sprintf( "\n%d form-integration assertions; %d failed.\n", $tests_run, count( $failures ) ) );
	exit( 1 );
}

echo sprintf( "PASS: %d form-integration assertions (widget API-shaped stub).\n", $tests_run );
