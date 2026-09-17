<?php
/**
 * R4: "Reorder" — a button on the My Account orders list/order detail
 * that pre-fills the Quick Order form with a past order's quantities,
 * and a "reorder last order" shortcut for the Quick Order tab.
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

	public function register_hooks(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_reorder_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_reorder_button_on_order_detail' ) );
	}

	/**
	 * @param array     $actions
	 * @param \WC_Order $order
	 */
	public function add_reorder_action( array $actions, $order ): array {
		if ( ! Roles::is_wholesale_customer( $order->get_customer_id() ) ) {
			return $actions;
		}

		$actions['protech_reorder'] = array(
			'url'  => esc_url( $this->get_reorder_url( $order->get_id() ) ),
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
			esc_url( $this->get_reorder_url( $order->get_id() ) ),
			esc_html__( 'Reorder', 'protech-wholesale' )
		);
	}

	private function get_reorder_url( int $order_id ): string {
		return add_query_arg( 'reorder', $order_id, MyAccount::quick_order_url() );
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
	 * Builds the { itemId: cases } map (plus any skip notes) used to
	 * pre-fill the Quick Order form from a past order.
	 *
	 * @return array{cases: array<int,int>, notes: string[]}|\WP_Error
	 */
	public static function build_prefill_payload( int $order_id, int $user_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'protech_reorder_not_found', __( 'That order could not be found.', 'protech-wholesale' ) );
		}

		if ( (int) $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'protech_reorder_forbidden', __( 'That order does not belong to your account.', 'protech-wholesale' ) );
		}

		$cases = array();
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
					__( '%s is currently out of stock and was skipped — the rest of your order was pre-filled.', 'protech-wholesale' ),
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

			$cases[ $product->get_id() ] = (int) round( CaseRules::cases_for_quantity( $product->get_id(), $item->get_quantity() ) );
		}

		return array(
			'cases' => $cases,
			'notes' => $notes,
		);
	}
}
