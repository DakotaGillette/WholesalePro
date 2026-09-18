<?php
/**
 * Notifications for the application/approval flow, plus the "WHOLESALE
 * ORDER" subject prefix on admin new-order emails (R6).
 *
 * Sent through WooCommerce's mailer so each message carries the store's
 * branded email header/footer and From name/address (WooCommerce →
 * Settings → Emails) instead of being a bare wp_mail() text body from
 * "WordPress".
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

	/**
	 * @param string   $type     'admin_new_application' | 'applicant_received' | 'approved' | 'rejected'.
	 * @param string   $body     HTML body (already escaped).
	 * @param string[] $headers  Extra headers (Reply-To etc.).
	 */
	private static function send( string $to, string $subject, string $heading, string $body, string $type, int $user_id, array $headers = array() ): void {
		/**
		 * Filters an outgoing wholesale email's HTML body.
		 *
		 * @param string $body    HTML body.
		 * @param string $type    Which email this is.
		 * @param int    $user_id The applicant/customer the email is about.
		 */
		$body = (string) apply_filters( 'protech_wholesale_email_body', $body, $type, $user_id );

		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );

		$mailer->send( $to, $subject, $message, array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), $headers ) );
	}

	private static function paragraph( string $text ): string {
		return '<p>' . esc_html( $text ) . '</p>';
	}

	public static function send_admin_new_application( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$answer = static function ( string $key ) use ( $user_id ): string {
			return (string) get_user_meta( $user_id, '_protech_wholesale_app_' . $key, true );
		};

		$store_name = $answer( 'store_name' );

		$subject = sprintf(
			/* translators: %s: applicant store name. */
			__( 'New wholesale application: %s', 'protech-wholesale' ),
			$store_name ?: $user->user_email
		);

		$fields = array(
			__( 'Store', 'protech-wholesale' )                  => $store_name,
			__( 'Contact', 'protech-wholesale' )                => trim( $answer( 'name' ) . ( $answer( 'title' ) ? ' (' . $answer( 'title' ) . ')' : '' ) ),
			__( 'Email', 'protech-wholesale' )                  => $user->user_email,
			__( 'Phone', 'protech-wholesale' )                  => $answer( 'phone' ),
			__( 'Business type', 'protech-wholesale' )          => $answer( 'business_type' ),
			__( 'Address', 'protech-wholesale' )                => $answer( 'address' ),
			__( 'Website', 'protech-wholesale' )                => $answer( 'website' ),
			__( 'Sales channels', 'protech-wholesale' )         => $answer( 'sales_channels' ),
			__( 'TCGs carried', 'protech-wholesale' )           => $answer( 'tcgs_carried' ),
			__( 'Hosts TCG events', 'protech-wholesale' )       => $answer( 'hosts_events' ) ? __( 'Yes', 'protech-wholesale' ) : __( 'No', 'protech-wholesale' ),
			__( 'Estimated monthly spend', 'protech-wholesale' ) => $answer( 'estimated_monthly_spend' ),
		);

		$body = self::paragraph( __( 'A new wholesale application was submitted.', 'protech-wholesale' ) );

		$body .= '<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">';

		foreach ( $fields as $label => $value ) {
			$body .= '<tr><th align="left" style="border-bottom:1px solid #e5e5e5;white-space:nowrap;">' . esc_html( $label ) . '</th>';
			$body .= '<td style="border-bottom:1px solid #e5e5e5;">' . esc_html( '' !== $value ? $value : '—' ) . '</td></tr>';
		}

		$body .= '</table>';

		if ( Roles::is_privileged( $user_id ) ) {
			$body .= self::paragraph( __( 'Note: this email address belongs to an existing staff account, so the application was recorded without changing that account\'s roles. Approve it from the user\'s profile if the request is genuine.', 'protech-wholesale' ) );
		}

		// Not get_edit_user_link(): that returns '' unless the CURRENT user
		// can edit users, and this runs during the applicant's own request.
		$body .= '<p><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $user_id ) ) . '">' . esc_html__( 'Review the full application and approve or reject it', 'protech-wholesale' ) . '</a><br />';
		$body .= '<a href="' . esc_url( admin_url( 'admin.php?page=protech-wholesale' ) ) . '">' . esc_html__( 'All pending applications', 'protech-wholesale' ) . '</a></p>';

		self::send(
			self::get_admin_email(),
			$subject,
			__( 'New wholesale application', 'protech-wholesale' ),
			$body,
			'admin_new_application',
			$user_id,
			array( 'Reply-To: ' . $user->user_email )
		);
	}

	public static function send_applicant_received( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$body  = self::paragraph( __( 'Thanks for applying for a Protech Sleeves wholesale account.', 'protech-wholesale' ) );
		$body .= self::paragraph( __( 'Our team typically reviews applications within 1–3 business days. We\'ll email you as soon as a decision is made.', 'protech-wholesale' ) );

		self::send(
			$user->user_email,
			__( 'We received your wholesale application', 'protech-wholesale' ),
			__( 'Application received', 'protech-wholesale' ),
			$body,
			'applicant_received',
			$user_id
		);
	}

	public static function send_approved( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$body = self::paragraph( __( 'Good news — your wholesale application has been approved.', 'protech-wholesale' ) );

		$reset_key = get_password_reset_key( $user );

		if ( ! is_wp_error( $reset_key ) ) {
			// The same link WooCommerce's own password-reset email uses:
			// the My Account "lost password" endpoint with the key, so the
			// customer sets their password inside the store's own pages
			// rather than on the bare wp-login.php screen.
			$set_password_url = add_query_arg(
				array(
					'key' => $reset_key,
					'id'  => $user_id,
				),
				wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
			);

			$body .= '<p><a href="' . esc_url( $set_password_url ) . '" style="display:inline-block;padding:10px 18px;background:#000;color:#fff;text-decoration:none;border-radius:8px;">' . esc_html__( 'Set your password and log in', 'protech-wholesale' ) . '</a></p>';
			$body .= self::paragraph( __( 'That link is valid for 24 hours. If it has expired, use "Lost your password?" on the login page with this email address to get a new one.', 'protech-wholesale' ) );
		} else {
			$body .= '<p><a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Log in to your account', 'protech-wholesale' ) . '</a></p>';
		}

		$body .= self::paragraph( __( 'Once logged in you\'ll see wholesale pricing throughout the shop. Quantity tiers apply to your whole cart combined, and "Reorder" on any past order adds it straight back to your cart.', 'protech-wholesale' ) );

		self::send(
			$user->user_email,
			__( 'Your Protech Sleeves wholesale account is approved!', 'protech-wholesale' ),
			__( 'Welcome to Protech Wholesale', 'protech-wholesale' ),
			$body,
			'approved',
			$user_id
		);
	}

	public static function send_rejected( int $user_id, string $reason = '' ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$body = self::paragraph( __( 'Thanks for your interest in a Protech Sleeves wholesale account. After review, we\'re not able to approve your application at this time.', 'protech-wholesale' ) );

		if ( '' !== $reason ) {
			$body .= self::paragraph( sprintf( /* translators: %s: rejection reason. */ __( 'Reason: %s', 'protech-wholesale' ), $reason ) );
		}

		$body .= self::paragraph( __( 'If you think this was a mistake, or your business has changed since you applied, just reply to this email.', 'protech-wholesale' ) );

		self::send(
			$user->user_email,
			__( 'Update on your Protech Sleeves wholesale application', 'protech-wholesale' ),
			__( 'Your wholesale application', 'protech-wholesale' ),
			$body,
			'rejected',
			$user_id,
			array( 'Reply-To: ' . self::get_admin_email() )
		);
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
		return (string) apply_filters( 'protech_wholesale_admin_notification_email', Settings::notification_email() );
	}
}
