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

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_create_roles' ) );
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

	public static function is_wholesale_customer( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		return user_can( $user_id, self::CUSTOMER );
	}

	public static function is_wholesale_pending( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		return user_can( $user_id, self::PENDING );
	}
}
