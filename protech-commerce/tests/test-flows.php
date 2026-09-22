<?php
/**
 * Flows (3.6.0): storage/validation, legacy rule import, the run engine
 * (FlowRunner) walking through send/delay/condition/tag steps, and the
 * event-driven and daily triggers (FlowTriggers) that start a run.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Automations;
use ProtechWholesale\Contacts;
use ProtechWholesale\Flows;
use ProtechWholesale\FlowRunner;
use ProtechWholesale\FlowTriggers;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\Roles;

/**
 * Class Test_Flows
 */
class Test_Flows extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( MessagingSettings::OPT_ENABLED, 'yes' );
		// A DDL call in maybe_upgrade() (dbDelta) commits mid-transaction, which can
		// leave a previous test's option writes visible to this one; other Messaging
		// test files already work around it the same way (see test-emails-screen.php).
		delete_option( Flows::OPTION );
		delete_option( Automations::OPTION );
	}

	/** @return array<string, mixed> */
	private function save_flow( array $input ): array {
		$result = Flows::validate( $input );
		$this->assertSame( array(), $result['errors'], 'The flow must validate cleanly for this test to mean anything: ' . implode( ' ', $result['errors'] ) );

		return Flows::get( Flows::save( $result['flow'] ) );
	}

	// -----------------------------------------------------------------
	// Storage and validation.
	// -----------------------------------------------------------------

	public function test_validate_rejects_an_unknown_trigger_a_missing_name_and_no_steps(): void {
		$result = Flows::validate( array( 'name' => '', 'trigger' => array( 'type' => 'nonsense' ), 'steps' => array() ) );

		$this->assertContains( 'Choose a trigger.', $result['errors'] );
		$this->assertContains( 'Give the flow a name.', $result['errors'] );
		$this->assertContains( 'Add at least one step.', $result['errors'] );
	}

	public function test_duplicating_a_flow_copies_it_disabled_with_a_new_id(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Original',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array( array( 'type' => 'add_tag', 'tag' => 'vip' ) ),
			)
		);

		$copy_id = Flows::duplicate( (string) $flow['id'] );
		$copy    = Flows::get( $copy_id );

		$this->assertNotSame( $flow['id'], $copy['id'] );
		$this->assertFalse( $copy['enabled'] );
		$this->assertSame( $flow['steps'], $copy['steps'] );
	}

	// -----------------------------------------------------------------
	// Legacy import.
	// -----------------------------------------------------------------

	private function save_legacy_rule( string $trigger, array $params, array $overrides = array() ): array {
		$rule           = Automations::defaults( $trigger );
		$rule['name']   = $overrides['name'] ?? 'Legacy rule';
		$rule['enabled'] = true;
		$rule['params'] = $params;
		$rule['email']  = array( 'subject' => 'Subject', 'heading' => 'Heading', 'body' => 'Body {order_number}' );

		$id = Automations::save( $rule );

		return Automations::get( $id );
	}

	public function test_a_legacy_reorder_reminder_rule_imports_to_days_since_last_order_with_one_repeat(): void {
		$rule = $this->save_legacy_rule( Automations::TRIGGER_REORDER_REMINDER, array( 'days' => 21 ) );

		$imported = Flows::import_legacy_rules();
		$flow     = Flows::get( (string) $rule['id'] );

		$this->assertSame( 1, $imported );
		$this->assertSame( $rule['id'], $flow['id'], 'The imported flow must keep the rule\'s own id so message-log history still resolves.' );
		$this->assertSame( Flows::TRIGGER_DAYS_SINCE_LAST_ORDER, $flow['trigger']['type'] );
		$this->assertSame( array( 'days' => 21, 'repeats' => 1 ), $flow['trigger']['params'] );
		$this->assertSame( 'send_email', $flow['steps'][0]['type'] );
		$this->assertSame( 'Subject', $flow['steps'][0]['email']['subject'] );
	}

	public function test_a_legacy_winback_rule_imports_with_its_repeat_count(): void {
		$rule = $this->save_legacy_rule( Automations::TRIGGER_WINBACK, array( 'days' => 45, 'max_repeats' => 4 ) );

		Flows::import_legacy_rules();
		$flow = Flows::get( (string) $rule['id'] );

		$this->assertSame( Flows::TRIGGER_DAYS_SINCE_LAST_ORDER, $flow['trigger']['type'] );
		$this->assertSame( array( 'days' => 45, 'repeats' => 4 ), $flow['trigger']['params'] );
	}

	public function test_a_legacy_first_order_nudge_rule_imports_to_days_since_approval(): void {
		$rule = $this->save_legacy_rule( Automations::TRIGGER_FIRST_ORDER, array( 'days' => 5 ) );

		Flows::import_legacy_rules();
		$flow = Flows::get( (string) $rule['id'] );

		$this->assertSame( Flows::TRIGGER_DAYS_SINCE_APPROVAL, $flow['trigger']['type'] );
		$this->assertSame( array( 'days' => 5 ), $flow['trigger']['params'] );
	}

	public function test_a_legacy_order_status_rule_with_a_delay_imports_a_prepended_delay_step(): void {
		$rule = $this->save_legacy_rule( Automations::TRIGGER_ORDER_STATUS, array( 'status' => 'completed', 'delay_minutes' => 120 ) );

		Flows::import_legacy_rules();
		$flow = Flows::get( (string) $rule['id'] );

		$this->assertSame( Flows::TRIGGER_ORDER_STATUS, $flow['trigger']['type'] );
		$this->assertSame( 'completed', $flow['trigger']['params']['status'] );
		$this->assertSame( 'delay', $flow['steps'][0]['type'] );
		$this->assertSame( array( 'amount' => 2, 'unit' => 'hours' ), array( 'amount' => $flow['steps'][0]['amount'], 'unit' => $flow['steps'][0]['unit'] ) );
		$this->assertSame( 'send_email', $flow['steps'][1]['type'] );
	}

	public function test_a_legacy_order_status_rule_with_no_delay_has_no_delay_step(): void {
		$rule = $this->save_legacy_rule( Automations::TRIGGER_ORDER_STATUS, array( 'status' => 'completed', 'delay_minutes' => 0 ) );

		Flows::import_legacy_rules();
		$flow = Flows::get( (string) $rule['id'] );

		$this->assertSame( 'send_email', $flow['steps'][0]['type'] );
	}

	public function test_import_only_ever_runs_once_into_an_empty_flow_list(): void {
		$this->save_legacy_rule( Automations::TRIGGER_REORDER_REMINDER, array( 'days' => 30 ) );

		$this->assertSame( 1, Flows::import_legacy_rules() );

		// A second (interrupted-upgrade-retried) call must not duplicate or
		// overwrite anything, even if a rule was added since the first run.
		$this->save_legacy_rule( Automations::TRIGGER_WINBACK, array( 'days' => 45 ) );
		$this->assertSame( 0, Flows::import_legacy_rules() );
		$this->assertCount( 1, Flows::all() );
	}

	// -----------------------------------------------------------------
	// FlowRunner: starting, dedup, and content resolution.
	// -----------------------------------------------------------------

	public function test_a_run_starts_once_per_anchor(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Once per anchor',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array( array( 'type' => 'add_tag', 'tag' => 'ran' ) ),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();

		$this->assertGreaterThan( 0, FlowRunner::start( (string) $flow['id'], $user_id, 'manual:once' ) );
		$this->assertSame( 0, FlowRunner::start( (string) $flow['id'], $user_id, 'manual:once' ), 'The same anchor must never start a second run.' );
	}

	public function test_content_for_a_flow_run_resolves_the_exact_step_that_queued_it(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Condition test',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array(
					array(
						'type' => 'condition',
						'rule' => array( 'field' => 'is_wholesale', 'value' => '' ),
						'yes'  => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Wholesale path', 'heading' => '', 'body' => 'yes' ) ) ),
						'no'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Retail path', 'heading' => '', 'body' => 'no' ) ) ),
					),
				),
			)
		);

		$yes = Automations::content_for( 'flow:' . $flow['id'], 'manual:5:0.yes.0' );
		$no  = Automations::content_for( 'flow:' . $flow['id'], 'manual:5:0.no.0' );

		$this->assertSame( 'Wholesale path', $yes['email']['subject'] );
		$this->assertSame( 'Retail path', $no['email']['subject'] );
	}

	public function test_a_condition_step_branches_on_is_wholesale_and_queues_the_matching_step(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Condition run',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array(
					array(
						'type' => 'condition',
						'rule' => array( 'field' => 'is_wholesale', 'value' => '' ),
						'yes'  => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Wholesale path', 'heading' => '', 'body' => 'yes' ) ) ),
						'no'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Retail path', 'heading' => '', 'body' => 'no' ) ) ),
					),
				),
			)
		);

		$wholesale_id = Protech_Test_Factory::consented_customer();
		FlowRunner::start( (string) $flow['id'], $wholesale_id, 'manual:' . $wholesale_id );
		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $wholesale_id, 'manual:' . $wholesale_id . ':0.yes.0', MessageLog::CHANNEL_EMAIL ) );

		$retail_id = Protech_Test_Factory::retail_customer();
		FlowRunner::start( (string) $flow['id'], $retail_id, 'manual:' . $retail_id );
		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $retail_id, 'manual:' . $retail_id . ':0.no.0', MessageLog::CHANNEL_EMAIL ) );
	}

	public function test_a_delay_step_waits_and_the_send_after_it_is_queued_only_at_the_wake(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Delay then send',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array(
					array( 'type' => 'delay', 'amount' => 1, 'unit' => 'days' ),
					array( 'type' => 'send_email', 'email' => array( 'subject' => 'After the wait', 'heading' => '', 'body' => 'hi' ) ),
				),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();
		$run_id  = FlowRunner::start( (string) $flow['id'], $user_id, 'manual:' . $user_id );

		$this->assertGreaterThan( 0, $run_id );

		$run = FlowRunner::get_run( $run_id );
		$this->assertSame( Flows::RUN_WAITING, $run['status'] );
		$this->assertNotNull( $run['wake_at'] );
		$this->assertFalse( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'manual:' . $user_id . ':1', MessageLog::CHANNEL_EMAIL ) );

		FlowRunner::advance( $run_id );

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'manual:' . $user_id . ':1', MessageLog::CHANNEL_EMAIL ) );
		$this->assertSame( Flows::RUN_COMPLETED, FlowRunner::get_run( $run_id )['status'] );
	}

	public function test_an_add_tag_step_tags_the_contact(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Tag on entry',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array( array( 'type' => 'add_tag', 'tag' => 'vip' ) ),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();
		FlowRunner::start( (string) $flow['id'], $user_id, 'manual:' . $user_id );

		$this->assertTrue( Contacts::has_tag( Contacts::contact_id_for_user( $user_id ), 'vip' ) );
	}

	public function test_a_flow_restricted_to_a_tag_only_starts_once_the_contact_has_it(): void {
		$flow = $this->save_flow(
			array(
				'name'     => 'Tag restricted',
				'enabled'  => true,
				'trigger'  => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'tags_any' => array( 'vip' ),
				'steps'    => array( array( 'type' => 'add_tag', 'tag' => 'welcomed' ) ),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();

		$this->assertSame( 0, FlowRunner::start( (string) $flow['id'], $user_id, 'manual:a' ), 'No matching tag yet, so the run must not start.' );

		Contacts::add_tag( Contacts::contact_id_for_user( $user_id ), 'vip' );

		$this->assertGreaterThan( 0, FlowRunner::start( (string) $flow['id'], $user_id, 'manual:b' ) );
	}

	public function test_disabling_a_flow_cancels_a_waiting_run_and_a_stale_wake_is_a_no_op(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Cancel me',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_MANUAL, 'params' => array() ),
				'steps'   => array(
					array( 'type' => 'delay', 'amount' => 1, 'unit' => 'days' ),
					array( 'type' => 'send_email', 'email' => array( 'subject' => 'Never sent', 'heading' => '', 'body' => 'hi' ) ),
				),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();
		$run_id  = FlowRunner::start( (string) $flow['id'], $user_id, 'manual:' . $user_id );

		$this->assertSame( Flows::RUN_WAITING, FlowRunner::get_run( $run_id )['status'] );

		FlowRunner::cancel_runs_for_flow( (string) $flow['id'] );
		$this->assertSame( Flows::RUN_CANCELLED, FlowRunner::get_run( $run_id )['status'] );

		FlowRunner::advance( $run_id );
		$this->assertSame( Flows::RUN_CANCELLED, FlowRunner::get_run( $run_id )['status'] );
		$this->assertFalse( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'manual:' . $user_id . ':1', MessageLog::CHANNEL_EMAIL ) );
	}

	// -----------------------------------------------------------------
	// FlowTriggers: event-driven starts.
	// -----------------------------------------------------------------

	public function test_wholesale_approved_starts_when_roles_grant_adds_the_role(): void {
		$flow    = $this->save_flow(
			array(
				'name'    => 'Approved (add_role)',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_WHOLESALE_APPROVED, 'params' => array() ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Approved', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Roles::grant( $user_id, Roles::CUSTOMER );

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'approved:' . $user_id . ':0', MessageLog::CHANNEL_EMAIL ) );
	}

	/**
	 * Roles::grant() is the applicants-queue path and calls WP_User::add_role(), firing
	 * add_user_role — but a manual role change on the profile screen uses set_role(),
	 * firing the different, 3-arg set_user_role instead. Both must start the flow, or
	 * approvals granted by hand would silently never fire it.
	 */
	public function test_wholesale_approved_also_starts_when_the_role_is_set_directly(): void {
		$flow    = $this->save_flow(
			array(
				'name'    => 'Approved (set_role)',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_WHOLESALE_APPROVED, 'params' => array() ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Approved', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		get_userdata( $user_id )->set_role( Roles::CUSTOMER );

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'approved:' . $user_id . ':0', MessageLog::CHANNEL_EMAIL ) );
	}

	public function test_wholesale_approved_does_not_restart_on_a_no_op_role_save(): void {
		$flow    = $this->save_flow(
			array(
				'name'    => 'Approved (no-op)',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_WHOLESALE_APPROVED, 'params' => array() ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Approved', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);
		// Creating a user already granted with the role fires set_user_role once, with no
		// prior roles to compare against, which is a real grant, not a no-op: one run
		// already exists at this point. What this test actually checks is that re-saving
		// the same role afterwards, with the role already present in $old_roles, does not
		// start a second one.
		$user_id = self::factory()->user->create( array( 'role' => Roles::CUSTOMER ) );
		$before  = array_sum( FlowRunner::counts_for( (string) $flow['id'] ) );

		get_userdata( $user_id )->set_role( Roles::CUSTOMER );

		$this->assertSame( $before, array_sum( FlowRunner::counts_for( (string) $flow['id'] ) ) );
	}

	public function test_account_created_starts_on_user_register(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'New account',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_ACCOUNT_CREATED, 'params' => array() ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Welcome', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'account:' . $user_id . ':0', MessageLog::CHANNEL_EMAIL ) );
	}

	public function test_tag_added_starts_only_for_the_matching_tag(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'VIP tagged',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_TAG_ADDED, 'params' => array( 'tag' => 'vip' ) ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Welcome VIP', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);

		$user_id    = Protech_Test_Factory::consented_customer();
		$contact_id = Contacts::contact_id_for_user( $user_id );

		Contacts::add_tag( $contact_id, 'other' );
		$this->assertSame( 0, array_sum( FlowRunner::counts_for( (string) $flow['id'] ) ), 'A different tag must not start this flow.' );

		Contacts::add_tag( $contact_id, 'vip' );
		$this->assertSame( 1, array_sum( FlowRunner::counts_for( (string) $flow['id'] ) ) );
	}

	public function test_contact_subscribed_starts_for_a_contact_linked_to_a_user(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Subscribed',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_CONTACT_SUBSCRIBED, 'params' => array() ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Thanks for subscribing', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);

		$user_id    = Protech_Test_Factory::consented_customer();
		$contact_id = Contacts::contact_id_for_user( $user_id );

		do_action( 'protech_wholesale_contact_subscribed', $contact_id );

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'subscribed:' . $contact_id . ':0', MessageLog::CHANNEL_EMAIL ) );
	}

	// -----------------------------------------------------------------
	// FlowTriggers: the daily job.
	// -----------------------------------------------------------------

	public function test_run_daily_flows_reuses_automations_anchor_for_the_reorder_window(): void {
		$flow = $this->save_flow(
			array(
				'name'    => 'Reorder in 30',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_DAYS_SINCE_LAST_ORDER, 'params' => array( 'days' => 30, 'repeats' => 1 ) ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Reorder', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product();
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 1, 'completed' );

		$now = time();
		$order->set_date_created( $now - 30 * DAY_IN_SECONDS );
		$order->save();

		$started = FlowTriggers::run_daily_flows( array( $user_id ), array( $user_id => Automations::snapshot( $user_id ) ), $now );

		$this->assertSame( 1, $started );
		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'order:' . $order->get_id() . ':0', MessageLog::CHANNEL_EMAIL ) );

		// Running the same day again must not start a second run for the same order.
		$started_again = FlowTriggers::run_daily_flows( array( $user_id ), array( $user_id => Automations::snapshot( $user_id ) ), $now );
		$this->assertSame( 0, $started_again );
	}

	public function test_run_daily_flows_skips_a_customer_outside_the_window(): void {
		$this->save_flow(
			array(
				'name'    => 'Reorder in 30',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_DAYS_SINCE_LAST_ORDER, 'params' => array( 'days' => 30, 'repeats' => 1 ) ),
				'steps'   => array( array( 'type' => 'send_email', 'email' => array( 'subject' => 'Reorder', 'heading' => '', 'body' => 'hi' ) ) ),
			)
		);

		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product();
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 1, 'completed' );

		$now = time();
		$order->set_date_created( $now - 5 * DAY_IN_SECONDS );
		$order->save();

		$started = FlowTriggers::run_daily_flows( array( $user_id ), array( $user_id => Automations::snapshot( $user_id ) ), $now );

		$this->assertSame( 0, $started );
	}
}
