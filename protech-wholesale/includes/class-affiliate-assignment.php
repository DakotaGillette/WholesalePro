<?php
/**
 * Links a wholesale customer to a SliceWP affiliate for "lifetime
 * commissions" — some affiliates onboard wholesale accounts directly, with
 * no referral link or code ever clicked, so there is nothing for SliceWP's
 * normal cookie-based tracking to attach the order to.
 *
 * SliceWP Pro ships an add-on for exactly this ("Lifetime Commissions"):
 * an affiliate can be manually linked to a customer from the affiliate's
 * own edit screen, after which every future order from that customer earns
 * the affiliate a commission. This class calls the exact same underlying
 * SliceWP functions that button uses — confirmed by reading SliceWP's own
 * source (wp-admin's Plugin Editor, since Pro add-ons aren't publicly
 * published):
 *   - slicewp-pro/add-ons/lifetime-commissions/includes/admin/functions-actions-ajax.php
 *     (slicewp_action_ajax_link_customer(): slicewp_process_customer(),
 *     slicewp_get_customer_meta()/slicewp_update_customer_meta() on the
 *     'affiliate_id' customer-meta key)
 *   - .../includes/base/integrations/woocommerce/class-integration-woocommerce.php
 *     (get_lifetime_affiliate(): an order is credited to the customer's
 *     linked affiliate only if that SPECIFIC affiliate's own
 *     'lifetime_commissions' affiliate-meta is not empty/'disabled' — the
 *     sitewide switch alone does nothing for a manually-linked customer)
 * So this only ever touches ONE customer's own meta row per call — no
 * other customer of the affiliate, and no other affiliate, is ever
 * affected — matching the same scope SliceWP's own "Link Customer" button
 * has. The one necessary side effect: the first time a given affiliate is
 * ever used this way, their own 'lifetime_commissions' setting is switched
 * from "Site default" to "Custom" (never touched again after that, and
 * never touched at all if it's already something other than the empty
 * default). Sitewide lifetime commissions stay off, so no other affiliate
 * or customer is affected by that switch.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AffiliateAssignment
 */
class AffiliateAssignment {

	/** SliceWP customer-meta key the Lifetime Commissions add-on itself reads and writes. */
	private const META_KEY = 'affiliate_id';

	public static function is_available(): bool {
		return function_exists( 'slicewp_get_affiliates' )
			&& function_exists( 'slicewp_get_affiliate_name' )
			&& function_exists( 'slicewp_get_affiliate_meta' )
			&& function_exists( 'slicewp_update_affiliate_meta' )
			&& function_exists( 'slicewp_process_customer' )
			&& function_exists( 'slicewp_get_customer_by_user_id' )
			&& function_exists( 'slicewp_get_customer_meta' )
			&& function_exists( 'slicewp_update_customer_meta' )
			&& function_exists( 'slicewp_delete_customer_meta' );
	}

	/** @return array<int, string> active affiliate id => display label, for a dropdown. */
	public static function get_affiliate_options(): array {
		if ( ! self::is_available() ) {
			return array();
		}

		$options = array();

		foreach ( slicewp_get_affiliates( array( 'number' => -1, 'status' => 'active' ) ) as $affiliate ) {
			$id    = (int) $affiliate->get( 'id' );
			$name  = trim( (string) slicewp_get_affiliate_name( $id ) );
			$email = (string) slicewp_get_affiliate_email( $id );

			/* translators: %d: affiliate id, used only when the affiliate has no name or email on file. */
			$options[ $id ] = '' !== $name ? $name : ( '' !== $email ? $email : sprintf( __( 'Affiliate #%d', 'protech-wholesale' ), $id ) );
		}

		return $options;
	}

	/** The affiliate this WordPress user is currently linked to for lifetime commissions, or 0. */
	public static function get_assigned_affiliate_id( int $user_id ): int {
		if ( ! self::is_available() || $user_id <= 0 ) {
			return 0;
		}

		$customer = slicewp_get_customer_by_user_id( $user_id );

		if ( ! $customer ) {
			return 0;
		}

		return (int) slicewp_get_customer_meta( $customer->get( 'id' ), self::META_KEY, true );
	}

	/**
	 * Links this one wholesale customer to this one affiliate. Returns a
	 * warning message when the link was made but won't earn commissions
	 * yet (the affiliate's own lifetime-commissions setting is explicitly
	 * "Disabled" — a deliberate prior choice this never overrides), or ''
	 * when everything is in effect.
	 */
	public static function assign( int $user_id, int $affiliate_id ): string {
		if ( ! self::is_available() || $user_id <= 0 || $affiliate_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return '';
		}

		$customer_id = (int) slicewp_process_customer(
			array(
				'email'      => $user->user_email,
				'user_id'    => $user_id,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
			)
		);

		if ( ! $customer_id ) {
			return '';
		}

		$current_affiliate_id = (int) slicewp_get_customer_meta( $customer_id, self::META_KEY, true );

		if ( $current_affiliate_id && $current_affiliate_id !== $affiliate_id ) {
			// Scoped to this one customer's own link only — never another customer or affiliate.
			slicewp_delete_customer_meta( $customer_id, self::META_KEY, $current_affiliate_id );
		}

		slicewp_update_customer_meta( $customer_id, self::META_KEY, $affiliate_id );

		return self::ensure_affiliate_eligible( $affiliate_id );
	}

	/** Removes the link. The affiliate's own lifetime-commissions setting is left as-is. */
	public static function unassign( int $user_id ): void {
		if ( ! self::is_available() || $user_id <= 0 ) {
			return;
		}

		$customer = slicewp_get_customer_by_user_id( $user_id );

		if ( ! $customer ) {
			return;
		}

		$current_affiliate_id = (int) slicewp_get_customer_meta( $customer->get( 'id' ), self::META_KEY, true );

		if ( $current_affiliate_id ) {
			slicewp_delete_customer_meta( $customer->get( 'id' ), self::META_KEY, $current_affiliate_id );
		}
	}

	/**
	 * A customer link does nothing at all unless this specific affiliate's
	 * own 'lifetime_commissions' meta is non-empty (sitewide is
	 * deliberately left off) — switch it from the empty "Site default" to
	 * "Custom" the first time this affiliate is used this way, and leave
	 * it alone in every other case (already "Custom", or explicitly
	 * "Disabled" by a prior deliberate choice).
	 */
	private static function ensure_affiliate_eligible( int $affiliate_id ): string {
		$current = (string) slicewp_get_affiliate_meta( $affiliate_id, 'lifetime_commissions', true );

		if ( 'disabled' === $current ) {
			return __( 'Linked, but this affiliate has lifetime commissions explicitly disabled in SliceWP, so no commission will be earned until that is changed on their affiliate profile.', 'protech-wholesale' );
		}

		if ( '' === $current ) {
			slicewp_update_affiliate_meta( $affiliate_id, 'lifetime_commissions', 'custom' );
		}

		return '';
	}
}
