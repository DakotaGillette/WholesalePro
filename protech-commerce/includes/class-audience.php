<?php
/**
 * Resolves a Compose/campaign "audience" description into the customer ids
 * it matches. Shared by the Compose screen (the review counts, the actual
 * send) and nothing else — automation rules use their own tier + anchor-window
 * logic in Automations, since a rule's audience is really "who is in the
 * window today," not a static segment.
 *
 * Every segment is drawn from a pool of customers, chosen by `scope`: wholesale
 * accounts (the default, and the only pool before 2.5.0), retail customers (a
 * WooCommerce customer who is not a wholesale account), or everyone. The tier,
 * "prefers texts" and "selected" segments are wholesale ideas and always use
 * the wholesale pool.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Audience
 */
class Audience {

	public const TYPE_ALL              = 'all';
	public const TYPE_TIER              = 'tier';
	public const TYPE_INACTIVE          = 'inactive';
	public const TYPE_RECENT            = 'recent';
	public const TYPE_NEVER_ORDERED     = 'never_ordered';
	public const TYPE_BOUGHT_PRODUCT    = 'bought_product';
	public const TYPE_PREFERS_TEXT      = 'prefers_text';
	public const TYPE_SELECTED          = 'selected';

	public const TYPES = array(
		self::TYPE_ALL,
		self::TYPE_TIER,
		self::TYPE_INACTIVE,
		self::TYPE_RECENT,
		self::TYPE_NEVER_ORDERED,
		self::TYPE_BOUGHT_PRODUCT,
		self::TYPE_PREFERS_TEXT,
		self::TYPE_SELECTED,
	);

	public const SCOPE_WHOLESALE = 'wholesale';
	public const SCOPE_RETAIL    = 'retail';
	public const SCOPE_EVERYONE  = 'everyone';

	public const SCOPES = array( self::SCOPE_WHOLESALE, self::SCOPE_RETAIL, self::SCOPE_EVERYONE );

	/** Order statuses that count as "bought" or "ordered": money came in or is on its way. */
	private const ORDER_STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

	/**
	 * @param array<string, mixed> $input
	 * @return array{type: string, scope: string, tiers: string[], days: int, product_id: int, user_ids: int[]}
	 */
	public static function normalize( array $input ): array {
		$type  = (string) ( $input['type'] ?? self::TYPE_ALL );
		$scope = (string) ( $input['scope'] ?? self::SCOPE_WHOLESALE );

		return array(
			'type'       => in_array( $type, self::TYPES, true ) ? $type : self::TYPE_ALL,
			'scope'      => in_array( $scope, self::SCOPES, true ) ? $scope : self::SCOPE_WHOLESALE,
			'tiers'      => array_values( array_intersect( (array) ( $input['tiers'] ?? array() ), array_keys( Tiers::get_tier_labels() ) ) ),
			'days'       => max( 1, (int) ( ( self::TYPE_RECENT === $type ? ( $input['recent_days'] ?? $input['days'] ?? null ) : ( $input['days'] ?? null ) ) ?? 60 ) ),
			'product_id' => absint( $input['product_id'] ?? 0 ),
			'user_ids'   => array_values( array_unique( array_map( 'absint', (array) ( $input['user_ids'] ?? array() ) ) ) ),
		);
	}

	/**
	 * The customers a scope draws from.
	 *
	 * @return int[]
	 */
	public static function pool( string $scope ): array {
		$wholesale = array_map( 'intval', get_users( array( 'role' => Roles::CUSTOMER, 'fields' => 'ID' ) ) );

		if ( self::SCOPE_WHOLESALE === $scope ) {
			return $wholesale;
		}

		$retail = array_map( 'intval', get_users( array( 'role' => 'customer', 'fields' => 'ID' ) ) );
		$retail = array_values( array_diff( $retail, $wholesale ) );

		return self::SCOPE_RETAIL === $scope ? $retail : array_values( array_unique( array_merge( $wholesale, $retail ) ) );
	}

