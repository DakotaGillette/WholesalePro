<?php
/**
 * Audiences beyond wholesale accounts (2.5.0): the retail and everyone pools,
 * the recency and bought-a-product segments, and what a retail customer sees
 * in a marketing email footer and after unsubscribing.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Audience;
use ProtechWholesale\Campaigns;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\SmsConsent;
use ProtechWholesale\Unsubscribe;

/**
 * Class Test_Audience_Scope
 */
class Test_Audience_Scope extends WP_UnitTestCase {

	private function order_days_ago( int $user_id, WC_Product $product, int $days ): WC_Order {
		$order = Protech_Test_Factory::order_for( $user_id, $product, 1 );
		$order->set_date_created( time() - $days * DAY_IN_SECONDS );
		$order->save();

		return $order;
	}

	public function test_the_pools_split_wholesale_from_retail_and_everyone_is_both(): void {
		$wholesale = Protech_Test_Factory::wholesale_customer();
		$retail    = Protech_Test_Factory::retail_customer();

		$this->assertContains( $wholesale, Audience::pool( Audience::SCOPE_WHOLESALE ) );
		$this->assertNotContains( $retail, Audience::pool( Audience::SCOPE_WHOLESALE ) );

		$this->assertContains( $retail, Audience::pool( Audience::SCOPE_RETAIL ) );
		$this->assertNotContains( $wholesale, Audience::pool( Audience::SCOPE_RETAIL ) );

		$everyone = Audience::pool( Audience::SCOPE_EVERYONE );

		$this->assertContains( $wholesale, $everyone );
		$this->assertContains( $retail, $everyone );
		$this->assertSame( count( $everyone ), count( array_unique( $everyone ) ), 'Nobody is counted twice.' );
	}

	public function test_a_segment_without_a_scope_is_wholesale_only_as_before(): void {
		$wholesale = Protech_Test_Factory::wholesale_customer();
		$retail    = Protech_Test_Factory::retail_customer();

		$ids = Audience::resolve( array( 'type' => Audience::TYPE_ALL ) );

		$this->assertContains( $wholesale, $ids );
		$this->assertNotContains( $retail, $ids );
		$this->assertSame( 'wholesale', Audience::normalize( array() )['scope'] );
		$this->assertSame( 'wholesale', Audience::normalize( array( 'scope' => 'martians' ) )['scope'] );
	}

	public function test_all_retail_and_everyone_resolve_to_their_pools(): void {
		$wholesale = Protech_Test_Factory::wholesale_customer();
		$retail    = Protech_Test_Factory::retail_customer();

		$retail_ids   = Audience::resolve( array( 'type' => Audience::TYPE_ALL, 'scope' => Audience::SCOPE_RETAIL ) );
		$everyone_ids = Audience::resolve( array( 'type' => Audience::TYPE_ALL, 'scope' => Audience::SCOPE_EVERYONE ) );

		$this->assertContains( $retail, $retail_ids );
		$this->assertNotContains( $wholesale, $retail_ids );
		$this->assertContains( $retail, $everyone_ids );
		$this->assertContains( $wholesale, $everyone_ids );
	}

	public function test_tiers_and_prefers_texts_stay_wholesale_whatever_the_scope(): void {
		$retail = Protech_Test_Factory::retail_customer();

		$this->assertNotContains( $retail, Audience::resolve( array( 'type' => Audience::TYPE_TIER, 'scope' => Audience::SCOPE_EVERYONE ) ) );
	}

	public function test_recent_and_inactive_split_retail_customers_by_their_last_order(): void {
		$product = Protech_Test_Factory::simple_product();
		$recent  = Protech_Test_Factory::retail_customer();
		$lapsed  = Protech_Test_Factory::retail_customer();
		$never   = Protech_Test_Factory::retail_customer();

		$this->order_days_ago( $recent, $product, 5 );
		$this->order_days_ago( $lapsed, $product, 90 );

		$recent_ids   = Audience::resolve( array( 'type' => Audience::TYPE_RECENT, 'scope' => Audience::SCOPE_RETAIL, 'recent_days' => 30 ) );
		$inactive_ids = Audience::resolve( array( 'type' => Audience::TYPE_INACTIVE, 'scope' => Audience::SCOPE_RETAIL, 'days' => 60 ) );
		$never_ids    = Audience::resolve( array( 'type' => Audience::TYPE_NEVER_ORDERED, 'scope' => Audience::SCOPE_RETAIL ) );

		$this->assertContains( $recent, $recent_ids );
		$this->assertNotContains( $lapsed, $recent_ids );
		$this->assertNotContains( $never, $recent_ids );

		$this->assertContains( $lapsed, $inactive_ids );
		$this->assertNotContains( $recent, $inactive_ids );
		$this->assertNotContains( $never, $inactive_ids, 'Someone who never ordered is not "lapsed".' );

		$this->assertContains( $never, $never_ids );
		$this->assertNotContains( $recent, $never_ids );
	}

	public function test_the_recent_segment_reads_its_own_days_field(): void {
		$this->assertSame( 14, Audience::normalize( array( 'type' => Audience::TYPE_RECENT, 'recent_days' => 14, 'days' => 90 ) )['days'] );
		$this->assertSame( 90, Audience::normalize( array( 'type' => Audience::TYPE_INACTIVE, 'recent_days' => 14, 'days' => 90 ) )['days'] );
	}

