<?php
/**
 * Covers Reorder::build_prefill_payload(): the happy-path pack->case
 * conversion, the "order belongs to someone else" WP_Error, and one of its
 * three skip-with-a-note conditions (product deleted since the order was
 * placed). The other two skip conditions (out of stock / no longer
 * wholesale-priced) follow the exact same pattern — set up the product to
 * fail that one check instead of deleting it — and are not duplicated here.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\ProductFields;
use ProtechWholesale\Reorder;
use ProtechWholesale\Roles;

/**
 * Class Test_Reorder
 */
class Test_Reorder extends WP_UnitTestCase {

	private function create_wholesale_product( int $case_size = 5, string $wholesale_price = '2.00' ): WC_Product_Simple {
		if ( class_exists( 'WC_Helper_Product' ) ) {
			$product = WC_Helper_Product::create_simple_product();
		} else {
			$product = new WC_Product_Simple();
			$product->set_name( 'Protech Test Product' );
			$product->set_regular_price( '20.00' );
			$product->set_status( 'publish' );
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
			$product->save();
		}

		update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, $case_size );
		update_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE, $wholesale_price );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Builds a real WC_Order with one line item, using WC_Helper_Order when
	 * the WooCommerce test helpers are available, otherwise wc_create_order()
	 * + add_product() directly.
	 */
	private function create_order_for( int $customer_id, WC_Product $product, int $quantity ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->add_product( $product, $quantity );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	public function test_happy_path_converts_packs_to_cases(): void {
		$customer_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
		$product     = $this->create_wholesale_product( 5, '2.00' );
		$order       = $this->create_order_for( $customer_id, $product, 15 ); // 3 cases of 5.

		$payload = Reorder::build_prefill_payload( $order->get_id(), $customer_id );

		$this->assertIsArray( $payload );
		$this->assertSame( array( $product->get_id() => 3 ), $payload['cases'] );
		$this->assertSame( array(), $payload['notes'] );
	}

	public function test_order_belonging_to_another_customer_is_forbidden_for_non_admins(): void {
		$owner_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
		$other_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
		$product  = $this->create_wholesale_product();
		$order    = $this->create_order_for( $owner_id, $product, 5 );

		// A logged-out/non-admin actor requesting someone else's order.
		wp_set_current_user( 0 );

		$result = Reorder::build_prefill_payload( $order->get_id(), $other_id );

		$this->assertWPError( $result );
		$this->assertSame( 'protech_reorder_forbidden', $result->get_error_code() );
	}

	public function test_order_not_found_returns_wp_error(): void {
		$customer_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );

		$result = Reorder::build_prefill_payload( 999999999, $customer_id );

		$this->assertWPError( $result );
		$this->assertSame( 'protech_reorder_not_found', $result->get_error_code() );
	}

	public function test_deleted_product_is_skipped_with_a_note(): void {
		$customer_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
		$product     = $this->create_wholesale_product( 5, '2.00' );
		$product_id  = $product->get_id();
		$order       = $this->create_order_for( $customer_id, $product, 15 );

		wp_delete_post( $product_id, true );

		$payload = Reorder::build_prefill_payload( $order->get_id(), $customer_id );

		$this->assertIsArray( $payload );
		$this->assertSame( array(), $payload['cases'] );
		$this->assertCount( 1, $payload['notes'] );
	}
}
