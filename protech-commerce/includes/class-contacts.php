<?php
/**
 * A directory of everyone the store has a relationship with, wholesale,
 * retail or guest, one row per email address, independent of whether they
 * have a WordPress account. Built additively: it reads a snapshot of
 * consent from the existing SmsConsent (user-meta) system rather than
 * replacing it, and nothing in Compose, Campaigns or Automations targets a
 * contact yet, only a WordPress user, exactly as before. See DECISIONS.md
 * for why: SmsConsent's user-meta model is compliance-sensitive (it is the
 * store's proof of opt-in for Brevo's toll-free number verification), and
 * rewriting its storage in the same phase that also introduces guest
 * contacts was a materially larger risk than the value justified yet.
 *
 * A contact linked to a WordPress user (user_id > 0) is kept in sync from
 * that account; a guest contact (user_id = 0) exists only from what a
 * checkout collected. The one exception to "read-only snapshot" is
 * record_manual_unsubscribe() for a linked user: it calls SmsConsent's own
 * public API (not its storage), so an admin acting from the Contacts screen
 * has the same real effect an admin acting from the user's profile would.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Contacts
 */
class Contacts {

	public const TABLE             = 'protech_wholesale_contacts';
	public const CONSENT_LOG_TABLE = 'protech_wholesale_contact_consent_log';

	public const STATUS_SUBSCRIBED        = 'subscribed';
	public const STATUS_UNSUBSCRIBED      = 'unsubscribed';
	public const STATUS_TRANSACTIONAL_ONLY = 'transactional_only';

	public const SOURCE_WP_USER        = 'wp_user';
	public const SOURCE_GUEST_CHECKOUT = 'guest_checkout';
	public const SOURCE_ADMIN          = 'admin';
	public const SOURCE_BACKFILL       = 'backfill';

	/** Roles a backfill (and nothing else) treats as "an existing contact worth having". */
	private const BACKFILL_ROLES = array( 'customer', Roles::CUSTOMER, Roles::PENDING );

