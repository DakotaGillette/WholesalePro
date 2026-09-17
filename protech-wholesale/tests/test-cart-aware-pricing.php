<?php
/**
 * End-to-end: the price a product object reports (through WooCommerce's
 * own price filters) follows the quantity tier the current cart has
 * reached, and the per-request memoization is invalidated by cart changes.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Settings;

/**
 * Class Test_Cart_Aware_Pricing
 */
class Test_Cart_Aware_Pricing extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPT_DEFAULT_CASE_SIZE, '10' );
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '8' );
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '16' );
		update_option( Settings::OPT_BULK_THRESHOLD_CASES, '16' );
		update_option( Settings::OPT_VOLUME_PRICE, '5.00' );
		update_option( Settings::OPT_BULK_PRICE, '4.50' );
		WC()->cart->empty_cart();
	}

	public function test_price_follows_the_cart_tier_and_flushes_on_cart_changes(): void {
		$product = Protech_Test_Factory::simple_product( '5.50' );
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		$this->assertSame( 5.5, (float) wc_get_product( $product->get_id() )->get_price() );

		$key = WC()->cart->add_to_cart( $product->get_id(), 160 ); // 16 displays: Volume.
		$this->assertNotFalse( $key );
		$this->assertSame( 5.0, (float) wc_get_product( $product->get_id() )->get_price() );

		WC()->cart->set_quantity( $key, 1280 ); // 128 displays = 16 cases: Bulk.
		$this->assertSame( 4.5, (float) wc_get_product( $product->get_id() )->get_price() );

		WC()->cart->empty_cart();
		$this->assertSame( 5.5, (float) wc_get_product( $product->get_id() )->get_price() );
	}

	public function test_retail_customers_never_see_wholesale_prices_whatever_the_cart_holds(): void {
		$product = Protech_Test_Factory::simple_product( '5.50' );
		wp_set_current_user( Protech_Test_Factory::retail_customer() );

		WC()->cart->add_to_cart( $product->get_id(), 160 );

		$this->assertSame( 20.0, (float) wc_get_product( $product->get_id() )->get_price() );
	}

	public function test_switching_user_mid_request_is_not_served_a_stale_role(): void {
		$product      = Protech_Test_Factory::simple_product( '5.50' );
		$wholesale_id = Protech_Test_Factory::wholesale_customer();
		$retail_id    = Protech_Test_Factory::retail_customer();

		wp_set_current_user( $wholesale_id );
		$this->assertSame( 5.5, (float) wc_get_product( $product->get_id() )->get_price() );

		wp_set_current_user( $retail_id );
		$this->assertSame( 20.0, (float) wc_get_product( $product->get_id() )->get_price() );
	}
}
