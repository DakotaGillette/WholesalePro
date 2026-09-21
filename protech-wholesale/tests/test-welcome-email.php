<?php
/**
 * The welcome email for accounts upgraded to wholesale: what it says, that
 * a real send reaches the customer and stamps them, that a preview goes to
 * any typed address without stamping anyone, and the Customers-tab
 * handlers around it.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;
use ProtechWholesale\WelcomeEmail;

/**
 * Class Test_Welcome_Email
 */
class Test_Welcome_Email extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();

		// Same suite-only workaround as Test_Emails: keep WooCommerce's
		// header/footer actions alive across the framework's hook restore.
		$mailer = WC()->mailer();

		if ( false === has_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) ) ) {
			add_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) );
			add_action( 'woocommerce_email_footer', array( $mailer, 'email_footer' ) );
		}
	}

	public function tear_down(): void {
		unset( $_POST['protech_wholesale_customer_tiers_nonce'], $_POST['protech_customer_ids'], $_POST['protech_welcome_preview_nonce'], $_POST['preview_email'], $_REQUEST['protech_wholesale_customer_tiers_nonce'], $_REQUEST['protech_welcome_preview_nonce'] );
		parent::tear_down();
	}

	private function customer( string $email = 'ada@example.com' ): int {
		return self::factory()->user->create(
			array(
				'role'       => ProtechWholesale\Roles::CUSTOMER,
				'user_email' => $email,
				'first_name' => 'Ada',
			)
		);
	}

	private function run_handler( callable $handler ): string {
		$location = '';

		$capture = static function ( $target ) use ( &$location ) {
			$location = (string) $target;
			throw new RuntimeException( 'redirect' );
		};

		add_filter( 'wp_redirect', $capture );

		try {
			$handler();
		} catch ( RuntimeException $e ) {
			// Expected: the redirect short-circuits exit.
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		return $location;
	}

	public function test_body_explains_how_to_log_in_and_how_cases_work(): void {
		$body = WelcomeEmail::body_html( $this->customer() );

		$this->assertStringContainsString( 'Hi Ada,', $body );
		$this->assertStringContainsString( 'ada@example.com', $body, 'The address they log in with.' );
		$this->assertStringContainsString( esc_url( WelcomeEmail::login_url() ), $body );
		$this->assertStringContainsString( 'lost-password', $body );
		$this->assertStringContainsString( 'How wholesale quantities work', $body );
		$this->assertStringContainsString( '8 displays = 1 case.', $body );
		$this->assertStringContainsString( 'Mix and match your displays', $body );
		$this->assertStringContainsString( 'Under 16 displays', $body );
		$this->assertStringContainsString( '16 displays or more (2 cases)', $body );
		$this->assertStringContainsString( '16 cases or more (128 displays)', $body );
	}

	public function test_send_reaches_the_customer_logs_it_and_stamps_them(): void {
		$customer_id = $this->customer();

		$result = WelcomeEmail::send( $customer_id );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ada@example.com', $result['recipient'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'ada@example.com', $mail->to[0][0] );
		$this->assertSame( WelcomeEmail::subject(), $mail->subject );
		$this->assertStringContainsString( 'Welcome to Protech Wholesale', $mail->body );

		$this->assertGreaterThan( 0, (int) get_user_meta( $customer_id, WelcomeEmail::META_SENT_AT, true ) );

		$row = MessageLog::query( array( 'user_id' => $customer_id ), 1, 1 )['rows'][0] ?? null;
		$this->assertNotNull( $row );
		$this->assertSame( MessageLog::KIND_WELCOME, $row['kind'] );
		$this->assertSame( MessageLog::STATUS_SENT, $row['status'] );
	}

	public function test_a_preview_goes_to_the_typed_address_and_stamps_nobody(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );

		$result = WelcomeEmail::send( $admin_id, 'owner@example.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'owner@example.com', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
		$this->assertSame( '', get_user_meta( $admin_id, WelcomeEmail::META_SENT_AT, true ) );

		$row = MessageLog::query( array( 'user_id' => $admin_id ), 1, 1 )['rows'][0] ?? null;
		$this->assertSame( MessageLog::KIND_TEST, $row['kind'] );
	}

	public function test_it_is_not_sent_to_an_account_that_is_not_wholesale(): void {
		$retail_id = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'retail@example.com' ) );

		$result = WelcomeEmail::send( $retail_id );

		$this->assertFalse( $result['ok'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
		$this->assertSame( '', get_user_meta( $retail_id, WelcomeEmail::META_SENT_AT, true ) );
	}

	public function test_send_selected_emails_each_wholesale_customer_ticked(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$a        = $this->customer( 'a@example.com' );
		$b        = $this->customer( 'b@example.com' );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_customer_tiers_nonce'] = $_REQUEST['protech_wholesale_customer_tiers_nonce'] = wp_create_nonce( 'protech_wholesale_customer_tiers' ); // check_admin_referer() reads $_REQUEST.
		$_POST['protech_customer_ids']                   = array( (string) $a, (string) $b );

		$location = $this->run_handler( array( new WelcomeEmail(), 'handle_send_selected' ) );

		$this->assertStringContainsString( 'protech_welcome=sent', $location );
		$this->assertStringContainsString( 'sent=2', $location );
		$this->assertGreaterThan( 0, (int) get_user_meta( $a, WelcomeEmail::META_SENT_AT, true ) );
		$this->assertGreaterThan( 0, (int) get_user_meta( $b, WelcomeEmail::META_SENT_AT, true ) );
	}

	public function test_send_selected_with_nothing_ticked_says_so(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_customer_tiers_nonce'] = $_REQUEST['protech_wholesale_customer_tiers_nonce'] = wp_create_nonce( 'protech_wholesale_customer_tiers' ); // check_admin_referer() reads $_REQUEST.

		$location = $this->run_handler( array( new WelcomeEmail(), 'handle_send_selected' ) );

		$this->assertStringContainsString( 'protech_welcome=none', $location );
	}

	public function test_the_preview_handler_uses_the_typed_address_else_the_admins_own(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_welcome_preview_nonce'] = $_REQUEST['protech_welcome_preview_nonce'] = wp_create_nonce( 'protech_welcome_preview' );
		$_POST['preview_email']                 = 'typed@example.com';

		$location = $this->run_handler( array( new WelcomeEmail(), 'handle_send_preview' ) );

		$this->assertStringContainsString( 'protech_welcome=preview', $location );
		$this->assertSame( 'typed@example.com', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );

		reset_phpmailer_instance();
		$_POST['preview_email'] = '';
		$this->run_handler( array( new WelcomeEmail(), 'handle_send_preview' ) );

		$this->assertSame( 'admin@example.com', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
	}

	public function test_the_row_link_sends_to_that_one_customer(): void {
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer_id = $this->customer();

		wp_set_current_user( $admin_id );
		$_GET['user_id']      = (string) $customer_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'protech_send_welcome_' . $customer_id );

		$location = $this->run_handler( array( new WelcomeEmail(), 'handle_send_one' ) );

		unset( $_GET['user_id'], $_REQUEST['_wpnonce'] );

		$this->assertStringContainsString( 'sent=1', $location );
		$this->assertSame( 'ada@example.com', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
	}
}
