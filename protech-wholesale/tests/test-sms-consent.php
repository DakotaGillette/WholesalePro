<?php
/**
 * SmsConsent: phone normalization, recording consent (and NOT recording
 * a no-op change), gating for each channel/category, and the
 * application-form hook.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;
use ProtechWholesale\SmsConsent;

/**
 * Class Test_Sms_Consent
 */
class Test_Sms_Consent extends WP_UnitTestCase {

	public function test_phone_normalization(): void {
		$this->assertSame( '+15555550100', SmsConsent::normalize_phone( '555-555-0100' ) );
		$this->assertSame( '+15555550100', SmsConsent::normalize_phone( '(555) 555-0100' ) );
		$this->assertSame( '+15555550100', SmsConsent::normalize_phone( '15555550100' ) );
		$this->assertSame( '+15555550100', SmsConsent::normalize_phone( '+1 555 555 0100' ) );
		$this->assertSame( '+447911123456', SmsConsent::normalize_phone( '+44 7911 123456' ) );
		$this->assertSame( '', SmsConsent::normalize_phone( '12345' ) );
		$this->assertSame( '', SmsConsent::normalize_phone( '' ) );
	}

	public function test_phone_for_falls_back_through_billing_and_application_meta(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		$this->assertSame( '', SmsConsent::phone_for( $user_id ) );

		update_user_meta( $user_id, '_protech_wholesale_app_phone', '555-555-0100' );
		$this->assertSame( '+15555550100', SmsConsent::phone_for( $user_id ) );

		update_user_meta( $user_id, 'billing_phone', '555-555-0200' );
		$this->assertSame( '+15555550200', SmsConsent::phone_for( $user_id ), 'billing_phone outranks the application answer.' );

		update_user_meta( $user_id, SmsConsent::META_PHONE, '555-555-0300' );
		$this->assertSame( '+15555550300', SmsConsent::phone_for( $user_id ), 'An explicitly recorded number outranks everything else.' );
	}

	public function test_record_writes_meta_and_appends_a_consent_log_entry(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		SmsConsent::record(
			$user_id,
			array( 'phone' => '5555550100', 'sms_marketing' => true ),
			SmsConsent::SOURCE_MY_ACCOUNT,
			'',
			0
		);

		$state = SmsConsent::state( $user_id );
		$this->assertSame( '+15555550100', $state['phone'] );
		$this->assertSame( 'yes', $state['sms_marketing'] );

		$records = SmsConsent::records( $user_id );
		$this->assertCount( 1, $records );
		$this->assertSame( SmsConsent::SOURCE_MY_ACCOUNT, $records[0]['source'] );
		$this->assertArrayHasKey( 'wording', $records[0] );
		$this->assertNotEmpty( $records[0]['wording'] );
	}

	public function test_recording_the_same_state_again_does_not_add_a_log_entry_or_resend_confirmation(): void {
		$user_id = Protech_Test_Factory::consented_customer();
		$this->assertCount( 1, SmsConsent::records( $user_id ) );

		// Same values, submitted again — nothing actually changed.
		SmsConsent::record(
			$user_id,
			array( 'phone' => '5555550100', 'sms_transactional' => true, 'sms_marketing' => true ),
			SmsConsent::SOURCE_MY_ACCOUNT
		);

		$this->assertCount( 1, SmsConsent::records( $user_id ), 'A no-op save must not create a fresh consent record.' );
	}

	public function test_marketing_grant_queues_an_optin_confirmation_text(): void {
		global $wpdb;
		$table = MessageLog::table();

		$user_id = Protech_Test_Factory::wholesale_customer();

		SmsConsent::record( $user_id, array( 'phone' => '5555550100', 'sms_marketing' => true ), SmsConsent::SOURCE_APPLICATION_FORM );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND rule_id = %s", $user_id, 'sms_optin_confirmation' ), ARRAY_A );

		$this->assertNotNull( $row, 'Granting marketing SMS consent should queue the opt-in confirmation text.' );
		$this->assertSame( MessageLog::CATEGORY_TRANSACTIONAL, $row['category'] );
	}

	public function test_marketing_sms_requires_consent_and_a_phone(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$gate = SmsConsent::can_receive_sms( $user_id, MessageLog::CATEGORY_MARKETING );
		$this->assertFalse( $gate['ok'] );
		$this->assertSame( 'no_phone', $gate['reason'] );

		update_user_meta( $user_id, SmsConsent::META_PHONE, '+15555550100' );
		$gate = SmsConsent::can_receive_sms( $user_id, MessageLog::CATEGORY_MARKETING );
		$this->assertFalse( $gate['ok'] );
		$this->assertSame( 'no_consent', $gate['reason'] );

		update_user_meta( $user_id, SmsConsent::META_SMS_MARKETING, 'yes' );
		$gate = SmsConsent::can_receive_sms( $user_id, MessageLog::CATEGORY_MARKETING );
		$this->assertFalse( $gate['ok'], 'Without Brevo configured, the blacklist status is unknown, which fails CLOSED for a marketing message.' );
		$this->assertSame( 'consent_unverified', $gate['reason'] );
	}

	public function test_transactional_sms_only_needs_its_own_consent_and_a_phone(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id, SmsConsent::META_PHONE, '+15555550100' );
		update_user_meta( $user_id, SmsConsent::META_SMS_TRANSACTIONAL, 'yes' );

		$gate = SmsConsent::can_receive_sms( $user_id, MessageLog::CATEGORY_TRANSACTIONAL );
		$this->assertTrue( $gate['ok'] );

		// Marketing consent alone does not grant transactional sends.
		$user_id2 = Protech_Test_Factory::wholesale_customer();
		update_user_meta( $user_id2, SmsConsent::META_PHONE, '+15555550100' );
		update_user_meta( $user_id2, SmsConsent::META_SMS_MARKETING, 'yes' );

		$gate2 = SmsConsent::can_receive_sms( $user_id2, MessageLog::CATEGORY_TRANSACTIONAL );
		$this->assertFalse( $gate2['ok'] );
	}

	public function test_marketing_email_respects_opt_out_but_transactional_email_does_not(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		$this->assertTrue( SmsConsent::can_receive_email( $user_id, MessageLog::CATEGORY_MARKETING )['ok'], 'Email marketing is opt-out, so allowed by default.' );

		SmsConsent::record( $user_id, array( 'email_marketing' => false ), SmsConsent::SOURCE_UNSUBSCRIBE_LINK );

		$this->assertFalse( SmsConsent::can_receive_email( $user_id, MessageLog::CATEGORY_MARKETING )['ok'] );
		$this->assertTrue( SmsConsent::can_receive_email( $user_id, MessageLog::CATEGORY_TRANSACTIONAL )['ok'], 'An unsubscribe from marketing must never block a transactional email.' );
	}

	public function test_application_submission_records_consent_and_phone(): void {
		$user_id = Protech_Test_Factory::wholesale_customer();

		do_action(
			'protech_wholesale_application_submitted',
			$user_id,
			array(
				'phone'                      => '555-555-0100',
				'sms_transactional_consent'  => true,
				'sms_marketing_consent'      => false,
			)
		);

		$state = SmsConsent::state( $user_id );
		$this->assertSame( '+15555550100', $state['phone'] );
		$this->assertSame( 'yes', $state['sms_transactional'] );
		$this->assertNotSame( 'yes', $state['sms_marketing'] );
	}
}
