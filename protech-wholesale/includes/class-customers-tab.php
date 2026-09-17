<?php
/**
 * The "Customers" tab of the WooCommerce → Wholesale admin screen: every
 * approved wholesale customer with their store, tier, override count,
 * order count, last order, and lifetime wholesale spend — the view the
 * Applicants → Approved list was standing in for. Read-only; editing
 * still happens on each customer's profile.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomersTab
 */
class CustomersTab {

	private const PER_PAGE = 25;

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

		$args = array(
			'role'        => Roles::CUSTOMER,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'number'      => self::PER_PAGE,
			'paged'       => $paged,
			'count_total' => true,
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new \WP_User_Query( $args );
		$total = (int) $query->get_total();

		echo '<p>' . esc_html__( 'Every approved wholesale customer. Tier, price overrides, and the wholesale flag itself are edited on each customer\'s profile.', 'protech-wholesale' ) . '</p>';

		echo '<form method="get" class="search-form" style="margin:0 0 1em;">';
		echo '<input type="hidden" name="page" value="protech-wholesale" /><input type="hidden" name="tab" value="customers" />';
		echo '<p class="search-box" style="position:static;float:none;">';
		echo '<label class="screen-reader-text" for="protech-customer-search">' . esc_html__( 'Search customers', 'protech-wholesale' ) . '</label>';
		echo '<input type="search" id="protech-customer-search" name="s" value="' . esc_attr( $search ) . '" /> ';
		submit_button( __( 'Search customers', 'protech-wholesale' ), '', '', false );
		echo '</p></form>';

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'No wholesale customers found.', 'protech-wholesale' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach (
			array(
				__( 'Customer', 'protech-wholesale' ),
				__( 'Store', 'protech-wholesale' ),
				__( 'Tier', 'protech-wholesale' ),
				__( 'Price overrides', 'protech-wholesale' ),
				__( 'Orders', 'protech-wholesale' ),
				__( 'Last order', 'protech-wholesale' ),
				__( 'Lifetime spend', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		$tier_labels = Tiers::get_tier_labels();

		foreach ( $query->get_results() as $user ) {
			/** @var \WP_User $user */
			$overrides = get_user_meta( $user->ID, Approval::META_PRICE_OVERRIDES, true );
			$orders    = wc_get_orders(
				array(
					'customer' => $user->ID,
					'status'   => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
					'limit'    => -1,
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);

			$last_order = $orders[0] ?? null;
			$spend      = 0.0;

			foreach ( $orders as $order ) {
				$spend += (float) $order->get_total();
			}

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '"><strong>' . esc_html( $user->display_name ) . '</strong></a><br /><span class="description">' . esc_html( $user->user_email ) . '</span></td>';
			echo '<td>' . esc_html( (string) get_user_meta( $user->ID, '_protech_wholesale_app_store_name', true ) ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $tier_labels[ Tiers::get_user_tier( $user->ID ) ] ?? '' ) . '</td>';
			echo '<td>' . esc_html( (string) ( is_array( $overrides ) ? count( $overrides ) : 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) count( $orders ) ) . '</td>';
			echo '<td>';

			if ( $last_order instanceof \WC_Order ) {
				printf(
					'<a href="%s">#%s</a> <span class="description">%s</span>',
					esc_url( $last_order->get_edit_order_url() ),
					esc_html( $last_order->get_order_number() ),
					esc_html( $last_order->get_date_created() ? $last_order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' )
				);
			} else {
				echo '—';
			}

			echo '</td>';
			echo '<td>' . wp_kses_post( wc_price( $spend ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}
}
