<?php
/**
 * Resolves a Compose/campaign "audience" description into the wholesale
 * customer ids it matches. Shared by the Compose screen (live recipient
 * count, the actual send) and nothing else — automation rules use their
 * own tier + anchor-window logic in Automations, since a rule's audience
 * is really "who is in the window today," not a static segment.
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
	public const TYPE_NEVER_ORDERED     = 'never_ordered';
	public const TYPE_PREFERS_TEXT      = 'prefers_text';
	public const TYPE_SELECTED          = 'selected';

	public const TYPES = array(
		self::TYPE_ALL,
		self::TYPE_TIER,
		self::TYPE_INACTIVE,
		self::TYPE_NEVER_ORDERED,
		self::TYPE_PREFERS_TEXT,
		self::TYPE_SELECTED,
	);

	/**
	 * @param array<string, mixed> $input
	 * @return array{type: string, tiers: string[], days: int, user_ids: int[]}
	 */
	public static function normalize( array $input ): array {
		$type = (string) ( $input['type'] ?? self::TYPE_ALL );

		return array(
			'type'     => in_array( $type, self::TYPES, true ) ? $type : self::TYPE_ALL,
			'tiers'    => array_values( array_intersect( (array) ( $input['tiers'] ?? array() ), array_keys( Tiers::get_tier_labels() ) ) ),
			'days'     => max( 1, (int) ( $input['days'] ?? 60 ) ),
			'user_ids' => array_values( array_unique( array_map( 'absint', (array) ( $input['user_ids'] ?? array() ) ) ) ),
		);
	}

	/**
	 * @param array{type: string, tiers: string[], days: int, user_ids: int[]} $segment
	 * @return int[]
	 */
	public static function resolve( array $segment ): array {
		$segment = self::normalize( $segment );

		if ( self::TYPE_SELECTED === $segment['type'] ) {
			return array_values( array_filter( $segment['user_ids'], static fn( int $id ): bool => Roles::is_wholesale_customer( $id ) ) );
		}

		$user_ids = array_map( 'intval', get_users( array( 'role' => Roles::CUSTOMER, 'fields' => 'ID' ) ) );

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
							$order = Reorder::get_last_order_for_user( $id );

							return $order instanceof \WC_Order
								&& $order->get_date_created()
								&& $order->get_date_created()->getTimestamp() < $cutoff;
						}
					)
				);

			case self::TYPE_NEVER_ORDERED:
				return array_values( array_filter( $user_ids, static fn( int $id ): bool => 0 === wc_get_customer_order_count( $id ) ) );

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

	public static function count( array $segment ): int {
		return count( self::resolve( $segment ) );
	}

	/**
	 * @param array{type: string, tiers: string[], days: int, user_ids: int[]} $segment
	 */
	public static function describe( array $segment ): string {
		$segment = self::normalize( $segment );

		switch ( $segment['type'] ) {
			case self::TYPE_TIER:
				if ( empty( $segment['tiers'] ) ) {
					return __( 'All wholesale customers', 'protech-wholesale' );
				}

				$labels = Tiers::get_tier_labels();
				return implode( ', ', array_map( static fn( string $t ): string => $labels[ $t ] ?? $t, $segment['tiers'] ) ) . ' ' . __( 'customers', 'protech-wholesale' );

			case self::TYPE_INACTIVE:
				/* translators: %d: number of days. */
				return sprintf( __( 'Customers with no order in %d days', 'protech-wholesale' ), $segment['days'] );

			case self::TYPE_NEVER_ORDERED:
				return __( 'Approved customers who have never ordered', 'protech-wholesale' );

			case self::TYPE_PREFERS_TEXT:
				return __( 'Said they prefer texts, but haven\'t opted in', 'protech-wholesale' );

			case self::TYPE_SELECTED:
				/* translators: %d: number of selected customers. */
				return sprintf( _n( '%d selected customer', '%d selected customers', count( $segment['user_ids'] ), 'protech-wholesale' ), count( $segment['user_ids'] ) );

			default:
				return __( 'All wholesale customers', 'protech-wholesale' );
		}
	}
}
