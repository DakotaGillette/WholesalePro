<?php
/**
 * Roles::grant()/revoke() must only ever ADD or REMOVE the two wholesale
 * roles — never replace whatever else an account already is. The old
 * set_role() calls let a public form submission demote an administrator.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Roles;

/**
 * Class Test_Roles
 */
class Test_Roles extends WP_UnitTestCase {

	public function test_grant_customer_keeps_an_administrators_existing_role(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		Roles::grant( $admin_id, Roles::CUSTOMER );

		$user = get_userdata( $admin_id );
		$this->assertContains( 'administrator', $user->roles );
		$this->assertContains( Roles::CUSTOMER, $user->roles );
		$this->assertTrue( user_can( $admin_id, 'manage_options' ) );
		$this->assertTrue( Roles::is_wholesale_customer( $admin_id ) );
	}

	public function test_grant_customer_drops_pending_and_vice_versa(): void {
		$user_id = self::factory()->user->create( array( 'role' => Roles::PENDING ) );

		Roles::grant( $user_id, Roles::CUSTOMER );
		$this->assertSame( array( Roles::CUSTOMER ), array_values( get_userdata( $user_id )->roles ) );

		Roles::grant( $user_id, Roles::PENDING );
		$this->assertSame( array( Roles::PENDING ), array_values( get_userdata( $user_id )->roles ) );
	}

	public function test_grant_pending_keeps_the_customer_role(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		Roles::grant( $user_id, Roles::PENDING );

		$roles = get_userdata( $user_id )->roles;
		$this->assertContains( 'customer', $roles );
		$this->assertContains( Roles::PENDING, $roles );
		$this->assertTrue( Roles::is_wholesale_pending( $user_id ) );
	}

	public function test_revoke_leaves_an_ordinary_customer_role_behind(): void {
		$user_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );

		Roles::revoke( $user_id, Roles::CUSTOMER );

		$this->assertSame( array( 'customer' ), array_values( get_userdata( $user_id )->roles ) );
		$this->assertFalse( Roles::is_wholesale_customer( $user_id ) );
	}

	public function test_revoke_does_not_touch_other_roles(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		Roles::grant( $user_id, Roles::CUSTOMER );

		Roles::revoke( $user_id, Roles::CUSTOMER );

		$this->assertSame( array( 'shop_manager' ), array_values( get_userdata( $user_id )->roles ) );
	}

	public function test_is_privileged(): void {
		$this->assertTrue( Roles::is_privileged( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
		$this->assertTrue( Roles::is_privileged( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) ) );
		$this->assertTrue( Roles::is_privileged( self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		$this->assertFalse( Roles::is_privileged( self::factory()->user->create( array( 'role' => 'customer' ) ) ) );
		$this->assertFalse( Roles::is_privileged( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) );
		$this->assertFalse( Roles::is_privileged( self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) ) ) );
	}

	public function test_create_roles_registers_both_roles_with_expected_capability(): void {
		Roles::create_roles();

		$customer_role = get_role( Roles::CUSTOMER );
		$pending_role  = get_role( Roles::PENDING );

		$this->assertNotNull( $customer_role );
		$this->assertNotNull( $pending_role );
		$this->assertTrue( $customer_role->has_cap( Roles::CAP_WHOLESALE ) );
	}
}
