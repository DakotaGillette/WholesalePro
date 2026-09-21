<?php
/**
 * Starter kits: a product page that, instead of selling itself, adds one
 * display of every color of another product to the cart in one click.
 *
 * The kit product is only a landing page. What goes into the cart is the
 * REAL variations of the source product, one line per color — so stock
 * stays right per color, Reorder works on the resulting order, and the
 * price is whatever the ordinary pricing engine says: a 16-display kit
 * lands exactly on the Volume threshold, which is where its "$800 with
 * free shipping" comes from (160 packs x the Volume price), not from a
 * number typed onto the kit product.
 *
 * Composition is a rule, not a list, so it needs no upkeep as colors are
 * added. Two modes, set per kit:
 *   - "One of every color" (META_ONE_OF_EACH): exactly one display of
 *     every variation this customer can buy at wholesale and that is in
 *     stock — no target, no padding. 14 colors today is 14 displays;
 *     add a 15th tomorrow and the kit is 15 displays with nothing to
 *     edit, forever.
 *   - Padded to a target (the original mode, still available for a kit
 *     that should always land on a specific tier): one of every color,
 *     then if that is fewer than the target (default: the Volume
 *     threshold) the shortfall is made up with extra displays of the
 *     chosen filler colors, in order, round-robin. With 14 colors, a
 *     target of 16 and fillers Black, White that is every color plus a
 *     second Black and a second White.
 * Either way, a color that is out of stock is simply left out.
 *
 * Configured per product on the Wholesale tab (simple products).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StarterKit
 */
class StarterKit {

	public const META_ENABLED         = '_protech_kit_enabled'; // 'yes' | 'no'.
	public const META_SOURCE          = '_protech_kit_source'; // Variable product ID.
	public const META_ONE_OF_EACH     = '_protech_kit_one_of_each'; // 'yes' | 'no'. When 'yes', target/fillers below are ignored.
	public const META_TARGET_DISPLAYS = '_protech_kit_target_displays'; // int | '' (= Volume threshold).
	public const META_FILLERS         = '_protech_kit_fillers'; // int[] variation IDs, in priority order.

	public const NONCE_ACTION = 'protech_starter_kit';
	public const POST_ACTION  = 'protech_add_kit';
	public const MAX_KITS     = 50;

	public function register_hooks(): void {
		// Admin: fields on the Wholesale product tab, saved after
		// ProductFields' own save (10) so the flag re-sync sees them.
		add_action( 'protech_wholesale_product_data_panel', array( $this, 'render_admin_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_admin_fields' ), 15 );

		// The kit product itself is never what gets bought.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'make_kit_unpurchasable' ), 20, 2 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'keep_kit_visible_to_wholesale' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 20, 2 );

		// Where the add-to-cart form would be (30).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_on_product_page' ), 30 );

		add_action( 'wp_ajax_protech_kit_quote', array( $this, 'ajax_quote' ) );
		add_action( 'wp_ajax_' . self::POST_ACTION, array( $this, 'ajax_add' ) );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle_post' ) );
	}

	public static function is_kit( int $product_id ): bool {
		return $product_id > 0 && 'yes' === get_post_meta( $product_id, self::META_ENABLED, true );
	}

	/** True on the single product page of a kit, for a wholesale customer — i.e. when the kit UI renders. */
	public static function is_kit_page(): bool {
		return function_exists( 'is_product' ) && is_product() && Roles::is_wholesale_customer() && self::is_kit( (int) get_queried_object_id() );
	}

	public static function is_one_of_each( int $kit_id ): bool {
		return 'yes' === get_post_meta( $kit_id, self::META_ONE_OF_EACH, true );
	}

	public static function get_target_displays( int $kit_id ): int {
		$target = (int) get_post_meta( $kit_id, self::META_TARGET_DISPLAYS, true );

		return $target > 0 ? $target : Settings::get_volume_threshold_displays();
	}

	/**
	 * @return int[]
	 */
	public static function get_filler_ids( int $kit_id ): array {
		$fillers = get_post_meta( $kit_id, self::META_FILLERS, true );

		return is_array( $fillers ) ? array_values( array_filter( array_map( 'intval', $fillers ) ) ) : array();
	}

