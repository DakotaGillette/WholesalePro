<?php
/**
 * The order_status automation trigger: an order moving to a matching
 * status on a wholesale order schedules a single delayed Action
 * Scheduler action (never sends inline from the checkout/admin
 * request), and firing it re-checks that the order hasn't already
 * moved on to a different status during the delay.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Automations;
use ProtechWholesale\MessageLog;
use ProtechWholesale\MessagingSettings;
use ProtechWholesale\OrdersAdmin;

/**
 * Class Test_Order_Status_Trigger
 */
class Test_Order_Status_Trigger extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( MessagingSettings::OPT_ENABLED, 'yes' );
	}

	private function shipped_rule(): array {
		$rule = Automations::defaults( Automations::TRIGGER_ORDER_STATUS );
		$rule['name']    = 'Shipped';
		$rule['enabled'] = true;
		$rule['params']  = array( 'status' => 'completed', 'delay_minutes' => 10 );
		$rule['email']   = array( 'subject' => 'Shipped!', 'heading' => 'On its way', 'body' => 'Order #{order_number}' );

		// save() assigns a fresh id for any rule it doesn't already know
		// about (the real editor always submits a new rule with an empty
		// id) — read the id back from its return value rather than
		// assuming a caller-chosen one survives.
		$id = Automations::save( $rule );

		return Automations::get( $id );
	}

	public function test_a_wholesale_order_reaching_the_matching_status_schedules_a_delayed_action(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available in this test environment.' );
		}

		$rule    = $this->shipped_rule();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 10, 'processing' );
		$order->update_meta_data( OrdersAdmin::META_IS_WHOLESALE, 'yes' );
		$order->save();

		// WC_Order::save() fires woocommerce_order_status_changed itself
		// when the status actually transitions — no need to fire it by hand.
		$order->set_status( 'completed' );
		$order->save();

		$scheduled = as_next_scheduled_action( \ProtechWholesale\AutomationRunner::HOOK_ORDER_EVENT, array( 'order_id' => $order->get_id(), 'rule_id' => $rule['id'], 'status' => 'completed' ), \ProtechWholesale\AutomationRunner::GROUP );
		$this->assertNotFalse( $scheduled, 'Reaching the matching status must schedule the order-event action.' );
		$this->assertGreaterThan( time() + 9 * MINUTE_IN_SECONDS, $scheduled, 'The delay from the rule must be honored.' );
	}

	public function test_a_retail_order_never_schedules_anything(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available in this test environment.' );
		}

		$this->shipped_rule();
		$user_id = Protech_Test_Factory::retail_customer();
		$product = Protech_Test_Factory::simple_product();
		// order_for() itself transitions the order to $status and saves it,
		// which is what actually fires woocommerce_order_status_changed.
		Protech_Test_Factory::order_for( $user_id, $product, 1, 'completed' );

		$scheduled = as_next_scheduled_action( \ProtechWholesale\AutomationRunner::HOOK_ORDER_EVENT, array(), \ProtechWholesale\AutomationRunner::GROUP );
		$this->assertFalse( $scheduled );
	}

	public function test_fire_order_event_skips_when_the_order_moved_on_during_the_delay(): void {
		$rule    = $this->shipped_rule();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 10, 'refunded' );

		Automations::fire_order_event( $order->get_id(), $rule['id'], 'completed' );

		$this->assertFalse( MessageLog::exists( $rule['id'], $user_id, 'order:' . $order->get_id() . ':completed', MessageLog::CHANNEL_EMAIL ), 'The order is no longer "completed" — nothing should be queued.' );
	}

	public function test_fire_order_event_queues_and_delivers_for_a_matching_order(): void {
		$rule    = $this->shipped_rule();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = Protech_Test_Factory::order_for( $user_id, $product, 10, 'completed' );

		Automations::fire_order_event( $order->get_id(), $rule['id'], 'completed' );

		$this->assertTrue( MessageLog::exists( $rule['id'], $user_id, 'order:' . $order->get_id() . ':completed', MessageLog::CHANNEL_EMAIL ) );
	}
}
