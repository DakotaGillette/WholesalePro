<?php
/**
 * Covers CaseRules: case-size resolution/fallback, the "quantity must be a
 * multiple of the case size" check, the woocommerce_add_to_cart_validation
 * callback that enforces it for wholesale customers on wholesale-priced
 * products only, and the header cart badge count.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\CaseRules;
use ProtechWholesale\ProductFields;
use ProtechWholesale\Roles;

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

	public function test_validate_add_to_cart_blocks_non_multiple_quantity_for_wholesale_customer(): void {
		$product     = Protech_Test_Factory::simple_product( '5.50', 10 );
		$customer_id = $this->create_wholesale_customer();

		wp_set_current_user( $customer_id );

		$case_rules = new CaseRules();
		$result     = $case_rules->validate_add_to_cart( true, $product->get_id(), 25 );

		$this->assertFalse( $result );
	}

	public function test_display_rules_do_not_apply_to_a_product_with_no_wholesale_price(): void {
		// The Vendor Starter Kit case: an unlisted $800 one-off with no
		// wholesale price, bought on retail terms by everyone — including a
		// logged-in wholesale customer, one at a time.
		$sample_pack = $this->create_product();
		$customer_id = $this->create_wholesale_customer();

		wp_set_current_user( $customer_id );

		$this->assertFalse( CaseRules::sold_by_the_display( $sample_pack->get_id(), $customer_id ) );

		$case_rules = new CaseRules();
		$this->assertTrue( $case_rules->validate_add_to_cart( true, $sample_pack->get_id(), 1 ) );

		$args = $case_rules->set_quantity_step( array( 'step' => 1, 'min_value' => 1, 'input_value' => 1 ), $sample_pack );
		$this->assertSame( 1, $args['step'], 'The quantity box steps by one, not by the display size.' );
		$this->assertArrayNotHasKey( 'classes', $args, 'Nothing for the Display/Case control to take over.' );

		$item_data = $case_rules->add_case_count_to_item_data( array(), array( 'product_id' => $sample_pack->get_id(), 'variation_id' => 0, 'quantity' => 1 ) );
		$this->assertSame( array(), $item_data, 'No "Displays" annotation on a line not sold by the display.' );
	}

	public function test_quantity_box_of_a_variable_product_steps_by_the_display(): void {
		// The parent has no price of its own; its colors do. WooCommerce
		// hands the parent to woocommerce_quantity_input_args.
		$built       = Protech_Test_Factory::variable_product( array( 'blue', 'red' ), array( 'blue' => '5.50', 'red' => '5.50' ) );
		$customer_id = $this->create_wholesale_customer();

		wp_set_current_user( $customer_id );

		$this->assertTrue( CaseRules::sold_by_the_display( $built['parent']->get_id(), $customer_id ) );
		$this->assertTrue( CaseRules::sold_by_the_display( $built['variations']['blue']->get_id(), $customer_id ) );

		$args = ( new CaseRules() )->set_quantity_step( array( 'step' => 1, 'min_value' => 1, 'input_value' => 1 ), $built['parent'] );
		$this->assertSame( 10, $args['step'], 'The stepper jumps by the display size, so unit-selector.js can take it over.' );
		$this->assertContains( 'protech-native-qty', $args['classes'] );
	}

	public function test_display_rules_still_apply_to_a_wholesale_priced_product(): void {
		$product     = Protech_Test_Factory::simple_product( '5.50', 10 );
		$customer_id = $this->create_wholesale_customer();

		wp_set_current_user( $customer_id );

		$this->assertTrue( CaseRules::sold_by_the_display( $product->get_id(), $customer_id ) );

		$args = ( new CaseRules() )->set_quantity_step( array( 'step' => 1, 'min_value' => 1, 'input_value' => 1 ), $product );
		$this->assertSame( 10, $args['step'] );
		$this->assertSame( 10, $args['input_value'] );
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
		$product     = Protech_Test_Factory::simple_product( '5.50', 10 );
		$customer_id = $this->create_wholesale_customer();

		wp_set_current_user( $customer_id );

		$case_rules = new CaseRules();
		$result     = $case_rules->validate_add_to_cart( true, $product->get_id(), 30 );

		$this->assertTrue( $result );
	}

	public function test_cart_badge_counts_displays_for_a_wholesale_customer(): void {
		$ten_pack = Protech_Test_Factory::simple_product( '5.50', 10 );
		$six_pack = Protech_Test_Factory::simple_product( '5.50', 6 );

		wp_set_current_user( $this->create_wholesale_customer() );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $ten_pack->get_id(), 30 ); // 3 displays.
		WC()->cart->add_to_cart( $six_pack->get_id(), 12 ); // 2 displays.

		// 42 packs, but 5 displays — what the header badge should say.
		$this->assertSame( 5, WC()->cart->get_cart_contents_count() );

		// A sample pack (no wholesale price) counts as one item, not as a
		// fraction of a display.
		$sample_pack = $this->create_product();
		WC()->cart->add_to_cart( $sample_pack->get_id(), 1 );
		$this->assertSame( 6, WC()->cart->get_cart_contents_count() );

		WC()->cart->empty_cart();
	}

	public function test_cart_badge_still_counts_items_for_a_retail_customer(): void {
		$product = Protech_Test_Factory::simple_product( '5.50', 10 );

		wp_set_current_user( Protech_Test_Factory::retail_customer() );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 30 );

		$this->assertSame( 30, (int) WC()->cart->get_cart_contents_count() );

		WC()->cart->empty_cart();
	}

	public function test_cart_badge_in_displays_can_be_switched_off(): void {
		$product = Protech_Test_Factory::simple_product( '5.50', 10 );

		wp_set_current_user( $this->create_wholesale_customer() );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 30 );

		add_filter( 'protech_wholesale_cart_count_in_displays', '__return_false' );
		$this->assertSame( 30, (int) WC()->cart->get_cart_contents_count() );
		remove_filter( 'protech_wholesale_cart_count_in_displays', '__return_false' );

		WC()->cart->empty_cart();
	}

	public function test_the_quantity_legend_renders_on_its_own_and_not_inside_the_order_control(): void {
		global $product;

		$product = Protech_Test_Factory::simple_product( '5.50' ); // Store defaults: 10 packs/display, 8 displays/case, 16 displays for free shipping.
		wp_set_current_user( $this->create_wholesale_customer() );

		ob_start();
		( new CaseRules() )->render_quantity_legend();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'How wholesale quantities work', $html );
		$this->assertStringContainsString( '8 displays = 1 case.', $html );
		$this->assertStringContainsString( 'Free shipping from 16 displays (2 cases)', $html );

		// The order control no longer carries it.
		ob_start();
		( new CaseRules() )->render_unit_selector();
		$selector = (string) ob_get_clean();
		$this->assertStringContainsString( 'protech-unit-selector', $selector );
		$this->assertStringNotContainsString( 'How wholesale quantities work', $selector );

		wp_set_current_user( 0 );
		$product = null;
	}
}
