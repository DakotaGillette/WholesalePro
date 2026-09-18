<?php
/**
 * Reads the tax-exempt status of a wholesale customer.
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
 * actually affect what a customer is charged. This class just surfaces the
 * existing value where the wholesale admin actually looks: the Customers
 * tab, so tax-exempt accounts are visible at a glance without opening each
 * profile individually.
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
	private const META_KEY = 'tax_exemption';

	private const STATUS_EXEMPT        = 'customer_exempt';
	private const STATUS_REVERSE_CHARGE = 'reverse_charge';

	public static function is_exempt( int $user_id ): bool {
		return in_array( self::status( $user_id ), array( self::STATUS_EXEMPT, self::STATUS_REVERSE_CHARGE ), true );
	}

	private static function status( int $user_id ): string {
		return $user_id > 0 ? (string) get_user_meta( $user_id, self::META_KEY, true ) : '';
	}

	/** A short label for the Customers-tab badge, or '' when not exempt. */
	public static function label( int $user_id ): string {
		switch ( self::status( $user_id ) ) {
			case self::STATUS_EXEMPT:
				return __( 'Exempt', 'protech-wholesale' );
			case self::STATUS_REVERSE_CHARGE:
				return __( 'Reverse charge', 'protech-wholesale' );
			default:
				return '';
		}
	}
}
