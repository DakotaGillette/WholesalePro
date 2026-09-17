<?php
/**
 * Quantity-break wholesale pricing — Standard/Volume/Bulk, based on the
 * combined Display+Case quantity across every wholesale-eligible item in
 * the cart (not per product/color). Unlike the hidden Bronze/Silver/
 * Gold/Platinum customer tiers (class-tiers.php), this ladder is meant
 * to be visible to the buyer — see class-global-tier-bar.php for the
 * sticky progress-bar UI that shows it.
 *
 * Precedence for a wholesale customer's final per-pack price:
 *   1. Per-customer price override (Approval::META_PRICE_OVERRIDES) —
 *      ignores quantity entirely.
 *   2. This quantity ladder's price for whichever tier the cart has
 *      reached (Standard/Volume/Bulk), using a product's own override
 *      for that tier if set, else the store default.
 *   3. The customer's hidden tier discount percentage (class-tiers.php),
 *      applied on top of #2.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class VolumePricing
 */
class VolumePricing {

	public const TIER_STANDARD = 'standard';
	public const TIER_VOLUME   = 'volume';
	public const TIER_BULK     = 'bulk';

	public const OPT_TIER_LABELS_NONCE_ACTION = 'protech_wholesale_save_pricing';

	public function register_hooks(): void {
		// Rendered/saved from Approval's "Pricing" tab; nothing to hook
		// globally here — the actual price/shipping application lives in
		// Pricing's cart-aware price filters and WholesaleShippingMethod,
		// both of which call into this class's static helpers.
	}

	/**
	 * @return array<string, string> tier slug => label.
	 */
	public static function get_tier_labels(): array {
		return array(
			self::TIER_STANDARD => __( 'Standard (Tier 1)', 'protech-wholesale' ),
			self::TIER_VOLUME   => __( 'Volume (Tier 2)', 'protech-wholesale' ),
			self::TIER_BULK     => __( 'Bulk (Tier 3)', 'protech-wholesale' ),
		);
	}

	/**
	 * Displays per case for a product/variation — its own setting if set,
	 * else its parent's (for a variation), else the store default.
	 */
	public static function get_displays_per_case( int $product_id ): int {
		$own = (int) get_post_meta( $product_id, ProductFields::META_DISPLAYS_PER_CASE, true );

		if ( $own > 0 ) {
			return $own;
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof \WC_Product && $product->is_type( 'variation' ) ) {
			$parent = (int) get_post_meta( $product->get_parent_id(), ProductFields::META_DISPLAYS_PER_CASE, true );

			if ( $parent > 0 ) {
				return $parent;
			}
		}

		return Settings::get_default_displays_per_case();
	}

	public static function displays_for_quantity( int $product_id, int $quantity ): float {
		return CaseRules::cases_for_quantity( $product_id, $quantity );
	}

	public static function cases_for_quantity( int $product_id, int $quantity ): float {
		$displays_per_case = self::get_displays_per_case( $product_id );

		return $displays_per_case > 0 ? self::displays_for_quantity( $product_id, $quantity ) / $displays_per_case : 0.0;
	}

	/**
	 * Combined Display/Case totals across every wholesale-eligible line
	 * in a set of cart/package items (each with 'product_id',
	 * 'variation_id', 'quantity' keys — the shape both WC_Cart::get_cart()
	 * and a shipping $package['contents'] array share).
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array{displays: float, cases: float}
	 */
	public static function get_totals_for_items( array $items, int $user_id ): array {
		$total_displays = 0.0;
		$total_cases    = 0.0;

		foreach ( $items as $item ) {
			$product_id = $item['variation_id'] ?: $item['product_id'];

			if ( ! Pricing::is_available_at_wholesale( $product_id, $user_id ) ) {
				continue;
			}

			$quantity        = (int) ( $item['quantity'] ?? 0 );
			$total_displays += self::displays_for_quantity( $product_id, $quantity );
			$total_cases    += self::cases_for_quantity( $product_id, $quantity );
		}

		return array(
			'displays' => $total_displays,
			'cases'    => $total_cases,
		);
	}

	public static function get_tier_for_totals( float $total_displays, float $total_cases ): string {
		if ( $total_cases >= Settings::get_bulk_threshold_cases() ) {
			return self::TIER_BULK;
		}

		if ( $total_displays >= Settings::get_volume_threshold_displays() ) {
			return self::TIER_VOLUME;
		}

		return self::TIER_STANDARD;
	}

