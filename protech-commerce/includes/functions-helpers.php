<?php
/**
 * Small free functions kept outside the ProtechWholesale namespace so
 * theme/template code can call them without a `use` statement.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

use ProtechWholesale\CaseRules;
use ProtechWholesale\Pricing;
use ProtechWholesale\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True if the given (or current) user is an approved wholesale customer.
 */
function protech_wholesale_is_customer( int $user_id = 0 ): bool {
	return Roles::is_wholesale_customer( $user_id );
}

/**
 * True if the given (or current) user's application is pending approval.
 */
function protech_wholesale_is_pending( int $user_id = 0 ): bool {
	return Roles::is_wholesale_pending( $user_id );
}

/**
 * The price (in the store currency, per pack) a specific wholesale
 * customer pays for a product/variation, honoring the
 * customer-override > group-price > not-available precedence from R2.
 * Returns null when the item isn't available at wholesale for this user.
 */
function protech_wholesale_get_price( int $product_id, int $user_id = 0 ): ?float {
	$user_id = $user_id ?: get_current_user_id();

	return Pricing::get_wholesale_price( $product_id, $user_id );
}

/**
 * Packs per display configured for a product/variation ("case" in
 * CaseRules's own naming — see that class's docblock).
 */
function protech_wholesale_get_case_size( int $product_id ): int {
	return CaseRules::get_case_size( $product_id );
}

// protech_wholesale_get_minimum_order() was removed in 1.3.0 along with
// the unenforced dollar order minimum it reported.
