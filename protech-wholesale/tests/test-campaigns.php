<?php
/**
 * Manual sends: audience resolution for each segment type, campaign
 * validation, launch() queuing one row per recipient x channel while
 * respecting consent gates, and send_test() delivering synchronously
 * (captured by the PHPMailer mock when Brevo isn't connected).
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Audience;
use ProtechWholesale\Campaigns;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\SmsConsent;
use ProtechWholesale\Tiers;

/**
 * Class Test_Campaigns
 */
class Test_Campaigns extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_not_blacklisted' ), 10 );
		parent::tear_down();
	}

	/**
	 * A Brevo "contact not found" answer for every /contacts/ lookup — the
	 * gating tests below care about the CONSENT decision, not Brevo's
	 * blacklist plumbing (that's covered in Test_Brevo_Client), so this
	 * stands in for "definitely not blacklisted" without a real Brevo key.
	 *
	 * @param false|array|WP_Error $preempt
	 * @param array                $args
	 * @param string               $url
	 * @return false|array|WP_Error
	 */
	public function answer_not_blacklisted( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api.brevo.com/v3/contacts/' ) ) {
			return $preempt;
		}

		return array(
			'response' => array( 'code' => 404 ),
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'message' => 'Contact does not exist' ) ),
		);
	}

	public function test_audience_all_returns_every_wholesale_customer(): void {
		$a = Protech_Test_Factory::wholesale_customer();
		$b = Protech_Test_Factory::wholesale_customer();
		Protech_Test_Factory::retail_customer();

		$ids = Audience::resolve( array( 'type' => Audience::TYPE_ALL ) );

		$this->assertContains( $a, $ids );
		$this->assertContains( $b, $ids );
	}

	public function test_audience_tier_filters_by_tier(): void {
		$gold   = Protech_Test_Factory::wholesale_customer();
		Tiers::set_user_tier( $gold, Tiers::GOLD );
		$bronze = Protech_Test_Factory::wholesale_customer();

		$ids = Audience::resolve( array( 'type' => Audience::TYPE_TIER, 'tiers' => array( Tiers::GOLD ) ) );

		$this->assertContains( $gold, $ids );
		$this->assertNotContains( $bronze, $ids );
	}

	public function test_audience_never_ordered_excludes_customers_with_an_order(): void {
		$never   = Protech_Test_Factory::wholesale_customer();
		$ordered = Protech_Test_Factory::wholesale_customer();
		Protech_Test_Factory::order_for( $ordered, Protech_Test_Factory::simple_product(), 1 );

		$ids = Audience::resolve( array( 'type' => Audience::TYPE_NEVER_ORDERED ) );

		$this->assertContains( $never, $ids );
		$this->assertNotContains( $ordered, $ids );
	}

	public function test_audience_selected_ignores_ids_that_are_not_wholesale_customers(): void {
		$wholesale = Protech_Test_Factory::wholesale_customer();
		$retail    = Protech_Test_Factory::retail_customer();

		$ids = Audience::resolve( array( 'type' => Audience::TYPE_SELECTED, 'user_ids' => array( $wholesale, $retail, $wholesale ) ) );

		$this->assertSame( array( $wholesale ), $ids, 'A non-wholesale id is dropped and a duplicate id collapses.' );
	}

	public function test_create_rejects_an_empty_body_and_unknown_tags(): void {
		$result = Campaigns::create(
			array(
				'channel' => 'email',
				'email'   => array( 'subject' => 'Hi', 'body' => '' ),
			),
			1
		);

		$this->assertNotEmpty( $result['errors'] );

		$result = Campaigns::create(
			array(
				'channel' => 'email',
				'email'   => array( 'subject' => 'Hi', 'body' => 'Track it: {tracking_url}' ),
			),
			1
		);

		$this->assertNotEmpty( $result['errors'], 'Order-only tags are never available in a campaign.' );
	}

	public function test_launch_queues_one_row_per_recipient_per_channel_and_skips_ungated_ones(): void {
		// Marketing SMS fails CLOSED when the Brevo blacklist status can't
		// be verified (see SmsConsent::can_receive_sms()) — configure a
		// key and answer the blacklist check definitively so this test
		// isolates the CONSENT decision, not Brevo connectivity.
		update_option( MessagingSettings::OPT_BREVO_API_KEY, 'test-key' );
		add_filter( 'pre_http_request', array( $this, 'answer_not_blacklisted' ), 10, 3 );

		$consented   = Protech_Test_Factory::consented_customer();
		$unconsented = Protech_Test_Factory::wholesale_customer(); // no SMS consent, no phone.

		$result = Campaigns::create(
			array(
				'channel'  => 'both',
				'audience' => array( 'type' => Audience::TYPE_SELECTED, 'user_ids' => array( $consented, $unconsented ) ),
				'email'    => array( 'subject' => 'Hi {first_name}', 'body' => 'Hello there' ),
				'sms'      => array( 'body' => 'Hi there' ),
			),
			1
		);

		$this->assertEmpty( $result['errors'] );

		$launch = Campaigns::launch( $result['campaign'] );

		// Each of the two customers gets an email row (opt-out only); only
		// the consented one also gets an SMS row.
		$this->assertSame( 3, $launch['queued'] );
		$this->assertSame( 1, $launch['skipped']['no_phone'] ?? 0 );

		$this->assertTrue( MessageLog::exists( 'campaign:' . $result['campaign']['id'], $consented, '', MessageLog::CHANNEL_SMS ) );
		$this->assertFalse( MessageLog::exists( 'campaign:' . $result['campaign']['id'], $unconsented, '', MessageLog::CHANNEL_SMS ) );
	}

	public function test_launch_is_idempotent_if_called_twice_for_the_same_campaign(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$result  = Campaigns::create(
			array(
				'channel'  => 'email',
				'audience' => array( 'type' => Audience::TYPE_SELECTED, 'user_ids' => array( $user_id ) ),
				'email'    => array( 'subject' => 'Hi', 'body' => 'Hello' ),
			),
			1
		);

		$first  = Campaigns::launch( $result['campaign'] );
		$second = Campaigns::launch( $result['campaign'] );

		$this->assertSame( 1, $first['queued'] );
		$this->assertSame( 0, $second['queued'], 'The same campaign id launched twice must not double-queue.' );
	}

	public function test_send_test_delivers_synchronously_and_is_captured_by_the_mail_mock(): void {
		reset_phpmailer_instance();
		$mailer = WC()->mailer();
		if ( false === has_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) ) ) {
			add_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) );
			add_action( 'woocommerce_email_footer', array( $mailer, 'email_footer' ) );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );

		$result = Campaigns::send_test(
			array(
				'channel' => 'email',
				'email'   => array( 'subject' => 'Test subject', 'heading' => 'Heading', 'body' => 'Body text' ),
			),
			$admin_id
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'wc_mailer', $result['provider'], 'No Brevo key is configured in tests, so the WooCommerce mailer fallback is used.' );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'admin@example.com', $sent->to[0][0] );
		$this->assertStringContainsString( 'Body text', $sent->body );

		$row = MessageLog::query( array( 'user_id' => $admin_id ), 1, 1 )['rows'][0] ?? null;
		$this->assertNotNull( $row );
		$this->assertSame( MessageLog::KIND_TEST, $row['kind'] );
		$this->assertSame( MessageLog::STATUS_SENT, $row['status'] );
	}
}
