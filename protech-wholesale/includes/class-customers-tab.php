<?php
/**
 * The "Customers" tab of the WooCommerce → Wholesale admin screen: every
 * approved wholesale customer with their store, tier, override count,
 * order count, last order, and lifetime spend — the view the Applicants →
 * Approved list was standing in for. The tier can be changed right here;
 * price overrides and the wholesale flag itself live on the profile.
 *
 * Order count and spend come from WooCommerce's own per-customer
 * lookups (wc_get_customer_order_count(), wc_get_customer_total_spent()),
 * which are cached in user meta — not from loading every order of every
 * customer on the page, which is what this tab did before 1.3.0.
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

		self::maybe_save_tiers();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only paging and search.
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		// phpcs:enable

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

		echo '<p>' . esc_html__( 'Every approved wholesale customer. Change a tier here and save; price overrides and the wholesale flag itself are on each customer\'s profile.', 'protech-wholesale' ) . '</p>';

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

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_customer_tiers', 'protech_wholesale_customer_tiers_nonce' );

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
			$overrides  = get_user_meta( $user->ID, Approval::META_PRICE_OVERRIDES, true );
			$last_order = self::last_order( (int) $user->ID );
			$user_tier  = Tiers::get_user_tier( (int) $user->ID );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '"><strong>' . esc_html( $user->display_name ) . '</strong></a><br /><span class="description">' . esc_html( $user->user_email ) . '</span></td>';
			echo '<td>' . esc_html( (string) get_user_meta( $user->ID, '_protech_wholesale_app_store_name', true ) ?: '—' ) . '</td>';

			echo '<td><select name="protech_tier[' . esc_attr( (string) $user->ID ) . ']" aria-label="' . esc_attr( sprintf( /* translators: %s: customer name. */ __( 'Tier for %s', 'protech-wholesale' ), $user->display_name ) ) . '">';
			foreach ( $tier_labels as $slug => $label ) {
				echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $user_tier, $slug, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td>';

			echo '<td>' . esc_html( (string) ( is_array( $overrides ) ? count( $overrides ) : 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) wc_get_customer_order_count( (int) $user->ID ) ) . '</td>';
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
			echo '<td>' . wp_kses_post( wc_price( wc_get_customer_total_spent( (int) $user->ID ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Save tiers', 'protech-wholesale' ), 'secondary', 'protech_save_customer_tiers' );
		echo '</form>';

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

	private static function last_order( int $user_id ): ?\WC_Order {
		$orders = wc_get_orders(
			array(
				'customer' => $user_id,
				'status'   => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
				'limit'    => 1,
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);

		return $orders[0] ?? null;
	}

	/** The "Save tiers" button: only rows whose tier actually changed are written. */
	private static function maybe_save_tiers(): void {
		if ( ! isset( $_POST['protech_wholesale_customer_tiers_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_customer_tiers_nonce'] ) ),
				'protech_wholesale_customer_tiers'
			)
		) {
			return;
		}

		$posted  = isset( $_POST['protech_tier'] ) && is_array( $_POST['protech_tier'] ) ? wp_unslash( $_POST['protech_tier'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		$changed = 0;

		foreach ( $posted as $user_id => $tier ) {
			$user_id = absint( $user_id );
			$tier    = sanitize_key( (string) $tier );

			if ( ! $user_id || ! Roles::is_wholesale_customer( $user_id ) || Tiers::get_user_tier( $user_id ) === $tier ) {
				continue;
			}

			Tiers::set_user_tier( $user_id, $tier );
			++$changed;
		}

		if ( $changed > 0 ) {
			Logger::info( sprintf( 'Customer tiers changed for %d account(s) by admin #%d', $changed, get_current_user_id() ) );
		}

		echo '<div class="updated notice"><p>' . esc_html(
			sprintf(
				/* translators: %d: number of customers whose tier changed. */
				_n( '%d customer tier updated.', '%d customer tiers updated.', $changed, 'protech-wholesale' ),
				$changed
			)
		) . '</p></div>';
	}
}
