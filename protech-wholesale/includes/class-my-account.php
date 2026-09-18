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
		// The "Wholesale Partner" strip, above the pending notice — on EVERY
		// account page (orders, addresses, ...), not just the dashboard.
		add_action( 'woocommerce_before_account_navigation', array( $this, 'render_account_header' ), 5 );
		add_action( 'woocommerce_before_account_navigation', array( $this, 'maybe_show_pending_notice' ) );

		// Before Reorder's "your last order" prompt on the same hook (10).
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_wholesale_dashboard_panel' ), 5 );
	}

	/**
	 * Makes the account's wholesale status unmissable anywhere in My
	 * Account: a "Wholesale Partner" badge with the business name for an
	 * approved customer, an "Application under review" one for a pending
	 * applicant. Retail accounts see nothing.
	 */
	public function render_account_header(): void {
		$is_wholesale = Roles::is_wholesale_customer();

		if ( ! $is_wholesale && ! Roles::is_wholesale_pending() ) {
			return;
		}

		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;

		// The name they trade under: WooCommerce's billing company if they've
		// set one, else what they put on the application form.
		$company = trim( (string) get_user_meta( $user_id, 'billing_company', true ) );

		if ( '' === $company ) {
			$company = trim( (string) get_user_meta( $user_id, '_protech_wholesale_app_store_name', true ) );
		}

		wc_get_template(
			'account-wholesale-header.php',
			array(
				'status'       => $is_wholesale ? 'approved' : 'pending',
				'display_name' => (string) $user->display_name,
				'company'      => $company,
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
	}

	/**
	 * A short "your wholesale account" panel on the My Account dashboard:
	 * where the current cart sits on the quantity ladder, what the next
	 * tier unlocks, and the way back to the shop.
	 */
	public function render_wholesale_dashboard_panel(): void {
		if ( ! Roles::is_wholesale_customer() ) {
			return;
		}

		$labels = array(
			VolumePricing::TIER_STANDARD => __( 'Standard pricing', 'protech-wholesale' ),
			VolumePricing::TIER_VOLUME   => __( 'Volume pricing', 'protech-wholesale' ),
			VolumePricing::TIER_BULK     => __( 'Bulk pricing', 'protech-wholesale' ),
		);

		$state = VolumePricing::get_tier_bar_state( get_current_user_id() );

		wc_get_template(
			'account-wholesale-panel.php',
			array(
				'state'          => $state,
				'tier_label'     => $labels[ $state['tier'] ] ?? '',
				'displays_label' => VolumePricing::format_quantity( (float) $state['displays'] ),
				'cases_label'    => VolumePricing::format_quantity( (float) $state['cases'] ),
				'shop_url'       => wc_get_page_permalink( 'shop' ),
				'cart_url'       => wc_get_cart_url(),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
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
