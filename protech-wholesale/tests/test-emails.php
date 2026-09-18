<?php
/**
 * Application-flow emails go through WooCommerce's mailer (branded
 * header/footer), the approval email links to the My Account password
 * form with a valid reset key, and the admin notice carries the answers.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Emails;
use ProtechWholesale\Roles;

/**
 * Class Test_Emails
 */
class Test_Emails extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	private function applicant( string $email ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::PENDING,
				'user_email' => $email,
			)
		);
		update_user_meta( $user_id, '_protech_wholesale_app_store_name', 'Analytical Engine Games' );
		update_user_meta( $user_id, '_protech_wholesale_app_name', 'Ada Lovelace' );
		update_user_meta( $user_id, '_protech_wholesale_app_phone', '555-0100' );
		update_user_meta( $user_id, '_protech_wholesale_app_hosts_events', true );

		return $user_id;
	}

	public function test_approval_email_links_to_the_my_account_password_form_with_a_valid_key(): void {
		$user_id = $this->applicant( 'ada@example.com' );

		Emails::send_approved( $user_id );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'ada@example.com', $mail->to[0][0] );
		$this->assertStringContainsString( 'approved', $mail->subject );
		$this->assertStringContainsString( 'lost-password', $mail->body );
		$this->assertStringNotContainsString( 'wp-login.php', $mail->body );

		preg_match( '/[?&]key=([^&"]+)&(?:amp;)?id=(\d+)/', $mail->body, $matches );
		$this->assertNotEmpty( $matches, 'Expected a key/id reset link in the body.' );
		$this->assertSame( $user_id, (int) $matches[2] );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( rawurldecode( $matches[1] ), get_userdata( $user_id )->user_login ) );
	}

	public function test_emails_are_wrapped_in_the_woocommerce_template(): void {
		$user_id = $this->applicant( 'ada@example.com' );

		Emails::send_applicant_received( $user_id );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		// TEMPORARY DIAGNOSTIC (CI only): why is the WooCommerce header absent?
		fwrite( STDERR, "\n[diag] has_action email_header: " . var_export( has_action( 'woocommerce_email_header' ), true ) . "\n" );
		fwrite( STDERR, '[diag] located template: ' . var_export( wc_locate_template( 'emails/email-header.php' ), true ) . "\n" );
		fwrite( STDERR, '[diag] wrap_message: ' . substr( preg_replace( '/\s+/', ' ', WC()->mailer()->wrap_message( 'Diag', '<p>x</p>' ) ), 0, 400 ) . "\n" );
		fwrite( STDERR, '[diag] mailer class: ' . get_class( WC()->mailer() ) . ' | WC ' . WC()->version . "\n" );
		fwrite( STDERR, '[diag] body head: ' . substr( preg_replace( '/\s+/', ' ', $mail->body ), 0, 300 ) . "\n" );

		$this->assertStringContainsString( 'Content-Type: text/html', $mail->header );
		// WooCommerce's email-header.php / email-footer.php markup.
		$this->assertStringContainsString( 'id="template_header"', $mail->body );
		$this->assertStringContainsString( 'id="template_footer"', $mail->body );
		$this->assertStringContainsString( '1–3 business days', $mail->body );
	}

	public function test_admin_notice_includes_the_answers_and_replies_to_the_applicant(): void {
		update_option( 'admin_email', 'owner@example.com' );
		$user_id = $this->applicant( 'ada@example.com' );

		Emails::send_admin_new_application( $user_id );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( 'owner@example.com', $mail->to[0][0] );
		$this->assertStringContainsString( 'Analytical Engine Games', $mail->subject );
		$this->assertStringContainsString( 'Ada Lovelace', $mail->body );
		$this->assertStringContainsString( '555-0100', $mail->body );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $user_id, $mail->body );
		$this->assertStringContainsString( 'Reply-To: ada@example.com', $mail->header );
	}

	public function test_rejection_email_carries_the_reason(): void {
		$user_id = $this->applicant( 'ada@example.com' );

		Emails::send_rejected( $user_id, 'Outside our distribution area' );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertStringContainsString( 'Outside our distribution area', $mail->body );
	}
}
