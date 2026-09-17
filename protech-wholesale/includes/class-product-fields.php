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

	public const DEFAULT_CASE_SIZE         = 10;
	public const DEFAULT_DISPLAYS_PER_CASE = 8;

	public function register_hooks(): void {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple_fields' ) );

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		add_action( 'woocommerce_variable_product_bulk_edit_actions', array( $this, 'render_bulk_edit_action' ) );
		add_action( 'woocommerce_bulk_edit_variations', array( $this, 'handle_bulk_edit' ), 10, 4 );
	}

	public static function is_wholesale_only( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, self::META_WHOLESALE_ONLY, true );
	}

	public function render_simple_fields(): void {
		global $product_object;

		echo '<div class="options_group protech-wholesale-fields">';

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_WHOLESALE_PRICE,
				'label'             => sprintf( __( 'Wholesale price (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'description'       => __( 'Per pack. This is the Tier 1 (Standard) price — see WooCommerce → Wholesale → Pricing for the Volume/Bulk tiers. Leave empty to keep this product unavailable at wholesale.', 'protech-wholesale' ),
				'desc_tip'          => true,
				'data_type'         => 'price',
				'value'             => $product_object ? $product_object->get_meta( self::META_WHOLESALE_PRICE ) : '',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_CASE_SIZE,
				'label'       => __( 'Packs per display', 'protech-wholesale' ),
				/* translators: %d: store's default, set under WooCommerce -> Wholesale -> Tiers. */
				'description' => sprintf( __( 'Wholesale quantities must be a multiple of this. Leave empty to use the store default (%d).', 'protech-wholesale' ), Settings::get_default_case_size() ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array(
					'step'        => '1',
					'min'         => '1',
					'placeholder' => (string) Settings::get_default_case_size(),
				),
				// Empty (not the default) when unset, so saving without
				// touching this field doesn't bake today's default into
				// this product's own meta — see save_price_and_case().
				'value'       => $product_object ? $product_object->get_meta( self::META_CASE_SIZE ) : '',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_DISPLAYS_PER_CASE,
				'label'       => __( 'Displays per case', 'protech-wholesale' ),
				/* translators: %d: store's default, set under WooCommerce -> Wholesale -> Pricing. */
				'description' => sprintf( __( 'How many displays make up one case for this product. Leave empty to use the store default (%d).', 'protech-wholesale' ), Settings::get_default_displays_per_case() ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array(
					'step'        => '1',
					'min'         => '1',
					'placeholder' => (string) Settings::get_default_displays_per_case(),
				),
				'value'       => $product_object ? $product_object->get_meta( self::META_DISPLAYS_PER_CASE ) : '',
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
				'value'       => $product_object ? $product_object->get_meta( self::META_VOLUME_PRICE ) : '',
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
				'value'       => $product_object ? $product_object->get_meta( self::META_BULK_PRICE ) : '',
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_WHOLESALE_ONLY,
				'label'       => __( 'Wholesale only', 'protech-wholesale' ),
				'description' => __( 'Hide this product from the retail shop, search, and its own product page (with an "apply for wholesale" message) for anyone who is not an approved wholesale customer. Leave unchecked to keep it available at retail too.', 'protech-wholesale' ),
				'desc_tip'    => true,
				'value'       => $product_object && 'yes' === $product_object->get_meta( self::META_WHOLESALE_ONLY ) ? 'yes' : 'no',
			)
		);

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
	public function render_bulk_edit_action(): void {
		echo '<option value="protech_set_wholesale_price">' . esc_html__( 'Set wholesale prices', 'protech-wholesale' ) . '</option>';
	}

	/**
	 * @param string      $bulk_action
	 * @param array       $data
	 * @param int         $variable_product_id
	 * @param array       $variation_ids
	 */
	public function handle_bulk_edit( string $bulk_action, array $data, int $variable_product_id, array $variation_ids ): void {
		if ( 'protech_set_wholesale_price' !== $bulk_action ) {
			return;
		}

		// admin.js prompts for the price and relies on WooCommerce's own
		// bulk-edit AJAX call to carry it as `value`. If that value is
		// missing (e.g. the prompt was cancelled, or WooCommerce's JS on
		// this version doesn't forward it for a non-core bulk action),
		// do nothing rather than guessing — an empty value here must
		// never be read as "clear every variation's wholesale price".
		if ( ! isset( $data['value'] ) || '' === trim( (string) $data['value'] ) ) {
			Logger::warning( "Bulk 'set wholesale prices' selected on product #{$variable_product_id} with no price value; no changes made." );
			return;
		}

		$price = wc_format_decimal( sanitize_text_field( wp_unslash( (string) $data['value'] ) ) );

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$variation->update_meta_data( self::META_WHOLESALE_PRICE, $price );
			$variation->save();
		}

		Logger::info( "Bulk-set wholesale price to '{$price}' on " . count( $variation_ids ) . " variations of product #{$variable_product_id}" );
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
