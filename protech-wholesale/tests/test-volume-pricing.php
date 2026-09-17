<?php
/**
 * Covers the Standard/Volume/Bulk quantity ladder: tier thresholds,
 * combined-cart Display/Case totals, per-product tier price overrides, and
 * how the ladder composes with per-customer overrides and the hidden
 * customer-tier discount inside Pricing::get_wholesale_price().
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\Pricing;
use ProtechWholesale\ProductFields;
use ProtechWholesale\Settings;
use ProtechWholesale\Tiers;
use ProtechWholesale\VolumePricing;

/**
 * Class Test_Volume_Pricing
 */
class Test_Volume_Pricing extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Pin the store defaults the assertions below rely on.
		update_option( Settings::OPT_DEFAULT_CASE_SIZE, '10' );
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '8' );
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '16' );
		update_option( Settings::OPT_BULK_THRESHOLD_CASES, '16' );
		update_option( Settings::OPT_VOLUME_PRICE, '5.00' );
		update_option( Settings::OPT_BULK_PRICE, '4.50' );
	}

	public function test_tier_thresholds(): void {
		$this->assertSame( VolumePricing::TIER_STANDARD, VolumePricing::get_tier_for_totals( 0.0, 0.0 ) );
		$this->assertSame( VolumePricing::TIER_STANDARD, VolumePricing::get_tier_for_totals( 15.9, 1.9 ) );
		$this->assertSame( VolumePricing::TIER_VOLUME, VolumePricing::get_tier_for_totals( 16.0, 2.0 ) );
		$this->assertSame( VolumePricing::TIER_VOLUME, VolumePricing::get_tier_for_totals( 127.0, 15.9 ) );
		$this->assertSame( VolumePricing::TIER_BULK, VolumePricing::get_tier_for_totals( 128.0, 16.0 ) );
	}

	public function test_totals_are_combined_across_wholesale_eligible_lines_only(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$a           = Protech_Test_Factory::simple_product( '5.50' );          // 10 packs/display (default).
		$b           = Protech_Test_Factory::simple_product( '5.50' );
		$retail_only = Protech_Test_Factory::simple_product( null );           // No wholesale price: ignored.

		$items = Protech_Test_Factory::cart_items(
			array(
				array( $a, 100 ),          // 10 displays, 1.25 cases.
				array( $b, 60 ),           // 6 displays, 0.75 cases.
				array( $retail_only, 999 ),
			)
		);

		$totals = VolumePricing::get_totals_for_items( $items, $customer_id );

		$this->assertSame( 16.0, $totals['displays'] );
		$this->assertSame( 2.0, $totals['cases'] );
		$this->assertSame( VolumePricing::TIER_VOLUME, VolumePricing::get_tier_for_totals( $totals['displays'], $totals['cases'] ) );
	}

	public function test_tier_price_uses_product_override_then_store_default(): void {
		$product = Protech_Test_Factory::simple_product( '5.50' );

		$this->assertSame( 5.5, VolumePricing::get_tier_price( $product->get_id(), VolumePricing::TIER_STANDARD ) );
		$this->assertSame( 5.0, VolumePricing::get_tier_price( $product->get_id(), VolumePricing::TIER_VOLUME ) );
		$this->assertSame( 4.5, VolumePricing::get_tier_price( $product->get_id(), VolumePricing::TIER_BULK ) );

		update_post_meta( $product->get_id(), ProductFields::META_VOLUME_PRICE, '4.75' );

		$this->assertSame( 4.75, VolumePricing::get_tier_price( $product->get_id(), VolumePricing::TIER_VOLUME ) );
		$this->assertSame( 4.5, VolumePricing::get_tier_price( $product->get_id(), VolumePricing::TIER_BULK ) );
	}

	public function test_get_wholesale_price_by_tier(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' );

		$this->assertSame( 5.5, Pricing::get_wholesale_price( $product->get_id(), $customer_id ) );
		$this->assertSame( 5.5, Pricing::get_wholesale_price( $product->get_id(), $customer_id, VolumePricing::TIER_STANDARD ) );
		$this->assertSame( 5.0, Pricing::get_wholesale_price( $product->get_id(), $customer_id, VolumePricing::TIER_VOLUME ) );
		$this->assertSame( 4.5, Pricing::get_wholesale_price( $product->get_id(), $customer_id, VolumePricing::TIER_BULK ) );
	}

	public function test_customer_tier_discount_stacks_on_the_quantity_tier_price(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' );

		update_option(
			Tiers::OPT_TIER_SETTINGS,
			array(
				Tiers::SILVER => array(
					'min_order'        => '',
					'discount_percent' => '20',
				),
			)
		);
		Tiers::set_user_tier( $customer_id, Tiers::SILVER );

		$this->assertSame( 4.4, Pricing::get_wholesale_price( $product->get_id(), $customer_id ) );
		$this->assertSame( 4.0, Pricing::get_wholesale_price( $product->get_id(), $customer_id, VolumePricing::TIER_VOLUME ) );
	}

	public function test_per_customer_override_ignores_tier_and_discount(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		$product     = Protech_Test_Factory::simple_product( '5.50' );

		update_user_meta( $customer_id, Approval::META_PRICE_OVERRIDES, array( $product->get_id() => 3.25 ) );
		Tiers::set_user_tier( $customer_id, Tiers::PLATINUM );

		$this->assertSame( 3.25, Pricing::get_wholesale_price( $product->get_id(), $customer_id, VolumePricing::TIER_BULK ) );
	}

	public function test_tier_bar_state_for_an_empty_cart(): void {
		$customer_id = Protech_Test_Factory::wholesale_customer();
		WC()->cart->empty_cart();

		$state = VolumePricing::get_tier_bar_state( $customer_id );

		$this->assertSame( VolumePricing::TIER_STANDARD, $state['tier'] );
		$this->assertSame( 0.0, $state['fill_percent'] );
		$this->assertSame( 16, $state['volume_threshold_displays'] );
		$this->assertSame( 16, $state['bulk_threshold_cases'] );
		// 16 displays out of a 128-display (16 cases x 8) track.
		$this->assertSame( 12.5, $state['volume_marker_percent'] );
		$this->assertStringContainsString( '16', $state['message'] );
	}
}
