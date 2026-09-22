<?php
/**
 * The message log: one row per recipient x channel, for every automated,
 * manual, or test email/SMS this plugin has ever queued. It is the
 * plugin's first custom database table (rather than an option or user
 * meta) because dedup needs an atomic uniqueness guarantee across
 * concurrent Action Scheduler runs — only a UNIQUE KEY plus INSERT
 * IGNORE gives that — and the Log/Compliance views need to query across
 * every customer by rule, status and date, which a serialized per-user
 * meta array cannot do without loading every user. See DECISIONS.md.
 *
 * Two operations make delivery idempotent under Action Scheduler retries:
 * enqueue() is an INSERT IGNORE keyed on (rule_id, user_id, anchor,
 * channel) — a second evaluation of the same rule/customer/day is a
 * silent no-op — and claim() is a conditional UPDATE ... WHERE
 * status = 'queued', so a row already claimed by another worker cannot
 * be claimed (and therefore sent) twice.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageLog
 */
class MessageLog {

	public const TABLE = 'protech_wholesale_messages';

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_SENDING = 'sending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	public const CHANNEL_EMAIL = 'email';
	public const CHANNEL_SMS   = 'sms';

	public const KIND_AUTO   = 'auto';
	public const KIND_MANUAL = 'manual';
	public const KIND_TEST   = 'test';
	public const KIND_WELCOME = 'welcome';
	/** The three application-flow emails (received/approved/rejected): sent once, logged as already sent, never queued or retried. */
	public const KIND_LIFECYCLE = 'lifecycle';

	public const CATEGORY_MARKETING     = 'marketing';
	public const CATEGORY_TRANSACTIONAL = 'transactional';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	public static function table_exists(): bool {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is strict about formatting: two spaces before KEY/PRIMARY
		// KEY, and no backticks around the table name.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			channel varchar(10) NOT NULL,
			kind varchar(10) NOT NULL,
			category varchar(15) NOT NULL DEFAULT 'marketing',
			rule_id varchar(64) NOT NULL DEFAULT '',
			anchor varchar(64) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'queued',
			reason varchar(40) NOT NULL DEFAULT '',
			provider varchar(10) NOT NULL DEFAULT '',
			provider_id varchar(64) NOT NULL DEFAULT '',
			recipient varchar(190) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			error text NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			send_after datetime NULL,
			created_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rule_user_anchor_channel (rule_id,user_id,anchor,channel),
			KEY user_id (user_id),
			KEY status_send_after (status,send_after),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		/**
		 * Fires every time install_table() runs dbDelta() against this table,
		 * whether or not anything actually changed. Exists so a test can
		 * confirm Plugin::maybe_upgrade() calls this unconditionally, without
		 * needing to physically drop and recreate the table itself.
		 */
		do_action( 'protech_wholesale_message_log_table_installed' );
	}

