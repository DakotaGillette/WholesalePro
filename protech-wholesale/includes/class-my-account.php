<?php
/**
 * R5: the post-login redirect for wholesale users, and the "pending"
 * notice on the account dashboard. Used to also own the "Quick Order"
 * tab — removed per owner feedback in favor of wholesale pricing being
 * visible directly on the normal shop/product pages everywhere, with the
 * sticky global tier bar (class-global-tier-bar.php) as the one piece of
 * dedicated wholesale UI. See DECISIONS.md.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MyAccount
 */
class MyAccount {

	public function register_hooks(): void {
		// wp-login.php ...
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		// ... and WooCommerce's own My Account login form, which doesn't go
		// through login_redirect at all.
		add_filter( 'woocommerce_login_redirect', array( $this, 'woocommerce_login_redirect' ), 10, 2 );
		add_action( 'woocommerce_before_account_navigation', array( $this, 'maybe_show_pending_notice' ) );
	}

	/**
	 * @param string   $redirect_to
	 * @param \WP_User $user
	 */
	public function woocommerce_login_redirect( $redirect_to, $user ) {
		return $this->login_redirect( $redirect_to, '', $user );
	}

	/**
	 * @param string            $redirect_to
	 * @param string            $requested_redirect_to
	 * @param \WP_User|\WP_Error $user
	 */
	public function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		if ( Roles::is_wholesale_customer( $user->ID ) ) {
			return wc_get_page_permalink( 'shop' );
		}

		if ( Roles::is_wholesale_pending( $user->ID ) ) {
			return home_url( '/wholesale' );
		}

		return $redirect_to;
	}

	public function maybe_show_pending_notice(): void {
		if ( Roles::is_wholesale_pending() ) {
			wc_print_notice(
				__( 'Your wholesale application is under review — we typically respond within 1–3 business days.', 'protech-wholesale' ),
				'notice'
			);
		}
	}
}