	/**
	 * What one kit contains for this customer, right now.
	 *
	 * @return array{lines: array<int, array{variation_id: int, parent_id: int, name: string, color: string, displays: int, packs: int}>, unavailable: string[], displays: int, packs: int, target: int}
	 */
	public static function get_composition( int $kit_id, int $user_id ): array {
		$one_of_each = self::is_one_of_each( $kit_id );

		$empty = array(
			'lines'       => array(),
			'unavailable' => array(),
			'displays'    => 0,
			'packs'       => 0,
			// Meaningless in "one of every color" mode — there is no fixed
			// target, the kit simply is however many colors exist.
			'target'      => $one_of_each ? 0 : self::get_target_displays( $kit_id ),
		);

		$source = wc_get_product( (int) get_post_meta( $kit_id, self::META_SOURCE, true ) );

		if ( ! $source instanceof \WC_Product || ! $source->is_type( 'variable' ) ) {
			return $empty;
		}

		$displays    = array(); // variation id => displays.
		$variations  = array(); // variation id => WC_Product_Variation.
		$unavailable = array();

		foreach ( $source->get_children() as $variation_id ) {
			$variation_id = (int) $variation_id;
			$variation    = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			// Not offered to this customer at wholesale: simply not part of
			// their kit, rather than something to apologise for.
			if ( ! Pricing::is_available_at_wholesale( $variation_id, $user_id ) ) {
				continue;
			}

			if ( ! self::can_supply( $variation, 1 ) ) {
				$unavailable[] = self::variation_label( $variation );
				continue;
			}

			$displays[ $variation_id ]   = 1;
			$variations[ $variation_id ] = $variation;
		}

		// "One of every color" skips all of this: no target, no padding —
		// whatever count() of available colors came out of the loop above
		// is the whole kit.
		if ( ! $one_of_each ) {
			// Make up any shortfall with the filler colors, round-robin, for
			// as long as at least one of them can still supply another display.
			$shortfall = $empty['target'] - count( $displays );
			$fillers   = array_values( array_intersect( self::get_filler_ids( $kit_id ), array_keys( $displays ) ) );

			while ( $shortfall > 0 && ! empty( $fillers ) ) {
				$progressed = false;

				foreach ( $fillers as $filler_id ) {
					if ( $shortfall <= 0 ) {
						break;
					}

					if ( ! self::can_supply( $variations[ $filler_id ], $displays[ $filler_id ] + 1 ) ) {
						continue;
					}

					++$displays[ $filler_id ];
					--$shortfall;
					$progressed = true;
				}

				if ( ! $progressed ) {
					break;
				}
			}
		}

		$lines          = array();
		$total_displays = 0;
		$total_packs    = 0;

		foreach ( $displays as $variation_id => $count ) {
			$packs = CaseRules::quantity_for_cases( $variation_id, $count );

			$lines[] = array(
				'variation_id' => $variation_id,
				'parent_id'    => $source->get_id(),
				'name'         => self::variation_label( $variations[ $variation_id ] ),
				'color'        => self::swatch_color( $variations[ $variation_id ] ),
				'displays'     => $count,
				'packs'        => $packs,
			);

			$total_displays += $count;
			$total_packs    += $packs;
		}

		return array(
			'lines'       => $lines,
			'unavailable' => $unavailable,
			'displays'    => $total_displays,
			'packs'       => $total_packs,
			// In "one of every color" mode there is no fixed target — report
			// however many displays this kit actually turned out to be.
			'target'      => $one_of_each ? $total_displays : $empty['target'],
		);
	}

	/** In stock, and — where stock is counted — enough of it for this many displays. */
	private static function can_supply( \WC_Product $variation, int $displays ): bool {
		if ( ! $variation->is_in_stock() || ! $variation->is_purchasable() ) {
			return false;
		}

		return $variation->has_enough_stock( CaseRules::quantity_for_cases( $variation->get_id(), $displays ) );
	}

