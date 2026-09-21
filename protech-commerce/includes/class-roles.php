<?php
/**
 * Wholesale roles: wholesale_pending (awaiting approval) and
 * wholesale_customer (approved, cloned from customer).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Roles
 */
class Roles {

	public const PENDING  = 'wholesale_pending';
	public const CUSTOMER = 'wholesale_customer';

	/** Custom capability granted to both roles, used for asset/UI gating. */
	public const CAP_WHOLESALE = 'protech_wholesale_customer';

	/** @var array<string, bool> "role:user id" => result, for this request. */
	private static array $role_cache = array();

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_create_roles' ) );

		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role', 'clean_user_cache', 'wp_login', 'wp_logout' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
	}

	public static function flush_cache(): void {
		self::$role_cache = array();
	}

	/**
	 * user_can() builds a fresh WP_User (and re-reads its capabilities)
	 * on every call, and these two checks run from every price filter on
	 * a page — hundreds of times per request. Memoized per user for the
	 * request; flushed by the role-change hooks in register_hooks().
	 */
	private static function has_role_cached( int $user_id, string $role ): bool {
		$key = $role . ':' . $user_id;

		if ( ! isset( self::$role_cache[ $key ] ) ) {
			self::$role_cache[ $key ] = user_can( $user_id, $role );
		}

		return self::$role_cache[ $key ];
	}

	/**
	 * Roles are created on activation; this is a safety net for sites
	 * where another plugin resets roles (e.g. after a WP core update).
	 */
	public function maybe_create_roles(): void {
		if ( ! get_role( self::CUSTOMER ) || ! get_role( self::PENDING ) ) {
			self::create_roles();
		}
	}

	public static function create_roles(): void {
		$customer_role = get_role( 'customer' );
		$capabilities   = $customer_role ? $customer_role->capabilities : array( 'read' => true );

		remove_role( self::CUSTOMER );
		remove_role( self::PENDING );

		add_role(
			self::CUSTOMER,
			__( 'Wholesale Customer', 'protech-wholesale' ),
			array_merge( $capabilities, array( self::CAP_WHOLESALE => true ) )
		);

		add_role(
			self::PENDING,
			__( 'Wholesale Applicant (Pending)', 'protech-wholesale' ),
			array( 'read' => true )
		);
	}

	public static function remove_roles(): void {
		remove_role( self::CUSTOMER );
		remove_role( self::PENDING );
	}

	/**
	 * True for any account holding capabilities beyond an ordinary
	 * shopper's (administrators, shop managers, editors, ...). The
	 * application/approval flow never changes such an account's roles on
	 * its own — see grant()/revoke() and ApplicationForm.
	 */
	public static function is_privileged( int $user_id ): bool {
		$privileged = user_can( $user_id, 'edit_posts' )
			|| user_can( $user_id, 'manage_woocommerce' )
			|| user_can( $user_id, 'edit_users' );

		return (bool) apply_filters( 'protech_wholesale_is_privileged_account', $privileged, $user_id );
	}

	/**
	 * Add one of the two wholesale roles to an account WITHOUT disturbing
	 * whatever else it already is. Everything here used to go through
	 * WP_User::set_role(), which replaces every role on the account — so a
	 * public application form submission carrying an administrator's email
	 * address turned that administrator into a read-only pending applicant,
	 * and un-flagging a shop manager demoted them to a plain customer. The
	 * two wholesale roles are only ever added to or removed from an
	 * account now (is_wholesale_customer()/is_wholesale_pending() are
	 * capability checks, so multiple roles are fine). Granting CUSTOMER
	 * drops PENDING and vice versa: an account is never both.
	 */
	public static function grant( int $user_id, string $role ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! in_array( $role, array( self::PENDING, self::CUSTOMER ), true ) ) {
			return;
		}

		$user->remove_role( self::CUSTOMER === $role ? self::PENDING : self::CUSTOMER );

		if ( ! in_array( $role, (array) $user->roles, true ) ) {
			$user->add_role( $role );
		}
	}

	/**
	 * Remove one of the two wholesale roles. An account left with no role at
	 * all is given WooCommerce's ordinary 'customer' role so it stays a
	 * working shopper account (WordPress would otherwise leave it with no
	 * capabilities whatsoever, not even 'read').
	 */
	public static function revoke( int $user_id, string $role ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! in_array( $role, array( self::PENDING, self::CUSTOMER ), true ) ) {
			return;
		}

		$user->remove_role( $role );

		if ( empty( $user->roles ) ) {
			$user->add_role( 'customer' );
		}
	}

	public static function is_wholesale_customer( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		return self::has_role_cached( $user_id, self::CUSTOMER );
	}

	public static function is_wholesale_pending( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		return self::has_role_cached( $user_id, self::PENDING );
	}
}
