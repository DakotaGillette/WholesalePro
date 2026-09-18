<?php
/**
 * MessageLog: the two locks that make delivery idempotent under Action
 * Scheduler retries (enqueue()'s INSERT IGNORE, claim()'s conditional
 * UPDATE), and sweep()'s recovery of stuck/expired rows.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\MessageLog;

/**
 * Class Test_Message_Log
 */
class Test_Message_Log extends WP_UnitTestCase {

	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'user_id'  => 1,
				'channel'  => MessageLog::CHANNEL_EMAIL,
				'kind'     => MessageLog::KIND_AUTO,
				'category' => MessageLog::CATEGORY_MARKETING,
				'rule_id'  => 'r_test',
				'anchor'   => 'order:1',
			),
			$overrides
		);
	}

	public function test_table_exists_after_install(): void {
		$this->assertTrue( MessageLog::table_exists() );
	}

	public function test_enqueue_is_idempotent_on_the_same_rule_user_anchor_channel(): void {
		$id1 = MessageLog::enqueue( $this->row() );
		$id2 = MessageLog::enqueue( $this->row() );

		$this->assertGreaterThan( 0, $id1 );
		$this->assertSame( 0, $id2, 'A duplicate (rule, user, anchor, channel) must be silently ignored, not inserted again.' );

		$this->assertTrue( MessageLog::exists( 'r_test', 1, 'order:1', MessageLog::CHANNEL_EMAIL ) );
		$this->assertFalse( MessageLog::exists( 'r_test', 1, 'order:1', MessageLog::CHANNEL_SMS ), 'A different channel is a different row.' );
	}

	public function test_claim_can_only_succeed_once(): void {
		$id = MessageLog::enqueue( $this->row() );

		$claimed      = MessageLog::claim( $id );
		$claimed_again = MessageLog::claim( $id );

		$this->assertIsArray( $claimed );
		$this->assertSame( MessageLog::STATUS_SENDING, $claimed['status'] );
		$this->assertNull( $claimed_again, 'A row already claimed (sending) cannot be claimed a second time.' );
	}

	public function test_a_future_send_after_blocks_claim_until_due(): void {
		$id = MessageLog::enqueue( $this->row( array( 'send_after' => time() + HOUR_IN_SECONDS ) ) );

		$this->assertNull( MessageLog::claim( $id ) );

		MessageLog::requeue( $id, time() - 1 );
		$this->assertIsArray( MessageLog::claim( $id ) );
	}

	public function test_finish_records_the_outcome(): void {
		$id = MessageLog::enqueue( $this->row() );
		MessageLog::claim( $id );
		MessageLog::finish( $id, MessageLog::STATUS_SENT, array( 'provider' => 'brevo', 'provider_id' => 'msg-1' ) );

		$row = MessageLog::get( $id );
		$this->assertSame( MessageLog::STATUS_SENT, $row['status'] );
		$this->assertSame( 'msg-1', $row['provider_id'] );
		$this->assertNotEmpty( $row['sent_at'] );
	}

	public function test_sweep_resets_stuck_sending_rows_and_expires_old_queued_rows(): void {
		global $wpdb;
		$table = MessageLog::table();

		$stuck_id = MessageLog::enqueue( $this->row( array( 'anchor' => 'order:2' ) ) );
		MessageLog::claim( $stuck_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ), array( 'id' => $stuck_id ) );

		$expired_id = MessageLog::enqueue( $this->row( array( 'anchor' => 'order:3' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'id' => $expired_id ) );

		$result = MessageLog::sweep( time() );

		$this->assertSame( MessageLog::STATUS_QUEUED, MessageLog::get( $stuck_id )['status'], 'A row stuck "sending" for over an hour is reset to queued for another attempt.' );
		$this->assertSame( MessageLog::STATUS_FAILED, MessageLog::get( $expired_id )['status'], 'A row queued for over a day is expired rather than sent stale.' );
		$this->assertSame( 'expired', MessageLog::get( $expired_id )['reason'] );
	}

	public function test_last_auto_marketing_at_only_counts_automated_marketing_rows(): void {
		MessageLog::enqueue( $this->row( array( 'kind' => MessageLog::KIND_MANUAL, 'anchor' => 'a' ) ) );
		MessageLog::enqueue( $this->row( array( 'category' => MessageLog::CATEGORY_TRANSACTIONAL, 'anchor' => 'b' ) ) );

		$this->assertNull( MessageLog::last_auto_marketing_at( 1 ) );

		MessageLog::enqueue( $this->row( array( 'anchor' => 'c' ) ) );
		$this->assertNotNull( MessageLog::last_auto_marketing_at( 1 ) );
	}
}