	/** "Arctic Blue" rather than "Protech Premium Matte Sleeves - Arctic Blue". */
	private static function variation_label( \WC_Product_Variation $variation ): string {
		$parts = array();

		foreach ( $variation->get_variation_attributes() as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}

			$taxonomy = str_replace( 'attribute_', '', (string) $key );
			$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) $value, $taxonomy ) : false;

			$parts[] = $term instanceof \WP_Term ? $term->name : ucwords( str_replace( array( '-', '_' ), ' ', (string) $value ) );
		}

		return ! empty( $parts ) ? implode( ', ', $parts ) : $variation->get_name();
	}

	/**
	 * The color swatch the store already assigned to this variation's
	 * color term (the "Variation Swatches for WooCommerce" plugin keeps it
	 * in term meta), if there is one. Purely decorative: '' when absent.
	 */
	private static function swatch_color( \WC_Product_Variation $variation ): string {
		foreach ( $variation->get_variation_attributes() as $key => $value ) {
			$taxonomy = str_replace( 'attribute_', '', (string) $key );

			if ( '' === (string) $value || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', (string) $value, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			// Not sanitize_hex_color(): that lives with the Customizer and
			// isn't loaded on every front-end request.
			$color = trim( (string) get_term_meta( $term->term_id, 'product_attribute_color', true ) );

			if ( preg_match( '/^#(?:[A-Fa-f0-9]{3}){1,2}$/', $color ) ) {
				return strtolower( $color );
			}
		}

		return '';
	}

	/**
	 * What $kits kits would cost this customer if added to their cart as it
	 * stands — priced at the tier the cart would then have reached, which is
	 * exactly what the pricing engine will charge once they are in it.
	 *
	 * @return array{kits: int, displays: int, packs: int, cases: float, tier: string, tier_label: string, total: float, total_html: string, pack_price_html: string, free_shipping: bool, summary: string, note: string}
	 */
	public static function get_quote( int $kit_id, int $kits, int $user_id ): array {
		$kits        = max( 1, min( self::MAX_KITS, $kits ) );
		$composition = self::get_composition( $kit_id, $user_id );

		$items = array();

		foreach ( $composition['lines'] as $line ) {
			$items[] = array(
				'product_id'   => $line['parent_id'],
				'variation_id' => $line['variation_id'],
				'quantity'     => $line['packs'] * $kits,
			);
		}

		$cart       = function_exists( 'WC' ) ? WC()->cart : null;
		$cart_items = $cart instanceof \WC_Cart ? array_values( $cart->get_cart() ) : array();

		$kit_totals = VolumePricing::get_totals_for_items( $items, $user_id );
		$all_totals = VolumePricing::get_totals_for_items( array_merge( $cart_items, $items ), $user_id );
		$tier       = VolumePricing::get_tier_for_totals( $all_totals['displays'], $all_totals['cases'] );

		$total       = 0.0;
		$pack_prices = array();

		foreach ( $items as $item ) {
			$price = Pricing::get_wholesale_price( (int) $item['variation_id'], $user_id, $tier );

			if ( null === $price ) {
				continue;
			}

			$total        += $price * $item['quantity'];
			$pack_prices[] = $price;
		}

		$pack_prices     = array_values( array_unique( $pack_prices ) );
		$pack_price_html = 1 === count( $pack_prices ) ? wp_strip_all_tags( wc_price( $pack_prices[0] ) ) : '';
		$free_shipping   = VolumePricing::TIER_STANDARD !== $tier;
		$tier_label      = VolumePricing::get_tier_short_label( $tier );

		if ( '' === $tier_label ) {
			$tier_label = __( 'Wholesale', 'protech-wholesale' ); // The base tier has no name of its own.
		}

		$summary = sprintf(
			/* translators: 1: number of displays, 2: number of packs. */
			__( '%1$s displays · %2$s packs', 'protech-wholesale' ),
			number_format_i18n( $composition['displays'] * $kits ),
			number_format_i18n( $composition['packs'] * $kits )
		);

		if ( '' !== $pack_price_html ) {
			$note = $free_shipping
				/* translators: 1: tier name, 2: price per pack. */
				? sprintf( __( '%1$s pricing (%2$s/pack) with free shipping.', 'protech-wholesale' ), $tier_label, $pack_price_html )
				/* translators: 1: tier name, 2: price per pack. */
				: sprintf( __( '%1$s pricing (%2$s/pack).', 'protech-wholesale' ), $tier_label, $pack_price_html );
		} else {
			$note = $free_shipping
				/* translators: %s: tier name. */
				? sprintf( __( '%s pricing with free shipping.', 'protech-wholesale' ), $tier_label )
				/* translators: %s: tier name. */
				: sprintf( __( '%s pricing.', 'protech-wholesale' ), $tier_label );
		}

		return array(
			'kits'            => $kits,
			'displays'        => $composition['displays'] * $kits,
			'packs'           => $composition['packs'] * $kits,
			'cases'           => (float) $kit_totals['cases'],
			'tier'            => $tier,
			'tier_label'      => $tier_label,
			'total'           => round( $total, 2 ),
			'total_html'      => wp_strip_all_tags( wc_price( $total ) ),
			'pack_price_html' => $pack_price_html,
			'free_shipping'   => $free_shipping,
			'summary'         => $summary,
			'note'            => $note,
		);
	}

	/**
	 * Adds $kits kits to the current cart as ordinary variation lines.
	 *
	 * @return array{added_lines: int, added_displays: int, notes: string[]}|\WP_Error
	 */
	public static function add_to_cart( int $kit_id, int $kits, int $user_id ) {
		if ( ! self::is_kit( $kit_id ) || ! Roles::is_wholesale_customer( $user_id ) ) {
			return new \WP_Error( 'protech_kit_unavailable', __( 'This starter kit is not available.', 'protech-wholesale' ) );
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return new \WP_Error( 'protech_kit_no_cart', __( 'Your cart could not be loaded. Please try again.', 'protech-wholesale' ) );
		}

		$kits        = max( 1, min( self::MAX_KITS, $kits ) );
		$composition = self::get_composition( $kit_id, $user_id );

		if ( empty( $composition['lines'] ) ) {
			return new \WP_Error( 'protech_kit_empty', __( 'Nothing in this starter kit is available right now.', 'protech-wholesale' ) );
		}

		$added_lines    = 0;
		$added_displays = 0;
		$notes          = array();

		foreach ( $composition['lines'] as $line ) {
			$result = WC()->cart->add_to_cart( $line['parent_id'], $line['packs'] * $kits, $line['variation_id'] );

			if ( false === $result ) {
				$reason = self::take_cart_error();

				$notes[] = '' !== $reason
					? $line['name'] . ': ' . $reason
					: sprintf(
						/* translators: %s: color name. */
						__( 'Could not add %s to your cart.', 'protech-wholesale' ),
						$line['name']
					);
				continue;
			}

			++$added_lines;
			$added_displays += $line['displays'] * $kits;
		}

		foreach ( $composition['unavailable'] as $name ) {
			$notes[] = sprintf(
				/* translators: %s: color name. */
				__( '%s is out of stock and was left out.', 'protech-wholesale' ),
				$name
			);
		}

		return array(
			'added_lines'    => $added_lines,
			'added_displays' => $added_displays,
			'notes'          => $notes,
		);
	}

	/**
	 * When WC_Cart::add_to_cart() refuses a line it says why in an error
	 * notice ("you cannot add that amount — we have 20 in stock"). Lift
	 * that text into the kit's own report and drop the notice, so the
	 * reason appears once, beside the kit, instead of resurfacing on
	 * whatever page the customer happens to load next.
	 */
	private static function take_cart_error(): string {
		if ( ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
			return '';
		}

		$notices = wc_get_notices();

		if ( empty( $notices['error'] ) || ! is_array( $notices['error'] ) ) {
			return '';
		}

		$last = end( $notices['error'] );
		$text = is_array( $last ) ? (string) ( $last['notice'] ?? '' ) : (string) $last;

		unset( $notices['error'] );
		wc_set_notices( $notices );

		return trim( wp_strip_all_tags( $text ) );
	}

	private static function added_message( int $displays ): string {
		return sprintf(
			/* translators: %s: number of displays. */
			_n( 'Starter kit added: %s display is in your cart.', 'Starter kit added: %s displays are in your cart.', $displays, 'protech-wholesale' ),
			number_format_i18n( $displays )
		);
	}

	/**
	 * @param bool        $purchasable
	 * @param \WC_Product $product
	 */
	public function make_kit_unpurchasable( $purchasable, $product ) {
		return $product instanceof \WC_Product && self::is_kit( $product->get_id() ) ? false : $purchasable;
	}

	/**
	 * A kit needs no wholesale price of its own, which would otherwise get
	 * it hidden from a wholesale customer's shop under the "hide" setting.
	 *
	 * @param bool $visible
	 * @param int  $product_id
	 */
	public function keep_kit_visible_to_wholesale( $visible, $product_id ) {
		if ( $visible || is_admin() || ! Roles::is_wholesale_customer() || ! self::is_kit( (int) $product_id ) ) {
			return $visible;
		}

		$product = wc_get_product( (int) $product_id );

		return $product instanceof \WC_Product && 'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility();
	}

	/**
	 * The kit's price, wherever WooCommerce prints one, is what the kit
	 * would cost this customer right now — not a number typed onto the kit
	 * product. Non-wholesale viewers keep whatever Pricing decided (for a
	 * wholesale-only kit, the "approved accounts only" notice).
	 *
	 * @param string      $price_html
	 * @param \WC_Product $product
	 */
	public function filter_price_html( $price_html, $product ) {
		if ( ! $product instanceof \WC_Product || ! self::is_kit( $product->get_id() ) || ! Roles::is_wholesale_customer() ) {
			return $price_html;
		}

		$quote = self::get_quote( $product->get_id(), 1, get_current_user_id() );

		if ( $quote['displays'] <= 0 ) {
			return $price_html;
		}

		return '<span class="protech-kit-price" data-protech-kit-total>' . esc_html( $quote['total_html'] ) . '</span>'
			. ' <span class="protech-wholesale-label">' . esc_html__( 'Wholesale price', 'protech-wholesale' ) . '</span>';
	}

	public function render_on_product_page(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! Roles::is_wholesale_customer() || ! self::is_kit( $product->get_id() ) ) {
			return;
		}

		$user_id     = get_current_user_id();
		$composition = self::get_composition( $product->get_id(), $user_id );

		wc_get_template(
			'starter-kit.php',
			array(
				'kit_id'      => $product->get_id(),
				'composition' => $composition,
				'quote'       => self::get_quote( $product->get_id(), 1, $user_id ),
				'post_url'    => admin_url( 'admin-post.php' ),
				'max_kits'    => self::MAX_KITS,
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
	}

	private function requested_kit_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- verified by each caller.
		return isset( $_REQUEST['kit_id'] ) ? absint( $_REQUEST['kit_id'] ) : 0;
	}

	private function requested_kits(): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- verified by each caller.
		return isset( $_REQUEST['kits'] ) ? max( 1, absint( $_REQUEST['kits'] ) ) : 1;
	}

	public function ajax_quote(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$kit_id = $this->requested_kit_id();

		if ( ! Roles::is_wholesale_customer() || ! self::is_kit( $kit_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not available.', 'protech-wholesale' ) ), 403 );
		}

		wp_send_json_success( self::get_quote( $kit_id, $this->requested_kits(), get_current_user_id() ) );
	}

	public function ajax_add(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$kit_id = $this->requested_kit_id();
		$result = self::add_to_cart( $kit_id, $this->requested_kits(), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		if ( $result['added_lines'] < 1 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nothing from this starter kit could be added to your cart.', 'protech-wholesale' ),
					'notes'   => $result['notes'],
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'        => self::added_message( $result['added_displays'] ),
				'notes'          => $result['notes'],
				'added_displays' => $result['added_displays'],
				'quote'          => self::get_quote( $kit_id, 1, get_current_user_id() ),
			)
		);
	}

	/**
	 * The same add, for a visitor whose JavaScript never ran: an ordinary
	 * form post that ends on the cart page, like Reorder.
	 */
	public function handle_post(): void {
		if ( ! is_user_logged_in() || ! Roles::is_wholesale_customer() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		$nonce = isset( $_POST['protech_kit_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['protech_kit_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'This form has expired. Please go back and try again.', 'protech-wholesale' ) );
		}

		// admin-post.php runs under is_admin(): no cart until asked for.
		wc_load_cart();

		$result = self::add_to_cart( $this->requested_kit_id(), $this->requested_kits(), get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			foreach ( $result['notes'] as $note ) {
				wc_add_notice( $note, 'notice' );
			}

			if ( $result['added_lines'] > 0 ) {
				wc_add_notice( self::added_message( $result['added_displays'] ), 'success' );
			}
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * "Starter kit" group on the Wholesale product data tab.
	 *
	 * @param \WC_Product|null $product_object
	 */
	public function render_admin_fields( $product_object ): void {
		$product_id = $product_object instanceof \WC_Product ? $product_object->get_id() : 0;
		$source_id  = $product_id ? (int) get_post_meta( $product_id, self::META_SOURCE, true ) : 0;
		$source     = $source_id ? wc_get_product( $source_id ) : null;
		$target     = $product_id ? (string) get_post_meta( $product_id, self::META_TARGET_DISPLAYS, true ) : '';

		echo '<div class="options_group show_if_simple">';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ENABLED,
				'label'       => __( 'Starter kit', 'protech-wholesale' ),
				'description' => __( 'This product is a starter kit: its page adds one display of every color of the product chosen below to the cart. The kit itself is never sold, so its own prices and pack sizes above are ignored.', 'protech-wholesale' ),
				'value'       => $product_id && self::is_kit( $product_id ) ? 'yes' : 'no',
			)
		);

		echo '<p class="form-field"><label for="' . esc_attr( self::META_SOURCE ) . '">' . esc_html__( 'Kit is built from', 'protech-wholesale' ) . '</label>';
		echo '<select class="wc-product-search" style="width:50%;" id="' . esc_attr( self::META_SOURCE ) . '" name="' . esc_attr( self::META_SOURCE ) . '" data-allow_clear="true" data-placeholder="' . esc_attr__( 'Search for a variable product…', 'protech-wholesale' ) . '" data-action="woocommerce_json_search_products">';

		if ( $source instanceof \WC_Product ) {
			echo '<option value="' . esc_attr( (string) $source_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $source->get_formatted_name() ) ) . '</option>';
		}

		echo '</select> ' . wc_help_tip( __( 'The variable product whose colors make up the kit. Every color with a wholesale price, in stock, gets one display.', 'protech-wholesale' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes.

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ONE_OF_EACH,
				'label'       => __( 'One of every color', 'protech-wholesale' ),
				'description' => __( 'The kit is exactly one display of every color currently available — no padding to a fixed count. Add or remove a color on the product above and the kit updates on its own; the two fields below are ignored while this is checked.', 'protech-wholesale' ),
				'value'       => $product_id && self::is_one_of_each( $product_id ) ? 'yes' : 'no',
			)
		);

		echo '<div id="protech-kit-target-fields">';

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_TARGET_DISPLAYS,
				'label'             => __( 'Displays in a kit', 'protech-wholesale' ),
				/* translators: %d: the Standard threshold, in displays. */
				'description'       => sprintf( __( 'If there are fewer colors than this, the difference is made up with the colors below. Leave empty to use the Standard threshold (%d), which is what earns the kit Standard pricing and free shipping.', 'protech-wholesale' ), Settings::get_volume_threshold_displays() ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step'        => '1',
					'min'         => '1',
					'placeholder' => (string) Settings::get_volume_threshold_displays(),
				),
				'value'             => $target,
			)
		);

		echo '<p class="form-field"><label for="' . esc_attr( self::META_FILLERS ) . '">' . esc_html__( 'Make up the difference with', 'protech-wholesale' ) . '</label>';
		echo '<select class="wc-product-search" multiple="multiple" style="width:50%;" id="' . esc_attr( self::META_FILLERS ) . '" name="' . esc_attr( self::META_FILLERS ) . '[]" data-sortable="true" data-placeholder="' . esc_attr__( 'Search for colors, e.g. Black, White…', 'protech-wholesale' ) . '" data-action="woocommerce_json_search_products_and_variations">';

		$filler_ids = $product_id ? self::get_filler_ids( $product_id ) : array();

		foreach ( $filler_ids as $filler_id ) {
			$filler = wc_get_product( $filler_id );

			if ( $filler instanceof \WC_Product ) {
				echo '<option value="' . esc_attr( (string) $filler_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $filler->get_formatted_name() ) ) . '</option>';
			}
		}

		echo '</select> ' . wc_help_tip( __( 'Colors of the product above that get an extra display while there are fewer colors than displays in the kit, in this order. Once there are enough colors these are no longer used.', 'protech-wholesale' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes.

		echo '</div>'; // #protech-kit-target-fields.

		echo '</div>';
	}

	/**
	 * Runs inside WooCommerce's product save, after its nonce check.
	 */
	public function save_admin_fields( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- woocommerce_process_product_meta fires after WooCommerce verifies woocommerce_meta_nonce.
		$enabled     = ! empty( $_POST[ self::META_ENABLED ] );
		$source      = isset( $_POST[ self::META_SOURCE ] ) ? absint( $_POST[ self::META_SOURCE ] ) : 0;
		$one_of_each = ! empty( $_POST[ self::META_ONE_OF_EACH ] );
		$target      = isset( $_POST[ self::META_TARGET_DISPLAYS ] ) ? absint( $_POST[ self::META_TARGET_DISPLAYS ] ) : 0;
		$fillers     = isset( $_POST[ self::META_FILLERS ] ) && is_array( $_POST[ self::META_FILLERS ] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST[ self::META_FILLERS ] ) ) ) ) )
			: array();
		// phpcs:enable

		update_post_meta( $post_id, self::META_ENABLED, $enabled ? 'yes' : 'no' );
		update_post_meta( $post_id, self::META_SOURCE, $source > 0 ? $source : '' );
		update_post_meta( $post_id, self::META_ONE_OF_EACH, $one_of_each ? 'yes' : 'no' );
		update_post_meta( $post_id, self::META_TARGET_DISPLAYS, $target > 0 ? $target : '' );
		update_post_meta( $post_id, self::META_FILLERS, $fillers );

		// ProductFields synced this flag a moment ago, before it could know.
		ProductFields::sync_has_wholesale_price_flag( $post_id );
	}
}
