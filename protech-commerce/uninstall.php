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
 * plugin file (protech-commerce.php) to reuse its class constants.
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
	'protech_wholesale_login_landing_url',
	// Email composer, 2.2.0. The templates are stored in ONE option (no post
	// type, no table, no user meta), so this is all there is to remove.
	'protech_wholesale_email_templates',        // EmailTemplates::OPTION.
	'protech_wholesale_msg_email_logo_id',      // MessagingSettings::OPT_EMAIL_LOGO_ID.
	'protech_wholesale_msg_email_brand_color',  // MessagingSettings::OPT_EMAIL_BRAND_COLOR.
	'protech_wholesale_msg_email_footer_text',  // MessagingSettings::OPT_EMAIL_FOOTER_TEXT.
	'protech_wholesale_msg_email_width',        // MessagingSettings::OPT_EMAIL_WIDTH.
	'protech_wholesale_msg_email_heading_font', // MessagingSettings::OPT_EMAIL_HEADING_FONT.
	'protech_wholesale_msg_email_link_color',   // MessagingSettings::OPT_EMAIL_LINK_COLOR.
	'protech_wholesale_msg_email_mobile_padding', // MessagingSettings::OPT_EMAIL_MOBILE_PADDING.
	'protech_wholesale_msg_email_provider',     // MessagingSettings::OPT_EMAIL_PROVIDER.
	'protech_wholesale_msg_sms_provider',       // MessagingSettings::OPT_SMS_PROVIDER.
	'protech_wholesale_tier_settings', // Tiers::OPT_TIER_SETTINGS.
	'protech_wholesale_db_version',    // Plugin::OPT_DB_VERSION.
	// Messaging & automations, 1.5.0 (mirrors MessagingSettings::get_defaults() keys).
	'protech_wholesale_msg_enabled',
	'protech_wholesale_msg_brevo_api_key',
	'protech_wholesale_msg_from_name',
	'protech_wholesale_msg_from_email',
	'protech_wholesale_msg_reply_to',
	'protech_wholesale_msg_sms_sender',
	'protech_wholesale_msg_brand',
	'protech_wholesale_msg_daily_hour',
	'protech_wholesale_msg_quiet_start',
	'protech_wholesale_msg_quiet_end',
	'protech_wholesale_msg_frequency_cap_days',
	'protech_wholesale_msg_sms_append_stop',
	'protech_wholesale_msg_sms_optin_confirmation',
	'protech_wholesale_msg_email_footer',
	'protech_wholesale_msg_privacy_url',
	'protech_wholesale_msg_terms_url',
	'protech_wholesale_msg_consent_wording',
	'protech_wholesale_msg_log_retention_days',
	'protech_wholesale_msg_last_daily_run',
	'protech_wholesale_automations',   // Automations::OPTION.
	'protech_wholesale_campaigns',     // Campaigns::OPTION.
	'protech_wholesale_signup_forms',  // SignupForms::OPTION.
	'protech_wholesale_flows',         // Flows::OPTION.
);

delete_transient( 'protech_wholesale_upgrading' );
delete_transient( 'protech_wholesale_as_selfheal' ); // AutomationRunner::SELF_HEAL_TRANSIENT.

foreach ( $protech_option_keys as $protech_option_key ) {
	delete_option( $protech_option_key );

	// Multisite: options are also stored per-site under network installs
	// via the same wp_options table per blog, so nothing extra is needed
	// here; delete_site_option() is intentionally not called since these
	// settings are never registered as network-wide options.
}

// -----------------------------------------------------------------
// 1b. The message log table (MessageLog::TABLE) and Action Scheduler's
//     own queued/scheduled actions for this plugin's hooks — mirrors
//     AutomationRunner::GROUP and its five hook constants. Action
//     Scheduler ships as part of WooCommerce, which is a hard
//     dependency, so its unschedule function is expected to exist.
// -----------------------------------------------------------------
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}protech_wholesale_messages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

// 1c. The contacts directory, its consent log, and its tags (Contacts::TABLE / CONSENT_LOG_TABLE / TAGS_TABLE, 3.4.0/3.6.0).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}protech_wholesale_contacts" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}protech_wholesale_contact_consent_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}protech_wholesale_contact_tags" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}protech_wholesale_flow_runs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach (
		array(
			'protech_wholesale_daily_automations',
			'protech_wholesale_deliver_messages',
			'protech_wholesale_order_event',
			'protech_wholesale_sync_contact',
			'protech_wholesale_purge_messages',
			'protech_wholesale_flow_wake',
		) as $protech_as_hook
	) {
		as_unschedule_all_actions( $protech_as_hook, array(), 'protech-wholesale' );
	}
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
	// Messaging & automations, 1.5.0.
	'_protech_wholesale_app_contact_methods',
	'_protech_wholesale_app_sms_transactional_consent',
	'_protech_wholesale_app_sms_marketing_consent',
	'_protech_wholesale_approved_at',            // Approval::META_APPROVED_AT.
	'_protech_wholesale_sms_phone',              // SmsConsent::META_PHONE.
	'_protech_wholesale_sms_transactional',      // SmsConsent::META_SMS_TRANSACTIONAL.
	'_protech_wholesale_sms_marketing',          // SmsConsent::META_SMS_MARKETING.
	'_protech_wholesale_email_marketing',        // SmsConsent::META_EMAIL_MARKETING.
	'_protech_wholesale_consent_log',            // SmsConsent::META_CONSENT_LOG.
	'_protech_wholesale_unsub_token',            // Unsubscribe::META_TOKEN.
	'_protech_wholesale_welcome_sent_at',        // WelcomeEmail::META_SENT_AT.
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
//   - The 'tax_exemption' user meta key (TaxExemption) — read and
//     written from the Customers tab, but it's owned by the separate
//     "Stripe Tax for WooCommerce" plugin, whose own "Stripe Tax
//     Exemptions" profile section is the real, functioning tax-exemption
//     control on this store; this plugin never purges another plugin's
//     data.
//   - SliceWP's own 'affiliate_id' customer meta and any affiliate meta
//     this plugin may have set (AffiliateAssignment) — same reasoning:
//     it's SliceWP's data, read and written in place, never owned or
//     purged by this plugin.
// -----------------------------------------------------------------
