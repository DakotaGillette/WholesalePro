<?php
/**
 * R3: Display size (packs per display) and order minimum enforcement.
 *
 * Internal naming note: this class and its meta keys/methods still say
 * "case" throughout (get_case_size(), META_CASE_SIZE, "cases_for_
 * quantity()"...) from before the store introduced a second, bigger
 * "Case" unit (8 Displays — see class-volume-pricing.php). Renaming
 * every internal identifier was judged not worth the churn/risk; only
 * user-facing text was updated to say "Display". So here: "case" in
 * code == "display" to the customer (10 packs of one color by default).
 * Inventory itself always stays in packs — this class only ever
 * validates and annotates, never changes what's tracked in stock.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CaseRules
 */
class CaseRules {

	public function register_hooks(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 4 );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'set_quantity_step' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'add_case_count_to_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_case_count_on_order_item' ), 10, 4 );

		// Display/Case unit selector on the single product page.
		add_action( 'woocommerce_before_add_to_cart_quantity', array( $this, 'render_unit_selector' ) );

		// The legend sits in the summary, above the price table (TierLadder, 25).
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_quantity_legend' ), 24 );
		add_filter( 'woocommerce_available_variation', array( $this, 'add_unit_data_to_variation' ), 10, 3 );

		// Header cart badge / Blocks mini-cart count, in displays.
		add_filter( 'woocommerce_cart_contents_count', array( $this, 'count_cart_in_displays' ) );

		// Classic cart & checkout.
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_and_notify' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_cart_and_notify' ) );

		// WooCommerce Blocks (Store API) cart & checkout — confirmed
		// load-bearing on this site (both pages are Blocks).
		add_filter( 'woocommerce_store_api_cart_errors', array( $this, 'validate_cart_for_store_api' ), 10, 2 );

		// The Blocks cart's own quantity controls ignore the classic
		// woocommerce_quantity_input_args filter; these are their
		// equivalent — the +/- steps by display size, and the Store API
		// rejects a non-multiple on update-item/add-item itself.
		add_filter( 'woocommerce_store_api_product_quantity_multiple_of', array( $this, 'store_api_quantity_rule' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( $this, 'store_api_quantity_rule' ), 10, 3 );

		// The order minimum no longer blocks checkout (see DECISIONS.md,
		// 2026-09-17) — it's now purely the free-shipping threshold,
		// enforced by WholesaleShippingMethod below.
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_wholesale_shipping_method' ) );
		add_filter( 'woocommerce_package_rates', array( $this, 'hide_retail_shipping_for_wholesale' ), 10, 2 );
	}

	public function register_wholesale_shipping_method( array $methods ): array {
		$methods[ WholesaleShippingMethod::METHOD_ID ] = WholesaleShippingMethod::class;

		return $methods;
	}

	/**
	 * Once the store owner adds "Protech Wholesale Shipping" to a zone,
	 * a wholesale customer in that zone should only ever see that one
	 * rate — not also the store's normal Flat Rate/Free Shipping options
	 * meant for retail. Only strips those two method types, and only
	 * once our method is actually present for this package, so a
	 * wholesale customer in a zone where the method hasn't been added
	 * yet still sees the zone's normal methods rather than nothing.
	 *
	 * @param \WC_Shipping_Rate[] $rates
	 * @param array               $package
	 */
	public function hide_retail_shipping_for_wholesale( array $rates, array $package ): array {
		if ( ! Roles::is_wholesale_customer() ) {
			return $rates;
		}

		$has_wholesale_rate = false;

		foreach ( $rates as $rate ) {
			if ( WholesaleShippingMethod::METHOD_ID === $rate->get_method_id() ) {
				$has_wholesale_rate = true;
				break;
			}
		}

		if ( ! $has_wholesale_rate ) {
			return $rates;
		}

		/**
		 * Which retail shipping methods to hide from wholesale customers
		 * once the wholesale method is present. Add e.g. 'local_pickup' or
		 * a carrier plugin's method id if the store uses them.
		 *
		 * @param string[] $method_ids
		 */
		$retail_methods = (array) apply_filters( 'protech_wholesale_retail_shipping_methods', array( 'flat_rate', 'free_shipping' ) );

		foreach ( $rates as $key => $rate ) {
			if ( in_array( $rate->get_method_id(), $retail_methods, true ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * Both the Store API's "multiple of" and "minimum" for a wholesale
	 * customer are the display size (packs per display).
	 *
	 * @param int|float                 $value
	 * @param \WC_Product               $product
	 * @param array<string, mixed>|null $cart_item
	 * @return int|float
	 */
	public function store_api_quantity_rule( $value, $product, $cart_item = null ) {
		if ( ! $product instanceof \WC_Product || ! self::sold_by_the_display( $product->get_id() ) ) {
			return $value;
		}

		return self::get_case_size( $product->get_id() );
	}

	/**
	 * Whether the display/case rules apply to this product for this
	 * customer: only when they are a wholesale customer AND the product is
	 * actually sold to them at wholesale. A product with no wholesale
	 * price — the Vendor Starter Kit, an unlisted $800 one-off that new
	 * vendors buy from a link — is bought on ordinary retail terms by
	 * everyone, quantity 1 allowed, counting toward nothing. Until
	 * 1.3.0 the rules applied to every product a wholesale customer
	 * touched, which made that kit unbuyable for them.
	 */
	public static function sold_by_the_display( int $product_id, int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();

		// "Including variations": WooCommerce builds a variable product's
		// quantity box (woocommerce_quantity_input_args) from the PARENT,
		// which never carries a price itself — its colors do. Checking the
		// bare parent said "not wholesale" for the flagship sleeves, so the
		// theme's own stepper came back and the Display/Case control had
		// nothing to drive (1.3.0, caught by the owner on staging). Cart
		// lines and the Store API always pass the variation, so for them
		// this is the plain per-item check.
		return Roles::is_wholesale_customer( $user_id ) && Pricing::is_available_at_wholesale_including_variations( $product_id, $user_id );
	}

	/**
	 * Packs per display: the product's own value, else (for a variation)
	 * its parent's — set once on the parent's Wholesale tab instead of on
	 * every color — else the store default. Same fallback order as
	 * VolumePricing::get_displays_per_case().
	 */
	public static function get_case_size( int $product_id ): int {
		$size = (int) get_post_meta( $product_id, ProductFields::META_CASE_SIZE, true );

		if ( $size > 0 ) {
			return $size;
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );

		if ( $parent_id > 0 ) {
			$size = (int) get_post_meta( $parent_id, ProductFields::META_CASE_SIZE, true );

			if ( $size > 0 ) {
				return $size;
			}
		}

		return Settings::get_default_case_size();
	}

	public static function is_multiple_of_case( int $product_id, int $quantity ): bool {
		$case_size = self::get_case_size( $product_id );

		return $quantity > 0 && 0 === ( $quantity % $case_size );
	}

	public static function cases_for_quantity( int $product_id, int $quantity ): float {
		$case_size = self::get_case_size( $product_id );

		return $case_size > 0 ? $quantity / $case_size : 0.0;
	}

	public static function quantity_for_cases( int $product_id, int $cases ): int {
		return $cases * self::get_case_size( $product_id );
	}

	/**
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 * @param int  $variation_id
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		$item_id = (int) ( $variation_id ?: $product_id );

		if ( ! self::sold_by_the_display( $item_id ) ) {
			return $passed;
		}

		if ( self::is_multiple_of_case( $item_id, (int) $quantity ) ) {
			return $passed;
		}

		$case_size = self::get_case_size( $item_id );

		wc_add_notice(
			sprintf(
				/* translators: %d: display size in packs. */
				__( 'Wholesale orders are placed in full displays of %1$d packs. Please enter a quantity that is a multiple of %1$d.', 'protech-wholesale' ),
				$case_size
			),
			'error'
		);

		return false;
	}

	/**
	 * @param array       $args
	 * @param \WC_Product $product
	 */
	public function set_quantity_step( array $args, $product ): array {
		if ( ! $product instanceof \WC_Product || ! self::sold_by_the_display( $product->get_id() ) ) {
			return $args;
		}

		$case_size = self::get_case_size( $product->get_id() );

		// WooCommerce's own quantity-input template reads 'step', not
		// 'input_step' — the latter is silently ignored, which is why the
		// on-page +/- stepper was incrementing by 1 instead of jumping by
		// the case size, even though server-side validation
		// (validate_add_to_cart() below) was never affected by this and
		// always correctly blocked a non-multiple quantity regardless.
		$args['step']      = $case_size;
		$args['min_value'] = max( $case_size, (float) ( $args['min_value'] ?? 0 ) );

		if ( empty( $args['input_value'] ) || (int) $args['input_value'] < $case_size ) {
			$args['input_value'] = $case_size;
		}

		// Marks this input for assets/js/unit-selector.js to find and
		// hide once the Display/Case selector below takes it over — its
		// value (in packs) is still what actually submits with the form.
		$args['classes'] = array_merge( (array) ( $args['classes'] ?? array() ), array( 'protech-native-qty' ) );

		return $args;
	}

	/**
	 * The "Order by: Display / Case" convenience control shown just above
	 * the (now hidden, via JS) real pack-quantity input on the single
	 * product page — the plugin still only ever tracks/sells in packs;
	 * this is purely a friendlier way for a wholesale customer to enter
	 * that pack count. See assets/js/unit-selector.js for the conversion
	 * logic and add_unit_data_to_variation() for how it stays correct
	 * across a variable product's color switches.
	 */
	public function render_unit_selector(): void {
		// woocommerce_before_add_to_cart_quantity fires with no arguments —
		// simple.php and variable.php's add-to-cart templates both expect
		// hooked callbacks to read the current product from this global,
		// same as ProductFields::render_product_data_panel() already does
		// for its own template-hook rendering.
		global $product;

		if ( ! $product instanceof \WC_Product || ! Roles::is_wholesale_customer() ) {
			return;
		}

		if ( ! Pricing::is_available_at_wholesale_including_variations( $product->get_id(), get_current_user_id() ) ) {
			return;
		}

		$case_size         = self::get_case_size( $product->get_id() );
		$displays_per_case = max( 1, VolumePricing::get_displays_per_case( $product->get_id() ) );
		$case_packs        = $case_size * $displays_per_case;
		// One control, top to bottom: pick the unit (two big radio cards),
		// dial in how many, read back exactly what that means in packs.
		// The theme's own pack stepper underneath is hidden by
		// unit-selector.js once it takes over (see .protech-hidden-qty in
		// wholesale.css for why that needs !important on Salient) — and
		// stays as the working fallback if that script never runs. The
		// radios' name is not one WooCommerce reads; a non-AJAX submit
		// carries it along harmlessly.
		?>
		<div
			class="protech-unit-selector"
			data-case-size="<?php echo esc_attr( (string) $case_size ); ?>"
			data-displays-per-case="<?php echo esc_attr( (string) $displays_per_case ); ?>"
		>
			<fieldset class="protech-unit-fieldset">
				<legend class="protech-unit-legend"><?php esc_html_e( 'Order by', 'protech-wholesale' ); ?></legend>
				<div class="protech-unit-toggle">
					<label class="protech-unit-option">
						<input type="radio" name="protech_unit" value="display" checked />
						<span class="protech-unit-option-body">
							<span class="protech-unit-option-name"><?php esc_html_e( 'Display', 'protech-wholesale' ); ?></span>
							<span class="protech-unit-option-meta" data-protech-unit-meta="display"><?php echo esc_html( sprintf( /* translators: %d: packs per display. */ _n( '%d pack', '%d packs', $case_size, 'protech-wholesale' ), $case_size ) ); ?></span>
						</span>
					</label>
					<label class="protech-unit-option">
						<input type="radio" name="protech_unit" value="case" />
						<span class="protech-unit-option-body">
							<span class="protech-unit-option-name"><?php esc_html_e( 'Case', 'protech-wholesale' ); ?></span>
							<span class="protech-unit-option-meta" data-protech-unit-meta="case"><?php echo esc_html( sprintf( /* translators: 1: displays per case, 2: packs per case. */ __( '%1$d displays · %2$d packs', 'protech-wholesale' ), $displays_per_case, $case_packs ) ); ?></span>
						</span>
					</label>
				</div>
			</fieldset>

			<div class="protech-unit-qty-row">
				<label class="protech-unit-legend" for="protech-unit-qty"><?php esc_html_e( 'How many', 'protech-wholesale' ); ?></label>
				<div class="protech-unit-qty-controls">
					<div class="protech-stepper">
						<button type="button" class="protech-stepper-btn" data-protech-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'protech-wholesale' ); ?>">&minus;</button>
						<input type="number" id="protech-unit-qty" class="protech-unit-qty" value="1" min="1" step="1" inputmode="numeric" autocomplete="off" />
						<button type="button" class="protech-stepper-btn" data-protech-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'protech-wholesale' ); ?>">+</button>
					</div>
					<span class="protech-unit-word" id="protech-unit-word"><?php esc_html_e( 'display', 'protech-wholesale' ); ?></span>
				</div>
			</div>

			<p class="protech-unit-selector-hint" id="protech-unit-selector-hint" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * "How wholesale quantities work" (templates/quantity-legend.php), above
	 * the wholesale price table on a single product page: most wholesale
	 * buyers meet the words display and case here for the first time, and
	 * everything below (the price table's quantities, the order control)
	 * assumes they know them.
	 */
	public function render_quantity_legend(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! Roles::is_wholesale_customer() ) {
			return;
		}

		if ( ! Pricing::is_available_at_wholesale_including_variations( $product->get_id(), get_current_user_id() ) ) {
			return;
		}

		$case_size         = self::get_case_size( $product->get_id() );
		$displays_per_case = max( 1, VolumePricing::get_displays_per_case( $product->get_id() ) );
		$volume_threshold  = Settings::get_volume_threshold_displays();

		wc_get_template(
			'quantity-legend.php',
			array(
				'case_size'         => $case_size,
				'displays_per_case' => $displays_per_case,
				'case_packs'        => $case_size * $displays_per_case,
				'volume_threshold'  => $volume_threshold,
				'threshold_cases'   => VolumePricing::format_quantity( $volume_threshold / $displays_per_case ),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);
	}

	/**
	 * The header cart badge (Salient reads WC_Cart's legacy
	 * cart_contents_count property) and the Blocks mini-cart (Store API
	 * items_count) both come from WC_Cart::get_cart_contents_count(), which
	 * is a PACK count — a modest wholesale cart is already three digits,
	 * which means nothing to someone ordering by the display and doesn't
	 * fit the theme's fixed-size badge. For a wholesale customer, count
	 * displays instead: every wholesale line is a whole number of them, and
	 * it's the same figure the sticky tier bar shows. Display only — no
	 * totals, stock or validation logic reads this value.
	 *
	 * @param int|float $count
	 * @return int|float
	 */
	public function count_cart_in_displays( $count ) {
		if ( ! Roles::is_wholesale_customer() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $count;
		}

		/**
		 * Whether a wholesale customer's cart badge counts displays rather
		 * than packs.
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'protech_wholesale_cart_count_in_displays', true ) ) {
			return $count;
		}

		$displays = 0.0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );
			$quantity   = (int) $cart_item['quantity'];

			// A line not sold by the display (a sample pack) counts as its
			// plain quantity, the way any retail badge would.
			$displays += self::sold_by_the_display( $product_id )
				? self::cases_for_quantity( $product_id, $quantity )
				: $quantity;
		}

		return (int) ceil( $displays );
	}

	/**
	 * Adds this product's Display/Case sizing to the JSON WooCommerce
	 * already sends the browser for each variation (available_variations,
	 * read by its own core add-to-cart-variation.js) — so
	 * assets/js/unit-selector.js can keep the unit selector's pack math
	 * correct as the customer switches colors, without a separate AJAX
	 * round trip. Harmless for a retail shopper: the extra keys are
	 * simply unused by WooCommerce's own JS.
	 *
	 * @param array<string, mixed> $data
	 * @param \WC_Product          $product
	 * @param \WC_Product_Variation $variation
	 */
	public function add_unit_data_to_variation( array $data, $product, $variation ): array {
		if ( ! Roles::is_wholesale_customer() ) {
			return $data;
		}

		$data['protech_case_size']         = self::get_case_size( $variation->get_id() );
		$data['protech_displays_per_case'] = VolumePricing::get_displays_per_case( $variation->get_id() );

		return $data;
	}

	/**
	 * Cart/checkout pages show packs (so stock math is unaffected) with
	 * a "Displays: 3" annotation underneath, per R3.
	 *
	 * @param array $item_data
	 * @param array $cart_item
	 */
	public function add_case_count_to_item_data( array $item_data, array $cart_item ): array {
		$product_id = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );

		if ( ! self::sold_by_the_display( $product_id ) ) {
			return $item_data;
		}

		$cases = self::cases_for_quantity( $product_id, (int) $cart_item['quantity'] );

		if ( $cases > 0 ) {
			$item_data[] = array(
				'name'  => __( 'Displays', 'protech-wholesale' ),
				'value' => (string) $cases,
			);
		}

		return $item_data;
	}

	/**
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $values
	 * @param \WC_Order              $order
	 */
	public function persist_case_count_on_order_item( $item, string $cart_item_key, array $values, $order ): void {
		// The Store API (Blocks checkout) creates line items before it sets
		// the draft order's customer, so fall back to the logged-in user.
		$customer_id = (int) $order->get_customer_id() ?: get_current_user_id();
		$product_id  = (int) ( $values['variation_id'] ?: $values['product_id'] );

		if ( ! self::sold_by_the_display( $product_id, $customer_id ) ) {
			return;
		}

		$cases = self::cases_for_quantity( $product_id, (int) $values['quantity'] );

		if ( $cases > 0 ) {
			$item->add_meta_data( __( 'Displays', 'protech-wholesale' ), (string) $cases, true );
		}
	}

	/**
	 * Case-multiple violations only — the order minimum no longer blocks
	 * checkout (see DECISIONS.md, 2026-09-17). It's now purely the
	 * free-shipping threshold; WholesaleShippingMethod communicates "add
	 * $X more for free shipping" directly on the shipping rate itself
	 * instead of via a separate cart notice.
	 *
	 * @return string[] Human-readable validation errors for the current cart, empty if none.
	 */
	private function get_cart_errors(): array {
		if ( ! Roles::is_wholesale_customer() || ! WC()->cart ) {
			return array();
		}

		$errors = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );

			if ( ! self::sold_by_the_display( $product_id ) ) {
				continue;
			}

			if ( ! self::is_multiple_of_case( $product_id, (int) $cart_item['quantity'] ) ) {
				$product = wc_get_product( $product_id );

				$errors[] = sprintf(
					/* translators: 1: product name, 2: display size. */
					__( '%1$s must be ordered in multiples of %2$d packs (one display).', 'protech-wholesale' ),
					$product ? $product->get_name() : __( 'An item in your cart', 'protech-wholesale' ),
					self::get_case_size( $product_id )
				);
			}
		}

		return $errors;
	}

	public function validate_cart_and_notify(): void {
		foreach ( $this->get_cart_errors() as $error ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * @param \WP_Error $errors
	 * @param mixed     $cart
	 */
	public function validate_cart_for_store_api( $errors, $cart ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}

		foreach ( $this->get_cart_errors() as $error ) {
			$errors->add( 'protech_wholesale_cart_rule', wp_strip_all_tags( $error ) );
		}

		return $errors;
	}
}
