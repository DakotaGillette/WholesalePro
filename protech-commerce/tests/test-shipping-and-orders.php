<?php
/**
 * The wholesale shipping method (flat rate below the Volume threshold,
 * free at/above, never offered to retail) and the order-level wholesale
 * stamp that reporting and the "WHOLESALE ORDER" email subject depend on —
 * including the Store API (Blocks checkout) path, which never fires the
 * classic checkout hook.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\CaseRules;
use ProtechWholesale\Emails;
use ProtechWholesale\OrdersAdmin;
use ProtechWholesale\Roles;
use ProtechWholesale\Settings;
use ProtechWholesale\WholesaleShippingMethod;

/**
 * Class Test_Shipping_And_Orders
 */
class Test_Shipping_And_Orders extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPT_DEFAULT_CASE_SIZE, '10' );
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '16' );
		update_option( Settings::OPT_SHIPPING_FLAT_RATE, '19.95' );
	}

	public function test_method_is_never_available_to_retail(): void {
		wp_set_current_user( Protech_Test_Factory::retail_customer() );

		$this->assertFalse( ( new WholesaleShippingMethod() )->is_available( array( 'contents' => array() ) ) );
	}

	/**
	 * The destination WC_Shipping_Method::is_available() reads from a
	 * package; a real package always carries one.
	 *
	 * @return array<string, string>
	 */
	private static function destination(): array {
		return array(
			'country'   => 'US',
			'state'     => 'CA',
			'postcode'  => '90210',
			'city'      => 'Beverly Hills',
			'address'   => '',
			'address_1' => '',
			'address_2' => '',
		);
	}

	public function test_method_is_unavailable_when_the_cart_holds_nothing_wholesale_eligible(): void {
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		$retail_only = Protech_Test_Factory::simple_product(); // No wholesale price.
		$priced      = Protech_Test_Factory::simple_product( '5.50' );

		$method = new WholesaleShippingMethod();

		$this->assertFalse( $method->is_available( array( 'destination' => self::destination(), 'contents' => Protech_Test_Factory::cart_items( array( array( $retail_only, 10 ) ) ) ) ) );
		$this->assertTrue( $method->is_available( array( 'destination' => self::destination(), 'contents' => Protech_Test_Factory::cart_items( array( array( $priced, 10 ) ) ) ) ) );
	}

	public function test_flat_rate_below_threshold_and_free_at_threshold(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' ); // 10 packs/display.
		wp_set_current_user( $customer_id );

		$method = new WholesaleShippingMethod();
		$method->calculate_shipping( array( 'destination' => self::destination(), 'contents' => Protech_Test_Factory::cart_items( array( array( $product, 150 ) ) ) ) ); // 15 displays.
		$this->assertCount( 1, $method->rates );
		$this->assertSame( 19.95, (float) reset( $method->rates )->get_cost() );

		$method = new WholesaleShippingMethod();
		$method->calculate_shipping( array( 'destination' => self::destination(), 'contents' => Protech_Test_Factory::cart_items( array( array( $product, 160 ) ) ) ) ); // 16 displays.
		$this->assertSame( 0.0, (float) reset( $method->rates )->get_cost() );
	}

	public function test_retail_rates_are_hidden_only_when_the_wholesale_rate_is_present(): void {
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		$rules = new CaseRules();

		$flat      = new WC_Shipping_Rate( 'flat_rate:1', 'Flat rate', 5, array(), 'flat_rate', 1 );
		$free      = new WC_Shipping_Rate( 'free_shipping:2', 'Free shipping', 0, array(), 'free_shipping', 2 );
		$wholesale = new WC_Shipping_Rate( WholesaleShippingMethod::METHOD_ID . ':3', 'Wholesale', 19.95, array(), WholesaleShippingMethod::METHOD_ID, 3 );

		$without = $rules->hide_retail_shipping_for_wholesale( array( 'flat_rate:1' => $flat, 'free_shipping:2' => $free ), array() );
		$this->assertCount( 2, $without, 'Zone without the wholesale method: retail rates stay as the fallback.' );

		$with = $rules->hide_retail_shipping_for_wholesale( array( 'flat_rate:1' => $flat, 'free_shipping:2' => $free, 'w' => $wholesale ), array() );
		$this->assertSame( array( 'w' ), array_keys( $with ) );
	}

	public function test_wholesale_flag_is_stamped_for_a_wholesale_customer_order(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$order       = wc_create_order( array( 'customer_id' => $customer_id ) );

		( new OrdersAdmin() )->stamp_wholesale_flag( $order ); // Store API signature: no $data.

		$this->assertSame( 'yes', $order->get_meta( OrdersAdmin::META_IS_WHOLESALE ) );
	}

	public function test_wholesale_flag_falls_back_to_the_logged_in_user_when_the_order_has_no_customer_yet(): void {
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );
		$order = wc_create_order();

		( new OrdersAdmin() )->stamp_wholesale_flag( $order, array() );

		$this->assertSame( 'yes', $order->get_meta( OrdersAdmin::META_IS_WHOLESALE ) );
	}

	public function test_retail_order_is_stamped_no(): void {
		$order = wc_create_order( array( 'customer_id' => Protech_Test_Factory::retail_customer() ) );

		( new OrdersAdmin() )->stamp_wholesale_flag( $order );

		$this->assertSame( 'no', $order->get_meta( OrdersAdmin::META_IS_WHOLESALE ) );
	}

	public function test_email_subject_prefix_uses_the_flag_then_falls_back_to_role(): void {
		$emails = new Emails();

		$flagged = wc_create_order( array( 'customer_id' => Protech_Test_Factory::retail_customer() ) );
		$flagged->update_meta_data( OrdersAdmin::META_IS_WHOLESALE, 'yes' );
		$this->assertStringStartsWith( 'WHOLESALE ORDER', $emails->flag_wholesale_order_subject( 'New order', $flagged ) );

		$unflagged_wholesale = wc_create_order( array( 'customer_id' => Protech_Test_Factory::wholesale_customer() ) );
		$this->assertStringStartsWith( 'WHOLESALE ORDER', $emails->flag_wholesale_order_subject( 'New order', $unflagged_wholesale ) );

		$retail = wc_create_order( array( 'customer_id' => Protech_Test_Factory::retail_customer() ) );
		$retail->update_meta_data( OrdersAdmin::META_IS_WHOLESALE, 'no' );
		$this->assertSame( 'New order', $emails->flag_wholesale_order_subject( 'New order', $retail ) );
	}

	public function test_case_size_falls_back_from_variation_to_parent(): void {
		$built = Protech_Test_Factory::variable_product( array( 'blue' ) );
		$vid   = $built['variations']['blue']->get_id();

		$this->assertSame( 10, CaseRules::get_case_size( $vid ) );

		update_post_meta( $built['parent']->get_id(), '_protech_case_size', 12 );
		$this->assertSame( 12, CaseRules::get_case_size( $vid ) );

		update_post_meta( $vid, '_protech_case_size', 6 );
		$this->assertSame( 6, CaseRules::get_case_size( $vid ) );
	}
}
