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

	public const META_WHOLESALE_PRICE = '_protech_wholesale_price';
	public const META_CASE_SIZE       = '_protech_case_size';

	public const DEFAULT_CASE_SIZE = 10;

	public function register_hooks(): void {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple_fields' ) );

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		add_action( 'woocommerce_variable_product_bulk_edit_actions', array( $this, 'render_bulk_edit_action' ) );
		add_action( 'woocommerce_bulk_edit_variations', array( $this, 'handle_bulk_edit' ), 10, 4 );
	}

	public function render_simple_fields(): void {
		global $product_object;

		echo '<div class="options_group protech-wholesale-fields">';

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_WHOLESALE_PRICE,
				'label'             => sprintf( __( 'Wholesale price (%s)', 'protech-wholesale' ), get_woocommerce_currency_symbol() ),
				'description'       => __( 'Per pack. Leave empty to keep this product unavailable at wholesale.', 'protech-wholesale' ),
				'desc_tip'          => true,
				'data_type'         => 'price',
				'value'             => $product_object ? $product_object->get_meta( self::META_WHOLESALE_PRICE ) : '',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_CASE_SIZE,
				'label'       => __( 'Case size (packs)', 'protech-wholesale' ),
				'description' => __( 'Wholesale quantities must be a multiple of this. Default 10.', 'protech-wholesale' ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array( 'step' => '1', 'min' => '1' ),
				'value'       => $product_object ? ( $product_object->get_meta( self::META_CASE_SIZE ) ?: self::DEFAULT_CASE_SIZE ) : self::DEFAULT_CASE_SIZE,
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
				'label'             => __( 'Case size (packs)', 'protech-wholesale' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1', 'min' => '1' ),
				'value'             => $product->get_meta( self::META_CASE_SIZE ) ?: self::DEFAULT_CASE_SIZE,
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
		$product->save();
	}

	private function save_price_and_case( \WC_Product $product, array $source ): void {
		$price = isset( $source[ self::META_WHOLESALE_PRICE ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( (string) $source[ self::META_WHOLESALE_PRICE ] ) ) ) : '';

		if ( '' === $price ) {
			$product->delete_meta_data( self::META_WHOLESALE_PRICE );
		} else {
			$product->update_meta_data( self::META_WHOLESALE_PRICE, $price );
		}

		$case_size = isset( $source[ self::META_CASE_SIZE ] ) ? absint( $source[ self::META_CASE_SIZE ] ) : 0;
		$product->update_meta_data( self::META_CASE_SIZE, $case_size > 0 ? $case_size : self::DEFAULT_CASE_SIZE );
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

		$price = isset( $data['value'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( (string) $data['value'] ) ) ) : '';

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			if ( '' === $price ) {
				$variation->delete_meta_data( self::META_WHOLESALE_PRICE );
			} else {
				$variation->update_meta_data( self::META_WHOLESALE_PRICE, $price );
			}

			$variation->save();
		}

		Logger::info( "Bulk-set wholesale price to '{$price}' on " . count( $variation_ids ) . " variations of product #{$variable_product_id}" );
	}
}
