<?php
/**
 * Plugin bootstrap and administrator notices.
 *
 * @package SmartFormConditionsProElements
 */

namespace SmartFormConditionsProElements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts the integration only after the real WordPress and Elementor runtime
 * has been checked.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Dependency_Check */
	private $dependency_check;

	/** @var string */
	private $failure_message = '';

	/** @var bool */
	private $bootstrap_ready = false;

	/** @var bool */
	private $elementor_init_ran = false;

	/** @var Form_Integration|null */
	private $form_integration = null;

	/**
	 * Get the plugin singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register dependency checks without referencing Elementor classes.
	 */
	private function __construct() {
		$this->dependency_check = new Dependency_Check();
		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ], 20 );
	}

	/**
	 * Load translations and verify base dependencies after plugins have loaded.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		load_plugin_textdomain(
			'smart-form-conditions-pro-elements',
			false,
			dirname( plugin_basename( SFCPE_FILE ) ) . '/languages'
		);

		$result = $this->dependency_check->check_bootstrap();
		if ( empty( $result['ok'] ) ) {
			$this->set_failure_message( isset( $result['message'] ) ? $result['message'] : __( 'The required page-builder dependencies are unavailable.', 'smart-form-conditions-pro-elements' ) );
			add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
			return;
		}

		$this->bootstrap_ready = true;
		add_action( 'elementor/init', [ $this, 'on_elementor_init' ], 100 );
		add_action( 'init', [ $this, 'check_elementor_init_completed' ], 1000 );
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
	}

	/**
	 * Start integration adapters after Elementor and Pro Elements have created
	 * their module managers.
	 *
	 * @return void
	 */
	public function on_elementor_init() {
		$this->elementor_init_ran = true;

		$runtime = $this->dependency_check->check_forms_runtime();
		if ( empty( $runtime['ok'] ) ) {
			$this->set_failure_message( isset( $runtime['message'] ) ? $runtime['message'] : __( 'Pro Elements form processing could not be verified.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		// Elementor-dependent adapters are not included until Elementor and the
		// Pro Elements Forms module have passed their runtime checks.
		require_once SFCPE_PATH . 'includes/class-condition-engine.php';
		require_once SFCPE_PATH . 'includes/class-condition-controls.php';
		require_once SFCPE_PATH . 'includes/class-assets.php';
		require_once SFCPE_PATH . 'includes/class-form-validation.php';
		require_once SFCPE_PATH . 'includes/class-form-integration.php';

		$assets = new Assets();
		// Load the small public evaluator early so forms rendered later by popup,
		// AJAX, or other dynamic insertion can be initialized as well.
		add_action( 'wp_enqueue_scripts', [ $assets, 'enqueue_frontend' ], 20 );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $assets, 'enqueue_editor' ] );
		$validation = new Form_Validation();
		$this->form_integration = new Form_Integration( $assets, $validation, [ $this, 'set_failure_message' ] );
		$this->form_integration->register();
	}

	/**
	 * Catch an Elementor bootstrap that never reached elementor/init.
	 *
	 * @return void
	 */
	public function check_elementor_init_completed() {
		if ( $this->bootstrap_ready && ! $this->elementor_init_ran && '' === $this->failure_message ) {
			$this->set_failure_message( __( 'Elementor was detected but did not complete its initialization. Conditional forms have not been loaded.', 'smart-form-conditions-pro-elements' ) );
		}
	}

	/**
	 * Render the dependency or compatibility notice.
	 *
	 * On administrator pages, ask Elementor for its actual widget registry if
	 * needed. This lets the plugin distinguish an inactive Forms widget from a
	 * widget that simply has not been requested on a public page.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( '' === $this->failure_message && $this->bootstrap_ready && $this->form_integration && ! $this->form_integration->is_verified() ) {
			$this->verify_widget_registry_for_admin();
		}

		if ( '' === $this->failure_message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $this->failure_message )
		);
	}

	/**
	 * Make the widget manager perform its normal registration on admin pages.
	 *
	 * @return void
	 */
	private function verify_widget_registry_for_admin() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) || ! isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			$this->set_failure_message( __( 'Elementor initialized, but its widget manager is unavailable. Conditional form controls were not registered.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		$manager = \Elementor\Plugin::$instance->widgets_manager;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
			$this->set_failure_message( __( 'Elementor does not expose the widget registry API required by Pro Elements forms.', 'smart-form-conditions-pro-elements' ) );
			return;
		}

		$manager->get_widget_types();
		if ( ! $this->form_integration->is_verified() && '' === $this->failure_message ) {
			$this->set_failure_message( __( 'Pro Elements is active, but a compatible classic Form widget was not registered. Conditional logic is disabled; verify the Pro Elements Forms module and update to a supported version.', 'smart-form-conditions-pro-elements' ) );
		}
	}

	/**
	 * Store a sanitized, static notice string for the next admin notice render.
	 *
	 * @param string $message Administrator-facing message.
	 * @return void
	 */
	public function set_failure_message( $message ) {
		if ( ! is_string( $message ) || '' === trim( $message ) ) {
			return;
		}

		$this->failure_message = $message;
	}
}
