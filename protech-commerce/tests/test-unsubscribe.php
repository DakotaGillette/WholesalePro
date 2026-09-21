<?php
/**
 * Unsubscribe: the per-user token link that lets a marketing email
 * recipient opt out of marketing email without logging in, a wrong
 * token changing nothing, and a transactional email never being
 * affected by an unsubscribe.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;
use ProtechWholesale\SmsConsent;
use ProtechWholesale\Unsubscribe;

/**
 * Class Test_Unsubscribe
 */
class Test_Unsubscribe extends WP_UnitTestCase {

	public function test_the_url_carries_a_real_per_user_token(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$url     = Unsubscribe::url( $user_id );

		$this->assertStringContainsString( 'action=protech_unsubscribe', $url );
		$this->assertStringContainsString( 'uid=' . $user_id, $url );
		$this->assertNotEmpty( get_user_meta( $user_id, Unsubscribe::META_TOKEN, true ) );
	}

	public function test_a_wrong_token_changes_nothing(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		Unsubscribe::url( $user_id ); // Ensures a real token exists to be wrong against.

		$result = Unsubscribe::process( $user_id, 'not-the-real-token' );

		$this->assertFalse( $result );
		$this->assertSame( '', (string) get_user_meta( $user_id, SmsConsent::META_EMAIL_MARKETING, true ), 'A wrong token must not record an opt-out.' );
	}

	public function test_the_real_token_records_an_email_marketing_opt_out(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$url     = Unsubscribe::url( $user_id );
		$query   = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$result = Unsubscribe::process( $user_id, $query['t'] );

		$this->assertTrue( $result );
		$this->assertSame( 'no', get_user_meta( $user_id, SmsConsent::META_EMAIL_MARKETING, true ) );

		$records = SmsConsent::records( $user_id );
		$this->assertNotEmpty( $records );
		$this->assertSame( SmsConsent::SOURCE_UNSUBSCRIBE_LINK, end( $records )['source'] );
	}

	public function test_the_footer_is_only_appended_to_marketing_email_and_transactional_still_sends_after_unsubscribe(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		SmsConsent::record( $user_id, array( 'email_marketing' => false ), SmsConsent::SOURCE_UNSUBSCRIBE_LINK );

		$user = get_userdata( $user_id );

		reset_phpmailer_instance();
		$mailer = WC()->mailer();
		if ( false === has_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) ) ) {
			add_action( 'woocommerce_email_header', array( $mailer, 'email_header' ) );
			add_action( 'woocommerce_email_footer', array( $mailer, 'email_footer' ) );
		}

		$transactional = MessageTransport::send_email( $user_id, $user->user_email, 'Order update', 'Heading', '<p>Body</p>', MessageLog::CATEGORY_TRANSACTIONAL );
		$this->assertSame( 'sent', $transactional['status'], 'An unsubscribe from marketing must never block a transactional email.' );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringNotContainsString( 'Unsubscribe', $sent->body, 'Transactional email gets no unsubscribe footer.' );

		reset_phpmailer_instance();
		MessageTransport::send_email( $user_id, $user->user_email, 'A deal for you', 'Heading', '<p>Body</p>', MessageLog::CATEGORY_MARKETING );
		$marketing_sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'Unsubscribe', $marketing_sent->body );
	}
}
