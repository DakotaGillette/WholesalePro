<?php
/**
 * MergeTags: context values (first name / store name precedence, last
 * order figures), rendering per output format (html escapes, text
 * strips tags, unknown tags vanish rather than leaking the literal
 * placeholder), which tags are available for which trigger, SMS segment
 * counting, and the Advanced Shipment Tracking bridge.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MergeTags;

/**
 * Class Test_Merge_Tags
 */
class Test_Merge_Tags extends WP_UnitTestCase {

	public function test_first_name_prefers_the_application_answer_over_billing_and_display_name(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		wp_update_user( array( 'ID' => $user_id, 'display_name' => 'Display Name' ) );
		update_user_meta( $user_id, 'billing_first_name', 'Billing' );

		$context = MergeTags::context_for_customer( $user_id );
		$this->assertSame( 'Display Name', $context['first_name'], 'Falls back to display name with nothing else set.' );

		update_user_meta( $user_id, '_protech_wholesale_app_name', 'Ada Lovelace' );
		$context = MergeTags::context_for_customer( $user_id );
		$this->assertSame( 'Ada', $context['first_name'] );
		$this->assertSame( 'Ada Lovelace', $context['name'] );
	}

	public function test_last_order_tags_are_populated_from_a_real_order(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 10 );
		$order->set_date_created( time() - 3 * DAY_IN_SECONDS );
		$order->save();

		$context = MergeTags::context_for_customer( $user_id );

		$this->assertSame( (string) $order->get_order_number(), $context['last_order_number'] );
		$this->assertSame( '3', $context['days_since_last_order'] );
		$this->assertSame( '1', $context['order_count'] );
		$this->assertNotEmpty( $context['last_order_url'] );
	}

	public function test_no_orders_leaves_last_order_tags_empty_not_missing(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$context = MergeTags::context_for_customer( $user_id );

		$this->assertSame( '', $context['last_order_number'] );
		$this->assertSame( '', $context['days_since_last_order'] );
		$this->assertArrayHasKey( 'last_order_url', $context );
	}

	public function test_html_render_escapes_values_and_esc_urls_url_tags(): void {
		$context = array(
			'store_name' => '<script>alert(1)</script>',
			'shop_url'   => 'https://example.com/shop?a=1&b=2',
		);

		$rendered = MergeTags::render( 'Hi {store_name}, visit {shop_url}', $context, 'html' );

		$this->assertStringNotContainsString( '<script>', $rendered );
		$this->assertStringContainsString( '&lt;script&gt;', $rendered );
		$this->assertStringContainsString( 'https://example.com/shop', $rendered );
	}

	public function test_text_render_strips_html_and_keeps_raw_values(): void {
		$context  = array( 'first_name' => 'Ada' );
		$rendered = MergeTags::render( '<strong>Hi {first_name}!</strong>', $context, 'text' );

		$this->assertSame( 'Hi Ada!', trim( $rendered ) );
	}

	public function test_an_unknown_tag_renders_as_empty_and_is_reported(): void {
		$rendered = MergeTags::render( 'Hi {not_a_real_tag}', array(), 'text' );
		$this->assertSame( 'Hi', trim( $rendered ) );

		$unknown = MergeTags::unknown_tags( 'Hi {not_a_real_tag} {first_name}' );
		$this->assertSame( array( 'not_a_real_tag' ), $unknown );
	}

	public function test_order_only_tags_are_unavailable_outside_the_order_status_trigger(): void {
		$this->assertNotEmpty( MergeTags::unknown_tags( '{tracking_url}', '' ) );
		$this->assertEmpty( MergeTags::unknown_tags( '{tracking_url}', 'order_status' ) );
		$this->assertArrayNotHasKey( 'tracking_url', MergeTags::all( '' ) );
		$this->assertArrayHasKey( 'tracking_url', MergeTags::all( 'order_status' ) );
	}

	public function test_sms_segment_counting(): void {
		$ascii_one_segment = str_repeat( 'a', 160 );
		$ascii_two_segments = str_repeat( 'a', 161 );
		$unicode_one_segment = str_repeat( 'é', 70 );
		$unicode_two_segments = str_repeat( 'é', 71 );

		$this->assertSame( 1, MergeTags::sms_segments( $ascii_one_segment )['segments'] );
		$this->assertSame( 2, MergeTags::sms_segments( $ascii_two_segments )['segments'] );
		$this->assertFalse( MergeTags::sms_segments( $ascii_one_segment )['unicode'] );

		$this->assertSame( 1, MergeTags::sms_segments( $unicode_one_segment )['segments'] );
		$this->assertSame( 2, MergeTags::sms_segments( $unicode_two_segments )['segments'] );
		$this->assertTrue( MergeTags::sms_segments( $unicode_one_segment )['unicode'] );
	}

	public function test_tracking_is_empty_without_ast_and_reads_from_the_test_filter(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 1 );

		$empty = MergeTags::tracking_for_order( $order );
		$this->assertSame( '', $empty['number'] );
		$this->assertSame( '', $empty['block'] );

		$filter = static function ( array $items, \WC_Order $filtered_order ) use ( $order ) {
			if ( $filtered_order->get_id() !== $order->get_id() ) {
				return $items;
			}

			return array(
				array(
					'tracking_number'             => '1Z999',
					'formatted_tracking_provider' => 'UPS',
					'formatted_tracking_link'     => 'https://example.com/track/1Z999',
				),
			);
		};

		add_filter( 'protech_wholesale_order_tracking', $filter, 10, 2 );

		$context = MergeTags::context_for_customer( $user_id, $order );
		$this->assertSame( '1Z999', $context['tracking_number'] );
		$this->assertSame( 'https://example.com/track/1Z999', $context['tracking_url'] );
		$this->assertSame( 'UPS', $context['carrier'] );
		$this->assertStringContainsString( '1Z999', $context['tracking_block'] );

		remove_filter( 'protech_wholesale_order_tracking', $filter, 10 );
	}
}
