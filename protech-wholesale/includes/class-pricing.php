<?php
/**
 * R2: wholesale pricing engine — precedence, cart/checkout/email price
 * filters, per-role variation cache busting, and the wholesale label.
 *
 * Precedence: per-customer override > group (product/variation) price >
 * not available at wholesale (falls through to retail).
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

		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'restrict_retail_coupons' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'coupon_error_message' ), 10, 3 );
	}

	/**
	 * The price a specific user pays for a product/variation, or null if
	 * that item isn't available to them at wholesale.
	 */
	public static function get_wholesale_price( int $product_id, int $user_id ): ?float {
		if ( ! $user_id || ! Roles::is_wholesale_customer( $user_id ) ) {
			return null;
		}

		$overrides = get_user_meta( $user_id, Approval::META_PRICE_OVERRIDES, true );

		if ( is_array( $overrides ) && isset( $overrides[ $product_id ] ) && '' !== $overrides[ $product_id ] ) {
			return (float) $overrides[ $product_id ];
		}

		$group_price = get_post_meta( $product_id, ProductFields::META_WHOLESALE_PRICE, true );

		if ( '' !== $group_price && null !== $group_price ) {
			return (float) $group_price;
		}

		return null;
	}

	public static function is_available_at_wholesale( int $product_id, int $user_id ): bool {
		return null !== self::get_wholesale_price( $product_id, $user_id );
	}

	/**
	 * @param string      $price
	 * @param \WC_Product $product
	 */
	public function filter_price( $price, $product ) {
		$wholesale_price = self::get_wholesale_price( $product->get_id(), get_current_user_id() );

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
		$wholesale_price = self::get_wholesale_price( $product->get_id(), get_current_user_id() );

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
		$wholesale_price = self::get_wholesale_price( $variation->get_id(), get_current_user_id() );

		return null !== $wholesale_price ? wc_format_decimal( $wholesale_price ) : $price;
	}

	public function filter_variation_prices_array_sale_entry( $price, $variation, $product ) {
		$wholesale_price = self::get_wholesale_price( $variation->get_id(), get_current_user_id() );

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
	 * @param string      $price_html
	 * @param \WC_Product $product
	 */
	public function filter_price_html( string $price_html, $product ): string {
		$user_id = get_current_user_id();

		if ( ! Roles::is_wholesale_customer( $user_id ) ) {
			return $price_html;
		}

		if ( self::is_available_at_wholesale( $product->get_id(), $user_id ) ) {
			return $price_html . ' <span class="protech-wholesale-label">' . esc_html__( 'Wholesale price', 'protech-wholesale' ) . '</span>';
		}

		if ( 'fallback' === Settings::empty_price_behavior() && is_product() ) {
			return $price_html . ' <span class="protech-wholesale-retail-note">' . esc_html__( '(retail only — not available at wholesale)', 'protech-wholesale' ) . '</span>';
		}

		return $price_html;
	}

	/**
	 * Hides products with no wholesale price from the shop/search catalog
	 * for wholesale customers when the "hide" setting is active. Does not
	 * block direct access to the single product page — see DECISIONS.md.
	 *
	 * @param bool $visible
	 * @param int  $product_id
	 */
	public function filter_catalog_visibility( bool $visible, int $product_id ): bool {
		$user_id = get_current_user_id();

		if ( is_admin() || ! Roles::is_wholesale_customer( $user_id ) ) {
			return $visible;
		}

		if ( self::is_available_at_wholesale( $product_id, $user_id ) ) {
			return $visible;
		}

		return 'hide' === Settings::empty_price_behavior() ? false : $visible;
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
