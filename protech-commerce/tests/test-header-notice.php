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

	public function test_a_wholesale_customer_sees_the_generated_message_from_the_live_threshold_in_cases(): void {
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '16' );
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '8' );
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		$notice = new HeaderNotice();

		$this->assertSame( 'FREE SHIPPING ON WHOLESALE ORDERS OF 2+ CASES', $notice->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' ) );

		// Never stale: changing the threshold changes the message with it.
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '24' );
		$this->assertStringContainsString( '3+', $notice->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' ) );
	}

	public function test_the_case_count_rounds_up_so_it_never_overstates_the_display_threshold(): void {
		// 20 displays at 8 per case is 2.5 cases — "2+ cases" would understate
		// the real threshold, so this must round up to 3.
		update_option( Settings::OPT_VOLUME_THRESHOLD_DISPLAYS, '20' );
		update_option( Settings::OPT_DEFAULT_DISPLAYS_PER_CASE, '8' );
		wp_set_current_user( Protech_Test_Factory::wholesale_customer() );

		$this->assertSame(
			'FREE SHIPPING ON WHOLESALE ORDERS OF 3+ CASES',
			( new HeaderNotice() )->render( array(), 'FREE SHIPPING WITH $30+ ORDERS' )
		);
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
