<?php
/**
 * TaxExemption: reads the tax-exempt status the "Stripe Tax for
 * WooCommerce" plugin already stores on `tax_exemption` user meta ('none' |
 * 'customer_exempt' | 'reverse_charge') — this plugin never writes that
 * key, only surfaces it in the Customers-tab badge.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\TaxExemption;

/**
 * Class Test_Tax_Exemption
 */
class Test_Tax_Exemption extends WP_UnitTestCase {

	public function test_is_exempt_defaults_to_false_when_no_meta_is_set(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$this->assertFalse( TaxExemption::is_exempt( $user_id ) );
		$this->assertSame( '', TaxExemption::label( $user_id ) );
	}

	public function test_is_exempt_is_false_for_the_taxable_default_value(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, 'tax_exemption', 'none' );

		$this->assertFalse( TaxExemption::is_exempt( $user_id ) );
	}

	public function test_is_exempt_is_true_for_customer_exempt(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, 'tax_exemption', 'customer_exempt' );

		$this->assertTrue( TaxExemption::is_exempt( $user_id ) );
		$this->assertSame( 'Exempt', TaxExemption::label( $user_id ) );
	}

	public function test_is_exempt_is_true_for_reverse_charge(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, 'tax_exemption', 'reverse_charge' );

		$this->assertTrue( TaxExemption::is_exempt( $user_id ) );
		$this->assertSame( 'Reverse charge', TaxExemption::label( $user_id ) );
	}

	public function test_an_unrecognised_value_is_treated_as_not_exempt(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, 'tax_exemption', 'garbage' );

		$this->assertFalse( TaxExemption::is_exempt( $user_id ) );
	}
}
