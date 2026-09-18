<?php
/**
 * AffiliateAssignment: links a wholesale customer to a SliceWP affiliate
 * for "lifetime commissions" by calling SliceWP Pro's own underlying
 * functions directly (no fake AJAX calls, no separate data store of our
 * own). SliceWP isn't installed in the test environment, so what's
 * tested here is the same thing test-setup-checks.php tests for Fluent
 * Forms: every method degrades gracefully — never fatals, never assumes
 * the integration is present — when it isn't. The real write/read paths
 * against SliceWP's actual functions were verified by hand on staging
 * (reading SliceWP's own source, then a live round-trip through the
 * Customers-tab dropdown), the same way TaxExemption's real mechanism was.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\AffiliateAssignment;

/**
 * Class Test_Affiliate_Assignment
 */
class Test_Affiliate_Assignment extends WP_UnitTestCase {

	public function test_is_not_available_without_slicewp_installed(): void {
		$this->assertFalse( AffiliateAssignment::is_available(), 'SliceWP is not installed in the test environment.' );
	}

	public function test_get_affiliate_options_is_empty_without_slicewp(): void {
		$this->assertSame( array(), AffiliateAssignment::get_affiliate_options() );
	}

	public function test_get_assigned_affiliate_id_is_zero_without_slicewp(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$this->assertSame( 0, AffiliateAssignment::get_assigned_affiliate_id( $user_id ) );
	}

	public function test_assign_is_a_no_op_without_slicewp(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$this->assertSame( '', AffiliateAssignment::assign( $user_id, 123 ) );
		$this->assertSame( 0, AffiliateAssignment::get_assigned_affiliate_id( $user_id ) );
	}

	public function test_unassign_does_not_fatal_without_slicewp(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		AffiliateAssignment::unassign( $user_id );

		$this->assertSame( 0, AffiliateAssignment::get_assigned_affiliate_id( $user_id ) );
	}

	public function test_assign_rejects_invalid_ids(): void {
		$this->assertSame( '', AffiliateAssignment::assign( 0, 123 ) );
		$this->assertSame( '', AffiliateAssignment::assign( 123, 0 ) );
	}
}
