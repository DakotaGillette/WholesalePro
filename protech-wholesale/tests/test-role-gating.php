<?php
/**
 * Covers the role-gating requirements around wholesale pricing: guests and
 * retail customers never see wholesale prices, an applicant in the pending
 * role isn't treated as an approved wholesale customer, and the roles
 * themselves exist with the expected capability once created.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Pricing;
use ProtechWholesale\ProductFields;
use ProtechWholesale\Roles;

/**
 * Class Test_Role_Gating
 */
class Test_Role_Gating extends WP_UnitTestCase {

	private function create_product_with_group_price( string $price = '5.00' ): WC_Product_Simple {
		if ( class_exists( 'WC_Helper_Product' ) ) {
			$product = WC_Helper_Product::create_simple_product();
		} else {
			$product = new WC_Product_Simple();
			$product->set_name( 'Protech Test Product' );
			$product->set_regular_price( '20.00' );
			$product->set_status( 'publish' );
			$product->save();
		}

		update_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE, $price );

		return $product;
	}

	public function test_guest_never_gets_wholesale_price(): void {
		$product = $this->create_product_with_group_price();

		wp_set_current_user( 0 );

		$this->assertNull( Pricing::get_wholesale_price( $product->get_id(), 0 ) );
	}

	public function test_retail_customer_never_gets_wholesale_price(): void {
		$product   = $this->create_product_with_group_price();
		$retail_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		wp_set_current_user( $retail_id );

		$this->assertNull( Pricing::get_wholesale_price( $product->get_id(), $retail_id ) );
	}

	public function test_pending_applicant_is_not_a_wholesale_customer(): void {
		$product    = $this->create_product_with_group_price();
		$pending_id = self::factory()->user->create( array( 'role' => Roles::PENDING ) );

		$this->assertFalse( Roles::is_wholesale_customer( $pending_id ) );
		$this->assertTrue( Roles::is_wholesale_pending( $pending_id ) );
		$this->assertNull( Pricing::get_wholesale_price( $product->get_id(), $pending_id ) );
	}

	public function test_approved_wholesale_customer_gets_wholesale_price(): void {
		$product     = $this->create_product_with_group_price( '5.00' );
		$customer_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );

		$this->assertTrue( Roles::is_wholesale_customer( $customer_id ) );
		$this->assertSame( 5.0, Pricing::get_wholesale_price( $product->get_id(), $customer_id ) );
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
