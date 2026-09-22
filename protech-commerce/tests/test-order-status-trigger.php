<?php
/**
 * The order-based flow triggers (order_status, order_placed, first_order):
 * an order reaching a matching status on a wholesale order starts a flow
 * run for that order right away (see FlowTriggers::on_order_status_changed()
 * and FlowRunner::start()'s own INSERT IGNORE idempotency) — a run with no
 * delay step queues its send in the same request, but delivery itself still
 * always goes through the Action Scheduler queue, never sent inline.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Flows;
use ProtechWholesale\FlowRunner;
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

	/** @return array<string, mixed> */
	private function save_flow( array $input ): array {
		$result = Flows::validate( $input );
		$this->assertSame( array(), $result['errors'], 'The flow must validate cleanly for this test to mean anything.' );

		return Flows::get( Flows::save( $result['flow'] ) );
	}

	private function shipped_flow(): array {
		return $this->save_flow(
			array(
				'name'    => 'Shipped',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_ORDER_STATUS, 'params' => array( 'status' => 'completed' ) ),
				'steps'   => array(
					array( 'type' => 'send_email', 'email' => array( 'subject' => 'Shipped!', 'heading' => 'On its way', 'body' => 'Order #{order_number}' ) ),
				),
			)
		);
	}

	private function order_placed_flow(): array {
		return $this->save_flow(
			array(
				'name'    => 'Order placed',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_ORDER_PLACED, 'params' => array() ),
				'steps'   => array(
					array( 'type' => 'send_email', 'email' => array( 'subject' => 'Thanks', 'heading' => '', 'body' => 'Thanks for your order' ) ),
				),
			)
		);
	}

	private function first_order_flow(): array {
		return $this->save_flow(
			array(
				'name'    => 'Welcome',
				'enabled' => true,
				'trigger' => array( 'type' => Flows::TRIGGER_FIRST_ORDER, 'params' => array() ),
				'steps'   => array(
					array( 'type' => 'send_email', 'email' => array( 'subject' => 'Welcome', 'heading' => '', 'body' => 'Welcome aboard' ) ),
				),
			)
		);
	}

	/** Puts a wholesale order into $status without going through the meta transition itself. */
	private function wholesale_order( int $user_id, WC_Product $product, int $quantity, string $starting_status = 'pending' ): WC_Order {
		$order = Protech_Test_Factory::order_for( $user_id, $product, $quantity, $starting_status );
		$order->update_meta_data( OrdersAdmin::META_IS_WHOLESALE, 'yes' );
		$order->save();

		return $order;
	}

	public function test_a_wholesale_order_reaching_the_matching_status_starts_the_flow_and_queues_the_send(): void {
		$flow    = $this->shipped_flow();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = $this->wholesale_order( $user_id, $product, 10, 'processing' );

		$order->set_status( 'completed' );
		$order->save();

		$this->assertTrue(
			MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'order:' . $order->get_id() . ':completed:0', MessageLog::CHANNEL_EMAIL ),
			'A step with no delay must be queued in the same request that starts the run.'
		);

		$counts = FlowRunner::counts_for( (string) $flow['id'] );
		$this->assertSame( 1, $counts[ Flows::RUN_COMPLETED ], 'A flow with no delay/wait step finishes as soon as it starts.' );
	}

	public function test_the_same_order_reaching_the_status_twice_never_starts_a_second_run(): void {
		$flow    = $this->shipped_flow();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product( '5.00' );
		$order   = $this->wholesale_order( $user_id, $product, 10, 'processing' );

		$order->set_status( 'completed' );
		$order->save();

		// Simulate the same real-world event firing twice (a retried webhook, a duplicate save).
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'completed', $order );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'completed', $order );

		$counts = FlowRunner::counts_for( (string) $flow['id'] );
		$this->assertSame( 1, array_sum( $counts ), 'INSERT IGNORE on (flow_id, user_id, anchor) must keep this to exactly one run.' );
	}

	public function test_a_retail_order_never_starts_a_run(): void {
		$flow    = $this->shipped_flow();
		$user_id = Protech_Test_Factory::retail_customer();
		$product = Protech_Test_Factory::simple_product();

		Protech_Test_Factory::order_for( $user_id, $product, 1, 'completed' );

		$counts = FlowRunner::counts_for( (string) $flow['id'] );
		$this->assertSame( 0, array_sum( $counts ) );
	}

	public function test_any_wholesale_order_reaching_processing_or_completed_starts_the_order_placed_flow(): void {
		$flow    = $this->order_placed_flow();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product();
		$order   = $this->wholesale_order( $user_id, $product, 1, 'pending' );

		$order->set_status( 'processing' );
		$order->save();

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'order:' . $order->get_id() . ':0', MessageLog::CHANNEL_EMAIL ) );
	}

	public function test_the_first_order_flow_fires_only_for_a_customers_first_paid_order(): void {
		$flow    = $this->first_order_flow();
		$user_id = Protech_Test_Factory::consented_customer();
		$product = Protech_Test_Factory::simple_product();

		$first = $this->wholesale_order( $user_id, $product, 1, 'pending' );
		$first->set_status( 'processing' );
		$first->save();

		$this->assertTrue( MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'order:' . $first->get_id() . ':0', MessageLog::CHANNEL_EMAIL ) );

		$second = $this->wholesale_order( $user_id, $product, 1, 'pending' );
		$second->set_status( 'processing' );
		$second->save();

		$this->assertFalse(
			MessageLog::exists( 'flow:' . $flow['id'], $user_id, 'order:' . $second->get_id() . ':0', MessageLog::CHANNEL_EMAIL ),
			'A second order for the same customer is not their first order.'
		);
	}
}
