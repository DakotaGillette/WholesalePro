<?php
/**
 * R4: the Quick Order form — [protech_wholesale_order_form] shortcode,
 * rendered on a standalone page and again inside the My Account
 * "Quick Order" tab (MyAccount::ENDPOINT). One row per wholesale-eligible
 * product/variation, quantities entered in cases, AJAX add-all-to-cart.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderForm
 */
class OrderForm {

	public function register_hooks(): void {
		add_shortcode( 'protech_wholesale_order_form', array( $this, 'render' ) );
		add_action( 'wp_ajax_protech_add_all_to_cart', array( $this, 'handle_add_all_to_cart' ) );
	}

	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view wholesale pricing and order.', 'protech-wholesale' ) . '</p>';
		}

		if ( Roles::is_wholesale_pending() ) {
			return '<p>' . esc_html__( 'Your wholesale application is still under review. We will email you once a decision has been made.', 'protech-wholesale' ) . '</p>';
		}

		if ( ! Roles::is_wholesale_customer() ) {
			return '<p>' . sprintf(
				/* translators: %s: apply page URL. */
				wp_kses_post( __( 'This page is for approved wholesale accounts. <a href="%s">Apply for a wholesale account</a>.', 'protech-wholesale' ) ),
				esc_url( home_url( '/wholesale-application' ) )
			) . '</p>';
		}

		$rows = $this->get_wholesale_rows( get_current_user_id() );

		if ( empty( $rows ) ) {
			return '<p>' . esc_html__( 'No products are currently available for wholesale ordering. Please check back soon.', 'protech-wholesale' ) . '</p>';
		}

		$prefill      = array();
		$prefill_note = '';

		if ( isset( $_GET['reorder'] ) ) {
			$payload = Reorder::build_prefill_payload( absint( $_GET['reorder'] ), get_current_user_id() );

			if ( ! is_wp_error( $payload ) ) {
				$prefill      = $payload['cases'];
				$prefill_note = implode( ' ', $payload['notes'] );
			} else {
				$prefill_note = $payload->get_error_message();
			}
		}

		$last_order = Reorder::get_last_order_for_user( get_current_user_id() );

		ob_start();
		wc_get_template(
			'order-form.php',
			array(
				'rows'         => $rows,
				'prefill'      => $prefill,
				'prefill_note' => $prefill_note,
				'last_order'   => $last_order,
				'minimum'      => CaseRules::get_minimum_order( get_current_user_id() ),
			),
			'',
			PROTECH_WHOLESALE_DIR . 'templates/'
		);

		return (string) ob_get_clean();
	}

	/**
	 * One row per wholesale-eligible product/variation for this user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_wholesale_rows( int $user_id ): array {
		$rows     = array();
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'type'   => array( 'simple', 'variable' ),
			)
		);

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$row = $this->build_row( wc_get_product( $variation_id ), $product, $user_id );

					if ( $row ) {
						$rows[] = $row;
					}
				}
			} else {
				$row = $this->build_row( $product, $product, $user_id );

				if ( $row ) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	/**
	 * @param \WC_Product|false $item      The variation (or the simple product itself).
	 * @param \WC_Product       $parent    The parent product (== $item for simple products).
	 */
	private function build_row( $item, \WC_Product $parent, int $user_id ): ?array {
		if ( ! $item instanceof \WC_Product ) {
			return null;
		}

		$price = Pricing::get_wholesale_price( $item->get_id(), $user_id );

		if ( null === $price ) {
			return null;
		}

		$case_size     = CaseRules::get_case_size( $item->get_id() );
		$price_per_case = $price * $case_size;

		$label = $item->is_type( 'variation' ) ? wc_get_formatted_variation( $item, true ) : $item->get_name();

		return array(
			'product_id'      => $parent->get_id(),
			'variation_id'    => $item->is_type( 'variation' ) ? $item->get_id() : 0,
			'item_id'         => $item->get_id(),
			'label'           => $label ?: $parent->get_name(),
			'sku'             => $item->get_sku(),
			'image'           => $item->get_image( 'thumbnail' ),
			'case_size'       => $case_size,
			'price_per_case'  => $price_per_case,
			'stock_cases'     => $this->stock_in_cases( $item, $case_size ),
			'in_stock'        => $item->is_in_stock(),
		);
	}

	private function stock_in_cases( \WC_Product $product, int $case_size ): string {
		if ( ! $product->is_in_stock() ) {
			return __( 'Out of stock', 'protech-wholesale' );
		}

		if ( $product->managing_stock() ) {
			$cases = (int) floor( (float) $product->get_stock_quantity() / max( 1, $case_size ) );

			return sprintf(
				/* translators: %d: number of full cases in stock. */
				_n( '%d case', '%d cases', $cases, 'protech-wholesale' ),
				$cases
			);
		}

		return __( 'In stock', 'protech-wholesale' );
	}

	/**
	 * AJAX: add every non-zero row on the Quick Order form to the cart
	 * in one request.
	 */
	public function handle_add_all_to_cart(): void {
		check_ajax_referer( 'protech_wholesale_order_form', 'nonce' );

		if ( ! Roles::is_wholesale_customer() ) {
			wp_send_json_error( array( 'message' => __( 'Your account is not approved for wholesale ordering.', 'protech-wholesale' ) ), 403 );
		}

		$cases = isset( $_POST['cases'] ) && is_array( $_POST['cases'] ) ? wp_unslash( $_POST['cases'] ) : array();

		wc_clear_notices();

		$added  = 0;
		$errors = array();

		foreach ( $cases as $item_id => $case_qty ) {
			$item_id  = absint( $item_id );
			$case_qty = absint( $case_qty );

			if ( ! $item_id || ! $case_qty ) {
				continue;
			}

			$product = wc_get_product( $item_id );

			if ( ! $product ) {
				continue;
			}

			$parent_id     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$variation_id  = $product->is_type( 'variation' ) ? $product->get_id() : 0;
			$quantity      = CaseRules::quantity_for_cases( $item_id, $case_qty );

			$result = WC()->cart->add_to_cart( $parent_id, $quantity, $variation_id );

			if ( false === $result ) {
				$errors[] = sprintf(
					/* translators: %s: product name. */
					__( 'Could not add %s to your cart.', 'protech-wholesale' ),
					$product->get_name()
				);
			} else {
				++$added;
			}
		}

		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$errors[] = wp_strip_all_tags( $notice['notice'] );
		}

		wc_clear_notices();

		$subtotal = (float) WC()->cart->get_subtotal();
		$minimum  = CaseRules::get_minimum_order( get_current_user_id() );

		wp_send_json_success(
			array(
				'added'            => $added,
				'errors'           => $errors,
				'cart_count'       => WC()->cart->get_cart_contents_count(),
				'cart_subtotal'    => wp_strip_all_tags( wc_price( $subtotal ) ),
				'cart_url'         => wc_get_cart_url(),
				'minimum'          => wp_strip_all_tags( wc_price( $minimum ) ),
				'minimum_met'      => $subtotal >= $minimum,
				'remaining'        => $subtotal < $minimum ? wp_strip_all_tags( wc_price( $minimum - $subtotal ) ) : null,
			)
		);
	}
}