	public function test_bought_a_product_finds_its_buyers_and_nobody_else(): void {
		$bought    = Protech_Test_Factory::simple_product();
		$other     = Protech_Test_Factory::simple_product();
		$buyer     = Protech_Test_Factory::retail_customer();
		$bystander = Protech_Test_Factory::retail_customer();

		Protech_Test_Factory::order_for( $buyer, $bought, 1 );
		Protech_Test_Factory::order_for( $bystander, $other, 1 );

		$ids = Audience::resolve( array( 'type' => Audience::TYPE_BOUGHT_PRODUCT, 'scope' => Audience::SCOPE_EVERYONE, 'product_id' => $bought->get_id() ) );

		$this->assertContains( $buyer, $ids );
		$this->assertNotContains( $bystander, $ids );
	}

	public function test_a_cancelled_order_does_not_count_as_buying(): void {
		$product = Protech_Test_Factory::simple_product();
		$user    = Protech_Test_Factory::retail_customer();

		Protech_Test_Factory::order_for( $user, $product, 1, 'cancelled' );

		$this->assertSame( array(), Audience::buyers_of( $product->get_id() ) );
	}

	public function test_a_campaign_to_buyers_needs_a_product(): void {
		$result = Campaigns::create(
			array(
				'channel'  => 'email',
				'audience' => array( 'type' => Audience::TYPE_BOUGHT_PRODUCT, 'product_id' => 0 ),
				'email'    => array( 'subject' => 'Hi', 'body' => 'Body' ),
			),
			1
		);

		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_the_description_names_who_it_is_for(): void {
		$this->assertSame( 'All wholesale customers', Audience::describe( array( 'type' => Audience::TYPE_ALL ) ) );
		$this->assertSame( 'All retail customers', Audience::describe( array( 'type' => Audience::TYPE_ALL, 'scope' => Audience::SCOPE_RETAIL ) ) );
		$this->assertSame( 'All customers', Audience::describe( array( 'type' => Audience::TYPE_ALL, 'scope' => Audience::SCOPE_EVERYONE ) ) );
		$this->assertSame( 'Retail customers who ordered in the last 30 days', Audience::describe( array( 'type' => Audience::TYPE_RECENT, 'scope' => Audience::SCOPE_RETAIL, 'recent_days' => 30 ) ) );
	}

	public function test_a_retail_marketing_email_gets_an_unsubscribe_link_but_no_wholesale_preferences_page(): void {
		$retail    = Protech_Test_Factory::retail_customer();
		$wholesale = Protech_Test_Factory::wholesale_customer();

		$retail_footer    = MessageTransport::footer_html_for( $retail, MessageLog::CATEGORY_MARKETING );
		$wholesale_footer = MessageTransport::footer_html_for( $wholesale, MessageLog::CATEGORY_MARKETING );

		$this->assertStringContainsString( 'Unsubscribe', $retail_footer );
		$this->assertStringNotContainsString( 'Manage preferences', $retail_footer );
		$this->assertStringContainsString( 'Manage preferences', $wholesale_footer );
	}

	public function test_a_retail_customer_can_unsubscribe_and_is_then_left_out_of_marketing_email(): void {
		$retail = Protech_Test_Factory::retail_customer();
		$url    = Unsubscribe::url( $retail );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertTrue( SmsConsent::can_receive_email( $retail, MessageLog::CATEGORY_MARKETING )['ok'] );
		$this->assertTrue( Unsubscribe::process( $retail, (string) $query['t'] ) );
		$this->assertSame( 'unsubscribed', SmsConsent::can_receive_email( $retail, MessageLog::CATEGORY_MARKETING )['reason'] );
		$this->assertTrue( SmsConsent::can_receive_email( $retail, MessageLog::CATEGORY_TRANSACTIONAL )['ok'], 'Service email still reaches them.' );
	}

	public function test_a_retail_customer_never_gets_a_marketing_text_without_saying_yes(): void {
		$retail = Protech_Test_Factory::retail_customer();

		$this->assertFalse( SmsConsent::can_receive_sms( $retail, MessageLog::CATEGORY_MARKETING )['ok'] );
	}

	public function test_counting_recipients_does_not_ask_brevo_about_each_person(): void {
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );

		$calls = 0;
		$spy   = static function ( $preempt, $args, $url ) use ( &$calls ) {
			if ( false === strpos( (string) $url, 'api.brevo.com/v3/contacts/' ) ) {
				return $preempt;
			}

			++$calls;

			return array( 'response' => array( 'code' => 404 ), 'headers' => array(), 'body' => '{}' );
		};

		add_filter( 'pre_http_request', $spy, 10, 3 );

		$retail = Protech_Test_Factory::retail_customer();

		$this->assertTrue( SmsConsent::can_receive_email( $retail, MessageLog::CATEGORY_MARKETING, false )['ok'] );
		$this->assertSame( 0, $calls, 'Counting an audience uses the local unsubscribe record only.' );

		$this->assertTrue( SmsConsent::can_receive_email( $retail, MessageLog::CATEGORY_MARKETING )['ok'] );
		$this->assertGreaterThan( 0, $calls, 'Delivering a message still asks Brevo.' );

		remove_filter( 'pre_http_request', $spy, 10 );
		delete_option( MessagingSettings::OPT_BREVO_API_KEY );
	}
}
