<?php
/**
 * R5b: the /wholesale landing page — [protech_wholesale_portal].
 * Content depends entirely on the visitor's state: logged out (login +
 * pitch + apply link), pending (under-review notice), approved (redirect
 * straight to the shop, where wholesale pricing is already visible), or
 * retail-only (short message + apply link).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Portal
 */
class Portal {

	private static ?\WP_Error $login_error = null;

	public function register_hooks(): void {
		add_shortcode( 'protech_wholesale_portal', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_login_submission' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_approved_customer' ), 6 );
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'exclude_wholesale_from_free_shipping' ), 10, 2 );
	}

	/**
	 * Handles the native login form on this page (rather than routing
	 * through wp-login.php) so errors can be shown inline, in Salient's
	 * page chrome, instead of on wp-login.php's default screen.
	 */
	public function handle_login_submission(): void {
		if ( ! isset( $_POST['protech_wholesale_login_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_login_nonce'] ) ),
				'protech_wholesale_login'
			)
		) {
			return;
		}

		$creds = array(
			'user_login'    => sanitize_user( wp_unslash( $_POST['protech_username'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['protech_password'] ?? '' ),
			'remember'      => ! empty( $_POST['protech_remember'] ),
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			self::$login_error = $user;
			return;
		}

		wp_set_current_user( $user->ID );

		if ( Roles::is_wholesale_customer( $user->ID ) ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
		} else {
			wp_safe_redirect( home_url( '/wholesale' ) );
		}

		exit;
	}

	/**
	 * Approved wholesale customers never see the portal body — they go
	 * straight to the shop, where their wholesale pricing is already
	 * visible on every product. Runs on any singular page/post carrying
	 * the shortcode, not just the /wholesale page itself, since it's also
	 * usable from a WPBakery layout.
	 */
	public function maybe_redirect_approved_customer(): void {
		if ( ! Roles::is_wholesale_customer() || ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( $post && has_shortcode( (string) $post->post_content, 'protech_wholesale_portal' ) ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}
	}

	public function render(): string {
		if ( Roles::is_wholesale_customer() ) {
			// Reachable only if maybe_redirect_approved_customer() didn't
			// fire (e.g. output already started elsewhere on the page).
			return sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( wc_get_page_permalink( 'shop' ) ),
				esc_html__( 'Continue to the shop', 'protech-wholesale' )
			);
		}

		if ( is_user_logged_in() ) {
			if ( Roles::is_wholesale_pending() ) {
				$state = 'pending';
			} else {
				$state = 'retail_only';
			}
		} else {
			$state = 'login';
		}

		ob_start();
		wc_get_template(
			'portal.php',
			array(
				'state'       => $state,
				'login_error' => self::$login_error,
				'apply_url'   => home_url( '/wholesale-application' ),
				'contact_url' => apply_filters( 'protech_wholesale_contact_url', home_url( '/contact-us' ) ),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);

		return (string) ob_get_clean();
	}

	/**
	 * R5: wholesale orders pay real shipping rates — they never qualify
	 * for the retail "free shipping over $30" rule.
	 *
	 * @param bool                    $is_available
	 * @param \WC_Shipping_Free_Shipping $method
	 */
	public function exclude_wholesale_from_free_shipping( bool $is_available, $method ): bool {
		if ( ! $is_available || ! Settings::exclude_free_shipping() ) {
			return $is_available;
		}

		return ! Roles::is_wholesale_customer();
	}
}
