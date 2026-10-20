<?php
/**
 * Standalone tests for the PHP condition evaluator.
 * Run with: php tests/php/condition-engine-test.php
 *
 * @package SmartFormConditionsProElements
 */

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../../includes/class-condition-engine.php';

use SmartFormConditionsProElements\Condition_Engine;

$tests_run = 0;
$failures = [];

$assert = static function ( $condition, $message ) use ( &$tests_run, &$failures ) {
	$tests_run++;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$make_field = static function ( $id, $type, array $rules, $action = 'show', $match = 'all' ) {
	$conditions = [];
	foreach ( $rules as $rule ) {
		$conditions[] = [
			'sfcpe_source'   => isset( $rule[0] ) ? $rule[0] : '',
			'sfcpe_operator' => isset( $rule[1] ) ? $rule[1] : '',
			'sfcpe_value'    => isset( $rule[2] ) ? $rule[2] : '',
		];
	}

	return [
		'custom_id'           => $id,
		'field_type'          => $type,
		'sfcpe_enabled'       => 'yes',
		'sfcpe_action'        => $action,
		'sfcpe_match'         => $match,
		'sfcpe_hidden_behavior' => 'clear',
		'sfcpe_conditions'    => $conditions,
	];
};

$fields = [
	[ 'custom_id' => 'account', 'field_type' => 'select' ],
	[ 'custom_id' => 'country', 'field_type' => 'select' ],
	$make_field( 'company', 'text', [ [ 'account', 'equals', 'business' ], [ 'country', 'equals', 'UAE' ] ] ),
	$make_field( 'consultation', 'text', [ [ 'account', 'equals', 'business' ], [ 'country', 'equals', 'UAE' ] ], 'show', 'any' ),
];
$result = Condition_Engine::calculate_visibility( $fields, [ 'account' => 'business', 'country' => 'UAE' ] );
$assert( true === $result['visibility']['company'], 'ALL/AND condition should pass.' );
$assert( true === $result['visibility']['consultation'], 'ANY/OR condition should pass.' );
$result = Condition_Engine::calculate_visibility( $fields, [ 'account' => 'business', 'country' => 'UK' ] );
$assert( false === $result['visibility']['company'], 'ALL/AND condition should fail when one rule fails.' );
$assert( true === $result['visibility']['consultation'], 'ANY/OR condition should pass when one rule passes.' );

$operator_cases = [
	[ 'text', '  Hello ', 'equals', 'Hello', true ],
	[ 'text', 'Hello', 'not_equals', 'Other', true ],
	[ 'text', 'Hello', 'contains', 'ell', true ],
	[ 'text', 'Hello', 'not_contains', 'xyz', true ],
	[ 'text', 'Hello', 'starts_with', 'He', true ],
	[ 'text', 'Hello', 'ends_with', 'lo', true ],
	[ 'text', " \t ", 'is_empty', '', true ],
	[ 'text', 'hello', 'is_not_empty', '', true ],
	[ 'number', '12.5', 'equals', '12.5', true ],
	[ 'number', '12.5', 'equals', '12.50', true ],
	[ 'number', '12.5', 'not_equals', '13', true ],
	[ 'number', '12.5', 'greater_than', '12', true ],
	[ 'number', '12.5', 'less_than', '13', true ],
	[ 'number', '12.5', 'greater_than_or_equal', '12.5', true ],
	[ 'number', '12.5', 'less_than_or_equal', '12.5', true ],
	[ 'number', '', 'greater_than', '0', false ],
	[ 'number', '5', 'not_equals', 'five', false ],
	[ 'number', '0x10', 'equals', '16', false ],
	[ 'select', [ 'website', 'seo' ], 'contains', 'seo', true ],
	[ 'radio', 'business', 'equals', 'business', true ],
	[ 'checkbox', [ 'website', 'seo' ], 'is_selected', 'seo', true ],
	[ 'checkbox', [ 'website' ], 'is_not_selected', 'seo', true ],
	[ 'checkbox', [ '0' ], 'is_checked', '', true ],
	[ 'checkbox', [], 'is_unchecked', '', true ],
	[ 'acceptance', [ 'on' ], 'is_checked', '', true ],
	[ 'acceptance', '', 'is_unchecked', '', true ],
];

foreach ( $operator_cases as $case ) {
	$actual = Condition_Engine::evaluate_rule( $case[1], $case[0], $case[2], $case[3] );
	$assert( $case[4] === $actual, 'Operator failed: ' . $case[2] . ' for ' . $case[0] );
}

$nested_fields = [
	[ 'custom_id' => 'account', 'field_type' => 'select' ],
	$make_field( 'company', 'text', [ [ 'account', 'equals', 'business' ] ] ),
	$make_field( 'tax_id', 'number', [ [ 'company', 'equals', 'ready' ] ] ),
];
$result = Condition_Engine::calculate_visibility( $nested_fields, [ 'account' => 'business', 'company' => 'ready' ] );
$assert( true === $result['visibility']['company'] && true === $result['visibility']['tax_id'], 'Nested dependencies should show in source order.' );
$result = Condition_Engine::calculate_visibility( $nested_fields, [ 'account' => 'personal', 'company' => 'ready' ] );
$assert( false === $result['visibility']['company'] && false === $result['visibility']['tax_id'], 'A hidden source must not activate downstream Show rules.' );

$cycle_fields = [
	$make_field( 'first', 'text', [ [ 'second', 'equals', 'yes' ] ] ),
	$make_field( 'second', 'text', [ [ 'first', 'equals', 'yes' ] ] ),
	$make_field( 'tail', 'text', [ [ 'first', 'is_not_empty', '' ] ] ),
];
$result = Condition_Engine::calculate_visibility( $cycle_fields, [ 'first' => '', 'second' => '' ] );
$assert( in_array( 'first', $result['cycles'], true ) && in_array( 'second', $result['cycles'], true ), 'Cycle members should be detected.' );
$assert( true === $result['visibility']['first'] && true === $result['visibility']['second'], 'Cycle members should fail open as visible.' );
$assert( false === $result['visibility']['tail'], 'Downstream logic may still evaluate against the visible cycle member value.' );

$invalid_fields = [
	[ 'custom_id' => 'text_source', 'field_type' => 'text' ],
	[ 'custom_id' => 'file_source', 'field_type' => 'upload' ],
	$make_field( 'self_target', 'text', [ [ 'self_target', 'equals', 'x' ] ] ),
	$make_field( 'missing_target', 'text', [ [ 'deleted', 'equals', 'x' ] ] ),
	$make_field( 'unsupported_target', 'text', [ [ 'file_source', 'equals', 'x' ] ] ),
	$make_field( 'wrong_operator_target', 'text', [ [ 'text_source', 'greater_than', '5' ] ] ),
];
$result = Condition_Engine::calculate_visibility( $invalid_fields, [] );
foreach ( [ 'self_target', 'missing_target', 'unsupported_target', 'wrong_operator_target' ] as $id ) {
	$assert( true === $result['visibility'][ $id ], 'Invalid reference should fail open: ' . $id );
}

$invalid_and_valid = [
	[ 'custom_id' => 'choice', 'field_type' => 'select' ],
	$make_field( 'all_target', 'text', [ [ 'choice', 'equals', 'yes' ], [ 'deleted', 'equals', 'x' ] ] ),
	$make_field( 'any_target', 'text', [ [ 'choice', 'equals', 'yes' ], [ 'deleted', 'equals', 'x' ] ], 'show', 'any' ),
	$make_field( 'any_hide_target', 'text', [ [ 'choice', 'equals', 'yes' ], [ 'deleted', 'equals', 'x' ] ], 'hide', 'any' ),
];
$result = Condition_Engine::calculate_visibility( $invalid_and_valid, [ 'choice' => 'yes' ] );
$assert( true === $result['visibility']['all_target'], 'An invalid reference fails open instead of applying a partial ALL group.' );
$assert( true === $result['visibility']['any_target'], 'An invalid reference fails open instead of applying a partial ANY group.' );
$assert( true === $result['visibility']['any_hide_target'], 'Invalid ANY rules do not unexpectedly hide the target.' );

$duplicate_fields = [
	[ 'custom_id' => 'source', 'field_type' => 'text' ],
	[ 'custom_id' => 'source', 'field_type' => 'text' ],
	$make_field( 'target', 'text', [ [ 'source', 'equals', 'x' ] ] ),
];
$result = Condition_Engine::calculate_visibility( $duplicate_fields, [ 'source' => 'x' ] );
$assert( true === $result['visibility']['target'], 'Duplicate field IDs must not be treated as a valid condition source.' );

$defaults = Condition_Engine::get_default_values(
	[
		[ 'custom_id' => 'single', 'field_type' => 'select', 'field_options' => "Business|business\nPersonal|personal" ],
		[ 'custom_id' => 'invalid_single', 'field_type' => 'select', 'field_value' => 'missing', 'field_options' => "Business|business\nPersonal|personal" ],
		[ 'custom_id' => 'multiple', 'field_type' => 'select', 'allow_multiple' => 'true', 'field_value' => 'one,missing', 'field_options' => "One|one\nTwo|two" ],
		[ 'custom_id' => 'invalid_radio', 'field_type' => 'radio', 'field_value' => 'missing', 'field_options' => "Yes|yes\nNo|no" ],
		[ 'custom_id' => 'zero_radio', 'field_type' => 'radio', 'field_value' => '0', 'field_options' => "Zero|0\nOne|1" ],
		[ 'custom_id' => 'invalid_checkbox', 'field_type' => 'checkbox', 'field_value' => 'missing', 'field_options' => "Yes|yes\nNo|no" ],
		[ 'custom_id' => 'accept', 'field_type' => 'acceptance', 'checked_by_default' => 'yes' ],
	]
);
$assert( 'business' === $defaults['single'], 'Single select default should match Pro Elements first-option behavior.' );
$assert( 'business' === $defaults['invalid_single'], 'An invalid single-select default falls back to the first rendered option.' );
$assert( [ 'one' ] === $defaults['multiple'], 'Multiple select defaults include only options that are actually rendered as selected.' );
$assert( '' === $defaults['invalid_radio'], 'Radio default must match a rendered option.' );
$assert( '' === $defaults['zero_radio'], 'A string-zero radio default follows Pro Elements empty() behavior.' );
$assert( [] === $defaults['invalid_checkbox'], 'Checkbox default must match a rendered option.' );
$assert( [ 'on' ] === $defaults['accept'], 'Acceptance default is checked when configured.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	fwrite( STDERR, sprintf( "\n%d PHP condition-engine assertions; %d failed.\n", $tests_run, count( $failures ) ) );
	exit( 1 );
}

echo sprintf( "PASS: %d PHP condition-engine assertions.\n", $tests_run );
