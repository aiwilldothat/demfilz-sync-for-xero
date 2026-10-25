<?php
/**
 * Plugin Name:       Demfilz Sync for Xero
 * Plugin URI:        https://demfilz.com/demfilz-woo-to-xero
 * Description:       Send WooCommerce orders, customers and stock to Xero via the official Xero Accounting API. Free manual sync; Pro adds automation.
 * Version:           1.2.9
 * Requires at least: 6.2
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 * Author:            Demfilz
 * Author URI:        https://demfilz.com
 * Text Domain:       demfilz-sync-for-xero
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

define( 'DWTX_VERSION', '1.2.9' );
define( 'DWTX_FILE', __FILE__ );
define( 'DWTX_DIR', plugin_dir_path( __FILE__ ) );
define( 'DWTX_URL', plugin_dir_url( __FILE__ ) );
define( 'DWTX_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal PSR-ish autoloader for the plugin's class files.
 *
 * Classes are named Dwtx_Foo_Bar and live in includes/ as class-dwtx-foo-bar.php,
 * with sub-namespacing by directory handled in the map below.
 *
 * @param string $class_name Fully-qualified class name being requested.
 * @return void
 */
function dwtx_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Dwtx_' ) ) {
		return;
	}

	$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	$dirs = array(
		DWTX_DIR . 'includes/',
		DWTX_DIR . 'includes/providers/',
		DWTX_DIR . 'includes/sync/',
		DWTX_DIR . 'includes/admin/',
	);

	foreach ( $dirs as $dir ) {
		$path = $dir . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
		// Interfaces use an interface- prefix.
		$ipath = $dir . str_replace( 'class-', 'interface-', $file );
		if ( file_exists( $ipath ) ) {
			require_once $ipath;
			return;
		}
		// Abstract classes use an abstract-class- prefix.
		$apath = $dir . str_replace( 'class-', 'abstract-class-', $file );
		if ( file_exists( $apath ) ) {
			require_once $apath;
			return;
		}
	}
}
spl_autoload_register( 'dwtx_autoload' );

register_activation_hook( __FILE__, array( 'Dwtx_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Dwtx_Installer', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce is available).
 *
 * @return void
 */
function dwtx_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Demfilz Sync for WooCommerce to Xero requires WooCommerce to be installed and active.', 'demfilz-sync-for-xero' );
				echo '</p></div>';
			}
		);
		return;
	}
	Dwtx_Plugin::instance();
}
add_action( 'plugins_loaded', 'dwtx_bootstrap' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * @return void
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DWTX_FILE, true );
		}
	}
);
