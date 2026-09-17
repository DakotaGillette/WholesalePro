<?php
/**
 * Covers CaseRules: case-size resolution/fallback, the "quantity must be a
 * multiple of the case size" check, per-customer minimum-order override,
 * and the woocommerce_add_to_cart_validation callback that enforces it for
 * wholesale customers only.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\CaseRules;
use ProtechWholesale\ProductFields;
use ProtechWholesale\Roles;
use ProtechWholesale\Settings;

/**
 * Class Test_CaseRules
 */
class Test_CaseRules extends WP_UnitTestCase {

	private function create_product(): WC_Product_Simple {
		if ( class_exists( 'WC_Helper_Product' ) ) {
			return WC_Helper_Product::create_simple_product();
		}

		$product = new WC_Product_Simple();
		$product->set_name( 'Protech Test Product' );
		$product->set_regular_price( '20.00' );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	private function create_wholesale_customer(): int {
		return self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
	}

	public function test_get_case_size_uses_product_meta_when_set(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, 6 );

		$this->assertSame( 6, CaseRules::get_case_size( $product->get_id() ) );
	}

	public function test_get_case_size_falls_back_to_default_when_unset(): void {
		$product = $this->create_product();

		$this->assertSame( ProductFields::DEFAULT_CASE_SIZE, CaseRules::get_case_size( $product->get_id() ) );
	}

	public function test_get_case_size_falls_back_to_default_when_zero(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, 0 );

		$this->assertSame( ProductFields::DEFAULT_CASE_SIZE, CaseRules::get_case_size( $product->get_id() ) );
	}

	public function test_is_multiple_of_case(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, 10 );

		$this->assertTrue( CaseRules::is_multiple_of_case( $product->get_id(), 30 ) );
		$this->assertFalse( CaseRules::is_multiple_of_case( $product->get_id(), 25 ) );
		$this->assertFalse( CaseRules::is_multiple_of_case( $product->get_id(), 0 ) );
	}

	public function test_get_minimum_order_defaults_to_settings_when_no_override(): void {
		$customer_id = $this->create_wholesale_customer();

		$this->assertSame( Settings::get_min_order(), CaseRules::get_minimum_order( $customer_id ) );
		$this->assertSame( 800.0, CaseRules::get_minimum_order( $customer_id ) );
	}

	public function test_get_minimum_order_uses_per_customer_override(): void {
		$customer_id = $this->create_wholesale_customer();
		update_user_meta( $customer_id, Approval::META_MIN_ORDER, 250 );

		$this->assertSame( 250.0, CaseRules::get_minimum_order( $customer_id ) );
	}

	public function test_validate_add_to_cart_blocks_non_multiple_quantity_for_wholesale_customer(): void {
		$product     = $this->create_product();
		$customer_id = $this->create_wholesale_customer();
		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, 10 );

		wp_set_current_user( $customer_id );

		$case_rules = new CaseRules();
		$result     = $case_rules->validate_add_to_cart( true, $product->get_id(), 25 );

		$this->assertFalse( $result );
	}

	public function test_validate_add_to_cart_does_not_block_retail_customers(): void {
		$product     = $this->create_product();
		$retail_id   = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, 10 );

		wp_set_current_user( $retail_id );

		$case_rules = new CaseRules();
		$result     = $case_rules->validate_add_to_cart( true, $product->get_id(), 25 );

		// Retail customers are unaffected by the case-size rule: whatever
		// $passed came in as is returned unchanged.
		$this->assertTrue( $result );
	}

	public function test_validate_add_to_cart_allows_a_multiple_of_the_case_size(): void {
		$product     = $this->create_product();
		$customer_id = $this->create_wholesale_customer();
		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, 10 );

		wp_set_current_user( $customer_id );

		$case_rules = new CaseRules();
		$result     = $case_rules->validate_add_to_cart( true, $product->get_id(), 30 );

		$this->assertTrue( $result );
	}
}
