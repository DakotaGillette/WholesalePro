<?php
/**
 * PHPUnit bootstrap for Protech Wholesale.
 *
 * Standard WooCommerce-extension pattern: locate the WP core PHPUnit test
 * library, load WooCommerce and then this plugin on `muplugins_loaded` (i.e.
 * before WP's own test suite finishes bootstrapping and starts firing
 * `plugins_loaded`/`init`), then hand off to the WP test bootstrap.
 *
 * @package ProtechWholesale
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Matches the directory the common `install-wp-tests.sh` / wp-env
	// convention checks out the WP test library into.
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php" . PHP_EOL;
	echo 'Have you run bin/install-wp-tests.sh, or started this project with wp-env (`npx wp-env start`)?' . PHP_EOL;
	echo 'Set the WP_TESTS_DIR environment variable if the test library lives somewhere else.' . PHP_EOL;
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
}
tests_add_filter( 'muplugins_loaded', '_protech_wholesale_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// WooCommerce ships its own test helper classes (WC_Helper_Product,
// WC_Helper_Order, ...) under its own PHPUnit test tree; they aren't
// autoloaded by WooCommerce itself. Pull them in if present so our tests can
// use them, but degrade gracefully (each test falls back to plain
// wc_get_product()/wc_create_order() calls) when they aren't installed,
// since a WooCommerce release zip doesn't always ship its tests/ directory.
$_wc_helpers_dir = WP_PLUGIN_DIR . '/woocommerce/tests/legacy/unit-tests/helpers';

if ( is_dir( $_wc_helpers_dir ) ) {
	foreach ( glob( $_wc_helpers_dir . '/*.php' ) as $_wc_helper_file ) {
		require_once $_wc_helper_file;
	}
}
