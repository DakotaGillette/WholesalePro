<?php
/**
 * Fires when the plugin is deleted from the Plugins screen (or via
 * `wp plugin delete`) — never on ordinary deactivation.
 *
 * Per the master prompt's engineering constraint, "keep data by default;
 * setting to purge": if the "Purge data on uninstall" setting was never
 * turned on, this file does nothing at all and every setting, role,
 * application, and price override survives a reinstall untouched.
 *
 * Implementation note: this intentionally does NOT `require` the main
 * plugin file (protech-wholesale.php) to reuse its class constants.
 * uninstall.php runs as a standalone script outside the plugin's normal
 * `plugins_loaded` lifecycle — loading the plugin bootstrap here would
 * register its autoloader and activation/deactivation hooks for no
 * reason, and would silently break (with no warning) the moment a
 * future refactor renamed a class or constant. Hardcoding the literal
 * option/meta-key strings — copied from class-settings.php,
 * class-approval.php, class-application-form.php, and
 * class-orders-admin.php at the time this file was written — is the
 * standard, more robust WordPress practice for uninstall.php, at the
 * cost of needing to keep this list in sync if those keys ever change.
 *
 * @package ProtechWholesale
 */

// Guard: only run when WordPress itself is uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Mirrors Settings::OPT_PURGE_ON_UNINSTALL / Settings::purge_on_uninstall().
$protech_purge_on_uninstall = get_option( 'protech_wholesale_purge_on_uninstall', 'no' );

if ( 'yes' !== $protech_purge_on_uninstall ) {
	// Default: keep all data. Nothing further to do.
	return;
}

// -----------------------------------------------------------------
// 1. Plugin settings (mirrors Settings::get_defaults() keys).
// -----------------------------------------------------------------
$protech_option_keys = array(
	'protech_wholesale_min_order', // Removed in 1.3.0; still cleaned up.
	'protech_wholesale_default_case_size',
	'protech_wholesale_default_displays_per_case',
	'protech_wholesale_empty_price_behavior',
	'protech_wholesale_allow_retail_coupons',
	'protech_wholesale_exclude_free_shipping',
	'protech_wholesale_shipping_flat_rate',
	'protech_wholesale_volume_threshold_displays',
	'protech_wholesale_volume_price',
	'protech_wholesale_bulk_threshold_cases',
	'protech_wholesale_bulk_price',
	'protech_wholesale_application_source',
	'protech_wholesale_application_form_id',
	'protech_wholesale_purge_on_uninstall',
	'protech_wholesale_notification_email',
	'protech_wholesale_tier_settings', // Tiers::OPT_TIER_SETTINGS.
	'protech_wholesale_db_version',    // Plugin::OPT_DB_VERSION.
);

delete_transient( 'protech_wholesale_upgrading' );

foreach ( $protech_option_keys as $protech_option_key ) {
	delete_option( $protech_option_key );

	// Multisite: options are also stored per-site under network installs
	// via the same wp_options table per blog, so nothing extra is needed
	// here; delete_site_option() is intentionally not called since these
	// settings are never registered as network-wide options.
}

// -----------------------------------------------------------------
// 2. Wholesale roles (mirrors Roles::PENDING / Roles::CUSTOMER).
// -----------------------------------------------------------------
remove_role( 'wholesale_pending' );
remove_role( 'wholesale_customer' );

// -----------------------------------------------------------------
// 3. The auto-created /wholesale page — only if it's still exactly the
//    portal shortcode, so we never delete a page the owner has since
//    customized (e.g. rewritten in WPBakery, added copy, etc.).
// -----------------------------------------------------------------
$protech_portal_page = get_page_by_path( 'wholesale' );

if ( $protech_portal_page instanceof WP_Post
	&& '[protech_wholesale_portal]' === trim( $protech_portal_page->post_content )
) {
	wp_delete_post( $protech_portal_page->ID, true );
}

// -----------------------------------------------------------------
// 4. Per-user meta this plugin created.
//    (mirrors Approval::META_PRICE_OVERRIDES and the
//    '_protech_wholesale_app_*' keys written by
//    ApplicationForm::create_pending_applicant() / Approval::handle_reject()).
//    delete_metadata()'s $delete_all = true form removes a meta key for
//    every user at once, since we have no list of affected user IDs here.
// -----------------------------------------------------------------
$protech_user_meta_keys = array(
	'_protech_price_overrides',
	'_protech_wholesale_min_order_override', // Removed in 1.3.0; still cleaned up.
	'_protech_wholesale_tier', // Tiers::META_USER_TIER.
	'_protech_wholesale_app_status',
	'_protech_wholesale_app_submitted_at',
	'_protech_wholesale_app_reject_reason',
	'_protech_wholesale_app_name',
	'_protech_wholesale_app_title',
	'_protech_wholesale_app_phone',
	'_protech_wholesale_app_email',
	'_protech_wholesale_app_store_name',
	'_protech_wholesale_app_business_type',
	'_protech_wholesale_app_address',
	'_protech_wholesale_app_website',
	'_protech_wholesale_app_sales_channels',
	'_protech_wholesale_app_tcgs_carried',
	'_protech_wholesale_app_hosts_events',
	'_protech_wholesale_app_estimated_monthly_spend',
	'_protech_wholesale_app_accuracy_confirmation',
);

foreach ( $protech_user_meta_keys as $protech_user_meta_key ) {
	delete_metadata( 'user', 0, $protech_user_meta_key, '', true );
}

// -----------------------------------------------------------------
// Deliberately NOT deleted, even when purging:
//   - Product/variation meta: '_protech_wholesale_price', '_protech_case_size',
//     '_protech_displays_per_case', '_protech_volume_price',
//     '_protech_bulk_price', '_protech_wholesale_only', and the derived
//     '_protech_has_wholesale_price' flag (see ProductFields) — these are
//     pricing configuration on the store's own catalog, not data this
//     plugin "owns" the way applications and overrides are; silently
//     stripping wholesale prices from products on uninstall would be a
//     surprising, destructive side effect far beyond what a "purge on
//     uninstall" checkbox reasonably implies.
//   - The wholesale shipping method's per-zone instance settings
//     (WooCommerce stores those with the shipping zone, and removes them
//     when the method is removed from the zone).
//   - The '_protech_is_wholesale' order meta flag (OrdersAdmin::META_IS_WHOLESALE)
//     on existing orders — rewriting historical order data after the
//     fact would corrupt past reporting for orders that already shipped;
//     uninstalling this plugin should never change what already
//     happened.
// -----------------------------------------------------------------
