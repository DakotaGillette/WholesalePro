<?php
/**
 * Everything that touches Action Scheduler: the recurring daily job that
 * evaluates day-based automation rules, the queue that actually delivers
 * a claimed message-log row, the delayed order-status action, and a
 * "self-heal" check that re-schedules the daily job if it's ever missing
 * — which matters here because this plugin updates itself from GitHub
 * releases (see class-updater.php), and a WordPress update never re-runs
 * the activation hook that would otherwise schedule it.
 *
 * WooCommerce is a hard dependency of this plugin (see the `Requires
 * Plugins` header), which guarantees Action Scheduler is present — no
 * feature-detection is needed, only `function_exists()` guards for the
 * rare case a request runs before WooCommerce has fully loaded it.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutomationRunner
 */
class AutomationRunner {

	public const GROUP = 'protech-wholesale';

	public const HOOK_DAILY       = 'protech_wholesale_daily_automations';
	public const HOOK_DELIVER     = 'protech_wholesale_deliver_messages';
	public const HOOK_ORDER_EVENT = 'protech_wholesale_order_event';
	public const HOOK_SYNC_CONTACT = 'protech_wholesale_sync_contact';
	public const HOOK_PURGE        = 'protech_wholesale_purge_messages';

	public const BATCH_SIZE = 20;

	private const SELF_HEAL_TRANSIENT = 'protech_wholesale_as_selfheal';

	public function register_hooks(): void {
		add_action( self::HOOK_DAILY, array( $this, 'run_daily' ) );
		add_action( self::HOOK_DELIVER, array( $this, 'run_deliver' ) );
		add_action( self::HOOK_ORDER_EVENT, array( $this, 'run_order_event' ), 10, 3 );
		add_action( self::HOOK_SYNC_CONTACT, array( $this, 'run_sync_contact' ) );
		add_action( self::HOOK_PURGE, array( $this, 'run_purge' ) );

		add_action( 'init', array( $this, 'self_heal' ), 30 );

		add_action( 'update_option_' . MessagingSettings::OPT_ENABLED, array( __CLASS__, 'on_enabled_changed' ), 10, 2 );
		add_action( 'update_option_' . MessagingSettings::OPT_DAILY_HOUR, array( __CLASS__, 'reschedule_daily' ) );
	}

	private static function as_available(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_enqueue_async_action' );
	}

	public static function next_run_timestamp( int $hour, ?int $now = null ): int {
		$now   = $now ?? time();
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( $hour, 0, 0 );
		$ts    = $today->getTimestamp();

		return $ts > $now ? $ts : $ts + DAY_IN_SECONDS;
	}

