<?php
/**
 * MessageTransport::deliver(): the quiet-hours hold for SMS, which must
 * requeue the row rather than fail, and must never throw regardless of how
 * many arguments a future edit to result() might add or remove.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;
use ProtechWholesale\MessageTransport;
use ProtechWholesale\MessagingSettings;

/**
 * Class Test_Message_Transport
 */
class Test_Message_Transport extends WP_UnitTestCase {

	/** A one-hour quiet-hours window guaranteed to include the current moment, whatever time the suite runs. */
	private function set_quiet_hours_to_cover_now(): void {
		$hour = (int) ( new DateTimeImmutable( '@' . time() ) )->setTimezone( wp_timezone() )->format( 'G' );
		update_option( MessagingSettings::OPT_QUIET_START, (string) $hour );
		update_option( MessagingSettings::OPT_QUIET_END, (string) ( ( $hour + 1 ) % 24 ) );
	}

	public function test_an_sms_in_quiet_hours_is_requeued_not_fatal(): void {
		$this->set_quiet_hours_to_cover_now();
		$user_id = Protech_Test_Factory::consented_customer();

		$log_id = MessageLog::enqueue(
			array(
				'user_id'  => $user_id,
				'channel'  => MessageLog::CHANNEL_SMS,
				// Transactional, not marketing: marketing SMS fails closed on an
				// unverified (no Brevo configured) blacklist check, which would
				// stop this test at the consent gate before ever reaching the
				// quiet-hours branch this test exists to cover.
				'category' => MessageLog::CATEGORY_TRANSACTIONAL,
				'kind'     => MessageLog::KIND_AUTO,
				'rule_id'  => 'r_quiet_test',
				'anchor'   => 'order:1',
			)
		);

		$row = MessageLog::claim( $log_id );
		$this->assertIsArray( $row );

		// Before the fix, result() received 7 arguments for an 8-parameter
		// strict-typed method and this line threw an ArgumentCountError.
		$result = MessageTransport::deliver( $row );

		$this->assertSame( 'requeued', $result['status'] );
		$this->assertSame( 'quiet_hours', $result['reason'] );
		$this->assertFalse( $result['retryable'] );

		$stored = MessageLog::get( $log_id );
		$this->assertSame( MessageLog::STATUS_QUEUED, $stored['status'], 'Requeued means back to queued for the next quiet-hours window, not failed.' );
		$this->assertNotEmpty( $stored['send_after'], 'A send_after in the future is what the daily worker uses to leave it alone until quiet hours end.' );
	}
}
