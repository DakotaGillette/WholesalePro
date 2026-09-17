<?php
/**
 * Admin-side product/variation fields: "Wholesale price" (R2) and
 * "Case size" (R3). Kept in one class because they render into the same
 * product data panels and share the same save routine.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductFields
 */
class ProductFields {

	public const META_WHOLESALE_PRICE   = '_protech_wholesale_price'; // Tier 1 / standard price, per pack.
	public const META_CASE_SIZE         = '_protech_case_size'; // Packs per DISPLAY — name kept for backwards compatibility.
	public const META_DISPLAYS_PER_CASE = '_protech_displays_per_case'; // Displays per (big) CASE.
	public const META_VOLUME_PRICE      = '_protech_volume_price'; // Per-pack override for the Tier 2 (Volume) price; blank = store default.
	public const META_BULK_PRICE        = '_protech_bulk_price'; // Per-pack override for the Tier 3 (Bulk) price; blank = store default.
	public const META_WHOLESALE_ONLY    = '_protech_wholesale_only'; // 'yes' | unset. Product-level only, not per-variation.
	public const META_HAS_WHOLESALE_PRICE = '_protech_has_wholesale_price'; // 'yes' | 'no', parent-level, kept in sync by sync_has_wholesale_price_flag().

	public const DEFAULT_CASE_SIZE         = 10;
	public const DEFAULT_DISPLAYS_PER_CASE = 8;

	public function register_hooks(): void {
		// A dedicated "Wholesale" product data tab. These used to hook
		// woocommerce_product_options_pricing, which WooCommerce renders
		// inside its `show_if_simple show_if_external` pricing group — so on
		// a variable product (the flagship sleeves) the product-level fields
		// ("Wholesale only", Displays per case) were never visible at all.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple_fields' ) );

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		add_action( 'woocommerce_variable_product_bulk_edit_actions', array( $this, 'render_bulk_edit_action' ) );
		add_action( 'woocommerce_bulk_edit_variations', array( $this, 'handle_bulk_edit' ), 10, 4 );
	}

	public static function is_wholesale_only( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, self::META_WHOLESALE_ONLY, true );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function add_product_data_tab( array $tabs ): array {
		$tabs['protech_wholesale'] = array(
			'label'    => __( 'Wholesale', 'protech-wholesale' ),
			'target'   => 'protech_wholesale_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 21, // Right after General (10) and Inventory (20).
		);

		return $tabs;
	}

