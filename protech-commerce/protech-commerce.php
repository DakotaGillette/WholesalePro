<?php
/**
 * Plugin Name:       Protech Commerce
 * Plugin URI:        https://protechsleeves.com
 * Description:       Wholesale accounts, pricing, case-based ordering and reorder, plus email and SMS messaging, for the Protech Sleeves WooCommerce store.
 * Version:           3.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Protech Sleeves
 * Update URI:        https://github.com/DakotaGillette/WholesalePro
 * Text Domain:       protech-wholesale
 * Domain Path:       /languages
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PROTECH_WHOLESALE_VERSION', '3.1.0' );
define( 'PROTECH_WHOLESALE_FILE', __FILE__ );
define( 'PROTECH_WHOLESALE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROTECH_WHOLESALE_URL', plugin_dir_url( __FILE__ ) );
define( 'PROTECH_WHOLESALE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal autoloader for the ProtechWholesale\* namespace.
 *
 * Maps ProtechWholesale\CaseRules -> includes/class-case-rules.php
 * (PascalCase class name -> WordPress-style "class-kebab-case.php" file).
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );

		if ( str_contains( $relative, '\\' ) ) {
			return; // No sub-namespaces in this plugin.
		}

		$kebab = strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $relative ) );
		$path  = PROTECH_WHOLESALE_DIR . 'includes/class-' . $kebab . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once PROTECH_WHOLESALE_DIR . 'includes/functions-helpers.php';

/**
 * Declare HPOS (custom order tables) compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PROTECH_WHOLESALE_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				PROTECH_WHOLESALE_FILE,
				true
			);
		}
	}
);

register_activation_hook( PROTECH_WHOLESALE_FILE, array( Activator::class, 'activate' ) );
register_deactivation_hook( PROTECH_WHOLESALE_FILE, array( Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \WooCommerce::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Protech Wholesale requires WooCommerce to be installed and active.', 'protech-wholesale' ) .
						'</p></div>';
				}
			);
			return;
		}

		Plugin::instance()->init();
	}
);
