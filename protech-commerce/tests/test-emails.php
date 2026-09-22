<?php
/**
 * Application-flow emails go through WooCommerce's mailer (branded
 * header/footer), the approval email links to the My Account password
 * form with a valid reset key, and the admin notice carries the answers.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Emails;
use ProtechWholesale\MessageLog;
use ProtechWholesale\Roles;

/**
 * Class Test_Emails
 */
class Test_Emails extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();

		// WC_Emails registers its header/footer actions in its constructor.
		// If the singleton was first created INSIDE an earlier test, the WP
		// test framework's hook restore at that test's end removed those
		// actions while the instance lived on, so every email wrapped from
		// then on has no header or footer — in the suite only, never on a
		// real request, where instance and hooks are created together.
		$mailer = WC()->mailer();

		if ( false === has_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) ) ) {
			add_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) );
			add_action( 'woocommerce_email_footer', array( $mailer, 'email_footer' ) );
		}
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

		// esc_url() writes the ampersand as &#038;; read the link the way a
		// mail client would.
		preg_match( '/[?&]key=([^&"]+)&id=(\d+)/', html_entity_decode( $mail->body, ENT_QUOTES | ENT_HTML5 ), $matches );
		$this->assertNotEmpty( $matches, 'Expected a key/id reset link in the body.' );
		$this->assertSame( $user_id, (int) $matches[2] );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( rawurldecode( $matches[1] ), get_userdata( $user_id )->user_login ) );
	}

	public function test_emails_are_wrapped_in_the_woocommerce_template(): void {
		$user_id = $this->applicant( 'ada@example.com' );

		Emails::send_applicant_received( $user_id );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();

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

	public function test_application_emails_are_logged(): void {
		$received_user = $this->applicant( 'ada@example.com' );
		Emails::send_applicant_received( $received_user );

		$approved_user = $this->applicant( 'grace@example.com' );
		Emails::send_approved( $approved_user );

		$rejected_user = $this->applicant( 'katherine@example.com' );
		Emails::send_rejected( $rejected_user, 'Outside our distribution area' );

		foreach (
			array(
				array( $received_user, 'ada@example.com' ),
				array( $approved_user, 'grace@example.com' ),
				array( $rejected_user, 'katherine@example.com' ),
			) as [ $user_id, $email ]
		) {
			$rows = MessageLog::query( array( 'user_id' => $user_id ) )['rows'];
			$this->assertCount( 1, $rows, "Expected exactly one logged message for {$email}." );
			$this->assertSame( MessageLog::KIND_LIFECYCLE, $rows[0]['kind'] );
			$this->assertSame( MessageLog::STATUS_SENT, $rows[0]['status'] );
			$this->assertSame( $email, $rows[0]['recipient'] );
			$this->assertNotEmpty( $rows[0]['subject'] );
		}
	}

	public function test_a_second_lifecycle_email_to_the_same_customer_is_its_own_log_row(): void {
		$user_id = $this->applicant( 'ada@example.com' );

		Emails::send_applicant_received( $user_id );
		Emails::send_rejected( $user_id, 'Changed our minds' );

		$rows = MessageLog::query( array( 'user_id' => $user_id ) )['rows'];
		$this->assertCount( 2, $rows, 'Two different lifecycle emails to the same customer must not collide on the dedup key.' );
	}
}
