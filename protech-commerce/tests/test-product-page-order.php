<?php
/**
 * The order of a product page's summary for a wholesale customer: the short
 * description (size, count, finish) sits below the add-to-cart button, and
 * everyone else keeps WooCommerce's standard order.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Plugin;

/**
 * Class Test_Product_Page_Order
 */
class Test_Product_Page_Order extends WP_UnitTestCase {

	private function visit_product_page(): void {
		// WooCommerce's own default, in case the suite loaded without its template hooks.
		if ( false === has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' ) ) {
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
		}

		$product = Protech_Test_Factory::simple_product( '5.50' );

		$this->go_to( get_permalink( $product->get_id() ) );
	}

	public function test_a_wholesale_customer_gets_the_short_description_below_add_to_cart(): void {
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		$this->visit_product_page();

		$this->assertTrue( is_product() );

		// Visiting the page fires the `wp` action the plugin hooks, so by now the
		// move has happened: after add to cart (30), before category/brand (40).
		$this->assertSame( 35, has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' ) );

		// Running it again must not print the description twice.
		Plugin::instance()->move_short_description_below_add_to_cart();
		$this->assertSame( 35, has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' ) );
	}

	public function test_everyone_else_keeps_the_standard_order(): void {
		wp_set_current_user( Protech_Test_Factory::retail_customer() );
		$this->visit_product_page();

		Plugin::instance()->move_short_description_below_add_to_cart();

		$this->assertSame( 20, has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' ) );

		wp_set_current_user( 0 );
		Plugin::instance()->move_short_description_below_add_to_cart();

		$this->assertSame( 20, has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' ) );
	}
}
