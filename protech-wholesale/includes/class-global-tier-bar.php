<?php
/**
 * Sticky, site-wide tier progress bar — shown to logged-in wholesale
 * customers on every front-end page, reflecting their actual current
 * cart, and refreshed via AJAX after any add-to-cart action anywhere on
 * the site (a single product page, the shop loop, the mini-cart, etc.).
 * The only piece of dedicated wholesale UI in the plugin — wholesale
 * pricing itself is visible directly on ordinary shop/product pages, so
 * there is no separate order-taking page for this bar to avoid
 * duplicating. See DECISIONS.md.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GlobalTierBar
 */
class GlobalTierBar {

	public const AJAX_NONCE_ACTION = 'protech_global_tier_bar';

	public function register_hooks(): void {
		add_action( 'wp_footer', array( $this, 'render' ) );
		add_action( 'wp_ajax_protech_global_tier_bar_state', array( $this, 'ajax_state' ) );
	}

	/**
	 * Also used by Plugin::enqueue_frontend_assets() to decide whether to
	 * load this feature's script.
	 */
	public static function should_render(): bool {
		return ! is_admin() && Roles::is_wholesale_customer();
	}

	public function render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$state = VolumePricing::get_tier_bar_state( get_current_user_id() );

		wc_get_template(
			'global-tier-bar.php',
			array(
				'state'    => $state,
				'cart_url' => wc_get_cart_url(),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
	}

	public function ajax_state(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! Roles::is_wholesale_customer() ) {
			wp_send_json_error( array( 'message' => __( 'Not available.', 'protech-wholesale' ) ), 403 );
		}

		wp_send_json_success( VolumePricing::get_tier_bar_state( get_current_user_id() ) );
	}
}
