<?php
/**
 * R6: stamps `_protech_is_wholesale` on the order at checkout (so
 * reporting survives a later role change), and adds a wholesale
 * column + filter to the admin orders list — for both the legacy
 * (posts-based) and HPOS (custom order tables) screens.
 *
 * The exact HPOS admin-list hook names below are WooCommerce's
 * documented extension points as of WC 8.x, but the specific column/
 * filter-dropdown placement should get a quick visual check on staging
 * (see QA.md) since it couldn't be verified against a live install here.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrdersAdmin
 */
class OrdersAdmin {

	public const META_IS_WHOLESALE = '_protech_is_wholesale';

	/**
	 * Whether an order is a wholesale order: the flag stamped at checkout
	 * (see stamp_wholesale_flag()) when present, falling back to the
	 * customer's current role for orders placed before the Store API
	 * stamp hook existed (see Emails::flag_wholesale_order_subject(),
	 * the original home of this check, and FlowTriggers::on_order_status_changed(),
	 * which reuses it to gate order-status flows).
	 */
	public static function is_wholesale_order( \WC_Order $order ): bool {
		$flag = (string) $order->get_meta( self::META_IS_WHOLESALE );

		return 'yes' === $flag
			|| ( '' === $flag && Roles::is_wholesale_customer( (int) $order->get_customer_id() ) );
	}

	public function register_hooks(): void {
		// Classic (shortcode) checkout builds the order in WC_Checkout::create_order().
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_wholesale_flag' ), 10, 2 );

		// WooCommerce Blocks checkout — what this site actually uses — goes
		// through the Store API's OrderController instead, which never fires
		// woocommerce_checkout_create_order; this is its equivalent (fired on
		// every draft-order update and again on the final place-order call).
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'stamp_wholesale_flag' ), 10, 1 );

		// Legacy (posts-based) orders screen.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter_dropdown_legacy' ) );
		add_filter( 'request', array( $this, 'filter_legacy_orders_by_wholesale' ) );

		// HPOS (custom order tables) orders screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filter_dropdown_hpos' ), 10, 2 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'filter_hpos_orders_by_wholesale' ) );
	}

	/**
	 * @param \WC_Order $order
	 * @param array     $data  Posted checkout data (classic checkout only).
	 */
	public function stamp_wholesale_flag( \WC_Order $order, array $data = array() ): void {
		// The Store API sets the order's customer late in its draft-order
		// update; the logged-in user is the reliable fallback there.
		$customer_id = (int) $order->get_customer_id() ?: get_current_user_id();

		$order->update_meta_data( self::META_IS_WHOLESALE, Roles::is_wholesale_customer( $customer_id ) ? 'yes' : 'no' );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$with_wholesale = array();

		foreach ( $columns as $key => $label ) {
			$with_wholesale[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$with_wholesale['protech_wholesale'] = __( 'Wholesale', 'protech-wholesale' );
			}
		}

		if ( ! isset( $with_wholesale['protech_wholesale'] ) ) {
			$with_wholesale['protech_wholesale'] = __( 'Wholesale', 'protech-wholesale' );
		}

		return $with_wholesale;
	}

	public function render_column_legacy( string $column ): void {
		if ( 'protech_wholesale' !== $column ) {
			return;
		}

		global $post;

		$order = wc_get_order( $post->ID );

		if ( $order ) {
			$this->render_badge( $order );
		}
	}

	public function render_column_hpos( string $column, $order ): void {
		if ( 'protech_wholesale' !== $column || ! $order instanceof \WC_Order ) {
			return;
		}

		$this->render_badge( $order );
	}

	private function render_badge( \WC_Order $order ): void {
		if ( 'yes' === $order->get_meta( self::META_IS_WHOLESALE ) ) {
			echo '<span class="protech-wholesale-badge" title="' . esc_attr__( 'Wholesale order', 'protech-wholesale' ) . '">' . esc_html__( 'Wholesale', 'protech-wholesale' ) . '</span>';
		} else {
			echo '&#8212;';
		}
	}

	private function render_filter_dropdown_markup(): void {
		$current = sanitize_key( $_GET['wholesale_filter'] ?? '' );
		?>
		<select name="wholesale_filter">
			<option value=""><?php esc_html_e( 'All order types', 'protech-wholesale' ); ?></option>
			<option value="wholesale" <?php selected( $current, 'wholesale' ); ?>><?php esc_html_e( 'Wholesale only', 'protech-wholesale' ); ?></option>
			<option value="retail" <?php selected( $current, 'retail' ); ?>><?php esc_html_e( 'Retail only', 'protech-wholesale' ); ?></option>
		</select>
		<?php
	}

	public function render_filter_dropdown_legacy( string $post_type = '' ): void {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$this->render_filter_dropdown_markup();
	}

	/**
	 * @param string $order_type
	 * @param string $which      'top' or 'bottom' — the hook fires for both tablenavs.
	 */
	public function render_filter_dropdown_hpos( string $order_type = '', string $which = 'top' ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$this->render_filter_dropdown_markup();
	}

	/**
	 * @param array<string, mixed> $query_vars
	 * @return array<string, mixed>
	 */
	public function filter_legacy_orders_by_wholesale( array $query_vars ): array {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || ( $query_vars['post_type'] ?? '' ) !== 'shop_order' ) {
			return $query_vars;
		}

		$meta_query = $this->wholesale_meta_query();

		if ( $meta_query ) {
			$query_vars['meta_query'] = array_merge( $query_vars['meta_query'] ?? array(), array( $meta_query ) );
		}

		return $query_vars;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function filter_hpos_orders_by_wholesale( array $args ): array {
		if ( ! is_admin() ) {
			return $args;
		}

		$meta_query = $this->wholesale_meta_query();

		if ( $meta_query ) {
			$args['meta_query'] = array_merge( $args['meta_query'] ?? array(), array( $meta_query ) );
		}

		return $args;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function wholesale_meta_query(): ?array {
		$filter = sanitize_key( $_GET['wholesale_filter'] ?? '' );

		if ( 'wholesale' === $filter ) {
			return array( 'key' => self::META_IS_WHOLESALE, 'value' => 'yes' );
		}

		if ( 'retail' === $filter ) {
			return array(
				'relation' => 'OR',
				array( 'key' => self::META_IS_WHOLESALE, 'value' => 'yes', 'compare' => '!=' ),
				array( 'key' => self::META_IS_WHOLESALE, 'compare' => 'NOT EXISTS' ),
			);
		}

		return null;
	}
}
