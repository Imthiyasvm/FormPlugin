<?php
/**
 * Server-side Pro Elements submission integration.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies saved visibility and hidden-value policy after Pro Elements field
 * validators have run, without replacing or duplicating Pro Elements actions.
 */
final class Form_Validation {

	/**
	 * Handle the verified `elementor_pro/forms/validation` hook.
	 *
	 * Pro Elements 4.3.0 runs this hook after its per-field validation and its
	 * built-in required-field check. We remove only that exact required error for
	 * a field that the saved server-side rules say is hidden. Other field and
	 * custom validation errors are deliberately preserved.
	 *
	 * @param object $record       Pro Elements Form_Record.
	 * @param object $ajax_handler Pro Elements Ajax_Handler.
	 * @return void
	 */
	public function validate_conditional_fields( $record, $ajax_handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get_form_settings' ) || ! method_exists( $record, 'get' ) || ! method_exists( $record, 'remove_field' ) ) {
			return;
		}

		$form_fields = $record->get_form_settings( 'form_fields' );
		$record_fields = $record->get( 'fields' );
		if ( ! is_array( $form_fields ) || ! is_array( $record_fields ) ) {
			return;
		}

		$values = [];
		foreach ( $record_fields as $id => $record_field ) {
			if ( ! is_array( $record_field ) ) {
				continue;
			}

			if ( array_key_exists( 'raw_value', $record_field ) ) {
				$values[ (string) $id ] = $record_field['raw_value'];
			} elseif ( array_key_exists( 'value', $record_field ) ) {
				$values[ (string) $id ] = $record_field['value'];
			}
		}

		$result = Condition_Engine::calculate_visibility( $form_fields, $values );
		$visibility = isset( $result['visibility'] ) && is_array( $result['visibility'] ) ? $result['visibility'] : [];
		$form_settings = $record->get( 'form_settings' );
		$form_settings = is_array( $form_settings ) ? $form_settings : [];
		$required_message = $this->get_required_message( $form_settings );
		$removed_required_error = false;

		foreach ( $form_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$config = Condition_Engine::get_frontend_config( $field );
			if ( null === $config ) {
				continue;
			}

			$id = isset( $field['custom_id'] ) && is_scalar( $field['custom_id'] ) ? trim( (string) $field['custom_id'] ) : '';
			if ( '' === $id || ! array_key_exists( $id, $visibility ) || true === $visibility[ $id ] ) {
				continue;
			}

			// Clear values server-side as well; a visitor cannot change the saved
			// hidden-field policy by manipulating the browser-side script.
			if ( 'clear' === $config['hidden_behavior'] ) {
				$record->remove_field( $id );
			}

			// The stock required error is the only validation we suppress. This
			// leaves Pro Elements' type validators and custom security/business
			// validation callbacks intact even when a field is hidden.
			$is_required = isset( $field['required'] ) && in_array( strtolower( (string) $field['required'] ), [ '1', 'true', 'yes', 'on' ], true );
			$record_value_is_empty = isset( $record_fields[ $id ] ) && is_array( $record_fields[ $id ] ) && isset( $record_fields[ $id ]['value'] ) && '' === $record_fields[ $id ]['value'];
			if ( $is_required && $record_value_is_empty && null !== $required_message && is_object( $ajax_handler ) && isset( $ajax_handler->errors ) && is_array( $ajax_handler->errors ) && array_key_exists( $id, $ajax_handler->errors ) && $required_message === $ajax_handler->errors[ $id ] ) {
				unset( $ajax_handler->errors[ $id ] );
				$removed_required_error = true;
			}
		}

		// Form_Record::validate() returns based on the field-error map. It is safe
		// to restore the handler's success flag only when the required error we
		// removed was its sole failure; unrelated errors/messages remain fatal.
		if ( $removed_required_error && is_object( $ajax_handler ) && method_exists( $ajax_handler, 'set_success' ) && isset( $ajax_handler->errors ) && empty( $ajax_handler->errors ) ) {
			$messages = isset( $ajax_handler->messages ) && is_array( $ajax_handler->messages ) ? $ajax_handler->messages : [];
			$has_messages = ! empty( $messages['error'] ) || ! empty( $messages['admin_error'] );
			if ( ! $has_messages ) {
				$ajax_handler->set_success( true );
			}
		}
	}

	/**
	 * Ask Pro Elements for its actual configured required-field message.
	 *
	 * @param array $form_settings Form settings.
	 * @return string|null
	 */
	private function get_required_message( array $form_settings ) {
		$ajax_class = '\\ElementorPro\\Modules\\Forms\\Classes\\Ajax_Handler';
		if ( ! class_exists( $ajax_class ) || ! method_exists( $ajax_class, 'get_default_message' ) ) {
			return null;
		}

		return (string) call_user_func( [ $ajax_class, 'get_default_message' ], 'required_field', $form_settings );
	}
}
