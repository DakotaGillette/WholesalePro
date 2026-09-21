<?php
/**
 * The product-page price table: one row per quantity tier with this
 * customer's actual price, a range for a variable product whose colors
 * are priced differently, and nothing for an unpriced product.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\Settings;
use ProtechWholesale\TierLadder;
use ProtechWholesale\VolumePricing;

/**
 * Class Test_Tier_Ladder
 */
class Test_Tier_Ladder extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '8' );
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '16' );
		update_option( Settings::OPT_BULK_THRESHOLD_CASES, '16' );
		update_option( Settings::OPT_VOLUME_PRICE, '5.00' );
		update_option( Settings::OPT_BULK_PRICE, '4.50' );
	}

	public function test_simple_product_rows(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' );

		$rows = TierLadder::get_rows( $product, $customer_id );

		$this->assertCount( 3, $rows );
		$this->assertSame( array( VolumePricing::TIER_STANDARD, VolumePricing::TIER_VOLUME, VolumePricing::TIER_BULK ), array_column( $rows, 'tier' ) );
		$this->assertSame( array( 5.5, 5.0, 4.5 ), array_column( $rows, 'min' ) );
		// The base tier has no name, only its range; the named tiers follow.
		$this->assertSame( array( '', 'Standard', 'Volume' ), array_column( $rows, 'label' ) );
		$this->assertSame( 'Under 16 displays', $rows[0]['threshold'] );
		$this->assertSame( '16+ displays', $rows[1]['threshold'] );
		$this->assertSame( '16+ cases (128 displays)', $rows[2]['threshold'] );
		$this->assertSame( 'Free shipping', $rows[1]['note'] );
	}

	public function test_variable_product_shows_a_range_when_colors_differ(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$built       = Protech_Test_Factory::variable_product( array( 'blue', 'red', 'green' ), array( 'blue' => '5.50', 'red' => '6.00' ) );

		$rows = TierLadder::get_rows( $built['parent'], $customer_id );

		$this->assertCount( 3, $rows );
		$this->assertSame( 5.5, $rows[0]['min'] );
		$this->assertSame( 6.0, $rows[0]['max'] );
		// Volume/Bulk come from the store defaults, identical for every color.
		$this->assertSame( 5.0, $rows[1]['min'] );
		$this->assertSame( 5.0, $rows[1]['max'] );
	}

	public function test_per_customer_override_flattens_every_tier(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' );
		update_user_meta( $customer_id, Approval::META_PRICE_OVERRIDES, array( $product->get_id() => 3.0 ) );

		$rows = TierLadder::get_rows( $product, $customer_id );

		$this->assertSame( array( 3.0, 3.0, 3.0 ), array_column( $rows, 'min' ) );
	}

	public function test_unpriced_product_has_no_rows(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();

		$this->assertSame( array(), TierLadder::get_rows( Protech_Test_Factory::simple_product(), $customer_id ) );
	}

	public function test_rows_report_msrp_alongside_the_wholesale_price(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' ); // regular_price 20.00, from the factory.

		$rows = TierLadder::get_rows( $product, $customer_id );

		$this->assertSame( array( 20.0, 20.0, 20.0 ), array_column( $rows, 'msrp' ) );
		$this->assertStringContainsString( '20.00', $rows[0]['msrp_html'] );
	}

	public function test_msrp_is_null_without_a_regular_price(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' );
		delete_post_meta( $product->get_id(), '_regular_price' );

		$rows = TierLadder::get_rows( $product, $customer_id );

		$this->assertNull( $rows[0]['msrp'] );
		$this->assertSame( '', $rows[0]['msrp_html'] );
	}
}
