<?php
/**
 * Covers Reorder::add_order_items_to_cart(): the happy path (original pack
 * quantity lands in the cart), the "order belongs to someone else"
 * WP_Error, order-not-found, and each skip-with-a-note condition (product
 * deleted, out of stock, no longer wholesale-priced).
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\ProductFields;
use ProtechWholesale\Reorder;

/**
 * Class Test_Reorder
 */
class Test_Reorder extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->cart->empty_cart();
	}

	public function test_happy_path_adds_original_pack_quantity_to_cart(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '2.00', 5 );
		$order       = Protech_Test_Factory::order_for( $customer_id, $product, 15 );

		wp_set_current_user( $customer_id );

		$result = Reorder::add_order_items_to_cart( $order->get_id(), $customer_id );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( array(), $result['notes'] );

		$cart = WC()->cart->get_cart();
		$this->assertCount( 1, $cart );
		$this->assertSame( 15, (int) reset( $cart )['quantity'] );
	}

	public function test_variation_line_is_added_with_its_attributes(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$built       = Protech_Test_Factory::variable_product( array( 'blue' ), array( 'blue' => '5.00' ) );
		$variation   = $built['variations']['blue'];
		$order       = Protech_Test_Factory::order_for( $customer_id, $variation, 10 );

		wp_set_current_user( $customer_id );

		$result = Reorder::add_order_items_to_cart( $order->get_id(), $customer_id );

		$this->assertSame( 1, $result['added'] );

		$line = reset( WC()->cart->get_cart() );
		$this->assertSame( $variation->get_id(), (int) $line['variation_id'] );
		// WC_Cart::add_to_cart() fills the attributes in from the variation itself.
		$this->assertSame( 'blue', $line['variation']['attribute_color'] ?? null );
	}

	public function test_order_belonging_to_another_customer_is_forbidden_for_non_admins(): void {
		$owner_id = Protech_Test_Factory::wholesale_customer();
		$other_id = Protech_Test_Factory::wholesale_customer();
		$product  = Protech_Test_Factory::simple_product( '2.00', 5 );
		$order    = Protech_Test_Factory::order_for( $owner_id, $product, 5 );

		wp_set_current_user( $other_id );

		$result = Reorder::add_order_items_to_cart( $order->get_id(), $other_id );

		$this->assertWPError( $result );
		$this->assertSame( 'protech_reorder_forbidden', $result->get_error_code() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_order_not_found_returns_wp_error(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();

		$result = Reorder::add_order_items_to_cart( 999999999, $customer_id );

		$this->assertWPError( $result );
		$this->assertSame( 'protech_reorder_not_found', $result->get_error_code() );
	}

	public function test_deleted_product_is_skipped_with_a_note(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '2.00', 5 );
		$order       = Protech_Test_Factory::order_for( $customer_id, $product, 15 );

		wp_delete_post( $product->get_id(), true );
		wp_set_current_user( $customer_id );

		$result = Reorder::add_order_items_to_cart( $order->get_id(), $customer_id );

		$this->assertSame( 0, $result['added'] );
		$this->assertCount( 1, $result['notes'] );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_out_of_stock_product_is_skipped_with_a_note(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '2.00', 5 );
		$order       = Protech_Test_Factory::order_for( $customer_id, $product, 15 );

		$product->set_stock_status( 'outofstock' );
		$product->save();
		wp_set_current_user( $customer_id );

		$result = Reorder::add_order_items_to_cart( $order->get_id(), $customer_id );

		$this->assertSame( 0, $result['added'] );
		$this->assertCount( 1, $result['notes'] );
		$this->assertStringContainsString( 'out of stock', $result['notes'][0] );
	}

	public function test_product_no_longer_wholesale_priced_is_skipped_with_a_note(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '2.00', 5 );
		$order       = Protech_Test_Factory::order_for( $customer_id, $product, 15 );

		delete_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE );
		wp_set_current_user( $customer_id );

		$result = Reorder::add_order_items_to_cart( $order->get_id(), $customer_id );

		$this->assertSame( 0, $result['added'] );
		$this->assertCount( 1, $result['notes'] );
		$this->assertStringContainsString( 'no longer available at wholesale', $result['notes'][0] );
	}
}
