<?php
/**
 * The "Customers" tab of the WooCommerce → Wholesale admin screen: every
 * approved wholesale customer with their store, tier, override count,
 * order count, last order, and lifetime spend — the view the Applicants →
 * Approved list was standing in for. The tier, tax status, and lifetime
 * affiliate can all be changed right here; price overrides and the
 * wholesale flag itself live on the profile. Tax status is the "Stripe
 * Tax for WooCommerce" plugin's own per-account exemption field (see
 * class-tax-exemption.php); Affiliate is SliceWP's own "lifetime
 * commissions" customer link (see class-affiliate-assignment.php) — both
 * columns read and write the exact same values those plugins use, not a
 * separate mechanism. The Affiliate column only appears when SliceWP's
 * Lifetime Commissions functions are actually available.
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

	public function register_hooks(): void {
		add_action( 'admin_post_protech_add_existing_wholesale_customers', array( __CLASS__, 'handle_add_existing' ) );
		add_action( 'admin_post_protech_create_wholesale_customer', array( __CLASS__, 'handle_create_new' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		self::maybe_save_customer_changes();
		self::maybe_show_quickadd_notice();
		WelcomeEmail::render_notice();

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

		// SliceWP's own manual customer-linking action requires manage_options — matched here, not just manage_woocommerce.
		$affiliates_available = AffiliateAssignment::is_available() && current_user_can( 'manage_options' );
		$affiliate_options    = $affiliates_available ? AffiliateAssignment::get_affiliate_options() : array();

		echo '<p>' . esc_html__( 'Every approved wholesale customer. Change a tier, tax status, or lifetime affiliate here and save; price overrides and the wholesale flag itself are on each customer\'s profile.', 'protech-wholesale' ) . '</p>';

		self::render_quickadd_sections();
		WelcomeEmail::render_preview_box();

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
		echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="protech-select-all-customers" /></td>';

		foreach (
			array(
				__( 'Customer', 'protech-wholesale' ),
				__( 'Store', 'protech-wholesale' ),
				__( 'Tier', 'protech-wholesale' ),
				__( 'Price overrides', 'protech-wholesale' ),
				__( 'Orders', 'protech-wholesale' ),
				__( 'Last order', 'protech-wholesale' ),
				__( 'Lifetime spend', 'protech-wholesale' ),
				__( 'SMS', 'protech-wholesale' ),
				__( 'Tax status', 'protech-wholesale' ),
				__( 'Welcome email', 'protech-wholesale' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		if ( $affiliates_available ) {
			echo '<th>' . esc_html__( 'Affiliate', 'protech-wholesale' ) . '</th>';
		}

		echo '<th></th>';

		echo '</tr></thead><tbody>';

		$tier_labels = Tiers::get_tier_labels();

		foreach ( $query->get_results() as $user ) {
			/** @var \WP_User $user */
			$overrides  = get_user_meta( $user->ID, Approval::META_PRICE_OVERRIDES, true );
			$last_order = Reorder::get_last_order_for_user( (int) $user->ID );
			$user_tier  = Tiers::get_user_tier( (int) $user->ID );
			$sms_state  = SmsConsent::state( (int) $user->ID );

			echo '<tr>';
			echo '<th class="check-column"><input type="checkbox" class="protech-customer-checkbox" name="protech_customer_ids[]" value="' . esc_attr( (string) $user->ID ) . '" /></th>';
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
			echo '<td>' . esc_html( self::sms_status_label( $sms_state ) ) . '</td>';

			echo '<td><select name="protech_tax_status[' . esc_attr( (string) $user->ID ) . ']" aria-label="' . esc_attr( sprintf( /* translators: %s: customer name. */ __( 'Tax status for %s', 'protech-wholesale' ), $user->display_name ) ) . '">';
			foreach ( TaxExemption::status_labels() as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( TaxExemption::status( (int) $user->ID ), $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td>';

			echo wp_kses_post( WelcomeEmail::row_cell( $user ) );

			if ( $affiliates_available ) {
				$assigned_affiliate_id = AffiliateAssignment::get_assigned_affiliate_id( (int) $user->ID );

				echo '<td><select name="protech_affiliate[' . esc_attr( (string) $user->ID ) . ']" aria-label="' . esc_attr( sprintf( /* translators: %s: customer name. */ __( 'Lifetime affiliate for %s', 'protech-wholesale' ), $user->display_name ) ) . '">';
				echo '<option value="0" ' . selected( $assigned_affiliate_id, 0, false ) . '>' . esc_html__( '— None —', 'protech-wholesale' ) . '</option>';
				foreach ( $affiliate_options as $affiliate_id => $affiliate_label ) {
					echo '<option value="' . esc_attr( (string) $affiliate_id ) . '" ' . selected( $assigned_affiliate_id, $affiliate_id, false ) . '>' . esc_html( $affiliate_label ) . '</option>';
				}
				echo '</select></td>';
			}

			echo '<td><a href="' . esc_url( MessagingTab::url( 'compose', array( 'ids' => $user->ID ) ) ) . '">' . esc_html__( 'Message', 'protech-wholesale' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'protech-wholesale' ), 'secondary', 'protech_save_customer_tiers', true, array( 'style' => 'margin-right:8px;' ) );

		printf(
			'<button type="submit" class="button" formaction="%s" formmethod="post" name="action" value="protech_message_customers">%s</button>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Send message to selected', 'protech-wholesale' )
		);

		printf(
			' <button type="submit" class="button" formaction="%s" formmethod="post" name="action" value="protech_send_welcome">%s</button>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Send welcome email', 'protech-wholesale' )
		);

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

	/**
	 * Two collapsed-by-default forms above the search box: bringing an
	 * already-existing WooCommerce customer into wholesale directly (no
	 * application needed — for the backlog of accounts that were always
	 * effectively wholesale before this plugin existed to say so), and
	 * creating a brand new account that's wholesale from the moment it's
	 * created, instead of a plain customer signup someone then has to
	 * remember to flag.
	 */
	private static function render_quickadd_sections(): void {
		echo '<details class="protech-quickadd"><summary>' . esc_html__( '+ Add existing customers to wholesale', 'protech-wholesale' ) . '</summary>';
		echo '<form method="post" style="margin:0.75em 0 0;">';
		wp_nonce_field( 'protech_wholesale_add_existing', 'protech_wholesale_add_existing_nonce' );
		echo '<p>' . esc_html__( 'Already a customer, just never had a wholesale flag? Search for their account and add it directly.', 'protech-wholesale' ) . '</p>';
		echo '<select class="wc-customer-search" multiple="multiple" style="width:100%;max-width:460px;" name="protech_existing_customer_ids[]" data-placeholder="' . esc_attr__( 'Search by name or email…', 'protech-wholesale' ) . '" data-action="woocommerce_json_search_customers"></select>';
		echo '<p><label><input type="checkbox" name="protech_email_existing" value="1" checked="checked" /> ' . esc_html__( 'Send them the welcome email (how to log in, how ordering works)', 'protech-wholesale' ) . '</label></p>';
		printf(
			'<button type="submit" class="button" formaction="%s" formmethod="post" name="action" value="protech_add_existing_wholesale_customers">%s</button>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Add to wholesale', 'protech-wholesale' )
		);
		echo '</form></details>';

		echo '<details class="protech-quickadd"><summary>' . esc_html__( '+ Add a new wholesale customer', 'protech-wholesale' ) . '</summary>';
		echo '<form method="post" style="margin:0.75em 0 1.5em;">';
		wp_nonce_field( 'protech_wholesale_create_customer', 'protech_wholesale_create_customer_nonce' );
		echo '<p>' . esc_html__( "Creates the account directly with wholesale pricing already on, no application step. They'll get an email to set their password.", 'protech-wholesale' ) . '</p>';
		echo '<p>';
		echo '<input type="text" name="protech_new_first_name" placeholder="' . esc_attr__( 'First name', 'protech-wholesale' ) . '" required="required" /> ';
		echo '<input type="text" name="protech_new_last_name" placeholder="' . esc_attr__( 'Last name', 'protech-wholesale' ) . '" /> ';
		echo '<input type="email" name="protech_new_email" placeholder="' . esc_attr__( 'Email address', 'protech-wholesale' ) . '" required="required" />';
		echo '</p>';
		printf(
			'<button type="submit" class="button button-primary" formaction="%s" formmethod="post" name="action" value="protech_create_wholesale_customer">%s</button>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Create wholesale account', 'protech-wholesale' )
		);
		echo '</form></details>';
	}

	/** The redirect landing back on this tab after either quick-add action, per the &protech_quickadd= query arg it set. */
	private static function maybe_show_quickadd_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only controls which fixed notice string is echoed.
		$notice = isset( $_GET['protech_quickadd'] ) ? sanitize_key( wp_unslash( $_GET['protech_quickadd'] ) ) : '';

		$messages = array(
			'added'         => __( 'Added the selected customer(s) to wholesale.', 'protech-wholesale' ),
			'none_selected' => __( 'No customers were selected.', 'protech-wholesale' ),
			'created'       => __( 'Wholesale account created.', 'protech-wholesale' ),
			'exists'        => __( 'An account with that email address already exists — use "Add existing customers" instead.', 'protech-wholesale' ),
			'invalid_email' => __( 'That email address is not valid.', 'protech-wholesale' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$class = in_array( $notice, array( 'added', 'created' ), true ) ? 'updated' : 'notice-warning';
		echo '<div class="' . esc_attr( $class ) . ' notice"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}

	/** "Add existing customers to wholesale": grants the role to every selected, already-existing account. */
	public static function handle_add_existing(): void {
		if ( ! current_user_can( 'manage_woocommerce' )
			|| ! isset( $_POST['protech_wholesale_add_existing_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_add_existing_nonce'] ) ), 'protech_wholesale_add_existing' )
		) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$ids = array_map( 'absint', (array) ( $_POST['protech_existing_customer_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() sanitizes each value.
		$ids = array_filter( array_unique( $ids ) );

		if ( empty( $ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&tab=customers&protech_quickadd=none_selected' ) );
			exit;
		}

		$send_email = ! empty( $_POST['protech_email_existing'] );
		$added      = 0;

		foreach ( $ids as $id ) {
			if ( Roles::is_wholesale_customer( $id ) ) {
				continue;
			}

			// The "approved" email tells an applicant to set a password; these
			// accounts already have one, so they get the welcome email instead.
			Approval::approve_user( $id, false );

			if ( $send_email ) {
				WelcomeEmail::send( $id );
			}

			++$added;
		}

		if ( $added > 0 ) {
			Logger::info( sprintf( '%d existing customer(s) added to wholesale directly by admin #%d', $added, get_current_user_id() ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&tab=customers&protech_quickadd=added' ) );
		exit;
	}

	/** "Add a new wholesale customer": creates the account from scratch, already wholesale, and emails a password-setup link. */
	public static function handle_create_new(): void {
		if ( ! current_user_can( 'manage_woocommerce' )
			|| ! isset( $_POST['protech_wholesale_create_customer_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protech_wholesale_create_customer_nonce'] ) ), 'protech_wholesale_create_customer' )
		) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		$email = sanitize_email( wp_unslash( $_POST['protech_new_email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&tab=customers&protech_quickadd=invalid_email' ) );
			exit;
		}

		if ( email_exists( $email ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&tab=customers&protech_quickadd=exists' ) );
			exit;
		}

		$first_name = sanitize_text_field( wp_unslash( $_POST['protech_new_first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( wp_unslash( $_POST['protech_new_last_name'] ?? '' ) );

		$user_id = wp_insert_user(
			array(
				'user_login' => self::generate_unique_login( $email ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 20 ),
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'role'       => Roles::CUSTOMER,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&tab=customers&protech_quickadd=invalid_email' ) );
			exit;
		}

		update_user_meta( $user_id, Approval::META_APP_STATUS, Approval::STATUS_APPROVED );
		Emails::send_approved( $user_id );
		Logger::info( "Wholesale customer #{$user_id} created directly by admin #" . get_current_user_id() );

		wp_safe_redirect( admin_url( 'admin.php?page=protech-wholesale&tab=customers&protech_quickadd=created' ) );
		exit;
	}

	private static function generate_unique_login( string $email ): string {
		$base  = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'wholesale';
		$login = $base;
		$i     = 1;

		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}

		return $login;
	}

	/**
	 * @param array{phone: string, sms_transactional: string, sms_marketing: string, email_marketing: string} $state
	 */
	private static function sms_status_label( array $state ): string {
		if ( '' === $state['phone'] ) {
			return '—';
		}

		if ( 'yes' === $state['sms_marketing'] ) {
			return __( 'Marketing + updates', 'protech-wholesale' );
		}

		if ( 'yes' === $state['sms_transactional'] ) {
			return __( 'Order updates only', 'protech-wholesale' );
		}

		return __( 'Not opted in', 'protech-wholesale' );
	}

	/** The "Save changes" button: only rows whose tier, tax status, or affiliate actually changed are written. */
	private static function maybe_save_customer_changes(): void {
		if ( ! isset( $_POST['protech_wholesale_customer_tiers_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_customer_tiers_nonce'] ) ),
				'protech_wholesale_customer_tiers'
			)
		) {
			return;
		}

		$posted_tiers     = isset( $_POST['protech_tier'] ) && is_array( $_POST['protech_tier'] ) ? wp_unslash( $_POST['protech_tier'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		$posted_tax       = isset( $_POST['protech_tax_status'] ) && is_array( $_POST['protech_tax_status'] ) ? wp_unslash( $_POST['protech_tax_status'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		$posted_affiliate = isset( $_POST['protech_affiliate'] ) && is_array( $_POST['protech_affiliate'] ) ? wp_unslash( $_POST['protech_affiliate'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		// SliceWP's own manual customer-linking action requires this same capability — matching its security boundary here.
		$can_assign_affiliates = AffiliateAssignment::is_available() && current_user_can( 'manage_options' );
		$changed               = 0;
		$warnings              = array();

		foreach ( $posted_tiers as $user_id => $tier ) {
			$user_id = absint( $user_id );

			if ( ! $user_id || ! Roles::is_wholesale_customer( $user_id ) ) {
				continue;
			}

			$tier = sanitize_key( (string) $tier );

			if ( Tiers::get_user_tier( $user_id ) !== $tier ) {
				Tiers::set_user_tier( $user_id, $tier );
				++$changed;
			}

			$tax_status = isset( $posted_tax[ $user_id ] ) ? sanitize_key( (string) $posted_tax[ $user_id ] ) : '';

			if ( '' !== $tax_status && TaxExemption::status( $user_id ) !== $tax_status ) {
				TaxExemption::set_status( $user_id, $tax_status );
				++$changed;
			}

			if ( ! $can_assign_affiliates ) {
				continue;
			}

			$current_affiliate_id = AffiliateAssignment::get_assigned_affiliate_id( $user_id );
			$affiliate_id         = isset( $posted_affiliate[ $user_id ] ) ? absint( $posted_affiliate[ $user_id ] ) : $current_affiliate_id;

			if ( $affiliate_id === $current_affiliate_id ) {
				continue;
			}

			if ( 0 === $affiliate_id ) {
				AffiliateAssignment::unassign( $user_id );
			} else {
				$warning = AffiliateAssignment::assign( $user_id, $affiliate_id );

				if ( '' !== $warning ) {
					$warnings[] = $warning;
				}
			}

			++$changed;
		}

		if ( $changed > 0 ) {
			Logger::info( sprintf( 'Customer tier/tax status/affiliate changed for %d account(s) by admin #%d', $changed, get_current_user_id() ) );
		}

		echo '<div class="updated notice"><p>' . esc_html(
			sprintf(
				/* translators: %d: number of customer rows changed. */
				_n( '%d customer updated.', '%d customers updated.', $changed, 'protech-wholesale' ),
				$changed
			)
		) . '</p></div>';

		foreach ( $warnings as $warning ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $warning ) . '</p></div>';
		}
	}
}
