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

	/** What was typed into a login that just failed, so the form can keep it. */
	private static string $attempted_username = '';

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
			self::$login_error        = $user;
			self::$attempted_username = $creds['user_login'];
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, set by Unsubscribe::handle()'s redirect.
		if ( ! Roles::is_wholesale_customer() || ! is_singular() || isset( $_GET[ Unsubscribe::QUERY_FLAG ] ) ) {
			return;
		}

		$post = get_post();

		if ( $post && has_shortcode( (string) $post->post_content, 'protech_wholesale_portal' ) ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}
	}

	public function render(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, set by Unsubscribe::handle()'s redirect.
		$unsubscribed = isset( $_GET[ Unsubscribe::QUERY_FLAG ] );

		if ( Roles::is_wholesale_customer() ) {
			// Reachable only if maybe_redirect_approved_customer() didn't
			// fire (e.g. output already started elsewhere on the page, or
			// the customer just clicked an unsubscribe link and should see
			// the confirmation before bouncing straight back to the shop).
			if ( $unsubscribed ) {
				return $this->unsubscribed_notice() . sprintf(
					'<p><a href="%s">%s</a></p>',
					esc_url( wc_get_page_permalink( 'shop' ) ),
					esc_html__( 'Continue to the shop', 'protech-wholesale' )
				);
			}

			return sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( wc_get_page_permalink( 'shop' ) ),
				esc_html__( 'Continue to the shop', 'protech-wholesale' )
			);
		}

		if ( is_user_logged_in() ) {
			if ( Roles::is_wholesale_pending() ) {
				$state = 'pending';
			} elseif ( Approval::STATUS_REJECTED === get_user_meta( get_current_user_id(), Approval::META_APP_STATUS, true ) ) {
				$state = 'rejected';
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
				'state'         => $state,
				'login_error'   => self::$login_error,
				'username'      => self::$attempted_username,
				'benefits'      => self::get_benefits(),
				'apply_url'     => home_url( '/wholesale-application' ),
				'contact_url'   => apply_filters( 'protech_wholesale_contact_url', home_url( '/contact-us' ) ),
				'unsubscribed'  => $unsubscribed,
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);

		return (string) ob_get_clean();
	}

	/** Shown above the login/pending/rejected/retail-only card right after a customer clicks an unsubscribe link. */
	private function unsubscribed_notice(): string {
		return '<div class="woocommerce-message">' . esc_html__( 'You\'re unsubscribed from marketing emails. Order emails still arrive. Manage texts and preferences under My Account → Notifications.', 'protech-wholesale' ) . '</div>';
	}

	/**
	 * The login page's pitch, built from the store's real settings — it
	 * only ever promises what the Pricing tab currently delivers, and
	 * follows along when those thresholds change.
	 *
	 * @return array<int, array{title: string, detail: string}>
	 */
	public static function get_benefits(): array {
		$benefits = array(
			array(
				'title'  => __( 'Your pricing, right in the shop', 'protech-wholesale' ),
				'detail' => __( 'Log in and the shop shows your wholesale prices. No separate order form.', 'protech-wholesale' ),
			),
			array(
				'title'  => __( 'Tiered pricing and free shipping', 'protech-wholesale' ),
				'detail' => __( 'The more you order, the better your price — with free shipping unlocked at higher tiers. Mix any products and colours toward your total.', 'protech-wholesale' ),
			),
			array(
				'title'  => __( 'Order by the display or the case', 'protech-wholesale' ),
				'detail' => __( 'Pick the unit you stock in. We do the pack maths.', 'protech-wholesale' ),
			),
			array(
				'title'  => __( 'Reorder in one click', 'protech-wholesale' ),
				'detail' => __( 'Any past order goes straight back into your cart.', 'protech-wholesale' ),
			),
		);

		/**
		 * The benefit list on the /wholesale login page.
		 *
		 * @param array<int, array{title: string, detail: string}> $benefits
		 */
		return (array) apply_filters( 'protech_wholesale_portal_benefits', $benefits );
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
