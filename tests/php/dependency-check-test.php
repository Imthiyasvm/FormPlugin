<?php
/**
 * Dependency/runtime checks using small API-shaped Elementor/Pro Elements doubles.
 * Run with: php tests/php/dependency-check-test.php
 *
 * @package SmartFormConditionsProElements
 */

namespace Elementor {
	class Plugin {
		public static $instance;
	}
}

namespace ElementorPro {
	class Plugin {
		public static $instance;
		public $modules_manager;

		public static function instance() {
			return self::$instance;
		}
	}
}

namespace ElementorPro\Modules\Forms\Classes {
	class Form_Record {
		public function validate( $ajax_handler ) {}
		public function process_fields( $ajax_handler ) {}
		public function get( $property ) {}
		public function get_form_settings( $setting ) {}
		public function remove_field( $id ) {}
	}

	class Ajax_Handler {
		public $errors = [];
		public $messages = [];

		public function ajax_send_form() {}
		public static function get_default_message( $id, $settings ) {}
		public function set_success( $success ) {}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ELEMENTOR_VERSION', '4.0.0' );
	define( 'ELEMENTOR_PRO_VERSION', '4.3.0' );
	define( 'ELEMENTOR_PRO_REQUIRED_CORE_VERSION', '4.0' );
	define( 'IS_PRO_ELEMENTS', 'true' );

	function did_action( $hook ) {
		return 'elementor/loaded' === $hook ? 1 : 0;
	}

	function get_bloginfo( $show ) {
		return 'version' === $show ? '6.8' : '';
	}

	function __( $text, $domain = null ) {
		return $text;
	}

	require_once __DIR__ . '/../../includes/class-dependency-check.php';

	class SFCPE_Test_Registrar {
		public function get() {
			return [];
		}
	}

	class SFCPE_Test_Forms_Module {
		public $actions_registrar;
		public $fields_registrar;

		public function __construct() {
			$this->actions_registrar = new SFCPE_Test_Registrar();
			$this->fields_registrar = new SFCPE_Test_Registrar();
		}
	}

	class SFCPE_Test_Module_Manager {
		private $forms_module;

		public function __construct( $forms_module ) {
			$this->forms_module = $forms_module;
		}

		public function get_modules( $slug ) {
			return 'forms' === $slug ? $this->forms_module : null;
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

	$elementor_class = '\\Elementor\\Plugin';
	$elementor_reflection = new \ReflectionClass( $elementor_class );
	$elementor_class::$instance = $elementor_reflection->newInstance();

	$pro_class = '\\ElementorPro\\Plugin';
	$pro_reflection = new \ReflectionClass( $pro_class );
	$pro_instance = $pro_reflection->newInstance();
	$pro_instance->modules_manager = new SFCPE_Test_Module_Manager( new SFCPE_Test_Forms_Module() );
	$pro_class::$instance = $pro_instance;

	$check = new \SmartFormConditionsProElements\Dependency_Check();
	$bootstrap = $check->check_bootstrap();
	$assert( true === $bootstrap['ok'], 'Supported initialized Elementor/Pro Elements versions pass bootstrap checks.' );
	$runtime = $check->check_forms_runtime();
	$assert( true === $runtime['ok'] && is_object( $runtime['module'] ), 'The expected Forms module and public processing APIs pass runtime checks.' );

	$pro_instance->modules_manager = new SFCPE_Test_Module_Manager( null );
	$missing_forms = $check->check_forms_runtime();
	$assert( false === $missing_forms['ok'] && false !== strpos( $missing_forms['message'], 'Forms module' ), 'A missing Forms module returns a useful failure instead of enabling the integration.' );

	if ( ! empty( $failures ) ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		fwrite( STDERR, sprintf( "\n%d dependency-check assertions; %d failed.\n", $tests_run, count( $failures ) ) );
		exit( 1 );
	}

	echo sprintf( "PASS: %d dependency-check assertions (API-shaped stubs).\n", $tests_run );
}
