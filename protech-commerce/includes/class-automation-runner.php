<?php
/**
 * Everything that touches Action Scheduler: the recurring daily job that
 * evaluates day-based flow triggers, the queue that actually delivers a
 * claimed message-log row, a waiting flow run's delayed wake, and a
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
	public const HOOK_SYNC_CONTACT = 'protech_wholesale_sync_contact';
	public const HOOK_PURGE        = 'protech_wholesale_purge_messages';
	public const HOOK_FLOW_WAKE    = 'protech_wholesale_flow_wake';
	/** An email scheduled to send at a set time (3.10.0). */
	public const HOOK_LAUNCH_CAMPAIGN = 'protech_wholesale_launch_campaign';

	public const BATCH_SIZE = 20;

	private const SELF_HEAL_TRANSIENT = 'protech_wholesale_as_selfheal';

	private const CATCH_UP_TRANSIENT = 'protech_wholesale_scheduled_catchup';

	public function register_hooks(): void {
		add_action( self::HOOK_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( self::HOOK_DELIVER, array( __CLASS__, 'run_deliver' ) );
		add_action( self::HOOK_SYNC_CONTACT, array( __CLASS__, 'run_sync_contact' ) );
		add_action( self::HOOK_PURGE, array( __CLASS__, 'run_purge' ) );
		add_action( self::HOOK_FLOW_WAKE, array( __CLASS__, 'run_flow_wake' ) );
		add_action( self::HOOK_LAUNCH_CAMPAIGN, array( Campaigns::class, 'run_scheduled' ) );

		add_action( 'init', array( __CLASS__, 'self_heal' ), 30 );
		add_action( 'init', array( __CLASS__, 'catch_up_scheduled' ), 31 );

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

		foreach ( array( self::HOOK_DAILY, self::HOOK_DELIVER, self::HOOK_SYNC_CONTACT, self::HOOK_PURGE, self::HOOK_FLOW_WAKE, self::HOOK_LAUNCH_CAMPAIGN ) as $hook ) {
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
	public static function self_heal(): void {
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
	 * Sends a scheduled email at its time. The argument key must stay
	 * `campaign_id`, matching Campaigns::run_scheduled()'s parameter name
	 * (see run_deliver()'s docblock on named arguments).
	 */
	public static function schedule_launch( string $campaign_id, int $send_at ): void {
		if ( ! self::as_available() ) {
			return;
		}

		self::unschedule_launch( $campaign_id );
		as_schedule_single_action( $send_at, self::HOOK_LAUNCH_CAMPAIGN, array( 'campaign_id' => $campaign_id ), self::GROUP );
	}

	public static function unschedule_launch( string $campaign_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_LAUNCH_CAMPAIGN, array( 'campaign_id' => $campaign_id ), self::GROUP );
		}
	}

	/**
	 * WP-Cron only runs when someone visits the site, so a scheduled email
	 * can be late on a quiet or fully cached site. At most every five
	 * minutes, any scheduled email more than five minutes overdue whose
	 * action has gone missing is queued to send now.
	 */
	public static function catch_up_scheduled(): void {
		if ( ! self::as_available() || get_transient( self::CATCH_UP_TRANSIENT ) ) {
			return;
		}

		set_transient( self::CATCH_UP_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		foreach ( Campaigns::overdue( time() ) as $campaign_id ) {
			if ( ! as_has_scheduled_action( self::HOOK_LAUNCH_CAMPAIGN, array( 'campaign_id' => $campaign_id ), self::GROUP ) ) {
				as_schedule_single_action( time(), self::HOOK_LAUNCH_CAMPAIGN, array( 'campaign_id' => $campaign_id ), self::GROUP );
				Logger::info( sprintf( 'Scheduled email %s was overdue; queued to send now.', $campaign_id ) );
			}
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

	public static function schedule_sync_contact( int $user_id ): void {
		if ( ! self::as_available() ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_SYNC_CONTACT, array( 'user_id' => $user_id ), self::GROUP );
	}

	/** "Run automations now" (Messaging → Automations): queues an immediate run of the daily job without waiting for its next scheduled time. Still runs in the background, never inline in the admin request. */
	public static function run_now(): void {
		if ( self::as_available() ) {
			as_enqueue_async_action( self::HOOK_DAILY, array(), self::GROUP );
		}
	}

	/** Delivers a single already-queued row right away, from inside a background action (never from an admin/customer request). */
	public static function deliver_now( int $id ): void {
		self::run_deliver( array( $id ) );
	}

	public static function run_daily(): void {
		$now = time();

		MessageLog::sweep( $now );

		if ( ! MessagingSettings::enabled() ) {
			return;
		}

		$flows = array_merge( Flows::enabled( Flows::TRIGGER_DAYS_SINCE_LAST_ORDER ), Flows::enabled( Flows::TRIGGER_DAYS_SINCE_APPROVAL ) );

		if ( empty( $flows ) ) {
			MessagingSettings::set_last_daily_run( $now );
			return;
		}

		$started = 0;
		$paged   = 1;

		do {
			$query = new \WP_User_Query(
				array(
					'role'   => Roles::CUSTOMER,
					'fields' => 'ID',
					'number' => 200,
					'paged'  => $paged,
				)
			);

			$user_ids  = array_map( 'intval', $query->get_results() );
			$snapshots = array();

			foreach ( $user_ids as $user_id ) {
				$snapshots[ $user_id ] = Automations::snapshot( $user_id );
			}

			$started += FlowTriggers::run_daily_flows( $user_ids, $snapshots, $now );

			++$paged;
		} while ( count( $user_ids ) === 200 );

		foreach ( $flows as $flow ) {
			$stored = Flows::get( (string) $flow['id'] );

			if ( $stored ) {
				$stored['last_run_at'] = $now;
				Flows::save( $stored );
			}
		}

		MessagingSettings::set_last_daily_run( $now );
		Logger::info( sprintf( 'Daily messaging automation run: %d flow run(s) started across %d flow(s).', $started, count( $flows ) ) );
	}

	/**
	 * Parameter name matters: Action Scheduler runs a scheduled action via
	 * do_action_ref_array(), and since PHP 8.1 a callback invoked through
	 * call_user_func_array() with a STRING-keyed args array (schedule_delivery()
	 * schedules with ['ids' => $chunk]) binds by NAME, not position — this
	 * parameter has to be named $ids, not $args, or the call fails outright.
	 *
	 * @param int[] $ids
	 */
	public static function run_deliver( array $ids ): void {
		foreach ( $ids as $id ) {
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

	/** Parameter name must match schedule_sync_contact()'s array key — see run_deliver()'s docblock. */
	public static function run_sync_contact( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! BrevoClient::is_configured() ) {
			return;
		}

		$phone = SmsConsent::phone_for( $user_id );

		if ( '' === $phone ) {
			return;
		}

		( new BrevoClient() )->upsert_contact( $user->user_email, array( 'SMS' => $phone ) );
	}

	public static function run_purge(): void {
		MessageLog::sweep( time() );
	}

	/** A waiting flow run's delay has elapsed; parameter name must match FlowRunner::schedule_wake()'s array key. */
	public static function run_flow_wake( int $run_id ): void {
		FlowRunner::advance( $run_id );
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
