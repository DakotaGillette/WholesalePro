<?php
/**
 * Settings, rendered as the "Settings" tab of the WooCommerce → Wholesale
 * admin screen (see Approval::render_page() for the tab shell). Built on
 * the WooCommerce Settings API helpers (woocommerce_admin_fields() /
 * woocommerce_update_options()) rather than a custom settings framework.
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	// 'protech_wholesale_min_order' (a dollar order floor) was removed in
	// 1.3.0; uninstall.php still deletes it on purge.
	public const OPT_DEFAULT_CASE_SIZE      = 'protech_wholesale_default_case_size'; // Packs per DISPLAY — name kept for backwards compatibility, see class-case-rules.php.
	public const OPT_DEFAULT_DISPLAYS_PER_CASE = 'protech_wholesale_default_displays_per_case'; // Displays per (big) CASE.
	public const OPT_EMPTY_PRICE_BEHAVIOR   = 'protech_wholesale_empty_price_behavior'; // 'hide' | 'fallback'.
	public const OPT_ALLOW_RETAIL_COUPONS   = 'protech_wholesale_allow_retail_coupons'; // 'no' | 'yes'.
	public const OPT_EXCLUDE_FREE_SHIPPING  = 'protech_wholesale_exclude_free_shipping'; // 'yes' | 'no'.
	public const OPT_SHIPPING_FLAT_RATE     = 'protech_wholesale_shipping_flat_rate'; // Charged below the volume threshold.
	public const OPT_VOLUME_THRESHOLD_DISPLAYS = 'protech_wholesale_volume_threshold_displays'; // Also the free-shipping cutoff.
	public const OPT_VOLUME_PRICE           = 'protech_wholesale_volume_price';
	public const OPT_BULK_THRESHOLD_CASES   = 'protech_wholesale_bulk_threshold_cases';
	public const OPT_BULK_PRICE             = 'protech_wholesale_bulk_price';
	public const OPT_APPLICATION_SOURCE     = 'protech_wholesale_application_source';
	public const OPT_APPLICATION_FORM_ID    = 'protech_wholesale_application_form_id'; // '' = accept any form from that plugin.
	public const OPT_PURGE_ON_UNINSTALL     = 'protech_wholesale_purge_on_uninstall'; // 'no' | 'yes'.
	public const OPT_NOTIFICATION_EMAIL     = 'protech_wholesale_notification_email'; // '' = site admin email.
	public const OPT_LOGIN_LANDING_URL      = 'protech_wholesale_login_landing_url'; // '' = the shop page.

	public const SETTINGS_UPDATED_QUERY_ARG = 'protech-wholesale-settings-updated';

	/**
	 * @return array<string, mixed> option => default value.
	 */
	public static function get_defaults(): array {
		return array(
			self::OPT_DEFAULT_CASE_SIZE      => (string) ProductFields::DEFAULT_CASE_SIZE,
			self::OPT_DEFAULT_DISPLAYS_PER_CASE => (string) ProductFields::DEFAULT_DISPLAYS_PER_CASE,
			self::OPT_EMPTY_PRICE_BEHAVIOR   => 'hide',
			self::OPT_ALLOW_RETAIL_COUPONS   => 'no',
			self::OPT_EXCLUDE_FREE_SHIPPING  => 'yes',
			self::OPT_SHIPPING_FLAT_RATE     => '19.95',
			self::OPT_VOLUME_THRESHOLD_DISPLAYS => '16',
			self::OPT_VOLUME_PRICE          => '5.00',
			self::OPT_BULK_THRESHOLD_CASES   => '16',
			self::OPT_BULK_PRICE            => '4.50',
			// Confirmed against staging 2026-09-17 — Fluent Forms form #4
			// renders /wholesale-application. See DECISIONS.md.
			self::OPT_APPLICATION_SOURCE     => ApplicationForm::SOURCE_FLUENT_FORMS,
			self::OPT_APPLICATION_FORM_ID    => '4',
			self::OPT_PURGE_ON_UNINSTALL     => 'no',
			self::OPT_NOTIFICATION_EMAIL     => '',
			self::OPT_LOGIN_LANDING_URL      => '',
		);
	}

	public function register_hooks(): void {
		// Settings are rendered/saved from Approval's admin page (the
		// "Settings" tab); nothing to hook globally here.
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			// -- Catalog ---------------------------------------------------
			array(
				'title' => __( 'Catalog', 'protech-wholesale' ),
				'desc'  => __( 'Prices, thresholds and shipping are on the Pricing & Shipping tab; customer discount levels on the Tiers tab.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_settings_catalog',
			),
			array(
				'title'   => __( 'Products with no wholesale price', 'protech-wholesale' ),
				'desc'    => __( 'What a wholesale customer sees for a product or color that has no wholesale price. Either way it is bought on retail terms, with no display or case rules.', 'protech-wholesale' ),
				'id'      => self::OPT_EMPTY_PRICE_BEHAVIOR,
				'type'    => 'select',
				'default' => 'hide',
				'options' => array(
					'hide'     => __( 'Hide from wholesale views (recommended)', 'protech-wholesale' ),
					'fallback' => __( 'Show retail price with a "retail only" note', 'protech-wholesale' ),
				),
			),
			array(
				'title'       => __( 'After a wholesale login, go to', 'protech-wholesale' ),
				'desc'        => __( 'Where an approved wholesale customer lands after logging in on the wholesale page or My Account: usually the product they order most. Leave empty for the shop page.', 'protech-wholesale' ),
				'id'          => self::OPT_LOGIN_LANDING_URL,
				'type'        => 'url',
				'default'     => '',
				'placeholder' => wc_get_page_permalink( 'shop' ),
				'desc_tip'    => true,
				'css'         => 'width:400px;',
			),
			array(
				'title'   => __( 'Coupons', 'protech-wholesale' ),
				'desc'    => __( 'Let wholesale customers use retail coupons', 'protech-wholesale' ),
				'id'      => self::OPT_ALLOW_RETAIL_COUPONS,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_settings_catalog_end',
			),

			// -- Applications ----------------------------------------------
			array(
				'title' => __( 'Applications', 'protech-wholesale' ),
				'desc'  => __( 'Which form on the site creates wholesale applications, and who hears about them.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_settings_applications',
			),
			array(
				'title'   => __( 'Application form plugin', 'protech-wholesale' ),
				'desc'    => __( 'The plugin that renders /wholesale-application.', 'protech-wholesale' ),
				'id'      => self::OPT_APPLICATION_SOURCE,
				'type'    => 'select',
				'default' => ApplicationForm::SOURCE_FLUENT_FORMS,
				'options' => ApplicationForm::get_source_labels(),
			),
			array(
				'title'    => __( 'Application form ID', 'protech-wholesale' ),
				'desc'     => __( "That plugin's own numeric ID for the application form (check its Forms list). Leave empty to accept submissions from any form built with that plugin, which is not recommended if the site has other forms (a contact form, a newsletter signup) built with the same plugin.", 'protech-wholesale' ),
				'id'       => self::OPT_APPLICATION_FORM_ID,
				'type'     => 'text',
				'default'  => '4',
				'desc_tip' => true,
				'css'      => 'width:100px;',
			),
			array(
				'title'       => __( 'Send new-application emails to', 'protech-wholesale' ),
				'desc'        => sprintf(
					/* translators: %s: the site admin email address. */
					__( 'Leave empty to use the site admin address (%s).', 'protech-wholesale' ),
					(string) get_option( 'admin_email' )
				),
				'id'          => self::OPT_NOTIFICATION_EMAIL,
				'type'        => 'email',
				'default'     => '',
				'placeholder' => (string) get_option( 'admin_email' ),
				'css'         => 'width:280px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_settings_applications_end',
			),

			// -- Uninstall -------------------------------------------------
			array(
				'title' => __( 'Uninstall', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_settings_uninstall',
			),
			array(
				'title'   => __( 'On uninstall', 'protech-wholesale' ),
				'desc'    => __( 'Delete all Protech Wholesale data (roles, prices, applications) when the plugin is deleted', 'protech-wholesale' ),
				'id'      => self::OPT_PURGE_ON_UNINSTALL,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'protech_wholesale_settings_uninstall_end',
			),
		);
	}

	/**
	 * Render the Settings tab body (called from Approval::render_page()).
	 */
	public function render_settings_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'protech-wholesale' ) );
		}

		if ( isset( $_POST['protech_wholesale_settings_nonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['protech_wholesale_settings_nonce'] ) ),
				'protech_wholesale_save_settings'
			)
		) {
			woocommerce_update_options( $this->get_fields() );
			echo '<div class="updated notice"><p>' .
				esc_html__( 'Settings saved.', 'protech-wholesale' ) .
				'</p></div>';
			Logger::info( 'Wholesale settings updated by user #' . get_current_user_id() );
		}

		echo '<form method="post">';
		wp_nonce_field( 'protech_wholesale_save_settings', 'protech_wholesale_settings_nonce' );
		woocommerce_admin_fields( $this->get_fields() );
		submit_button();
		echo '</form>';

		// Not a saved setting: version, latest release and a "check now"
		// button, rendered by the updater itself.
		Updater::render_settings_section();
	}

	/** Where new-application emails go: the setting if set, else the site admin. */
	public static function notification_email(): string {
		$email = sanitize_email( (string) get_option( self::OPT_NOTIFICATION_EMAIL, '' ) );

		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/** Editable under WooCommerce → Wholesale → Pricing & Shipping, not this Settings tab. */
	/**
	 * Where an approved wholesale customer is sent after logging in:
	 * the configured landing URL if it is on this site, else the shop.
	 */
	public static function login_landing_url(): string {
		$shop = wc_get_page_permalink( 'shop' );
		$url  = trim( (string) get_option( self::OPT_LOGIN_LANDING_URL, '' ) );

		return '' === $url ? $shop : wp_validate_redirect( $url, $shop );
	}

	public static function get_default_case_size(): int {
		$value = (int) get_option( self::OPT_DEFAULT_CASE_SIZE, ProductFields::DEFAULT_CASE_SIZE );

		return $value > 0 ? $value : ProductFields::DEFAULT_CASE_SIZE;
	}

	/** Editable under WooCommerce → Wholesale → Pricing, not this Settings tab. */
	public static function get_default_displays_per_case(): int {
		$value = (int) get_option( self::OPT_DEFAULT_DISPLAYS_PER_CASE, ProductFields::DEFAULT_DISPLAYS_PER_CASE );

		return $value > 0 ? $value : ProductFields::DEFAULT_DISPLAYS_PER_CASE;
	}

	/** Editable under WooCommerce → Wholesale → Pricing. Flat fee below the volume threshold; free at/above it. */
	public static function get_shipping_flat_rate(): float {
		return (float) get_option( self::OPT_SHIPPING_FLAT_RATE, 19.95 );
	}

	/** Editable under WooCommerce → Wholesale → Pricing. Also the free-shipping cutoff — see get_shipping_flat_rate(). */
	public static function get_volume_threshold_displays(): int {
		return max( 1, (int) get_option( self::OPT_VOLUME_THRESHOLD_DISPLAYS, 16 ) );
	}

	/** Editable under WooCommerce → Wholesale → Pricing — the storewide default; a product may override it. */
	public static function get_volume_price(): float {
		return (float) get_option( self::OPT_VOLUME_PRICE, 5.00 );
	}

	/** Editable under WooCommerce → Wholesale → Pricing. */
	public static function get_bulk_threshold_cases(): int {
		return max( 1, (int) get_option( self::OPT_BULK_THRESHOLD_CASES, 16 ) );
	}

	/** Editable under WooCommerce → Wholesale → Pricing — the storewide default; a product may override it. */
	public static function get_bulk_price(): float {
		return (float) get_option( self::OPT_BULK_PRICE, 4.50 );
	}

	public static function empty_price_behavior(): string {
		return (string) get_option( self::OPT_EMPTY_PRICE_BEHAVIOR, 'hide' );
	}

	public static function allow_retail_coupons(): bool {
		return 'yes' === get_option( self::OPT_ALLOW_RETAIL_COUPONS, 'no' );
	}

	public static function exclude_free_shipping(): bool {
		return 'yes' === get_option( self::OPT_EXCLUDE_FREE_SHIPPING, 'yes' );
	}

	public static function application_source(): string {
		return (string) get_option( self::OPT_APPLICATION_SOURCE, ApplicationForm::SOURCE_NATIVE );
	}

	/** Empty string means "accept any form built with the selected plugin." */
	public static function application_form_id(): string {
		return trim( (string) get_option( self::OPT_APPLICATION_FORM_ID, '' ) );
	}

	public static function purge_on_uninstall(): bool {
		return 'yes' === get_option( self::OPT_PURGE_ON_UNINSTALL, 'no' );
	}
}
