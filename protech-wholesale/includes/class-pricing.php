<?php
/**
 * R2: wholesale pricing engine — precedence, cart/checkout/email price
 * filters, per-role variation cache busting, and the wholesale label.
 * See get_wholesale_price() for the full precedence order, and
 * class-volume-pricing.php for the Standard/Volume/Bulk quantity ladder
 * this now layers on top of the original flat group price.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pricing
 */
class Pricing {

	public function register_hooks(): void {
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price' ) as $hook ) {
			add_filter( $hook, array( $this, 'filter_price' ), 10, 2 );
		}

		foreach ( array( 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ) as $hook ) {
			add_filter( $hook, array( $this, 'filter_sale_price' ), 10, 2 );
		}

		foreach ( array( 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price' ) as $hook ) {
			add_filter( $hook, array( $this, 'filter_variation_prices_array_entry' ), 10, 3 );
		}
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'filter_variation_prices_array_sale_entry' ), 10, 3 );

		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'bust_variation_price_cache_per_role' ), 10, 3 );

		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 10, 2 );

		add_filter( 'woocommerce_product_is_visible', array( $this, 'filter_catalog_visibility' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'restrict_wholesale_only_purchase' ), 10, 2 );

		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'restrict_retail_coupons' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'coupon_error_message' ), 10, 3 );
	}

	/**
	 * The Standard/Volume/Bulk tier that applies right now, for a given
	 * user — based on the combined Display/Case quantity across every
	 * wholesale-eligible item already in their cart, if any.
	 *
	 * This is deliberately what every price filter below consults
	 * directly, rather than trying to push a tier-adjusted price onto
	 * cart line items via woocommerce_before_calculate_totals +
	 * set_price(): WooCommerce (and the Store API) re-invoke
	 * woocommerce_product_get_price on demand throughout a request —
	 * checkout, cart totals, the Store API's own item schema — and each
	 * of those calls would otherwise re-run filter_price() with no tier
	 * argument and silently overwrite whatever set_price() had done.
	 * Making the filter itself cart-aware avoids that race entirely.
	 */
	private static function get_current_tier( int $user_id ): string {
		if ( ! $user_id || null === WC()->cart ) {
			return VolumePricing::TIER_STANDARD;
		}

		$totals = VolumePricing::get_totals_for_items( WC()->cart->get_cart(), $user_id );

		return VolumePricing::get_tier_for_totals( $totals['displays'], $totals['cases'] );
	}

	/**
	 * The price a specific user pays for a product/variation, or null if
	 * that item isn't available to them at wholesale.
	 *
	 * Precedence: per-customer override (ignores quantity entirely) >
	 * the quantity-tier price for $tier (VolumePricing; a product may
	 * override Volume/Bulk, Standard is always its own wholesale price)
	 * > the customer's hidden tier discount off that (Bronze has none) >
	 * not available.
	 *
	 * @param string|null $tier One of VolumePricing::TIER_*; null = Standard,
	 *                          which is what every non-cart-aware call site uses.
	 */
	public static function get_wholesale_price( int $product_id, int $user_id, ?string $tier = null ): ?float {
		if ( ! $user_id || ! Roles::is_wholesale_customer( $user_id ) ) {
			return null;
		}

		$overrides = get_user_meta( $user_id, Approval::META_PRICE_OVERRIDES, true );

		if ( is_array( $overrides ) && isset( $overrides[ $product_id ] ) && is_numeric( $overrides[ $product_id ] ) ) {
			return (float) $overrides[ $product_id ];
		}

		$base_price = get_post_meta( $product_id, ProductFields::META_WHOLESALE_PRICE, true );

		if ( ! is_numeric( $base_price ) ) {
			return null; // Not wholesale-eligible at all, regardless of tier.
		}

		$tier       = $tier ?? VolumePricing::TIER_STANDARD;
		$list_price = VolumePricing::TIER_STANDARD === $tier
			? (float) $base_price
			: ( VolumePricing::get_tier_price( $product_id, $tier ) ?? (float) $base_price );

		$discount_percent = Tiers::get_tier_discount_percent( Tiers::get_user_tier( $user_id ) );

		if ( $discount_percent > 0 ) {
			return round( $list_price * ( 1 - $discount_percent / 100 ), 2 );
		}

		return $list_price;
	}

	public static function is_available_at_wholesale( int $product_id, int $user_id ): bool {
		return null !== self::get_wholesale_price( $product_id, $user_id );
	}

	/**
	 * Whether $product_id is available at wholesale, OR — if it's a
	 * variable product — whether ANY of its variations are. Cart/order
	 * line pricing (get_wholesale_price(), is_available_at_wholesale())
	 * never needs this: a line item always resolves to one specific
	 * simple product or variation ID, never an ambiguous parent. But
	 * WooCommerce hands filters like woocommerce_get_price_html and
	 * woocommerce_product_is_visible the PARENT product for a variable
	 * product's range/loop display — and the standard way to set up
	 * wholesale pricing on a variable product is per-variation (this
	 * plugin's own variation edit-screen fields), so the parent itself
	 * typically has no wholesale price of its own. Checking only the bare
	 * parent ID there would treat every such product as unavailable at
	 * wholesale — hiding it from the wholesale shop grid entirely and
	 * never showing the "Wholesale price" label — despite every one of
	 * its variations being correctly priced and purchasable.
	 */
	public static function is_available_at_wholesale_including_variations( int $product_id, int $user_id ): bool {
		if ( self::is_available_at_wholesale( $product_id, $user_id ) ) {
			return true;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return false;
		}

		foreach ( $product->get_children() as $variation_id ) {
			if ( self::is_available_at_wholesale( $variation_id, $user_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string      $price
	 * @param \WC_Product $product
	 */
	public function filter_price( $price, $product ) {
		$user_id          = get_current_user_id();
		$wholesale_price = self::get_wholesale_price( $product->get_id(), $user_id, self::get_current_tier( $user_id ) );

		return null !== $wholesale_price ? (string) wc_format_decimal( $wholesale_price ) : $price;
	}

	/**
	 * Wholesale has no separate "sale" concept — never show a retail
	 * strikethrough/sale badge on a wholesale-priced line.
	 *
	 * @param string      $price
	 * @param \WC_Product $product
	 */
	public function filter_sale_price( $price, $product ) {
		$user_id          = get_current_user_id();
		$wholesale_price = self::get_wholesale_price( $product->get_id(), $user_id, self::get_current_tier( $user_id ) );

		return null !== $wholesale_price ? '' : $price;
	}

	/**
	 * Keeps a variable product's displayed price RANGE consistent with
	 * the per-variation override when WooCommerce builds its cached
	 * min/max variation prices array.
	 *
	 * @param string      $price
	 * @param \WC_Product $variation
	 * @param \WC_Product $product
	 */
	public function filter_variation_prices_array_entry( $price, $variation, $product ) {
		$user_id          = get_current_user_id();
		$wholesale_price = self::get_wholesale_price( $variation->get_id(), $user_id, self::get_current_tier( $user_id ) );

		return null !== $wholesale_price ? wc_format_decimal( $wholesale_price ) : $price;
	}

	public function filter_variation_prices_array_sale_entry( $price, $variation, $product ) {
		$user_id          = get_current_user_id();
		$wholesale_price = self::get_wholesale_price( $variation->get_id(), $user_id, self::get_current_tier( $user_id ) );

		return null !== $wholesale_price ? '' : $price;
	}

	/**
	 * WooCommerce transient-caches get_variation_prices() keyed by this
	 * hash. Without role/user in it, the first user to load a variable
	 * product's price range "wins" the cache for everyone until it
	 * expires — a retail guest would then see a stale wholesale price,
	 * or vice versa. Add role + user to the hash so each combination
	 * gets its own cache entry.
	 *
	 * @param array       $hash
	 * @param \WC_Product $product
	 * @param bool        $for_display
	 */
	public function bust_variation_price_cache_per_role( array $hash, $product, $for_display ): array {
		$user_id = get_current_user_id();

		$hash[] = Roles::is_wholesale_customer( $user_id ) ? 'wholesale:' . $user_id : 'retail';

		return $hash;
	}

	/**
	 * A variation's "wholesale only" status lives on its parent product —
	 * the flag is a whole-product concept (hide the listing entirely),
	 * not something that varies per color/variation.
	 */
	private function wholesale_only_id( \WC_Product $product ): int {
		return $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	}

	private function wholesale_only_notice_html(): string {
		return '<span class="protech-wholesale-only-notice">' .
			esc_html__( 'Available to approved wholesale accounts only.', 'protech-wholesale' ) .
			' <a href="' . esc_url( home_url( '/wholesale-application' ) ) . '">' .
			esc_html__( 'Apply for a wholesale account', 'protech-wholesale' ) .
			'</a></span>';
	}

	/**
	 * @param string      $price_html
	 * @param \WC_Product $product
	 */
	public function filter_price_html( string $price_html, $product ): string {
		$user_id = get_current_user_id();

		// On the single product page, a "wholesale only" item shows an
		// apply-here message instead of a price for anyone who isn't an
		// approved wholesale customer — it's already hidden from shop/
		// search/category listings by filter_catalog_visibility(), so
		// this only matters for someone reaching it by direct URL.
		if ( is_product() && ! Roles::is_wholesale_customer( $user_id ) && ProductFields::is_wholesale_only( $this->wholesale_only_id( $product ) ) ) {
			return $this->wholesale_only_notice_html();
		}

		if ( ! Roles::is_wholesale_customer( $user_id ) ) {
			return $price_html;
		}

		if ( self::is_available_at_wholesale_including_variations( $product->get_id(), $user_id ) ) {
			return $price_html . ' <span class="protech-wholesale-label">' . esc_html__( 'Wholesale price', 'protech-wholesale' ) . '</span>';
		}

		if ( 'fallback' === Settings::empty_price_behavior() && is_product() ) {
			return $price_html . ' <span class="protech-wholesale-retail-note">' . esc_html__( '(retail only — not available at wholesale)', 'protech-wholesale' ) . '</span>';
		}

		return $price_html;
	}

	/**
	 * Two independent things this hides from the shop/search catalog,
	 * neither of which blocks direct access to the single product page
	 * itself (see filter_price_html()/restrict_wholesale_only_purchase()
	 * for what happens there instead):
	 *  - "Wholesale only" products, from anyone who isn't an approved
	 *    wholesale customer.
	 *  - Products with no wholesale price, from wholesale customers,
	 *    when the "hide" empty-price setting is active.
	 *
	 * @param bool $visible
	 * @param int  $product_id
	 */
	public function filter_catalog_visibility( bool $visible, int $product_id ): bool {
		if ( is_admin() ) {
			return $visible;
		}

		$user_id      = get_current_user_id();
		$is_wholesale = Roles::is_wholesale_customer( $user_id );

		if ( ProductFields::is_wholesale_only( $product_id ) && ! $is_wholesale ) {
			return false;
		}

		if ( ! $is_wholesale ) {
			return $visible;
		}

		if ( self::is_available_at_wholesale_including_variations( $product_id, $user_id ) ) {
			return $visible;
		}

		return 'hide' === Settings::empty_price_behavior() ? false : $visible;
	}

	/**
	 * A "wholesale only" product can never be added to cart — by anyone
	 * who isn't an approved wholesale customer, regardless of whether
	 * it also has a wholesale price. WooCommerce's own add-to-cart flow
	 * already checks is_purchasable() before allowing an add, so this
	 * alone blocks it both at the UI layer and server-side.
	 *
	 * @param bool        $purchasable
	 * @param \WC_Product $product
	 */
	public function restrict_wholesale_only_purchase( bool $purchasable, $product ): bool {
		if ( ! $purchasable || Roles::is_wholesale_customer() ) {
			return $purchasable;
		}

		return ! ProductFields::is_wholesale_only( $this->wholesale_only_id( $product ) );
	}

	/**
	 * @param bool       $valid
	 * @param \WC_Coupon $coupon
	 */
	public function restrict_retail_coupons( bool $valid, $coupon ): bool {
		if ( ! $valid || Settings::allow_retail_coupons() ) {
			return $valid;
		}

		if ( ! Roles::is_wholesale_customer() ) {
			return $valid;
		}

		return false;
	}

	/**
	 * @param string     $error
	 * @param int        $error_code
	 * @param \WC_Coupon $coupon
	 */
	public function coupon_error_message( string $error, int $error_code, $coupon ): string {
		if ( 100 === $error_code && Roles::is_wholesale_customer() && ! Settings::allow_retail_coupons() ) {
			return __( 'This coupon is not valid for wholesale accounts.', 'protech-wholesale' );
		}

		return $error;
	}
}