	/**
	 * @param array<string, mixed> $segment
	 * @return int[]
	 */
	public static function resolve( array $segment ): array {
		$segment = self::normalize( $segment );

		if ( self::TYPE_SELECTED === $segment['type'] ) {
			return array_values( array_filter( $segment['user_ids'], static fn( int $id ): bool => Roles::is_wholesale_customer( $id ) ) );
		}

		// Tiers and "prefers texts" only mean something for a wholesale account.
		$wholesale_only = in_array( $segment['type'], array( self::TYPE_TIER, self::TYPE_PREFERS_TEXT ), true );
		$user_ids       = self::pool( $wholesale_only ? self::SCOPE_WHOLESALE : $segment['scope'] );

		switch ( $segment['type'] ) {
			case self::TYPE_TIER:
				if ( empty( $segment['tiers'] ) ) {
					return $user_ids;
				}

				return array_values(
					array_filter( $user_ids, static fn( int $id ): bool => in_array( Tiers::get_user_tier( $id ), $segment['tiers'], true ) )
				);

			case self::TYPE_INACTIVE:
				$cutoff = time() - $segment['days'] * DAY_IN_SECONDS;

				return array_values(
					array_filter(
						$user_ids,
						static function ( int $id ) use ( $cutoff ): bool {
							$last = self::last_order_time( $id );

							return null !== $last && $last < $cutoff;
						}
					)
				);

			case self::TYPE_RECENT:
				$cutoff = time() - $segment['days'] * DAY_IN_SECONDS;

				return array_values(
					array_filter(
						$user_ids,
						static function ( int $id ) use ( $cutoff ): bool {
							$last = self::last_order_time( $id );

							return null !== $last && $last >= $cutoff;
						}
					)
				);

			case self::TYPE_NEVER_ORDERED:
				return array_values( array_filter( $user_ids, static fn( int $id ): bool => 0 === wc_get_customer_order_count( $id ) ) );

			case self::TYPE_BOUGHT_PRODUCT:
				return array_values( array_intersect( $user_ids, self::buyers_of( $segment['product_id'] ) ) );

			case self::TYPE_PREFERS_TEXT:
				return array_values(
					array_filter(
						$user_ids,
						static function ( int $id ): bool {
							$prefers = stripos( (string) get_user_meta( $id, '_protech_wholesale_app_contact_methods', true ), 'text' ) !== false;
							return $prefers && 'yes' !== get_user_meta( $id, SmsConsent::META_SMS_MARKETING, true );
						}
					)
				);

			default:
				return $user_ids;
		}
	}

	/** When the customer last ordered (an order that counts), or null when they never have. */
	private static function last_order_time( int $user_id ): ?int {
		$order = Reorder::get_last_order_for_user( $user_id );

		return $order instanceof \WC_Order && $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null;
	}

