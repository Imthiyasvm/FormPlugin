<?php
/**
 * Plugin asset registration and enqueueing.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and enqueues the lightweight public evaluator early enough to catch dynamically rendered forms.
 */
final class Assets {

	const FRONTEND_SCRIPT = 'sfcpe-condition-engine';
	const FRONTEND_STYLE  = 'sfcpe-frontend';
	const EDITOR_SCRIPT   = 'sfcpe-editor-controls';

	/**
	 * Enqueue the frontend engine and its minimal stylesheet.
	 *
	 * @return void
	 */
	public function enqueue_frontend() {
		$this->register_frontend();
		wp_enqueue_script( self::FRONTEND_SCRIPT );
		wp_enqueue_style( self::FRONTEND_STYLE );
	}

	/**
	 * Enqueue editor-only helpers for dynamic source/operator choices.
	 *
	 * @return void
	 */
	public function enqueue_editor() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR_SCRIPT,
			SFCPE_URL . 'assets/js/editor-controls.js',
			[ 'elementor-editor', 'wp-i18n' ],
			SFCPE_VERSION,
			true
		);

		$operators_by_type = [];
		foreach ( Condition_Engine::SUPPORTED_FIELD_TYPES as $field_type ) {
			$operators_by_type[ $field_type ] = Condition_Engine::get_operators_for_type( $field_type );
		}

		wp_localize_script(
			self::EDITOR_SCRIPT,
			'SFCPEEditorControls',
			[
				'selectField'          => __( 'Select a field', 'smart-form-conditions-pro-elements' ),
				'missingField'         => __( 'Missing or invalid field', 'smart-form-conditions-pro-elements' ),
				'unsupportedOperator'  => __( 'Not supported for this field type', 'smart-form-conditions-pro-elements' ),
				'operatorsByType'      => $operators_by_type,
				'operatorLabels'       => Condition_Controls::get_operator_labels(),
			]
		);

		wp_enqueue_script( self::EDITOR_SCRIPT );
	}

	/**
	 * Register the public assets before they are enqueued.
	 *
	 * @return void
	 */
	private function register_frontend() {
		if ( ! wp_script_is( self::FRONTEND_SCRIPT, 'registered' ) ) {
			wp_register_script(
				self::FRONTEND_SCRIPT,
				SFCPE_URL . 'assets/js/condition-engine.js',
				[],
				SFCPE_VERSION,
				true
			);
		}

		if ( ! wp_style_is( self::FRONTEND_STYLE, 'registered' ) ) {
			wp_register_style(
				self::FRONTEND_STYLE,
				SFCPE_URL . 'assets/css/frontend.css',
				[],
				SFCPE_VERSION
			);
		}
	}
}
