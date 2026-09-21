<?php
/**
 * Covers Pricing::get_wholesale_price()'s precedence rule (per-customer
 * override > group price > not available) and the "guests/retail customers
 * never see a wholesale price" requirement.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\Pricing;
use ProtechWholesale\ProductFields;
use ProtechWholesale\Roles;

/**
 * Class Test_Pricing
 */
class Test_Pricing extends WP_UnitTestCase {

	/**
	 * Creates a bare simple product with no wholesale meta.
	 *
	 * Prefers WooCommerce's own WC_Helper_Product::create_simple_product()
	 * (available when the WooCommerce install under test ships its
	 * tests/legacy/unit-tests/helpers/ directory), since it sets up a
	 * realistic product (stock, tax class, etc.) the way WooCommerce's own
	 * suite does. Falls back to a minimal hand-built WC_Product_Simple when
	 * that helper isn't present, e.g. on a plain release zip.
	 */
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

	private function create_retail_customer(): int {
		return self::factory()->user->create( array( 'role' => 'customer' ) );
	}

	public function test_no_group_price_and_no_override_is_not_available_at_wholesale(): void {
		$product     = $this->create_product();
		$customer_id = $this->create_wholesale_customer();

		$this->assertNull( Pricing::get_wholesale_price( $product->get_id(), $customer_id ) );
		$this->assertFalse( Pricing::is_available_at_wholesale( $product->get_id(), $customer_id ) );
	}

	public function test_group_price_applies_to_wholesale_customers_only(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE, '5.00' );

		$wholesale_id = $this->create_wholesale_customer();
		$retail_id    = $this->create_retail_customer();

		$this->assertSame( 5.0, Pricing::get_wholesale_price( $product->get_id(), $wholesale_id ) );

		// Guests never see wholesale prices, regardless of the group price.
		$this->assertNull( Pricing::get_wholesale_price( $product->get_id(), 0 ) );

		// Neither do plain retail-role customers.
		$this->assertNull( Pricing::get_wholesale_price( $product->get_id(), $retail_id ) );
	}

	public function test_per_customer_override_beats_group_price(): void {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE, '5.00' );

		$customer_id = $this->create_wholesale_customer();
		update_user_meta(
			$customer_id,
			Approval::META_PRICE_OVERRIDES,
			array( $product->get_id() => 3.5 )
		);

		$this->assertSame( 3.5, Pricing::get_wholesale_price( $product->get_id(), $customer_id ) );
	}

	/**
	 * get_wholesale_price() reads the override map before it ever looks at
	 * the group price meta, so an override with no group price set at all
	 * still applies — it doesn't require a group price to "override".
	 */
	public function test_per_customer_override_applies_even_without_a_group_price(): void {
		$product     = $this->create_product();
		$customer_id = $this->create_wholesale_customer();

		update_user_meta(
			$customer_id,
			Approval::META_PRICE_OVERRIDES,
			array( $product->get_id() => 4.25 )
		);

		$this->assertSame( 4.25, Pricing::get_wholesale_price( $product->get_id(), $customer_id ) );
	}

	public function test_guest_and_retail_role_are_never_wholesale_customers(): void {
		$retail_id = $this->create_retail_customer();

		$this->assertFalse( Roles::is_wholesale_customer( 0 ) );
		$this->assertFalse( Roles::is_wholesale_customer( $retail_id ) );
	}

	/**
	 * get_msrp() reads the raw postmeta directly rather than
	 * $product->get_regular_price(), so it stays the true retail price
	 * even while filter_price() is actively substituting the wholesale
	 * price for a logged-in wholesale customer viewing the same product.
	 */
	public function test_msrp_is_unaffected_by_the_wholesale_price_substitution(): void {
		$product = $this->create_product(); // regular_price 20.00.
		update_post_meta( $product->get_id(), ProductFields::META_WHOLESALE_PRICE, '5.00' );

		$this->assertSame( 20.0, Pricing::get_msrp( $product->get_id() ) );

		wp_set_current_user( $this->create_wholesale_customer() );
		// get_regular_price() returns a formatted string, hence the cast.
		$this->assertSame( 5.0, (float) wc_get_product( $product->get_id() )->get_regular_price() );
		$this->assertSame( 20.0, Pricing::get_msrp( $product->get_id() ) );
	}

	public function test_msrp_is_null_without_a_regular_price(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'No MSRP' );
		$product->set_status( 'publish' );
		$product->save();

		$this->assertNull( Pricing::get_msrp( $product->get_id() ) );
	}

	public function test_price_html_crosses_out_the_msrp_for_a_wholesale_customer(): void {
		$product     = Protech_Test_Factory::simple_product( '5.50' ); // regular_price 20.00.
		$customer_id = $this->create_wholesale_customer();
		wp_set_current_user( $customer_id );

		$pricing = new Pricing();
		$pricing->register_hooks();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringContainsString( '<del', $html );
		$this->assertStringContainsString( '20.00', $html );
		$this->assertStringContainsString( '<ins', $html );
		$this->assertStringContainsString( '5.50', $html );
		$this->assertStringContainsString( 'Save 73%', $html ); // 1 - 5.50 / 20.00.
		$this->assertStringContainsString( 'Wholesale price', $html );

		wp_set_current_user( 0 );
	}

	public function test_price_html_for_a_variable_product_uses_the_variations_msrp(): void {
		$built       = Protech_Test_Factory::variable_product( array( 'blue', 'red' ), array( 'blue' => '5.00', 'red' => '5.00' ) ); // regular_price 9.99 each.
		$customer_id = $this->create_wholesale_customer();

		$this->assertSame( array( 'min' => 9.99, 'max' => 9.99 ), Pricing::get_msrp_range( $built['parent'], $customer_id ) );

		wp_set_current_user( $customer_id );

		$pricing = new Pricing();
		$pricing->register_hooks();

		$html = wc_get_product( $built['parent']->get_id() )->get_price_html();

		$this->assertStringContainsString( '<del', $html );
		$this->assertStringContainsString( '9.99', $html );
		$this->assertStringContainsString( 'Save 50%', $html );

		wp_set_current_user( 0 );
	}

	public function test_price_html_is_untouched_for_a_retail_customer(): void {
		$product = Protech_Test_Factory::simple_product( '5.50' );
		wp_set_current_user( $this->create_retail_customer() );

		$pricing = new Pricing();
		$pricing->register_hooks();

		$html = wc_get_product( $product->get_id() )->get_price_html();

		$this->assertStringNotContainsString( '<del', $html );
		$this->assertStringContainsString( '20.00', $html );

		wp_set_current_user( 0 );
	}
}