	/**
	 * Insert a queued row. Returns 0 (rather than throwing) when the
	 * unique key already exists — i.e. this exact message was already
	 * queued for this recipient — which is the normal, expected outcome
	 * of a dedup hit, not an error.
	 *
	 * @param array{user_id:int, channel:string, kind:string, category:string, rule_id:string, anchor:string, recipient?:string, subject?:string, send_after?:?int} $row
	 */
	public static function enqueue( array $row ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$data = array(
			'user_id'    => (int) $row['user_id'],
			'channel'    => (string) $row['channel'],
			'kind'       => (string) $row['kind'],
			'category'   => (string) ( $row['category'] ?? self::CATEGORY_MARKETING ),
			'rule_id'    => (string) ( $row['rule_id'] ?? '' ),
			'anchor'     => (string) ( $row['anchor'] ?? '' ),
			'status'     => self::STATUS_QUEUED,
			'recipient'  => (string) ( $row['recipient'] ?? '' ),
			'subject'    => (string) ( $row['subject'] ?? '' ),
			'created_at' => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( ! empty( $row['send_after'] ) ) {
			$data['send_after'] = gmdate( 'Y-m-d H:i:s', (int) $row['send_after'] );
			$formats[]          = '%s';
		}

		$sql = 'INSERT IGNORE INTO ' . self::table() . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', $formats ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from a fixed key list and $wpdb->prepare below.
		$wpdb->query( $wpdb->prepare( $sql, array_values( $data ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $wpdb->rows_affected ? (int) $wpdb->insert_id : 0;
	}

	public static function exists( string $rule_id, int $user_id, string $anchor, string $channel ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . self::table() . ' WHERE rule_id = %s AND user_id = %d AND anchor = %s AND channel = %s LIMIT 1',
				$rule_id,
				$user_id,
				$anchor,
				$channel
			)
		);
	}

	/**
	 * Atomically claims a queued row for delivery: a second call for the
	 * same id (a re-run queue worker, a retry) returns null instead of
	 * claiming it again.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function claim( int $id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = attempts + 1 WHERE id = %d AND status = %s AND (send_after IS NULL OR send_after <= %s)",
				self::STATUS_SENDING,
				$id,
				self::STATUS_QUEUED,
				current_time( 'mysql', true )
			)
		);

		if ( ! $updated ) {
			return null;
		}

		return self::get( $id );
	}

	/**
	 * @param array{provider?:string, provider_id?:string, recipient?:string, subject?:string, error?:string, reason?:string} $fields
	 */
	public static function finish( int $id, string $status, array $fields = array() ): void {
		global $wpdb;

		$data = array( 'status' => $status );

		foreach ( array( 'provider', 'provider_id', 'recipient', 'subject', 'error', 'reason' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = (string) $fields[ $key ];
			}
		}

		if ( self::STATUS_SENT === $status ) {
			$data['sent_at'] = current_time( 'mysql', true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	public static function requeue( int $id, int $send_after_ts ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_QUEUED,
				'send_after' => gmdate( 'Y-m-d H:i:s', $send_after_ts ),
			),
			array( 'id' => $id )
		);
	}

	/** MAX(created_at) of an automated marketing row already queued or sent for this customer. */
	public static function last_auto_marketing_at( int $user_id ): ?int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$table} WHERE user_id = %d AND kind = %s AND category = %s AND status IN (%s,%s,%s)",
				$user_id,
				self::KIND_AUTO,
				self::CATEGORY_MARKETING,
				self::STATUS_QUEUED,
				self::STATUS_SENDING,
				self::STATUS_SENT
			)
		);

		if ( ! $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false !== $timestamp ? $timestamp : null;
	}

	/**
	 * @return array<string, int> status => count.
	 */
	public static function counts_for( string $rule_id ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE rule_id = %s GROUP BY status", $rule_id ), ARRAY_A );

		$counts = array(
			self::STATUS_QUEUED  => 0,
			self::STATUS_SENDING => 0,
			self::STATUS_SENT    => 0,
			self::STATUS_FAILED  => 0,
			self::STATUS_SKIPPED => 0,
		);

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/** Rows currently queued or sending — the plugin-wide "pending" count shown in admin. */
	public static function count_pending(): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN (%s,%s)", self::STATUS_QUEUED, self::STATUS_SENDING ) );
	}

	public static function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{channel?:string, status?:string, rule_id?:string, user_id?:int, s?:string} $filters
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table = self::table();
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['channel'] ) ) {
			$where[] = 'channel = %s';
			$args[]  = $filters['channel'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}

		if ( ! empty( $filters['rule_id'] ) ) {
			$where[] = 'rule_id = %s';
			$args[]  = $filters['rule_id'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $filters['user_id'];
		}

		if ( '' !== trim( (string) ( $filters['s'] ?? '' ) ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $filters['s'] ) ) . '%';
			$where[] = '(recipient LIKE %s OR subject LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		if ( $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		}

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Recovers rows stuck 'sending' (a worker died mid-delivery) back to
	 * 'queued' for another attempt (capped at 3), expires rows that have
	 * sat 'queued' too long (a deactivate/reactivate gap), and purges old
	 * rows past retention. Called at the start of every daily run.
	 *
	 * @return array{reset:int, expired:int, purged:int}
	 */
	public static function sweep( int $now ): array {
		global $wpdb;

		$table = self::table();

		$stuck_since   = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$expired_since = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
		$purge_before  = gmdate( 'Y-m-d H:i:s', $now - MessagingSettings::log_retention_days() * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reset = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE status = %s AND attempts < 3 AND created_at < %s",
				self::STATUS_QUEUED,
				self::STATUS_SENDING,
				$stuck_since
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, reason = %s WHERE status = %s AND attempts >= 3",
				self::STATUS_FAILED,
				'stuck',
				self::STATUS_SENDING
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, reason = %s WHERE status = %s AND created_at < %s",
				self::STATUS_FAILED,
				'expired',
				self::STATUS_QUEUED,
				$expired_since
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$purged = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $purge_before ) );

		return array(
			'reset'   => $reset,
			'expired' => $expired,
			'purged'  => $purged,
		);
	}
}
