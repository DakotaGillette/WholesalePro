<?php
/**
 * Shared builders for the test suite. WooCommerce's own WC_Helper_* classes
 * aren't shipped in release zips (which is what wp-env installs), so these
 * build the same things by hand with the plain WC_Product API.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\ProductFields;
use ProtechWholesale\Roles;

/**
 * Class Protech_Test_Factory
 */
class Protech_Test_Factory {

	/**
	 * A published, in-stock simple product; optionally wholesale-priced.
	 */
	public static function simple_product( ?string $wholesale_price = null, ?int $case_size = null ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Protech Test Product' );
		$product->set_regular_price( '20.00' );
		$product->set_status( 'publish' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->save();

		if ( null !== $wholesale_price ) {
			update_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE, $wholesale_price );
		}

		if ( null !== $case_size ) {
			update_post_meta( $product->get_id(), ProductFields::META_CASE_SIZE, $case_size );
		}

		ProductFields::sync_has_wholesale_price_flag( $product->get_id() );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * A published variable product with one variation per colour, each at a
	 * $9.99 retail price and no wholesale price unless $wholesale_prices
	 * (colour => price) says otherwise.
	 *
	 * @param string[]              $colors
	 * @param array<string, string> $wholesale_prices
	 * @return array{parent: WC_Product_Variable, variations: array<string, WC_Product_Variation>}
	 */
	public static function variable_product( array $colors = array( 'blue', 'red' ), array $wholesale_prices = array() ): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'color' );
		$attribute->set_options( $colors );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Protech Test Sleeves' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variations = array();

		foreach ( $colors as $color ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'color' => $color ) );
			$variation->set_regular_price( '9.99' );
			$variation->set_status( 'publish' );
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
			$variation->save();

			if ( isset( $wholesale_prices[ $color ] ) ) {
				update_post_meta( $variation->get_id(), ProductFields::META_WHOLESALE_PRICE, $wholesale_prices[ $color ] );
			}

			$variations[ $color ] = wc_get_product( $variation->get_id() );
		}

		ProductFields::sync_has_wholesale_price_flag( $parent->get_id() );

		return array(
			'parent'     => wc_get_product( $parent->get_id() ),
			'variations' => $variations,
		);
	}

	/**
	 * WP_UnitTestCase::factory() is protected, so a helper outside the test
	 * case can't call it; the underlying factory class is public.
	 */
	private static function factory(): WP_UnitTest_Factory {
		static $factory = null;

		if ( null === $factory ) {
			$factory = new WP_UnitTest_Factory();
		}

		return $factory;
	}

	public static function wholesale_customer(): int {
		return self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
	}

	public static function retail_customer(): int {
		return self::factory()->user->create( array( 'role' => 'customer' ) );
	}

	/**
	 * A real WC_Order with one line item.
	 */
	public static function order_for( int $customer_id, WC_Product $product, int $quantity, string $status = 'completed' ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->add_product( $product, $quantity );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * Cart-item shaped rows (what WC_Cart::get_cart() and a shipping
	 * package's 'contents' share), for the VolumePricing/shipping math.
	 *
	 * @param array<int, array{0: WC_Product, 1: int}> $lines [product, quantity] pairs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function cart_items( array $lines ): array {
		$items = array();

		foreach ( $lines as $i => [ $product, $quantity ] ) {
			$is_variation = $product->is_type( 'variation' );

			$items[ 'item' . $i ] = array(
				'product_id'   => $is_variation ? $product->get_parent_id() : $product->get_id(),
				'variation_id' => $is_variation ? $product->get_id() : 0,
				'quantity'     => $quantity,
				'data'         => $product,
			);
		}

		return $items;
	}
}
