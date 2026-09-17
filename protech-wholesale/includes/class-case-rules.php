<?php
/**
 * R3: case size (packs per case) and order minimum enforcement.
 *
 * Wholesale sells by the display case (10 packs of one color by
 * default); inventory itself always stays in packs. This class only
 * ever validates and annotates — it never changes what's tracked in
 * stock.
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

		// Classic cart & checkout.
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_and_notify' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_cart_and_notify' ) );

		// WooCommerce Blocks (Store API) cart & checkout. See PLAN.md /
		// DECISIONS.md — confirm this hook still matches the Blocks
		// version installed once the site's cart/checkout type is known.
		add_filter( 'woocommerce_store_api_cart_errors', array( $this, 'validate_cart_for_store_api' ), 10, 2 );
	}

	public static function get_case_size( int $product_id ): int {
		$size = (int) get_post_meta( $product_id, ProductFields::META_CASE_SIZE, true );

		return $size > 0 ? $size : Settings::get_default_case_size();
	}

	/**
	 * Precedence: per-customer override > their tier's minimum (Bronze has
	 * none, and always falls through) > the global default.
	 */
	public static function get_minimum_order( int $user_id ): float {
		if ( $user_id ) {
			$override = get_user_meta( $user_id, Approval::META_MIN_ORDER, true );

			if ( '' !== $override && null !== $override ) {
				return (float) $override;
			}

			$tier_min = Tiers::get_tier_min_order( Tiers::get_user_tier( $user_id ) );

			if ( null !== $tier_min ) {
				return $tier_min;
			}
		}

		return Settings::get_min_order();
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
		if ( ! Roles::is_wholesale_customer() ) {
			return $passed;
		}

		$item_id = $variation_id ?: $product_id;

		if ( self::is_multiple_of_case( $item_id, (int) $quantity ) ) {
			return $passed;
		}

		$case_size = self::get_case_size( $item_id );

		wc_add_notice(
			sprintf(
				/* translators: %d: case size in packs. */
				__( 'Wholesale orders are placed in full cases of %1$d packs. Please enter a quantity that is a multiple of %1$d.', 'protech-wholesale' ),
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
		if ( ! Roles::is_wholesale_customer() ) {
			return $args;
		}

		$case_size = self::get_case_size( $product->get_id() );

		$args['input_step'] = $case_size;
		$args['min_value']  = max( $case_size, (float) ( $args['min_value'] ?? 0 ) );

		if ( empty( $args['input_value'] ) || (int) $args['input_value'] < $case_size ) {
			$args['input_value'] = $case_size;
		}

		return $args;
	}

	/**
	 * Cart/checkout pages show packs (so stock math is unaffected) with
	 * a "(3 cases)" annotation underneath, per R3.
	 *
	 * @param array $item_data
	 * @param array $cart_item
	 */
	public function add_case_count_to_item_data( array $item_data, array $cart_item ): array {
		if ( ! Roles::is_wholesale_customer() ) {
			return $item_data;
		}

		$product_id = $cart_item['variation_id'] ?: $cart_item['product_id'];
		$cases      = self::cases_for_quantity( $product_id, (int) $cart_item['quantity'] );

		if ( $cases > 0 ) {
			$item_data[] = array(
				'name'  => __( 'Cases', 'protech-wholesale' ),
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
		if ( ! Roles::is_wholesale_customer( $order->get_customer_id() ) ) {
			return;
		}

		$product_id = $values['variation_id'] ?: $values['product_id'];
		$cases      = self::cases_for_quantity( $product_id, (int) $values['quantity'] );

		if ( $cases > 0 ) {
			$item->add_meta_data( __( 'Cases', 'protech-wholesale' ), (string) $cases, true );
		}
	}

	/**
	 * @return string[] Human-readable validation errors for the current cart, empty if none.
	 */
	private function get_cart_errors(): array {
		if ( ! Roles::is_wholesale_customer() || ! WC()->cart ) {
			return array();
		}

		$errors  = array();
		$user_id = get_current_user_id();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = $cart_item['variation_id'] ?: $cart_item['product_id'];

			if ( ! self::is_multiple_of_case( $product_id, (int) $cart_item['quantity'] ) ) {
				$product = wc_get_product( $product_id );

				$errors[] = sprintf(
					/* translators: 1: product name, 2: case size. */
					__( '%1$s must be ordered in multiples of %2$d packs (one case).', 'protech-wholesale' ),
					$product ? $product->get_name() : __( 'An item in your cart', 'protech-wholesale' ),
					self::get_case_size( $product_id )
				);
			}
		}

		$minimum = self::get_minimum_order( $user_id );
		$subtotal = (float) WC()->cart->get_subtotal();

		if ( $minimum > 0 && $subtotal < $minimum ) {
			$errors[] = sprintf(
				/* translators: 1: amount remaining, 2: minimum order subtotal. */
				__( 'Add %1$s more to reach your %2$s wholesale order minimum.', 'protech-wholesale' ),
				wc_price( $minimum - $subtotal ),
				wc_price( $minimum )
			);
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
