<?php
/**
 * The Customers tab's two quick-add actions: bringing an already-existing
 * WooCommerce customer into wholesale directly (no application), and
 * creating a brand new account that's wholesale from the moment it's
 * created. Both funnel through Approval::approve_user(), covered on its
 * own in test-approval.php — what's specific here is the request handling
 * (nonce, selection, validation) around that shared call.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\CustomersTab;
use ProtechWholesale\Roles;

/**
 * Class Test_Customers_Tab
 */
class Test_Customers_Tab extends WP_UnitTestCase {

	public function tear_down(): void {
		unset(
			$_POST['protech_wholesale_add_existing_nonce'],
			$_POST['protech_existing_customer_ids'],
			$_POST['protech_email_existing'],
			$_POST['protech_wholesale_create_customer_nonce'],
			$_POST['protech_new_first_name'],
			$_POST['protech_new_last_name'],
			$_POST['protech_new_email']
		);
		parent::tear_down();
	}

	/**
	 * Runs an admin-post handler that ends in wp_safe_redirect() + exit,
	 * capturing the redirect instead of dying — same technique
	 * test-approval.php uses for the same kind of handler.
	 */
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

	public function test_add_existing_grants_wholesale_without_an_email_by_default(): void {
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_add_existing_nonce'] = wp_create_nonce( 'protech_wholesale_add_existing' );
		$_POST['protech_existing_customer_ids']        = array( (string) $customer_id );

		$location = $this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_add_existing' ) );

		$this->assertStringContainsString( 'protech_quickadd=added', $location );
		$this->assertTrue( Roles::is_wholesale_customer( $customer_id ) );
		$this->assertContains( 'customer', get_userdata( $customer_id )->roles );
	}

	public function test_add_existing_emails_when_asked(): void {
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer_id = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'existing@example.com' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_add_existing_nonce'] = wp_create_nonce( 'protech_wholesale_add_existing' );
		$_POST['protech_existing_customer_ids']        = array( (string) $customer_id );
		$_POST['protech_email_existing']               = '1';

		$this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_add_existing' ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'existing@example.com', $mail->to[0][0] );
	}

	public function test_add_existing_leaves_an_already_wholesale_account_alone(): void {
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer_id = Protech_Test_Factory::wholesale_customer();

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_add_existing_nonce'] = wp_create_nonce( 'protech_wholesale_add_existing' );
		$_POST['protech_existing_customer_ids']        = array( (string) $customer_id );
		$_POST['protech_email_existing']               = '1';

		$this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_add_existing' ) );

		// Already wholesale, so nothing (including no re-approval email) happens for them.
		// get_sent() is end() over the mock's sent list; false is what an empty list returns.
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_add_existing_with_nothing_selected_redirects_accordingly(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_add_existing_nonce'] = wp_create_nonce( 'protech_wholesale_add_existing' );

		$location = $this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_add_existing' ) );

		$this->assertStringContainsString( 'protech_quickadd=none_selected', $location );
	}

	public function test_create_new_makes_a_wholesale_account_and_emails_a_password_link(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_create_customer_nonce'] = wp_create_nonce( 'protech_wholesale_create_customer' );
		$_POST['protech_new_first_name']                  = 'Ada';
		$_POST['protech_new_last_name']                   = 'Lovelace';
		$_POST['protech_new_email']                       = 'ada.new@example.com';

		$location = $this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_create_new' ) );

		$this->assertStringContainsString( 'protech_quickadd=created', $location );

		$user = get_user_by( 'email', 'ada.new@example.com' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertTrue( Roles::is_wholesale_customer( $user->ID ) );
		$this->assertSame( 'Ada', $user->first_name );
		$this->assertSame( Approval::STATUS_APPROVED, get_user_meta( $user->ID, Approval::META_APP_STATUS, true ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'ada.new@example.com', $mail->to[0][0] );
	}

	public function test_create_new_rejects_an_email_that_already_exists(): void {
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$existing_id = self::factory()->user->create( array( 'user_email' => 'taken@example.com' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_create_customer_nonce'] = wp_create_nonce( 'protech_wholesale_create_customer' );
		$_POST['protech_new_email']                       = 'taken@example.com';

		$location = $this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_create_new' ) );

		$this->assertStringContainsString( 'protech_quickadd=exists', $location );
		$this->assertFalse( Roles::is_wholesale_customer( $existing_id ), 'The existing account must not have been touched.' );
	}

	public function test_create_new_rejects_an_invalid_email(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$_POST['protech_wholesale_create_customer_nonce'] = wp_create_nonce( 'protech_wholesale_create_customer' );
		$_POST['protech_new_email']                       = 'not-an-email';

		$location = $this->run_handler( array( 'ProtechWholesale\\CustomersTab', 'handle_create_new' ) );

		$this->assertStringContainsString( 'protech_quickadd=invalid_email', $location );
	}
}
