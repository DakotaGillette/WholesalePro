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
			self::TIER_STANDARD => __( 'Standard', 'protech-wholesale' ),
			self::TIER_VOLUME   => __( 'Volume', 'protech-wholesale' ),
			self::TIER_BULK     => __( 'Bulk', 'protech-wholesale' ),
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
	 * Where the Volume marker sits on the sticky bar's track. The track is
	 * two straight segments rather than one: with the store defaults
	 * (Volume at 16 displays, Bulk at 16 cases = 128 displays) a single
	 * linear scale parks the Volume marker at 12.5%, cramming the whole
	 * first tier into the left eighth of the bar and leaving the first few
	 * displays a customer adds with no visible progress at all.
	 */
	public const VOLUME_MARKER_PERCENT = 40.0;

	/** Short, customer-facing tier name ("Volume"), as opposed to the admin labels above. */
	public static function get_tier_short_label( string $tier ): string {
		$labels = array(
			self::TIER_STANDARD => __( 'Standard', 'protech-wholesale' ),
			self::TIER_VOLUME   => __( 'Volume', 'protech-wholesale' ),
			self::TIER_BULK     => __( 'Bulk', 'protech-wholesale' ),
		);

		return $labels[ $tier ] ?? $labels[ self::TIER_STANDARD ];
	}

	/**
	 * Track position (0–100) for a combined display count: zero up to the
	 * Volume threshold spans 0 → VOLUME_MARKER_PERCENT, Volume up to Bulk
	 * spans the rest. Falls back to one linear segment if the thresholds
	 * are configured so that Volume isn't below Bulk.
	 * assets/js/global-tier-bar.js mirrors this for its add-to-cart preview
	 * only — what the bar actually shows always comes from here.
	 */
	public static function scale_percent( float $displays, int $volume_displays, int $bulk_displays ): float {
		if ( $bulk_displays <= 0 || $displays <= 0 ) {
			return 0.0;
		}

		if ( $volume_displays <= 0 || $volume_displays >= $bulk_displays ) {
			return round( min( 100.0, ( $displays / $bulk_displays ) * 100 ), 2 );
		}

		if ( $displays <= $volume_displays ) {
			return round( ( $displays / $volume_displays ) * self::VOLUME_MARKER_PERCENT, 2 );
		}

		$progress = ( $displays - $volume_displays ) / ( $bulk_displays - $volume_displays );

		return round( min( 100.0, self::VOLUME_MARKER_PERCENT + $progress * ( 100 - self::VOLUME_MARKER_PERCENT ) ), 2 );
	}

	/**
	 * What the current tier is saving this cart against Standard pricing:
	 * per eligible line, quantity x (Standard price - the price actually
	 * being paid). Zero at Standard, and for any line on a per-customer
	 * override (which ignores the ladder, so both prices are the same).
	 *
	 * @param array<int|string, array<string, mixed>> $items
	 */
	public static function get_savings_for_items( array $items, int $user_id, string $tier ): float {
		if ( self::TIER_STANDARD === $tier ) {
			return 0.0;
		}

		$savings = 0.0;

		foreach ( $items as $item ) {
			$product_id = (int) ( $item['variation_id'] ?: $item['product_id'] );
			$standard   = Pricing::get_wholesale_price( $product_id, $user_id, self::TIER_STANDARD );
			$current    = Pricing::get_wholesale_price( $product_id, $user_id, $tier );

			if ( null === $standard || null === $current || $current >= $standard ) {
				continue;
			}

			$savings += ( $standard - $current ) * (int) ( $item['quantity'] ?? 0 );
		}

		return round( $savings, 2 );
	}

	/**
	 * Everything the sticky global tier bar (class-global-tier-bar.php)
	 * needs to render, computed from a user's actual current cart. Shared
	 * by that class's initial render and its AJAX refresh so there's
	 * exactly one place that builds this copy/math.
	 *
	 * @return array{tier: string, tier_label: string, displays: float, cases: float, fill_percent: float, message: string, message_html: string, stats: string, subtotal: float, subtotal_html: string, savings: float, savings_html: string, volume_price_html: string, bulk_price_html: string, volume_threshold_displays: int, bulk_threshold_cases: int, volume_marker_percent: float, scale: array{volume_displays: int, bulk_displays: int, marker_percent: float}, ticks: array<int, array{percent: float, cases: int}>}
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

		// The Bulk marker is always the end of the track (100%); the Volume
		// marker is a fixed visual reference point (see VOLUME_MARKER_PERCENT).
		$volume_marker_percent = self::scale_percent( (float) $volume_threshold_displays, $volume_threshold_displays, $max_scale_displays );
		$fill_percent          = self::scale_percent( $totals['displays'], $volume_threshold_displays, $max_scale_displays );

		// The tier itself is decided per product (each line's own displays-
		// per-case), while the track is drawn from the store DEFAULT case
		// composition — so pin the fill to whichever marker the tier says
		// has really been reached, and keep it short of one that hasn't.
		if ( self::TIER_BULK === $tier ) {
			$fill_percent = 100.0;
		} elseif ( self::TIER_VOLUME === $tier ) {
			$fill_percent = min( 97.0, max( $fill_percent, $volume_marker_percent ) );
		} else {
			$fill_percent = min( $fill_percent, max( 0.0, $volume_marker_percent - 1.5 ) );
		}

		$unlocked_everything = __( "You've unlocked our best price and free shipping!", 'protech-wholesale' );
		$cases_remaining     = max( 0.0, $bulk_threshold_cases - $totals['cases'] );
		$displays_remaining  = max( 0.0, $volume_threshold_displays - $totals['displays'] );
		$remaining           = '';

		if ( self::TIER_BULK === $tier || ( self::TIER_VOLUME === $tier && $cases_remaining <= 0 ) ) {
			$template = $unlocked_everything;
		} elseif ( self::TIER_VOLUME === $tier ) {
			$remaining = self::format_quantity( $cases_remaining );
			$template  = 1.0 === round( $cases_remaining, 2 )
				/* translators: %s: number of cases, always "1" here. */
				? __( 'Free shipping unlocked! Add %s more case to unlock our best price.', 'protech-wholesale' )
				/* translators: %s: number of cases (e.g. "2" or "2.5"). */
				: __( 'Free shipping unlocked! Add %s more cases to unlock our best price.', 'protech-wholesale' );
		} else {
			$remaining = self::format_quantity( $displays_remaining );

			if ( $totals['displays'] <= 0 ) {
				/* translators: %s: number of displays. */
				$template = __( 'Add %s displays to unlock Volume pricing and free shipping.', 'protech-wholesale' );
			} elseif ( 1.0 === round( $displays_remaining, 2 ) ) {
				/* translators: %s: number of displays, always "1" here. */
				$template = __( 'Add %s more display to unlock Volume pricing and free shipping.', 'protech-wholesale' );
			} else {
				/* translators: %s: number of displays (e.g. "3"). */
				$template = __( 'Add %s more displays to unlock Volume pricing and free shipping.', 'protech-wholesale' );
			}
		}

		// Same sentence twice: plain (aria-live, the My Account panel) and
		// with the quantity emphasised for the bar itself. esc_html() leaves
		// the %s placeholder intact, so only our own <strong> is unescaped.
		$message      = sprintf( $template, $remaining );
		$message_html = sprintf( esc_html( $template ), '<strong>' . esc_html( $remaining ) . '</strong>' );

		$stats = sprintf(
			/* translators: 1: number of displays, 2: number of cases. */
			__( '%1$s displays (%2$s cases)', 'protech-wholesale' ),
			self::format_quantity( $totals['displays'] ),
			self::format_quantity( $totals['cases'] )
		);

		$savings  = self::get_savings_for_items( $items, $user_id, $tier );
		$subtotal = $cart instanceof \WC_Cart ? (float) $cart->get_subtotal() : 0.0;

		// Marker prices are the STORE DEFAULT Volume/Bulk prices with this
		// customer's hidden tier discount applied — a product with its own
		// Volume/Bulk override can differ, and the price table on each
		// product page stays the exact figure. See DECISIONS.md.
		$volume_price_html = '';
		$bulk_price_html   = '';

		/**
		 * Whether the sticky bar's Volume/Bulk markers show a per-pack price.
		 * Turn off for a catalogue where most products override the defaults.
		 *
		 * @param bool $show
		 */
		if ( apply_filters( 'protech_wholesale_tier_bar_show_prices', true ) ) {
			$discount          = max( 0.0, min( 100.0, (float) Tiers::get_tier_discount_percent( Tiers::get_user_tier( $user_id ) ) ) );
			$volume_price_html = self::plain_price( round( Settings::get_volume_price() * ( 1 - $discount / 100 ), 2 ) );
			$bulk_price_html   = self::plain_price( round( Settings::get_bulk_price() * ( 1 - $discount / 100 ), 2 ) );
		}

		return array(
			'tier'                       => $tier,
			'tier_label'                 => self::get_tier_short_label( $tier ),
			'displays'                   => $totals['displays'],
			'cases'                      => $totals['cases'],
			'fill_percent'               => $fill_percent,
			'message'                    => $message,
			'message_html'               => $message_html,
			'stats'                      => $stats,
			'subtotal'                   => $subtotal,
			'subtotal_html'              => self::plain_price( $subtotal ),
			'savings'                    => $savings,
			'savings_html'               => $savings > 0 ? self::plain_price( $savings ) : '',
			'volume_price_html'          => $volume_price_html,
			'bulk_price_html'            => $bulk_price_html,
			'volume_threshold_displays'  => $volume_threshold_displays,
			'bulk_threshold_cases'       => $bulk_threshold_cases,
			'volume_marker_percent'      => $volume_marker_percent,
			'scale'                      => array(
				'volume_displays' => $volume_threshold_displays,
				'bulk_displays'   => $max_scale_displays,
				'marker_percent'  => $volume_marker_percent,
			),
			'ticks'                      => self::get_track_ticks( $bulk_threshold_cases, $default_displays_per_case, $volume_threshold_displays, $volume_marker_percent ),
		);
	}

	/**
	 * Minor tick marks along the track at whole-case intervals, so the long
	 * Volume → Bulk stretch reads as a scale instead of an empty rail. At
	 * most eight, and none on top of the Volume marker or either end.
	 *
	 * @return array<int, array{percent: float, cases: int}>
	 */
	private static function get_track_ticks( int $bulk_cases, int $displays_per_case, int $volume_displays, float $volume_marker_percent ): array {
		if ( $bulk_cases < 2 ) {
			return array();
		}

		$step  = (int) max( 1, ceil( ( $bulk_cases - 1 ) / 8 ) );
		$ticks = array();

		for ( $cases = $step; $cases < $bulk_cases; $cases += $step ) {
			$percent = self::scale_percent( (float) ( $cases * $displays_per_case ), $volume_displays, $bulk_cases * $displays_per_case );

			if ( $percent < 4 || $percent > 96 || abs( $percent - $volume_marker_percent ) < 4 ) {
				continue;
			}

			$ticks[] = array(
				'percent' => $percent,
				'cases'   => $cases,
			);
		}

		return $ticks;
	}

	/**
	 * wc_price() without its markup — the store's own currency symbol,
	 * position and decimals, as text (entities intact) the bar's JS can
	 * drop straight into place.
	 */
	private static function plain_price( float $amount ): string {
		return wp_strip_all_tags( wc_price( $amount ) );
	}

	/** "3" for a whole number, "3.5" for a fraction. */
	public static function format_quantity( float $value ): string {
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

		$currency = get_woocommerce_currency_symbol();

		echo '<form method="post">';
		wp_nonce_field( self::OPT_TIER_LABELS_NONCE_ACTION, 'protech_wholesale_pricing_nonce' );

		// -- 1. Quantity pricing --------------------------------------------
		echo '<h2>' . esc_html__( 'Quantity pricing', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'The price ladder every wholesale customer sees. It is based on the combined display and case quantity across their whole cart, every product and colour together. Standard is always a product\'s own wholesale price; a product can override its Volume or Bulk price on its own edit screen.', 'protech-wholesale' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>';
		foreach (
			array(
				__( 'Price level', 'protech-wholesale' ),
				__( 'Unlocks at', 'protech-wholesale' ),
				/* translators: %s: currency symbol. */
				sprintf( __( 'Price per pack (%s)', 'protech-wholesale' ), $currency ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		echo '<tr><td><strong>' . esc_html__( 'Standard', 'protech-wholesale' ) . '</strong></td>';
		echo '<td>' . esc_html__( 'Every wholesale order', 'protech-wholesale' ) . '</td>';
		echo '<td>' . esc_html__( "Each product's own wholesale price", 'protech-wholesale' ) . '</td></tr>';

		echo '<tr><td><strong>' . esc_html__( 'Volume', 'protech-wholesale' ) . '</strong><br /><span class="description">' . esc_html__( 'Also unlocks free shipping', 'protech-wholesale' ) . '</span></td>';
		echo '<td><input type="number" step="1" min="1" name="protech_volume_threshold_displays" value="' . esc_attr( (string) Settings::get_volume_threshold_displays() ) . '" style="width:80px;" /> ' . esc_html__( 'combined displays', 'protech-wholesale' ) . '</td>';
		echo '<td><input type="number" step="0.01" min="0" name="protech_volume_price" value="' . esc_attr( (string) Settings::get_volume_price() ) . '" style="width:100px;" /></td></tr>';

		echo '<tr><td><strong>' . esc_html__( 'Bulk', 'protech-wholesale' ) . '</strong><br /><span class="description">' . esc_html__( 'Best price', 'protech-wholesale' ) . '</span></td>';
		echo '<td><input type="number" step="1" min="1" name="protech_bulk_threshold_cases" value="' . esc_attr( (string) Settings::get_bulk_threshold_cases() ) . '" style="width:80px;" /> ' . esc_html__( 'combined cases', 'protech-wholesale' ) . '</td>';
		echo '<td><input type="number" step="0.01" min="0" name="protech_bulk_price" value="' . esc_attr( (string) Settings::get_bulk_price() ) . '" style="width:100px;" /></td></tr>';

		echo '</tbody></table>';

		// -- 2. Displays and cases -------------------------------------------
		echo '<h2>' . esc_html__( 'Displays and cases', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'Wholesale quantities are whole displays. These are the store defaults; a product (or a single colour) can set its own on its Wholesale tab.', 'protech-wholesale' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="protech_default_case_size">' . esc_html__( 'Packs per display', 'protech-wholesale' ) . '</label></th><td><input type="number" step="1" min="1" id="protech_default_case_size" name="protech_default_case_size" value="' . esc_attr( (string) Settings::get_default_case_size() ) . '" style="width:100px;" /></td></tr>';
		echo '<tr><th><label for="protech_default_displays_per_case">' . esc_html__( 'Displays per case', 'protech-wholesale' ) . '</label></th><td><input type="number" step="1" min="1" id="protech_default_displays_per_case" name="protech_default_displays_per_case" value="' . esc_attr( (string) Settings::get_default_displays_per_case() ) . '" style="width:100px;" /></td></tr>';
		echo '</tbody></table>';

		// -- 3. Shipping -------------------------------------------------------
		echo '<h2>' . esc_html__( 'Wholesale shipping', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . esc_html__( 'Wholesale orders ship on their own rate: a flat fee below the Volume threshold, free at or above it. Retail customers never see it.', 'protech-wholesale' ) . '</p>';

		$zones_with_method = SetupChecks::zones_with_wholesale_shipping();
		$shipping_url      = admin_url( 'admin.php?page=wc-settings&tab=shipping' );

		if ( empty( $zones_with_method ) ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to WooCommerce shipping settings. */
					__( '"Protech Wholesale Shipping" has not been added to any shipping zone yet, so wholesale customers are still seeing the retail shipping options. <a href="%s">Add it to a zone</a>.', 'protech-wholesale' ),
					esc_url( $shipping_url )
				)
			) . '</p></div>';
		} else {
			echo '<p class="description">' . wp_kses_post(
				sprintf(
					/* translators: 1: comma-separated zone names, 2: link to WooCommerce shipping settings. */
					__( 'Active in: %1$s. <a href="%2$s">Manage zones</a>.', 'protech-wholesale' ),
					esc_html( implode( ', ', $zones_with_method ) ),
					esc_url( $shipping_url )
				)
			) . '</p>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		/* translators: %s: currency symbol. */
		echo '<tr><th><label for="protech_shipping_flat_rate">' . esc_html( sprintf( __( 'Flat rate below the Volume threshold (%s)', 'protech-wholesale' ), $currency ) ) . '</label></th><td><input type="number" step="0.01" min="0" id="protech_shipping_flat_rate" name="protech_shipping_flat_rate" value="' . esc_attr( (string) Settings::get_shipping_flat_rate() ) . '" style="width:100px;" /> <p class="description">' . esc_html( sprintf( /* translators: %d: number of displays. */ __( 'Free from %d combined displays (the Volume threshold above).', 'protech-wholesale' ), Settings::get_volume_threshold_displays() ) ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Retail free shipping', 'protech-wholesale' ) . '</th><td><label><input type="checkbox" name="protech_exclude_free_shipping" value="yes" ' . checked( Settings::exclude_free_shipping(), true, false ) . ' /> ' . esc_html__( 'Never give wholesale orders the retail free-shipping rule', 'protech-wholesale' ) . '</label><p class="description">' . esc_html__( 'Applies in zones where the wholesale method has not been added. Wholesale orders pay their own rate or earn free shipping at the Volume threshold, never through the retail "free over $30" rule.', 'protech-wholesale' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button();
		echo '</form>';
	}

	private static function save_pricing_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by render_pricing_tab() before calling this.
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

		// A checkbox: absent when unticked, so it is always written.
		update_option( Settings::OPT_EXCLUDE_FREE_SHIPPING, ! empty( $_POST['protech_exclude_free_shipping'] ) ? 'yes' : 'no' );
		// phpcs:enable

		Logger::info( 'Wholesale pricing settings updated by user #' . get_current_user_id() );
	}
}