	public function register_hooks(): void {
		add_action( 'user_register', array( __CLASS__, 'sync_user' ) );
		add_action( 'profile_update', array( __CLASS__, 'sync_user' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'sync_user' ) );

		// Classic (shortcode) checkout builds the order in WC_Checkout::create_order();
		// WooCommerce Blocks checkout (what this store actually uses) never fires that, so
		// it needs its own hook too — see OrdersAdmin::register_hooks() for the same pairing.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'sync_from_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'sync_from_order' ), 20, 1 );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'refresh_order_stats' ), 10, 4 );
	}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	public static function consent_log_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::CONSENT_LOG_TABLE;
	}

	public static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();
		$log_table       = self::consent_log_table();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				company varchar(190) NOT NULL DEFAULT '',
				phone varchar(30) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'subscribed',
				source varchar(20) NOT NULL DEFAULT 'wp_user',
				last_order_at datetime NULL,
				order_count int(10) unsigned NOT NULL DEFAULT 0,
				lifetime_value decimal(10,2) NOT NULL DEFAULT 0.00,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$log_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				contact_id bigint(20) unsigned NOT NULL,
				at datetime NOT NULL,
				source varchar(30) NOT NULL DEFAULT '',
				note text NULL,
				recorded_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY contact_id (contact_id)
			) {$charset_collate};"
		);

		do_action( 'protech_wholesale_contacts_table_installed' );
	}

	// -----------------------------------------------------------------
	// Reading.
	// -----------------------------------------------------------------

	/** @return array<string, mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string, mixed>|null */
	public static function get_by_email( string $email ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{search?: string, status?: string, source?: string} $filters
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table = self::table();
		$where = array( '1=1' );
		$args  = array();

		if ( '' !== trim( (string) ( $filters['search'] ?? '' ) ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $filters['search'] ) ) . '%';
			$where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR company LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		if ( '' !== (string) ( $filters['status'] ?? '' ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $filters['status'];
		}

		if ( '' !== (string) ( $filters['source'] ?? '' ) ) {
			$where[] = 'source = %s';
			$args[]  = (string) $filters['source'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		if ( $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		}

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/** Every contact matching $filters, unpaginated — CSV export only; the admin screen always paginates. */
	public static function all( array $filters = array() ): array {
		return self::query( $filters, 1, 100000 )['rows'];
	}

	public static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/** @return array<int, array<string, mixed>> newest first. */
	public static function consent_log_for( int $contact_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::consent_log_table() . ' WHERE contact_id = %d ORDER BY id DESC', $contact_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	// -----------------------------------------------------------------
	// Writing.
	// -----------------------------------------------------------------

	/** A WordPress user's contact, created or refreshed from their current account state. Returns the contact id. */
	public static function for_user( int $user_id, string $source = self::SOURCE_WP_USER ): int {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return 0;
		}

		$email_marketing = (string) get_user_meta( $user_id, SmsConsent::META_EMAIL_MARKETING, true );

		return self::upsert(
			array(
				'user_id'    => $user_id,
				'email'      => (string) $user->user_email,
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'company'    => trim( (string) get_user_meta( $user_id, 'billing_company', true ) ),
				'phone'      => SmsConsent::phone_for( $user_id ),
				'status'     => 'no' === $email_marketing ? self::STATUS_UNSUBSCRIBED : self::STATUS_SUBSCRIBED,
				'source'     => $source,
			)
		);
	}

	public static function sync_user( int $user_id ): void {
		self::for_user( $user_id );
	}

	/**
	 * A guest checkout's contact, from whatever billing details are on the
	 * order; a logged-in checkout is left to the user hooks instead, so a
	 * member never gets a second, guest-sourced row for the same account.
	 */
	public static function sync_from_order( \WC_Order $order ): void {
		$customer_id = (int) $order->get_customer_id();

		if ( $customer_id > 0 ) {
			return;
		}

		$email = trim( (string) $order->get_billing_email() );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$existing = self::get_by_email( $email );

		self::upsert(
			array(
				'user_id'    => 0,
				'email'      => $email,
				'first_name' => (string) $order->get_billing_first_name(),
				'last_name'  => (string) $order->get_billing_last_name(),
				'company'    => (string) $order->get_billing_company(),
				'phone'      => SmsConsent::normalize_phone( (string) $order->get_billing_phone() ),
				// No checkout opt-in yet (see DECISIONS.md): a first-time guest starts transactional-only;
				// an existing contact's status (subscribed, or a past unsubscribe) is left exactly as it was.
				'status'     => null !== $existing ? (string) $existing['status'] : self::STATUS_TRANSACTIONAL_ONLY,
				'source'     => null !== $existing ? (string) $existing['source'] : self::SOURCE_GUEST_CHECKOUT,
			)
		);
	}

	/**
	 * @param array{user_id: int, email: string, first_name: string, last_name: string, company: string, phone: string, status: string, source: string} $fields
	 */
	private static function upsert( array $fields ): int {
		global $wpdb;

		$existing = self::get_by_email( $fields['email'] );
		$now      = current_time( 'mysql', true );

		$data = array(
			'user_id'    => $fields['user_id'],
			'email'      => $fields['email'],
			'first_name' => $fields['first_name'],
			'last_name'  => $fields['last_name'],
			'company'    => $fields['company'],
			'phone'      => $fields['phone'],
			'status'     => $fields['status'],
			'source'     => $fields['source'],
			'updated_at' => $now,
		);

		if ( null !== $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( self::table(), $data, array( 'id' => (int) $existing['id'] ) );

			return (int) $existing['id'];
		}

		$data['created_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Keeps last_order_at/order_count/lifetime_value current whenever an
	 * order reaches processing or completed, for whichever contact the
	 * order belongs to (a member by user_id, a guest by billing email).
	 *
	 * Untyped params, matching Automations::on_order_status_changed() on
	 * this same hook: with strict_types on, a type-hinted param would fatal
	 * if WooCommerce ever passed something unexpected here.
	 */
	public static function refresh_order_stats( $order_id, $from, $to, $order ): void {
		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) || ! $order instanceof \WC_Order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();
		$email       = trim( (string) $order->get_billing_email() );

		// A guest contact should already exist from sync_from_order() at checkout time, well
		// before an order can reach processing or completed; this covers it regardless, since
		// an order created some other way (a manual admin order, a test) never fires that hook.
		if ( 0 === $customer_id ) {
			self::sync_from_order( $order );
		}

		if ( $customer_id > 0 ) {
			$id = self::for_user( $customer_id );
		} else {
			$contact = self::get_by_email( $email );
			$id      = null !== $contact ? (int) $contact['id'] : 0;
		}

		if ( 0 === $id ) {
			return;
		}

		if ( $customer_id > 0 ) {
			$order_count    = wc_get_customer_order_count( $customer_id );
			$lifetime_value = wc_get_customer_total_spent( $customer_id );
		} else {
			$guest_orders   = wc_get_orders(
				array(
					'billing_email' => $email,
					'status'        => array( 'processing', 'completed' ),
					'limit'         => -1,
					'return'        => 'ids',
				)
			);
			$order_count    = count( $guest_orders );
			$lifetime_value = 0.0;

			foreach ( $guest_orders as $guest_order_id ) {
				$guest_order = wc_get_order( $guest_order_id );

				if ( $guest_order instanceof \WC_Order ) {
					$lifetime_value += (float) $guest_order->get_total();
				}
			}
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'last_order_at'  => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql', true ),
				'order_count'    => $order_count,
				'lifetime_value' => $lifetime_value,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Marks a contact unsubscribed and logs why. For a contact linked to a
	 * WordPress account, also calls SmsConsent's own public API, so the
	 * real send-gating this store relies on today actually changes, not
	 * only what this screen displays (see the class docblock).
	 */
	public static function record_manual_unsubscribe( int $id, string $note, int $recorded_by ): void {
		$contact = self::get( $id );

		if ( null === $contact ) {
			return;
		}

		if ( (int) $contact['user_id'] > 0 ) {
			SmsConsent::record( (int) $contact['user_id'], array( 'email_marketing' => false ), SmsConsent::SOURCE_ADMIN, $note, $recorded_by );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), array( 'status' => self::STATUS_UNSUBSCRIBED, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::consent_log_table(),
			array(
				'contact_id'  => $id,
				'at'          => current_time( 'mysql', true ),
				'source'      => self::SOURCE_ADMIN,
				'note'        => $note,
				'recorded_by' => $recorded_by,
			)
		);
	}

	/**
	 * Creates a contact for every existing user with a customer-ish role.
	 * Run once, inline, from Plugin::maybe_upgrade() (the same pattern
	 * Automations::backfill_approved_at() already uses): bounded by how
	 * many such users this store actually has, not by its full order
	 * history, so it needs no Action Scheduler batching. A guest's own
	 * past orders are not backfilled; new ones are captured going forward
	 * by sync_from_order() (see DECISIONS.md).
	 */
	public static function backfill(): int {
		$user_ids = get_users(
			array(
				'role__in' => self::BACKFILL_ROLES,
				'fields'   => 'ID',
			)
		);

		$before = self::count();

		foreach ( $user_ids as $user_id ) {
			self::for_user( (int) $user_id, self::SOURCE_BACKFILL );
		}

		return max( 0, self::count() - $before );
	}
}
