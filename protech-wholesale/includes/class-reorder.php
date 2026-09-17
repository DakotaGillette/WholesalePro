<?php
/**
 * R4: "Reorder" — a button on the My Account orders list, on each order's
 * detail page, and a shortcut on the account dashboard for the most
 * recent order — that adds a past order's still-available items straight
 * to the current cart and sends the customer to /cart/ to review pricing
 * (now recalculated at whatever quantity tier the reorder lands in) and
 * check out. Previously routed through the Quick Order page's prefill
 * query arg; that page was removed, so this now acts directly rather
 * than handing off to a review screen. See DECISIONS.md.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Reorder
 */
class Reorder {

	public const ACTION = 'protech_reorder';

	public function register_hooks(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_reorder_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_reorder_button_on_order_detail' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_last_order_reorder_prompt' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_reorder' ) );
	}

	/**
	 * @param array     $actions
	 * @param \WC_Order $order
	 */
	public function add_reorder_action( array $actions, $order ): array {
		if ( ! Roles::is_wholesale_customer( $order->get_customer_id() ) ) {
			return $actions;
		}

		$actions[ self::ACTION ] = array(
			'url'  => esc_url( self::get_reorder_url( $order->get_id() ) ),
			'name' => __( 'Reorder', 'protech-wholesale' ),
		);

		return $actions;
	}

	public function render_reorder_button_on_order_detail( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Roles::is_wholesale_customer( $order->get_customer_id() ) ) {
			return;
		}

		printf(
			'<p class="protech-reorder-wrap"><a class="button" href="%s">%s</a></p>',
			esc_url( self::get_reorder_url( $order->get_id() ) ),
			esc_html__( 'Reorder', 'protech-wholesale' )
		);
	}

	public function render_last_order_reorder_prompt(): void {
		if ( ! Roles::is_wholesale_customer() ) {
			return;
		}

		$order = self::get_last_order_for_user( get_current_user_id() );

		if ( ! $order ) {
			return;
		}

		printf(
			'<div class="woocommerce-info protech-reorder-note">%s <a class="button" href="%s">%s</a></div>',
			esc_html(
				sprintf(
					/* translators: 1: order number, 2: order date. */
					__( 'Your last order (#%1$s, %2$s) is ready to reorder.', 'protech-wholesale' ),
					$order->get_order_number(),
					wc_format_datetime( $order->get_date_created() )
				)
			),
			esc_url( self::get_reorder_url( $order->get_id() ) ),
			esc_html__( 'Reorder last order', 'protech-wholesale' )
		);
	}

	public static function get_reorder_url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order_id
		);
	}

	public static function get_last_order_for_user( int $user_id ): ?\WC_Order {
		$orders = wc_get_orders(
			array(
				'customer' => $user_id,
				'limit'    => 1,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'status'   => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			)
		);

		return $orders[0] ?? null;
	}

	/**
	 * Handles a click on any of the Reorder links above: adds every
	 * still-available line from the order to the current cart in real
	 * pack quantities, notices any skipped lines, and sends the customer
	 * to the cart to review and check out.
	 */
	public function handle_reorder(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order_id ) ) {
			wp_die( esc_html__( 'This reorder link is invalid or has expired.', 'protech-wholesale' ) );
		}

		if ( ! is_user_logged_in() || ! Roles::is_wholesale_customer() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		// admin-post.php requests run under is_admin(), so WooCommerce
		// never initializes WC()->cart/WC()->session for them the way it
		// would for an ordinary front-end request — without this, the
		// add_to_cart() call below is a fatal (null->add_to_cart()) that
		// silently truncates the response with no visible error.
		wc_load_cart();

		$result = self::add_order_items_to_cart( $order_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			foreach ( $result['notes'] as $note ) {
				wc_add_notice( $note, 'notice' );
			}

			if ( $result['added'] > 0 ) {
				wc_add_notice(
					sprintf(
						/* translators: %d: number of line items added to the cart. */
						_n( 'Added %d item from your past order to your cart.', 'Added %d items from your past order to your cart.', $result['added'], 'protech-wholesale' ),
						$result['added']
					),
					'success'
				);
			}
		}

		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * @return array{added: int, notes: string[]}|\WP_Error
	 */
	public static function add_order_items_to_cart( int $order_id, int $user_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'protech_reorder_not_found', __( 'That order could not be found.', 'protech-wholesale' ) );
		}

		if ( (int) $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'protech_reorder_forbidden', __( 'That order does not belong to your account.', 'protech-wholesale' ) );
		}

		$added = 0;
		$notes = array();

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product ) {
				$notes[] = sprintf(
					/* translators: %s: order line item name. */
					__( '%s is no longer available and was skipped.', 'protech-wholesale' ),
					$item->get_name()
				);
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				$notes[] = sprintf(
					/* translators: %s: product name. */
					__( '%s is currently out of stock and was skipped.', 'protech-wholesale' ),
					$product->get_name()
				);
				continue;
			}

			if ( ! Pricing::is_available_at_wholesale( $product->get_id(), $user_id ) ) {
				$notes[] = sprintf(
					/* translators: %s: product name. */
					__( '%s is no longer available at wholesale and was skipped.', 'protech-wholesale' ),
					$product->get_name()
				);
				continue;
			}

			$parent_id       = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$variation_id    = $product->is_type( 'variation' ) ? $product->get_id() : 0;
			$ordered_quantity = $item->get_quantity();

			$result = WC()->cart->add_to_cart( $parent_id, $ordered_quantity, $variation_id );

			if ( false === $result ) {
				$notes[] = sprintf(
					/* translators: %s: product name. */
					__( 'Could not add %s to your cart.', 'protech-wholesale' ),
					$product->get_name()
				);
				continue;
			}

			++$added;

			// WooCommerce itself (not this plugin) silently rounds a
			// below-minimum quantity UP to the product's current minimum
			// order quantity inside add_to_cart() — driven by the same
			// case-size minimum CaseRules::set_quantity_step() sets for
			// the front-end quantity field. That minimum can only have
			// gone UP since the original order (case-multiple validation
			// already guarantees every real order was valid at the time
			// it was placed), so this only ever surfaces here if the
			// store's case size/minimum changed since — surface it
			// rather than letting the customer discover a bigger cart
			// than they clicked "Reorder" expecting.
			$cart_item = WC()->cart->get_cart_item( $result );
			$new_quantity = $cart_item['quantity'] ?? $ordered_quantity;

			if ( $new_quantity > $ordered_quantity ) {
				$notes[] = sprintf(
					/* translators: 1: product name, 2: originally ordered quantity, 3: new, adjusted quantity. */
					__( '%1$s: your original quantity of %2$d packs was increased to %3$d packs to meet the current minimum order quantity for this product.', 'protech-wholesale' ),
					$product->get_name(),
					$ordered_quantity,
					$new_quantity
				);
			}
		}

		return array(
			'added' => $added,
			'notes' => $notes,
		);
	}
}
