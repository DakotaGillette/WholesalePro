<?php
/**
 * Multi-step automations: a trigger, an optional tier/tag filter, and a
 * vertical list of steps (send an email or text, wait, add/remove a tag,
 * or branch one level on a condition). Storage mirrors Automations (an
 * option, not a post type) and every send still goes through the same
 * consent gate, frequency cap and MessageLog dedup Automations always has
 * — see FlowRunner, which is the only thing that reads a flow's steps.
 *
 * Replaces Automations as of 3.6.0 (FlowRunner::import_legacy_rules()
 * converts each existing rule to a flow under the same id, so its message
 * log history still resolves); the rule editor is gone, but Automations
 * itself is untouched so nothing here needs to fight it for the option.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Flows
 */
class Flows {

	public const OPTION     = 'protech_wholesale_flows';
	public const MAX_STORED = 100;

	public const TRIGGER_DAYS_SINCE_LAST_ORDER = 'days_since_last_order';
	public const TRIGGER_DAYS_SINCE_APPROVAL   = 'days_since_approval';
	public const TRIGGER_ORDER_STATUS          = 'order_status';
	public const TRIGGER_ORDER_PLACED          = 'order_placed';
	public const TRIGGER_FIRST_ORDER           = 'first_order';
	public const TRIGGER_WHOLESALE_APPROVED    = 'wholesale_approved';
	public const TRIGGER_CONTACT_SUBSCRIBED    = 'contact_subscribed';
	public const TRIGGER_ACCOUNT_CREATED       = 'account_created';
	public const TRIGGER_TAG_ADDED             = 'tag_added';
	public const TRIGGER_MANUAL                = 'manual';

	/** Evaluated once a day over every wholesale customer, the same way Automations always has been (see Automations::anchor_for()). */
	public const DAILY_TRIGGERS = array( self::TRIGGER_DAYS_SINCE_LAST_ORDER, self::TRIGGER_DAYS_SINCE_APPROVAL );

	public const TRIGGERS = array(
		self::TRIGGER_DAYS_SINCE_LAST_ORDER,
		self::TRIGGER_DAYS_SINCE_APPROVAL,
		self::TRIGGER_ORDER_STATUS,
		self::TRIGGER_ORDER_PLACED,
		self::TRIGGER_FIRST_ORDER,
		self::TRIGGER_WHOLESALE_APPROVED,
		self::TRIGGER_CONTACT_SUBSCRIBED,
		self::TRIGGER_ACCOUNT_CREATED,
		self::TRIGGER_TAG_ADDED,
		self::TRIGGER_MANUAL,
	);

	public const STEP_TYPES = array( 'send_email', 'send_sms', 'delay', 'condition', 'add_tag', 'remove_tag', 'end' );

	public const CONDITION_FIELDS = array( 'is_wholesale', 'tier', 'has_tag', 'total_spent_gt', 'has_ordered_since_entry' );

	public const RUNS_TABLE = 'protech_wholesale_flow_runs';

	public const RUN_ACTIVE    = 'active';
	public const RUN_WAITING   = 'waiting';
	public const RUN_COMPLETED = 'completed';
	public const RUN_EXITED    = 'exited';
	public const RUN_CANCELLED = 'cancelled';

