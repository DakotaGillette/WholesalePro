<?php
/**
 * Plain wp_mail() notifications for the application/approval flow, plus
 * the "WHOLESALE ORDER" subject prefix on admin new-order emails (R6).
 *
 * These are simple transactional notices, not WooCommerce customer
 * emails, so they don't need the full WC_Email template system.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Emails
 */
class Emails {

	public function register_hooks(): void {
		add_filter( 'woocommerce_email_subject_new_order', array( $this, 'flag_wholesale_order_subject' ), 10, 2 );
	}

	public static function send_admin_new_application( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$store_name = get_user_meta( $user_id, '_protech_wholesale_app_store_name', true );

		$subject = sprintf(
			/* translators: %s: applicant store name. */
			__( 'New wholesale application: %s', 'protech-wholesale' ),
			$store_name ?: $user->user_email
		);

		$review_url = admin_url( 'admin.php?page=protech-wholesale' );

		$body = sprintf(
			/* translators: 1: store name, 2: email, 3: review URL. */
			__( "A new wholesale application was submitted.\n\nStore: %1\$s\nEmail: %2\$s\n\nReview it here: %3\$s", 'protech-wholesale' ),
			$store_name ?: '—',
			$user->user_email,
			$review_url
		);

		wp_mail( self::get_admin_email(), $subject, $body );
	}

	public static function send_applicant_received( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$subject = __( 'We received your wholesale application', 'protech-wholesale' );

		$body = __( "Thanks for applying for a Protech Sleeves wholesale account.\n\nOur team typically reviews applications within 1–3 business days. We'll email you as soon as a decision is made.", 'protech-wholesale' );

		wp_mail( $user->user_email, $subject, apply_filters( 'protech_wholesale_email_body', $body, 'applicant_received', $user_id ) );
	}

	public static function send_approved( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$reset_key = get_password_reset_key( $user );
		$login_url = is_wp_error( $reset_key )
			? wc_get_page_permalink( 'myaccount' )
			: network_site_url( "wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode( $user->user_login ), 'login' );

		$subject = __( 'Your Protech Sleeves wholesale account is approved!', 'protech-wholesale' );

		$body = sprintf(
			/* translators: %s: password set/reset link. */
			__( "Good news — your wholesale application has been approved.\n\nSet your password and log in here: %s\n\nOnce logged in you'll see wholesale pricing throughout the shop and can order as usual.", 'protech-wholesale' ),
			$login_url
		);

		wp_mail( $user->user_email, $subject, apply_filters( 'protech_wholesale_email_body', $body, 'approved', $user_id ) );
	}

	public static function send_rejected( int $user_id, string $reason = '' ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$subject = __( 'Update on your Protech Sleeves wholesale application', 'protech-wholesale' );

		$body = __( "Thanks for your interest in a Protech Sleeves wholesale account. After review, we're not able to approve your application at this time.", 'protech-wholesale' );

		if ( $reason ) {
			$body .= "\n\n" . sprintf( /* translators: %s: rejection reason. */ __( 'Reason: %s', 'protech-wholesale' ), $reason );
		}

		wp_mail( $user->user_email, $subject, apply_filters( 'protech_wholesale_email_body', $body, 'rejected', $user_id ) );
	}

	public function flag_wholesale_order_subject( string $subject, $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return $subject;
		}

		$flag = (string) $order->get_meta( OrdersAdmin::META_IS_WHOLESALE );

		// Orders placed through the Blocks checkout before the Store API
		// stamp hook existed (see OrdersAdmin::register_hooks()) carry no
		// flag at all — fall back to the customer's role for those rather
		// than silently dropping the prefix.
		$is_wholesale = 'yes' === $flag
			|| ( '' === $flag && Roles::is_wholesale_customer( (int) $order->get_customer_id() ) );

		if ( $is_wholesale ) {
			$subject = __( 'WHOLESALE ORDER', 'protech-wholesale' ) . ' — ' . $subject;
		}

		return $subject;
	}

	private static function get_admin_email(): string {
		return apply_filters( 'protech_wholesale_admin_notification_email', get_option( 'admin_email' ) );
	}
}
