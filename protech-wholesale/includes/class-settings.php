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

	public const OPT_MIN_ORDER             = 'protech_wholesale_min_order';
	public const OPT_DEFAULT_CASE_SIZE      = 'protech_wholesale_default_case_size';
	public const OPT_EMPTY_PRICE_BEHAVIOR   = 'protech_wholesale_empty_price_behavior'; // 'hide' | 'fallback'.
	public const OPT_ALLOW_RETAIL_COUPONS   = 'protech_wholesale_allow_retail_coupons'; // 'no' | 'yes'.
	public const OPT_EXCLUDE_FREE_SHIPPING  = 'protech_wholesale_exclude_free_shipping'; // 'yes' | 'no'.
	public const OPT_APPLICATION_SOURCE     = 'protech_wholesale_application_source';
	public const OPT_APPLICATION_FORM_ID    = 'protech_wholesale_application_form_id'; // '' = accept any form from that plugin.
	public const OPT_PURGE_ON_UNINSTALL     = 'protech_wholesale_purge_on_uninstall'; // 'no' | 'yes'.

	public const SETTINGS_UPDATED_QUERY_ARG = 'protech-wholesale-settings-updated';

	/**
	 * @return array<string, mixed> option => default value.
	 */
	public static function get_defaults(): array {
		return array(
			self::OPT_MIN_ORDER             => '800',
			self::OPT_DEFAULT_CASE_SIZE      => (string) ProductFields::DEFAULT_CASE_SIZE,
			self::OPT_EMPTY_PRICE_BEHAVIOR   => 'hide',
			self::OPT_ALLOW_RETAIL_COUPONS   => 'no',
			self::OPT_EXCLUDE_FREE_SHIPPING  => 'yes',
			// Confirmed against staging 2026-09-17 — Fluent Forms form #4
			// renders /wholesale-application. See DECISIONS.md.
			self::OPT_APPLICATION_SOURCE     => ApplicationForm::SOURCE_FLUENT_FORMS,
			self::OPT_APPLICATION_FORM_ID    => '4',
			self::OPT_PURGE_ON_UNINSTALL     => 'no',
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
			array(
				'title' => __( 'Wholesale Settings', 'protech-wholesale' ),
				'desc'  => __( 'The minimum order subtotal and default case size moved to the Tiers tab (as the Bronze row) — everything tier-related lives there now.', 'protech-wholesale' ),
				'type'  => 'title',
				'id'    => 'protech_wholesale_settings_title',
			),
			array(
				'title'   => __( 'Products with no wholesale price', 'protech-wholesale' ),
				'desc'    => __( 'What a wholesale customer sees for a product/variation that has no group or per-customer wholesale price set.', 'protech-wholesale' ),
				'id'      => self::OPT_EMPTY_PRICE_BEHAVIOR,
				'type'    => 'select',
				'default' => 'hide',
				'options' => array(
					'hide'     => __( 'Hide from wholesale views (recommended)', 'protech-wholesale' ),
					'fallback' => __( 'Show retail price with a "retail only" note', 'protech-wholesale' ),
				),
			),
			array(
				'title'   => __( 'Allow retail coupons for wholesale customers', 'protech-wholesale' ),
				'id'      => self::OPT_ALLOW_RETAIL_COUPONS,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Exclude wholesale orders from free shipping', 'protech-wholesale' ),
				'desc'    => __( 'Keeps the retail free-shipping-over-$30 rule from applying to wholesale orders.', 'protech-wholesale' ),
				'id'      => self::OPT_EXCLUDE_FREE_SHIPPING,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Application form source', 'protech-wholesale' ),
				'desc'    => __( 'Which plugin renders /wholesale-application. See DECISIONS.md.', 'protech-wholesale' ),
				'id'      => self::OPT_APPLICATION_SOURCE,
				'type'    => 'select',
				'default' => ApplicationForm::SOURCE_FLUENT_FORMS,
				'options' => ApplicationForm::get_source_labels(),
			),
			array(
				'title'    => __( 'Application form ID', 'protech-wholesale' ),
				'desc'     => __( "The specific form's ID within the plugin selected above (e.g. Fluent Forms' own numeric form ID — check its Forms list). Leave empty to accept submissions from any form built with that plugin, which isn't recommended if the site has other forms (a contact form, newsletter signup, etc.) built with the same plugin.", 'protech-wholesale' ),
				'id'       => self::OPT_APPLICATION_FORM_ID,
				'type'     => 'text',
				'default'  => '4',
				'desc_tip' => true,
				'css'      => 'width:100px;',
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
				'id'   => 'protech_wholesale_settings_end',
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
	}

	/** Editable under WooCommerce → Wholesale → Tiers, as the Bronze row — not this Settings tab. */
	public static function get_min_order(): float {
		return (float) get_option( self::OPT_MIN_ORDER, 800 );
	}

	/** Editable under WooCommerce → Wholesale → Tiers, as the Bronze row — not this Settings tab. */
	public static function get_default_case_size(): int {
		$value = (int) get_option( self::OPT_DEFAULT_CASE_SIZE, ProductFields::DEFAULT_CASE_SIZE );

		return $value > 0 ? $value : ProductFields::DEFAULT_CASE_SIZE;
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
