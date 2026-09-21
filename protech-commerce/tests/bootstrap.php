<?php
/**
 * PHPUnit bootstrap for Protech Wholesale.
 *
 * Standard WooCommerce-extension pattern: locate the WP core PHPUnit test
 * library, load WooCommerce and then this plugin on `muplugins_loaded` (i.e.
 * before WP's own test suite finishes bootstrapping and starts firing
 * `plugins_loaded`/`init`), then hand off to the WP test bootstrap.
 *
 * Run it with wp-env (Docker required):
 *   npx wp-env start
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/protech-wholesale vendor/bin/phpunit
 *
 * @package ProtechWholesale
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

// PHPUnit + the Yoast polyfills the WP test library requires.
$_composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( file_exists( $_composer_autoload ) ) {
	require_once $_composer_autoload;
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// wp-env's tests container ships the library here; install-wp-tests.sh
	// convention is the /tmp path.
	foreach ( array( '/wordpress-phpunit', '/tmp/wordpress-tests-lib' ) as $_candidate ) {
		if ( file_exists( $_candidate . '/includes/functions.php' ) ) {
			$_tests_dir = $_candidate;
			break;
		}
	}
}

if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress PHPUnit test library.' . PHP_EOL;
	echo 'Start this project with wp-env (`npx wp-env start`) and run PHPUnit inside it, or set WP_TESTS_DIR.' . PHP_EOL;
	exit( 1 );
}

// Gives access to tests_add_filter().
require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce and then this plugin, before the WP test suite itself
 * finishes loading. Runs on `muplugins_loaded`, same as the standard
 * WooCommerce-extension boilerplate.
 */
function _protech_wholesale_manually_load_plugin(): void {
	$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';

	if ( ! file_exists( $woocommerce ) ) {
		die(
			"Could not find WooCommerce at {$woocommerce}." . PHP_EOL .
			'Protech Wholesale hard-requires WooCommerce to be present in the test install' . PHP_EOL .
			'(e.g. via .wp-env.json\'s "plugins" list, or symlinked/copied into wp-content/plugins).' . PHP_EOL
		);
	}

	require $woocommerce;
	require dirname( __DIR__ ) . '/protech-wholesale.php';

	// Cookie-free session so cart operations work under PHPUnit.
	require_once __DIR__ . '/helpers/class-protech-mock-session-handler.php';
	add_filter(
		'woocommerce_session_handler',
		static function (): string {
			return 'Protech_Mock_Session_Handler';
		}
	);
}
tests_add_filter( 'muplugins_loaded', '_protech_wholesale_manually_load_plugin' );

/**
 * WooCommerce only installs its tables and roles on activation; the test
 * install never activates it, so trigger the installer once the suite is
 * up and reload the role registry so 'customer'/'shop_manager' exist.
 */
function _protech_wholesale_install_woocommerce(): void {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	WC_Install::install();
	update_option( 'woocommerce_db_version', WC()->version );

	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_roles();
}
tests_add_filter( 'setup_theme', '_protech_wholesale_install_woocommerce' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Test-side factory for the objects most tests need (simple/variable
// products with wholesale meta, wholesale customers, orders).
require_once __DIR__ . '/helpers/class-protech-test-factory.php';
