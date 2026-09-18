<?php
/**
 * Automations: the evaluation windows that decide whether a rule fires
 * for a customer today (the part that stops an automation from either
 * blasting years of history the moment it's enabled, or firing twice
 * for the same order), the dedup guarantee, the tier filter, and
 * validation.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Approval;
use ProtechWholesale\Automations;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\Roles;
use ProtechWholesale\Tiers;

/**
 * Class Test_Automations
 */
class Test_Automations extends WP_UnitTestCase {

	private function backdated_order( int $user_id, int $days_ago ): WC_Order {
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 10 );
		$order->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		$order->save();

		return $order;
	}

	private function reminder_rule( array $overrides = array() ): array {
		$rule = Automations::defaults( Automations::TRIGGER_REORDER_REMINDER );
		$rule['id']      = 'r_test_reminder';
		$rule['name']    = 'Test reminder';
		$rule['enabled'] = true;
		$rule['email']['subject'] = 'Reorder?';
		$rule['email']['body']    = 'Hi {first_name}';

		return array_replace_recursive( $rule, $overrides );
	}

	public function test_reorder_reminder_fires_only_inside_its_window(): void {
		$rule = $this->reminder_rule( array( 'params' => array( 'days' => 30 ) ) );

		$user_29 = Protech_Test_Factory::wholesale_customer();
		$this->backdated_order( $user_29, 29 );
		$snapshot_29 = Automations::snapshot( $user_29 );
		$this->assertNull( Automations::anchor_for( $rule, $snapshot_29, time() )['anchor'], 'Day 29 of a 30-day rule must not fire yet.' );

		$user_30 = Protech_Test_Factory::wholesale_customer();
		$order_30 = $this->backdated_order( $user_30, 30 );
		$snapshot_30 = Automations::snapshot( $user_30 );
		$result_30 = Automations::anchor_for( $rule, $snapshot_30, time() );
		$this->assertSame( 'order:' . $order_30->get_id(), $result_30['anchor'] );

		$user_36 = Protech_Test_Factory::wholesale_customer();
		$this->backdated_order( $user_36, 36 );
		$snapshot_36 = Automations::snapshot( $user_36 );
		$this->assertNotNull( Automations::anchor_for( $rule, $snapshot_36, time() )['anchor'], 'Day 36 is still inside the 7-day catch-up window.' );

		$user_37 = Protech_Test_Factory::wholesale_customer();
		$this->backdated_order( $user_37, 37 );
		$snapshot_37 = Automations::snapshot( $user_37 );
		$this->assertNull( Automations::anchor_for( $rule, $snapshot_37, time() )['anchor'], 'Day 37 is past the catch-up window — enabling the rule must not reach this far back.' );
	}

	public function test_a_newer_order_moves_the_anchor_forward(): void {
		$rule    = $this->reminder_rule( array( 'params' => array( 'days' => 30 ) ) );
		$user_id = Protech_Test_Factory::wholesale_customer();

		$old_order = $this->backdated_order( $user_id, 30 );
		$this->assertSame( 'order:' . $old_order->get_id(), Automations::anchor_for( $rule, Automations::snapshot( $user_id ), time() )['anchor'] );

		// A brand new order supersedes the 30-day-old one as the anchor —
		// "haven't ordered since" is true by construction because the
		// anchor is always the customer's LATEST order.
		$new_order = $this->backdated_order( $user_id, 0 );
		$result    = Automations::anchor_for( $rule, Automations::snapshot( $user_id ), time() );
		$this->assertNull( $result['anchor'], 'The new order is only 0 days old — nowhere near the 30-day window.' );
	}

	public function test_winback_repeats_up_to_max_repeats(): void {
		$rule    = Automations::defaults( Automations::TRIGGER_WINBACK );
		$rule['params'] = array( 'days' => 45, 'max_repeats' => 3 );
		$user_id = Protech_Test_Factory::wholesale_customer();
		$order   = $this->backdated_order( $user_id, 45 );
		$snapshot = Automations::snapshot( $user_id );

		$result = Automations::anchor_for( $rule, $snapshot, time() );
		$this->assertSame( 'order:' . $order->get_id() . ':1', $result['anchor'] );

		$order->set_date_created( time() - 90 * DAY_IN_SECONDS );
		$order->save();
		$snapshot = Automations::snapshot( $user_id );
		$result = Automations::anchor_for( $rule, $snapshot, time() );
		$this->assertSame( 'order:' . $order->get_id() . ':2', $result['anchor'] );

		$order->set_date_created( time() - 4 * 45 * DAY_IN_SECONDS );
		$order->save();
		$snapshot = Automations::snapshot( $user_id );
		$result = Automations::anchor_for( $rule, $snapshot, time() );
		$this->assertNull( $result['anchor'], 'k=4 exceeds max_repeats=3.' );
	}

	public function test_first_order_nudge_skips_customers_who_already_ordered(): void {
		$rule    = Automations::defaults( Automations::TRIGGER_FIRST_ORDER );
		$rule['params'] = array( 'days' => 7 );

		$fresh = Protech_Test_Factory::approved_customer( 7 );
		$result = Automations::anchor_for( $rule, Automations::snapshot( $fresh ), time() );
		$this->assertNotNull( $result['anchor'] );

		$already_ordered = Protech_Test_Factory::approved_customer( 7 );
		$this->backdated_order( $already_ordered, 1 );
		$result = Automations::anchor_for( $rule, Automations::snapshot( $already_ordered ), time() );
		$this->assertNull( $result['anchor'] );
		$this->assertSame( 'has_orders', $result['reason'] );
	}

	public function test_tier_filter_excludes_customers_outside_the_chosen_tiers(): void {
		$rule = $this->reminder_rule( array( 'tiers' => array( Tiers::GOLD ) ) );

		$gold   = Protech_Test_Factory::wholesale_customer();
		Tiers::set_user_tier( $gold, Tiers::GOLD );
		$this->backdated_order( $gold, 30 );

		$bronze = Protech_Test_Factory::wholesale_customer();
		$this->backdated_order( $bronze, 30 );

		$candidates = Automations::candidates( $rule, array( $gold, $bronze ), time(), true );
		$gold_ok    = array_filter( $candidates, static fn( $c ) => $c['user_id'] === $gold );
		$bronze_row = array_values( array_filter( $candidates, static fn( $c ) => $c['user_id'] === $bronze ) );

		$this->assertNotEmpty( array_filter( $gold_ok, static fn( $c ) => $c['ok'] ) );
		$this->assertSame( 'tier', $bronze_row[0]['reason'] );
	}

	public function test_dedup_a_message_is_never_queued_twice_for_the_same_anchor(): void {
		$rule    = $this->reminder_rule();
		$user_id = Protech_Test_Factory::consented_customer();
		$this->backdated_order( $user_id, 30 );

		$first  = Automations::candidates( $rule, array( $user_id ), time() );
		$this->assertNotEmpty( $first );

		foreach ( $first as $candidate ) {
			if ( $candidate['ok'] ) {
				MessageLog::enqueue(
					array(
						'user_id'  => $candidate['user_id'],
						'channel'  => $candidate['channel'],
						'kind'     => MessageLog::KIND_AUTO,
						'category' => MessageLog::CATEGORY_MARKETING,
						'rule_id'  => $rule['id'],
						'anchor'   => $candidate['anchor'],
					)
				);
			}
		}

		$second = Automations::candidates( $rule, array( $user_id ), time() );
		$this->assertEmpty( array_filter( $second, static fn( $c ) => $c['ok'] ), 'Every channel is already queued for this anchor — nothing new should be eligible.' );
	}

	public function test_frequency_cap_blocks_a_second_automated_marketing_message_too_soon(): void {
		update_option( MessagingSettings::OPT_FREQUENCY_CAP_DAYS, '7' );

		$user_id = Protech_Test_Factory::consented_customer();
		MessageLog::enqueue(
			array(
				'user_id'  => $user_id,
				'channel'  => MessageLog::CHANNEL_EMAIL,
				'kind'     => MessageLog::KIND_AUTO,
				'category' => MessageLog::CATEGORY_MARKETING,
				'rule_id'  => 'some_other_rule',
				'anchor'   => 'order:999',
			)
		);

		$rule = $this->reminder_rule();
		$this->backdated_order( $user_id, 30 );

		$candidates = Automations::candidates( $rule, array( $user_id ), time(), true );
		$email_row  = array_values( array_filter( $candidates, static fn( $c ) => 'email' === $c['channel'] ) )[0];

		$this->assertFalse( $email_row['ok'] );
		$this->assertSame( 'frequency_cap', $email_row['reason'] );
	}

	public function test_validate_rejects_out_of_range_days_and_unknown_tags(): void {
		$result = Automations::validate(
			array(
				'trigger' => Automations::TRIGGER_REORDER_REMINDER,
				'name'    => 'Bad rule',
				'channel' => 'email',
				'params'  => array( 'days' => 9999 ),
				'email'   => array( 'subject' => 'Hi', 'body' => 'Track it: {tracking_url}' ),
			)
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 365, $result['rule']['params']['days'], 'An out-of-range value is clamped, not silently accepted.' );
	}

	public function test_approval_timestamp_is_stamped_once_and_never_overwritten(): void {
		$user_id = Protech_Test_Factory::retail_customer();
		$this->assertNull( Approval::approved_at( $user_id ) );

		Roles::grant( $user_id, Roles::CUSTOMER );
		$first = Approval::approved_at( $user_id );
		$this->assertNotNull( $first );

		// A customer un-flagged and later re-approved (revoke() removes the
		// role entirely, so the next grant() really does fire add_role()
		// again) must keep their ORIGINAL approval date, not today's.
		Approval::set_approved_at( $user_id, $first - 1000 );
		Roles::revoke( $user_id, Roles::CUSTOMER );
		Roles::grant( $user_id, Roles::CUSTOMER );

		$this->assertSame( $first - 1000, Approval::approved_at( $user_id ), 'Re-approving an account must not reset an existing approval date.' );
	}

	public function test_backfill_approved_at_uses_application_submission_date(): void {
		// wholesale_customer() itself now stamps an approval date live (see
		// Approval::maybe_stamp_approved_at()) — clear it first to simulate
		// a customer approved before that existed, which is exactly who
		// this one-off migration is for.
		$user_id = Protech_Test_Factory::wholesale_customer();
		delete_user_meta( $user_id, Approval::META_APPROVED_AT );
		update_user_meta( $user_id, '_protech_wholesale_app_submitted_at', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$count = Automations::backfill_approved_at();

		$this->assertGreaterThanOrEqual( 1, $count );
		$this->assertNotNull( Approval::approved_at( $user_id ) );
		$this->assertEqualsWithDelta( time() - 10 * DAY_IN_SECONDS, Approval::approved_at( $user_id ), 5 );
	}
}