	/**
	 * Everything the sticky global tier bar (class-global-tier-bar.php)
	 * needs to render, computed from a user's actual current cart. Shared
	 * by that class's initial render and its AJAX refresh so there's
	 * exactly one place that builds this copy/math.
	 *
	 * @return array{tier: string, displays: float, cases: float, fill_percent: float, message: string, stats: string, subtotal_html: string, volume_threshold_displays: int, bulk_threshold_cases: int, volume_marker_percent: float}
	 */
	public static function get_tier_bar_state( int $user_id ): array {
		$cart  = function_exists( 'WC' ) ? WC()->cart : null;
		$items = ( $cart instanceof \WC_Cart ) ? $cart->get_cart() : array();

		$totals = self::get_totals_for_items( $items, $user_id );
		$tier   = self::get_tier_for_totals( $totals['displays'], $totals['cases'] );

		$volume_threshold_displays  = Settings::get_volume_threshold_displays();
		$bulk_threshold_cases       = Settings::get_bulk_threshold_cases();
		$default_displays_per_case  = max( 1, Settings::get_default_displays_per_case() );
		$max_scale_displays         = $bulk_threshold_cases * $default_displays_per_case;

		$fill_percent = $max_scale_displays > 0
			? min( 100, ( $totals['displays'] / $max_scale_displays ) * 100 )
			: 0.0;

		// Where the Volume marker sits along the track — a static layout
		// based on the store's default case composition (not live cart
		// state, since it's a fixed visual reference point). The Bulk
		// marker is always the end of the track (100%).
		$volume_marker_percent = $max_scale_displays > 0
			? min( 100, ( $volume_threshold_displays / $max_scale_displays ) * 100 )
			: 0.0;

		if ( self::TIER_BULK === $tier ) {
			$message = __( "You've unlocked our best price and free shipping!", 'protech-wholesale' );
		} elseif ( self::TIER_VOLUME === $tier ) {
			$cases_remaining = max( 0, $bulk_threshold_cases - $totals['cases'] );

			$message = $cases_remaining > 0
				? sprintf(
					/* translators: %s: number of cases (e.g. "2" or "2.5"). */
					__( 'Free shipping unlocked! Add %s more case worth to unlock our best price.', 'protech-wholesale' ),
					self::format_quantity( $cases_remaining )
				)
				: __( "You've unlocked our best price and free shipping!", 'protech-wholesale' );
		} else {
			$displays_remaining = max( 0, $volume_threshold_displays - $totals['displays'] );

			$message = sprintf(
				/* translators: %s: number of displays (e.g. "3" or "3.5"). */
				__( 'Add %s more display worth to unlock better pricing and free shipping.', 'protech-wholesale' ),
				self::format_quantity( $displays_remaining )
			);
		}

		$stats = sprintf(
			/* translators: 1: number of displays, 2: number of cases. */
			__( '%1$s displays (%2$s cases)', 'protech-wholesale' ),
			self::format_quantity( $totals['displays'] ),
			self::format_quantity( $totals['cases'] )
		);

		return array(
			'tier'                       => $tier,
			'displays'                   => $totals['displays'],
			'cases'                      => $totals['cases'],
			'fill_percent'               => $fill_percent,
			'message'                    => $message,
			'stats'                      => $stats,
			'subtotal_html'              => wp_strip_all_tags( wc_price( $cart instanceof \WC_Cart ? (float) $cart->get_subtotal() : 0.0 ) ),
			'volume_threshold_displays'  => $volume_threshold_displays,
			'bulk_threshold_cases'       => $bulk_threshold_cases,
			'volume_marker_percent'      => $volume_marker_percent,
		);
	}

	/** "3" for a whole number, "3.5" for a fraction. */
	private static function format_quantity( float $value ): string {
		$rounded = round( $value, 2 );

		return ( 0.0 === fmod( $rounded, 1.0 ) ) ? (string) (int) round( $rounded ) : number_format( $rounded, 1 );
	}

	/**
	 * The list price (before customer-tier discount, before per-customer
	 * override) for a product at a given quantity tier. Standard is
	 * always the product's own wholesale price — there's no separate
	 * override field for it, since that field IS the group price. Null
	 * means the product has no wholesale price set at all (not eligible
	 * at any tier) — callers should already have gated on that via
	 * Pricing::is_available_at_wholesale() before reaching here.
	 */
	public static function get_tier_price( int $product_id, string $tier ): ?float {
		if ( self::TIER_STANDARD === $tier ) {
			$price = get_post_meta( $product_id, ProductFields::META_WHOLESALE_PRICE, true );

			return is_numeric( $price ) ? (float) $price : null;
		}

		$meta_key = self::TIER_BULK === $tier ? ProductFields::META_BULK_PRICE : ProductFields::META_VOLUME_PRICE;
		$override = get_post_meta( $product_id, $meta_key, true );

		if ( is_numeric( $override ) ) {
			return (float) $override;
		}

		return self::TIER_BULK === $tier ? Settings::get_bulk_price() : Settings::get_volume_price();
	}

