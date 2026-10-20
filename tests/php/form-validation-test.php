<?php
/**
 * Standalone server-side validation-adapter tests using API-shaped stubs.
 * Run with: php tests/php/form-validation-test.php
 *
 * @package SmartFormConditionsProElements
 */

namespace ElementorPro\Modules\Forms\Classes {
	/** Test double matching the public APIs inspected in Pro Elements. */
	class Ajax_Handler {
		public $errors = [];
		public $messages = [ 'success' => [], 'error' => [], 'admin_error' => [] ];
		public $success = false;

		public static function get_default_message( $id, $settings ) {
			if ( 'required_field' === $id && ! empty( $settings['custom_messages'] ) && isset( $settings['required_field_message'] ) ) {
				return $settings['required_field_message'];
			}
			return 'required_field' === $id ? 'This field is required.' : 'Unknown error.';
		}

		public function set_success( $success ) {
			$this->success = (bool) $success;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	require_once __DIR__ . '/../../includes/class-condition-engine.php';
	require_once __DIR__ . '/../../includes/class-form-validation.php';

	use SmartFormConditionsProElements\Form_Validation;

	/** Test record implementing the verified methods used by the adapter. */
	class SFCPE_Test_Record {
		private $form_fields;
		private $form_settings;
		private $fields;
		public $removed = [];

		public function __construct( array $form_fields, array $fields, array $form_settings = [] ) {
			$this->form_fields = $form_fields;
			$this->fields = $fields;
			$this->form_settings = $form_settings;
		}

		public function get_form_settings( $setting ) {
			return 'form_fields' === $setting ? $this->form_fields : null;
		}

		public function get( $property ) {
			if ( 'fields' === $property ) {
				return $this->fields;
			}
			if ( 'form_settings' === $property ) {
				return $this->form_settings;
			}
			return null;
		}

		public function remove_field( $id ) {
			$this->removed[] = $id;
			unset( $this->fields[ $id ] );
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
	$make_field = static function ( $id, $type, $source, $hidden_behavior = 'clear' ) {
		return [
			'custom_id'             => $id,
			'field_type'            => $type,
			'required'              => 'yes',
			'sfcpe_enabled'         => 'yes',
			'sfcpe_action'          => 'show',
			'sfcpe_match'           => 'all',
			'sfcpe_hidden_behavior' => $hidden_behavior,
			'sfcpe_conditions'      => [
				[
					'sfcpe_source'   => $source,
					'sfcpe_operator' => 'equals',
					'sfcpe_value'    => 'business',
				],
			],
		];
	};
	$validation = new Form_Validation();

	$form_fields = [
		[ 'custom_id' => 'account', 'field_type' => 'select', 'required' => '' ],
		$make_field( 'details', 'text', 'account' ),
	];
	$fields = [
		'account' => [ 'raw_value' => 'personal', 'value' => 'personal' ],
		'details' => [ 'raw_value' => '', 'value' => '', 'required' => true ],
	];
	$record = new SFCPE_Test_Record( $form_fields, $fields );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'This field is required.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( [ 'details' ] === $record->removed, 'Clear policy removes a hidden field from the Pro Elements record.' );
	$assert( ! isset( $ajax->errors['details'] ), 'The configured hidden required error is suppressed.' );
	$assert( true === $ajax->success, 'A sole hidden-required error restores submission success.' );

	$record = new SFCPE_Test_Record( $form_fields, $fields );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'This field is required.', 'account' => 'A separate validation error.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( ! isset( $ajax->errors['details'] ) && isset( $ajax->errors['account'] ), 'Only the hidden required error is removed; unrelated errors remain.' );
	$assert( false === $ajax->success, 'Unrelated validation failures do not become successful submissions.' );

	$retained_fields = $form_fields;
	$retained_fields[1]['sfcpe_hidden_behavior'] = 'retain';
	$record = new SFCPE_Test_Record( $retained_fields, $fields );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'This field is required.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( [] === $record->removed, 'Retain policy leaves the hidden value in the record.' );
	$assert( ! isset( $ajax->errors['details'] ) && true === $ajax->success, 'Retained hidden fields are not blocked by required validation.' );

	$visible_fields = $form_fields;
	$visible_record_fields = $fields;
	$visible_record_fields['account'] = [ 'raw_value' => 'business', 'value' => 'business' ];
	$record = new SFCPE_Test_Record( $visible_fields, $visible_record_fields );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'This field is required.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( isset( $ajax->errors['details'] ), 'Visible required fields retain normal required validation.' );
	$assert( false === $ajax->success, 'Visible required errors remain fatal.' );

	$custom_settings = [ 'custom_messages' => 'yes', 'required_field_message' => 'Please complete this field.' ];
	$record = new SFCPE_Test_Record( $form_fields, $fields, $custom_settings );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'Please complete this field.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( ! isset( $ajax->errors['details'] ), 'Customized Pro Elements required messages are matched safely.' );

	$record = new SFCPE_Test_Record( $form_fields, $fields );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'An unrelated custom validator failed.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( isset( $ajax->errors['details'] ), 'Custom errors on hidden fields are not mistaken for required errors.' );

	$record = new SFCPE_Test_Record( $form_fields, $fields, [ 'custom_messages' => 'yes', 'required_field_message' => '' ] );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => '' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( ! isset( $ajax->errors['details'] ) && true === $ajax->success, 'An empty configured required message is still suppressed for a missing hidden required field.' );

	$non_required_fields = $form_fields;
	$non_required_fields[1]['required'] = '';
	$record = new SFCPE_Test_Record( $non_required_fields, $fields );
	$ajax = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
	$ajax->errors = [ 'details' => 'This field is required.' ];
	$validation->validate_conditional_fields( $record, $ajax );
	$assert( isset( $ajax->errors['details'] ), 'A matching message on a non-required field is not suppressed.' );
	if ( ! empty( $failures ) ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		fwrite( STDERR, sprintf( "\n%d form-validation assertions; %d failed.\n", $tests_run, count( $failures ) ) );
		exit( 1 );
	}

	echo sprintf( "PASS: %d form-validation assertions (API-shaped stubs).\n", $tests_run );
}
