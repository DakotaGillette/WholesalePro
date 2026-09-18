<?php
/**
 * The setup checks behind the "Wholesale is not fully set up yet" notice:
 * each one turns off when the thing it checks for is in place.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessagingSettings;
use ProtechWholesale\SetupChecks;
use ProtechWholesale\WholesaleShippingMethod;

/**
 * Class Test_Setup_Checks
 */
class Test_Setup_Checks extends WP_UnitTestCase {

	/** @return string[] The problem texts. */
	private function problem_texts(): array {
		return array_column( SetupChecks::problems(), 'text' );
	}

	private function has_problem_about( string $needle ): bool {
		foreach ( $this->problem_texts() as $text ) {
			if ( false !== strpos( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	public function test_a_priced_product_clears_the_products_check(): void {
		Protech_Test_Factory::simple_product( null );
		$this->assertFalse( SetupChecks::has_priced_products() );
		$this->assertTrue( $this->has_problem_about( 'No product has a wholesale price' ) );

		Protech_Test_Factory::simple_product( '5.50' );
		$this->assertTrue( SetupChecks::has_priced_products() );
		$this->assertFalse( $this->has_problem_about( 'No product has a wholesale price' ) );
	}

	public function test_a_variable_product_priced_per_variation_counts(): void {
		Protech_Test_Factory::variable_product( array( 'blue' ), array( 'blue' => '5.50' ) );

		$this->assertTrue( SetupChecks::has_priced_products() );
	}

	public function test_the_portal_page_is_found_by_its_shortcode(): void {
		$before = SetupChecks::portal_page_id();

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Trade',
				'post_name'    => 'trade-login',
				'post_content' => 'Welcome [protech_wholesale_portal]',
			)
		);

		$this->assertGreaterThan( 0, SetupChecks::portal_page_id() );
		$this->assertFalse( $this->has_problem_about( 'no published page' ) );

		wp_delete_post( $page_id, true );

		if ( 0 === $before ) {
			$this->assertTrue( $this->has_problem_about( 'no published page' ) );
		}
	}

	public function test_the_shipping_method_is_found_in_a_zone(): void {
		$this->assertSame( array(), SetupChecks::zones_with_wholesale_shipping() );
		$this->assertTrue( $this->has_problem_about( 'not in any shipping zone' ) );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test zone' );
		$zone->save();
		$zone->add_shipping_method( WholesaleShippingMethod::METHOD_ID );

		$this->assertSame( array( 'Test zone' ), SetupChecks::zones_with_wholesale_shipping() );
		$this->assertFalse( $this->has_problem_about( 'not in any shipping zone' ) );
	}

	public function test_the_form_check_is_skipped_when_it_cannot_be_made(): void {
		// Fluent Forms is not installed in the test environment.
		$this->assertNull( SetupChecks::application_form_exists() );
		$this->assertFalse( $this->has_problem_about( 'Fluent Forms has no form' ) );
	}

	public function test_brevo_connected_is_null_unless_automations_are_enabled(): void {
		$this->assertNull( SetupChecks::brevo_connected(), 'Not a problem for a store that never turned automations on.' );
		$this->assertFalse( $this->has_problem_about( 'Brevo is not connected' ) );

		update_option( MessagingSettings::OPT_ENABLED, 'yes' );
		$this->assertFalse( SetupChecks::brevo_connected() );
		$this->assertTrue( $this->has_problem_about( 'Brevo is not connected' ) );

		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'a-key' );
		$this->assertTrue( SetupChecks::brevo_connected() );
		$this->assertFalse( $this->has_problem_about( 'Brevo is not connected' ) );
	}

	public function test_privacy_policy_sms_check_is_null_without_a_privacy_page(): void {
		update_option( 'wp_page_for_privacy_policy', 0 );
		$this->assertNull( SetupChecks::privacy_policy_mentions_sms() );
	}

	public function test_privacy_policy_sms_check_reads_the_pages_content(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'We collect your name and email address.',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $page_id );

		$this->assertFalse( SetupChecks::privacy_policy_mentions_sms() );

		wp_update_post( array( 'ID' => $page_id, 'post_content' => 'We may send SMS text messages about your order.' ) );
		$this->assertTrue( SetupChecks::privacy_policy_mentions_sms() );
	}
}
