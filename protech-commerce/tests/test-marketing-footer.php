<?php
/**
 * The footer every marketing email carries: the unsubscribe and preferences
 * links, wording that fits who is reading it, and the store's postal address
 * (CAN-SPAM requires one). Transactional email carries none of it.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MergeTags;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\SetupChecks;

/**
 * Class Test_Marketing_Footer
 */
class Test_Marketing_Footer extends WP_UnitTestCase {

	private function set_store_address(): void {
		update_option( 'woocommerce_store_address', '1 Main St' );
		update_option( 'woocommerce_store_city', 'Portland' );
		update_option( 'woocommerce_store_postcode', '97201' );
		update_option( 'woocommerce_default_country', 'US:OR' );
	}

	private function clear_store_address(): void {
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country' ) as $option ) {
			update_option( $option, '' );
		}
	}

	public function tear_down(): void {
		delete_option( MessagingSettings::OPT_ENABLED );
		delete_option( MessagingSettings::OPT_EMAIL_FOOTER );
		parent::tear_down();
	}

	public function test_a_marketing_footer_carries_the_unsubscribe_link_and_the_postal_address(): void {
		$this->set_store_address();
		$user_id = Protech_Test_Factory::wholesale_customer();

		$footer = MessageTransport::footer_html_for( $user_id, MessageLog::CATEGORY_MARKETING );

		$this->assertStringContainsString( 'Unsubscribe', $footer );
		$this->assertStringContainsString( 'Manage preferences', $footer );
		$this->assertStringContainsString( '1 Main St', $footer );
		$this->assertStringContainsString( 'Portland', $footer );
	}

	public function test_the_wording_fits_a_wholesale_account_and_a_past_customer(): void {
		$wholesale = MessageTransport::footer_html_for( Protech_Test_Factory::wholesale_customer(), MessageLog::CATEGORY_MARKETING );
		$retail    = MessageTransport::footer_html_for( Protech_Test_Factory::retail_customer(), MessageLog::CATEGORY_MARKETING );

		$this->assertStringContainsString( 'you have a wholesale account with', $wholesale );
		$this->assertStringContainsString( 'you have shopped with', $retail );
		$this->assertStringNotContainsString( 'wholesale account', $retail );
	}

	public function test_transactional_email_gets_no_footer(): void {
		$this->assertSame( '', MessageTransport::footer_html_for( Protech_Test_Factory::wholesale_customer(), MessageLog::CATEGORY_TRANSACTIONAL ) );
	}

	public function test_switching_the_footer_off_removes_it_for_marketing(): void {
		update_option( MessagingSettings::OPT_EMAIL_FOOTER, 'no' );

		$this->assertSame( '', MessageTransport::footer_html_for( Protech_Test_Factory::wholesale_customer(), MessageLog::CATEGORY_MARKETING ) );
	}

	public function test_without_a_store_address_the_footer_still_has_its_links(): void {
		$this->clear_store_address();
		$footer = MessageTransport::footer_html_for( Protech_Test_Factory::wholesale_customer(), MessageLog::CATEGORY_MARKETING );

		$this->assertStringContainsString( 'Unsubscribe', $footer );
		$this->assertStringNotContainsString( '<br />', $footer );
	}

	public function test_the_setup_check_warns_about_a_missing_address_only_when_messaging_is_on(): void {
		$this->clear_store_address();

		$texts = static function (): array {
			return array_column( SetupChecks::problems(), 'text' );
		};
		$has   = static function ( array $texts ): bool {
			foreach ( $texts as $text ) {
				if ( false !== strpos( $text, 'postal address' ) ) {
					return true;
				}
			}

			return false;
		};

		update_option( MessagingSettings::OPT_ENABLED, 'no' );
		$this->assertFalse( $has( $texts() ), 'Nothing to warn about while messaging is off.' );

		update_option( MessagingSettings::OPT_ENABLED, 'yes' );
		$this->assertTrue( $has( $texts() ) );

		$this->set_store_address();
		$this->assertFalse( $has( $texts() ) );
		$this->assertNotSame( '', MergeTags::store_address() );
	}
}