	/**
	 * The customers (user ids) with a counting order containing a product or any
	 * of its variations. Read from the order line items, which every WooCommerce
	 * order storage layout keeps, then mapped to the order's customer.
	 *
	 * @return int[]
	 */
	public static function buyers_of( int $product_id ): array {
		global $wpdb;

		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$product_ids = array_map( 'intval', array_merge( array( $product_id ), $product->get_children() ) );
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the only interpolation is a generated list of %d placeholders.
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT i.order_id FROM {$wpdb->prefix}woocommerce_order_items i
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id
				WHERE i.order_item_type = 'line_item' AND m.meta_key IN ( '_product_id', '_variation_id' ) AND m.meta_value IN ( $placeholders )",
				$product_ids
			)
		);
		// phpcs:enable

		$buyers = array();

		foreach ( array_map( 'intval', (array) $order_ids ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order && in_array( 'wc-' . $order->get_status(), self::ORDER_STATUSES, true ) && $order->get_customer_id() > 0 ) {
				$buyers[ $order->get_customer_id() ] = $order->get_customer_id();
			}
		}

		return array_values( $buyers );
	}

	public static function count( array $segment ): int {
		return count( self::resolve( $segment ) );
	}

	/**
	 * Who would get a message right now and who would be left out, by the
	 * same consent gate a real send applies, without sending anything. Email
	 * is counted from the local unsubscribe record only: asking Brevo about
	 * every person in a big audience would time the request out, and each
	 * message is checked against Brevo again as it goes out.
	 *
	 * @param array<string, mixed> $segment  Raw or normalized audience.
	 * @param string[]             $channels 'email' and/or 'sms'.
	 * @return array{total: int, sent_to: array<string, int>, reasons: array<string, int>}
	 */
	public static function estimate( array $segment, array $channels, string $category ): array {
		$user_ids = self::resolve( self::normalize( $segment ) );
		$sent_to  = array_fill_keys( $channels, 0 );
		$reasons  = array();

		foreach ( $user_ids as $user_id ) {
			foreach ( $channels as $channel ) {
				$gate = 'sms' === $channel ? SmsConsent::can_receive_sms( $user_id, $category ) : SmsConsent::can_receive_email( $user_id, $category, false );

				if ( $gate['ok'] ) {
					++$sent_to[ $channel ];
				} else {
					$reasons[ $gate['reason'] ] = ( $reasons[ $gate['reason'] ] ?? 0 ) + 1;
				}
			}
		}

		return array( 'total' => count( $user_ids ), 'sent_to' => $sent_to, 'reasons' => $reasons );
	}

	/** "All wholesale customers", "All retail customers" or "All customers", for use in a sentence. */
	private static function scope_label( string $scope ): string {
		switch ( $scope ) {
			case self::SCOPE_RETAIL:
				return __( 'retail customers', 'protech-wholesale' );
			case self::SCOPE_EVERYONE:
				return __( 'customers', 'protech-wholesale' );
			default:
				return __( 'wholesale customers', 'protech-wholesale' );
		}
	}

	/**
	 * @param array<string, mixed> $segment
	 */
	public static function describe( array $segment ): string {
		$segment = self::normalize( $segment );
		$who     = self::scope_label( $segment['scope'] );

		switch ( $segment['type'] ) {
			case self::TYPE_TIER:
				if ( empty( $segment['tiers'] ) ) {
					return __( 'All wholesale customers', 'protech-wholesale' );
				}

				$labels = Tiers::get_tier_labels();
				return implode( ', ', array_map( static fn( string $t ): string => $labels[ $t ] ?? $t, $segment['tiers'] ) ) . ' ' . __( 'customers', 'protech-wholesale' );

			case self::TYPE_INACTIVE:
				/* translators: 1: kind of customer, for example "retail customers"; 2: number of days. */
				return ucfirst( sprintf( __( '%1$s with no order in %2$d days', 'protech-wholesale' ), $who, $segment['days'] ) );

			case self::TYPE_RECENT:
				/* translators: 1: kind of customer, for example "retail customers"; 2: number of days. */
				return ucfirst( sprintf( __( '%1$s who ordered in the last %2$d days', 'protech-wholesale' ), $who, $segment['days'] ) );

			case self::TYPE_NEVER_ORDERED:
				/* translators: %s: kind of customer, for example "retail customers". */
				return ucfirst( sprintf( __( '%s who have never ordered', 'protech-wholesale' ), $who ) );

			case self::TYPE_BOUGHT_PRODUCT:
				$product = $segment['product_id'] > 0 ? wc_get_product( $segment['product_id'] ) : null;

				/* translators: 1: kind of customer, for example "retail customers"; 2: product name. */
				return ucfirst( sprintf( __( '%1$s who bought %2$s', 'protech-wholesale' ), $who, $product instanceof \WC_Product ? $product->get_name() : __( 'a product', 'protech-wholesale' ) ) );

			case self::TYPE_PREFERS_TEXT:
				return __( 'Said they prefer texts, but haven\'t opted in', 'protech-wholesale' );

			case self::TYPE_SELECTED:
				/* translators: %d: number of selected customers. */
				return sprintf( _n( '%d selected customer', '%d selected customers', count( $segment['user_ids'] ), 'protech-wholesale' ), count( $segment['user_ids'] ) );

			default:
				/* translators: %s: kind of customer, for example "retail customers". */
				return ucfirst( sprintf( __( 'All %s', 'protech-wholesale' ), $who ) );
		}
	}
}
