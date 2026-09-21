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

		// Products → All Products: what each product's wholesale setup is,
		// without opening it.
		add_filter( 'manage_product_posts_columns', array( $this, 'add_products_list_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_products_list_column' ), 10, 2 );
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
				'description' => __( 'Per pack. This is the base price, below the Standard threshold — see WooCommerce → Wholesale → Pricing for the Standard/Volume tiers. Leave empty to keep this product unavailable at wholesale.', 'protech-wholesale' ),
				'desc_tip'    => true,
				'data_type'   => 'price',
				'value'       => $meta( self::META_WHOLESALE_PRICE ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_VOLUME_PRICE,
				'label'       => sprintf( __( 'Standard price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
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
				'label'       => sprintf( __( 'Volume price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				/* translators: %d: cases, %s: price. Both are the store defaults set under WooCommerce -> Wholesale -> Pricing. */
				'description' => sprintf( __( 'Per pack, once the cart reaches %1$d combined cases. Leave empty to use the store default (%2$s).', 'protech-wholesale' ), Settings::get_bulk_threshold_cases(), wp_strip_all_tags( wc_price( Settings::get_bulk_price() ) ) ),
				'desc_tip'    => true,
				'data_type'   => 'price',
				'value'       => $meta( self::META_BULK_PRICE ),
			)
		);

		echo '</div>';

		// Variable products are priced per variation (the store's flagship
		// sleeves have 14 colors, all at one price). These three fields
		// write the same value to every variation on Update and then come
		// back empty; the current state is summarised underneath. Pricing
		// one color differently is still done on the Variations tab.
		echo '<div class="options_group show_if_variable">';

		$variation_ids = $product_object instanceof \WC_Product && $product_object->is_type( 'variable' )
			? array_map( 'intval', $product_object->get_children() )
			: array();

		foreach ( self::apply_all_fields() as $field_name => $field ) {
			woocommerce_wp_text_input(
				array(
					'id'          => $field_name,
					'label'       => sprintf( $field['label'], get_woocommerce_currency_symbol() ),
					'description' => $field['description'] . ' ' . self::describe_variation_values( $variation_ids, $field['meta_key'] ),
					'desc_tip'    => false,
					'data_type'   => 'price',
					'value'       => '',
					'placeholder' => __( 'Apply to all variations', 'protech-wholesale' ),
				)
			);
		}

		echo '<p class="form-field"><span class="description">' . esc_html__( 'To price one color differently, expand it on the Variations tab.', 'protech-wholesale' ) . '</span></p>';
		echo '</div>';

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

		/**
		 * Further option groups inside the Wholesale product data panel
		 * (StarterKit adds its fields here).
		 *
		 * @param \WC_Product|null $product_object
		 */
		do_action( 'protech_wholesale_product_data_panel', $product_object instanceof \WC_Product ? $product_object : null );

		echo '</div>';
	}

	/**
	 * The parent-level "apply to all variations" inputs: POST field name
	 * => label (with a %s for the currency symbol), description, and the
	 * variation meta key it writes.
	 *
	 * @return array<string, array{label: string, description: string, meta_key: string}>
	 */
	private static function apply_all_fields(): array {
		return array(
			'_protech_apply_all_wholesale_price' => array(
				'label'       => __( 'Wholesale price (%s)', 'protech-wholesale' ),
				'description' => __( 'Per pack, written to every variation when you Update.', 'protech-wholesale' ),
				'meta_key'    => self::META_WHOLESALE_PRICE,
			),
			'_protech_apply_all_volume_price'    => array(
				'label'       => __( 'Standard price override (%s)', 'protech-wholesale' ),
				'description' => __( 'Per pack at the Standard level; empty variations use the store default.', 'protech-wholesale' ),
				'meta_key'    => self::META_VOLUME_PRICE,
			),
			'_protech_apply_all_bulk_price'      => array(
				'label'       => __( 'Volume price override (%s)', 'protech-wholesale' ),
				'description' => __( 'Per pack at the Volume level; empty variations use the store default.', 'protech-wholesale' ),
				'meta_key'    => self::META_BULK_PRICE,
			),
		);
	}

	/** "Currently $5.50 on 14 of 14 variations." / "Currently varies (13 of 14 set)." */
	private static function describe_variation_values( array $variation_ids, string $meta_key ): string {
		if ( empty( $variation_ids ) ) {
			return '';
		}

		$values = array();

		foreach ( $variation_ids as $variation_id ) {
			$value = get_post_meta( $variation_id, $meta_key, true );

			if ( is_numeric( $value ) ) {
				$values[] = (float) $value;
			}
		}

		$total = count( $variation_ids );
		$set   = count( $values );

		if ( 0 === $set ) {
			return __( 'Currently not set on any variation.', 'protech-wholesale' );
		}

		if ( 1 === count( array_unique( $values ) ) ) {
			return sprintf(
				/* translators: 1: price, 2: number of variations with it, 3: total number of variations. */
				__( 'Currently %1$s on %2$d of %3$d variations.', 'protech-wholesale' ),
				wp_strip_all_tags( wc_price( $values[0] ) ),
				$set,
				$total
			);
		}

		return sprintf(
			/* translators: 1: lowest price, 2: highest price, 3: number of variations set, 4: total number of variations. */
			__( 'Currently varies from %1$s to %2$s (%3$d of %4$d set).', 'protech-wholesale' ),
			wp_strip_all_tags( wc_price( min( $values ) ) ),
			wp_strip_all_tags( wc_price( max( $values ) ) ),
			$set,
			$total
		);
	}

	/**
	 * Writes one price meta value to every variation of a variable
	 * product. Shared by the parent-level "apply to all" fields and the
	 * Variations-tab bulk actions.
	 *
	 * @return int Variations written.
	 */
	public static function set_on_all_variations( int $parent_id, string $meta_key, string $price ): int {
		$parent = wc_get_product( $parent_id );

		if ( ! $parent instanceof \WC_Product || ! $parent->is_type( 'variable' ) ) {
			return 0;
		}

		$written = 0;

		foreach ( $parent->get_children() as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );

			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}

			$variation->update_meta_data( $meta_key, $price );
			$variation->save();
			++$written;
		}

		if ( self::META_WHOLESALE_PRICE === $meta_key ) {
			self::sync_has_wholesale_price_flag( $parent_id );
		}

		return $written;
	}

	public function save_simple_fields( int $post_id ): void {
		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		// "Apply to all variations": only a non-empty, numeric value does
		// anything; the fields never store on the parent.
		if ( $product->is_type( 'variable' ) ) {
			foreach ( self::apply_all_fields() as $field_name => $field ) {
				$raw = isset( $_POST[ $field_name ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $field_name ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce.

				if ( '' === $raw ) {
					continue;
				}

				$price = wc_format_decimal( $raw );

				if ( is_numeric( $price ) ) {
					$written = self::set_on_all_variations( $post_id, $field['meta_key'], (string) $price );
					Logger::info( "Applied {$field['meta_key']} = '{$price}' to {$written} variations of product #{$post_id} from the Wholesale tab" );
				}
			}
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
			// A starter kit sells other products' variations and needs no
			// wholesale price of its own to belong in the wholesale catalog.
			$has_price = is_numeric( get_post_meta( $product->get_id(), self::META_WHOLESALE_PRICE, true ) )
				|| StarterKit::is_kit( $product->get_id() );
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
				'label'             => sprintf( __( 'Standard price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'data_type'         => 'price',
				'value'             => $product->get_meta( self::META_VOLUME_PRICE ),
				'wrapper_class'     => 'form-row form-row-first',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_BULK_PRICE . "_{$loop}",
				'name'              => self::META_BULK_PRICE . "[{$loop}]",
				'label'             => sprintf( __( 'Volume price override (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
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
		echo '<option value="protech_set_volume_price">' . esc_html__( 'Set Standard price overrides', 'protech-wholesale' ) . '</option>';
		echo '<option value="protech_set_bulk_price">' . esc_html__( 'Set Volume price overrides', 'protech-wholesale' ) . '</option>';
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

		// WooCommerce hands over the selected variation IDs; the bulk action
		// applies to exactly those, not necessarily every child.
		$written = 0;

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$variation->update_meta_data( $meta_key, $price );
			$variation->save();
			++$written;
		}

		if ( self::META_WHOLESALE_PRICE === $meta_key ) {
			self::sync_has_wholesale_price_flag( $variable_product_id );
		}

		Logger::info( "Bulk-set {$meta_key} to '{$price}' on {$written} variations of product #{$variable_product_id}" );
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

		echo '<p>' . esc_html__( 'Every product priced or flagged for wholesale, for reference. Click a product to change any of this on its Wholesale tab. The same summary appears as a "Wholesale" column on Products → All Products.', 'protech-wholesale' ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No product is priced for wholesale yet. Open a product, choose its Wholesale tab, and enter a wholesale price (for a variable product, use "Apply to all variations").', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach (
			array(
				__( 'Product', 'protech-wholesale' ),
				__( 'Type', 'protech-wholesale' ),
				__( 'Wholesale price', 'protech-wholesale' ),
				__( 'Standard override', 'protech-wholesale' ),
				__( 'Volume override', 'protech-wholesale' ),
				__( 'Packs/display', 'protech-wholesale' ),
				__( 'Flags', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$flags = array();

			if ( $row['wholesale_only'] ) {
				$flags[] = __( 'Wholesale only', 'protech-wholesale' );
			}

			if ( $row['is_kit'] ) {
				$flags[] = __( 'Starter kit', 'protech-wholesale' );
			}

			echo '<tr>';
			echo '<td><a href="' . esc_url( $row['edit_url'] ) . '"><strong>' . esc_html( $row['name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( $row['type'] ) . '</td>';
			echo '<td>' . wp_kses_post( $row['price_display'] ) . '</td>';
			echo '<td>' . wp_kses_post( $row['volume_display'] ) . '</td>';
			echo '<td>' . wp_kses_post( $row['bulk_display'] ) . '</td>';
			echo '<td>' . esc_html( $row['case_size_display'] ) . '</td>';
			echo '<td>' . ( empty( $flags ) ? '&#8212;' : esc_html( implode( ', ', $flags ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @return array<int, array{name: string, type: string, price_display: string, case_size_display: string, wholesale_only: bool, edit_url: string}>
	 */
	private function get_wholesale_relevant_products(): array {
		$rows = array();

		$products = CatalogQuery::without_filtering(
			static function (): array {
				return wc_get_products(
					array(
						'status' => 'publish',
						'limit'  => -1,
						'type'   => array( 'simple', 'variable' ),
					)
				);
			}
		);

		foreach ( $products as $product ) {
			$summary = self::summarize( $product );

			if ( ! $summary['relevant'] ) {
				continue;
			}

			$rows[] = array(
				'name'              => $product->get_name(),
				'type'              => $product->is_type( 'variable' ) ? __( 'Variable', 'protech-wholesale' ) : __( 'Simple', 'protech-wholesale' ),
				'price_display'     => $summary['price_html'],
				'volume_display'    => $summary['volume_html'],
				'bulk_display'      => $summary['bulk_html'],
				'case_size_display' => $summary['case_size'],
				'wholesale_only'    => $summary['wholesale_only'],
				'is_kit'            => $summary['is_kit'],
				'edit_url'          => (string) get_edit_post_link( $product->get_id() ),
			);
		}

		return $rows;
	}

	/**
	 * One product's wholesale setup, summarised for a table cell: the
	 * price (a range across variations, when they differ), the Volume and
	 * Bulk overrides likewise, packs per display, and the flags. Shared by
	 * the Wholesale → Products tab and the column on Products → All
	 * Products.
	 *
	 * @return array{relevant: bool, has_price: bool, price_html: string, volume_html: string, bulk_html: string, case_size: string, wholesale_only: bool, is_kit: bool}
	 */
	public static function summarize( \WC_Product $product ): array {
		$ids = $product->is_type( 'variable' ) ? array_map( 'intval', $product->get_children() ) : array( $product->get_id() );

		$range = static function ( string $meta_key ) use ( $ids ): string {
			$values = array();

			foreach ( $ids as $id ) {
				$value = get_post_meta( $id, $meta_key, true );

				if ( is_numeric( $value ) ) {
					$values[] = (float) $value;
				}
			}

			if ( empty( $values ) ) {
				return '';
			}

			return 1 === count( array_unique( $values ) )
				? wc_price( $values[0] )
				: wc_price( min( $values ) ) . '&#8211;' . wc_price( max( $values ) );
		};

		$case_sizes = array_unique( array_map( array( CaseRules::class, 'get_case_size' ), $ids ) );
		$price_html = $range( self::META_WHOLESALE_PRICE );
		$is_kit     = StarterKit::is_kit( $product->get_id() );
		$only       = self::is_wholesale_only( $product->get_id() );

		return array(
			'relevant'       => '' !== $price_html || $only || $is_kit,
			'has_price'      => '' !== $price_html,
			'price_html'     => '' !== $price_html ? $price_html : '&#8212;',
			'volume_html'    => $range( self::META_VOLUME_PRICE ) ?: '&#8212;',
			'bulk_html'      => $range( self::META_BULK_PRICE ) ?: '&#8212;',
			'case_size'      => empty( $case_sizes ) ? '&#8212;' : ( 1 === count( $case_sizes ) ? (string) reset( $case_sizes ) : __( 'Varies', 'protech-wholesale' ) ),
			'wholesale_only' => $only,
			'is_kit'         => $is_kit,
		);
	}

	// -----------------------------------------------------------------
	// Products → All Products: a "Wholesale" column.
	// -----------------------------------------------------------------

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_products_list_column( array $columns ): array {
		$result = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			// Right after WooCommerce's own Price column.
			if ( 'price' === $key ) {
				$result['protech_wholesale'] = __( 'Wholesale', 'protech-wholesale' );
			}
		}

		if ( ! isset( $result['protech_wholesale'] ) ) {
			$result['protech_wholesale'] = __( 'Wholesale', 'protech-wholesale' );
		}

		return $result;
	}

	public function render_products_list_column( string $column, int $post_id ): void {
		if ( 'protech_wholesale' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$summary = self::summarize( $product );
		$badges  = array();

		if ( $summary['wholesale_only'] ) {
			$badges[] = __( 'Wholesale only', 'protech-wholesale' );
		}

		if ( $summary['is_kit'] ) {
			$badges[] = __( 'Starter kit', 'protech-wholesale' );
		}

		if ( $summary['is_kit'] ) {
			echo '<span class="description">' . esc_html__( 'Kit', 'protech-wholesale' ) . '</span>';
		} elseif ( $summary['has_price'] ) {
			echo wp_kses_post( $summary['price_html'] );
		} else {
			echo '<span class="description">&#8212;</span>';
		}

		foreach ( $badges as $badge ) {
			echo '<br /><span class="protech-admin-badge" style="display:inline-block;margin-top:2px;padding:1px 6px;border-radius:3px;background:#dde5f2;color:#2a4166;font-size:11px;">' . esc_html( $badge ) . '</span>';
		}
	}
}