	/**
	 * The "Pricing" tab of the WooCommerce → Wholesale admin screen: the
	 * three storewide tier thresholds/prices, the case-composition
	 * defaults, and the wholesale shipping rate — all the pieces a store
	 * owner needs to set up the quantity ladder without touching a
	 * single product.
	 */
	public static function render_pricing_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		if ( isset( $_POST['protech_wholesale_pricing_nonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_pricing_nonce'] ) ),
				self::OPT_TIER_LABELS_NONCE_ACTION
			)
		) {
			self::save_pricing_tab();
			echo '<div class="updated notice"><p>' . esc_html__( 'Pricing settings saved.', 'protech-wholesale' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'The quantity ladder every wholesale customer sees, based on the combined Display/Case quantity across their whole cart. A product can override the Volume/Bulk price on its own edit screen; Standard is always that product\'s own wholesale price.', 'protech-wholesale' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::OPT_TIER_LABELS_NONCE_ACTION, 'protech_wholesale_pricing_nonce' );

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Tier', 'protech-wholesale' ),
				__( 'Unlocks at', 'protech-wholesale' ),
				__( 'Price per pack', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		echo '<tr><td><strong>' . esc_html__( 'Standard (Tier 1)', 'protech-wholesale' ) . '</strong></td>';
		echo '<td>' . esc_html__( 'Every wholesale order', 'protech-wholesale' ) . '</td>';
		echo '<td>' . esc_html__( "Each product's own wholesale price", 'protech-wholesale' ) . '</td></tr>';

		echo '<tr><td><strong>' . esc_html__( 'Volume (Tier 2)', 'protech-wholesale' ) . '</strong></td>';
		echo '<td><input type="number" step="1" min="1" name="protech_volume_threshold_displays" value="' . esc_attr( (string) Settings::get_volume_threshold_displays() ) . '" style="width:80px;" /> ' . esc_html__( 'combined displays (also the free-shipping threshold below)', 'protech-wholesale' ) . '</td>';
		echo '<td><input type="number" step="0.01" min="0" name="protech_volume_price" value="' . esc_attr( (string) Settings::get_volume_price() ) . '" style="width:100px;" /></td></tr>';

		echo '<tr><td><strong>' . esc_html__( 'Bulk (Tier 3)', 'protech-wholesale' ) . '</strong></td>';
		echo '<td><input type="number" step="1" min="1" name="protech_bulk_threshold_cases" value="' . esc_attr( (string) Settings::get_bulk_threshold_cases() ) . '" style="width:80px;" /> ' . esc_html__( 'combined cases', 'protech-wholesale' ) . '</td>';
		echo '<td><input type="number" step="0.01" min="0" name="protech_bulk_price" value="' . esc_attr( (string) Settings::get_bulk_price() ) . '" style="width:100px;" /></td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Case composition defaults', 'protech-wholesale' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="protech_default_case_size">' . esc_html__( 'Packs per display', 'protech-wholesale' ) . '</label></th><td><input type="number" step="1" min="1" id="protech_default_case_size" name="protech_default_case_size" value="' . esc_attr( (string) Settings::get_default_case_size() ) . '" style="width:100px;" /></td></tr>';
		echo '<tr><th><label for="protech_default_displays_per_case">' . esc_html__( 'Displays per case', 'protech-wholesale' ) . '</label></th><td><input type="number" step="1" min="1" id="protech_default_displays_per_case" name="protech_default_displays_per_case" value="' . esc_attr( (string) Settings::get_default_displays_per_case() ) . '" style="width:100px;" /></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Wholesale shipping', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add "Protech Wholesale Shipping" as a shipping method to each zone under WooCommerce → Settings → Shipping — it\'s invisible to retail customers.', 'protech-wholesale' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="protech_shipping_flat_rate">' . esc_html__( 'Flat rate below the Volume threshold', 'protech-wholesale' ) . '</label></th><td><input type="number" step="0.01" min="0" id="protech_shipping_flat_rate" name="protech_shipping_flat_rate" value="' . esc_attr( (string) Settings::get_shipping_flat_rate() ) . '" style="width:100px;" /> <p class="description">' . esc_html__( 'Free shipping at/above the Volume threshold set above.', 'protech-wholesale' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button();
		echo '</form>';
	}

	private static function save_pricing_tab(): void {
		$fields = array(
			'protech_volume_threshold_displays' => Settings::OPT_VOLUME_THRESHOLD_DISPLAYS,
			'protech_volume_price'              => Settings::OPT_VOLUME_PRICE,
			'protech_bulk_threshold_cases'       => Settings::OPT_BULK_THRESHOLD_CASES,
			'protech_bulk_price'                => Settings::OPT_BULK_PRICE,
			'protech_default_case_size'          => Settings::OPT_DEFAULT_CASE_SIZE,
			'protech_default_displays_per_case'  => Settings::OPT_DEFAULT_DISPLAYS_PER_CASE,
			'protech_shipping_flat_rate'         => Settings::OPT_SHIPPING_FLAT_RATE,
		);

		foreach ( $fields as $post_key => $option_key ) {
			if ( ! isset( $_POST[ $post_key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) );

			if ( is_numeric( $value ) && (float) $value >= 0 ) {
				update_option( $option_key, (string) $value );
			}
		}

		Logger::info( 'Wholesale pricing settings updated by user #' . get_current_user_id() );
	}
}
