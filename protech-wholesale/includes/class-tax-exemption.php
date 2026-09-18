<?php
/**
 * Reads and writes the tax-exempt status of a wholesale customer.
 *
 * This store already has a working mechanism for this: the "Stripe Tax for
 * WooCommerce" plugin (active on staging, with "Enable Stripe Tax" on)
 * stores a per-account `tax_exemption` user meta value ('none' |
 * 'customer_exempt' | 'reverse_charge') on a "Stripe Tax Exemptions"
 * section it adds to every user's profile screen, and sends it straight
 * through as the `taxability_override` on every real Stripe Tax
 * calculation (see Stripe\StripeTaxForWooCommerce\Stripe\TaxExemptions and
 * Stripe\StripeTaxForWooCommerce\Stripe\CalculateTax::get_order_tax_exempt()
 * in that plugin). That's what actually zeroes out tax at checkout on this
 * site — WooCommerce's own native VAT-exempt flag is not consulted, since
 * Stripe Tax replaces WooCommerce's own tax-rate calculation entirely.
 *
 * So there's no need for this plugin to reinvent that switch — doing so
 * would only add a second, look-alike "tax exempt" control that doesn't
 * actually affect what a customer is charged. This class reads and writes
 * that same meta key, so the Wholesale → Customers tab can offer the same
 * three states as a dropdown right in the row, without opening each
 * customer's profile individually.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TaxExemption
 */
class TaxExemption {

	/** User meta key owned by the "Stripe Tax for WooCommerce" plugin, not this one. */
	public const META_KEY = 'tax_exemption';

	public const STATUS_TAXABLE        = 'none';
	public const STATUS_EXEMPT         = 'customer_exempt';
	public const STATUS_REVERSE_CHARGE = 'reverse_charge';

	/** @return array<string, string> status value => label, in the order the Stripe Tax plugin's own dropdown uses. */
	public static function status_labels(): array {
		return array(
			self::STATUS_TAXABLE        => __( 'Taxable', 'protech-wholesale' ),
			self::STATUS_EXEMPT         => __( 'Exempt', 'protech-wholesale' ),
			self::STATUS_REVERSE_CHARGE => __( 'Reverse charge', 'protech-wholesale' ),
		);
	}

	/** The raw status, defaulting to "Taxable" for an unset or unrecognised value — same fallback the Stripe Tax plugin itself uses. */
	public static function status( int $user_id ): string {
		$value = $user_id > 0 ? (string) get_user_meta( $user_id, self::META_KEY, true ) : '';

		return array_key_exists( $value, self::status_labels() ) ? $value : self::STATUS_TAXABLE;
	}

	public static function is_exempt( int $user_id ): bool {
		return in_array( self::status( $user_id ), array( self::STATUS_EXEMPT, self::STATUS_REVERSE_CHARGE ), true );
	}

	public static function set_status( int $user_id, string $status ): void {
		if ( $user_id <= 0 || ! array_key_exists( $status, self::status_labels() ) ) {
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $status );
	}
}
