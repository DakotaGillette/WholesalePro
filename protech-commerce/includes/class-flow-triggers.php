<?php
/**
 * Where a flow run actually starts from: the live, event-driven triggers
 * (an order reaching a status, an account approved, a signup confirmed, a
 * tag added, an account created) each start a run the moment their real
 * WordPress/WooCommerce hook fires, and the two day-based triggers
 * (days_since_last_order, days_since_approval) are evaluated once a day
 * over every wholesale customer by run_daily_flows(), reusing
 * Automations::anchor_for()'s exact catch-up-window math so a flow
 * imported from an old rule keeps behaving the same way day to day.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FlowTriggers
 */
class FlowTriggers {

	/** Order statuses treated as "paid", for order_placed and first_order. */
	private const PAID_STATUSES = array( 'processing', 'completed' );

	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 20, 4 );

		// Roles::grant() (the applicants queue) calls WP_User::add_role(), which fires
		// add_user_role (2 args, no $old_roles); a manual role change on the profile screen
		// uses set_role(), firing set_user_role (3 args) instead. Approval::register_hooks()
		// already has to hook both for the same reason — see its own comment.
		add_action( 'add_user_role', array( $this, 'on_role_added' ), 10, 2 );
		add_action( 'set_user_role', array( $this, 'on_role_set' ), 10, 3 );

		add_action( 'user_register', array( $this, 'on_account_created' ) );
		add_action( 'protech_wholesale_contact_subscribed', array( $this, 'on_contact_subscribed' ) );
		add_action( 'protech_wholesale_contact_tag_added', array( $this, 'on_tag_added' ), 10, 2 );
	}

	/**
	 * Untyped params, matching Contacts::refresh_order_stats() on this same hook (see its
	 * own docblock for why: with strict_types on, a type hint here would fatal if WooCommerce
	 * ever passed something unexpected).
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! MessagingSettings::enabled() || ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! OrdersAdmin::is_wholesale_order( $order ) || ! $order->get_customer_id() ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();

		foreach ( Flows::enabled( Flows::TRIGGER_ORDER_STATUS ) as $flow ) {
			if ( ( $flow['trigger']['params']['status'] ?? '' ) !== $to ) {
				continue;
			}

			FlowRunner::start( (string) $flow['id'], $user_id, 'order:' . $order_id . ':' . $to );
		}

		if ( ! in_array( (string) $to, self::PAID_STATUSES, true ) ) {
			return;
		}

		foreach ( Flows::enabled( Flows::TRIGGER_ORDER_PLACED ) as $flow ) {
			FlowRunner::start( (string) $flow['id'], $user_id, 'order:' . $order_id );
		}

		// wc_get_customer_order_count() reflects this very transition (the order has already
		// been saved by the time this hook fires), so <= 1 here means this order is the first.
		if ( wc_get_customer_order_count( $user_id ) <= 1 ) {
			foreach ( Flows::enabled( Flows::TRIGGER_FIRST_ORDER ) as $flow ) {
				FlowRunner::start( (string) $flow['id'], $user_id, 'order:' . $order_id );
			}
		}
	}

	/** add_user_role fires when Roles::grant() (the applicants queue) calls WP_User::add_role() — the role is always newly granted here, so there is no "already had it" case to filter out. */
	public function on_role_added( int $user_id, string $role ): void {
		$this->maybe_start_approved_flows( $user_id, $role );
	}

	/**
	 * set_user_role fires for a manual role change (Users → Edit); $old_roles
	 * lets a re-save of the same role (no real change) be told apart from a
	 * genuine grant, which add_user_role never needs since it is only ever
	 * called to add a role the account did not already have.
	 *
	 * @param int      $user_id
	 * @param string   $role
	 * @param string[] $old_roles
	 */
	public function on_role_set( $user_id, $role, $old_roles ): void {
		if ( in_array( Roles::CUSTOMER, (array) $old_roles, true ) ) {
			return;
		}

		$this->maybe_start_approved_flows( (int) $user_id, (string) $role );
	}

	private function maybe_start_approved_flows( int $user_id, string $role ): void {
		if ( Roles::CUSTOMER !== $role ) {
			return;
		}

		foreach ( Flows::enabled( Flows::TRIGGER_WHOLESALE_APPROVED ) as $flow ) {
			FlowRunner::start( (string) $flow['id'], $user_id, 'approved:' . $user_id );
		}
	}

	public function on_account_created( int $user_id ): void {
		foreach ( Flows::enabled( Flows::TRIGGER_ACCOUNT_CREATED ) as $flow ) {
			FlowRunner::start( (string) $flow['id'], $user_id, 'account:' . $user_id );
		}
	}

	/**
	 * A guest contact (no linked WordPress account) cannot be enrolled yet:
	 * flows still target the same wholesale-user audience Automations
	 * always did (see DECISIONS.md — the audience layer over contacts is
	 * not built yet).
	 */
	public function on_contact_subscribed( int $contact_id ): void {
		$contact = Contacts::get( $contact_id );

		if ( null === $contact || (int) $contact['user_id'] <= 0 ) {
			return;
		}

		foreach ( Flows::enabled( Flows::TRIGGER_CONTACT_SUBSCRIBED ) as $flow ) {
			FlowRunner::start( (string) $flow['id'], (int) $contact['user_id'], 'subscribed:' . $contact_id );
		}
	}

	public function on_tag_added( int $contact_id, string $tag ): void {
		$contact = Contacts::get( $contact_id );

		if ( null === $contact || (int) $contact['user_id'] <= 0 ) {
			return;
		}

		foreach ( Flows::enabled( Flows::TRIGGER_TAG_ADDED ) as $flow ) {
			if ( ( $flow['trigger']['params']['tag'] ?? '' ) !== $tag ) {
				continue;
			}

			FlowRunner::start( (string) $flow['id'], (int) $contact['user_id'], 'tag:' . $tag . ':' . time() );
		}
	}

	/**
	 * The two day-based triggers, evaluated once a day (AutomationRunner::run_daily(), the
	 * same heartbeat Automations always used) over every wholesale customer. Reuses
	 * Automations::anchor_for() by building the same legacy-shaped rule array it expects,
	 * so the exact catch-up-window math a rule already relied on before being imported to a
	 * flow (see Flows::import_legacy_rules()) is not re-implemented, and cannot drift from it.
	 *
	 * @param int[]                                  $user_ids
	 * @param array<int, array<string, mixed>>       $snapshots
	 * @return int How many runs were started.
	 */
	public static function run_daily_flows( array $user_ids, array $snapshots, int $now ): int {
		$started = 0;

		foreach ( array_merge( Flows::enabled( Flows::TRIGGER_DAYS_SINCE_LAST_ORDER ), Flows::enabled( Flows::TRIGGER_DAYS_SINCE_APPROVAL ) ) as $flow ) {
			$legacy_rule = self::legacy_rule_shape( $flow );

			foreach ( $user_ids as $user_id ) {
				$snapshot = $snapshots[ $user_id ] ?? Automations::snapshot( $user_id );
				$result   = Automations::anchor_for( $legacy_rule, $snapshot, $now );

				if ( null === $result['anchor'] ) {
					continue;
				}

				if ( FlowRunner::start( (string) $flow['id'], $user_id, (string) $result['anchor'] ) ) {
					++$started;
				}
			}
		}

		return $started;
	}

	/** @return array<string, mixed> Just enough of Automations' rule shape for anchor_for() to evaluate a day-based flow's trigger. */
	private static function legacy_rule_shape( array $flow ): array {
		$type   = (string) ( $flow['trigger']['type'] ?? '' );
		$params = (array) ( $flow['trigger']['params'] ?? array() );

		if ( Flows::TRIGGER_DAYS_SINCE_APPROVAL === $type ) {
			return array(
				'trigger' => Automations::TRIGGER_FIRST_ORDER,
				'params'  => array( 'days' => (int) ( $params['days'] ?? 7 ) ),
			);
		}

		$repeats = max( 1, (int) ( $params['repeats'] ?? 1 ) );

		return array(
			'trigger' => 1 === $repeats ? Automations::TRIGGER_REORDER_REMINDER : Automations::TRIGGER_WINBACK,
			'params'  => array( 'days' => (int) ( $params['days'] ?? 30 ), 'max_repeats' => $repeats ),
		);
	}
}
