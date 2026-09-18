<?php
/**
 * Automation rules: storage, validation, and the evaluation math shared
 * by the daily job, the "Preview recipients" dry run, and the
 * order-status listener.
 *
 * Each day-based rule fires once per "anchor" — the order (or approval
 * date) it relates to — inside a CATCH_UP_DAYS-wide window starting on
 * the configured day. The anchor itself is what makes a rule safe to
 * turn on for existing customers: it is always tied to a real event
 * (an order id, an approval date), so enabling a 30-day reorder reminder
 * today never reaches back to fire for an order from a year ago (the
 * window has long since closed), and it can never fire twice for the
 * same order (MessageLog's unique key is keyed on the very same anchor).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Automations
 */
class Automations {

	public const OPTION = 'protech_wholesale_automations';

	public const TRIGGER_REORDER_REMINDER = 'reorder_reminder';
	public const TRIGGER_WINBACK          = 'winback';
	public const TRIGGER_FIRST_ORDER      = 'first_order_nudge';
	public const TRIGGER_ORDER_STATUS     = 'order_status';

	public const TRIGGERS = array(
		self::TRIGGER_REORDER_REMINDER,
		self::TRIGGER_WINBACK,
		self::TRIGGER_FIRST_ORDER,
		self::TRIGGER_ORDER_STATUS,
	);