	public static function runs_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::RUNS_TABLE;
	}

	public static function install_runs_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::runs_table();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				flow_id varchar(20) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				anchor varchar(64) NOT NULL DEFAULT '',
				status varchar(12) NOT NULL DEFAULT 'active',
				step_path varchar(40) NOT NULL DEFAULT '',
				started_at datetime NOT NULL,
				finished_at datetime NULL,
				wake_at datetime NULL,
				exit_reason varchar(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY flow_user_anchor (flow_id,user_id,anchor),
				KEY status_wake (status,wake_at)
			) {$charset_collate};"
		);

		do_action( 'protech_wholesale_flow_runs_table_installed' );
	}

	// -----------------------------------------------------------------
	// Storage (mirrors Automations/EmailTemplates: an option, not a post type).
	// -----------------------------------------------------------------

	/** @return array<string, array<string, mixed>> id => flow. */
	public static function all(): array {
		$flows = get_option( self::OPTION, array() );

		return is_array( $flows ) ? $flows : array();
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/** @return array<string, array<string, mixed>> Only enabled flows, optionally filtered to one trigger type. */
	public static function enabled( string $trigger = '' ): array {
		return array_filter(
			self::all(),
			static function ( array $flow ) use ( $trigger ): bool {
				return ! empty( $flow['enabled'] ) && ( '' === $trigger || $trigger === ( $flow['trigger']['type'] ?? '' ) );
			}
		);
	}

	/** @return array<string, mixed> */
	public static function defaults( string $trigger = self::TRIGGER_ORDER_STATUS ): array {
		return array(
			'id'          => '',
			'name'        => '',
			'enabled'     => false,
			'trigger'     => array( 'type' => $trigger, 'params' => self::default_trigger_params( $trigger ) ),
			'tiers'       => array(),
			'tags_any'    => array(),
			'steps'       => array(),
			'created_at'  => 0,
			'updated_at'  => 0,
			'last_run_at' => 0,
		);
	}

	/** @return array<string, mixed> */
	public static function default_trigger_params( string $trigger ): array {
		switch ( $trigger ) {
			case self::TRIGGER_DAYS_SINCE_LAST_ORDER:
				return array( 'days' => 30, 'repeats' => 1 );
			case self::TRIGGER_DAYS_SINCE_APPROVAL:
				return array( 'days' => 7 );
			case self::TRIGGER_ORDER_STATUS:
				return array( 'status' => 'completed' );
			default:
				return array();
		}
	}

	public static function category_for( array $flow ): string {
		return self::TRIGGER_ORDER_STATUS === ( $flow['trigger']['type'] ?? '' ) || self::TRIGGER_ORDER_PLACED === ( $flow['trigger']['type'] ?? '' )
			? MessageLog::CATEGORY_TRANSACTIONAL
			: MessageLog::CATEGORY_MARKETING;
	}

	/**
	 * Content for a flow's queued message row: a flow's rule_id names only
	 * the flow ("flow:<id>"), never the step, since one flow can have many
	 * send steps — the step path travels in the row's anchor instead (see
	 * FlowRunner::run_send_step()), as the part after the run's own anchor's
	 * last colon (a run anchor like "order:123:completed" always has one;
	 * the step path never does, since it is dot-joined).
	 *
	 * @return array{trigger: string, category: string, email: array<string, mixed>, sms: array<string, mixed>}|null
	 */
	public static function content_for_run( string $flow_id, string $anchor ): ?array {
		$flow = self::get( $flow_id );

		if ( null === $flow ) {
			return null;
		}

		$colon_at = strrpos( $anchor, ':' );
		$path_str = false === $colon_at ? $anchor : substr( $anchor, $colon_at + 1 );
		$path     = '' === $path_str ? array() : explode( '.', $path_str );
		$step     = FlowRunner::step_at( (array) $flow['steps'], $path );

		if ( null === $step ) {
			return null;
		}

		return array(
			'trigger'  => (string) ( $flow['trigger']['type'] ?? '' ),
			'category' => self::category_for( $flow ),
			'email'    => (array) ( $step['email'] ?? array( 'subject' => '', 'heading' => '', 'body' => '' ) ),
			'sms'      => (array) ( $step['sms'] ?? array( 'body' => '' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{flow: array<string, mixed>, errors: string[]}
	 */
	public static function validate( array $input ): array {
		$errors       = array();
		$trigger_type = (string) ( $input['trigger']['type'] ?? '' );

		if ( ! in_array( $trigger_type, self::TRIGGERS, true ) ) {
			$errors[]     = __( 'Choose a trigger.', 'protech-wholesale' );
			$trigger_type = self::TRIGGER_ORDER_STATUS;
		}

		$existing = self::get( (string) ( $input['id'] ?? '' ) );
		$flow     = null !== $existing ? $existing : self::defaults( $trigger_type );

		$flow['id']      = (string) ( $input['id'] ?? '' );
		$flow['name']    = EmailBlocks::text( $input['name'] ?? '', 120 );
		$flow['enabled'] = EmailBlocks::flag( $input['enabled'] ?? false );

		if ( '' === $flow['name'] ) {
			$errors[] = __( 'Give the flow a name.', 'protech-wholesale' );
		}

		$valid_tiers  = array_keys( Tiers::get_tier_labels() );
		$flow['tiers'] = array_values( array_intersect( (array) ( $input['tiers'] ?? $flow['tiers'] ), $valid_tiers ) );
		$flow['tags_any'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['tags_any'] ?? $flow['tags_any'] ) ) ) );

		$trigger_params = (array) ( $input['trigger']['params'] ?? array() );

		switch ( $trigger_type ) {
			case self::TRIGGER_DAYS_SINCE_LAST_ORDER:
				$days    = max( 1, min( 365, (int) ( $trigger_params['days'] ?? 30 ) ) );
				$repeats = max( 1, min( 12, (int) ( $trigger_params['repeats'] ?? 1 ) ) );
				$trigger_params = array( 'days' => $days, 'repeats' => $repeats );
				break;

			case self::TRIGGER_DAYS_SINCE_APPROVAL:
				$trigger_params = array( 'days' => max( 1, min( 365, (int) ( $trigger_params['days'] ?? 7 ) ) ) );
				break;

			case self::TRIGGER_ORDER_STATUS:
				$statuses = wc_get_order_statuses();
				$status   = sanitize_key( (string) ( $trigger_params['status'] ?? 'completed' ) );
				$trigger_params = array( 'status' => isset( $statuses[ 'wc-' . $status ] ) ? $status : 'completed' );
				break;

			case self::TRIGGER_TAG_ADDED:
				$trigger_params = array( 'tag' => sanitize_key( (string) ( $trigger_params['tag'] ?? '' ) ) );
				break;

			default:
				$trigger_params = array();
		}

		$flow['trigger'] = array( 'type' => $trigger_type, 'params' => $trigger_params );

		$steps_in = is_array( $input['steps'] ?? null ) ? $input['steps'] : array();
		$flow['steps'] = self::validate_steps( $steps_in, $errors );

		if ( empty( $flow['steps'] ) ) {
			$errors[] = __( 'Add at least one step.', 'protech-wholesale' );
		}

		return array(
			'flow'   => $flow,
			'errors' => $errors,
		);
	}

	/**
	 * @param array<int, mixed> $steps_in
	 * @param string[]          $errors
	 * @return array<int, array<string, mixed>>
	 */
	private static function validate_steps( array $steps_in, array &$errors ): array {
		$clean = array();

		foreach ( array_values( $steps_in ) as $step_in ) {
			if ( ! is_array( $step_in ) ) {
				continue;
			}

			$type = (string) ( $step_in['type'] ?? '' );

			if ( ! in_array( $type, self::STEP_TYPES, true ) ) {
				continue;
			}

			$step = array( 'type' => $type );

			switch ( $type ) {
				case 'send_email':
					$step['email'] = array(
						'subject'     => sanitize_text_field( (string) ( $step_in['email']['subject'] ?? '' ) ),
						'heading'     => sanitize_text_field( (string) ( $step_in['email']['heading'] ?? '' ) ),
						'body'        => wp_kses_post( (string) ( $step_in['email']['body'] ?? '' ) ),
						'template_id' => sanitize_text_field( (string) ( $step_in['email']['template_id'] ?? '' ) ),
					);

					if ( '' !== $step['email']['template_id'] && ! EmailTemplates::exists( $step['email']['template_id'] ) ) {
						$errors[] = __( 'A step points at an email template that no longer exists.', 'protech-wholesale' );
						$step['email']['template_id'] = '';
					}
					break;

				case 'send_sms':
					$step['sms'] = array( 'body' => sanitize_textarea_field( (string) ( $step_in['sms']['body'] ?? '' ) ) );
					break;

				case 'delay':
					$step['amount'] = max( 1, min( 365, (int) ( $step_in['amount'] ?? 1 ) ) );
					$step['unit']   = in_array( $step_in['unit'] ?? '', array( 'hours', 'days' ), true ) ? $step_in['unit'] : 'days';
					break;

				case 'add_tag':
					$step['tag'] = sanitize_key( (string) ( $step_in['tag'] ?? '' ) );

					if ( '' === $step['tag'] ) {
						continue 2; // Never store a tag step with nothing to tag.
					}
					break;

				case 'remove_tag':
					// FlowsScreen submits this under [tag2], not [tag]: every slot's add_tag
					// and remove_tag fields are always both present in the form, and sharing
					// one name would let one silently blank the other. A direct caller (a
					// test, an import) that builds the step array itself still just uses 'tag'.
					$step['tag'] = sanitize_key( (string) ( $step_in['tag2'] ?? $step_in['tag'] ?? '' ) );

					if ( '' === $step['tag'] ) {
						continue 2; // Never store a tag step with nothing to tag.
					}
					break;

				case 'condition':
					$rule = is_array( $step_in['rule'] ?? null ) ? $step_in['rule'] : array();
					$field = in_array( $rule['field'] ?? '', self::CONDITION_FIELDS, true ) ? $rule['field'] : 'is_wholesale';

					$step['rule'] = array( 'field' => $field, 'value' => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ) );
					$step['yes']  = self::validate_steps( is_array( $step_in['yes'] ?? null ) ? $step_in['yes'] : array(), $errors );
					$step['no']   = self::validate_steps( is_array( $step_in['no'] ?? null ) ? $step_in['no'] : array(), $errors );
					break;
			}

			$clean[] = $step;
		}

		return $clean;
	}

	public static function save( array $flow ): string {
		$flows = self::all();
		$now   = time();
		$id    = (string) ( $flow['id'] ?? '' );

		if ( '' === $id || ! isset( $flows[ $id ] ) ) {
			$id                 = 'r_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$flow['created_at'] = $now;
		} else {
			$flow['created_at'] = $flows[ $id ]['created_at'] ?? $now;
		}

		$flow['id']         = $id;
		$flow['updated_at'] = $now;
		$flows[ $id ]       = $flow;

		if ( count( $flows ) > self::MAX_STORED ) {
			uasort( $flows, static fn( $a, $b ): int => (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ) );
			$flows = array_slice( $flows, 0, self::MAX_STORED, true );
		}

		update_option( self::OPTION, $flows, false );

		return $id;
	}

	public static function delete( string $id ): void {
		$flows = self::all();
		unset( $flows[ $id ] );
		update_option( self::OPTION, $flows, false );
	}

	public static function set_enabled( string $id, bool $on ): void {
		$flows = self::all();

		if ( isset( $flows[ $id ] ) ) {
			$flows[ $id ]['enabled'] = $on;
			update_option( self::OPTION, $flows, false );
		}
	}

	public static function duplicate( string $id ): string {
		$flow = self::get( $id );

		if ( null === $flow ) {
			return '';
		}

		$flow['id']      = '';
		$flow['enabled'] = false;
		/* translators: %s: name of the flow being copied. */
		$flow['name'] = sprintf( __( 'Copy of %s', 'protech-wholesale' ), (string) $flow['name'] );

		unset( $flow['last_run_at'] );

		return self::save( $flow );
	}

	/**
	 * One-time import (Plugin::maybe_upgrade(), DB_VERSION 10): every
	 * existing Automations rule becomes a flow under the SAME id, so its
	 * message log rows (rule_id = that id) still resolve to something.
	 * Only into an empty flow list, and only once — re-running it (an
	 * interrupted upgrade retried) never duplicates or overwrites a flow
	 * an admin has since edited.
	 *
	 * The day-based triggers (reorder reminder, win-back, first-order
	 * nudge) keep the exact catch-up-window evaluation Automations always
	 * used (FlowTriggers::daily_candidates() calls the same
	 * Automations::anchor_for() math); order_status keeps the same delayed
	 * per-order delivery. See DECISIONS.md for what changes and why.
	 *
	 * @return int How many rules were imported.
	 */
	public static function import_legacy_rules(): int {
		if ( ! empty( self::all() ) ) {
			return 0;
		}

		$imported = 0;

		foreach ( Automations::all() as $id => $rule ) {
			$flow = self::flow_from_legacy_rule( $rule );

			if ( null === $flow ) {
				continue;
			}

			$flows         = self::all();
			$flow['id']    = $id;
			$flows[ $id ]  = $flow;
			update_option( self::OPTION, $flows, false );
			++$imported;
		}

		return $imported;
	}

	/**
	 * @param array<string, mixed> $rule
	 * @return array<string, mixed>|null
	 */
	private static function flow_from_legacy_rule( array $rule ): ?array {
		$old_trigger = (string) ( $rule['trigger'] ?? '' );
		$params      = (array) ( $rule['params'] ?? array() );

		$trigger = null;

		switch ( $old_trigger ) {
			case Automations::TRIGGER_REORDER_REMINDER:
				$trigger = array( 'type' => self::TRIGGER_DAYS_SINCE_LAST_ORDER, 'params' => array( 'days' => (int) ( $params['days'] ?? 30 ), 'repeats' => 1 ) );
				break;

			case Automations::TRIGGER_WINBACK:
				$trigger = array( 'type' => self::TRIGGER_DAYS_SINCE_LAST_ORDER, 'params' => array( 'days' => (int) ( $params['days'] ?? 45 ), 'repeats' => (int) ( $params['max_repeats'] ?? 3 ) ) );
				break;

			case Automations::TRIGGER_FIRST_ORDER:
				$trigger = array( 'type' => self::TRIGGER_DAYS_SINCE_APPROVAL, 'params' => array( 'days' => (int) ( $params['days'] ?? 7 ) ) );
				break;

			case Automations::TRIGGER_ORDER_STATUS:
				$trigger = array( 'type' => self::TRIGGER_ORDER_STATUS, 'params' => array( 'status' => (string) ( $params['status'] ?? 'completed' ) ) );
				break;
		}

		if ( null === $trigger ) {
			return null;
		}

		$steps = array();

		// order_status kept its own delay as a rule param; every other legacy trigger's "days"
		// was when the flow itself fires, so nothing here needs a delay step to reproduce it.
		if ( Automations::TRIGGER_ORDER_STATUS === $old_trigger && (int) ( $params['delay_minutes'] ?? 0 ) > 0 ) {
			$steps[] = array( 'amount' => (int) ceil( (int) $params['delay_minutes'] / 60 ), 'unit' => 'hours', 'type' => 'delay' );
		}

		$channels = Automations::channels( $rule );

		if ( in_array( 'email', $channels, true ) ) {
			$steps[] = array(
				'type'  => 'send_email',
				'email' => array(
					'subject'     => (string) ( $rule['email']['subject'] ?? '' ),
					'heading'     => (string) ( $rule['email']['heading'] ?? '' ),
					'body'        => (string) ( $rule['email']['body'] ?? '' ),
					'template_id' => (string) ( $rule['email']['template_id'] ?? '' ),
				),
			);
		}

		if ( in_array( 'sms', $channels, true ) ) {
			$steps[] = array( 'type' => 'send_sms', 'sms' => array( 'body' => (string) ( $rule['sms']['body'] ?? '' ) ) );
		}

		return array(
			'id'          => (string) ( $rule['id'] ?? '' ),
			'name'        => (string) ( $rule['name'] ?? '' ),
			'enabled'     => ! empty( $rule['enabled'] ),
			'trigger'     => $trigger,
			'tiers'       => (array) ( $rule['tiers'] ?? array() ),
			'tags_any'    => array(),
			'steps'       => $steps,
			'created_at'  => (int) ( $rule['created_at'] ?? time() ),
			'updated_at'  => time(),
			'last_run_at' => (int) ( $rule['last_run_at'] ?? 0 ),
		);
	}
}
