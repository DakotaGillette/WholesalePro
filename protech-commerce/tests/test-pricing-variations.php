<?php
/**
 * Variable products are priced per VARIATION; the parent itself never has
 * a wholesale price. The shop grid and the "Wholesale price" label are
 * handed the parent, so they must look through to the variations — the
 * bug that hid the flagship product from every wholesale customer.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Pricing;
use ProtechWholesale\ProductFields;
use ProtechWholesale\Settings;

/**
 * Class Test_Pricing_Variations
 */
class Test_Pricing_Variations extends WP_UnitTestCase {

	public function test_parent_is_available_when_any_variation_is_priced(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$built       = Protech_Test_Factory::variable_product( array( 'blue', 'red' ), array( 'red' => '5.50' ) );
		$parent_id   = $built['parent']->get_id();

		// Strict, ID-exact check: the parent has no price of its own ...
		$this->assertFalse( Pricing::is_available_at_wholesale( $parent_id, $customer_id ) );
		$this->assertFalse( Pricing::is_available_at_wholesale( $built['variations']['blue']->get_id(), $customer_id ) );
		$this->assertTrue( Pricing::is_available_at_wholesale( $built['variations']['red']->get_id(), $customer_id ) );

		// ... but the catalog-facing check sees the priced variation.
		$this->assertTrue( Pricing::is_available_at_wholesale_including_variations( $parent_id, $customer_id ) );
	}

	public function test_parent_is_unavailable_when_no_variation_is_priced(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$built       = Protech_Test_Factory::variable_product( array( 'blue', 'red' ) );

		$this->assertFalse( Pricing::is_available_at_wholesale_including_variations( $built['parent']->get_id(), $customer_id ) );
	}

	public function test_catalog_visibility_filter_keeps_a_per_variation_priced_product_visible(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$built       = Protech_Test_Factory::variable_product( array( 'blue' ), array( 'blue' => '5.50' ) );
		$unpriced    = Protech_Test_Factory::variable_product( array( 'blue' ) );

		update_option( Settings::OPT_EMPTY_PRICE_BEHAVIOR, 'hide' );
		wp_set_current_user( $customer_id );

		$pricing = new Pricing();

		$this->assertTrue( $pricing->filter_catalog_visibility( true, $built['parent']->get_id() ) );
		$this->assertFalse( $pricing->filter_catalog_visibility( true, $unpriced['parent']->get_id() ) );
	}

	public function test_has_wholesale_price_flag_tracks_variation_prices(): void {
		$built     = Protech_Test_Factory::variable_product( array( 'blue', 'red' ) );
		$parent_id = $built['parent']->get_id();

		$this->assertSame( 'no', get_post_meta( $parent_id, ProductFields::META_HAS_WHOLESALE_PRICE, true ) );

		update_post_meta( $built['variations']['red']->get_id(), ProductFields::META_WHOLESALE_PRICE, '5.50' );
		ProductFields::sync_has_wholesale_price_flag( $built['variations']['red']->get_id() );

		$this->assertSame( 'yes', get_post_meta( $parent_id, ProductFields::META_HAS_WHOLESALE_PRICE, true ) );

		delete_post_meta( $built['variations']['red']->get_id(), ProductFields::META_WHOLESALE_PRICE );
		ProductFields::sync_has_wholesale_price_flag( $parent_id );

		$this->assertSame( 'no', get_post_meta( $parent_id, ProductFields::META_HAS_WHOLESALE_PRICE, true ) );
	}

	public function test_variation_price_cache_hash_differs_between_retail_and_wholesale_users(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$retail_id   = Protech_Test_Factory::retail_customer();
		$built       = Protech_Test_Factory::variable_product( array( 'blue' ), array( 'blue' => '5.50' ) );
		$pricing     = new Pricing();

		wp_set_current_user( $retail_id );
		$retail_hash = $pricing->bust_variation_price_cache_per_role( array(), $built['parent'], true );

		wp_set_current_user( $customer_id );
		$wholesale_hash = $pricing->bust_variation_price_cache_per_role( array(), $built['parent'], true );

		$this->assertSame( array( 'retail' ), $retail_hash );
		$this->assertNotSame( $retail_hash, $wholesale_hash );
		$this->assertStringStartsWith( 'wholesale:' . $customer_id . ':', $wholesale_hash[0] );
	}
}
