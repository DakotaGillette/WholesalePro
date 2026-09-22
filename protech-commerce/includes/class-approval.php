<?php
/**
 * WooCommerce → Wholesale admin screen (tab shell + applicant list with
 * approve/reject), and the per-user profile fields (manual wholesale
 * flag, tier, per-customer price overrides, the submitted application).
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

	/**
	 * Application lifecycle, stored on the user. The Applicants screen's
	 * Pending/Rejected views are driven by this status, not by role: a
	 * rejected applicant no longer holds the pending role, and a pending
	 * application recorded against a staff account never gets the role at
	 * all (see ApplicationForm::create_pending_applicant()).
	 */
	public const META_APP_STATUS        = '_protech_wholesale_app_status';
	public const META_APP_REJECT_REASON = '_protech_wholesale_app_reject_reason';
	public const STATUS_PENDING         = 'pending';
	public const STATUS_APPROVED        = 'approved';
	public const STATUS_REJECTED        = 'rejected';

	/** When an account first became an approved wholesale customer (GMT unix timestamp) — used by the first-order-nudge automation. Never overwritten once set, so a later re-approval keeps the original date. */
	public const META_APPROVED_AT = '_protech_wholesale_approved_at';

	public const PENDING_COUNT_TRANSIENT = 'protech_wholesale_pending_count';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'load-woocommerce_page_protech-wholesale', array( $this, 'add_help_tab' ) );
		add_action( 'admin_post_protech_approve_applicant', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_protech_reject_applicant', array( $this, 'handle_reject' ) );

		// Stamps the approval date the moment the Customer role is added to
		// an account, regardless of which path granted it (the Applicants
		// queue, the profile checkbox, or a future integration calling
		// Roles::grant() directly) — WP_User::add_role() fires this for
		// every one of them.
		add_action( 'add_user_role', array( __CLASS__, 'maybe_stamp_approved_at' ), 10, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'maybe_stamp_approved_at' ), 10, 3 );

		// The pending count on the menu is cached; any change to an
		// application's status starts it over.
		add_action( 'updated_user_meta', array( __CLASS__, 'flush_pending_count_on_meta' ), 10, 3 );
		add_action( 'added_user_meta', array( __CLASS__, 'flush_pending_count_on_meta' ), 10, 3 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'flush_pending_count_on_meta' ), 10, 3 );
		add_action( 'deleted_user', array( __CLASS__, 'flush_pending_count' ) );

		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	public function register_menu(): void {
		$pending = self::get_pending_count();
		$label   = __( 'Wholesale', 'protech-wholesale' );

		// The same bubble WordPress puts on Comments and Plugins: the queue
		// is visible from anywhere in wp-admin, not just once you click in.
		if ( $pending > 0 ) {
			$label .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
				$pending,
				esc_html( sprintf( /* translators: %d: number of pending applications. */ _n( '%d application awaiting review', '%d applications awaiting review', $pending, 'protech-wholesale' ), $pending ) )
			);
		}

		add_submenu_page(
			'woocommerce',
			__( 'Wholesale', 'protech-wholesale' ),
			$label,
			'manage_woocommerce',
			'protech-wholesale',
			array( $this, 'render_page' )
		);
	}

	/** Applications awaiting review, cached for five minutes. */
	public static function get_pending_count(): int {
		$cached = get_transient( self::PENDING_COUNT_TRANSIENT );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new \WP_User_Query(
			array(
				'meta_key'    => self::META_APP_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => self::STATUS_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'count_total' => true,
				'number'      => 1,
				'fields'      => 'ID',
			)
		);

		$count = (int) $query->get_total();
		set_transient( self::PENDING_COUNT_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	public static function flush_pending_count(): void {
		delete_transient( self::PENDING_COUNT_TRANSIENT );
	}

	/**
	 * @param int|string $meta_id
	 * @param int|string $object_id
	 * @param string     $meta_key
	 */
	public static function flush_pending_count_on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( self::META_APP_STATUS === (string) $meta_key ) {
			self::flush_pending_count();
		}
	}

	/**
	 * @param int      $user_id
	 * @param string   $role  The role just added (add_user_role) or set (set_user_role).
	 * @param string[] $old_roles Only present on set_user_role.
	 */
	public static function maybe_stamp_approved_at( int $user_id, string $role, array $old_roles = array() ): void {
		if ( Roles::CUSTOMER !== $role || null !== self::approved_at( $user_id ) ) {
			return;
		}

		self::set_approved_at( $user_id, time() );
	}

	/** GMT unix timestamp an account became an approved wholesale customer, or null if never recorded (see Automations::backfill_approved_at() for pre-1.5.0 accounts). */
	public static function approved_at( int $user_id ): ?int {
		$value = get_user_meta( $user_id, self::META_APPROVED_AT, true );

		return '' !== $value ? (int) $value : null;
	}

	public static function set_approved_at( int $user_id, int $timestamp ): void {
		update_user_meta( $user_id, self::META_APPROVED_AT, $timestamp );
	}

	/**
	 * The WordPress "Help" pull-down on the Wholesale screen: what each tab
	 * is for, and where the settings that are NOT on this screen live.
	 */
	public function add_help_tab(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'protech-wholesale-overview',
				'title'   => __( 'Wholesale tabs', 'protech-wholesale' ),
				'content' =>
					'<p><strong>' . esc_html__( 'Applicants', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'the review queue. Approve gives the account the Wholesale Customer role and emails them a password link; Reject records a reason and emails that.', 'protech-wholesale' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Customers', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'every approved account, with orders and spend. Change a customer\'s tier here; price overrides are on their profile.', 'protech-wholesale' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Products', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'everything priced or flagged for wholesale, for reference. Prices themselves are set on each product\'s Wholesale tab.', 'protech-wholesale' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Pricing & Shipping', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'the Standard / Volume / Bulk ladder, the display and case defaults, and the wholesale shipping rate.', 'protech-wholesale' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Tiers', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'hidden per-customer discount levels (Bronze to Platinum), on top of the ladder.', 'protech-wholesale' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Messaging', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'moved to its own Messaging menu in the sidebar (automated and one-off emails and texts, a send log and SMS consent records).', 'protech-wholesale' ) . '</p>' .
					'<p><strong>' . esc_html__( 'Settings', 'protech-wholesale' ) . '</strong> — ' . esc_html__( 'what wholesale customers see for unpriced products, coupons, which form takes applications, notifications, updates and uninstall.', 'protech-wholesale' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'protech-wholesale-products',
				'title'   => __( 'Pricing a product', 'protech-wholesale' ),
				'content' =>
					'<p>' . esc_html__( 'Open the product and choose its Wholesale tab. A simple product has its wholesale price there. A variable product is priced per color: enter a price in "Apply to all variations" and Update, or expand a variation on the Variations tab to price one color differently.', 'protech-wholesale' ) . '</p>' .
					'<p>' . esc_html__( 'Products with no wholesale price are not sold at wholesale: they are hidden from wholesale customers (or shown at retail, per Settings), and the display and case rules do not apply to them. That is how a one-off like a starter pack is sold to anyone at a plain price.', 'protech-wholesale' ) . '</p>',
			)
		);
	}

	/**
	 * @return array<string, string> tab slug => label, in display order.
	 */
	public static function get_tabs(): array {
		return array(
			'applicants' => __( 'Applicants', 'protech-wholesale' ),
			'customers'  => __( 'Customers', 'protech-wholesale' ),
			'products'   => __( 'Products', 'protech-wholesale' ),
			'pricing'    => __( 'Pricing & Shipping', 'protech-wholesale' ),
			'tiers'      => __( 'Tiers', 'protech-wholesale' ),
			'settings'   => __( 'Settings', 'protech-wholesale' ),
		);
	}

	public static function tab_url( string $tab ): string {
		return 'applicants' === $tab
			? admin_url( 'admin.php?page=protech-wholesale' )
			: admin_url( 'admin.php?page=protech-wholesale&tab=' . $tab );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		$tab = sanitize_key( $_GET['tab'] ?? 'applicants' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.

		echo '<div class="wrap"><h1>' . esc_html__( 'Wholesale', 'protech-wholesale' ) . '</h1>';

		// The pages this plugin runs that live outside wp-admin, one click away.
		$portal_id = SetupChecks::portal_page_id();
		$links     = array();

		if ( $portal_id > 0 ) {
			$links[] = '<a href="' . esc_url( (string) get_permalink( $portal_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Wholesale login page', 'protech-wholesale' ) . '</a>';
		}

		$links[] = '<a href="' . esc_url( home_url( '/wholesale-application' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Application form', 'protech-wholesale' ) . '</a>';
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=protech-wholesale' ) ) . '">' . esc_html__( 'Log', 'protech-wholesale' ) . '</a>';
		$links[] = '<a href="' . esc_url( MessagingTab::url( 'home' ) ) . '">' . esc_html__( 'Messaging', 'protech-wholesale' ) . '</a>';

		echo '<p class="description" style="margin:-6px 0 12px;">' . wp_kses_post( implode( ' &middot; ', $links ) ) . '</p>';

		echo '<nav class="nav-tab-wrapper">';

		foreach ( self::get_tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( self::tab_url( $slug ) ),
				$slug === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';

		if ( 'settings' === $tab ) {
			( new Settings() )->render_settings_tab();
		} elseif ( 'customers' === $tab ) {
			CustomersTab::render();
		} elseif ( 'products' === $tab ) {
			( new ProductFields() )->render_products_tab();
		} elseif ( 'pricing' === $tab ) {
			VolumePricing::render_pricing_tab();
		} elseif ( 'tiers' === $tab ) {
			Tiers::render_tiers_tab();
		} else {
			$this->render_applicants_tab();
		}

		echo '</div>';
	}

	private function render_applicants_tab(): void {
		// Only while something is missing; otherwise nothing is printed.
		SetupChecks::render_notice();

		$table = new ApplicantsListTable();
		$table->views();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="protech-wholesale" />';
		echo '<input type="hidden" name="status" value="' . esc_attr( ApplicantsListTable::current_view() ) . '" />';
		$table->search_box( __( 'Search applicants', 'protech-wholesale' ), 'protech-applicant' );
		$table->display();
		echo '</form>';

		// Filled in and submitted by assets/js/admin.js when an admin clicks
		// Reject, so the optional reason travels as POST data rather than in
		// the URL. The Reject link itself still works as a plain nonce'd GET
		// (with no reason) if JavaScript is unavailable.
		echo '<form id="protech-reject-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
		echo '<input type="hidden" name="action" value="protech_reject_applicant" />';
		echo '<input type="hidden" name="user_id" value="" />';
		echo '<input type="hidden" name="_wpnonce" value="" />';
		echo '<input type="hidden" name="reason" value="" />';
		echo '</form>';
	}

	public function handle_approve(): void {
		$user_id = absint( $_REQUEST['user_id'] ?? 0 );

		check_admin_referer( 'protech_approve_applicant_' . $user_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$user = get_userdata( $user_id );

		if ( $user ) {
			self::approve_user( $user_id, true );
			Logger::info( "Wholesale applicant #{$user_id} approved by admin #" . get_current_user_id() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&status=approved' ) );
		exit;
	}

	/**
	 * The one place that actually grants wholesale status — additive
	 * (whatever else the account already is stays intact), clears any
	 * stale rejection, and optionally emails the same "you're approved,
	 * set your password" message the Applicants queue sends. Shared by
	 * the applicant queue, the profile checkbox, and the Customers tab's
	 * "add existing customers" and "add a new wholesale customer" actions.
	 */
	public static function approve_user( int $user_id, bool $send_email ): void {
		Roles::grant( $user_id, Roles::CUSTOMER );
		update_user_meta( $user_id, self::META_APP_STATUS, self::STATUS_APPROVED );
		delete_user_meta( $user_id, self::META_APP_REJECT_REASON );

		if ( $send_email ) {
			Emails::send_approved( $user_id );
		}
	}

	public function handle_reject(): void {
		$user_id = absint( $_REQUEST['user_id'] ?? 0 );

		check_admin_referer( 'protech_reject_applicant_' . $user_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		// POSTed by admin.js's reason prompt; the GET form is the no-JS fallback.
		$reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? $_GET['reason'] ?? '' ) );
		$user   = get_userdata( $user_id );

		if ( $user ) {
			// A rejected applicant is no longer "pending": drop that role so
			// they stop landing on the under-review screen (and drop out of
			// the Pending list), but keep the record so the Rejected view
			// and a later re-application both work.
			Roles::revoke( $user_id, Roles::PENDING );
			update_user_meta( $user_id, self::META_APP_STATUS, self::STATUS_REJECTED );
			update_user_meta( $user_id, self::META_APP_REJECT_REASON, $reason );
			Logger::info( "Wholesale applicant #{$user_id} rejected by admin #" . get_current_user_id() . ( $reason ? " ({$reason})" : '' ) );
			Emails::send_rejected( $user_id, $reason );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&status=rejected' ) );
		exit;
	}

	// -----------------------------------------------------------------
	// User profile: manual flag, tier, overrides, submitted application.
	// -----------------------------------------------------------------

	public function render_profile_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$is_wholesale   = Roles::is_wholesale_customer( $user->ID );
		$current_tier   = Tiers::get_user_tier( $user->ID );
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
					<p class="description"><?php esc_html_e( 'Adds the Wholesale Customer role immediately, without going through the application queue. Any other role the account has is kept. If this account has a pending application, checking this also sends them the approval email.', 'protech-wholesale' ); ?></p>
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
					<p class="description"><?php esc_html_e( 'Internal only — the customer never sees their tier. Sets their wholesale price discount unless overridden below. Manage tier-wide settings under WooCommerce → Wholesale → Tiers.', 'protech-wholesale' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Per-customer price overrides', 'protech-wholesale' ); ?></th>
				<td>
					<div id="protech-price-overrides">
						<?php foreach ( $overrides as $product_id => $price ) : ?>
							<?php $override_product = wc_get_product( (int) $product_id ); ?>
							<p class="protech-price-override-row">
								<select
									class="wc-product-search protech-override-product"
									name="protech_price_override_ids[]"
									data-placeholder="<?php esc_attr_e( 'Search for a product or variation…', 'protech-wholesale' ); ?>"
									data-action="woocommerce_json_search_products_and_variations"
									data-allow_clear="true"
									style="width:50%;"
								>
									<option value="<?php echo esc_attr( (string) $product_id ); ?>" selected="selected">
										<?php echo esc_html( $override_product instanceof \WC_Product ? $override_product->get_formatted_name() : sprintf( /* translators: %d: product ID. */ __( 'Product #%d (no longer exists)', 'protech-wholesale' ), (int) $product_id ) ); ?>
									</option>
								</select>
								<input type="number" step="0.01" min="0" name="protech_price_override_prices[]" value="<?php echo esc_attr( (string) $price ); ?>" placeholder="<?php esc_attr_e( 'Price per pack', 'protech-wholesale' ); ?>" />
								<button type="button" class="button protech-remove-override-row">&times;</button>
							</p>
						<?php endforeach; ?>
					</div>
					<button
						type="button"
						class="button"
						id="protech-add-override-row"
						data-placeholder-product="<?php esc_attr_e( 'Search for a product or variation…', 'protech-wholesale' ); ?>"
						data-placeholder-price="<?php esc_attr_e( 'Price per pack', 'protech-wholesale' ); ?>"
					><?php esc_html_e( '+ Add price override', 'protech-wholesale' ); ?></button>
					<p class="description"><?php esc_html_e( 'A per-pack price this one customer pays for that product or variation, regardless of quantity tier. Beats the group wholesale price for this customer only.', 'protech-wholesale' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		$this->render_application_details( $user );
	}

	/**
	 * The answers the applicant submitted, read-only (stored by
	 * ApplicationForm::create_pending_applicant()). Nothing is shown for
	 * an account that never applied (one flagged manually).
	 */
	private function render_application_details( \WP_User $user ): void {
		$status = (string) get_user_meta( $user->ID, self::META_APP_STATUS, true );

		if ( '' === $status ) {
			return;
		}

		$labels = array(
			'store_name'              => __( 'Store name', 'protech-wholesale' ),
			'name'                    => __( 'Contact name', 'protech-wholesale' ),
			'title'                   => __( 'Title', 'protech-wholesale' ),
			'phone'                   => __( 'Phone', 'protech-wholesale' ),
			'email'                   => __( 'Email', 'protech-wholesale' ),
			'business_type'           => __( 'Business type', 'protech-wholesale' ),
			'address'                 => __( 'Address', 'protech-wholesale' ),
			'website'                 => __( 'Website', 'protech-wholesale' ),
			'sales_channels'          => __( 'Sales channels', 'protech-wholesale' ),
			'tcgs_carried'            => __( 'TCGs carried', 'protech-wholesale' ),
			'hosts_events'            => __( 'Hosts TCG events', 'protech-wholesale' ),
			'estimated_monthly_spend'   => __( 'Estimated monthly spend', 'protech-wholesale' ),
			'accuracy_confirmation'     => __( 'Confirmed accuracy', 'protech-wholesale' ),
			'contact_methods'           => __( 'Preferred contact methods', 'protech-wholesale' ),
			'sms_transactional_consent' => __( 'Opted in: order-update texts', 'protech-wholesale' ),
			'sms_marketing_consent'     => __( 'Opted in: marketing texts', 'protech-wholesale' ),
		);

		$status_labels = array(
			self::STATUS_PENDING  => __( 'Pending review', 'protech-wholesale' ),
			self::STATUS_APPROVED => __( 'Approved', 'protech-wholesale' ),
			self::STATUS_REJECTED => __( 'Rejected', 'protech-wholesale' ),
		);

		$submitted = (string) get_user_meta( $user->ID, '_protech_wholesale_app_submitted_at', true );
		$reason    = (string) get_user_meta( $user->ID, self::META_APP_REJECT_REASON, true );
		?>
		<h3><?php esc_html_e( 'Wholesale application', 'protech-wholesale' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Status', 'protech-wholesale' ); ?></th>
				<td>
					<strong><?php echo esc_html( $status_labels[ $status ] ?? $status ); ?></strong>
					<?php if ( '' !== $submitted ) : ?>
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: submission date/time. */
									__( '— submitted %s', 'protech-wholesale' ),
									mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submitted )
								)
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( self::STATUS_REJECTED === $status && '' !== $reason ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: rejection reason. */ __( 'Reason given: %s', 'protech-wholesale' ), $reason ) ); ?></p>
					<?php endif; ?>
					<?php if ( self::STATUS_PENDING === $status && Roles::is_privileged( $user->ID ) ) : ?>
						<p class="description"><?php esc_html_e( 'This is a staff account, so the application was recorded without changing its roles. Use the "Wholesale status" checkbox above if the request is genuine.', 'protech-wholesale' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php foreach ( $labels as $key => $label ) : ?>
				<?php
				$value = get_user_meta( $user->ID, '_protech_wholesale_app_' . $key, true );

				if ( in_array( $key, array( 'hosts_events', 'accuracy_confirmation', 'sms_transactional_consent', 'sms_marketing_consent' ), true ) ) {
					$display = $value ? __( 'Yes', 'protech-wholesale' ) : __( 'No', 'protech-wholesale' );
				} else {
					$display = '' === (string) $value ? '—' : (string) $value;
				}
				?>
				<tr>
					<th><?php echo esc_html( $label ); ?></th>
					<td>
						<?php if ( 'website' === $key && '' !== (string) $value ) : ?>
							<a href="<?php echo esc_url( (string) $value ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $value ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $display ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
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
			$had_pending_application = self::STATUS_PENDING === get_user_meta( $user_id, self::META_APP_STATUS, true );

			// Same outcome as clicking Approve on the Applicants screen —
			// the applicant shouldn't miss their password link because the
			// admin approved from the profile instead.
			self::approve_user( $user_id, $had_pending_application );
			Logger::info( "User #{$user_id} manually flagged as wholesale by admin #" . get_current_user_id() );
		} elseif ( ! $should_be_wholesale && $is_wholesale ) {
			Roles::revoke( $user_id, Roles::CUSTOMER );
			delete_user_meta( $user_id, self::META_APP_STATUS );
			Logger::info( "User #{$user_id} manually un-flagged as wholesale by admin #" . get_current_user_id() );
		}

		if ( isset( $_POST['protech_wholesale_tier'] ) ) {
			Tiers::set_user_tier( $user_id, sanitize_key( wp_unslash( $_POST['protech_wholesale_tier'] ) ) );
		}

		$ids    = array_map( 'absint', (array) ( $_POST['protech_price_override_ids'] ?? array() ) );
		$prices = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['protech_price_override_prices'] ?? array() ) ) );

		$overrides = array();

		foreach ( $ids as $i => $product_id ) {
			if ( $product_id > 0 && isset( $prices[ $i ] ) && '' !== $prices[ $i ] && is_numeric( $prices[ $i ] ) ) {
				$overrides[ $product_id ] = round( (float) $prices[ $i ], 2 );
			}
		}

		update_user_meta( $user_id, self::META_PRICE_OVERRIDES, $overrides );
	}
}
