<?php
/**
 * R4/R5: the "Quick Order" My Account tab, the post-login redirect for
 * wholesale users, and the "pending" notice on the account dashboard.
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

	public const ENDPOINT = 'quick-order';

	public function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint_content' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_action( 'woocommerce_before_account_navigation', array( $this, 'maybe_show_pending_notice' ) );
	}

	/**
	 * Also called directly from Activator::activate() — a rewrite
	 * endpoint added only via the `init` hook won't exist yet on the
	 * very request that first activates the plugin.
	 */
	public static function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Only shown to approved wholesale customers — no layout shift for
	 * retail accounts.
	 *
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		if ( ! Roles::is_wholesale_customer() ) {
			return $items;
		}

		$with_quick_order = array();

		foreach ( $items as $key => $label ) {
			$with_quick_order[ $key ] = $label;

			if ( 'dashboard' === $key ) {
				$with_quick_order[ self::ENDPOINT ] = __( 'Quick Order', 'protech-wholesale' );
			}
		}

		return $with_quick_order;
	}

	public function render_endpoint_content(): void {
		if ( ! Roles::is_wholesale_customer() ) {
			echo '<p>' . esc_html__( 'This page is only available to approved wholesale accounts.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo do_shortcode( '[protech_wholesale_order_form]' );
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
			return self::quick_order_url();
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

	public static function quick_order_url(): string {
		return wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );
	}
}