	public static function schedule_daily(): void {
		if ( ! self::as_available() || as_has_scheduled_action( self::HOOK_DAILY, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			self::next_run_timestamp( MessagingSettings::daily_hour() ),
			DAY_IN_SECONDS,
			self::HOOK_DAILY,
			array(),
			self::GROUP,
			true
		);

		as_schedule_recurring_action( self::next_run_timestamp( 3 ), DAY_IN_SECONDS, self::HOOK_PURGE, array(), self::GROUP, true );
	}

	public static function reschedule_daily(): void {
		if ( ! self::as_available() ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_DAILY, array(), self::GROUP );

		if ( MessagingSettings::enabled() ) {
			self::schedule_daily();
		}
	}

	public static function unschedule_all(): void {
		if ( ! self::as_available() || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( array( self::HOOK_DAILY, self::HOOK_DELIVER, self::HOOK_ORDER_EVENT, self::HOOK_SYNC_CONTACT, self::HOOK_PURGE ) as $hook ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
	}

	/**
	 * @param string $old
	 * @param string $new
	 */
	public static function on_enabled_changed( $old, $new ): void {
		if ( 'yes' === $new ) {
			self::schedule_daily();
		} else {
			as_unschedule_all_actions( self::HOOK_DAILY, array(), self::GROUP );
		}
	}

	/**
	 * Re-schedules the daily job if it's ever missing while automations
	 * are enabled — covers a GitHub-release update (which never fires
	 * the activation hook) and someone deleting the scheduled action by
	 * hand. Throttled so this check runs at most once an hour.
	 */
	public function self_heal(): void {
		if ( ! MessagingSettings::enabled() || ! self::as_available() ) {
			return;
		}

		if ( get_transient( self::SELF_HEAL_TRANSIENT ) ) {
			return;
		}

		set_transient( self::SELF_HEAL_TRANSIENT, 1, HOUR_IN_SECONDS );

		if ( ! as_has_scheduled_action( self::HOOK_DAILY, array(), self::GROUP ) ) {
			self::schedule_daily();
			Logger::info( 'Re-scheduled the daily messaging automation job (it was missing).' );
		}
	}

	/**
	 * @param int[] $ids
	 */
	public static function schedule_delivery( array $ids ): int {
		if ( empty( $ids ) || ! self::as_available() ) {
			return 0;
		}

		$scheduled = 0;

		foreach ( array_chunk( $ids, self::BATCH_SIZE ) as $i => $chunk ) {
			as_schedule_single_action( time() + $i, self::HOOK_DELIVER, array( 'ids' => $chunk ), self::GROUP );
			++$scheduled;
		}

		return $scheduled;
	}

	public static function schedule_order_event( int $order_id, string $rule_id, string $status, int $delay_seconds ): void {
		if ( ! self::as_available() ) {
			return;
		}

		// $unique = true: collapses a double firing of the same order's
		// transition to the same status into a single scheduled action —
		// WooCommerce (or a payment gateway) can save an order more than
		// once around the same transition.
		as_schedule_single_action(
			time() + max( 0, $delay_seconds ),
			self::HOOK_ORDER_EVENT,
			array( 'order_id' => $order_id, 'rule_id' => $rule_id, 'status' => $status ),
			self::GROUP,
			true
		);
	}

	public static function schedule_sync_contact( int $user_id ): void {
		if ( ! self::as_available() ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_SYNC_CONTACT, array( 'user_id' => $user_id ), self::GROUP );
	}

	/** Delivers a single already-queued row right away, from inside a background action (never from an admin/customer request). */
	public static function deliver_now( int $id ): void {
		self::run_deliver( array( 'ids' => array( $id ) ) );
	}

	public function run_daily(): void {
		$now = time();

		MessageLog::sweep( $now );

		if ( ! MessagingSettings::enabled() ) {
			return;
		}

		$rules = array_filter(
			Automations::enabled(),
			static function ( array $rule ): bool {
				return Automations::TRIGGER_ORDER_STATUS !== ( $rule['trigger'] ?? '' );
			}
		);

		if ( empty( $rules ) ) {
			MessagingSettings::set_last_daily_run( $now );
			return;
		}

		$ids    = array();
		$queued = 0;
		$paged  = 1;

		do {
			$query = new \WP_User_Query(
				array(
					'role'    => Roles::CUSTOMER,
					'fields'  => 'ID',
					'number'  => 200,
					'paged'   => $paged,
				)
			);

			$user_ids  = array_map( 'intval', $query->get_results() );
			$snapshots = array();

			foreach ( $user_ids as $user_id ) {
				$snapshots[ $user_id ] = Automations::snapshot( $user_id );
			}

			foreach ( $rules as $rule ) {
				foreach ( Automations::candidates( $rule, $user_ids, $now, false, $snapshots ) as $candidate ) {
					if ( ! $candidate['ok'] ) {
						continue;
					}

					$id = MessageLog::enqueue(
						array(
							'user_id'  => $candidate['user_id'],
							'channel'  => $candidate['channel'],
							'kind'     => MessageLog::KIND_AUTO,
							'category' => (string) $rule['category'],
							'rule_id'  => (string) $rule['id'],
							'anchor'   => $candidate['anchor'],
						)
					);

					if ( $id ) {
						$ids[] = $id;
						++$queued;
					}
				}
			}

			++$paged;
		} while ( count( $user_ids ) === 200 );

		self::schedule_delivery( $ids );

		foreach ( $rules as $rule ) {
			$stored = Automations::get( (string) $rule['id'] );

			if ( $stored ) {
				$stored['last_run_at'] = $now;
				Automations::save( $stored );
			}
		}

		MessagingSettings::set_last_daily_run( $now );
		Logger::info( sprintf( 'Daily messaging automation run: %d message(s) queued across %d rule(s).', $queued, count( $rules ) ) );
	}

	/**
	 * @param array{ids: int[]} $args
	 */
	public function run_deliver( array $args ): void {
		foreach ( (array) ( $args['ids'] ?? array() ) as $id ) {
			$row = MessageLog::claim( (int) $id );

			if ( null === $row ) {
				continue; // Already claimed/delivered — the idempotency lock did its job.
			}

			$result = MessageTransport::deliver( $row );

			if ( 'requeued' === $result['status'] ) {
				continue; // deliver() already called MessageLog::requeue().
			}

			if ( $result['retryable'] && (int) $row['attempts'] < 3 ) {
				MessageLog::requeue( (int) $id, time() + 5 * MINUTE_IN_SECONDS );
				continue;
			}

			MessageLog::finish(
				(int) $id,
				$result['status'],
				array(
					'provider'    => $result['provider'],
					'provider_id' => $result['provider_id'],
					'recipient'   => $result['recipient'],
					'subject'     => $result['subject'],
					'error'       => $result['error'],
					'reason'      => $result['reason'],
				)
			);

			if ( MessageLog::STATUS_FAILED === $result['status'] ) {
				Logger::error( sprintf( 'Message #%d failed: %s', $id, $result['error'] ) );
			}
		}
	}

	/**
	 * @param array{order_id: int, rule_id: string, status: string} $args
	 */
	public function run_order_event( array $args ): void {
		Automations::fire_order_event( (int) ( $args['order_id'] ?? 0 ), (string) ( $args['rule_id'] ?? '' ), (string) ( $args['status'] ?? '' ) );
	}

	/**
	 * @param array{user_id: int} $args
	 */
	public function run_sync_contact( array $args ): void {
		$user_id = (int) ( $args['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );

		if ( ! $user || ! BrevoClient::is_configured() ) {
			return;
		}

		$phone = SmsConsent::phone_for( $user_id );

		if ( '' === $phone ) {
			return;
		}

		( new BrevoClient() )->upsert_contact( $user->user_email, array( 'SMS' => $phone ) );
	}

	public function run_purge(): void {
		MessageLog::sweep( time() );
	}

	/**
	 * @return array{next_daily_run: int, pending: int, last_run: int}
	 */
	public static function status(): array {
		$next = 0;

		if ( self::as_available() && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK_DAILY, array(), self::GROUP );
			$next = is_numeric( $next ) ? (int) $next : 0;
		}

		return array(
			'next_daily_run' => $next,
			'pending'        => MessageLog::table_exists() ? MessageLog::count_pending() : 0,
			'last_run'       => MessagingSettings::last_daily_run(),
		);
	}
}
