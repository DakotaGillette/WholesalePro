<?php
/**
 * Approve/reject lifecycle: role changes are additive, a rejected applicant
 * stops being "pending" but keeps a reviewable record, and the Applicants
 * list views follow the application status rather than the role.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\ApplicantsListTable;
use ProtechWholesale\Approval;
use ProtechWholesale\Roles;

/**
 * Class Test_Approval
 */
class Test_Approval extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_List_Table needs the screen machinery, which only wp-admin loads.
		foreach ( array( 'class-wp-screen.php', 'screen.php', 'template.php' ) as $file ) {
			require_once ABSPATH . 'wp-admin/includes/' . $file;
		}
	}

	private function pending_applicant( string $email ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::PENDING,
				'user_email' => $email,
			)
		);
		update_user_meta( $user_id, Approval::META_APP_STATUS, Approval::STATUS_PENDING );
		update_user_meta( $user_id, '_protech_wholesale_app_store_name', 'Test Store' );

		return $user_id;
	}

	/**
	 * Runs an admin-post handler that ends in wp_safe_redirect() + exit,
	 * capturing the redirect instead of dying.
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

	public function test_reject_removes_the_pending_role_and_records_the_reason(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_id  = $this->pending_applicant( 'reject@example.com' );

		wp_set_current_user( $admin_id );
		$_REQUEST['user_id']  = (string) $user_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'protech_reject_applicant_' . $user_id );
		$_POST['reason']      = 'Outside our distribution area';

		$location = $this->run_handler( array( new Approval(), 'handle_reject' ) );

		$this->assertStringContainsString( 'status=rejected', $location );
		$this->assertFalse( Roles::is_wholesale_pending( $user_id ) );
		$this->assertFalse( Roles::is_wholesale_customer( $user_id ) );
		$this->assertSame( array( 'customer' ), array_values( get_userdata( $user_id )->roles ) );
		$this->assertSame( Approval::STATUS_REJECTED, get_user_meta( $user_id, Approval::META_APP_STATUS, true ) );
		$this->assertSame( 'Outside our distribution area', get_user_meta( $user_id, Approval::META_APP_REJECT_REASON, true ) );

		unset( $_REQUEST['user_id'], $_REQUEST['_wpnonce'], $_POST['reason'] );
	}

	public function test_approve_grants_customer_role_additively(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_id  = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'approve@example.com',
			)
		);
		Roles::grant( $user_id, Roles::PENDING );
		update_user_meta( $user_id, Approval::META_APP_STATUS, Approval::STATUS_PENDING );

		wp_set_current_user( $admin_id );
		$_REQUEST['user_id']  = (string) $user_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'protech_approve_applicant_' . $user_id );

		$location = $this->run_handler( array( new Approval(), 'handle_approve' ) );

		$this->assertStringContainsString( 'status=approved', $location );
		$this->assertTrue( Roles::is_wholesale_customer( $user_id ) );
		$this->assertFalse( Roles::is_wholesale_pending( $user_id ) );
		$this->assertContains( 'customer', get_userdata( $user_id )->roles );
		$this->assertSame( Approval::STATUS_APPROVED, get_user_meta( $user_id, Approval::META_APP_STATUS, true ) );

		unset( $_REQUEST['user_id'], $_REQUEST['_wpnonce'] );
	}

	public function test_list_views_follow_application_status(): void {
		$pending  = $this->pending_applicant( 'p@example.com' );
		$rejected = $this->pending_applicant( 'r@example.com' );
		Roles::revoke( $rejected, Roles::PENDING );
		update_user_meta( $rejected, Approval::META_APP_STATUS, Approval::STATUS_REJECTED );
		$approved = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );

		$ids_in_view = static function ( string $status ): array {
			$_GET['status'] = $status;
			$table          = new ApplicantsListTable();
			$table->prepare_items();
			unset( $_GET['status'] );

			return array_map( static fn( array $item ): int => (int) $item['id'], $table->items );
		};

		$this->assertSame( array( $pending ), $ids_in_view( Approval::STATUS_PENDING ) );
		$this->assertSame( array( $rejected ), $ids_in_view( Approval::STATUS_REJECTED ) );
		$this->assertContains( $approved, $ids_in_view( Approval::STATUS_APPROVED ) );
		$this->assertNotContains( $pending, $ids_in_view( Approval::STATUS_APPROVED ) );
	}
}