	/**
	 * The "Wholesale" tab's panel. Two groups: the per-product price ladder
	 * (simple products only — a variable product prices each variation
	 * under the Variations tab), and the settings that apply to every
	 * product type (case composition, which variations inherit unless they
	 * set their own, and the wholesale-only flag).
	 */
	public function render_product_data_panel(): void {
		global $product_object;

		$meta = static function ( string $key ) use ( $product_object ): string {
			return $product_object instanceof \WC_Product ? (string) $product_object->get_meta( $key ) : '';
		};

		echo '<div id="protech_wholesale_product_data" class="panel woocommerce_options_panel hidden">';

		echo '<div class="options_group show_if_simple">';

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_WHOLESALE_PRICE,
				'label'       => sprintf( __( 'Wholesale price (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'description' => __( 'Per pack. This is the Tier 1 (Standard) price — see WooCommerce → Wholesale → Pricing for the Volume/Bulk tiers. Leave empty to keep this product unavailable at wholesale.', 'protech-wholesale' ),
				'desc_tip'    => true,
				'data_type'   => 'price',
				'value'       => $meta( self::META_WHOLESALE_PRICE ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_VOLUME_PRICE,
				'label'       => sprintf( __( 'Volume price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %d: displays, %s: price. Both are the store defaults set under WooCommerce -> Wholesale -> Pricing. */
				'description' => sprintf( __( 'Per pack, once the cart reaches %1$d combined displays. Leave empty to use the store default (%2$s).', 'protech-wholesale' ), Settings::get_volume_threshold_displays(), wp_strip_all_tags( wc_price( Settings::get_volume_price() ) ) ),
				'desc_tip'    => true,
				'data_type'   => 'price',
				'value'       => $meta( self::META_VOLUME_PRICE ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_BULK_PRICE,
				'label'       => sprintf( __( 'Bulk price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %d: cases, %s: price. Both are the store defaults set under WooCommerce -> Wholesale -> Pricing. */
				'description' => sprintf( __( 'Per pack, once the cart reaches %1$d combined cases. Leave empty to use the store default (%2$s).', 'protech-wholesale' ), Settings::get_bulk_threshold_cases(), wp_strip_all_tags( wc_price( Settings::get_bulk_price() ) ) ),
				'desc_tip'    => true,
				'data_type'   => 'price',
				'value'       => $meta( self::META_BULK_PRICE ),
			)
		);

		echo '</div>';

		echo '<div class="options_group show_if_variable"><p class="form-field">';
		esc_html_e( 'Wholesale, Volume, and Bulk prices for a variable product are set per variation on the Variations tab — expand a variation to edit one, or use the "Set wholesale prices" bulk action there to set every variation at once.', 'protech-wholesale' );
		echo '</p></div>';

		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_CASE_SIZE,
				'label'             => __( 'Packs per display', 'protech-wholesale' ),
				/* translators: %d: store's default, set under WooCommerce -> Wholesale -> Pricing. */
				'description'       => sprintf( __( 'Wholesale quantities must be a multiple of this. A variation inherits it unless it sets its own. Leave empty to use the store default (%d).', 'protech-wholesale' ), Settings::get_default_case_size() ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step'        => '1',
					'min'         => '1',
					'placeholder' => (string) Settings::get_default_case_size(),
				),
				// Empty (not the default) when unset, so saving without
				// touching this field doesn't bake today's default into
				// this product's own meta — see save_price_and_case().
				'value'             => $meta( self::META_CASE_SIZE ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_DISPLAYS_PER_CASE,
				'label'             => __( 'Displays per case', 'protech-wholesale' ),
				/* translators: %d: store's default, set under WooCommerce -> Wholesale -> Pricing. */
				'description'       => sprintf( __( 'How many displays make up one case for this product. A variation inherits it. Leave empty to use the store default (%d).', 'protech-wholesale' ), Settings::get_default_displays_per_case() ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step'        => '1',
					'min'         => '1',
					'placeholder' => (string) Settings::get_default_displays_per_case(),
				),
				'value'             => $meta( self::META_DISPLAYS_PER_CASE ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_WHOLESALE_ONLY,
				'label'       => __( 'Wholesale only', 'protech-wholesale' ),
				'description' => __( 'Hide this product from the retail shop, search, and its own product page (with an "apply for wholesale" message) for anyone who is not an approved wholesale customer. Leave unchecked to keep it available at retail too.', 'protech-wholesale' ),
				'desc_tip'    => true,
				'value'       => 'yes' === $meta( self::META_WHOLESALE_ONLY ) ? 'yes' : 'no',
			)
		);

		echo '</div>';

		echo '</div>';
	}

	public function save_simple_fields( int $post_id ): void {
		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		$this->save_price_and_case( $product, $_POST );

		$this->save_optional_price_field( $product, self::META_VOLUME_PRICE, $_POST[ self::META_VOLUME_PRICE ] ?? null );
		$this->save_optional_price_field( $product, self::META_BULK_PRICE, $_POST[ self::META_BULK_PRICE ] ?? null );
		$this->save_optional_int_field( $product, self::META_DISPLAYS_PER_CASE, $_POST[ self::META_DISPLAYS_PER_CASE ] ?? null );

		// Product-level only — never saved from save_variation_fields().
		$wholesale_only = ! empty( $_POST[ self::META_WHOLESALE_ONLY ] );
		$product->update_meta_data( self::META_WHOLESALE_ONLY, $wholesale_only ? 'yes' : 'no' );

		// A "wholesale only" product must keep WooCommerce's OWN native
		// catalog visibility at "visible" — that native setting (the
		// "Catalog visibility" dropdown further down this same panel) is
		// an unconditional, role-blind exclusion at the database query
		// level, applied before our own woocommerce_product_is_visible
		// filter (Pricing::filter_catalog_visibility()) ever runs. If an
		// admin sets it to "Hidden" — the intuitive-looking way to hide a
		// product from retail, without knowing this checkbox already
		// handles that per-role — the product disappears from the shop
		// for wholesale customers too, with no way for our filter to add
		// it back. Forcing this here means our checkbox is the only
		// visibility control that matters for a wholesale-only product.
		if ( $wholesale_only && 'visible' !== $product->get_catalog_visibility() ) {
			$product->set_catalog_visibility( 'visible' );
		}

		$product->save();

		self::sync_has_wholesale_price_flag( $post_id );
	}

	/**
	 * Keeps a parent-level "this product has SOME wholesale price" flag in
	 * sync on every save path (simple save, variation save, bulk edit).
	 * A variable product's wholesale prices live on its variations, so
	 * anything that needs to know whether a product is sellable at
	 * wholesale at all — the catalog-level hiding of products with no
	 * wholesale price — would otherwise need a per-product walk of every
	 * child; with this flag it can be a plain meta query instead.
	 */
	public static function sync_has_wholesale_price_flag( int $product_id ): void {
		$product = wc_get_product( $product_id );

		if ( $product instanceof \WC_Product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$has_price = false;

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				if ( is_numeric( get_post_meta( $child_id, self::META_WHOLESALE_PRICE, true ) ) ) {
					$has_price = true;
					break;
				}
			}
		} else {
			$has_price = is_numeric( get_post_meta( $product->get_id(), self::META_WHOLESALE_PRICE, true ) );
		}

		// Plain meta write on purpose: no second WC_Product::save() (and no
		// re-firing of the save hooks this is called from).
		update_post_meta( $product->get_id(), self::META_HAS_WHOLESALE_PRICE, $has_price ? 'yes' : 'no' );
	}

	/**
	 * Writes the flag onto every product once — run by Plugin::maybe_upgrade()
	 * when the flag is introduced, since CatalogQuery would otherwise hide
	 * every not-yet-resaved product from wholesale customers.
	 *
	 * @return int Number of products processed.
	 */
	public static function backfill_has_wholesale_price_flags(): int {
		$ids = CatalogQuery::without_filtering(
			static function (): array {
				return wc_get_products(
					array(
						'status' => 'any',
						'type'   => array( 'simple', 'variable' ),
						'limit'  => -1,
						'return' => 'ids',
					)
				);
			}
		);

		foreach ( $ids as $product_id ) {
			self::sync_has_wholesale_price_flag( (int) $product_id );
		}

		return count( $ids );
	}

	/**
	 * @param int        $loop
	 * @param array      $variation_data
	 * @param \WP_Post   $variation
	 */
	public function render_variation_fields( int $loop, array $variation_data, \WP_Post $variation ): void {
		$product = wc_get_product( $variation->ID );

		if ( ! $product ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_WHOLESALE_PRICE . "_{$loop}",
				'name'              => self::META_WHOLESALE_PRICE . "[{$loop}]",
				'label'             => sprintf( __( 'Wholesale price (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'data_type'         => 'price',
				'value'             => $product->get_meta( self::META_WHOLESALE_PRICE ),
				'wrapper_class'     => 'form-row form-row-first',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_CASE_SIZE . "_{$loop}",
				'name'              => self::META_CASE_SIZE . "[{$loop}]",
				'label'             => __( 'Packs per display', 'protech-wholesale' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step'        => '1',
					'min'         => '1',
					'placeholder' => (string) Settings::get_default_case_size(),
				),
				'value'             => $product->get_meta( self::META_CASE_SIZE ),
				'wrapper_class'     => 'form-row form-row-last',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_VOLUME_PRICE . "_{$loop}",
				'name'              => self::META_VOLUME_PRICE . "[{$loop}]",
				'label'             => sprintf( __( 'Volume price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'data_type'         => 'price',
				'value'             => $product->get_meta( self::META_VOLUME_PRICE ),
				'wrapper_class'     => 'form-row form-row-first',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_BULK_PRICE . "_{$loop}",
				'name'              => self::META_BULK_PRICE . "[{$loop}]",
				'label'             => sprintf( __( 'Bulk price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'data_type'         => 'price',
				'value'             => $product->get_meta( self::META_BULK_PRICE ),
				'wrapper_class'     => 'form-row form-row-last',
			)
		);
	}

	public function save_variation_fields( int $variation_id, int $loop ): void {
		$product = wc_get_product( $variation_id );

		if ( ! $product ) {
			return;
		}

		$price = $_POST[ self::META_WHOLESALE_PRICE ][ $loop ] ?? null;
		$case  = $_POST[ self::META_CASE_SIZE ][ $loop ] ?? null;

		$this->save_price_and_case( $product, array( self::META_WHOLESALE_PRICE => $price, self::META_CASE_SIZE => $case ) );

		$this->save_optional_price_field( $product, self::META_VOLUME_PRICE, $_POST[ self::META_VOLUME_PRICE ][ $loop ] ?? null );
		$this->save_optional_price_field( $product, self::META_BULK_PRICE, $_POST[ self::META_BULK_PRICE ][ $loop ] ?? null );

		$product->save();

		self::sync_has_wholesale_price_flag( $variation_id );
	}

	private function save_price_and_case( \WC_Product $product, array $source ): void {
		$raw_price = isset( $source[ self::META_WHOLESALE_PRICE ] ) ? sanitize_text_field( wp_unslash( (string) $source[ self::META_WHOLESALE_PRICE ] ) ) : '';

		if ( '' === trim( $raw_price ) ) {
			$product->delete_meta_data( self::META_WHOLESALE_PRICE );
		} elseif ( is_numeric( $raw_price ) ) {
			$product->update_meta_data( self::META_WHOLESALE_PRICE, wc_format_decimal( $raw_price ) );
		}
		// A non-empty, non-numeric value is ignored rather than saved —
		// leaves the existing price untouched instead of silently
		// coercing garbage input to 0.

		$case_size = isset( $source[ self::META_CASE_SIZE ] ) ? absint( $source[ self::META_CASE_SIZE ] ) : 0;

		if ( $case_size > 0 ) {
			$product->update_meta_data( self::META_CASE_SIZE, $case_size );
		} else {
			// Leave unset rather than baking in today's default — this way
			// a later change to the global default (Settings ->
			// get_default_case_size()) still applies to this product.
			$product->delete_meta_data( self::META_CASE_SIZE );
		}
	}

	/**
	 * Shared by the volume/bulk price override fields: empty clears the
	 * override (falls back to the store default), a non-numeric value is
	 * ignored rather than saved as garbage.
	 *
	 * @param mixed $raw
	 */
	private function save_optional_price_field( \WC_Product $product, string $meta_key, $raw ): void {
		$value = null === $raw ? '' : sanitize_text_field( wp_unslash( (string) $raw ) );

		if ( '' === trim( $value ) ) {
			$product->delete_meta_data( $meta_key );
		} elseif ( is_numeric( $value ) ) {
			$product->update_meta_data( $meta_key, wc_format_decimal( $value ) );
		}
	}

	/**
	 * @param mixed $raw
	 */
	private function save_optional_int_field( \WC_Product $product, string $meta_key, $raw ): void {
		$value = null === $raw ? 0 : absint( $raw );

		if ( $value > 0 ) {
			$product->update_meta_data( $meta_key, $value );
		} else {
			$product->delete_meta_data( $meta_key );
		}
	}

	/**
	 * "Set all variations to $___" bulk helper in the Variations panel.
	 */
	/**
	 * Bulk action => variation meta key it sets. admin.js prompts for the
	 * value through WooCommerce's `<action>_ajax_data` event contract.
	 *
	 * @return array<string, string>
	 */
	private static function bulk_actions(): array {
		return array(
			'protech_set_wholesale_price' => self::META_WHOLESALE_PRICE,
			'protech_set_volume_price'    => self::META_VOLUME_PRICE,
			'protech_set_bulk_price'      => self::META_BULK_PRICE,
		);
	}

	public function render_bulk_edit_action(): void {
		echo '<optgroup label="' . esc_attr__( 'Wholesale', 'protech-wholesale' ) . '">';
		echo '<option value="protech_set_wholesale_price">' . esc_html__( 'Set wholesale prices', 'protech-wholesale' ) . '</option>';
		echo '<option value="protech_set_volume_price">' . esc_html__( 'Set Volume price overrides', 'protech-wholesale' ) . '</option>';
		echo '<option value="protech_set_bulk_price">' . esc_html__( 'Set Bulk price overrides', 'protech-wholesale' ) . '</option>';
		echo '</optgroup>';
	}

	/**
	 * @param string      $bulk_action
	 * @param array       $data
	 * @param int         $variable_product_id
	 * @param array       $variation_ids
	 */
	public function handle_bulk_edit( string $bulk_action, array $data, int $variable_product_id, array $variation_ids ): void {
		$meta_key = self::bulk_actions()[ $bulk_action ] ?? null;

		if ( null === $meta_key ) {
			return;
		}

		// admin.js supplies the price as `value` via WooCommerce's own
		// bulk-edit AJAX call. If it's missing (the prompt was cancelled, or
		// a future WooCommerce version changes how it forwards data for a
		// non-core bulk action), do nothing rather than guessing — an empty
		// value here must never be read as "clear every variation's price".
		if ( ! isset( $data['value'] ) || '' === trim( (string) $data['value'] ) ) {
			Logger::warning( "Bulk '{$bulk_action}' selected on product #{$variable_product_id} with no price value; no changes made." );
			return;
		}

		$price = wc_format_decimal( sanitize_text_field( wp_unslash( (string) $data['value'] ) ) );

		if ( ! is_numeric( $price ) ) {
			Logger::warning( "Bulk '{$bulk_action}' on product #{$variable_product_id} ignored non-numeric value '{$price}'." );
			return;
		}

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$variation->update_meta_data( $meta_key, $price );
			$variation->save();
		}

		if ( self::META_WHOLESALE_PRICE === $meta_key ) {
			self::sync_has_wholesale_price_flag( $variable_product_id );
		}

		Logger::info( "Bulk-set {$meta_key} to '{$price}' on " . count( $variation_ids ) . " variations of product #{$variable_product_id}" );
	}

	/**
	 * The "Products" tab of the WooCommerce → Wholesale admin screen — a
	 * read-only, at-a-glance list of every product configured for
	 * wholesale in some way. Editing still happens on each product's own
	 * edit screen (the wholesale price/case size/wholesale-only fields
	 * live there, next to the rest of that product's settings, rather
	 * than being duplicated into a second editable UI here).
	 */
	public function render_products_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$rows = $this->get_wholesale_relevant_products();

		echo '<p>' . esc_html__( 'Every product with a wholesale price set, or flagged "Wholesale only" — for reference. Click a product to edit its wholesale price, case size, or wholesale-only setting on its own edit screen.', 'protech-wholesale' ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No products have wholesale pricing configured yet. Open a product and look for the "Wholesale price" field in the General pricing section.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach (
			array(
				__( 'Product', 'protech-wholesale' ),
				__( 'Type', 'protech-wholesale' ),
				__( 'Wholesale price', 'protech-wholesale' ),
				__( 'Packs/display', 'protech-wholesale' ),
				__( 'Wholesale only', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><a href="' . esc_url( $row['edit_url'] ) . '"><strong>' . esc_html( $row['name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( $row['type'] ) . '</td>';
			echo '<td>' . wp_kses_post( $row['price_display'] ) . '</td>';
			echo '<td>' . esc_html( $row['case_size_display'] ) . '</td>';
			echo '<td>' . ( $row['wholesale_only'] ? esc_html__( 'Yes', 'protech-wholesale' ) : '&#8212;' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @return array<int, array{name: string, type: string, price_display: string, case_size_display: string, wholesale_only: bool, edit_url: string}>
	 */
	private function get_wholesale_relevant_products(): array {
		$rows = array();

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'type'   => array( 'simple', 'variable' ),
			)
		);

		foreach ( $products as $product ) {
			$is_wholesale_only = self::is_wholesale_only( $product->get_id() );

			if ( $product->is_type( 'variable' ) ) {
				$prices     = array();
				$case_sizes = array();

				foreach ( $product->get_children() as $variation_id ) {
					$price = get_post_meta( $variation_id, self::META_WHOLESALE_PRICE, true );

					if ( is_numeric( $price ) ) {
						$prices[] = (float) $price;
					}

					$case_sizes[] = CaseRules::get_case_size( $variation_id );
				}

				if ( empty( $prices ) && ! $is_wholesale_only ) {
					continue;
				}

				if ( empty( $prices ) ) {
					$price_display = '&#8212;';
				} elseif ( 1 === count( array_unique( $prices ) ) ) {
					$price_display = wc_price( $prices[0] );
				} else {
					$price_display = wc_price( min( $prices ) ) . '&#8211;' . wc_price( max( $prices ) );
				}

				$case_size_display = empty( $case_sizes )
					? '&#8212;'
					: ( 1 === count( array_unique( $case_sizes ) ) ? (string) $case_sizes[0] : __( 'Varies', 'protech-wholesale' ) );
			} else {
				$price = get_post_meta( $product->get_id(), self::META_WHOLESALE_PRICE, true );

				if ( ! is_numeric( $price ) && ! $is_wholesale_only ) {
					continue;
				}

				$price_display      = is_numeric( $price ) ? wc_price( (float) $price ) : '&#8212;';
				$case_size_display  = (string) CaseRules::get_case_size( $product->get_id() );
			}

			$rows[] = array(
				'name'              => $product->get_name(),
				'type'              => $product->is_type( 'variable' ) ? __( 'Variable', 'protech-wholesale' ) : __( 'Simple', 'protech-wholesale' ),
				'price_display'     => $price_display,
				'case_size_display' => $case_size_display,
				'wholesale_only'    => $is_wholesale_only,
				'edit_url'          => (string) get_edit_post_link( $product->get_id() ),
			);
		}

		return $rows;
	}
}