	/** How many days past the configured trigger day a rule still catches a customer up — the safety net against blasting old history when a rule is first enabled, and against a missed cron run. */
	public const CATCH_UP_DAYS = 7;

	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
	}

	/**
	 * @return array<string, array<string, mixed>> id => rule.
	 */
	public static function all(): array {
		$rules = get_option( self::OPTION, array() );

		return is_array( $rules ) ? $rules : array();
	}

	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function enabled( string $trigger = '' ): array {
		return array_filter(
			self::all(),
			static function ( array $rule ) use ( $trigger ): bool {
				return ! empty( $rule['enabled'] ) && ( '' === $trigger || $trigger === ( $rule['trigger'] ?? '' ) );
			}
		);
	}

	public static function category_for( string $trigger ): string {
		return self::TRIGGER_ORDER_STATUS === $trigger ? MessageLog::CATEGORY_TRANSACTIONAL : MessageLog::CATEGORY_MARKETING;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults( string $trigger ): array {
		$params = array();

		switch ( $trigger ) {
			case self::TRIGGER_REORDER_REMINDER:
				$params = array( 'days' => 30 );
				break;
			case self::TRIGGER_WINBACK:
				$params = array( 'days' => 45, 'max_repeats' => 3 );
				break;
			case self::TRIGGER_FIRST_ORDER:
				$params = array( 'days' => 7 );
				break;
			case self::TRIGGER_ORDER_STATUS:
				$params = array( 'status' => 'completed', 'delay_minutes' => 10 );
				break;
		}

		return array(
			'id'       => '',
			'name'     => '',
			'enabled'  => false,
			'trigger'  => $trigger,
			'channel'  => 'email',
			'category' => self::category_for( $trigger ),
			'tiers'    => array(),
			'params'   => $params,
			'email'    => array( 'subject' => '', 'heading' => '', 'body' => '' ),
			'sms'      => array( 'body' => '' ),
		);
	}

	/**
	 * Ready-made rules with real copy, offered as a starting point in the
	 * editor. Never saved automatically — "Start from" copies one into
	 * the (disabled) editor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets(): array {
		$presets = array();

		$reminder                     = self::defaults( self::TRIGGER_REORDER_REMINDER );
		$reminder['name']             = __( 'Reorder reminder (30 days)', 'protech-wholesale' );
		$reminder['email']['subject'] = __( 'Time to restock, {store_name}?', 'protech-wholesale' );
		$reminder['email']['heading'] = __( 'Ready to reorder?', 'protech-wholesale' );
		$reminder['email']['body']    = __( "Hi {first_name},\n\nIt's been about a month since your last order (#{last_order_number}, {last_order_date}). If you're running low, your reorder is one click away.\n\n{last_order_url}", 'protech-wholesale' );
		$reminder['sms']['body']      = __( 'Hi {first_name}, it\'s been about a month since your last order. Ready to restock? {shop_url}', 'protech-wholesale' );
		$presets[ self::TRIGGER_REORDER_REMINDER ] = $reminder;

		$winback                     = self::defaults( self::TRIGGER_WINBACK );
		$winback['name']             = __( 'Win-back (45 days, up to 3 times)', 'protech-wholesale' );
		$winback['email']['subject'] = __( 'We miss you at {brand}', 'protech-wholesale' );
		$winback['email']['heading'] = __( 'It\'s been a while', 'protech-wholesale' );
		$winback['email']['body']    = __( "Hi {first_name},\n\nWe haven't seen an order from {store_name} in a while. Your wholesale pricing is waiting whenever you're ready.\n\n{shop_url}", 'protech-wholesale' );
		$winback['sms']['body']      = __( 'Hi {first_name}, we miss you at {brand}! Your wholesale pricing is ready when you are: {shop_url}', 'protech-wholesale' );
		$presets[ self::TRIGGER_WINBACK ] = $winback;

		$nudge                     = self::defaults( self::TRIGGER_FIRST_ORDER );
		$nudge['name']             = __( 'First-order nudge (7 days)', 'protech-wholesale' );
		$nudge['email']['subject'] = __( 'Welcome to {brand} wholesale — ready for your first order?', 'protech-wholesale' );
		$nudge['email']['heading'] = __( 'Let\'s get your first order in', 'protech-wholesale' );
		$nudge['email']['body']    = __( "Hi {first_name},\n\nYour wholesale account is approved and your pricing is live in the shop. If you have questions about getting started, just reply to this email.\n\n{shop_url}", 'protech-wholesale' );
		$nudge['sms']['body']      = __( 'Hi {first_name}, your {brand} wholesale account is ready! Shop your pricing: {shop_url}', 'protech-wholesale' );
		$presets[ self::TRIGGER_FIRST_ORDER ] = $nudge;

		$shipped                     = self::defaults( self::TRIGGER_ORDER_STATUS );
		$shipped['name']             = __( 'Order shipped', 'protech-wholesale' );
		$shipped['email']['subject'] = __( 'Your order #{order_number} has shipped', 'protech-wholesale' );
		$shipped['email']['heading'] = __( 'On its way!', 'protech-wholesale' );
		$shipped['email']['body']    = __( "Hi {first_name},\n\nYour order #{order_number} is on its way.\n\n{tracking_block}", 'protech-wholesale' );
		$shipped['sms']['body']      = __( 'Your {brand} order #{order_number} has shipped. {tracking_url}', 'protech-wholesale' );
		$presets[ self::TRIGGER_ORDER_STATUS ] = $shipped;

		return $presets;
	}

	/**
	 * @return string[]
	 */
	public static function channels( array $rule ): array {
		$channel = (string) ( $rule['channel'] ?? 'email' );

		if ( 'both' === $channel ) {
			return array( MessageLog::CHANNEL_EMAIL, MessageLog::CHANNEL_SMS );
		}

		return array( in_array( $channel, array( MessageLog::CHANNEL_EMAIL, MessageLog::CHANNEL_SMS ), true ) ? $channel : MessageLog::CHANNEL_EMAIL );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{rule: array<string, mixed>, errors: string[], warnings: string[]}
	 */
	public static function validate( array $input ): array {
		$errors   = array();
		$warnings = array();
		$trigger  = (string) ( $input['trigger'] ?? '' );

		if ( ! in_array( $trigger, self::TRIGGERS, true ) ) {
			$errors[] = __( 'Choose a trigger.', 'protech-wholesale' );
			$trigger  = self::TRIGGER_REORDER_REMINDER;
		}

		$rule = self::defaults( $trigger );

		$rule['id']      = (string) ( $input['id'] ?? '' );
		$rule['name']    = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$rule['enabled'] = ! empty( $input['enabled'] );
		$rule['channel'] = in_array( $input['channel'] ?? '', array( 'email', 'sms', 'both' ), true ) ? $input['channel'] : 'email';
		$rule['category'] = self::category_for( $trigger );

		if ( '' === $rule['name'] ) {
			$errors[] = __( 'Give the rule a name.', 'protech-wholesale' );
		}

		$valid_tiers = array_keys( Tiers::get_tier_labels() );
		$rule['tiers'] = array_values( array_intersect( (array) ( $input['tiers'] ?? array() ), $valid_tiers ) );

		$params = (array) ( $input['params'] ?? array() );

		switch ( $trigger ) {
			case self::TRIGGER_REORDER_REMINDER:
			case self::TRIGGER_WINBACK:
			case self::TRIGGER_FIRST_ORDER:
				$days = (int) ( $params['days'] ?? $rule['params']['days'] );

				if ( $days < 1 || $days > 365 ) {
					$errors[] = __( 'Days must be between 1 and 365.', 'protech-wholesale' );
					$days     = max( 1, min( 365, $days ) );
				}

				$rule['params']['days'] = $days;

				if ( self::TRIGGER_WINBACK === $trigger ) {
					$max_repeats = (int) ( $params['max_repeats'] ?? $rule['params']['max_repeats'] );

					if ( $max_repeats < 1 || $max_repeats > 12 ) {
						$errors[] = __( 'Max repeats must be between 1 and 12.', 'protech-wholesale' );
						$max_repeats = max( 1, min( 12, $max_repeats ) );
					}

					$rule['params']['max_repeats'] = $max_repeats;
				}
				break;

			case self::TRIGGER_ORDER_STATUS:
				$statuses = wc_get_order_statuses();
				$status   = sanitize_key( (string) ( $params['status'] ?? $rule['params']['status'] ) );

				if ( ! isset( $statuses[ 'wc-' . $status ] ) ) {
					$errors[] = __( 'Choose a valid order status.', 'protech-wholesale' );
					$status   = 'completed';
				}

				$delay = (int) ( $params['delay_minutes'] ?? $rule['params']['delay_minutes'] );

				if ( $delay < 0 || $delay > 1440 ) {
					$errors[] = __( 'Delay must be between 0 and 1440 minutes.', 'protech-wholesale' );
					$delay    = max( 0, min( 1440, $delay ) );
				}

				$rule['params']['status']        = $status;
				$rule['params']['delay_minutes'] = $delay;
				break;
		}

		$channels = self::channels( $rule );
		$email_in = (array) ( $input['email'] ?? array() );
		$sms_in   = (array) ( $input['sms'] ?? array() );

		$rule['email']['subject'] = sanitize_text_field( (string) ( $email_in['subject'] ?? '' ) );
		$rule['email']['heading'] = sanitize_text_field( (string) ( $email_in['heading'] ?? '' ) );
		$rule['email']['body']    = wp_kses_post( (string) ( $email_in['body'] ?? '' ) );
		$rule['sms']['body']      = sanitize_textarea_field( (string) ( $sms_in['body'] ?? '' ) );

		if ( strlen( $rule['email']['subject'] ) > 200 ) {
			$errors[] = __( 'Email subject is too long (200 characters max).', 'protech-wholesale' );
		}

		if ( strlen( $rule['sms']['body'] ) > 600 ) {
			$errors[] = __( 'SMS text is too long (600 characters max).', 'protech-wholesale' );
		}

		if ( in_array( 'email', $channels, true ) && '' === trim( $rule['email']['body'] ) ) {
			$errors[] = __( 'Write an email body, or remove email from this rule\'s channel.', 'protech-wholesale' );
		}

		if ( in_array( 'sms', $channels, true ) ) {
			if ( '' === trim( $rule['sms']['body'] ) ) {
				$errors[] = __( 'Write an SMS body, or remove SMS from this rule\'s channel.', 'protech-wholesale' );
			}

			if ( ! BrevoClient::is_configured() ) {
				$warnings[] = __( 'Brevo is not connected — SMS from this rule will fail until it is.', 'protech-wholesale' );
			} elseif ( '' === MessagingSettings::sms_sender() ) {
				$warnings[] = __( 'No SMS sender is set on the Settings tab.', 'protech-wholesale' );
			}

			$segments = MergeTags::sms_segments( MessageTransport::finalize_sms_text( $rule['sms']['body'], $rule['category'] ) );

			if ( $segments['segments'] > 1 ) {
				/* translators: %d: number of SMS segments. */
				$warnings[] = sprintf( __( 'This text will send as %d SMS segments (with the brand prefix and any STOP text added).', 'protech-wholesale' ), $segments['segments'] );
			}
		}

		foreach ( array( 'email', 'sms' ) as $part ) {
			if ( ! in_array( $part, $channels, true ) ) {
				continue;
			}

			$body   = 'email' === $part ? ( $rule['email']['subject'] . ' ' . $rule['email']['heading'] . ' ' . $rule['email']['body'] ) : $rule['sms']['body'];
			$unknown = MergeTags::unknown_tags( $body, $trigger );

			if ( ! empty( $unknown ) ) {
				/* translators: %s: comma-separated list of merge tags. */
				$errors[] = sprintf( __( 'Unknown or unavailable merge tags: %s', 'protech-wholesale' ), implode( ', ', $unknown ) );
			}
		}

		if ( MessageLog::CATEGORY_MARKETING === $rule['category']
			&& in_array( 'email', $channels, true )
			&& ! MessagingSettings::email_footer_enabled()
			&& ! str_contains( $rule['email']['body'], '{unsubscribe_url}' )
		) {
			$warnings[] = __( 'The automatic unsubscribe footer is off and this email has no {unsubscribe_url} tag.', 'protech-wholesale' );
		}

		return array(
			'rule'     => $rule,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string, mixed> $rule Already validated.
	 */
	public static function save( array $rule ): string {
		$rules = self::all();
		$now   = time();

		$id = (string) ( $rule['id'] ?? '' );

		if ( '' === $id || ! isset( $rules[ $id ] ) ) {
			$id             = 'r_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$rule['created_at'] = $now;
		} else {
			$rule['created_at'] = $rules[ $id ]['created_at'] ?? $now;
		}

		$rule['id']         = $id;
		$rule['updated_at'] = $now;
		$rules[ $id ]       = $rule;

		update_option( self::OPTION, $rules, false );

		return $id;
	}

	public static function delete( string $id ): void {
		$rules = self::all();
		unset( $rules[ $id ] );
		update_option( self::OPTION, $rules, false );
	}

	public static function set_enabled( string $id, bool $on ): void {
		$rules = self::all();

		if ( isset( $rules[ $id ] ) ) {
			$rules[ $id ]['enabled'] = $on;
			update_option( self::OPTION, $rules, false );
		}
	}

	/**
	 * A snapshot of everything the evaluation math needs about one
	 * customer: their tier, most recent paid order (if any), lifetime
	 * order count, and approval date.
	 *
	 * @return array{tier: string, last_order: ?\WC_Order, order_count: int, approved_at: ?int}
	 */
	public static function snapshot( int $user_id ): array {
		return array(
			'tier'        => Tiers::get_user_tier( $user_id ),
			'last_order'  => Reorder::get_last_order_for_user( $user_id ),
			'order_count' => wc_get_customer_order_count( $user_id ),
			'approved_at' => Approval::approved_at( $user_id ),
		);
	}

	/**
	 * @param array<string, mixed> $rule
	 * @param array{tier: string, last_order: ?\WC_Order, order_count: int, approved_at: ?int} $snapshot
	 * @return array{anchor: ?string, reason: string}
	 */
	public static function anchor_for( array $rule, array $snapshot, int $now ): array {
		/**
		 * How many days past the configured trigger day a rule still
		 * fires for a customer who first enters its window — absorbs a
		 * missed daily run without ever reaching back into old history.
		 *
		 * @param int $days
		 */
		$catchup = (int) apply_filters( 'protech_wholesale_automation_catchup_days', self::CATCH_UP_DAYS );
		$trigger = (string) ( $rule['trigger'] ?? '' );
		$params  = (array) ( $rule['params'] ?? array() );

		if ( self::TRIGGER_REORDER_REMINDER === $trigger ) {
			$order = $snapshot['last_order'];

			if ( ! $order instanceof \WC_Order || ! $order->get_date_created() ) {
				return array( 'anchor' => null, 'reason' => 'no_orders' );
			}

			$days_since = self::days_since( $order->get_date_created()->getTimestamp(), $now );
			$x          = max( 1, (int) ( $params['days'] ?? 30 ) );

			if ( $days_since < $x || $days_since >= $x + $catchup ) {
				return array( 'anchor' => null, 'reason' => 'window' );
			}

			return array( 'anchor' => 'order:' . $order->get_id(), 'reason' => '' );
		}

		if ( self::TRIGGER_WINBACK === $trigger ) {
			$order = $snapshot['last_order'];

			if ( ! $order instanceof \WC_Order || ! $order->get_date_created() ) {
				return array( 'anchor' => null, 'reason' => 'no_orders' );
			}

			$days_since = self::days_since( $order->get_date_created()->getTimestamp(), $now );
			$n          = max( 1, (int) ( $params['days'] ?? 45 ) );
			$m          = max( 1, (int) ( $params['max_repeats'] ?? 3 ) );
			$k          = intdiv( $days_since, $n );

			if ( $k < 1 || $k > $m || $days_since >= $n * $k + $catchup ) {
				return array( 'anchor' => null, 'reason' => 'window' );
			}

			return array( 'anchor' => 'order:' . $order->get_id() . ':' . $k, 'reason' => '' );
		}

		if ( self::TRIGGER_FIRST_ORDER === $trigger ) {
			if ( $snapshot['order_count'] > 0 ) {
				return array( 'anchor' => null, 'reason' => 'has_orders' );
			}

			$approved_at = $snapshot['approved_at'];

			if ( null === $approved_at ) {
				return array( 'anchor' => null, 'reason' => 'not_approved' );
			}

			$days_since = self::days_since( $approved_at, $now );
			$x          = max( 1, (int) ( $params['days'] ?? 7 ) );

			if ( $days_since < $x || $days_since >= $x + $catchup ) {
				return array( 'anchor' => null, 'reason' => 'window' );
			}

			return array( 'anchor' => 'approval:' . gmdate( 'Y-m-d', $approved_at ), 'reason' => '' );
		}

		return array( 'anchor' => null, 'reason' => 'unsupported_trigger' );
	}

	private static function days_since( int $from_ts, int $now ): int {
		return (int) floor( ( $now - $from_ts ) / DAY_IN_SECONDS );
	}

	/**
	 * The evaluation core, shared by the real daily run and the "Preview
	 * recipients" dry run — the only difference is whether the caller
	 * (AutomationRunner::run_daily()) goes on to actually enqueue()
	 * anything. Never writes to the message log itself.
	 *
	 * @param int[]                              $user_ids
	 * @param array<int, array<string, mixed>>|null $snapshots Pre-built user_id => snapshot() map, to avoid re-querying each customer's last order once per rule when evaluating several rules over the same batch (see AutomationRunner::run_daily()). Built lazily per user when omitted.
	 * @return array<int, array{user_id: int, channel: string, anchor: string, ok: bool, reason: string}>
	 */
	public static function candidates( array $rule, array $user_ids, int $now, bool $with_reasons = false, ?array $snapshots = null ): array {
		$results        = array();
		$channels       = self::channels( $rule );
		$category       = (string) ( $rule['category'] ?? self::category_for( (string) ( $rule['trigger'] ?? '' ) ) );
		$cap_days       = MessagingSettings::frequency_cap_days();
		$cap_seconds    = $cap_days * DAY_IN_SECONDS;

		foreach ( $user_ids as $user_id ) {
			$snapshot = $snapshots[ $user_id ] ?? self::snapshot( $user_id );

			if ( ! empty( $rule['tiers'] ) && ! in_array( $snapshot['tier'], $rule['tiers'], true ) ) {
				if ( $with_reasons ) {
					foreach ( $channels as $channel ) {
						$results[] = array( 'user_id' => $user_id, 'channel' => $channel, 'anchor' => '', 'ok' => false, 'reason' => 'tier' );
					}
				}
				continue;
			}

			$anchor_result = self::anchor_for( $rule, $snapshot, $now );

			if ( null === $anchor_result['anchor'] ) {
				if ( $with_reasons ) {
					foreach ( $channels as $channel ) {
						$results[] = array( 'user_id' => $user_id, 'channel' => $channel, 'anchor' => '', 'ok' => false, 'reason' => $anchor_result['reason'] );
					}
				}
				continue;
			}

			$anchor = $anchor_result['anchor'];

			foreach ( $channels as $channel ) {
				if ( MessageLog::exists( (string) $rule['id'], $user_id, $anchor, $channel ) ) {
					if ( $with_reasons ) {
						$results[] = array( 'user_id' => $user_id, 'channel' => $channel, 'anchor' => $anchor, 'ok' => false, 'reason' => 'already_sent' );
					}
					continue;
				}

				if ( MessageLog::CATEGORY_MARKETING === $category && $cap_seconds > 0 ) {
					$last = MessageLog::last_auto_marketing_at( $user_id );

					if ( null !== $last && $last >= $now - $cap_seconds ) {
						if ( $with_reasons ) {
							$results[] = array( 'user_id' => $user_id, 'channel' => $channel, 'anchor' => $anchor, 'ok' => false, 'reason' => 'frequency_cap' );
						}
						continue;
					}
				}

				$gate = MessageLog::CHANNEL_SMS === $channel
					? SmsConsent::can_receive_sms( $user_id, $category )
					: SmsConsent::can_receive_email( $user_id, $category );

				if ( ! $gate['ok'] ) {
					if ( $with_reasons ) {
						$results[] = array( 'user_id' => $user_id, 'channel' => $channel, 'anchor' => $anchor, 'ok' => false, 'reason' => $gate['reason'] );
					}
					continue;
				}

				$results[] = array( 'user_id' => $user_id, 'channel' => $channel, 'anchor' => $anchor, 'ok' => true, 'reason' => '' );
			}
		}

		return $results;
	}

	/**
	 * Content for a queued row's rule_id: a real automation rule, the
	 * built-in SMS opt-in confirmation, or a manual campaign — one entry
	 * point so MessageTransport::deliver() doesn't need to know which.
	 *
	 * @return array{trigger: string, category: string, email: array{subject:string,heading:string,body:string}, sms: array{body:string}}|null
	 */
	public static function content_for( string $rule_id ): ?array {
		if ( 'sms_optin_confirmation' === $rule_id ) {
			return array(
				'trigger'  => 'sms_optin_confirmation',
				'category' => MessageLog::CATEGORY_TRANSACTIONAL,
				'email'    => array( 'subject' => '', 'heading' => '', 'body' => '' ),
				'sms'      => array(
					'body' => __( "{brand}: you're opted in to wholesale texts. Msg frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.", 'protech-wholesale' ),
				),
			);
		}

		if ( str_starts_with( $rule_id, 'campaign:' ) ) {
			$campaign = Campaigns::get( substr( $rule_id, 9 ) );

			if ( ! $campaign ) {
				return null;
			}

			return array(
				'trigger'  => 'campaign',
				'category' => (string) ( $campaign['category'] ?? MessageLog::CATEGORY_MARKETING ),
				'email'    => (array) ( $campaign['email'] ?? array( 'subject' => '', 'heading' => '', 'body' => '' ) ),
				'sms'      => (array) ( $campaign['sms'] ?? array( 'body' => '' ) ),
			);
		}

		$rule = self::get( $rule_id );

		if ( ! $rule ) {
			return null;
		}

		return array(
			'trigger'  => (string) ( $rule['trigger'] ?? '' ),
			'category' => (string) ( $rule['category'] ?? self::category_for( (string) ( $rule['trigger'] ?? '' ) ) ),
			'email'    => (array) ( $rule['email'] ?? array( 'subject' => '', 'heading' => '', 'body' => '' ) ),
			'sms'      => (array) ( $rule['sms'] ?? array( 'body' => '' ) ),
		);
	}

	/**
	 * Queues an order-status rule's message for delayed delivery. The
	 * admin/checkout request does nothing more than schedule this single
	 * Action Scheduler call — no Brevo call ever happens here.
	 *
	 * @param int|string $order_id
	 * @param string     $from
	 * @param string     $to
	 * @param \WC_Order  $order
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! MessagingSettings::enabled() || ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! OrdersAdmin::is_wholesale_order( $order ) || ! $order->get_customer_id() ) {
			return;
		}

		foreach ( self::enabled( self::TRIGGER_ORDER_STATUS ) as $rule ) {
			if ( ( $rule['params']['status'] ?? '' ) !== $to ) {
				continue;
			}

			$delay = max( 0, (int) ( $rule['params']['delay_minutes'] ?? 0 ) ) * MINUTE_IN_SECONDS;

			AutomationRunner::schedule_order_event( (int) $order->get_id(), (string) $rule['id'], $to, $delay );
		}
	}

	/**
	 * Runs the actual order-status delivery once its delay has elapsed
	 * (called by AutomationRunner::run_order_event()). Skips quietly if
	 * the order moved to a different status during the delay, or the
	 * rule was disabled/deleted in the meantime.
	 */
	public static function fire_order_event( int $order_id, string $rule_id, string $status ): void {
		$order = wc_get_order( $order_id );
		$rule  = self::get( $rule_id );

		if ( ! $order instanceof \WC_Order || ! $rule || empty( $rule['enabled'] ) || $order->get_status() !== $status ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();

		if ( ! $user_id ) {
			return;
		}

		if ( ! empty( $rule['tiers'] ) && ! in_array( Tiers::get_user_tier( $user_id ), $rule['tiers'], true ) ) {
			return;
		}

		$anchor = 'order:' . $order_id . ':' . $status;
		$ids    = array();

		foreach ( self::channels( $rule ) as $channel ) {
			if ( MessageLog::exists( $rule_id, $user_id, $anchor, $channel ) ) {
				continue;
			}

			$gate = MessageLog::CHANNEL_SMS === $channel
				? SmsConsent::can_receive_sms( $user_id, MessageLog::CATEGORY_TRANSACTIONAL )
				: SmsConsent::can_receive_email( $user_id, MessageLog::CATEGORY_TRANSACTIONAL );

			if ( ! $gate['ok'] ) {
				continue;
			}

			$id = MessageLog::enqueue(
				array(
					'user_id'  => $user_id,
					'channel'  => $channel,
					'kind'     => MessageLog::KIND_AUTO,
					'category' => MessageLog::CATEGORY_TRANSACTIONAL,
					'rule_id'  => $rule_id,
					'anchor'   => $anchor,
				)
			);

			if ( $id ) {
				$ids[] = $id;
			}
		}

		if ( $ids ) {
			// Already inside a background Action Scheduler action, and at
			// most two rows (email + sms) — deliver right away rather than
			// scheduling yet another hop.
			foreach ( $ids as $id ) {
				AutomationRunner::deliver_now( $id );
			}
		}
	}

	/**
	 * One-off migration (Plugin::maybe_upgrade(), DB_VERSION 3): every
	 * existing wholesale customer gets an approval timestamp so the
	 * first-order nudge has something to measure from. From here on the
	 * timestamp is stamped live when the role is granted (see
	 * Approval::stamp_approved_at()) — this only backfills history.
	 */
	public static function backfill_approved_at(): int {
		$user_ids = get_users(
			array(
				'role'   => Roles::CUSTOMER,
				'fields' => 'ID',
			)
		);

		$count = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			if ( null !== Approval::approved_at( $user_id ) ) {
				continue;
			}

			$submitted = (string) get_user_meta( $user_id, '_protech_wholesale_app_submitted_at', true );
			$timestamp = '' !== $submitted ? strtotime( get_gmt_from_date( $submitted ) . ' UTC' ) : false;

			if ( false === $timestamp ) {
				$user      = get_userdata( $user_id );
				$timestamp = $user ? strtotime( $user->user_registered . ' UTC' ) : time();
			}

			Approval::set_approved_at( $user_id, (int) $timestamp );
			++$count;
		}

		return $count;
	}
}
