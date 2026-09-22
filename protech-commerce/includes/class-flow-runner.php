<?php
/**
 * Runs a flow one contact at a time: starting a run is an INSERT IGNORE on
 * (flow_id, user_id, anchor), exactly the same idempotency guarantee
 * MessageLog::enqueue() gives every other send, so the same real-world
 * event firing twice (a webhook retried, an order saved twice around the
 * same status change) can never start a second run. advance() walks
 * forward through send/tag/condition steps in one pass and only ever
 * pauses at a `delay` step, which schedules a single Action Scheduler
 * wake and returns; nothing here ever sends synchronously from a
 * customer- or admin-facing request.
 *
 * A step's position is a small path array: `[i]` for a top-level step, or
 * `[i, 'yes'|'no', j]` once a condition at top-level index i has branched
 * — see step_at()/advance_path(). A condition's branches do not rejoin:
 * finishing one completes the run rather than resuming after the
 * condition (see DECISIONS.md).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FlowRunner
 */
class FlowRunner {

	/** A run can loop through this many steps in one advance() call before something is clearly wrong (an empty condition branch, say) rather than legitimately still progressing. */
	private const STEP_GUARD = 200;

	/**
	 * Starts a run for $user_id on $flow_id at $anchor, unless the flow is
	 * disabled, the audience (tier/tag) filter excludes this contact, or a
	 * run for this exact anchor already exists. Returns the new run id, or
	 * 0 for any of those.
	 */
	public static function start( string $flow_id, int $user_id, string $anchor ): int {
		$flow = Flows::get( $flow_id );

		if ( null === $flow || empty( $flow['enabled'] ) || ! self::audience_matches( $flow, $user_id ) ) {
			return 0;
		}

		global $wpdb;
		$table = Flows::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (flow_id, user_id, anchor, status, step_path, started_at) VALUES (%s, %d, %s, %s, '', %s)",
				$flow_id,
				$user_id,
				$anchor,
				Flows::RUN_ACTIVE,
				current_time( 'mysql', true )
			)
		);

		if ( ! (int) $wpdb->rows_affected ) {
			return 0;
		}

		$run_id = (int) $wpdb->insert_id;

		self::advance( $run_id );

		return $run_id;
	}

	/** @param array<string, mixed> $flow */
	private static function audience_matches( array $flow, int $user_id ): bool {
		if ( ! empty( $flow['tiers'] ) && ! in_array( Tiers::get_user_tier( $user_id ), $flow['tiers'], true ) ) {
			return false;
		}

		if ( ! empty( $flow['tags_any'] ) ) {
			$contact_id = Contacts::contact_id_for_user( $user_id );
			$has_any    = false;

			foreach ( $flow['tags_any'] as $tag ) {
				if ( Contacts::has_tag( $contact_id, $tag ) ) {
					$has_any = true;
					break;
				}
			}

			if ( ! $has_any ) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string, mixed>|null */
	public static function get_run( int $run_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Flows::runs_table() . ' WHERE id = %d', $run_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Walks a run forward from its current position: sends, tag changes
	 * and condition branches all happen in the same pass; a `delay` step
	 * schedules a wake and returns; running out of steps (or hitting
	 * `end`) completes the run. Safe to call again on a run that is not
	 * active/waiting (a completed or cancelled run is left alone) and on
	 * one already mid-advance from a concurrent call, since every send
	 * step is itself deduplicated the same way MessageLog::enqueue() dedupes
	 * everything else.
	 */
	public static function advance( int $run_id ): void {
		$run = self::get_run( $run_id );

		if ( null === $run || ! in_array( $run['status'], array( Flows::RUN_ACTIVE, Flows::RUN_WAITING ), true ) ) {
			return;
		}

		$flow = Flows::get( (string) $run['flow_id'] );

		if ( null === $flow || empty( $flow['enabled'] ) ) {
			self::finish( $run_id, Flows::RUN_CANCELLED, 'flow_disabled' );
			return;
		}

		self::mark_active( $run_id );

		$steps = (array) $flow['steps'];
		// A fresh run's path is step 0, not "no path yet": step_at()/advance_path() both read
		// the first element as the top-level index, so it has to be present (`[0]`) rather than
		// implied by an empty array, or a condition's branch merge and a send's own anchor at the
		// very first step silently drop it (see class-flows.php's content_for_run(), which expects
		// the same `[0, 'yes'|'no', j]` shape this produces).
		$path  = '' === $run['step_path'] ? array( 0 ) : explode( '.', (string) $run['step_path'] );
		$guard = 0;

		while ( true ) {
			if ( ++$guard > self::STEP_GUARD ) {
				self::finish( $run_id, Flows::RUN_EXITED, 'step_limit' );
				return;
			}

			$step = self::step_at( $steps, $path );

			if ( null === $step ) {
				self::finish( $run_id, Flows::RUN_COMPLETED, '' );
				return;
			}

			switch ( (string) $step['type'] ) {
				case 'end':
					self::finish( $run_id, Flows::RUN_COMPLETED, '' );
					return;

				case 'delay':
					$wake_at = time() + self::delay_seconds( $step );
					self::wait( $run_id, implode( '.', self::advance_path( $steps, $path ) ), $wake_at );
					self::schedule_wake( $run_id, $wake_at );
					return;

				case 'send_email':
				case 'send_sms':
					self::run_send_step( $run, $flow, $step, $path );
					$path = self::advance_path( $steps, $path );
					break;

				case 'add_tag':
					Contacts::add_tag( Contacts::contact_id_for_user( (int) $run['user_id'] ), (string) $step['tag'] );
					$path = self::advance_path( $steps, $path );
					break;

				case 'remove_tag':
					Contacts::remove_tag( Contacts::contact_id_for_user( (int) $run['user_id'] ), (string) $step['tag'] );
					$path = self::advance_path( $steps, $path );
					break;

				case 'condition':
					$branch = self::evaluate_condition( (array) $step['rule'], $run ) ? 'yes' : 'no';
					$path   = array_merge( $path, array( $branch, 0 ) );
					break;

				default:
					$path = self::advance_path( $steps, $path );
			}
		}
	}

	/**
	 * The step at a path (see the class docblock for the path shape). Public
	 * so Flows::content_for_run() can resolve a queued message row back to
	 * the exact step that queued it, from the path encoded in its anchor.
	 *
	 * @param array<int, array<string, mixed>> $steps
	 * @param array<int, int|string>           $path
	 * @return array<string, mixed>|null
	 */
	public static function step_at( array $steps, array $path ): ?array {
		$top = isset( $path[0] ) ? (int) $path[0] : 0;

		if ( ! isset( $steps[ $top ] ) ) {
			return null;
		}

		if ( count( $path ) <= 1 ) {
			return $steps[ $top ];
		}

		$branch       = (string) $path[1];
		$index        = (int) ( $path[2] ?? 0 );
		$branch_steps = (array) ( $steps[ $top ][ $branch ] ?? array() );

		return $branch_steps[ $index ] ?? null;
	}

	/**
	 * @param array<int, array<string, mixed>> $steps
	 * @param array<int, int|string>           $path
	 * @return array<int, int|string>
	 */
	private static function advance_path( array $steps, array $path ): array {
		$top = isset( $path[0] ) ? (int) $path[0] : 0;

		if ( count( $path ) <= 1 ) {
			return array( $top + 1 );
		}

		$branch = (string) $path[1];
		$index  = (int) ( $path[2] ?? 0 );

		return array( $top, $branch, $index + 1 );
	}

	private static function delay_seconds( array $step ): int {
		$amount = max( 1, (int) ( $step['amount'] ?? 1 ) );

		return 'hours' === ( $step['unit'] ?? 'days' ) ? $amount * HOUR_IN_SECONDS : $amount * DAY_IN_SECONDS;
	}

	/**
	 * @param array<string, mixed> $rule
	 * @param array<string, mixed> $run
	 */
	private static function evaluate_condition( array $rule, array $run ): bool {
		$user_id = (int) $run['user_id'];
		$field   = (string) ( $rule['field'] ?? '' );
		$value   = (string) ( $rule['value'] ?? '' );

		switch ( $field ) {
			case 'is_wholesale':
				return Roles::is_wholesale_customer( $user_id );

			case 'tier':
				return Tiers::get_user_tier( $user_id ) === $value;

			case 'has_tag':
				return Contacts::has_tag( Contacts::contact_id_for_user( $user_id ), sanitize_key( $value ) );

			case 'total_spent_gt':
				return wc_get_customer_total_spent( $user_id ) > (float) $value;

			case 'has_ordered_since_entry':
				$last_order = Reorder::get_last_order_for_user( $user_id );

				if ( ! $last_order instanceof \WC_Order || ! $last_order->get_date_created() ) {
					return false;
				}

				$started = strtotime( $run['started_at'] . ' UTC' );

				return false !== $started && $last_order->get_date_created()->getTimestamp() > $started;

			default:
				return false;
		}
	}

	/**
	 * Queues a send step's message exactly the way Automations::candidates()
	 * queues a rule's: the same consent gate, the same marketing frequency
	 * cap, and the same MessageLog dedup, keyed by this run's anchor plus
	 * the step's own path so a re-run of advance() (a retried wake, a
	 * concurrent worker) can never send the same step twice.
	 *
	 * @param array<string, mixed>   $run
	 * @param array<string, mixed>   $flow
	 * @param array<string, mixed>   $step
	 * @param array<int, int|string> $path
	 */
	private static function run_send_step( array $run, array $flow, array $step, array $path ): void {
		$user_id  = (int) $run['user_id'];
		$category = Flows::category_for( $flow );
		$channel  = 'send_sms' === $step['type'] ? MessageLog::CHANNEL_SMS : MessageLog::CHANNEL_EMAIL;

		$gate = MessageLog::CHANNEL_SMS === $channel
			? SmsConsent::can_receive_sms( $user_id, $category )
			: SmsConsent::can_receive_email( $user_id, $category );

		if ( ! $gate['ok'] ) {
			return;
		}

		if ( MessageLog::CATEGORY_MARKETING === $category ) {
			$cap_days = MessagingSettings::frequency_cap_days();

			if ( $cap_days > 0 ) {
				$last = MessageLog::last_auto_marketing_at( $user_id );

				if ( null !== $last && $last >= time() - $cap_days * DAY_IN_SECONDS ) {
					return;
				}
			}
		}

		$rule_id = 'flow:' . $run['flow_id'];
		$anchor  = $run['anchor'] . ':' . implode( '.', $path );

		if ( MessageLog::exists( $rule_id, $user_id, $anchor, $channel ) ) {
			return;
		}

		$id = MessageLog::enqueue(
			array(
				'user_id'  => $user_id,
				'channel'  => $channel,
				'kind'     => MessageLog::KIND_AUTO,
				'category' => $category,
				'rule_id'  => $rule_id,
				'anchor'   => $anchor,
			)
		);

		if ( $id ) {
			AutomationRunner::schedule_delivery( array( $id ) );
		}
	}

	private static function mark_active( int $run_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Flows::runs_table(), array( 'status' => Flows::RUN_ACTIVE ), array( 'id' => $run_id ) );
	}

	private static function wait( int $run_id, string $step_path, int $wake_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Flows::runs_table(),
			array(
				'status'    => Flows::RUN_WAITING,
				'step_path' => $step_path,
				'wake_at'   => gmdate( 'Y-m-d H:i:s', $wake_at ),
			),
			array( 'id' => $run_id )
		);
	}

	private static function finish( int $run_id, string $status, string $reason ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Flows::runs_table(),
			array(
				'status'      => $status,
				'exit_reason' => $reason,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $run_id )
		);
	}

	private static function schedule_wake( int $run_id, int $wake_at ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( $wake_at, AutomationRunner::HOOK_FLOW_WAKE, array( 'run_id' => $run_id ), AutomationRunner::GROUP, true );
	}

	/** Disabling a flow (EmailsScreen-style toggle) cancels every run still active or waiting on it, rather than letting them keep sending on a flow the admin just turned off. */
	public static function cancel_runs_for_flow( string $flow_id ): int {
		global $wpdb;
		$table = Flows::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, exit_reason = %s, finished_at = %s WHERE flow_id = %s AND status IN (%s,%s)",
				Flows::RUN_CANCELLED,
				'flow_disabled',
				current_time( 'mysql', true ),
				$flow_id,
				Flows::RUN_ACTIVE,
				Flows::RUN_WAITING
			)
		);
	}

	/** @return array<string, int> status => count, for a flow's own admin page. */
	public static function counts_for( string $flow_id ): array {
		global $wpdb;
		$table = Flows::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE flow_id = %s GROUP BY status", $flow_id ), ARRAY_A );

		$counts = array(
			Flows::RUN_ACTIVE    => 0,
			Flows::RUN_WAITING   => 0,
			Flows::RUN_COMPLETED => 0,
			Flows::RUN_EXITED    => 0,
			Flows::RUN_CANCELLED => 0,
		);

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}
}
