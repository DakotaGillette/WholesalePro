<?php
/**
 * TaxExemption: reads and writes the tax-exempt status the "Stripe Tax for
 * WooCommerce" plugin already stores on `tax_exemption` user meta ('none' |
 * 'customer_exempt' | 'reverse_charge') — same key that plugin's own real
 * tax calculation reads, so the Customers-tab dropdown is a second place
 * to set the exact same value, not a separate mechanism.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\TaxExemption;

/**
 * Class Test_Tax_Exemption
 */
class Test_Tax_Exemption extends WP_UnitTestCase {

	public function test_status_defaults_to_taxable_when_no_meta_is_set(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$this->assertSame( TaxExemption::STATUS_TAXABLE, TaxExemption::status( $user_id ) );
		$this->assertFalse( TaxExemption::is_exempt( $user_id ) );
	}

	public function test_status_reflects_an_existing_meta_value(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, TaxExemption::META_KEY, 'customer_exempt' );

		$this->assertSame( TaxExemption::STATUS_EXEMPT, TaxExemption::status( $user_id ) );
		$this->assertTrue( TaxExemption::is_exempt( $user_id ) );
	}

	public function test_reverse_charge_counts_as_exempt(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, TaxExemption::META_KEY, 'reverse_charge' );

		$this->assertTrue( TaxExemption::is_exempt( $user_id ) );
	}

	public function test_an_unrecognised_meta_value_falls_back_to_taxable(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, TaxExemption::META_KEY, 'garbage' );

		$this->assertSame( TaxExemption::STATUS_TAXABLE, TaxExemption::status( $user_id ) );
		$this->assertFalse( TaxExemption::is_exempt( $user_id ) );
	}

	public function test_set_status_writes_the_same_meta_key_the_stripe_plugin_reads(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		TaxExemption::set_status( $user_id, TaxExemption::STATUS_EXEMPT );

		$this->assertSame( 'customer_exempt', get_user_meta( $user_id, 'tax_exemption', true ) );
		$this->assertTrue( TaxExemption::is_exempt( $user_id ) );
	}

	public function test_set_status_rejects_an_unknown_value(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, TaxExemption::META_KEY, TaxExemption::STATUS_EXEMPT );

		TaxExemption::set_status( $user_id, 'not_a_real_status' );

		$this->assertSame( TaxExemption::STATUS_EXEMPT, TaxExemption::status( $user_id ), 'An invalid value must not overwrite the existing one.' );
	}

	public function test_status_labels_cover_every_valid_status(): void {
		$labels = TaxExemption::status_labels();

		$this->assertArrayHasKey( TaxExemption::STATUS_TAXABLE, $labels );
		$this->assertArrayHasKey( TaxExemption::STATUS_EXEMPT, $labels );
		$this->assertArrayHasKey( TaxExemption::STATUS_REVERSE_CHARGE, $labels );
	}
}
