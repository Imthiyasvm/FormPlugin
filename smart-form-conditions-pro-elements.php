<?php
/**
 * Plugin Name: Smart Form Conditions for Pro Elements
 * Description: Adds lightweight, per-field conditional logic to classic Pro Elements forms.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-form-conditions-pro-elements
 * Domain Path: /languages
 *
 * @package SmartFormConditionsProElements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SFCPE_VERSION', '1.0.0' );
define( 'SFCPE_FILE', __FILE__ );
define( 'SFCPE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SFCPE_URL', plugin_dir_url( __FILE__ ) );

require_once SFCPE_PATH . 'includes/class-dependency-check.php';
require_once SFCPE_PATH . 'includes/class-plugin.php';

\SmartFormConditionsProElements\Plugin::instance();
