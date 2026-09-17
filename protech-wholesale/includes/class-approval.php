<?php
/**
 * WooCommerce → Wholesale admin screen: applicant list (approve/reject)
 * plus the Settings tab, and the per-user profile fields (manual
 * wholesale flag, per-customer price overrides, per-customer minimum).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Approval
 */
class Approval {

	public const META_PRICE_OVERRIDES = '_protech_price_overrides';
	public const META_MIN_ORDER       = '_protech_wholesale_min_order_override';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_protech_approve_applicant', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_protech_reject_applicant', array( $this, 'handle_reject' ) );

		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Wholesale', 'protech-wholesale' ),
			__( 'Wholesale', 'protech-wholesale' ),
			'manage_woocommerce',
			'protech-wholesale',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$tab = sanitize_key( $_GET['tab'] ?? 'applicants' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Wholesale', 'protech-wholesale' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=protech-wholesale' ) ),
			'applicants' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Applicants', 'protech-wholesale' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=protech-wholesale&tab=products' ) ),
			'products' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Products', 'protech-wholesale' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=protech-wholesale&tab=tiers' ) ),
			'tiers' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Tiers', 'protech-wholesale' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=protech-wholesale&tab=settings' ) ),
			'settings' === $tab ? 'nav-tab-active' : ''
			,
			esc_html__( 'Settings', 'protech-wholesale' )
		);
		echo '</nav>';

		if ( 'settings' === $tab ) {
			( new Settings() )->render_settings_tab();
		} elseif ( 'products' === $tab ) {
			( new ProductFields() )->render_products_tab();
		} elseif ( 'tiers' === $tab ) {
			Tiers::render_tiers_tab();
		} else {
			$this->render_applicants_tab();
		}

		echo '</div>';
	}

	private function render_applicants_tab(): void {
		$table = new ApplicantsListTable();
		$table->views();
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="protech-wholesale" />';
		$table->display();
		echo '</form>';
	}

	public function handle_approve(): void {
		$user_id = absint( $_GET['user_id'] ?? 0 );

		check_admin_referer( 'protech_approve_applicant_' . $user_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$user = get_userdata( $user_id );

		if ( $user ) {
			$user->set_role( Roles::CUSTOMER );
			update_user_meta( $user_id, '_protech_wholesale_app_status', 'approved' );
			Logger::info( "Wholesale applicant #{$user_id} approved by admin #" . get_current_user_id() );
			Emails::send_approved( $user_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&status=approved' ) );
		exit;
	}

	public function handle_reject(): void {
		$user_id = absint( $_GET['user_id'] ?? 0 );

		check_admin_referer( 'protech_reject_applicant_' . $user_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$reason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : '';
		$user   = get_userdata( $user_id );

		if ( $user ) {
			update_user_meta( $user_id, '_protech_wholesale_app_status', 'rejected' );
			update_user_meta( $user_id, '_protech_wholesale_app_reject_reason', $reason );
			Logger::info( "Wholesale applicant #{$user_id} rejected by admin #" . get_current_user_id() . ( $reason ? " ({$reason})" : '' ) );
			Emails::send_rejected( $user_id, $reason );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&status=pending' ) );
		exit;
	}

	// -----------------------------------------------------------------
	// User profile: manual flag + per-customer overrides.
	// -----------------------------------------------------------------

	public function render_profile_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$is_wholesale   = Roles::is_wholesale_customer( $user->ID );
		$current_tier   = Tiers::get_user_tier( $user->ID );
		$min_override   = get_user_meta( $user->ID, self::META_MIN_ORDER, true );
		$overrides      = get_user_meta( $user->ID, self::META_PRICE_OVERRIDES, true );
		$overrides      = is_array( $overrides ) ? $overrides : array();

		wp_nonce_field( 'protech_wholesale_profile', 'protech_wholesale_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Protech Wholesale', 'protech-wholesale' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="protech_is_wholesale"><?php esc_html_e( 'Wholesale status', 'protech-wholesale' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="protech_is_wholesale" id="protech_is_wholesale" value="1" <?php checked( $is_wholesale ); ?> />
						<?php esc_html_e( 'Flag this customer as an approved wholesale customer', 'protech-wholesale' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Switches their role to Wholesale Customer immediately, without going through the application queue.', 'protech-wholesale' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="protech_wholesale_tier"><?php esc_html_e( 'Tier', 'protech-wholesale' ); ?></label></th>
				<td>
					<select name="protech_wholesale_tier" id="protech_wholesale_tier">
						<?php foreach ( Tiers::get_tier_labels() as $tier_slug => $tier_label ) : ?>
							<option value="<?php echo esc_attr( $tier_slug ); ?>" <?php selected( $current_tier, $tier_slug ); ?>><?php echo esc_html( $tier_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Internal only — the customer never sees their tier. Sets their minimum order and wholesale price discount unless overridden below. Manage tier-wide settings under WooCommerce → Wholesale → Tiers.', 'protech-wholesale' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="protech_min_order_override"><?php esc_html_e( 'Minimum order override', 'protech-wholesale' ); ?></label></th>
				<td>
					<input type="number" step="0.01" min="0" name="protech_min_order_override" id="protech_min_order_override" value="<?php echo esc_attr( $min_override ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) Settings::get_min_order() ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank to use the global minimum wholesale order subtotal.', 'protech-wholesale' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Per-customer price overrides', 'protech-wholesale' ); ?></th>
				<td>
					<div id="protech-price-overrides">
						<?php foreach ( $overrides as $product_id => $price ) : ?>
							<p class="protech-price-override-row">
								<input type="number" name="protech_price_override_ids[]" value="<?php echo esc_attr( (string) $product_id ); ?>" placeholder="<?php esc_attr_e( 'Product/variation ID', 'protech-wholesale' ); ?>" />
								<input type="number" step="0.01" min="0" name="protech_price_override_prices[]" value="<?php echo esc_attr( (string) $price ); ?>" placeholder="<?php esc_attr_e( 'Price per pack', 'protech-wholesale' ); ?>" />
								<button type="button" class="button protech-remove-override-row">&times;</button>
							</p>
						<?php endforeach; ?>
					</div>
					<button
						type="button"
						class="button"
						id="protech-add-override-row"
						data-placeholder-id="<?php esc_attr_e( 'Product/variation ID', 'protech-wholesale' ); ?>"
						data-placeholder-price="<?php esc_attr_e( 'Price per pack', 'protech-wholesale' ); ?>"
					><?php esc_html_e( '+ Add price override', 'protech-wholesale' ); ?></button>
					<p class="description"><?php esc_html_e( 'Beats the group wholesale price for this customer only. Leave empty for none.', 'protech-wholesale' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['protech_wholesale_profile_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_profile_nonce'] ) ),
				'protech_wholesale_profile'
			)
		) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$should_be_wholesale = ! empty( $_POST['protech_is_wholesale'] );
		$is_wholesale         = Roles::is_wholesale_customer( $user_id );

		if ( $should_be_wholesale && ! $is_wholesale ) {
			$user->set_role( Roles::CUSTOMER );
			Logger::info( "User #{$user_id} manually flagged as wholesale by admin #" . get_current_user_id() );
		} elseif ( ! $should_be_wholesale && $is_wholesale ) {
			$user->set_role( 'customer' );
			Logger::info( "User #{$user_id} manually un-flagged as wholesale by admin #" . get_current_user_id() );
		}

		if ( isset( $_POST['protech_wholesale_tier'] ) ) {
			Tiers::set_user_tier( $user_id, sanitize_key( wp_unslash( $_POST['protech_wholesale_tier'] ) ) );
		}

		$min_override = sanitize_text_field( wp_unslash( $_POST['protech_min_order_override'] ?? '' ) );

		if ( '' === $min_override ) {
			delete_user_meta( $user_id, self::META_MIN_ORDER );
		} else {
			update_user_meta( $user_id, self::META_MIN_ORDER, (float) $min_override );
		}

		$ids    = array_map( 'absint', (array) ( $_POST['protech_price_override_ids'] ?? array() ) );
		$prices = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['protech_price_override_prices'] ?? array() ) ) );

		$overrides = array();

		foreach ( $ids as $i => $product_id ) {
			if ( $product_id > 0 && isset( $prices[ $i ] ) && '' !== $prices[ $i ] ) {
				$overrides[ $product_id ] = round( (float) $prices[ $i ], 2 );
			}
		}

		update_user_meta( $user_id, self::META_PRICE_OVERRIDES, $overrides );
	}
}
