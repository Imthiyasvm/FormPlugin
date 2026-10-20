<?php
/**
 * Dependency discovery that does not load Elementor-dependent classes early.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects initialized runtime APIs, not merely plugin directory names.
 */
final class Dependency_Check {

	const MIN_WORDPRESS_VERSION = '6.8';
	const MIN_ELEMENTOR_VERSION = '4.0';
	const MIN_PRO_ELEMENTS_VERSION = '4.3.0';

	/**
	 * Check the base runtime before any Elementor-dependent integration is loaded.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function check_bootstrap() {
		if ( PHP_VERSION_ID < 70400 ) {
			return [
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: required PHP version. */
					__( 'Smart Form Conditions for Pro Elements requires PHP %s or newer. The integration has not been loaded.', 'smart-form-conditions-pro-elements' ),
					'7.4'
				),
			];
		}

		$wordpress_version = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
		if ( '' !== $wordpress_version && version_compare( $wordpress_version, self::MIN_WORDPRESS_VERSION, '<' ) ) {
			return [
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: minimum WordPress version, 2: current WordPress version. */
					__( 'Smart Form Conditions for Pro Elements requires WordPress %1$s or newer. This site is running %2$s.', 'smart-form-conditions-pro-elements' ),
					self::MIN_WORDPRESS_VERSION,
					$wordpress_version
				),
			];
		}

		if ( ! $this->is_elementor_initialized() ) {
			return [
				'ok'      => false,
				'message' => $this->get_elementor_missing_message(),
			];
		}

		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
		if ( '' === $elementor_version || version_compare( $elementor_version, self::MIN_ELEMENTOR_VERSION, '<' ) ) {
			return [
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: required Elementor version, 2: current Elementor version. */
					__( 'Smart Form Conditions for Pro Elements requires Elementor %1$s or newer. The detected version is %2$s.', 'smart-form-conditions-pro-elements' ),
					self::MIN_ELEMENTOR_VERSION,
					$elementor_version ? $elementor_version : __( 'unknown', 'smart-form-conditions-pro-elements' )
				),
			];
		}

		if ( ! $this->is_pro_elements_initialized() ) {
			return [
				'ok'      => false,
				'message' => $this->get_pro_elements_missing_message(),
			];
		}

		$pro_elements_version = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '';
		if ( '' === $pro_elements_version || version_compare( $pro_elements_version, self::MIN_PRO_ELEMENTS_VERSION, '<' ) ) {
			return [
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: required PRO Elements version, 2: detected version. */
					__( 'Smart Form Conditions for Pro Elements has been verified against Pro Elements %1$s and newer. The detected version is %2$s; update Pro Elements before using conditional forms.', 'smart-form-conditions-pro-elements' ),
					self::MIN_PRO_ELEMENTS_VERSION,
					$pro_elements_version ? $pro_elements_version : __( 'unknown', 'smart-form-conditions-pro-elements' )
				),
			];
		}

		if ( defined( 'ELEMENTOR_PRO_REQUIRED_CORE_VERSION' ) && version_compare( $elementor_version, ELEMENTOR_PRO_REQUIRED_CORE_VERSION, '<' ) ) {
			return [
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: Pro Elements-required Elementor version, 2: current Elementor version. */
					__( 'Pro Elements requires Elementor %1$s or newer. This site is running Elementor %2$s, so the forms integration has been disabled.', 'smart-form-conditions-pro-elements' ),
					ELEMENTOR_PRO_REQUIRED_CORE_VERSION,
					$elementor_version
				),
			];
		}

		return [
			'ok'      => true,
			'message' => '',
		];
	}

	/**
	 * Verify Pro Elements' forms module and processing APIs after elementor/init.
	 *
	 * @return array{ok:bool,message:string,module:object|null}
	 */
	public function check_forms_runtime() {
		$pro_plugin_class = '\\ElementorPro\\Plugin';
		if ( ! class_exists( $pro_plugin_class ) || ! method_exists( $pro_plugin_class, 'instance' ) ) {
			return $this->forms_failure( __( 'Pro Elements is active, but its runtime plugin API is unavailable. Please reinstall or update Pro Elements.', 'smart-form-conditions-pro-elements' ) );
		}

		$pro_plugin = call_user_func( [ $pro_plugin_class, 'instance' ] );
		if ( ! is_object( $pro_plugin ) || ! isset( $pro_plugin->modules_manager ) || ! is_object( $pro_plugin->modules_manager ) || ! method_exists( $pro_plugin->modules_manager, 'get_modules' ) ) {
			return $this->forms_failure( __( 'Pro Elements did not initialize its module manager. The conditional-forms integration was not started.', 'smart-form-conditions-pro-elements' ) );
		}

		$forms_module = $pro_plugin->modules_manager->get_modules( 'forms' );
		if ( ! is_object( $forms_module ) ) {
			return $this->forms_failure( __( 'Pro Elements is active, but its Forms module is unavailable or disabled. Enable a Pro Elements version with the classic Form widget and form processing.', 'smart-form-conditions-pro-elements' ) );
		}

		if ( ! isset( $forms_module->actions_registrar, $forms_module->fields_registrar ) || ! is_object( $forms_module->actions_registrar ) || ! is_object( $forms_module->fields_registrar ) || ! method_exists( $forms_module->actions_registrar, 'get' ) || ! method_exists( $forms_module->fields_registrar, 'get' ) ) {
			return $this->forms_failure( __( 'The Pro Elements Forms module did not initialize its field and action registrars. Conditional logic has been disabled to avoid interrupting form submissions.', 'smart-form-conditions-pro-elements' ) );
		}

		$record_class = '\\ElementorPro\\Modules\\Forms\\Classes\\Form_Record';
		$ajax_class = '\\ElementorPro\\Modules\\Forms\\Classes\\Ajax_Handler';
		$required_record_methods = [ 'validate', 'process_fields', 'get', 'get_form_settings', 'remove_field' ];
		$required_ajax_methods = [ 'ajax_send_form', 'get_default_message', 'set_success' ];

		if ( ! class_exists( $record_class ) || ! class_exists( $ajax_class ) ) {
			return $this->forms_failure( __( 'Pro Elements has not loaded the Form_Record and Ajax_Handler classes required for form validation and submission.', 'smart-form-conditions-pro-elements' ) );
		}

		foreach ( $required_record_methods as $method ) {
			if ( ! method_exists( $record_class, $method ) ) {
				return $this->forms_failure( __( 'The installed Pro Elements Form_Record API is incompatible with the verified conditional-validation adapter.', 'smart-form-conditions-pro-elements' ) );
			}
		}

		foreach ( $required_ajax_methods as $method ) {
			if ( ! method_exists( $ajax_class, $method ) ) {
				return $this->forms_failure( __( 'The installed Pro Elements Ajax_Handler API is incompatible with the verified conditional-validation adapter.', 'smart-form-conditions-pro-elements' ) );
			}
		}

		try {
			$errors_property = new \ReflectionProperty( $ajax_class, 'errors' );
			$messages_property = new \ReflectionProperty( $ajax_class, 'messages' );
			if ( ! $errors_property->isPublic() || ! $messages_property->isPublic() ) {
				return $this->forms_failure( __( 'The installed Pro Elements Ajax_Handler does not expose the validation state required to ignore hidden required fields safely.', 'smart-form-conditions-pro-elements' ) );
			}
		} catch ( \ReflectionException $exception ) {
			return $this->forms_failure( __( 'The installed Pro Elements Ajax_Handler validation state could not be verified.', 'smart-form-conditions-pro-elements' ) );
		}

		return [
			'ok'      => true,
			'message' => '',
			'module'  => $forms_module,
		];
	}

	/**
	 * Check the registered Elementor runtime, not only the active-plugin option.
	 *
	 * @return bool
	 */
	public function is_elementor_initialized() {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return false;
		}

		try {
			return isset( \Elementor\Plugin::$instance ) && is_object( \Elementor\Plugin::$instance );
		} catch ( \Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Check the Pro Elements-specific runtime marker and registered class loader.
	 *
	 * IS_PRO_ELEMENTS is set by the inspected Pro Elements bootstrap only after
	 * Elementor is loaded. The public form/module APIs are checked separately.
	 *
	 * @return bool
	 */
	public function is_pro_elements_initialized() {
		if ( ! defined( 'IS_PRO_ELEMENTS' ) || ! $this->is_truthy( IS_PRO_ELEMENTS ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return false;
		}

		return class_exists( '\\ElementorPro\\Plugin' );
	}

	/**
	 * Build an Elementor notice without loading any Elementor integration class.
	 *
	 * @return string
	 */
	private function get_elementor_missing_message() {
		$state = $this->find_plugin_state( 'elementor' );
		if ( $state['installed'] && ! $state['active'] ) {
			return __( 'Smart Form Conditions for Pro Elements requires Elementor 4.0 or newer. Elementor is installed but inactive; activate it, then reload this page.', 'smart-form-conditions-pro-elements' );
		}
		if ( $state['installed'] && $state['active'] ) {
			return __( 'Elementor appears to be active, but its elementor/loaded runtime did not initialize. Check Elementor compatibility and the WordPress error log.', 'smart-form-conditions-pro-elements' );
		}
		return __( 'Smart Form Conditions for Pro Elements requires Elementor 4.0 or newer. Install and activate Elementor before using this plugin.', 'smart-form-conditions-pro-elements' );
	}

	/**
	 * Build a Pro Elements notice that distinguishes missing/inactive bootstrap.
	 *
	 * @return string
	 */
	private function get_pro_elements_missing_message() {
		$state = $this->find_plugin_state( 'pro-elements' );
		if ( $state['installed'] && ! $state['active'] ) {
			return __( 'Smart Form Conditions for Pro Elements requires Pro Elements 4.3.0 or newer with its Forms module enabled. Pro Elements is installed but inactive; activate it, then reload this page.', 'smart-form-conditions-pro-elements' );
		}
		if ( $state['installed'] && $state['active'] ) {
			return __( 'A Pro Elements plugin appears active, but its Pro Elements runtime marker was not set. Confirm that Elementor 4.0+ is initialized and that official Elementor Pro is not preventing Pro Elements from loading.', 'smart-form-conditions-pro-elements' );
		}
		return __( 'Smart Form Conditions for Pro Elements requires the Pro Elements plugin (4.3.0 or newer) with working classic Form functionality. Official Elementor Pro alone is not a substitute.', 'smart-form-conditions-pro-elements' );
	}

	/**
	 * Find a plugin by its installed header metadata and active state.
	 *
	 * Plugin identity is determined from the plugin Name, Text Domain, and URI;
	 * paths are used only to look up activation state, never as the sole test.
	 *
	 * @param string $kind Dependency slug: elementor or pro-elements.
	 * @return array{installed:bool,active:bool}
	 */
	private function find_plugin_state( $kind ) {
		$state = [
			'installed' => false,
			'active'    => false,
		];

		if ( ! is_admin() ) {
			return $state;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_api ) ) {
				require_once $plugin_api;
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			return $state;
		}

		$installed_plugins = get_plugins();
		if ( ! is_array( $installed_plugins ) ) {
			return $state;
		}

		foreach ( $installed_plugins as $plugin_file => $plugin_data ) {
			if ( ! is_array( $plugin_data ) || ! $this->matches_plugin_metadata( $kind, $plugin_data ) ) {
				continue;
			}

			$state['installed'] = true;
			$state['active'] = $state['active'] || $this->is_plugin_file_active( $plugin_file );
		}

		return $state;
	}

	/**
	 * Match the dependency using more than its folder name.
	 *
	 * @param string $kind        Dependency slug.
	 * @param array  $plugin_data Plugin header fields.
	 * @return bool
	 */
	private function matches_plugin_metadata( $kind, array $plugin_data ) {
		$name = isset( $plugin_data['Name'] ) ? strtolower( trim( $plugin_data['Name'] ) ) : '';
		$text_domain = isset( $plugin_data['TextDomain'] ) ? strtolower( trim( $plugin_data['TextDomain'] ) ) : '';
		$plugin_uri = isset( $plugin_data['PluginURI'] ) ? strtolower( (string) $plugin_data['PluginURI'] ) : '';

		if ( 'elementor' === $kind ) {
			return 'elementor' === $name || 'elementor' === $text_domain || false !== strpos( $plugin_uri, 'elementor.com' );
		}

		return false !== strpos( $name, 'pro elements' )
			|| 'pro-elements' === $text_domain
			|| false !== strpos( $plugin_uri, 'proelements.org' );
	}

	/**
	 * Check normal and multisite network activation for an installed plugin file.
	 *
	 * @param string $plugin_file Plugin-relative file path.
	 * @return bool
	 */
	private function is_plugin_file_active( $plugin_file ) {
		$active_plugins = get_option( 'active_plugins', [] );
		if ( is_array( $active_plugins ) && in_array( $plugin_file, $active_plugins, true ) ) {
			return true;
		}

		$network_plugins = function_exists( 'get_site_option' ) ? get_site_option( 'active_sitewide_plugins', [] ) : [];
		return is_array( $network_plugins ) && isset( $network_plugins[ $plugin_file ] );
	}

	/**
	 * Interpret the string marker used by Pro Elements' current bootstrap.
	 *
	 * @param mixed $value Marker value.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * Format a forms-runtime failure.
	 *
	 * @param string $message Notice text.
	 * @return array{ok:bool,message:string,module:null}
	 */
	private function forms_failure( $message ) {
		return [
			'ok'      => false,
			'message' => $message,
			'module'  => null,
		];
	}
}
