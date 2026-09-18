<?php
/**
 * [protech_header_notice]: a retail visitor gets the enclosed text back
 * unchanged; a wholesale customer gets the generated (or overridden)
 * wholesale line instead.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\HeaderNotice;
use ProtechWholesale\Settings;

/**
 * Class Test_Header_Notice
 */
class Test_Header_Notice extends WP_UnitTestCase {

	public function test_guests_and_retail_customers_see_the_enclosed_text_unchanged(): void {
		$notice = new HeaderNotice();

		wp_set_current_user( 0 );
		$this->assertSame( 'FREE SHIPPING WITH $30+ ORDERS', $notice->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' ) );

		wp_set_current_user( Protech_Test_Factory::retail_customer() );
		$this->assertSame( 'FREE SHIPPING WITH $30+ ORDERS', $notice->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' ) );
	}

	public function test_a_wholesale_customer_sees_the_generated_message_from_the_live_threshold(): void {
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '16' );
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		$notice = new HeaderNotice();

		$this->assertSame( 'FREE SHIPPING ON WHOLESALE ORDERS OF 16+ DISPLAYS', $notice->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' ) );

		// Never stale: changing the threshold changes the message with it.
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '24' );
		$this->assertStringContainsString( '24+', $notice->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' ) );
	}

	public function test_the_wholesale_attribute_overrides_the_generated_message(): void {
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		$notice = new HeaderNotice();
		$result = $notice->render( array( 'wholesale' => 'ASK ABOUT FREE FREIGHT' ), 'FREE SHIPPING WITH $30+ ORDERS' );

		$this->assertSame( 'ASK ABOUT FREE FREIGHT', $result );
	}

	public function test_the_wholesale_message_is_filterable(): void {
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		add_filter( 'protech_wholesale_header_notice', fn() => 'FILTERED MESSAGE' );
		$result = ( new HeaderNotice() )->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' );
		remove_all_filters( 'protech_wholesale_header_notice' );

		$this->assertSame( 'FILTERED MESSAGE', $result );
	}
}
